<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdmissionSetting;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Support\NigerianStates;
use App\Support\Sections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * School public portal & branded login.
 *
 * The public read is the school's own front door: what the subdomain shows a
 * signed-out visitor. It answers from an allowlist of institutional fields and
 * nothing else — never a student, parent, staff member, result or notice — so
 * a field added to `schools` later cannot leak here by accident.
 *
 * Two levels of exposure:
 *  - always: identity (name, crest, motto, hero, colour), because the sign-in
 *    page has to say which school it is;
 *  - once the admin publishes: about, contact, sections and admissions, and
 *    the web app lets search engines index the page.
 */
class SchoolProfileController extends Controller
{
    public const SOCIAL_HOSTS = [
        'facebook' => ['facebook.com', 'fb.com'],
        'instagram' => ['instagram.com'],
        'x' => ['x.com', 'twitter.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'linkedin' => ['linkedin.com'],
        'tiktok' => ['tiktok.com'],
    ];

    public const SCHOOL_TYPES = [
        'day' => 'Day school',
        'boarding' => 'Boarding school',
        'day_and_boarding' => 'Day and boarding',
    ];

    /** What publishing needs: enough for the page to be useful to a parent. */
    private const REQUIRED_TO_PUBLISH = ['about' => 'About the school', 'address' => 'Address', 'state' => 'State'];

    // ------------------------------------------------------------------
    // Public
    // ------------------------------------------------------------------

    public function publicShow(Request $request)
    {
        /** @var School|null $school */
        $school = $request->attributes->get('tenant_school');

        // A suspended school, SchoolPilot's own row, or a header naming no
        // school all look the same as an address that does not exist.
        abort_unless($school && ! $school->is_platform && in_array($school->status, ['active', 'trial'], true), 404, 'School not found.');

        $published = (bool) $school->public_profile_published;

        $profile = [
            'name' => $school->name,
            'short_name' => $school->short_name,
            'subdomain' => $school->subdomain,
            'motto' => $school->motto,
            'logo_url' => $school->logo_url,
            'hero_image_url' => $school->hero_image_url,
            'brand_color' => $school->brand_color,
        ];

        if ($published) {
            $profile += [
                'about' => $school->about,
                'address' => $school->address,
                'state' => $school->state,
                'lga' => $school->lga,
                'phone' => $school->phone,
                'email' => $school->email,
                'website' => $school->website,
                'social_links' => (object) array_filter((array) ($school->social_links ?? [])),
                'school_type' => $school->school_type ? (self::SCHOOL_TYPES[$school->school_type] ?? null) : null,
                'sections' => $this->sections($school),
            ];
        }

        return response()->json([
            'published' => $published,
            'admissions_open' => $published && AdmissionSetting::for($school->id)->public_form_enabled,
            'school' => $profile,
        ])->header('Cache-Control', 'public, max-age=300');
    }

    // ------------------------------------------------------------------
    // School admin
    // ------------------------------------------------------------------

    public function show()
    {
        $school = School::find($this->schoolId());

        if (! $school) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        return response()->json($this->describe($school));
    }

    public function update(Request $request)
    {
        $school = School::find($this->schoolId());

        if (! $school) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $validated = $request->validate([
            'short_name' => 'sometimes|nullable|string|max:40',
            'motto' => 'sometimes|nullable|string|max:150',
            'about' => 'sometimes|nullable|string|max:2000',
            'address' => 'sometimes|nullable|string|max:255',
            'state' => ['sometimes', 'nullable', Rule::in(NigerianStates::ALL)],
            'lga' => 'sometimes|nullable|string|max:80',
            'phone' => ['sometimes', 'nullable', 'string', 'max:24', 'regex:/^[0-9+\s()-]{7,24}$/'],
            'email' => 'sometimes|nullable|email:rfc|max:150',
            'website' => 'sometimes|nullable|url:http,https|max:255',
            'social_links' => 'sometimes|nullable|array',
            'social_links.*' => 'nullable|url:https|max:255',
            'school_type' => ['sometimes', 'nullable', Rule::in(array_keys(self::SCHOOL_TYPES))],
            'brand_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'published' => 'sometimes|boolean',
        ]);

        if (array_key_exists('social_links', $validated)) {
            $validated['social_links'] = $this->socialLinks((array) ($validated['social_links'] ?? []));
        }

        if (! empty($validated['brand_color']) && $this->contrastWithWhite($validated['brand_color']) < 4.5) {
            throw ValidationException::withMessages([
                'brand_color' => ['That colour is too light for white text on buttons. Choose a darker shade.'],
            ]);
        }

        if (isset($validated['brand_color'])) {
            $validated['brand_color'] = strtolower($validated['brand_color']);
        }

        $publish = $validated['published'] ?? null;
        unset($validated['published']);

        $before = $this->auditable($school);
        $school->fill($validated);

        if ($publish === true) {
            $missing = array_filter(self::REQUIRED_TO_PUBLISH, fn ($label, $field) => blank($school->{$field}), ARRAY_FILTER_USE_BOTH);

            if (blank($school->phone) && blank($school->email)) {
                $missing['contact'] = 'A phone number or email';
            }

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'published' => ['Add these before publishing: '.implode(', ', $missing).'.'],
                ]);
            }

