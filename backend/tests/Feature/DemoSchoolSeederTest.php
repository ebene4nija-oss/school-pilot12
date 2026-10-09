<?php

namespace Tests\Feature;

use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The demo school has to keep seeding as the schema moves, and the accounts it
 * prints have to actually sign in — a demo that fails at the login screen is
 * worse than no demo.
 */
class DemoSchoolSeederTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email): string
    {
        $token = $this->postJson('http://greenfield.localhost/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
            'subdomain' => 'greenfield',
        ])->assertStatus(200)->json('token');

        // Otherwise the guard keeps this account for the next request.
        $this->app['auth']->forgetGuards();

        return $token;
    }

    public function test_seeds_a_complete_school_and_every_demo_account_signs_in(): void
    {
        $this->seed(DemoSchoolSeeder::class);

        $schoolId = DB::table('schools')->where('subdomain', 'greenfield')->value('id');
        $this->assertNotNull($schoolId);
        // 96 in JSS and SS, 8 in Nursery 2 and 16 in Primary 4.
        $this->assertSame(120, DB::table('students')->where('school_id', $schoolId)->count());
        $this->assertSame(120, DB::table('invoices')->where('school_id', $schoolId)->count());
        $this->assertGreaterThan(0, DB::table('score_entries')->where('ai_comment_status', 'pending_approval')->count());

        foreach (['admin', 'bursar', 'nurse', 'teacher', 'parent', 'student', 'rose.eke'] as $handle) {
            $this->login("{$handle}@greenfield.test");
        }

        $this->withToken($this->login('parent@greenfield.test'))
            ->getJson('http://greenfield.localhost/api/v1/parent/children')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withToken($this->login('admin@greenfield.test'))
            ->getJson('http://greenfield.localhost/api/v1/students')
            ->assertOk();
    }

    /** The demo shows streams, sections, class teachers, the bank and exam halls. */
    public function test_the_demo_shows_streams_sections_teaching_and_exam_halls(): void
    {
        $this->seed(DemoSchoolSeeder::class);
        $host = 'http://greenfield.localhost/api/v1';
        $admin = $this->login('admin@greenfield.test');

        // SS arms are streamed; JSS 2 Gold is the merit arm.
        $classes = collect($this->withToken($admin)->getJson("{$host}/classes")->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['Nursery 2', 'Primary 4', 'JSS 1'], $classes->keys()->take(3)->all());
        $ss2 = collect($classes['SS 2']['arms'])->keyBy('name');
        $this->assertSame('science', $ss2['Gold']['stream']);
        $this->assertSame('arts', $ss2['Blue']['stream']);
        $this->assertTrue(collect($classes['JSS 2']['arms'])->firstWhere('name', 'Gold')['is_merit']);

        // An arts arm does not take Physics; Nursery 2 never sees it.
        $blue = collect($this->withToken($admin)->getJson("{$host}/subjects?class_id={$classes['SS 2']['id']}&arm_id={$ss2['Blue']['id']}")->json('data'))->pluck('name');
        $this->assertNotContains('Physics', $blue);
        $this->assertContains('Literature in English', $blue);
        $nursery = collect($this->withToken($admin)->getJson("{$host}/subjects?class_id={$classes['Nursery 2']['id']}")->json('data'))->pluck('name');
        $this->assertContains('Number Work', $nursery);
        $this->assertNotContains('Physics', $nursery);

        // Primary is taught by class teachers, with French by the specialist.
        $primary = $this->withToken($admin)->getJson("{$host}/teaching/overview?section=primary")->assertOk();
        $primary->assertJsonPath('teaching_model', 'class_teacher')->assertJsonPath('gaps', 0);
        $french = collect(collect($primary->json('classes'))->firstWhere('name', 'Primary 4')['subjects'])->firstWhere('name', 'French');
        $this->assertSame('Mme Claire Mensah', $french['cells'][0]['teachers'][0]['name']);
        $this->assertSame(0, $this->withToken($admin)->getJson("{$host}/teaching/overview?section=secondary")->json('gaps'));

        // The question bank is filed by class, with some still to file.
        $bank = $this->withToken($admin)->getJson("{$host}/cbt/questions/summary")->assertOk();
        $this->assertSame(19, $bank->json('total'));
        $this->assertSame(4, $bank->json('unsorted'));

        // Who has paid, by section.
        $term = $this->withToken($admin)->getJson("{$host}/terms")->json('current_term_id');
        $fees = $this->withToken($admin)->getJson("{$host}/finance/fee-status?term_id={$term}")->assertOk();
        $this->assertSame(['nursery', 'primary', 'secondary'], collect($fees->json('sections'))->pluck('section')->all());
        $this->assertSame(120, $fees->json('totals.students'));

        // SS 2 Physics is written in the Main Hall by the science arm only.
        $exam = DB::table('exam_timetables')->value('id');
        $papers = collect($this->withToken($admin)->getJson("{$host}/exam-timetables/{$exam}")->assertOk()->json('sittings'));
        $physics = $papers->firstWhere('subject', 'Physics');
        $this->assertSame('Main Hall', $physics['venue']);
        $this->assertSame(8, $physics['candidates']);
        $this->assertSame('Own classroom', $papers->firstWhere('subject', 'French')['venue']);

        // A class teacher in primary can enter any of her class's marks.
        $schoolId = DB::table('schools')->where('subdomain', 'greenfield')->value('id');
        $gold = collect($classes['Primary 4']['arms'])->firstWhere('name', 'Gold')['id'];
        $pupil = DB::table('students')->where('class_id', $classes['Primary 4']['id'])->where('arm_id', $gold)->value('id');
        $maths = DB::table('subjects')->where('school_id', $schoolId)->where('name', 'Mathematics')->value('id');
        $this->withToken($this->login('rose.eke@greenfield.test'))
            ->postJson("{$host}/assessment/score", ['term_id' => $term, 'student_id' => $pupil, 'subject_id' => $maths, 'first_ca' => 15, 'second_ca' => 14, 'exam' => 50])
            ->assertOk();
    }

    public function test_running_it_again_replaces_the_school_rather_than_duplicating_it(): void
    {
        $this->seed(DemoSchoolSeeder::class);
        $users = DB::table('users')->count();

        $this->seed(DemoSchoolSeeder::class);

        $this->assertSame(1, DB::table('schools')->where('subdomain', 'greenfield')->count());
        $this->assertSame($users, DB::table('users')->count());
    }
}
