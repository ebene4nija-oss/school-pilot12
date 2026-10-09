<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The marketing site's content an operator manages from the console
 * (Landing PRD §24): product screenshots, slot by slot, and the public
 * config the site reads when it is built.
 *
 * The site reads `/public/site-config` at build time, so changes made here
 * show on the site after its next rebuild. Text settings (WhatsApp number,
 * contacts, reply promise, trust and legal switches) are platform settings in
 * the Website group; this controller only adds what a settings form can't
 * hold: images.
 *
 * Screenshots must come from the demo school — never a real school's
 * children or records (Landing PRD §16). Every upload has already been
 * through the ScanUploads middleware before it reaches here.
 */
class PlatformWebsiteController extends Controller
{
    private const DIR = 'website/screens';

    /** The image slots the site has, and the screen each one should show. */
    public const SLOTS = [
        'admin-dashboard' => ['label' => 'School admin dashboard', 'frame' => 'desktop'],
        'admin-dashboard-phone' => ['label' => 'Admin dashboard, mobile app', 'frame' => 'phone'],
        'broadsheet' => ['label' => 'A class broadsheet with positions', 'frame' => 'desktop'],
        'cbt-exam' => ['label' => 'Student CBT exam screen', 'frame' => 'desktop'],
        'fees-outstanding' => ['label' => 'Invoices and outstanding balances', 'frame' => 'desktop'],
        'student-profile' => ['label' => 'Student profile', 'frame' => 'desktop'],
        'score-entry-phone' => ['label' => 'Teacher score entry, mobile', 'frame' => 'phone'],
        'parent-app-phone' => ['label' => 'Parent app: result and fee balance', 'frame' => 'phone'],
        'student-app-phone' => ['label' => 'Student app: materials and assignments', 'frame' => 'phone'],
        // The default preview when a page is shared on WhatsApp, Facebook, X or LinkedIn.
        'social-preview' => ['label' => 'Default share preview image', 'frame' => 'social'],
    ];

    /** Operator: every slot, with its current image if there is one. */
    public function screens()
    {
        $current = $this->current();

        return response()->json([
            'data' => collect(self::SLOTS)->map(fn (array $slot, string $key) => [
                'slot' => $key,
                'label' => $slot['label'],
                'frame' => $slot['frame'],
                'url' => isset($current[$key]) ? Storage::disk('public')->url($current[$key]) : null,
            ])->values(),
        ]);
    }

    /** Operator: put an image in a slot, replacing what was there. */
    public function upload(Request $request, string $slot)
    {
        if (! array_key_exists($slot, self::SLOTS)) {
            return response()->json(['message' => 'No such screenshot slot.'], 404);
        }

        $frame = self::SLOTS[$slot]['frame'];
        $phone = $frame === 'phone';

        $request->validate([
            'image' => [
                'required',
                'file',
                'mimes:png,jpg,jpeg,webp',
                'max:4096',
                // Big enough to stay sharp on the site; phones are portrait.
                match ($frame) {
                    'phone' => 'dimensions:min_width=360,min_height=640',
                    'social' => 'dimensions:min_width=1200,min_height=630',
                    default => 'dimensions:min_width=1000,min_height=600',
                },
            ],
        ], [
            'image.dimensions' => match ($frame) {
                'phone' => 'Use a portrait phone screenshot at least 360 × 640 pixels.',
                'social' => 'Use an image at least 1200 × 630 pixels, with the logo and text kept in the middle so cropping cannot cut them.',
                default => 'Use a screenshot at least 1000 × 600 pixels.',
            },
        ]);

        $file = $request->file('image');

        // A phone frame holds a portrait screenshot; a landscape one would be
        // squashed into it.
        if ($phone) {
            [$width, $height] = getimagesize($file->getRealPath()) ?: [0, 0];

            if ($width >= $height) {
                return response()->json([
                    'message' => 'This slot needs a portrait phone screenshot.',
                    'errors' => ['image' => ['Use a portrait phone screenshot, taller than it is wide.']],
                ], 422);
            }
        }

        $this->remove($slot);

        // A new name each time, so browsers and the site build never serve a
        // stale copy of the old image.
        $path = $file->storeAs(self::DIR, $slot.'-'.Str::lower(Str::random(8)).'.'.$file->extension(), 'public');

        $this->audit($request, 'website.screenshot.uploaded', ['slot' => $slot]);

        return response()->json([
            'slot' => $slot,
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }

    /** Operator: empty a slot; the site shows its "to capture" frame again. */
    public function destroy(Request $request, string $slot)
    {
        if (! array_key_exists($slot, self::SLOTS)) {
            return response()->json(['message' => 'No such screenshot slot.'], 404);
        }

        $this->remove($slot);
        $this->audit($request, 'website.screenshot.removed', ['slot' => $slot]);

        return response()->json(['slot' => $slot, 'url' => null]);
    }

    /**
     * Public: what the marketing site needs at build time. Only values meant
     * for the public site; nothing here is a secret.
     */
    public function siteConfig()
    {
        $config = config('services.marketing.site');
        $current = $this->current();

        return response()->json([
            'whatsapp_number' => $config['whatsapp_number'] ?: null,
            'response_minutes' => (int) ($config['response_minutes'] ?? 0),
            'demo_school_url' => $config['demo_school_url'] ?: null,
            'contact_email' => $config['contact_email'] ?: null,
            'contact_address' => $config['contact_address'] ?: null,
            'privacy_email' => $config['privacy_email'] ?: null,
            'trust_show_ai' => (bool) $config['trust_show_ai'],
            'trust_show_backups' => (bool) $config['trust_show_backups'],
            'legal_reviewed' => (bool) $config['legal_reviewed'],
            'google_site_verification' => $this->token($config['google_site_verification'] ?? null),
            'bing_site_verification' => $this->token($config['bing_site_verification'] ?? null),
            // The site's /download/ page states the version; the button itself uses the stable link.
            'offline_client' => OfflineClientReleaseController::summary(),
            'screens' => collect(self::SLOTS)->mapWithKeys(fn (array $slot, string $key) => [
                $key => isset($current[$key]) ? Storage::disk('public')->url($current[$key]) : null,
            ]),
        ]);
    }

    /** A verification token is letters, digits, dashes and underscores; anything else is dropped rather than printed into the site's HTML. */
    private function token(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^[A-Za-z0-9_-]{10,100}$/', $value) ? $value : null;
    }

    /** @return array<string, string> slot => stored path */
    private function current(): array
    {
        $found = [];

        foreach (Storage::disk('public')->files(self::DIR) as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $slot = Str::beforeLast($name, '-');

            if (array_key_exists($slot, self::SLOTS)) {
                $found[$slot] = $path;
            }
        }

        return $found;
    }

    private function remove(string $slot): void
    {
        foreach (Storage::disk('public')->files(self::DIR) as $path) {
            if (Str::beforeLast(pathinfo($path, PATHINFO_FILENAME), '-') === $slot) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function audit(Request $request, string $action, array $values): void
    {
        AuditLog::create([
            'school_id' => null,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => self::class,
            'auditable_id' => 0,
            'new_values' => $values,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