            if (! $school->public_profile_published) {
                $school->public_profile_published = true;
                $school->public_profile_published_at = now();
            }
        } elseif ($publish === false) {
            $school->public_profile_published = false;
        }

        $school->save();

        $this->audit($request, $school, 'school.profile.updated', $before, $this->auditable($school));

        return response()->json(['message' => 'School profile saved.'] + $this->describe($school->fresh()));
    }

    /**
     * The crest or the hero photo.
     *
     * Raster only: an SVG is a document that can carry script, and this file
     * is served from the school's own public origin. ScanUploads has already
     * looked inside it before this runs.
     */
    public function uploadImage(Request $request, string $kind)
    {
        abort_unless(in_array($kind, ['logo', 'hero'], true), 404);

        $school = School::find($this->schoolId());

        if (! $school) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.($kind === 'hero' ? 5120 : 1024), 'dimensions:min_width='.($kind === 'hero' ? 1200 : 128)],
            // A public, indexed page: a recognisable child on it is that
            // child's personal data, published (NDPA, doc §12).
            'no_identifiable_pupils' => $kind === 'hero' ? 'accepted' : 'nullable',
        ], [
            'no_identifiable_pupils.accepted' => 'Confirm the photo shows no recognisable pupils, or that you hold their parents\' consent.',
            'image.dimensions' => $kind === 'hero' ? 'Use a photo at least 1200 pixels wide so it stays sharp on large screens.' : 'Use an image at least 128 pixels wide.',
        ]);

        $column = $kind === 'hero' ? 'hero_image_url' : 'logo_url';
        $before = [$column => $school->{$column}];

        $file = $request->file('image');
        $path = $file->storeAs("schools/{$school->id}/brand", $kind.'-'.Str::uuid().'.'.strtolower($file->extension()), 'public');

        $this->deleteOwnImage($school, $school->{$column});

        $school->{$column} = Storage::disk('public')->url($path);

        if ($kind === 'hero') {
            $school->hero_image_confirmed_at = now();
            $school->hero_image_confirmed_by = $request->user()->id;
        }

        $school->save();

        $this->audit($request, $school, "school.profile.{$kind}_uploaded", $before, [$column => $school->{$column}] + ($kind === 'hero' ? ['no_identifiable_pupils_confirmed' => true] : []));

        return response()->json(['message' => $kind === 'hero' ? 'Hero photo saved.' : 'Logo saved.'] + $this->describe($school->fresh()));
    }

    public function removeImage(Request $request, string $kind)
    {
        abort_unless(in_array($kind, ['logo', 'hero'], true), 404);

        $school = School::find($this->schoolId());

        if (! $school) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $column = $kind === 'hero' ? 'hero_image_url' : 'logo_url';
        $before = [$column => $school->{$column}];

        $this->deleteOwnImage($school, $school->{$column});
        $school->{$column} = null;

        if ($kind === 'hero') {
            $school->hero_image_confirmed_at = null;
            $school->hero_image_confirmed_by = null;
        }

        $school->save();

        $this->audit($request, $school, "school.profile.{$kind}_removed", $before, [$column => null]);

        return response()->json(['message' => 'Image removed.'] + $this->describe($school->fresh()));
    }

    // ------------------------------------------------------------------

    private function describe(School $school): array
    {
        $domain = config('services.marketing.tenant_domain');

        return [
            'profile' => [
                'name' => $school->name,
                'subdomain' => $school->subdomain,
                'short_name' => $school->short_name,
                'motto' => $school->motto,
                'about' => $school->about,
                'address' => $school->address,
                'state' => $school->state,
                'lga' => $school->lga,
                'phone' => $school->phone,
                'email' => $school->email,
                'website' => $school->website,
                'social_links' => (object) ($school->social_links ?? []),
                'school_type' => $school->school_type,
                'brand_color' => $school->brand_color,
                'logo_url' => $school->logo_url,
                'hero_image_url' => $school->hero_image_url,
                'published' => (bool) $school->public_profile_published,
                'published_at' => $school->public_profile_published_at?->toIso8601String(),
            ],
            'public_url' => "https://{$school->subdomain}.{$domain}/",
            'sections' => $this->sections($school),
            'options' => [
                'states' => NigerianStates::ALL,
                'school_types' => self::SCHOOL_TYPES,
                'social_networks' => array_keys(self::SOCIAL_HOSTS),
            ],
        ];
    }

    /** The level groups the school actually runs, from its classes. */
    private function sections(School $school): array
    {
        $present = SchoolClass::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->distinct()
            ->pluck('level_category')
            ->all();

        return array_values(array_intersect_key(Sections::LEVEL_GROUPS, array_flip($present)));
    }

    /** Known networks only, each pointing at its own site. */
    private function socialLinks(array $links): array
    {
        $clean = [];

        foreach ($links as $network => $url) {
            if (blank($url)) {
                continue;
            }

            if (! isset(self::SOCIAL_HOSTS[$network])) {
                throw ValidationException::withMessages(["social_links.{$network}" => ['Unknown social network.']]);
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $host = preg_replace('/^(www|m|web)\./', '', $host);

            if (! in_array($host, self::SOCIAL_HOSTS[$network], true)) {
                throw ValidationException::withMessages(["social_links.{$network}" => ['That link is not a '.ucfirst($network).' address.']]);
            }

            $clean[$network] = $url;
        }

        return $clean;
    }

    /** WCAG contrast ratio of white text on the colour. */
    private function contrastWithWhite(string $hex): float
    {
        $channel = function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        $hex = ltrim($hex, '#');
        $luminance = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));

        return 1.05 / ($luminance + 0.05);
    }

    /** Only files this controller stored; an operator-set external logo URL is left alone. */
    private function deleteOwnImage(School $school, ?string $url): void
    {
        if (! $url) {
            return;
        }

        $prefix = Storage::disk('public')->url("schools/{$school->id}/brand/");

        if (str_starts_with($url, $prefix)) {
            Storage::disk('public')->delete("schools/{$school->id}/brand/".basename($url));
        }
    }

    private function auditable(School $school): array
    {
        return [
            'short_name' => $school->short_name,
            'motto' => $school->motto,
            'about' => $school->about,
            'address' => $school->address,
            'state' => $school->state,
            'lga' => $school->lga,
            'phone' => $school->phone,
            'email' => $school->email,
            'website' => $school->website,
            'social_links' => json_encode($school->social_links ?? []),
            'school_type' => $school->school_type,
            'brand_color' => $school->brand_color,
            'published' => (bool) $school->public_profile_published,
        ];
    }

    private function audit(Request $request, School $school, string $action, array $before, array $after): void
    {
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

        if ($changed === []) {
            return;
        }

        AuditLog::create([
            'school_id' => $school->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => School::class,
            'auditable_id' => $school->id,
            'old_values' => array_intersect_key($before, array_flip($changed)),
            'new_values' => array_intersect_key($after, array_flip($changed)),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
