<?php

namespace Tests\Feature;

use App\Models\AiUsageDaily;
use App\Models\School;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiUnavailableException;
use App\Services\ClaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Claude, ChatGPT and Gemini behind one gateway: SchoolPilot sets the default
 * and the keys, a school may choose among providers that have a key, and the
 * cap, the ledger and the no-invented-output rules hold for each of them.
 */
class AiProvidersTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield']);
        $this->admin = $this->person('Head', 'admin@gf.test', 'school_admin');
        $this->teacher = $this->person('Mr Bello', 'bello@gf.test', 'teacher');

        config()->set('services.anthropic.stub', false);
        config()->set('services.anthropic.api_key', null);
        config()->set('services.openai.api_key', null);
        config()->set('services.gemini.api_key', null);
    }

    private function person(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function comment(): string
    {
        return app(ClaudeService::class)->generateReportCardComment([
            'school_id' => $this->school->id,
            'student_name' => 'Chinelo',
            'subject' => 'Mathematics',
            'total_score' => 78,
        ]);
    }

    public function test_openai_as_the_default_writes_and_is_metered(): void
    {
        config()->set('services.ai.provider', 'openai');
        config()->set('services.openai.api_key', 'sk-openai-test');

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Chinelo has worked steadily.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40, 'prompt_tokens_details' => ['cached_tokens' => 30]],
        ])]);

        $this->assertSame('Chinelo has worked steadily.', $this->comment());

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.openai.com/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer sk-openai-test')
            && $r['model'] === 'gpt-5-mini'
            && $r['messages'][0]['role'] === 'user'
            && ! isset($r['temperature']));

        $row = AiUsageDaily::where('school_id', $this->school->id)->first();
        $this->assertSame(70, $row->input_tokens);
        $this->assertSame(30, $row->cache_read_tokens);
        $this->assertSame(40, $row->output_tokens);
        $this->assertGreaterThan(0, $row->cost_kobo);
    }

    public function test_gemini_gets_the_system_prompt_and_its_thinking_is_billed(): void
    {
        config()->set('services.ai.provider', 'gemini');
        config()->set('services.gemini.api_key', 'gm-test');

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => '{"objectives":["Add fractions"]}']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 50, 'thoughtsTokenCount' => 25],
        ])]);

        $plan = app(ClaudeService::class)->teachingJson('Write a lesson plan.', 'Fractions, Primary 5', $this->school->id, ['objectives' => []]);

        $this->assertSame(['Add fractions'], $plan['objectives']);
        $this->assertArrayNotHasKey('stub', $plan);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/models/gemini-2.5-pro:generateContent')
            && $r->hasHeader('x-goog-api-key', 'gm-test')
            && str_contains($r['systemInstruction']['parts'][0]['text'], 'Nigerian')
            && $r['contents'][0]['parts'][0]['text'] === 'Fractions, Primary 5');

        $this->assertSame(75, AiUsageDaily::where('school_id', $this->school->id)->value('output_tokens'));
    }

    public function test_gemini_receives_a_conversation_with_its_own_role_names(): void
    {
        config()->set('services.ai.provider', 'gemini');
        config()->set('services.gemini.api_key', 'gm-test');

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'What is the first step?']]], 'finishReason' => 'STOP']],
        ])]);

        app(AiGateway::class)->complete($this->school->id, 'comment', 'Tutor.', [
            ['role' => 'user', 'content' => 'Help with 2x = 10'],
            ['role' => 'assistant', 'content' => 'What do you do to both sides?'],
            ['role' => 'user', 'content' => 'Divide?'],
        ], 800);

        Http::assertSent(fn (HttpRequest $r) => array_column($r['contents'], 'role') === ['user', 'model', 'user']);
    }

    public function test_a_blocked_or_truncated_reply_is_never_passed_off_as_an_answer(): void
    {
        config()->set('services.ai.provider', 'gemini');
        config()->set('services.gemini.api_key', 'gm-test');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);

        try {
            $this->comment();
            $this->fail('A blocked reply must not become a remark.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('declined', $e->getMessage());
        }

        Cache::flush();
        config()->set('services.ai.provider', 'openai');
        config()->set('services.openai.api_key', 'sk-openai-test');
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']],
        ])]);

        $this->expectExceptionMessage('cut short');
        $this->comment();
    }

    public function test_no_key_for_the_chosen_provider_fails_loudly(): void
    {
        config()->set('services.ai.provider', 'openai');
        Http::fake();

        $this->expectException(AiUnavailableException::class);
        $this->expectExceptionMessage('OpenAI (ChatGPT)');

        try {
            $this->comment();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_school_can_choose_only_a_provider_schoolpilot_has_set_up(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-test');

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/school/ai-provider', ['provider' => 'gemini'])
            ->assertStatus(422);

        config()->set('services.gemini.api_key', 'gm-test');

        $res = $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/school/ai-provider', ['provider' => 'gemini'])
            ->assertOk();
        $this->assertSame('gemini', $res->json('ai.provider.in_use'));
        $this->assertSame('gemini', $this->school->fresh()->ai_provider);
        $this->assertDatabaseHas('audit_logs', ['school_id' => $this->school->id, 'action' => 'school.ai_provider.updated']);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Chinelo is improving.']]], 'finishReason' => 'STOP']],
        ])]);
        $this->assertSame('Chinelo is improving.', $this->comment());
        Http::assertSentCount(1);

        // The key is withdrawn: the school falls back to the default rather
        // than failing on a provider it can no longer use.
        config()->set('services.gemini.api_key', null);
        $this->assertSame('anthropic', app(AiGateway::class)->resolve($this->school->id)['provider']);

        // Back to SchoolPilot's default.
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/school/ai-provider', ['provider' => null])->assertOk();
        $this->assertNull($this->school->fresh()->ai_provider);
    }

    public function test_teachers_cannot_change_the_provider(): void
    {
        config()->set('services.openai.api_key', 'sk-openai-test');

        $this->actingAs($this->teacher, 'sanctum')->putJson('/api/v1/school/ai-provider', ['provider' => 'openai'])
            ->assertForbidden();
    }

    public function test_the_features_page_lists_which_providers_are_available(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-test');
        config()->set('services.openai.api_key', 'sk-openai-test');

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/school/features')->assertOk();

        $this->assertSame('anthropic', $res->json('ai.provider.default'));
        $this->assertSame(
            ['anthropic' => true, 'openai' => true, 'gemini' => false],
            collect($res->json('ai.provider.options'))->pluck('available', 'key')->all(),
        );
        // Keys are never sent to the school.
        $this->assertStringNotContainsString('sk-openai-test', $res->getContent());
    }

    public function test_the_operator_sets_the_default_provider_and_keys_from_the_console(): void
    {
        $operator = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('password')]);
        $operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);

        $this->actingAs($operator, 'sanctum')->putJson('/api/v1/platform/settings', ['values' => [
            'services.ai.provider' => 'gemini',
            'services.gemini.api_key' => 'gm-console-key',
            'services.gemini.comment_model' => 'gemini-2.5-flash-lite',
        ]])->assertOk();

        $this->assertSame('gemini', AiGateway::defaultProvider());
        $this->assertSame('gemini-2.5-flash-lite', app(AiGateway::class)->resolve($this->school->id)['model']);

        $this->actingAs($operator, 'sanctum')->putJson('/api/v1/platform/settings', ['values' => ['services.ai.provider' => 'mistral']])
            ->assertStatus(422);
    }
}
