<?php

namespace Tests\Feature;

use App\Models\CbtMediaAsset;
use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ClaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AI with pictures for CBT: questions from an image, a photographed paper
 * read into questions, and alt text for an image. The picture reaches each
 * provider in its own format; everything comes back as a draft.
 */
class AiCbtImageTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private string $host = 'http://graceland.localhost/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set('services.anthropic.stub', false);
        config()->set('services.ai.provider', 'anthropic');
        config()->set('services.anthropic.api_key', 'sk-ant-test');
        config()->set('services.openai.api_key', null);
        config()->set('services.gemini.api_key', null);

        $this->school = School::create(['name' => 'Graceland', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->teacher = User::factory()->create();
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAFUlEQVR42mNkYPhfz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC');
    }

    private function png(string $name = 'diagram.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes());
    }

    private function asset(?School $school = null): CbtMediaAsset
    {
        $school ??= $this->school;
        Storage::disk('public')->put("cbt/{$school->id}/ab/x.png", $this->pngBytes());

        return CbtMediaAsset::create([
            'school_id' => $school->id, 'uploaded_by' => $this->teacher->id, 'disk' => 'public', 'path' => "cbt/{$school->id}/ab/x.png",
            'mime_type' => 'image/png', 'extension' => 'png', 'byte_size' => 70, 'width' => 4, 'height' => 4,
            'checksum' => hash('sha256', (string) $school->id), 'alt_text' => 'A labelled diagram of a flowering plant.',
        ]);
    }

    private function claudeSays(array $json): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 900, 'output_tokens' => 300],
        ])]);
    }

    public function test_questions_from_a_picture_carry_the_picture_and_the_model_sees_it()
    {
        $asset = $this->asset();
        $this->claudeSays(['questions' => [
            ['question' => 'Which part labelled X makes pollen?', 'options' => ['A' => 'Anther', 'B' => 'Stigma', 'C' => 'Ovary', 'D' => 'Sepal'], 'correct_answer' => 'A', 'topic' => 'Flowers', 'difficulty' => 'medium'],
            ['question' => 'Broken: answer not an option', 'options' => ['A' => 'x', 'B' => 'y'], 'correct_answer' => 'Z'],
        ]]);

        $res = $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/ai/cbt/questions-from-image", [
            'asset_id' => $asset->id, 'subject' => 'Basic Science', 'count' => 2,
        ])->assertOk();

        $this->assertSame(1, $res->json('question_count'), 'A question whose key is not an option is dropped.');
        $this->assertSame([['asset_id' => $asset->id, 'role' => 'stem']], $res->json('questions.0.media'));
        Http::assertSent(fn (HttpRequest $r) => ($r->data()['messages'][0]['content'][0]['type'] ?? null) === 'image'
            && ($r->data()['messages'][0]['content'][0]['source']['media_type'] ?? null) === 'image/png'
            && ($r->data()['messages'][0]['content'][0]['source']['data'] ?? null) === base64_encode($this->pngBytes()));
    }

    public function test_another_schools_picture_is_not_reachable()
    {
        $other = School::create(['name' => 'Riverside', 'slug' => 'riverside', 'subdomain' => 'riverside']);
        $theirs = $this->asset($other);
        Http::fake();

        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/ai/cbt/questions-from-image", [
            'asset_id' => $theirs->id, 'subject' => 'Basic Science',
        ])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_scanned_paper_comes_back_as_drafts_that_flag_a_missing_key()
    {
        $this->claudeSays(['questions' => [
            ['number' => 1, 'question' => 'What is 2 + 3?', 'question_type' => 'multiple_choice', 'options' => ['A' => '4', 'B' => '5', 'C' => '6', 'D' => '7'], 'correct_answer' => null],
            ['number' => 2, 'question' => 'Explain photosynthesis.', 'question_type' => 'theory', 'options' => null, 'correct_answer' => null],
        ], 'unreadable' => null]);

        $res = $this->actingAs($this->teacher, 'sanctum')->post("{$this->host}/ai/cbt/scan-paper", [
            'pages' => [$this->png('p1.png'), $this->png('p2.png')], 'subject' => 'Mathematics',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(2, $res->json('question_count'));
        $this->assertTrue($res->json('questions.0.needs_answer'), 'No key on the paper: the teacher must give one.');
        $this->assertSame('theory', $res->json('questions.1.question_type'));
        Http::assertSent(fn (HttpRequest $r) => collect($r->data()['messages'][0]['content'])->where('type', 'image')->count() === 2);
    }

    public function test_alt_text_is_suggested_for_an_image()
    {
        $this->claudeSays(['alt_text' => 'A bar chart of rainfall for each month.', 'caption' => 'Monthly rainfall']);

        $this->actingAs($this->teacher, 'sanctum')->post("{$this->host}/ai/cbt/describe-image", ['image' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('alt_text', 'A bar chart of rainfall for each month.')
            ->assertJsonPath('caption', 'Monthly rainfall');
    }

    public function test_switched_off_question_generation_refuses()
    {
        $this->school->update(['ai_feature_flags' => ['exam_generation' => false]]);
        Http::fake();

        $this->actingAs($this->teacher, 'sanctum')->post("{$this->host}/ai/cbt/describe-image", ['image' => $this->png()], ['Accept' => 'application/json'])
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_openai_and_gemini_receive_the_picture_in_their_own_format()
    {
        $bytes = $this->pngBytes();

        config()->set('services.ai.provider', 'openai');
        config()->set('services.openai.api_key', 'sk-openai-test');
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"alt_text":"x","caption":"y"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])]);
        app(ClaudeService::class)->visionJson('Describe.', 'Go', [['mime' => 'image/png', 'bytes' => $bytes]], $this->school->id, []);
        Http::assertSent(fn (HttpRequest $r) => collect($r->data()['messages'])->contains(fn ($m) => is_array($m['content'])
            && ($m['content'][0]['image_url']['url'] ?? '') === 'data:image/png;base64,'.base64_encode($bytes)));

        config()->set('services.ai.provider', 'gemini');
        config()->set('services.gemini.api_key', 'gm-test');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"alt_text":"x","caption":"y"}']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ])]);
        app(ClaudeService::class)->visionJson('Describe.', 'Go', [['mime' => 'image/png', 'bytes' => $bytes]], $this->school->id, []);
        Http::assertSent(fn (HttpRequest $r) => ($r->data()['contents'][0]['parts'][0]['inline_data']['data'] ?? null) === base64_encode($bytes));
    }
}
