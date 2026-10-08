<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketingLead;
use App\Models\MarketingTouch;
use App\Models\School;
use App\Models\Student;
use App\Models\UserProfile;
use App\Services\Marketing\LeadScorer;
use App\Services\SmsService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The marketing site's only ties to the backend (Landing PRD §14.2).
 *
 * All four sit outside tenant resolution: the site lives on the apex domain,
 * whose first label is not a school, and none of these concern one school's
 * data. Nothing here reveals which schools are customers — /schools/resolve
 * answers an exact address only, and /stats returns thresholded totals.
 */
class PublicMarketingController extends Controller
{
    private const TRACKING = ['landing_page', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public function __construct(private TenantContext $tenant)
    {
    }

    /** A demo, start or contact request. */
    public function storeLead(Request $request)
    {
        /*
         * Honeypot: a field no human sees. A bot that fills it gets the same
         * success a person would, so it learns nothing, and nothing is kept.
         */
        if (filled($request->input('website'))) {
            return response()->json(['received' => true], 201);
        }

        if (! $this->turnstilePasses($request)) {
            return response()->json(['errors' => ['turnstile' => ['Please confirm you are not a robot and try again.']]], 422);
        }

        $validated = $request->validate([
            'intent' => ['required', Rule::in(['demo', 'start', 'contact'])],
            'full_name' => 'required|string|min:2|max:120',
            'phone' => 'required|string|max:24',
            'school_name' => 'required|string|min:2|max:150',
            'role' => ['required', Rule::in(MarketingLead::ROLES)],
            'student_band' => ['required_unless:intent,contact', 'nullable', Rule::in(MarketingLead::BANDS)],
            'sections' => 'nullable|array|max:4',
            'sections.*' => [Rule::in(MarketingLead::SECTIONS)],
            'email' => 'nullable|email:rfc|max:150',
            'preferred_contact' => ['nullable', Rule::in(['whatsapp', 'call', 'email'])],
            'current_method' => ['nullable', Rule::in(['paper', 'excel', 'portal', 'mixed'])],
            'message' => 'nullable|string|max:2000',
            'preferred_subdomain' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
            'migrate_what' => 'nullable|string|max:1000',
            'consent' => 'accepted',
            'ref_code' => ['nullable', 'string', 'regex:/^SP-[A-Z0-9]{4,8}$/'],
            // A partner's referral code, carried from `?p=CODE` on the link they shared.
            'partner_code' => ['nullable', 'string', 'regex:/^P[A-Z0-9]{6}$/'],
            'pages' => 'nullable|array|max:50',
            'pages.*' => 'string|max:255',
            ...$this->trackingRules(),
        ], [
            'consent.accepted' => 'Please tick the box so we can contact you about this request.',
        ]);

        $phone = SmsService::normaliseNumber($validated['phone']);

        if (! preg_match('/^\+234[789][01]\d{8}$/', $phone)) {
            return response()->json(['errors' => ['phone' => ['Enter a Nigerian phone number, e.g. 0803 123 4567.']]], 422);
        }

        $pages = $validated['pages'] ?? [];

        $lead = MarketingLead::create([
            ...collect($validated)->only([
                'intent', 'full_name', 'email', 'school_name', 'role', 'student_band',
                'sections', 'current_method', 'message', 'preferred_subdomain',
                'migrate_what', 'ref_code', ...self::TRACKING,
            ])->all(),
            'phone' => $phone,
            // An unknown or inactive code is ignored, never an error: the visitor did nothing wrong.
            'partner_id' => filled($validated['partner_code'] ?? null)
                ? \App\Models\Partner::where('code', $validated['partner_code'])->where('status', 'active')->value('id')
                : null,
            'preferred_contact' => $validated['preferred_contact'] ?? 'whatsapp',
            'pages' => $pages,
            'status' => 'new',
            'score' => LeadScorer::score(
                $validated['role'],
                $validated['student_band'] ?? null,
                $pages,
                $validated['landing_page'] ?? null,
            ),
            'consent_at' => now(),
        ]);

        if ($lead->isHot()) {
            // Picked up by the console's hot-lead filter; the log line is for
            // whoever is watching production until a sales channel is wired.
            Log::info('Hot marketing lead', ['lead_id' => $lead->id, 'score' => $lead->score]);
        }

        // First name only goes back, for the success message. Nothing else.
        return response()->json([
            'received' => true,
            'first_name' => strtok($lead->full_name, ' '),
        ], 201);
    }

    /**
     * What the partner page may say about the programme. Only the published
     * rate: a rate agreed with one partner never passes through here.
     */
    public function partnerTerms()
    {
        return response()->json([
            'commission_percent' => (int) config('services.partners.default_rate_bps') / 100,
            'lifetime' => true,
        ]);
    }

    /** Someone asking to join the partner programme. Starts as `pending` until an operator approves. */
    public function applyPartner(Request $request)
    {
        if (filled($request->input('website'))) {
            return response()->json(['received' => true], 201);
        }

        if (! $this->turnstilePasses($request)) {
            return response()->json(['errors' => ['turnstile' => ['Please confirm you are not a robot and try again.']]], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email:rfc|max:150',
            'phone' => 'required|string|max:24',
            'pitch' => 'nullable|string|max:1000',
            'consent' => 'accepted',
        ], ['consent.accepted' => 'Please tick the box so we can contact you about this application.']);

        $phone = SmsService::normaliseNumber($validated['phone']);

        if (! preg_match('/^\+234[789][01]\d{8}$/', $phone)) {
            return response()->json(['errors' => ['phone' => ['Enter a Nigerian phone number, e.g. 0803 123 4567.']]], 422);
        }

        // Same answer whether or not the address is already known, so the form
        // cannot be used to find out who is a partner or a user.
        $taken = \App\Models\Partner::where('email', $validated['email'])->exists()
            || \App\Models\User::where('email', $validated['email'])->exists();

        if (! $taken) {
            \App\Models\Partner::create([
                'code' => \App\Models\Partner::newCode(),
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $phone,
                'pitch' => $validated['pitch'] ?? null,
                'status' => 'pending',
            ]);
        }

        return response()->json(['received' => true, 'first_name' => strtok($validated['name'], ' ')], 201);
    }

    /** The anonymous attribution behind a WhatsApp click. */
    public function touch(Request $request)
    {
        $allowed = ['ref_code', 'cta', 'pages', ...self::TRACKING];
        $extra = array_diff(array_keys($request->all()), $allowed);

        // Personal data has no business here; refuse rather than drop it
        // silently, so a client bug that starts sending it is noticed.
        if ($extra !== []) {
            return response()->json(['errors' => ['payload' => ['Unexpected fields: '.implode(', ', $extra)]]], 422);
        }

        $validated = $request->validate([
            'ref_code' => ['required', 'string', 'regex:/^SP-[A-Z0-9]{4,8}$/'],
            'cta' => 'nullable|string|max:32',
            'pages' => 'nullable|array|max:50',
            'pages.*' => 'string|max:255',
            ...$this->trackingRules(),
        ]);

        MarketingTouch::updateOrCreate(
            ['ref_code' => $validated['ref_code']],
            collect($validated)->except('ref_code')->all(),
        );

        return response()->json(['recorded' => true], 201);
    }

    /**
     * Find-your-school: exact address only. Same response shape whether the
     * school exists or not apart from the flag, and no suggestions, so the
     * endpoint cannot be used to list SchoolPilot's customers.
     */
    public function resolveSchool(Request $request)
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $slug = strtolower($validated['slug']);

        $school = $this->tenant->withoutScoping(fn () => School::query()
            ->tenants()
            ->where('subdomain', $slug)
            ->whereIn('status', ['active', 'trial'])
            ->first(['id', 'subdomain']));

        if (! $school) {
            return response()->json(['exists' => false, 'login_url' => null]);
        }

        $domain = config('services.marketing.tenant_domain');

        return response()->json([
            'exists' => true,
            'login_url' => "https://{$school->subdomain}.{$domain}/login",
        ]);
    }

