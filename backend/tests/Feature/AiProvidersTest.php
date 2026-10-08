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

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/models/gemini-3.8-flash:generateContent')
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

    public function test_a_remark_prompt_never_carries_the_childs_name(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-test');

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '[NAME] has worked steadily in Mathematics this term.']],
            'stop_reason' => 'end_turn',
        ])]);

        $remark = app(ClaudeService::class)->generateReportCardComment([
            'school_id' => $this->school->id,
            'student_name' => 'Chinelo Okafor',
            'subject' => 'Mathematics',
            'total_score' => 78,
        ]);

        $this->assertSame('Chinelo has worked steadily in Mathematics this term.', $remark);
        Http::assertSent(fn (HttpRequest $r) => ! str_contains(json_encode($r->data()), 'Chinelo')
            && ! str_contains(json_encode($r->data()), 'Okafor')
            && str_contains(json_encode($r->data()), 'Mathematics'));
    }

    public function test_thinking_models_get_room_to_answer_and_others_do_not(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-test');
        config()->set('services.openai.api_key', 'sk-openai-test');

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Good work.']]]),
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Good work.'], 'finish_reason' => 'stop']]]),
        ]);

        $gateway = app(AiGateway::class);
        $gateway->complete($this->school->id, 'comment', null, [['role' => 'user', 'content' => 'Hi']], 150);

        config()->set('services.ai.provider', 'openai');
        $gateway->complete($this->school->id, 'comment', null, [['role' => 'user', 'content' => 'Hi']], 150);

        // Haiku answers directly; GPT-5 mini reasons first and counts it.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic') && $r['max_tokens'] === 150);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'openai') && $r['max_completion_tokens'] === 4000);
    }

    public function test_gradebook_remarks_use_the_schools_provider_and_are_metered(): void
    {
        config()->set('services.openai.api_key', 'sk-openai-test');
        $this->school->ai_provider = 'openai';
        $this->school->save();

        $session = \App\Models\AcademicSession::create(['school_id' => $this->school->id, 'name' => '2025/2026', 'start_date' => now(), 'end_date' => now()->addYear()]);
        $term = \App\Models\Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now(), 'end_date' => now()->addMonths(3)]);
        $child = User::create(['name' => 'Adaeze Nwosu', 'email' => 'ada@gf.test', 'password' => bcrypt('password')]);
        $student = \App\Models\Student::create(['school_id' => $this->school->id, 'user_id' => $child->id, 'gender' => 'female']);
        $subject = \App\Models\Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        $score = \App\Models\ScoreEntry::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'total_score' => 88]);

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '[NAME] has done excellent work.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 300],
        ])]);

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/v1/assessment/score/{$score->id}/ai-comment")
            ->assertOk()
            ->assertJsonPath('score_entry.ai_comment_status', 'pending_approval')
            ->assertJsonPath('score_entry.teacher_comment', 'Adaeze has done excellent work.');

        Http::assertSent(fn (HttpRequest $r) => ! str_contains(json_encode($r->data()), 'Adaeze'));
        $this->assertSame(300, AiUsageDaily::where('school_id', $this->school->id)->value('output_tokens'));
    }

    private function operator(): User
    {
        $operator = User::create(['name' => 'Ops2', 'email' => 'ops2@schoolpilot.test', 'password' => bcrypt('password')]);
        $operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);

        return $operator;
    }

    public function test_the_switcher_lists_providers_models_prices_and_presets_without_keys(): void
    {
        config()->set('services.openai.api_key', 'sk-openai-SECRET');
        config()->set('services.gemini.comment_model', 'gemini-9-experimental');

        $res = $this->actingAs($this->operator(), 'sanctum')->getJson('/api/v1/platform/ai/models')->assertOk();

        $providers = collect($res->json('providers'))->keyBy('key');
        $this->assertSame(['anthropic', 'openai', 'gemini'], $providers->keys()->all());
        $this->assertTrue($providers['openai']['key_set']);
        $this->assertFalse($providers['gemini']['key_set']);
        $this->assertTrue($providers['anthropic']['is_default']);
        $this->assertContains('claude-opus-5-5', array_column($providers['anthropic']['models'], 'id'));
        $this->assertSame(['comment' => 'claude-haiku-4-5', 'document' => 'claude-sonnet-5-5'], $providers['anthropic']['presets']['balanced']);
        $this->assertSame(['gemini-9-experimental'], $providers['gemini']['unpriced']);
        $this->assertStringNotContainsString('SECRET', $res->getContent());

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/platform/ai/models')->assertForbidden();
    }

    public function test_a_model_must_belong_to_its_provider_but_new_ids_are_allowed(): void
    {
        $operator = $this->operator();
        $save = fn (array $values) => $this->actingAs($operator, 'sanctum')->putJson('/api/v1/platform/settings', ['values' => $values]);

        $save(['services.anthropic.document_model' => 'gpt-6.1-sol'])->assertStatus(422);
        $save(['services.openai.comment_model' => 'gemini-3.8-flash'])->assertStatus(422);
        $save(['services.gemini.document_model' => 'gemini 3.8 flash'])->assertStatus(422);

        // A model released after this list was written can still be used.
        $save(['services.gemini.document_model' => 'gemini-4-pro'])->assertOk();
        $this->assertSame('gemini-4-pro', config('services.gemini.document_model'));

        // A preset is two ordinary settings saved together.
        $save(['services.openai.comment_model' => 'gpt-6.1-sol', 'services.openai.document_model' => 'gpt-6-astra'])->assertOk();
        $this->assertSame('gpt-6.1-sol', config('services.openai.comment_model'));
        $this->assertSame('gpt-6-astra', config('services.openai.document_model'));
    }

    public function test_the_test_button_makes_one_small_unmetered_call(): void
    {
        config()->set('services.gemini.api_key', 'gm-test');
        // A good reply, then a rejected key (sent three times: the call retries).
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push([
                'candidates' => [['content' => ['parts' => [['text' => 'ready']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 8, 'candidatesTokenCount' => 1],
            ])
            ->whenEmpty(Http::response(['error' => ['message' => 'API key not valid']], 400)),
        ]);

        $operator = $this->operator();
        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/platform/ai/test', ['provider' => 'gemini', 'model' => 'gemini-3.1-pro-preview'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'ready')
            ->assertJsonPath('model', 'gemini-3.1-pro-preview');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'gemini-3.1-pro-preview') && $r->hasHeader('x-goog-api-key', 'gm-test'));
        $this->assertSame(0, AiUsageDaily::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.ai.tested']);

        // A bad key comes back as a readable failure, not an exception.
        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/platform/ai/test', ['provider' => 'gemini'])
            ->assertOk()->assertJsonPath('ok', false);

        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/platform/ai/test', ['provider' => 'openai'])
            ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('error', 'No key is set for OpenAI (ChatGPT).');

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/platform/ai/test', ['provider' => 'gemini'])->assertForbidden();
    }
}
