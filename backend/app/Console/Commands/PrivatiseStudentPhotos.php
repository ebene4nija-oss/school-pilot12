<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\StudentPhotoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Move passport photos off the public disk (doc §12).
 *
 * Photos used to be stored where anyone with the link could open them. New
 * ones are private already; this moves the rest, stripping location metadata
 * on the way, and deletes the public copy. Run once after deploying; safe to
 * run again (a moved photo is skipped).
 */
class PrivatiseStudentPhotos extends Command
{
    protected $signature = 'students:privatise-photos {--dry-run : List what would move without changing anything}';

    protected $description = 'Move student passport photos from public to private storage';

    public function handle(StudentPhotoService $photos): int
    {
        $dry = (bool) $this->option('dry-run');
        $moved = 0;
        $missing = 0;

        Student::withoutGlobalScopes()
            ->whereNotNull('passport_photo_path')
            ->where('passport_photo_path', 'not like', 'private/%')
            ->orderBy('id')
            ->chunkById(200, function ($students) use ($photos, $dry, &$moved, &$missing) {
                foreach ($students as $student) {
                    $old = (string) $student->passport_photo_path;
                    $disk = Storage::disk('public');
                    if (str_contains($old, '..') || ! $disk->exists($old)) {
                        $missing++;
                        continue;
                    }
                    if ($dry) {
                        $this->line("would move student {$student->id}: {$old}");
                        $moved++;
                        continue;
                    }

                    $new = $photos->storeBytes((string) $disk->get($old), (string) $disk->mimeType($old));
                    DB::table('students')->where('id', $student->id)->update(['passport_photo_path' => $new]);
                    // The student's account avatar pointed at the same public file.
                    DB::table('user_profiles')->where('user_id', $student->user_id)->where('avatar_url', $old)->update(['avatar_url' => null]);
                    $disk->delete($old);
                    $moved++;
                }
            });

        $this->info(($dry ? 'Would move' : 'Moved')." {$moved} photo(s) to private storage.".($missing ? " {$missing} listed photo file(s) were not found." : ''));

        return self::SUCCESS;
    }
}
