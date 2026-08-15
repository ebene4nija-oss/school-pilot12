<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolDataExport;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\ZipWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolDataExportTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://pilotacademy.localhost/api/v1';

    private function seedSchool(): array
    {
        $school = School::create(['name' => 'Pilot Academy', 'slug' => 'pilot-academy', 'subdomain' => 'pilotacademy']);

        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-07-31',
            'is_current' => true,
        ]);

        $jss1 = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order_index' => 1]);

        $studentUser = User::factory()->create(['name' => 'Emeka Okafor']);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $studentUser->id, 'role' => 'student']);
        Student::create([
            'school_id' => $school->id,
            'user_id' => $studentUser->id,
            'class_id' => $jss1->id,
            'admission_number' => 'ADM/001',
            'gender' => 'male',
            'status' => 'active',
            'blood_group' => 'O+',
            'medical_notes' => 'Asthmatic.',
        ]);

        return [
            'school' => $school,
            'admin' => $admin,
            'jss1' => $jss1,
            'token' => $admin->createToken('t')->plainTextToken,
        ];
    }

    private function asAdmin(array $s)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $s['token']);
    }

    /** Read an entry back out of a ZIP without ext-zip. */
    private function entryFromZip(string $path, string $name): ?string
    {
        $binary = file_get_contents($path);
        $offset = 0;

        while (($signature = substr($binary, $offset, 4)) === "PK\x03\x04") {
            $header = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnamelen/vextralen',
                substr($binary, $offset + 4, 26));

            $entryName = substr($binary, $offset + 30, $header['namelen']);
            $dataStart = $offset + 30 + $header['namelen'] + $header['extralen'];
            $data = substr($binary, $dataStart, $header['compressed']);

            if ($entryName === $name) {
                return $header['method'] === 8 ? gzinflate($data) : $data;
            }

            $offset = $dataStart + $header['compressed'];
        }

        return null;
    }

    // ─── The writer itself ──────────────────────────────────────────

    public function test_zip_writer_produces_an_archive_readable_without_ext_zip()
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');

        $zip = new ZipWriter($path);
        $zip->add('small.txt', 'hi');
        $zip->add('large.csv', str_repeat("name,class\nEmeka,JSS 1\n", 200));
        $zip->finish();

        $this->assertEquals('hi', $this->entryFromZip($path, 'small.txt'));
        $this->assertStringContainsString('Emeka,JSS 1', $this->entryFromZip($path, 'large.csv'));

        // The compressible entry must actually have been deflated, and the
        // incompressible one stored rather than inflated.
        $this->assertLessThan(strlen(str_repeat("name,class\nEmeka,JSS 1\n", 200)), filesize($path));

        unlink($path);
    }

    // ─── Requesting ─────────────────────────────────────────────────

    public function test_requesting_an_export_queues_it_and_builds_a_downloadable_archive()
    {
        Storage::fake('local');
        $s = $this->seedSchool();

        // QUEUE_CONNECTION is sync in tests, so the job runs inline here.
        $response = $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', []);

        $response->assertStatus(202);

        $export = SchoolDataExport::withoutGlobalScopes()->first();

        $this->assertEquals('complete', $export->status);
        $this->assertNotNull($export->file_path);
        $this->assertGreaterThan(0, $export->row_counts['students']);
        $this->assertTrue($export->isDownloadable());
    }

    public function test_the_archive_contains_a_manifest_and_a_readable_roster()
    {
        $s = $this->seedSchool();

        $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', [])->assertStatus(202);

        $export = SchoolDataExport::withoutGlobalScopes()->first();
        $path = Storage::disk('local')->path($export->file_path);

        $manifest = json_decode($this->entryFromZip($path, 'manifest.json'), true);

        $this->assertEquals($s['school']->id, $manifest['school_id']);
        $this->assertFalse($manifest['includes_medical']);
        $this->assertArrayHasKey('students', $manifest['row_counts']);

        $roster = $this->entryFromZip($path, 'roster-readable.csv');

        $this->assertStringContainsString('Emeka Okafor', $roster);
        $this->assertStringContainsString('JSS 1', $roster);
        $this->assertStringContainsString('ADM/001', $roster);
    }

    /**
     * `$hidden` keeps the four encrypted columns out of ordinary responses;
     * an archive that quietly included them would undo that in one download.
     */
    public function test_medical_data_is_absent_unless_explicitly_requested()
    {
        $s = $this->seedSchool();

        $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', [])->assertStatus(202);

        $export = SchoolDataExport::withoutGlobalScopes()->first();
        $path = Storage::disk('local')->path($export->file_path);

        $this->assertNull($this->entryFromZip($path, 'students-medical.csv'));

        $students = $this->entryFromZip($path, 'students.csv');
        $this->assertStringNotContainsString('medical_notes', $students);
        $this->assertStringNotContainsString('Asthmatic', $students);
    }

    public function test_medical_data_is_decrypted_when_asked_for_and_audited()
    {
        $s = $this->seedSchool();

        $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', ['include_medical' => true])
            ->assertStatus(202);

        $export = SchoolDataExport::withoutGlobalScopes()->first();
        $path = Storage::disk('local')->path($export->file_path);

        $medical = $this->entryFromZip($path, 'students-medical.csv');

        $this->assertStringContainsString('Asthmatic.', $medical);
        $this->assertStringContainsString('O+', $medical);

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $s['school']->id,
            'action' => 'school.export_requested_with_medical',
        ]);
    }

    public function test_a_second_export_within_the_day_is_refused()
    {
        $s = $this->seedSchool();

        $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', [])->assertStatus(202);
        $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', [])->assertStatus(429);

        $this->assertEquals(1, SchoolDataExport::withoutGlobalScopes()->count());
    }

    // ─── Downloading ────────────────────────────────────────────────

    public function test_a_completed_export_downloads_as_a_zip_and_is_audited()
    {
        $s = $this->seedSchool();

        $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', [])->assertStatus(202);
        $export = SchoolDataExport::withoutGlobalScopes()->first();

        $response = $this->asAdmin($s)->get(self::HOST . '/admin/exports/' . $export->id . '/download');

        $response->assertStatus(200);
        $this->assertStringContainsString('.zip', $response->headers->get('content-disposition'));

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $s['school']->id,
            'action' => 'school.export_downloaded',
            'auditable_id' => $export->id,
        ]);
    }

    public function test_an_expired_export_is_refused()
    {
        $s = $this->seedSchool();

        $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', [])->assertStatus(202);

        $export = SchoolDataExport::withoutGlobalScopes()->first();
        $export->update(['expires_at' => now()->subDay()]);

        $this->asAdmin($s)->get(self::HOST . '/admin/exports/' . $export->id . '/download')
            ->assertStatus(409);
    }

    public function test_one_school_cannot_download_anothers_archive()
    {
        $s = $this->seedSchool();

        $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', [])->assertStatus(202);
        $export = SchoolDataExport::withoutGlobalScopes()->first();

        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $otherAdmin = User::factory()->create();
        UserProfile::create(['school_id' => $other->id, 'user_id' => $otherAdmin->id, 'role' => 'school_admin']);

        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $otherAdmin->createToken('t')->plainTextToken)
            ->get('http://other.localhost/api/v1/admin/exports/' . $export->id . '/download')
            ->assertStatus(404);
    }

    public function test_the_sweep_deletes_expired_archives_but_keeps_the_record()
    {
        $s = $this->seedSchool();

        $this->asAdmin($s)->postJson(self::HOST . '/admin/exports', [])->assertStatus(202);

        $export = SchoolDataExport::withoutGlobalScopes()->first();
        $path = $export->file_path;
        $export->update(['expires_at' => now()->subDay()]);

        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->artisan('exports:sweep')->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists($path));

        $export->refresh();
        $this->assertEquals('expired', $export->status);
        $this->assertNull($export->file_path);
        $this->assertNotNull($export->row_counts);
    }

    /**
     * The endpoint that had never returned a successful response: it eager-loaded
     * `class` and `arm`, which are not relations on Student.
     */
    public function test_the_legacy_inline_export_endpoint_no_longer_throws()
    {
        $s = $this->seedSchool();

        $this->asAdmin($s)->postJson(self::HOST . '/admin/export-data', [])
            ->assertStatus(200)
            ->assertJsonPath('export.total_students', 1);
    }
}
