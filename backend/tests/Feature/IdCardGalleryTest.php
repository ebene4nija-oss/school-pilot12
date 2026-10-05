<?php

namespace Tests\Feature;

use App\Models\IdCardTemplate;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\IdCard\IdCardGallery;
use App\Services\IdCard\IdCardIssuanceService;
use App\Services\IdCard\IdCardPrintService;
use App\Services\IdCard\IdCardTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Ready-made ID card designs in the school's own colours (TASK 18). */
class IdCardGalleryTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://graceland.localhost');
        Http::preventStrayRequests();

        $this->school = School::create(['name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland', 'phone' => '+234 803 000 1111']);
        $this->admin = User::create(['name' => 'Mr Bello', 'email' => 'bello@graceland.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $this->admin->id, 'role' => 'school_admin']);
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    public function test_three_designs_for_students_and_three_for_staff(): void
    {
        $designs = collect($this->asAdmin()->getJson('/api/v1/id-cards/gallery')->assertOk()->json('designs'));

        $this->assertSame(3, $designs->where('holder_type', 'student')->count());
        $this->assertSame(3, $designs->where('holder_type', 'staff')->count());
        $this->assertSame(['portrait'], $designs->where('key', 'portrait-lanyard')->pluck('orientation')->all());
    }

    public function test_every_design_imports_cleanly_with_nothing_stripped(): void
    {
        $service = app(IdCardTemplateService::class);

        foreach (array_keys(IdCardGallery::DESIGNS) as $key) {
            $template = $service->import(app(IdCardGallery::class)->bundle($key), $this->school->id, $this->admin->id);
            $report = $template->validation_report;

            $this->assertSame([], $report['markup_removals'], "{$key}: markup was stripped");
            $this->assertSame([], $report['style_removals'], "{$key}: CSS was stripped");
            $this->assertTrue($report['double_sided'], "{$key}: has a back");
        }
    }

    public function test_the_preview_uses_the_school_and_a_scannable_code_and_fits_a_long_name(): void
    {
        $html = $this->asAdmin()->get('/api/v1/id-cards/gallery/portrait-lanyard/preview?primary=%23800000')->assertOk()->getContent();

        $this->assertStringContainsString('GRACELAND COLLEGE', $html);
        $this->assertStringContainsString('+234 803 000 1111', $html);
        $this->assertStringContainsString('data:image/png;base64', $html, 'A sample QR code is drawn.');
        $this->assertStringContainsString('#800000', $html);
        $this->assertStringContainsString('fit-normal', $html);

        $long = $this->asAdmin()->get('/api/v1/id-cards/gallery/portrait-lanyard/preview?long_name=1')->getContent();
        $this->assertStringContainsString('fit-very-long', $long);
        $this->assertStringContainsString('OLUWADAMILARE CHUKWUEMEKA ADEBAYO-OKONKWO', $long);
    }

    public function test_text_stays_readable_on_whatever_colour_is_chosen(): void
    {
        $gallery = app(IdCardGallery::class);

        // A dark card coloured pale: the text turns dark.
        $pale = $gallery->bundle('executive-dark', '#f5f0e6')['styles'];
        $this->assertStringContainsString('color: #12141a; background: #f5f0e6', $pale);

        $dark = $gallery->bundle('executive-dark')['styles'];
        $this->assertStringContainsString('color: #ffffff; background: #111827', $dark);

        $this->asAdmin()->get('/api/v1/id-cards/gallery/classic-band/preview?primary=red')->assertStatus(422);
        $this->asAdmin()->postJson('/api/v1/id-cards/gallery/classic-band', ['primary' => 'blue'])->assertStatus(422);
        $this->asAdmin()->getJson('/api/v1/id-cards/gallery/no-such-design/preview')->assertStatus(422);
    }

    public function test_using_a_design_makes_it_live_and_a_recolour_is_a_new_version(): void
    {
        $this->asAdmin()->postJson('/api/v1/id-cards/gallery/classic-band', ['primary' => '#0b3d91'])->assertCreated()->assertJsonPath('template.status', 'draft');
        $live = $this->asAdmin()->postJson('/api/v1/id-cards/gallery/modern-stripe', ['primary' => '#0b3d91', 'accent' => '#f59e0b', 'activate' => true])->assertCreated();
        $this->assertSame('active', $live->json('template.status'));

        $again = $this->asAdmin()->postJson('/api/v1/id-cards/gallery/modern-stripe', ['primary' => '#7a1f5c', 'activate' => true])->assertCreated();
        $this->assertSame(2, $again->json('template.version'));
        $this->assertSame('archived', IdCardTemplate::withoutGlobalScopes()->find($live->json('template.id'))->status);

        $designs = collect($this->asAdmin()->getJson('/api/v1/id-cards/gallery')->json('designs'))->keyBy('key');
        $this->assertTrue($designs['modern-stripe']['live']);
        $this->assertSame('#7a1f5c', $designs['modern-stripe']['school_primary']);
        $this->assertFalse($designs['classic-band']['live']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'id_card.gallery_design_used']);

        // Staff designs are a separate choice and leave the student design alone.
        $this->asAdmin()->postJson('/api/v1/id-cards/gallery/staff-lanyard', ['activate' => true])->assertCreated();
        $this->assertSame('active', IdCardTemplate::withoutGlobalScopes()->find($again->json('template.id'))->status);
    }

    public function test_a_real_card_prints_from_a_gallery_design(): void
    {
        $this->asAdmin()->postJson('/api/v1/id-cards/gallery/portrait-lanyard', ['activate' => true])->assertCreated();
        $user = User::create(['name' => 'Oluwadamilare Chukwuemeka Adebayo-Okonkwo', 'email' => 'o@graceland.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'role' => 'student']);
        $student = Student::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => $this->class->id, 'admission_number' => 'GC/2024/0001', 'status' => 'active']);

        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $student->id);
        $template = app(IdCardTemplateService::class)->activeTemplateFor($this->school->id, 'student');
        $sides = app(IdCardPrintService::class)->renderCard($card, $student->fresh(), $template, ['photo' => '']);

        $this->assertStringContainsString('fit-very-long', $sides['front']);
        $this->assertStringContainsString('GC/2024/0001', $sides['front']);
        $this->assertStringContainsString('It is not a key', $sides['back']);
    }

    public function test_only_an_admin_chooses_the_design(): void
    {
        $teacher = User::create(['name' => 'Mrs Ade', 'email' => 'ade@graceland.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $teacher->id, 'role' => 'teacher']);

        $this->actingAs($teacher, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost'])
            ->postJson('/api/v1/id-cards/gallery/classic-band', ['activate' => true])->assertForbidden();
    }
}
