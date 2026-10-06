<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\ParentalConsent;
use App\Models\PickupAuthorization;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGamification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What one family may do, and not do to another: name who collects a child,
 * give or withdraw NDPA consent, edit a parent profile, and see a child's
 * subjects and badges — all for their own children only.
 */
class ParentSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $mum;
    private User $otherDad;
    private Student $ada;
    private Student $tunde;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'Primary 3']);
        $this->ada = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Ada Obi', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'ADA', 'status' => 'active']);
        $this->tunde = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Tunde Bello', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'TUN', 'status' => 'active']);
        $this->mum = $this->guardianOf('Mrs Obi', $this->ada);
        $this->otherDad = $this->guardianOf('Mr Bello', $this->tunde);
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function guardianOf(string $name, Student $child): User
    {
        $u = $this->user($name, 'parent');
        $g = Guardian::create(['school_id' => $this->school->id, 'user_id' => $u->id, 'relationship' => 'parent']);
        DB::table('student_guardian')->insert(['student_id' => $child->id, 'guardian_id' => $g->id]);

        return $u;
    }

    public function test_only_a_childs_guardian_names_who_may_collect_them_and_can_revoke_it(): void
    {
        $person = ['authorized_person_name' => 'Uncle Emeka', 'authorized_person_phone' => '08031234567', 'relationship' => 'uncle'];

        $this->actingAs($this->otherDad, 'sanctum')->postJson("{$this->host}/parent/pickup-authorization", $person + ['student_id' => $this->ada->id])->assertForbidden();
        $this->assertSame(0, PickupAuthorization::count());

        $id = $this->actingAs($this->mum, 'sanctum')->postJson("{$this->host}/parent/pickup-authorization", $person + ['student_id' => $this->ada->id])->assertCreated()->json('pickup_authorization.id');
        $this->assertSame('Uncle Emeka', $this->actingAs($this->mum, 'sanctum')->getJson("{$this->host}/parent/pickup-authorizations")->json('data.0.authorized_person_name'));
        $this->assertSame([], $this->actingAs($this->otherDad, 'sanctum')->getJson("{$this->host}/parent/pickup-authorizations")->json('data'));

        $this->actingAs($this->otherDad, 'sanctum')->postJson("{$this->host}/parent/pickup-authorizations/{$id}/revoke")->assertNotFound();
        $this->actingAs($this->mum, 'sanctum')->postJson("{$this->host}/parent/pickup-authorizations/{$id}/revoke")->assertOk();
        $this->assertFalse((bool) PickupAuthorization::find($id)->is_active);
    }

    public function test_consent_is_a_guardians_own_act_and_never_assumed(): void
    {
        $otherGuardian = Guardian::where('user_id', $this->otherDad->id)->value('id');

        $this->actingAs($this->otherDad, 'sanctum')->postJson("{$this->host}/compliance/parental-consent", ['guardian_id' => $otherGuardian, 'student_id' => $this->ada->id, 'consent_given' => true])->assertForbidden();

        // A forged guardian id is replaced by the caller's own.
        $this->actingAs($this->mum, 'sanctum')->postJson("{$this->host}/compliance/parental-consent", ['guardian_id' => $otherGuardian, 'student_id' => $this->ada->id, 'consent_given' => true])->assertOk();
        $consent = ParentalConsent::firstOrFail();
        $this->assertSame(Guardian::where('user_id', $this->mum->id)->value('id'), $consent->guardian_id);
        $this->assertFalse((bool) $consent->ai_cross_border_consent_given, 'Unticked means no.');

        // The web form sends no guardian id at all: the caller's own is used.
        $this->actingAs($this->mum, 'sanctum')->postJson("{$this->host}/compliance/parental-consent", ['student_id' => $this->ada->id, 'consent_given' => true, 'ai_cross_border_consent_given' => true])->assertOk();
        $this->assertTrue((bool) $consent->fresh()->ai_cross_border_consent_given);
        $this->assertCount(1, $this->actingAs($this->mum, 'sanctum')->getJson("{$this->host}/parent/consents")->json('data'));
        $this->actingAs($this->otherDad, 'sanctum')->postJson("{$this->host}/compliance/parental-consent/{$consent->id}/withdraw")->assertNotFound();
        $this->actingAs($this->mum, 'sanctum')->postJson("{$this->host}/compliance/parental-consent/{$consent->id}/withdraw")->assertOk();
    }

    public function test_a_parent_edits_only_their_own_profile_and_not_custody(): void
    {
        $this->actingAs($this->otherDad, 'sanctum')->putJson("{$this->host}/parents/{$this->mum->id}/profile", ['occupation' => 'Forged'])->assertForbidden();
        $this->actingAs($this->otherDad, 'sanctum')->getJson("{$this->host}/parents/{$this->mum->id}/profile")->assertForbidden();
        $this->actingAs($this->mum, 'sanctum')->putJson("{$this->host}/parents/{$this->mum->id}/profile", ['custody_type' => 'full'])->assertForbidden();
        $this->actingAs($this->mum, 'sanctum')->putJson("{$this->host}/parents/{$this->mum->id}/profile", ['occupation' => 'Trader', 'preferred_contact_method' => 'whatsapp'])->assertOk()->assertJsonPath('data.occupation', 'Trader');
    }

    public function test_dashboards_show_only_released_marks_and_never_an_unreviewed_ai_comment(): void
    {
        $session = \App\Models\AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31', 'is_current' => true]);
        $term = \App\Models\Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-08', 'end_date' => '2026-12-18']);
        $maths = \App\Models\Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);
        $english = \App\Models\Subject::create(['school_id' => $this->school->id, 'name' => 'English', 'code' => 'ENG']);
        \App\Models\ScoreEntry::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'student_id' => $this->ada->id, 'subject_id' => $maths->id, 'total_score' => 81, 'grade' => 'A', 'teacher_comment' => 'Excellent.', 'ai_comment_status' => 'approved']);
        \App\Models\ScoreEntry::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'student_id' => $this->ada->id, 'subject_id' => $english->id, 'total_score' => 44, 'grade' => 'E', 'teacher_comment' => 'AI DRAFT', 'ai_comment_status' => 'pending_approval']);

        $before = $this->actingAs($this->mum, 'sanctum')->getJson("{$this->host}/analytics/parent/{$this->ada->id}")->assertOk();
        $this->assertSame([], $before->json('grades_over_time'), 'Nothing before release.');
        $this->assertSame([], $this->actingAs($this->ada->user, 'sanctum')->getJson("{$this->host}/analytics/student")->json('score_breakdown'));

        \App\Models\ResultRelease::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'is_released' => true, 'released_at' => now()]);
        $after = $this->actingAs($this->mum, 'sanctum')->getJson("{$this->host}/analytics/parent/{$this->ada->id}");
        $this->assertCount(2, $after->json('grades_over_time'));
        $this->assertStringNotContainsString('AI DRAFT', $after->getContent());
        $this->assertStringContainsString('Excellent.', $after->getContent());

        $this->school->forceFill(['result_checker_enabled' => true])->save();
        $this->assertSame([], $this->actingAs($this->mum, 'sanctum')->getJson("{$this->host}/analytics/parent/{$this->ada->id}")->json('grades_over_time'), 'Behind a PIN, as on the result checker.');
    }

    public function test_another_childs_subjects_and_badges_are_not_a_parents_to_see(): void
    {
        StudentGamification::create(['school_id' => $this->school->id, 'student_id' => $this->ada->id, 'points' => 40, 'current_streak' => 2, 'badges' => ['Early Bird']]);

        $this->actingAs($this->otherDad, 'sanctum')->getJson("{$this->host}/students/{$this->ada->id}/subjects")->assertForbidden();
        $this->actingAs($this->otherDad, 'sanctum')->getJson("{$this->host}/gamification/profile/{$this->ada->id}")->assertForbidden();

        $mine = $this->actingAs($this->mum, 'sanctum')->getJson("{$this->host}/gamification/profile/{$this->ada->id}")->assertOk();
        $this->assertStringNotContainsString('@hilltop.test', $mine->getContent(), 'No account email.');

        $board = $this->actingAs($this->otherDad, 'sanctum')->getJson("{$this->host}/gamification/leaderboard")->assertOk();
        $this->assertSame('Ada O.', $board->json('data.0.name'));
        $this->assertStringNotContainsString('@hilltop.test', $board->getContent());
        $this->assertStringNotContainsString('admission_number', $board->getContent());
    }
}
