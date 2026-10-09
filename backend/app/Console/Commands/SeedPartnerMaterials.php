<?php

namespace App\Console\Commands;

use App\Models\PartnerMaterial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Loads the starter partner kit: message scripts, the two feature guides and
 * the social images. Safe to run again: anything already there (by title)
 * is left alone, so edits made in Super Admin are never overwritten.
 *
 *   php artisan partners:seed-materials
 *   php artisan partners:seed-materials --from=/path/to/repo
 */
class SeedPartnerMaterials extends Command
{
    protected $signature = 'partners:seed-materials {--from= : Folder holding the PDFs and logos/ (default: the repository root)}';

    protected $description = 'Load the starter partner materials (scripts, feature guides, social images)';

    public function handle(): int
    {
        $from = rtrim($this->option('from') ?: base_path('..'), '/\\');
        $added = 0;
        $sort = 10;

        foreach ($this->scripts() as [$title, $description, $body]) {
            $added += $this->add(['title' => $title, 'description' => $description, 'category' => 'script', 'kind' => 'text', 'body' => $body, 'sort' => $sort += 10]);
        }

        $files = [
            ['SchoolPilot-Feature-Guide.pdf', 'Feature guide for schools', 'The full guide: every module, what is live, and pricing. Send it after a first conversation.', 'brochure'],
            ['SchoolPilot_Platform_Features.pdf', 'Platform features (2 pages)', 'A short overview for a busy proprietor.', 'brochure'],
        ];
        foreach (glob($from.'/logos/social-posts/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $path) {
            $files[] = [substr($path, strlen($from) + 1), Str::headline(pathinfo($path, PATHINFO_FILENAME)), 'Ready to post on WhatsApp status, Facebook or Instagram.', 'social'];
        }
        foreach (glob($from.'/logos/social-covers/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $path) {
            $files[] = [substr($path, strlen($from) + 1), Str::headline(pathinfo($path, PATHINFO_FILENAME)), 'A cover image for your page or group.', 'social'];
        }

        foreach ($files as [$relative, $title, $description, $category]) {
            $path = $from.'/'.$relative;
            if (! is_file($path)) {
                $this->warn("Skipped {$relative}: not found under {$from}.");

                continue;
            }
            if (PartnerMaterial::where('title', $title)->exists()) {
                continue;
            }

            $stored = 'partner-materials/'.Str::lower(Str::random(16)).'.'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
            Storage::disk('local')->put($stored, file_get_contents($path));
            $added += $this->add([
                'title' => $title, 'description' => $description, 'category' => $category, 'kind' => 'file',
                'file_path' => $stored, 'file_name' => basename($path), 'mime' => mime_content_type($path) ?: null,
                'size_bytes' => filesize($path), 'sort' => $sort += 10,
            ]);
        }

        $this->info("{$added} material(s) added.");

        return self::SUCCESS;
    }

    private function add(array $attributes): int
    {
        if (PartnerMaterial::where('title', $attributes['title'])->exists()) {
            return 0;
        }
        PartnerMaterial::create($attributes + ['is_published' => true]);

        return 1;
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    private function scripts(): array
    {
        return [
            [
                'WhatsApp: first message to a proprietor',
                'Short and personal. Your link is filled in for you.',
                "Good day sir/ma. I work with SchoolPilot, a school management system built for Nigerian private schools: results and report cards, CBT, fees, attendance and a parents' app, all in one login.\n\nIt costs from ₦300 per student per term, there's nothing to buy, and they move your records in for free.\n\nYou can see how it works here: {link}\n\nWould you like me to arrange a short demo for your school?",
            ],
            [
                'WhatsApp: follow-up after a demo',
                'Send a day or two after the demo.',
                "Good day sir/ma, thank you for your time at the SchoolPilot demo. If you'd like to go ahead, you can start here: {link}\n\nYour records are moved in free, and your staff are trained as part of onboarding. Please let me know if you have any questions.",
            ],
            [
                'Email: introduction',
                'For schools you reach by email.',
                "Subject: Results, fees and CBT in one system for your school\n\nDear Proprietor,\n\nSchoolPilot is a school management system built for Nigerian private schools, from Nursery to SS3. Results and report cards, CBT, fees and payments, attendance, staff and payroll, and an app for parents, all in one login.\n\n• From ₦300 per student per term, or ₦500 with result management\n• Nothing to buy: it runs on the phones and computers you already have\n• Your records moved in free\n\nSee how it works: {link}\n\nI would be glad to arrange a demo.\n\nKind regards,\n{name}",
            ],
            [
                'WhatsApp status / social caption',
                'A short caption to post with one of the social images.',
                "Results in days, not weeks. Know who owes fees before resumption. CBT that keeps going when the internet doesn't. SchoolPilot runs your whole school from one login. {link}",
            ],
        ];
    }
}
