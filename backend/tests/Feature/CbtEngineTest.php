<?php

namespace Tests\Feature;

use App\Models\CbtAttempt;
use App\Models\CbtAttemptAnswer;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtMediaAsset;
use App\Models\QuestionBankItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\CbtExamService;
use App\Services\ImageMetadataStripper;
use App\Services\MathContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CbtEngineTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $teacher;
    protected User $studentUser;
    protected Student $student;
    protected Subject $subject;
    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Make the relative URIs below resolve to this school's subdomain.
         *
         * `withServerVariables(['HTTP_HOST' => ...])` does nothing: Laravel
         * builds the URL from the UrlGenerator root and Symfony then overwrites
         * HTTP_HOST from it, so the server variable is discarded and tenant
         * resolution never ran in this file.
         *
         * `forceRootUrl` rather than `config(['app.url' => ...])` — the
         * UrlGenerator takes its root at boot, so changing the config
         * afterwards has no effect (verified: a bogus subdomain set that way
         * still returned 200 instead of 404).
         */
        URL::forceRootUrl('http://graceland.localhost');

        Storage::fake('public');

        $this->school = School::create([
            'name' => 'Graceland College',
            'slug' => 'graceland',
            'subdomain' => 'graceland',
        ]);

        $this->teacher = User::create([
            'name' => 'Mrs Adeyemi',
            'email' => 'adeyemi@graceland.test',
            'password' => bcrypt('password'),
        ]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);

        $this->studentUser = User::create([
            'name' => 'Adaeze Nwosu',
            'email' => 'adaeze@graceland.test',
            'password' => bcrypt('password'),
        ]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $this->studentUser->id, 'role' => 'student']);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->studentUser->id,
            'class_id' => $this->class->id,
            'admission_number' => 'GC/2026/001',
        ]);

        $this->subject = Subject::create([
            'school_id' => $this->school->id,
            'name' => 'Mathematics',
            'code' => 'MTH',
        ]);
    }

    private function asTeacher()
    {
        return $this->actingAs($this->teacher, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    private function asStudent()
    {
        return $this->actingAs($this->studentUser, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    /** A minimal but valid PNG, built without GD so the test runs anywhere. */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAFUlEQVR42mNk' .
            'YPhfz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC'
        );
    }

    private function makeQuestion(array $overrides = []): QuestionBankItem
    {
        return QuestionBankItem::create(array_merge([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'topic' => 'Algebra',
            'question' => 'What is 2 + 2?',
            'question_type' => 'multiple_choice',
            'options' => ['A' => '3', 'B' => '4', 'C' => '5', 'D' => '6'],
            'correct_answer' => 'B',
            'marks' => 2,
            'status' => 'approved',
        ], $overrides));
    }

    private function makeExam(array $overrides = []): CbtExam
    {
        return CbtExam::create(array_merge([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'class_id' => $this->class->id,
            'title' => 'First CA — Mathematics',
            'duration_minutes' => 30,
            'status' => 'published',
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'max_attempts' => 1,
            'created_by' => $this->teacher->id,
        ], $overrides));
    }

    private function attach(CbtExam $exam, QuestionBankItem ...$questions): void
    {
        $order = 0;
        foreach ($questions as $question) {
            CbtExamQuestion::create([
                'exam_id' => $exam->id,
                'question_id' => $question->id,
                'order_index' => ++$order,
            ]);
        }
    }

    // ==================================================================
    // Images
    // ==================================================================

    public function test_uploading_an_image_stores_it_and_requires_alt_text()
    {
        $response = $this->asTeacher()->postJson('/api/v1/cbt/media', [
            'file' => UploadedFile::fake()->createWithContent('diagram.png', $this->pngBytes()),
            'alt_text' => 'Cross-section of a leaf with the xylem labelled',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('asset.alt_text', 'Cross-section of a leaf with the xylem labelled');

        $this->assertNotNull($response->json('asset.checksum'));
        Storage::disk('public')->assertExists(CbtMediaAsset::first()->path);
    }

    public function test_image_upload_is_rejected_without_alt_text()
    {
        $this->asTeacher()->postJson('/api/v1/cbt/media', [
            'file' => UploadedFile::fake()->createWithContent('diagram.png', $this->pngBytes()),
        ])->assertStatus(422)->assertJsonValidationErrors('alt_text');
    }

    public function test_svg_upload_is_refused_because_it_can_carry_scripts()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->asTeacher()->postJson('/api/v1/cbt/media', [
            'file' => UploadedFile::fake()->createWithContent('evil.svg', $svg),
            'alt_text' => 'A harmless looking diagram',
        ])->assertStatus(422);

        $this->assertDatabaseCount('cbt_media_assets', 0);
    }

    public function test_a_php_payload_renamed_to_png_is_refused()
    {
        $this->asTeacher()->postJson('/api/v1/cbt/media', [
            'file' => UploadedFile::fake()->createWithContent('shell.png', '<?php system($_GET["c"]); ?>'),
            'alt_text' => 'Definitely just a picture',
        ])->assertStatus(422);

        $this->assertDatabaseCount('cbt_media_assets', 0);
    }

    public function test_identical_images_are_stored_once_per_school()
    {
        $bytes = $this->pngBytes();

        $first = $this->asTeacher()->postJson('/api/v1/cbt/media', [
            'file' => UploadedFile::fake()->createWithContent('a.png', $bytes),
            'alt_text' => 'A diagram of a circuit',
        ]);

        $second = $this->asTeacher()->postJson('/api/v1/cbt/media', [
            'file' => UploadedFile::fake()->createWithContent('b.png', $bytes),
            'alt_text' => 'A diagram of a circuit',
        ]);

        $this->assertEquals($first->json('asset.asset_id'), $second->json('asset.asset_id'));
        $this->assertDatabaseCount('cbt_media_assets', 1);
    }

    public function test_exif_metadata_is_stripped_from_uploaded_jpegs()
    {
        // A JPEG carrying an APP1/EXIF segment with a GPS-looking payload.
        $exifPayload = "Exif\x00\x00GPSLatitude 6.5244 GPSLongitude 3.3792";
        $segmentLength = strlen($exifPayload) + 2;
        $jpeg = "\xFF\xD8"
            . "\xFF\xE1" . pack('n', $segmentLength) . $exifPayload
            . "\xFF\xDA" . "image-scan-data";

        $stripped = (new ImageMetadataStripper())->strip($jpeg, 'image/jpeg');

        $this->assertStringNotContainsString('GPSLatitude', $stripped);
        $this->assertStringNotContainsString('GPSLongitude', $stripped);
        $this->assertStringContainsString('image-scan-data', $stripped);
    }

    public function test_a_question_cannot_reference_another_schools_image()
    {
        $otherSchool = School::create(['name' => 'Rival Academy', 'slug' => 'rival', 'subdomain' => 'rival']);

        $foreignAsset = CbtMediaAsset::create([
            'school_id' => $otherSchool->id,
            'disk' => 'public',
            'path' => 'cbt/999/aa/foreign.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'byte_size' => 100,
            'checksum' => str_repeat('f', 64),
            'alt_text' => 'Another school\'s diagram',
        ]);

        $this->asTeacher()->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question' => 'Identify the labelled part.',
            'question_type' => 'multiple_choice',
            'options' => ['A' => 'Xylem', 'B' => 'Phloem'],
            'correct_answer' => 'A',
            'media' => [['asset_id' => $foreignAsset->id, 'role' => 'stem']],
        ])->assertStatus(422);

        $this->assertDatabaseCount('question_bank', 0);
    }

    public function test_diagram_label_question_requires_an_attached_image()
    {
        $this->asTeacher()->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question' => 'Label the parts of the flower.',
            'question_type' => 'diagram_label',
            'answer_schema' => ['zones' => [['id' => 'z1', 'label' => 'stamen', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2]]],
        ])->assertStatus(422);
    }

    public function test_option_images_are_served_with_their_options()
    {
        $asset = CbtMediaAsset::create([
            'school_id' => $this->school->id,
            'disk' => 'public',
            'path' => 'cbt/1/ab/shape.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'byte_size' => 120,
            'checksum' => str_repeat('a', 64),
            'alt_text' => 'A right-angled triangle',
        ]);

        $question = $this->makeQuestion([
            'question_type' => 'image_choice',
            'question' => 'Which shape is a right-angled triangle?',
            'options' => ['A' => '', 'B' => ''],
            'correct_answer' => 'A',
            'media' => [['asset_id' => $asset->id, 'role' => 'option:A', 'position' => 0]],
        ]);

        $exam = $this->makeExam();
        $this->attach($exam, $question);

        $response = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start");

        $response->assertStatus(201);
        $optionA = collect($response->json('questions.0.options'))->firstWhere('key', 'A');

        $this->assertNotNull($optionA['image']);
        $this->assertEquals('A right-angled triangle', $optionA['image']['alt_text']);
    }

    public function test_offline_package_lists_media_checksums_for_pre_caching()
    {
        $asset = CbtMediaAsset::create([
            'school_id' => $this->school->id,
            'disk' => 'public',
            'path' => 'cbt/1/ab/map.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'byte_size' => 4096,
            'checksum' => str_repeat('b', 64),
            'alt_text' => 'Map of the Niger Delta',
        ]);

        $question = $this->makeQuestion(['media' => [['asset_id' => $asset->id, 'role' => 'stem', 'position' => 0]]]);
        $exam = $this->makeExam(['allow_offline' => true]);
        $this->attach($exam, $question);

        $this->asTeacher()->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertStatus(200)
            ->assertJsonPath('media_manifest.asset_count', 1)
            ->assertJsonPath('media_manifest.total_bytes', 4096)
            ->assertJsonPath('media_manifest.assets.0.checksum', str_repeat('b', 64));
    }

    // ==================================================================
    // LaTeX maths
    // ==================================================================

    public function test_latex_question_is_stored_and_flagged_for_the_renderer()
    {
        $response = $this->asTeacher()->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question' => 'Solve for $x$: $$x^2 - 5x + 6 = 0$$',
            'question_type' => 'multiple_choice',
            'options' => ['A' => '$x = 2, 3$', 'B' => '$x = 1, 6$'],
            'correct_answer' => 'A',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('question.content_format', 'latex');
    }

    public function test_latex_in_options_alone_still_marks_the_question_as_latex()
    {
        // A maths paper often has plain prose in the stem and all the maths in
        // the options — the format must be detected from either.
        $response = $this->asTeacher()->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question' => 'Which of the following is correctly simplified?',
            'question_type' => 'multiple_choice',
            'options' => ['A' => '$\frac{4}{8} = \frac{1}{2}$', 'B' => '$\frac{4}{8} = \frac{1}{4}$'],
            'correct_answer' => 'A',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('question.content_format', 'latex');
    }

    public function test_unbalanced_latex_delimiters_are_rejected()
    {
        $this->asTeacher()->postJson('/api/v1/cbt/questions', [
            'subject_id' => $this->subject->id,
            'question' => 'Find $x^2 + 1 and simplify.',
            'question_type' => 'fill_blank',
            'content_format' => 'latex',
            'answer_schema' => ['accepted' => ['2']],
        ])->assertStatus(422);
    }

    public function test_dangerous_latex_macros_are_rejected()
    {
        foreach (['\input{/etc/passwd}', '\href{javascript:alert(1)}{click}', '\def\x{\x}\x'] as $payload) {
            $this->asTeacher()->postJson('/api/v1/cbt/questions', [
                'subject_id' => $this->subject->id,
                'question' => 'Evaluate $' . $payload . '$',
                'question_type' => 'fill_blank',
                'answer_schema' => ['accepted' => ['x']],
            ])->assertStatus(422);
        }

        $this->assertDatabaseCount('question_bank', 0);
    }

    public function test_escaped_dollar_signs_are_treated_as_currency_not_maths()
    {
        $maths = app(MathContentService::class);

        $result = $maths->validate('A textbook costs \$20 and a pen costs \$2.');

        $this->assertTrue($result['valid']);
        $this->assertCount(0, $result['expressions']);
    }

    public function test_strip_math_leaves_prose_for_search_and_ai_prompts()
    {
        $maths = app(MathContentService::class);

        $this->assertEquals(
            'Solve [maths] for x.',
            $maths->stripMath('Solve $x^2 - 4 = 0$ for x.')
        );
    }

    // ==================================================================
    // Grading
    // ==================================================================

    public function test_objective_paper_is_auto_graded_on_submission()
    {
        $q1 = $this->makeQuestion(['correct_answer' => 'B', 'marks' => 2]);
        $q2 = $this->makeQuestion([
            'question' => 'What is the capital of Nigeria?',
            'options' => ['A' => 'Lagos', 'B' => 'Abuja'],
            'correct_answer' => 'B',
            'marks' => 3,
        ]);

        $exam = $this->makeExam(['show_results_immediately' => true]);
        $this->attach($exam, $q1, $q2);

        $attemptId = $this->asStudent()
            ->postJson("/api/v1/cbt/exams/{$exam->id}/start")
            ->json('attempt.id');

        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
            'answers' => [
                ['question_id' => $q1->id, 'response' => ['option' => 'B']],
                ['question_id' => $q2->id, 'response' => ['option' => 'A']],
            ],
        ])->assertStatus(200);

        $response = $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/submit");

        $response->assertStatus(200)->assertJsonPath('result.grade', 'E8');

        // assertEquals, not assertJsonPath: PHP encodes the float 2.0 as `2`,
        // so a strict comparison against 2.0 can never match.
        $this->assertEquals(2, $response->json('result.raw_score'));
        $this->assertEquals(5, $response->json('result.max_score'));
        $this->assertEquals(40, $response->json('result.percentage'));
    }

    public function test_numeric_answers_are_graded_within_a_tolerance_band()
    {
        $question = $this->makeQuestion([
            'question' => 'State the acceleration due to gravity in m/s².',
            'question_type' => 'numeric',
            'options' => null,
            'correct_answer' => null,
            'answer_schema' => ['value' => 9.81, 'tolerance' => 0.05],
            'marks' => 4,
        ]);

        $exam = $this->makeExam(['show_results_immediately' => true]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
            'answers' => [['question_id' => $question->id, 'response' => ['value' => 9.8]]],
        ]);

        $this->assertEquals(4, $this->asStudent()
            ->postJson("/api/v1/cbt/attempts/{$attemptId}/submit")
            ->json('result.raw_score'));
    }

    public function test_diagram_label_questions_award_partial_credit_per_zone()
    {
        $asset = CbtMediaAsset::create([
            'school_id' => $this->school->id,
            'disk' => 'public',
            'path' => 'cbt/1/cd/flower.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'byte_size' => 200,
            'checksum' => str_repeat('c', 64),
            'alt_text' => 'A labelled flower',
        ]);

        $question = $this->makeQuestion([
            'question' => 'Label the parts of the flower.',
            'question_type' => 'diagram_label',
            'options' => null,
            'correct_answer' => null,
            'marks' => 4,
            'media' => [['asset_id' => $asset->id, 'role' => 'diagram', 'position' => 0]],
            'answer_schema' => [
                'partial_credit' => true,
                'zones' => [
                    ['id' => 'z1', 'label' => 'stamen', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2],
                    ['id' => 'z2', 'label' => 'petal', 'x' => 0.5, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2],
                ],
            ],
        ]);

        $exam = $this->makeExam(['show_results_immediately' => true]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
            'answers' => [[
                'question_id' => $question->id,
                'response' => ['labels' => ['z1' => 'stamen', 'z2' => 'sepal']],
            ]],
        ]);

        // Two zones, one right: half of four marks.
        $this->assertEquals(2, $this->asStudent()
            ->postJson("/api/v1/cbt/attempts/{$attemptId}/submit")
            ->json('result.raw_score'));
    }

    public function test_hotspot_answers_are_graded_against_normalised_coordinates()
    {
        $asset = CbtMediaAsset::create([
            'school_id' => $this->school->id,
            'disk' => 'public',
            'path' => 'cbt/1/de/map.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'byte_size' => 200,
            'checksum' => str_repeat('d', 64),
            'alt_text' => 'Map of Nigeria',
        ]);

        $question = $this->makeQuestion([
            'question' => 'Click on the location of Abuja.',
            'question_type' => 'hotspot',
            'options' => null,
            'correct_answer' => null,
            'marks' => 5,
            'media' => [['asset_id' => $asset->id, 'role' => 'diagram', 'position' => 0]],
            'answer_schema' => ['zones' => [['shape' => 'rect', 'x' => 0.4, 'y' => 0.4, 'w' => 0.2, 'h' => 0.2]]],
        ]);

        $exam = $this->makeExam(['show_results_immediately' => true]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
            'answers' => [['question_id' => $question->id, 'response' => ['x' => 0.5, 'y' => 0.5]]],
        ]);

        $this->assertEquals(5, $this->asStudent()
            ->postJson("/api/v1/cbt/attempts/{$attemptId}/submit")
            ->json('result.raw_score'));
    }

    public function test_a_blank_answer_never_attracts_a_negative_mark()
    {
        $question = $this->makeQuestion(['negative_marks' => 1, 'marks' => 2]);
        $exam = $this->makeExam(['negative_marking' => true, 'show_results_immediately' => true]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->assertEquals(0, $this->asStudent()
            ->postJson("/api/v1/cbt/attempts/{$attemptId}/submit")
            ->json('result.raw_score'));
    }

    public function test_theory_questions_are_held_for_manual_marking()
    {
        $question = $this->makeQuestion([
            'question' => 'Explain photosynthesis in your own words.',
            'question_type' => 'theory',
            'options' => null,
            'correct_answer' => null,
            'answer_schema' => ['allow_image_answer' => true],
            'marks' => 10,
        ]);

        $exam = $this->makeExam(['show_results_immediately' => true]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
            'answers' => [['question_id' => $question->id, 'response' => ['text' => 'Plants make food from sunlight.']]],
        ]);

        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/submit")
            ->assertJsonPath('result.requires_manual_grading', true);

        $graded = $this->asTeacher()->postJson("/api/v1/cbt/attempts/{$attemptId}/grade", [
            'marks' => [['question_id' => $question->id, 'awarded_marks' => 7, 'feedback' => 'Good, but mention chlorophyll.']],
        ])->assertStatus(200)
            ->assertJsonPath('result.requires_manual_grading', false);

        $this->assertEquals(7, $graded->json('result.raw_score'));

        $this->assertEquals('graded', CbtAttempt::find($attemptId)->status);
    }

    // ==================================================================
    // Delivery integrity
    // ==================================================================

    public function test_the_paper_served_to_a_candidate_never_carries_the_marking_scheme()
    {
        $question = $this->makeQuestion(['explanation' => 'Two plus two is four.']);
        $exam = $this->makeExam();
        $this->attach($exam, $question);

        $body = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->getContent();

        $this->assertStringNotContainsString('correct_answer', $body);
        $this->assertStringNotContainsString('Two plus two is four.', $body);
        $this->assertStringNotContainsString('answer_schema', $body);
    }

    public function test_option_shuffling_is_deterministic_across_a_resumed_attempt()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam(['shuffle_options' => true]);
        $this->attach($exam, $question);

        $first = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start");
        $attemptId = $first->json('attempt.id');

        $resumed = $this->asStudent()->getJson("/api/v1/cbt/attempts/{$attemptId}");

        $this->assertEquals(
            array_column($first->json('questions.0.options'), 'key'),
            array_column($resumed->json('questions.0.options'), 'key'),
            'A candidate who reconnects must see the same paper they left.'
        );
    }

    public function test_the_server_clock_decides_when_time_is_up()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam(['duration_minutes' => 10]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        // Wind the deadline into the past, as if the candidate walked away.
        CbtAttempt::find($attemptId)->update(['server_deadline_at' => now()->subMinute()]);

        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
            'answers' => [['question_id' => $question->id, 'response' => ['option' => 'B']]],
        ])->assertStatus(409);

        $attempt = CbtAttempt::find($attemptId);
        $this->assertTrue($attempt->isFinalised());
        $this->assertDatabaseHas('cbt_attempt_events', ['attempt_id' => $attemptId, 'event_type' => 'auto_submit']);
    }

    public function test_answers_for_questions_not_served_to_this_attempt_are_rejected()
    {
        $served = $this->makeQuestion();
        $notServed = $this->makeQuestion(['question' => 'A question from another paper.']);

        $exam = $this->makeExam();
        $this->attach($exam, $served);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $response = $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
            'answers' => [['question_id' => $notServed->id, 'response' => ['option' => 'A']]],
        ]);

        $response->assertStatus(200)->assertJsonPath('saved', 0);
        $this->assertNotEmpty($response->json('rejected'));
    }

    public function test_a_candidate_cannot_reach_another_candidates_attempt()
    {
        $intruderUser = User::create([
            'name' => 'Chidi Okeke',
            'email' => 'chidi@graceland.test',
            'password' => bcrypt('password'),
        ]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $intruderUser->id, 'role' => 'student']);
        Student::create([
            'school_id' => $this->school->id,
            'user_id' => $intruderUser->id,
            'class_id' => $this->class->id,
            'admission_number' => 'GC/2026/002',
        ]);

        $question = $this->makeQuestion();
        $exam = $this->makeExam(['max_attempts' => 2]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->actingAs($intruderUser, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost'])
            ->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
                'answers' => [['question_id' => $question->id, 'response' => ['option' => 'B']]],
            ])->assertStatus(403);
    }

    public function test_integrity_events_are_logged_and_counted_without_voiding_the_attempt()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam();
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/events", ['event_type' => 'focus_lost']);
        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/events", ['event_type' => 'focus_lost'])
            ->assertStatus(200)
            ->assertJsonPath('integrity_flags.focus_lost', 2);

        // Flagged, not punished — a teacher decides what it meant.
        $this->assertEquals('in_progress', CbtAttempt::find($attemptId)->status);
    }

    // ==================================================================
    // Offline sync
    // ==================================================================

    public function test_offline_batches_resolve_out_of_order_arrivals_by_client_sequence()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam(['allow_offline' => true]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        // The candidate's final answer arrives first; the earlier one follows.
        $this->asStudent()->postJson('/api/v1/cbt/offline-sync', [
            'attempt_id' => $attemptId,
            'answers' => [['question_id' => $question->id, 'response' => ['option' => 'B'], 'client_sequence' => 9]],
        ])->assertStatus(200);

        $this->asStudent()->postJson('/api/v1/cbt/offline-sync', [
            'attempt_id' => $attemptId,
            'answers' => [['question_id' => $question->id, 'response' => ['option' => 'C'], 'client_sequence' => 3]],
        ])->assertStatus(200)->assertJsonPath('synced_count', 0);

        $answer = CbtAttemptAnswer::where('attempt_id', $attemptId)->first();
        $this->assertEquals('B', $answer->response['option'], 'A stale offline batch must not overwrite a newer answer.');
    }

    public function test_a_finalised_attempt_ignores_a_late_offline_batch()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam(['allow_offline' => true]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');
        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/submit");

        $this->asStudent()->postJson('/api/v1/cbt/offline-sync', [
            'attempt_id' => $attemptId,
            'answers' => [['question_id' => $question->id, 'response' => ['option' => 'B'], 'client_sequence' => 99]],
        ])->assertStatus(200)->assertJsonPath('status', 'ignored');
    }

    public function test_offline_sync_accepts_the_legacy_selected_option_shape()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam(['allow_offline' => true, 'show_results_immediately' => true]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->asStudent()->postJson('/api/v1/cbt/offline-sync', [
            'attempt_id' => $attemptId,
            'answers' => [['question_id' => $question->id, 'response' => ['option' => 'B'], 'client_sequence' => 1]],
            'submit' => true,
        ])->assertStatus(200);

        $this->assertEquals('graded', CbtAttempt::find($attemptId)->status);
    }

    // ==================================================================
    // Lifecycle and analytics
    // ==================================================================

    public function test_publishing_is_refused_when_the_paper_cannot_be_sat()
    {
        $exam = $this->makeExam(['status' => 'draft', 'questions_per_attempt' => 5]);
        $this->attach($exam, $this->makeQuestion());

        $this->asTeacher()->postJson("/api/v1/cbt/exams/{$exam->id}/publish")
            ->assertStatus(422);
    }

    public function test_a_published_exams_questions_cannot_be_changed_underneath_candidates()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam();
        $this->attach($exam, $question);

        $this->asTeacher()->postJson("/api/v1/cbt/exams/{$exam->id}/questions", [
            'questions' => [['question_id' => $question->id]],
        ])->assertStatus(409);

        $this->asTeacher()->putJson("/api/v1/cbt/questions/{$question->id}", [
            'correct_answer' => 'C',
        ])->assertStatus(409);
    }

    public function test_attempts_beyond_the_permitted_count_are_refused()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam(['max_attempts' => 1]);
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');
        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/submit");

        $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")
            ->assertStatus(422);
    }

    public function test_topic_breakdown_shows_a_teacher_where_the_class_lost_marks()
    {
        $algebra = $this->makeQuestion(['topic' => 'Algebra', 'correct_answer' => 'B']);
        $geometry = $this->makeQuestion([
            'topic' => 'Geometry',
            'question' => 'How many degrees in a triangle?',
            'options' => ['A' => '90', 'B' => '180'],
            'correct_answer' => 'B',
        ]);

        $exam = $this->makeExam(['show_results_immediately' => true]);
        $this->attach($exam, $algebra, $geometry);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
            'answers' => [
                ['question_id' => $algebra->id, 'response' => ['option' => 'B']],
                ['question_id' => $geometry->id, 'response' => ['option' => 'A']],
            ],
        ]);

        $breakdown = collect(
            $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/submit")->json('result.topic_breakdown')
        )->keyBy('topic');

        $this->assertEquals(100.0, $breakdown['Algebra']['mastery_percentage']);
        $this->assertEquals(0.0, $breakdown['Geometry']['mastery_percentage']);
    }

    public function test_item_analysis_counts_how_the_cohort_answered_each_question()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam();
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');
        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/answers", [
            'answers' => [['question_id' => $question->id, 'response' => ['option' => 'B']]],
        ]);
        $this->asStudent()->postJson("/api/v1/cbt/attempts/{$attemptId}/submit");

        $results = $this->asTeacher()->getJson("/api/v1/cbt/exams/{$exam->id}/results")
            ->assertStatus(200)
            ->assertJsonPath('summary.attempts', 1);

        $this->assertEquals(1, $results->json('item_analysis.0.answered'));
        $this->assertEquals(1, $results->json('item_analysis.0.facility_index'));
    }

    public function test_the_scheduled_command_closes_overdue_attempts()
    {
        // The sweep only runs if something calls it. Without the scheduled
        // command an abandoned attempt stays `in_progress` forever, and
        // startAttempt() hands that same attempt back — locking the candidate
        // out of their own exam.
        $question = $this->makeQuestion();
        $exam = $this->makeExam();
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');
        CbtAttempt::find($attemptId)->update(['server_deadline_at' => now()->subHour()]);

        $this->artisan('cbt:expire-attempts')
            ->expectsOutputToContain('Auto-submitted and graded 1 overdue CBT attempt(s).')
            ->assertSuccessful();

        $this->assertTrue(CbtAttempt::find($attemptId)->isFinalised());

        // And the candidate can now sit their remaining attempt.
        $this->assertDatabaseHas('cbt_attempt_events', [
            'attempt_id' => $attemptId,
            'event_type' => 'auto_submit',
        ]);
    }

    public function test_the_expiry_sweep_leaves_attempts_that_still_have_time()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam();
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');

        $this->artisan('cbt:expire-attempts')->assertSuccessful();

        $this->assertEquals('in_progress', CbtAttempt::find($attemptId)->status);
    }

    public function test_overdue_attempts_are_swept_closed_rather_than_left_hanging()
    {
        $question = $this->makeQuestion();
        $exam = $this->makeExam();
        $this->attach($exam, $question);

        $attemptId = $this->asStudent()->postJson("/api/v1/cbt/exams/{$exam->id}/start")->json('attempt.id');
        CbtAttempt::find($attemptId)->update(['server_deadline_at' => now()->subHour()]);

        $closed = app(CbtExamService::class)->expireOverdueAttempts();

        $this->assertEquals(1, $closed);
        $this->assertTrue(CbtAttempt::find($attemptId)->isFinalised());
    }
}