    /**
     * Platform totals for the site, each hidden until it passes a threshold.
     * Aggregates only; never a school's name.
     */
    public function stats()
    {
        $counts = Cache::remember('marketing:public-stats', now()->addHour(), fn () => $this->tenant->withoutScoping(fn () => [
            'schools' => School::query()->tenants()->where('status', 'active')->count(),
            'students' => Student::query()
                ->whereIn('school_id', School::query()->tenants()->where('status', 'active')->select('id'))
                ->where('status', 'active')
                ->count(),
            'teachers' => UserProfile::query()
                ->whereIn('school_id', School::query()->tenants()->where('status', 'active')->select('id'))
                ->where('role', 'teacher')
                ->count(),
        ]));

        $thresholds = config('services.marketing.stats_thresholds');

        return response()->json(collect($counts)->map(
            fn (int $value, string $key) => $value >= ($thresholds[$key] ?? PHP_INT_MAX) ? $value : null
        ));
    }

    private function trackingRules(): array
    {
        return [
            'landing_page' => 'nullable|string|max:255',
            'referrer' => 'nullable|string|max:255',
            'utm_source' => 'nullable|string|max:100',
            'utm_medium' => 'nullable|string|max:100',
            'utm_campaign' => 'nullable|string|max:100',
            'utm_content' => 'nullable|string|max:100',
            'utm_term' => 'nullable|string|max:100',
        ];
    }

    /** Cloudflare Turnstile. Skipped when no secret is configured (local, tests). */
    private function turnstilePasses(Request $request): bool
    {
        $secret = config('services.marketing.turnstile_secret');

        if (blank($secret)) {
            return true;
        }

        $token = (string) $request->input('turnstile_token', '');

        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Turnstile verification failed', ['error' => $e->getMessage()]);

            return false;
        }

        return (bool) $response->json('success', false);
    }
}
