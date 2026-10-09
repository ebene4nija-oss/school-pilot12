<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\PartnerMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sales material SchoolPilot uploads for partners, and what partners see. */
class PartnerMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private User $partnerUser;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->operator = $this->person('Ops', 'super_admin');
        $this->partnerUser = $this->person('Chidi Okafor', 'partner');
        $this->partner = Partner::create([
            'code' => 'CHIDI1', 'name' => 'Chidi Okafor', 'email' => 'chidi-okafor@x.test',
            'status' => 'active', 'approved_at' => now(), 'user_id' => $this->partnerUser->id,
        ]);
    }

    private function person(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@x.test', 'password' => bcrypt('password')]);
        $u->userProfile()->create(['school_id' => null, 'role' => $role]);

        return $u;
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    public function test_a_script_reaches_the_partner_with_their_own_link_and_code(): void
    {
        $this->as($this->operator)->postJson('/api/v1/platform/partner-materials', [
            'title' => 'WhatsApp intro', 'category' => 'script', 'kind' => 'text',
            'body' => 'Hello, see {link} (code {code}) — {name}',
        ])->assertCreated();

        $res = $this->as($this->partnerUser)->getJson('/api/v1/partner/materials')->assertOk();
        $body = $res->json('data.0.body');

        $this->assertStringContainsString('/?p=CHIDI1', $body);
        $this->assertStringContainsString('code CHIDI1', $body);
        $this->assertStringContainsString('Chidi Okafor', $body);
        $this->assertArrayNotHasKey('is_published', $res->json('data.0'));
    }

    public function test_files_are_private_and_only_published_ones_reach_partners(): void
    {
        $id = $this->as($this->operator)->post('/api/v1/platform/partner-materials', [
            'title' => 'Feature guide', 'category' => 'brochure', 'kind' => 'file',
            'file' => UploadedFile::fake()->create('SchoolPilot-Feature-Guide.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->as($this->partnerUser)->get("/api/v1/partner/materials/{$id}/download")
            ->assertOk()->assertDownload('SchoolPilot-Feature-Guide.pdf');

        // Unpublished: gone from the list and the download.
        $this->as($this->operator)->putJson("/api/v1/platform/partner-materials/{$id}", ['is_published' => false])->assertOk();
        $this->as($this->partnerUser)->getJson('/api/v1/partner/materials')->assertJson(['data' => []]);
        $this->as($this->partnerUser)->get("/api/v1/partner/materials/{$id}/download")->assertNotFound();

        // Deleting removes the stored file too.
        $path = PartnerMaterial::find($id)->file_path;
        $this->as($this->operator)->deleteJson("/api/v1/platform/partner-materials/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_file_can_be_replaced_and_dangerous_files_are_refused(): void
    {
        $id = $this->as($this->operator)->post('/api/v1/platform/partner-materials', [
            'title' => 'Post', 'category' => 'social', 'kind' => 'file', 'file' => UploadedFile::fake()->image('post1.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $old = PartnerMaterial::find($id)->file_path;

        $this->as($this->operator)->post("/api/v1/platform/partner-materials/{$id}", ['file' => UploadedFile::fake()->image('post2.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.file_name', 'post2.png');
        Storage::disk('local')->assertMissing($old);

        $this->as($this->operator)->post('/api/v1/platform/partner-materials', [
            'title' => 'Bad', 'category' => 'other', 'kind' => 'file', 'file' => UploadedFile::fake()->create('run.exe', 10),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_only_the_operator_manages_and_a_suspended_partner_cannot_download(): void
    {
        $this->as($this->partnerUser)->postJson('/api/v1/platform/partner-materials', ['title' => 'x', 'category' => 'other', 'kind' => 'link', 'url' => 'https://x.test'])->assertForbidden();

        $id = $this->as($this->operator)->post('/api/v1/platform/partner-materials', [
            'title' => 'Guide', 'category' => 'brochure', 'kind' => 'file', 'file' => UploadedFile::fake()->create('g.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->json('data.id');

        $this->partner->update(['status' => 'suspended']);
        $this->as($this->partnerUser)->get("/api/v1/partner/materials/{$id}/download")->assertNotFound();
    }

    public function test_the_starter_kit_loads_once(): void
    {
        $this->artisan('partners:seed-materials', ['--from' => sys_get_temp_dir().'/no-such-folder'])->assertSuccessful();
        $scripts = PartnerMaterial::where('kind', 'text')->count();
        $this->assertGreaterThanOrEqual(3, $scripts);

        $this->artisan('partners:seed-materials', ['--from' => sys_get_temp_dir().'/no-such-folder'])->assertSuccessful();
        $this->assertSame($scripts, PartnerMaterial::where('kind', 'text')->count());
    }
}
