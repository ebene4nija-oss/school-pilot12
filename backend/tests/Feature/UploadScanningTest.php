<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Security\UploadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Every uploaded file is looked inside before it is kept. */
class UploadScanningTest extends TestCase
{
    use RefreshDatabase;

    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function check(string $name, string $content): ?string
    {
        return app(UploadScanner::class)->check($this->file($name, $content));
    }

    public function test_programs_are_refused_whatever_they_are_called(): void
    {
        $this->assertSame('This file is a program, not a document. It was not uploaded.', $this->check('notes.pdf', "MZ\x90\x00 This program cannot be run in DOS mode"));
        $this->assertNotNull($this->check('photo.jpg', "\x7FELF\x02\x01\x01"));
        $this->assertSame("Files of type .exe can't be uploaded.", $this->check('setup.exe', 'anything'));
        $this->assertNotNull($this->check('lesson.svg', '<svg/>'), 'SVG can carry scripts.');
    }

    public function test_hidden_code_scripted_pdfs_and_macros_are_refused(): void
    {
        $this->assertSame('This file has code hidden inside it. It was not uploaded.', $this->check('passport.jpg', "\xFF\xD8\xFF\xE0 junk <?php echo 'hello'; ?>"));
        $this->assertNotNull($this->check('form.pdf', "%PDF-1.7\n1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>"));
        $this->assertNotNull($this->check('marks.xlsm', 'PK'));

        // A .docx is a zip; entry names are stored as plain text in it.
        $docx = "PK\x03\x04\x14\x00\x00\x00\x08\x00".'[Content_Types].xml'.str_repeat('x', 40)."PK\x01\x02".'word/vbaProject.bin'."PK\x05\x06";
        $this->assertSame('This Office file contains macros. Save it without macros and upload that.', $this->check('lesson.docx', $docx));
    }

    public function test_ordinary_files_pass(): void
    {
        $this->assertNull($this->check('notes.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF"));
        $this->assertNull($this->check('scores.csv', "MZ_code,score\nA1,10\n"), 'A CSV starting with MZ is still a CSV.');
        $this->assertNull($this->check('code-lesson.txt', 'In PHP a file starts with <?php'));
        $this->assertNull($this->check('photo.png', "\x89PNG\r\n\x1a\n".random_bytes(64)));
    }

    public function test_when_the_virus_scanner_is_configured_but_down_uploads_wait(): void
    {
        config(['services.clamav.host' => '127.0.0.1', 'services.clamav.port' => 1]);
        $this->assertSame('Uploads are paused while the virus scanner is unavailable. Try again shortly.', $this->check('notes.pdf', '%PDF-1.4'));

        config(['services.clamav.fail_open' => true]);
        $this->assertNull($this->check('notes.pdf', '%PDF-1.4'));
    }

    public function test_the_api_refuses_a_disguised_program_before_any_controller_keeps_it(): void
    {
        $school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $teacher = User::create(['name' => 'Mr Chidi', 'email' => 'chidi@hilltop.test', 'password' => bcrypt('password123')]);
        $teacher->userProfile()->create(['school_id' => $school->id, 'role' => 'teacher']);

        $this->actingAs($teacher, 'sanctum')->post('http://hilltop.schoolpilot.test/api/v1/learning-materials', [
            'title' => 'Week 3 notes', 'file' => $this->file('week3.pdf', "MZ\x90\x00 This program cannot be run in DOS mode"),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'This file is a program, not a document. It was not uploaded.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'upload.blocked', 'user_id' => $teacher->id]);
        $this->assertDatabaseCount('learning_materials', 0);
    }
}
