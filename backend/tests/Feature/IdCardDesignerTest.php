<?php

namespace Tests\Feature;

use App\Models\IdCardTemplate;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\IdCard\CardLayoutCompiler;
use App\Services\IdCard\IdCardIssuanceService;
use App\Services\IdCard\IdCardPrintService;
use App\Services\IdCard\IdCardTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** A school's own artwork with live fields placed on it (TASK 18). */
class IdCardDesignerTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://graceland.localhost');
        Http::preventStrayRequests();
        Storage::fake('local');

        $this->school = School::create(['name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->admin = User::create(['name' => 'Mr Bello', 'email' => 'bello@graceland.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $this->admin->id, 'role' => 'school_admin']);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    /** A PNG of the given size, optionally carrying a text chunk. */
    private function png(int $w, int $h, ?string $text = null): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        if ($text !== null) {
            $chunk = 'tEXt'.'Comment'."\0".$text;
            $bytes = substr($bytes, 0, 33).pack('N', strlen($chunk) - 4).$chunk.pack('N', crc32($chunk)).substr($bytes, 33);
        }
        $path = tempnam(sys_get_temp_dir(), 'bg').'.png';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'card-front.png', 'image/png', null, true);
    }

    private function upload(UploadedFile $file, string $orientation = 'landscape')
    {
        return $this->asAdmin()->post('/api/v1/id-cards/designer/backgrounds', ['image' => $file, 'orientation' => $orientation], ['Accept' => 'application/json']);
    }

    /** A complete layout: name, class, admission number, photo and QR on the front. */
    private function layout(array $front = []): array
    {
        return [
            'orientation' => 'landscape',
            'front' => ['elements' => $front ?: [
                ['type' => 'photo', 'x' => 4, 'y' => 12, 'w' => 22, 'h' => 28],
                ['type' => 'field', 'field' => 'holder.name', 'x' => 30, 'y' => 14, 'w' => 36, 'h' => 8, 'font_size' => 9, 'bold' => true, 'upper' => true, 'colour' => '#ffffff'],
                ['type' => 'field', 'field' => 'holder.class', 'x' => 30, 'y' => 24, 'w' => 30, 'h' => 4, 'font_size' => 6],
                ['type' => 'field', 'field' => 'holder.id_value', 'x' => 30, 'y' => 30, 'w' => 36, 'h' => 4, 'font_size' => 6, 'show_caption' => true],
                ['type' => 'qr', 'x' => 68, 'y' => 34, 'w' => 14, 'h' => 14],
                ['type' => 'text', 'text' => 'Knowledge & <Service>', 'x' => 4, 'y' => 44, 'w' => 50, 'h' => 4, 'font_size' => 5],
            ]],
            'back' => ['elements' => []],
        ];
    }

    public function test_artwork_is_redrawn_by_the_server_and_its_resolution_checked(): void
    {
        $res = $this->upload($this->png(1011, 638, 'SECRET-MARKER-123'))->assertCreated();
        $ref = $res->json('ref');
        $this->assertSame([], $res->json('warnings'));

        $stored = Storage::disk('local')->get("id-card-designs/{$this->school->id}/{$ref}.jpg");
        $this->assertStringStartsWith("\xFF\xD8", $stored, 'Saved as a fresh JPEG.');
        $this->assertStringNotContainsString('SECRET-MARKER-123', $stored, 'Nothing from the original file survives but pixels.');

        $this->assertStringContainsString('little soft', implode(' ', $this->upload($this->png(600, 380))->assertCreated()->json('warnings')));
        $this->assertStringContainsString('stretched', implode(' ', $this->upload($this->png(1011, 638), 'portrait')->json('warnings')));
        $this->upload($this->png(300, 190))->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'too small to print'));

        $this->asAdmin()->get("/api/v1/id-cards/designer/backgrounds/{$ref}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_card_without_a_name_or_a_qr_code_is_refused_with_a_reason(): void
    {
        $noName = $this->layout([['type' => 'qr', 'x' => 60, 'y' => 30, 'w' => 14, 'h' => 14]]);
        $this->asAdmin()->postJson('/api/v1/id-cards/designer/preview', ['holder_type' => 'student', 'layout' => $noName])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, "holder's name"));

        $noQr = $this->layout([['type' => 'field', 'field' => 'holder.name', 'x' => 5, 'y' => 5, 'w' => 40, 'h' => 6]]);
        $this->asAdmin()->postJson('/api/v1/id-cards/designer/preview', ['holder_type' => 'student', 'layout' => $noQr])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'QR code'));
    }

    public function test_the_preview_draws_the_artwork_once_and_escapes_free_text(): void
    {
        $ref = $this->upload($this->png(1011, 638))->json('ref');

        $res = $this->asAdmin()->postJson('/api/v1/id-cards/designer/preview', [
            'holder_type' => 'student', 'layout' => $this->layout(), 'assets' => ['front_background' => $ref], 'long_name' => true,
        ])->assertOk();
        $html = $res->json('html');

        $this->assertSame(1, substr_count($html, 'background-image: url(data:image/jpeg;base64,'));
        $this->assertStringContainsString('OLUWADAMILARE CHUKWUEMEKA ADEBAYO-OKONKWO', $html);
        $this->assertStringContainsString('fit-very-long', $html);
        $this->assertStringContainsString('Knowledge &amp; &lt;Service&gt;', $html);
        $this->assertStringContainsString('Admission No.', $html, 'The caption is shown when asked for.');

        $braces = $this->layout();
        $braces['front']['elements'][5]['text'] = '{{ holder.blood_group }}';
        $this->asAdmin()->postJson('/api/v1/id-cards/designer/preview', ['holder_type' => 'student', 'layout' => $braces])->assertStatus(422);
    }

    public function test_guard_rails_keep_the_qr_scannable_and_items_on_the_card(): void
    {
        $compiled = app(CardLayoutCompiler::class)->compile($this->layout([
            ['type' => 'field', 'field' => 'holder.name', 'x' => 0.5, 'y' => 1, 'w' => 40, 'h' => 6, 'font_size' => 4.2],
            ['type' => 'qr', 'x' => 80, 'y' => 50, 'w' => 6, 'h' => 6],
            ['type' => 'box', 'x' => -10, 'y' => -10, 'w' => 500, 'h' => 4, 'colour' => '#b42318'],
        ]), 'Test', 'student');

        $css = $compiled['bundle']['styles'];
        $this->assertStringContainsString('.e-front-1 { left: 73.60mm; top: 42.00mm; width: 12.00mm; height: 12.00mm; }', $css, 'QR grown to 12mm, square, and kept on the card.');
        $this->assertStringContainsString('.e-front-2 { left: 0.00mm; top: 0.00mm; width: 85.60mm;', $css, 'Box clamped to the card.');
        $warnings = implode(' ', $compiled['warnings']);
        $this->assertStringContainsString('Name is within 2mm of the edge', $warnings);
        $this->assertStringContainsString('smaller than 5pt', $warnings);
    }

    public function test_saving_makes_it_live_and_a_real_card_prints_from_it(): void
    {
        $ref = $this->upload($this->png(1011, 638))->json('ref');
        $saved = $this->asAdmin()->postJson('/api/v1/id-cards/designer', [
            'name' => 'Graceland 2026', 'holder_type' => 'student', 'layout' => $this->layout(), 'assets' => ['front_background' => $ref], 'activate' => true,
        ])->assertCreated();
        $this->assertSame('active', $saved->json('template.status'));
        $this->assertSame('designer-graceland-2026', $saved->json('template.slug'));
        $this->assertSame('landscape', $saved->json('template.regions.0.designer.orientation'), 'The layout is kept for editing later.');

        // Saving again under the same name is version 2.
        $this->asAdmin()->postJson('/api/v1/id-cards/designer', ['name' => 'Graceland 2026', 'holder_type' => 'student', 'layout' => $this->layout(), 'assets' => ['front_background' => $ref]])
            ->assertCreated()->assertJsonPath('template.version', 2);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $user = User::create(['name' => 'Adaeze Nwosu', 'email' => 'a@graceland.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'role' => 'student']);
        $student = Student::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => $class->id, 'admission_number' => 'GC/2024/0187', 'status' => 'active']);
        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $student->id);

        $template = app(IdCardTemplateService::class)->activeTemplateFor($this->school->id, 'student');
        $sides = app(IdCardPrintService::class)->renderCard($card, $student->fresh(), $template, ['photo' => '']);
        $this->assertStringContainsString('ADAEZE NWOSU', $sides['front']);
        $this->assertStringContainsString('GC/2024/0187', $sides['front']);
        $this->assertStringContainsString('JSS 2', $sides['front']);
        $this->assertStringContainsString('data:image/png;base64', $sides['front'], 'The real QR code is drawn.');
        $this->assertStringContainsString('background-image: url(data:image/jpeg', app(IdCardTemplateService::class)->stylesFor($template));
    }

    public function test_another_schools_artwork_cannot_be_used(): void
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        Storage::disk('local')->put("id-card-designs/{$other->id}/".str_repeat('a', 32).'.jpg', 'x');

        $this->asAdmin()->postJson('/api/v1/id-cards/designer/preview', [
            'holder_type' => 'student', 'layout' => $this->layout(), 'assets' => ['front_background' => str_repeat('a', 32)],
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'missing'));
        $this->asAdmin()->get('/api/v1/id-cards/designer/backgrounds/'.str_repeat('a', 32))->assertNotFound();
    }

    public function test_only_an_admin_designs_cards(): void
    {
        $teacher = User::create(['name' => 'Mrs Ade', 'email' => 'ade@graceland.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $teacher->id, 'role' => 'teacher']);

        $this->actingAs($teacher, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost'])
            ->postJson('/api/v1/id-cards/designer', ['name' => 'X', 'holder_type' => 'student', 'layout' => $this->layout()])->assertForbidden();
        $this->assertSame(0, IdCardTemplate::withoutGlobalScopes()->count());
    }
}
