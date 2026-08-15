<?php

namespace Tests\Feature;

use App\Models\CbtAttempt;
use App\Models\CbtAttemptAnswer;
use App\Models\CbtAttemptEvent;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtOfflineBundle;
use App\Models\CbtQuestionGroup;
use App\Models\QuestionBankItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\CbtOfflineBundleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The offline lab relay — docs/offline-cbt-client.md §9.1–§9.3, §9.8.
 *
 * The design puts question text on a machine in a school we do not control,
 * the night before an exam. That is only defensible because of what is *not*
 * in the bundle, so the first test in this file is the one the document calls
 * the single most valuable test in it: no correct answers, no rubrics, no
 * explanations, anywhere in the ciphertext.
 *
 * Everything else here is the same idea from a different angle. A relay holds
 * a staff token; it must not be able to write to a paper it was never issued.
 * A key must not exist before the exam opens. A sync that is retried after a
 * dropped connection must not double-count anything.
 */
class CbtOfflineBundleTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private Subject $subject;
    private SchoolClass $class;
    private CbtExam $exam;
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://graceland.localhost');

        $this->school = School::create([
            'name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland',
        ]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'English', 'code' => 'ENG']);

        $this->teacher = $this->user('Mrs Adeyemi', 'adeyemi@graceland.test', 'teacher');

        foreach (['Adaeze Nwosu', 'Tunde Bello', 'Fatima Sani'] as $index => $name) {
            $user = $this->user($name, "candidate{$index}@graceland.test", 'student');
            $this->students[] = Student::create([
                'school_id' => $this->school->id,
                'user_id' => $user->id,
                'class_id' => $this->class->id,
                'admission_number' => 'GC/2026/00' . ($index + 1),
                'status' => 'active',
            ]);
        }

        $this->exam = $this->makePaper();
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function asTeacher()
    {
        return $this->actingAs($this->teacher, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    /**
     * A realistic mixed paper: objectives, a comprehension group, an on-screen
     * essay with a rubric, and an on-paper theory question. The bundle has to
     * survive all four.
     */
    private function makePaper(array $examOverrides = []): CbtExam
    {
        $exam = CbtExam::create(array_merge([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'class_id' => $this->class->id,
            'title' => 'JSS 2 English Mock',
            'duration_minutes' => 90,
            'status' => 'published',
            'opens_at' => now()->subMinutes(10),
            'closes_at' => now()->addHours(3),
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'allow_offline' => true,
            'show_results_immediately' => false,
            'max_attempts' => 1,
            'created_by' => $this->teacher->id,
        ], $examOverrides));

        $group = CbtQuestionGroup::create([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'title' => 'Passage 1 — The Harmattan',
            'stimulus' => 'The harmattan is a dry, dusty wind...',
            'instructions' => 'Read the passage and answer questions 1 to 2.',
        ]);

        $questions = [];

        $questions[] = QuestionBankItem::create([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'question' => 'What is the passage about?',
            'question_type' => 'multiple_choice',
            'options' => ['A' => 'Wind', 'B' => 'Rain', 'C' => 'Sun', 'D' => 'Snow'],
            'correct_answer' => 'A',
            'explanation' => 'The passage names the harmattan, a wind.',
            'group_id' => $group->id,
            'group_sequence' => 1,
            'marks' => 2,
            'status' => 'approved',
        ]);

        $questions[] = QuestionBankItem::create([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'question' => 'Where does the wind come from?',
            'question_type' => 'multiple_choice',
            'options' => ['A' => 'The Atlantic', 'B' => 'The Sahara'],
            'correct_answer' => 'B',
            'group_id' => $group->id,
            'group_sequence' => 2,
            'marks' => 2,
            'status' => 'approved',
        ]);

        $questions[] = QuestionBankItem::create([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'question' => 'Write an essay on the effects of the harmattan.',
            'question_type' => 'theory',
            'marks' => 10,
            'status' => 'approved',
            'answer_schema' => [
                'response_format' => 'essay',
                'answer_mode' => 'on_screen',
                'expected_words' => 250,
                'max_words' => 500,
                'allow_working_photo' => false,
                'rubric' => [
                    ['criterion' => 'Thesis is stated clearly', 'marks' => 2],
                    ['criterion' => 'Three supporting points', 'marks' => 6],
                    ['criterion' => 'Grammar and expression', 'marks' => 2],
                ],
            ],
        ]);

        $questions[] = QuestionBankItem::create([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'question' => 'Section B: answer in your booklet.',
            'question_type' => 'theory',
            'marks' => 6,
            'status' => 'approved',
            'answer_schema' => [
                'response_format' => 'structured',
                'answer_mode' => 'on_paper',
                'rubric' => [['criterion' => 'Correct working shown', 'marks' => 6]],
            ],
        ]);

        $index = 0;
        foreach ($questions as $question) {
            CbtExamQuestion::create([
                'exam_id' => $exam->id,
                'question_id' => $question->id,
                'order_index' => ++$index,
            ]);
        }

        return $exam->fresh();
    }

    private function issueBundle(array $payload = []): array
    {
        $response = $this->asTeacher()->postJson(
            "/api/v1/cbt/exams/{$this->exam->id}/offline-bundle",
            array_merge(['relay_identity' => 'Lab A laptop'], $payload)
        );

        $response->assertCreated();

        return $response->json();
    }

    // ==================================================================
    // What is not in the bundle
    // ==================================================================

    /**
     * The single most valuable test in the design document.
     *
     * Asserted against the *decrypted ciphertext*, not against the payload
     * before encryption — a test that inspects the array on its way in proves
     * nothing about what actually shipped to the lab.
     */
    public function test_the_bundle_contains_no_answers_and_no_rubrics(): void
    {
        $issued = $this->issueBundle();
        $bundle = CbtOfflineBundle::where('bundle_id', $issued['bundle']['bundle_id'])->firstOrFail();

        $opened = app(CbtOfflineBundleService::class)->open($issued['bundle'], $bundle->content_key);
        $serialised = json_encode($opened);

        foreach (['correct_answer', 'rubric', 'explanation', 'criterion'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $serialised,
                "The bundle carries '{$forbidden}' into the exam room."
            );
        }

        // Not just the key names — the values too. A rubric criterion whose
        // text leaked into some other field would pass a key-name check.
        $secrets = [
            'Thesis is stated clearly',
            'Three supporting points',
            'Grammar and expression',
            'Correct working shown',
            'The passage names the harmattan, a wind.', // the explanation
        ];

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $serialised, "The bundle leaks: {$secret}");
        }

        // Option *text* is a different thing and must travel — a candidate has
        // to read the choices. What must not travel is which one is right.
        $first = collect($opened['questions'])->firstWhere('question_id', 1);
        $this->assertSame(
            ['Wind', 'Rain', 'Sun', 'Snow'],
            array_column($first['options'], 'text')
        );
        $this->assertArrayNotHasKey('correct_answer', $first);

        // And the paper is genuinely there, so the assertions above are not
        // passing because the bundle is empty.
        $this->assertCount(4, $opened['questions']);
        $this->assertSame('What is the passage about?', $first['question']);
        $this->assertSame('Passage 1 — The Harmattan', $opened['groups'][0]['title']);
    }

    /** The candidate-facing half of a theory schema still travels. */
    public function test_the_bundle_carries_what_a_candidate_needs_to_answer(): void
    {
        $issued = $this->issueBundle();
        $bundle = CbtOfflineBundle::where('bundle_id', $issued['bundle']['bundle_id'])->firstOrFail();
        $opened = app(CbtOfflineBundleService::class)->open($issued['bundle'], $bundle->content_key);

        $essay = collect($opened['questions'])->firstWhere('question_id', 3);
        $onPaper = collect($opened['questions'])->last();

        $this->assertSame('essay', $essay['interaction']['response_format']);
        $this->assertSame(500, $essay['interaction']['max_words']);
        $this->assertSame('on_screen', $essay['answer_mode']);
        $this->assertSame('on_paper', $onPaper['answer_mode']);
    }

    // ==================================================================
    // Encryption
    // ==================================================================

    public function test_a_bundle_is_unreadable_without_its_key(): void
    {
        $issued = $this->issueBundle();
        $service = app(CbtOfflineBundleService::class);

        $this->expectException(\RuntimeException::class);
        $service->open($issued['bundle'], base64_encode(random_bytes(32)));
    }

    /** Authenticated, not merely encrypted: tampering must fail closed. */
    public function test_a_tampered_bundle_fails_to_open_rather_than_opening_with_garbage(): void
    {
        $issued = $this->issueBundle();
        $bundle = CbtOfflineBundle::where('bundle_id', $issued['bundle']['bundle_id'])->firstOrFail();

        $envelope = $issued['bundle'];
        $raw = base64_decode($envelope['cipher']['ciphertext']);
        $raw[10] = chr(ord($raw[10]) ^ 0xFF);
        $envelope['cipher']['ciphertext'] = base64_encode($raw);

        $this->expectException(\RuntimeException::class);
        app(CbtOfflineBundleService::class)->open($envelope, $bundle->content_key);
    }

    /**
     * The plaintext header is bound in as additional authenticated data, so a
     * bundle cannot be retargeted at a different exam while leaving the
     * ciphertext intact.
     */
    public function test_the_plaintext_header_cannot_be_edited(): void
    {
        $issued = $this->issueBundle();
        $bundle = CbtOfflineBundle::where('bundle_id', $issued['bundle']['bundle_id'])->firstOrFail();

        $envelope = $issued['bundle'];
        $envelope['header']['exam_id'] = 9999;

        $this->expectException(\RuntimeException::class);
        app(CbtOfflineBundleService::class)->open($envelope, $bundle->content_key);
    }

    /** The issuance response is ciphertext only — the key comes separately. */
    public function test_the_issuance_response_does_not_carry_the_key(): void
    {
        $issued = $this->issueBundle();

        $this->assertArrayNotHasKey('key', $issued['bundle']);
        $this->assertStringNotContainsString('"key"', json_encode($issued['bundle']));
        $this->assertArrayHasKey('media_manifest', $issued);
    }

    // ==================================================================
    // §9.2 Key release
    // ==================================================================

    public function test_the_key_is_refused_before_the_exam_opens(): void
    {
        $this->exam->update(['opens_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]);

        $issued = $this->issueBundle();

        $this->asTeacher()
            ->postJson("/api/v1/cbt/offline-bundle/{$issued['bundle']['bundle_id']}/key")
            ->assertStatus(403);

        // Refusals are audited too — an attempt to unlock early is exactly the
        // sort of thing somebody should be able to find afterwards.
        $this->assertDatabaseHas('audit_logs', ['action' => 'cbt.offline_bundle.key_refused']);
    }

    public function test_the_key_is_released_once_the_exam_opens_and_is_audited(): void
    {
        $issued = $this->issueBundle();

        $response = $this->asTeacher()
            ->postJson("/api/v1/cbt/offline-bundle/{$issued['bundle']['bundle_id']}/key")
            ->assertOk();

        $this->assertSame('aes-256-gcm', $response->json('algorithm'));

        // The released key opens the bundle the relay was handed.
        $opened = app(CbtOfflineBundleService::class)->open($issued['bundle'], $response->json('key'));
        $this->assertCount(4, $opened['questions']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cbt.offline_bundle.key_released',
            'user_id' => $this->teacher->id,
        ]);
        $this->assertNotNull(CbtOfflineBundle::where('bundle_id', $issued['bundle']['bundle_id'])->first()->unlocked_at);
    }

    public function test_a_revoked_bundle_never_gives_up_its_key(): void
    {
        $issued = $this->issueBundle();
        $bundleId = $issued['bundle']['bundle_id'];

        $this->asTeacher()->postJson("/api/v1/cbt/offline-bundle/{$bundleId}/revoke")
            ->assertOk()
            ->assertJsonPath('attempts_reclaimed', 3);

        $this->asTeacher()->postJson("/api/v1/cbt/offline-bundle/{$bundleId}/key")->assertStatus(403);

        // The pre-issued papers are reclaimed, not left as debris that would
        // block the candidates from ever being issued one again.
        $this->assertSame(0, CbtAttempt::where('exam_id', $this->exam->id)->count());
    }

    // ==================================================================
    // Pre-issued attempts
    // ==================================================================

    public function test_pre_issued_attempts_do_not_burn_an_allowance(): void
    {
        $this->issueBundle();

        $attempts = CbtAttempt::where('exam_id', $this->exam->id)->get();
        $this->assertCount(3, $attempts);
        $this->assertTrue($attempts->every(fn ($a) => $a->status === CbtAttempt::STATUS_PROVISIONED));

        // max_attempts is 1. A candidate who ends up sitting online must not
        // be told they have already used their attempt.
        $service = app(\App\Services\CbtExamService::class);
        $attempt = $service->startAttempt($this->exam, $this->students[0]->id);

        $this->assertSame('in_progress', $attempt->status);
        $this->assertSame(1, CbtAttempt::where('student_id', $this->students[0]->id)->count());
        $this->assertNotNull($attempt->started_at);
    }

    /** Re-issuing must not mint a second paper for the same candidate. */
    public function test_re_issuing_reuses_the_papers_already_provisioned(): void
    {
        $first = $this->issueBundle();
        $second = $this->issueBundle(['override_existing' => true]);

        $this->assertSame(3, CbtAttempt::where('exam_id', $this->exam->id)->count());
        $this->assertNotSame($first['bundle']['bundle_id'], $second['bundle']['bundle_id']);
    }

    /** Two relays for one exam is a split brain and needs saying out loud. */
    public function test_a_second_issuance_needs_an_explicit_override(): void
    {
        $this->issueBundle();

        $response = $this->asTeacher()
            ->postJson("/api/v1/cbt/exams/{$this->exam->id}/offline-bundle", ['relay_identity' => 'Lab B laptop'])
            ->assertStatus(422);

        // Named, so the second issuance is a deliberate act by somebody who
        // read which machine already has the paper.
        $this->assertStringContainsString('Lab A laptop', $response->json('error'));
        $this->assertStringContainsString('override_existing', $response->json('error'));

        $this->assertSame(1, CbtOfflineBundle::where('exam_id', $this->exam->id)->count());
    }

    public function test_an_online_only_paper_cannot_be_bundled(): void
    {
        $this->exam->update(['allow_offline' => false]);

        $this->asTeacher()
            ->postJson("/api/v1/cbt/exams/{$this->exam->id}/offline-bundle")
            ->assertStatus(422);
    }

    /** §15: no scheduled start means no deadline the relay can carry. */
    public function test_a_paper_with_no_opening_time_cannot_be_bundled(): void
    {
        $this->exam->update(['opens_at' => null]);

        $response = $this->asTeacher()
            ->postJson("/api/v1/cbt/exams/{$this->exam->id}/offline-bundle")
            ->assertStatus(422);

        $this->assertStringContainsString('opening time', $response->json('error'));
    }

    // ==================================================================
    // §9.3 Batch sync
    // ==================================================================

    private function rosterFor(array $issued): array
    {
        $bundle = CbtOfflineBundle::where('bundle_id', $issued['bundle']['bundle_id'])->firstOrFail();
        $opened = app(CbtOfflineBundleService::class)->open($issued['bundle'], $bundle->content_key);

        return $opened['roster'];
    }

    public function test_a_relay_syncs_a_whole_room_in_one_call(): void
    {
        $issued = $this->issueBundle();
        $roster = $this->rosterFor($issued);

        $attempts = [];
        foreach ($roster as $entry) {
            $attempts[] = [
                'attempt_id' => $entry['attempt_id'],
                'answers' => collect($entry['question_order'])->map(fn ($questionId, $i) => [
                    'question_id' => $questionId,
                    'response' => ['selected_option' => 'A'],
                    'client_sequence' => $i + 1,
                    'client_timestamp' => now()->subMinutes(30 - $i)->toIso8601String(),
                ])->all(),
                'events' => [
                    ['event_type' => 'focus_lost', 'occurred_at' => now()->subMinutes(20)->toIso8601String()],
                    ['event_type' => 'paper_section_reached', 'occurred_at' => now()->subMinutes(10)->toIso8601String()],
                ],
                'submit' => true,
            ];
        }

        $response = $this->asTeacher()->postJson('/api/v1/cbt/offline-sync/batch', [
            'bundle_id' => $issued['bundle']['bundle_id'],
            'attempts' => $attempts,
        ]);

        $response->assertOk();
        $this->assertSame(3, $response->json('summary.attempts'));
        $this->assertSame(12, $response->json('summary.saved'));
        $this->assertSame(6, $response->json('summary.events'));
        $this->assertSame(3, $response->json('summary.finalised'));

        // A mixed paper with theory in it is still awaiting a human.
        $this->assertSame(3, CbtAttempt::where('requires_manual_grading', true)->count());
    }

    /**
     * A relay holds a staff token. Without the roster check, that token plus a
     * guessed attempt id would be a write path into any child's paper in the
     * school.
     */
    public function test_batch_sync_rejects_an_attempt_outside_the_roster(): void
    {
        $issued = $this->issueBundle();

        // A real attempt, on a real paper, belonging to a candidate who was
        // simply not on this relay's roster.
        $otherExam = $this->makePaper(['title' => 'A different paper']);
        $outsider = CbtAttempt::create([
            'school_id' => $this->school->id,
            'exam_id' => $otherExam->id,
            'student_id' => $this->students[0]->id,
            'attempt_number' => 9,
            'seed' => 12345,
            'question_order' => [1],
            'status' => 'in_progress',
            'started_at' => now(),
            'server_deadline_at' => now()->addHour(),
        ]);

        $response = $this->asTeacher()->postJson('/api/v1/cbt/offline-sync/batch', [
            'bundle_id' => $issued['bundle']['bundle_id'],
            'attempts' => [[
                'attempt_id' => $outsider->id,
                'answers' => [['question_id' => 1, 'response' => ['selected_option' => 'A'], 'client_sequence' => 1]],
            ]],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('roster', $response->json('attempts.0.rejected.0'));
        $this->assertSame(0, CbtAttemptAnswer::where('attempt_id', $outsider->id)->count());
    }

    /** A retried sync after a dropped connection must change nothing. */
    public function test_a_replayed_batch_is_idempotent(): void
    {
        $issued = $this->issueBundle();
        $roster = $this->rosterFor($issued);
        $entry = $roster[0];

        $payload = [
            'bundle_id' => $issued['bundle']['bundle_id'],
            'attempts' => [[
                'attempt_id' => $entry['attempt_id'],
                'answers' => [[
                    'question_id' => $entry['question_order'][0],
                    'response' => ['selected_option' => 'A'],
                    'client_sequence' => 4,
                    'client_timestamp' => now()->subMinutes(5)->toIso8601String(),
                ]],
                'events' => [
                    ['event_type' => 'focus_lost', 'occurred_at' => now()->subMinutes(20)->toIso8601String()],
                ],
            ]],
        ];

        $first = $this->asTeacher()->postJson('/api/v1/cbt/offline-sync/batch', $payload)->assertOk();
        $second = $this->asTeacher()->postJson('/api/v1/cbt/offline-sync/batch', $payload)->assertOk();

        $this->assertSame(1, $first->json('summary.saved'));
        $this->assertSame(1, $first->json('summary.events'));

        // Second time round: the answer is recognised as already held, and the
        // integrity trail is not inflated. A duplicated focus-loss count is
        // worse than a missing one, because somebody will act on it.
        $this->assertSame(0, $second->json('summary.saved'));
        $this->assertSame(1, $second->json('summary.ignored'));
        $this->assertSame(0, $second->json('summary.events'));

        $this->assertSame(1, CbtAttemptAnswer::where('attempt_id', $entry['attempt_id'])->count());
        $this->assertSame(1, CbtAttemptEvent::where('attempt_id', $entry['attempt_id'])->where('event_type', 'focus_lost')->count());
    }

    /** One bad attempt must not cost the other 399 their sync. */
    public function test_a_partial_failure_is_legible_rather_than_all_or_nothing(): void
    {
        $issued = $this->issueBundle();
        $roster = $this->rosterFor($issued);

        $response = $this->asTeacher()->postJson('/api/v1/cbt/offline-sync/batch', [
            'bundle_id' => $issued['bundle']['bundle_id'],
            'attempts' => [
                [
                    'attempt_id' => $roster[0]['attempt_id'],
                    'answers' => [[
                        'question_id' => $roster[0]['question_order'][0],
                        'response' => ['selected_option' => 'A'],
                        'client_sequence' => 1,
                    ]],
                ],
                [
                    'attempt_id' => 999999,
                    'answers' => [['question_id' => 1, 'response' => ['selected_option' => 'A']]],
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.saved'));
        $this->assertSame(1, $response->json('summary.rejected'));
        $this->assertSame([], $response->json('attempts.0.rejected'));
        $this->assertNotEmpty($response->json('attempts.1.rejected'));
    }

    /** A candidate whose relay never got online still has their paper closed. */
    public function test_a_late_batch_is_still_saved_and_closed_out(): void
    {
        $issued = $this->issueBundle();
        $roster = $this->rosterFor($issued);
        $entry = $roster[0];

        CbtAttempt::where('id', $entry['attempt_id'])->update([
            'status' => 'in_progress',
            'started_at' => now()->subHours(4),
            'server_deadline_at' => now()->subHours(2),
        ]);

        $response = $this->asTeacher()->postJson('/api/v1/cbt/offline-sync/batch', [
            'bundle_id' => $issued['bundle']['bundle_id'],
            'attempts' => [[
                'attempt_id' => $entry['attempt_id'],
                'answers' => [[
                    'question_id' => $entry['question_order'][0],
                    'response' => ['selected_option' => 'A'],
                    'client_sequence' => 1,
                ]],
            ]],
        ]);

        $response->assertOk();
        $this->assertContains($response->json('attempts.0.attempt_status'), ['submitted', 'graded']);
    }

    /** A relay must not be able to forge the server's own account of events. */
    public function test_a_relay_cannot_report_a_server_only_event(): void
    {
        $issued = $this->issueBundle();
        $roster = $this->rosterFor($issued);

        $this->asTeacher()->postJson('/api/v1/cbt/offline-sync/batch', [
            'bundle_id' => $issued['bundle']['bundle_id'],
            'attempts' => [[
                'attempt_id' => $roster[0]['attempt_id'],
                'events' => [['event_type' => 'manually_graded']],
            ]],
        ])->assertStatus(422);
    }

    public function test_a_teacher_at_another_school_cannot_sync_this_bundle(): void
    {
        $issued = $this->issueBundle();

        $other = School::create(['name' => 'Zenith Academy', 'slug' => 'zenith', 'subdomain' => 'zenith']);
        $intruder = User::create(['name' => 'Mr Okonkwo', 'email' => 'okonkwo@zenith.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $other->id, 'user_id' => $intruder->id, 'role' => 'teacher']);

        $this->actingAs($intruder, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost'])
            ->postJson('/api/v1/cbt/offline-sync/batch', [
                'bundle_id' => $issued['bundle']['bundle_id'],
                'attempts' => [['attempt_id' => 1]],
            ])
            ->assertNotFound();
    }

    // ==================================================================
    // §5.4 Deferred results
    // ==================================================================

    public function test_an_offline_paper_cannot_show_results_immediately(): void
    {
        $response = $this->asTeacher()->postJson('/api/v1/cbt/exams', [
            'subject_id' => $this->subject->id,
            'class_id' => $this->class->id,
            'title' => 'Offline mock',
            'duration_minutes' => 45,
            'allow_offline' => true,
            'show_results_immediately' => true,
        ]);

        $response->assertCreated();
        $this->assertFalse($response->json('exam.show_results_immediately'));
        $this->assertStringContainsString('after the relay syncs', $response->json('notice'));
    }

    /**
     * An on-paper theory question stores no response by design. Without an
     * answer row the teacher's mark would have had nothing to write to, and
     * the attempt would have reported itself fully graded with the theory
     * marks silently lost.
     */
    public function test_an_unanswered_theory_question_still_waits_for_a_human(): void
    {
        $issued = $this->issueBundle();
        $roster = $this->rosterFor($issued);
        $entry = $roster[0];

        $this->asTeacher()->postJson('/api/v1/cbt/offline-sync/batch', [
            'bundle_id' => $issued['bundle']['bundle_id'],
            'attempts' => [[
                'attempt_id' => $entry['attempt_id'],
                // Only the objectives come back; Section B was written in a
                // booklet the teacher has not marked yet.
                'answers' => [[
                    'question_id' => $entry['question_order'][0],
                    'response' => ['selected_option' => 'A'],
                    'client_sequence' => 1,
                ]],
                'submit' => true,
            ]],
        ])->assertOk();

        $attempt = CbtAttempt::find($entry['attempt_id']);

        $this->assertTrue((bool) $attempt->requires_manual_grading);
        $this->assertSame('submitted', $attempt->status);

        // Two theory questions, both waiting, both with a row to mark against.
        $ungraded = CbtAttemptAnswer::where('attempt_id', $attempt->id)->whereNull('graded_by')->count();
        $this->assertSame(2, $ungraded);
    }
}
