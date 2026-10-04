<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Library lending, returns, renewals and reservations (doc §9.2). */
class LibraryCirculationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $librarian;
    private User $ada;
    private User $tunde;
    private User $parent;
    private Student $adaStudent;
    private int $book;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 09:00:00'); // a Wednesday
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->librarian = $this->user('Mrs Okafor', 'teacher');
        $this->ada = $this->user('Ada Obi', 'student');
        $this->tunde = $this->user('Tunde Bello', 'student');
        $this->parent = $this->user('Mr Obi', 'parent');
        $this->adaStudent = Student::create(['school_id' => $this->school->id, 'user_id' => $this->ada->id, 'class_id' => $class->id, 'admission_number' => 'HT/001', 'status' => 'active']);
        Student::create(['school_id' => $this->school->id, 'user_id' => $this->tunde->id, 'class_id' => $class->id, 'admission_number' => 'HT/002', 'status' => 'active']);
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->parent->id])->students()->attach($this->adaStudent->id);

        $this->book = DB::table('books')->insertGetId([
            'school_id' => $this->school->id, 'title' => 'Things Fall Apart', 'author' => 'Chinua Achebe', 'barcode' => 'BC-001',
            'total_copies' => 1, 'available_copies' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }

    private function lend(User $to, string $barcode = 'BC-001')
    {
        return $this->as($this->librarian)->postJson("{$this->host}/library/loans", ['barcode' => $barcode, 'borrower_user_id' => $to->id]);
    }

    public function test_lending_by_barcode_sets_the_due_date_and_takes_a_copy_off_the_shelf(): void
    {
        $loan = $this->lend($this->ada)->assertCreated()->json('loan');

        $this->assertSame('2026-10-21', $loan['due_date']);
        $this->assertSame('HT/001', $loan['admission_number']);
        $this->assertSame(0, DB::table('books')->where('id', $this->book)->value('available_copies'));
        $this->lend($this->tunde)->assertStatus(409)->assertJsonPath('message', 'No copy is on the shelf. They can reserve it.');

        // The catalogue's counts now come from real loans.
        $this->as($this->librarian)->getJson("{$this->host}/library/books")->assertJsonPath('data.0.on_loan', 1);
    }

    public function test_a_due_date_never_lands_on_a_weekend(): void
    {
        $this->as($this->librarian)->putJson("{$this->host}/library/settings", ['student_loan_days' => 3])->assertForbidden();
        $this->as($this->user('Principal', 'school_admin'))->putJson("{$this->host}/library/settings", ['student_loan_days' => 3])->assertOk();

        $this->assertSame('2026-10-12', $this->lend($this->ada)->json('loan.due_date'), 'Saturday 10th moves to Monday 12th.');
    }

    public function test_limits_and_overdue_books_stop_further_borrowing(): void
    {
        foreach (['BC-002', 'BC-003'] as $code) {
            DB::table('books')->insert(['school_id' => $this->school->id, 'title' => "Book {$code}", 'author' => 'A', 'barcode' => $code, 'total_copies' => 2, 'available_copies' => 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->lend($this->ada)->assertCreated();
        $this->lend($this->ada, 'BC-002')->assertCreated();
        $this->lend($this->ada, 'BC-003')->assertStatus(409)->assertJsonPath('message', 'They already have 2 book(s) out, the most the library allows.');

        Carbon::setTestNow('2026-11-02');
        $this->lend($this->tunde, 'BC-002')->assertCreated();
        $loan = DB::table('borrow_records')->where('user_id', $this->ada->id)->first();
        $this->as($this->librarian)->postJson("{$this->host}/library/loans/{$loan->id}/return")->assertOk();
        $this->lend($this->ada, 'BC-003')->assertStatus(409)->assertJsonPath('message', 'They have an overdue book. It must come back before they borrow another.');
    }

    public function test_a_reservation_holds_the_returned_copy_for_the_next_reader(): void
    {
        $loanId = $this->lend($this->ada)->json('loan.id');

        $this->as($this->tunde)->postJson("{$this->host}/library/reservations", ['book_id' => $this->book])->assertCreated();
        $this->as($this->tunde)->postJson("{$this->host}/library/reservations", ['book_id' => $this->book])->assertStatus(409);
        // Someone is waiting, so Ada can't renew.
        $this->as($this->ada)->postJson("{$this->host}/library/loans/{$loanId}/renew")->assertStatus(409);

        $this->as($this->librarian)->postJson("{$this->host}/library/returns", ['barcode' => 'BC-001'])->assertOk()->assertJsonPath('loan.return_condition', 'good');

        $this->assertSame('ready', DB::table('book_reservations')->value('status'));
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->tunde->id, 'category' => 'library']);
        // The copy on the shelf is Tunde's, not Ada's.
        $this->lend($this->ada)->assertStatus(409)->assertJsonPath('message', 'The copies on the shelf are being held for readers who reserved them.');
        $this->lend($this->tunde)->assertCreated();
        $this->assertSame('fulfilled', DB::table('book_reservations')->value('status'));
    }

    public function test_an_uncollected_hold_lapses_and_passes_down_the_queue(): void
    {
        $this->lend($this->ada);
        $other = $this->user('Chioma Eze', 'student');
        Student::create(['school_id' => $this->school->id, 'user_id' => $other->id, 'admission_number' => 'HT/003', 'status' => 'active']);
        $this->as($this->tunde)->postJson("{$this->host}/library/reservations", ['book_id' => $this->book])->assertCreated();
        $this->as($other)->postJson("{$this->host}/library/reservations", ['book_id' => $this->book])->assertCreated();
        $this->as($this->librarian)->postJson("{$this->host}/library/returns", ['barcode' => 'BC-001']);

        Carbon::setTestNow('2026-10-13 07:00:00');
        $this->artisan('library:daily')->assertSuccessful();

        $this->assertSame(['expired', 'ready'], DB::table('book_reservations')->orderBy('id')->pluck('status')->all());
    }

    public function test_renewal_extends_the_loan_up_to_the_limit(): void
    {
        $loanId = $this->lend($this->ada)->json('loan.id');

        $this->as($this->tunde)->postJson("{$this->host}/library/loans/{$loanId}/renew")->assertForbidden();
        $this->assertSame('2026-11-04', $this->as($this->ada)->postJson("{$this->host}/library/loans/{$loanId}/renew")->assertOk()->json('loan.due_date'));
        $this->as($this->ada)->postJson("{$this->host}/library/loans/{$loanId}/renew")->assertStatus(409);

        $mine = $this->as($this->ada)->getJson("{$this->host}/library/me")->assertOk()->json();
        $this->assertSame('Things Fall Apart', $mine['loans'][0]['title']);
        $this->assertFalse($mine['loans'][0]['can_renew']);
    }

    public function test_a_lost_copy_leaves_the_stock_and_is_audited(): void
    {
        $loanId = $this->lend($this->ada)->json('loan.id');
        $this->as($this->librarian)->postJson("{$this->host}/library/loans/{$loanId}/return", ['condition' => 'lost'])->assertOk();

        $book = DB::table('books')->find($this->book);
        $this->assertSame([0, 0], [(int) $book->total_copies, (int) $book->available_copies]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'library.copy_lost', 'auditable_id' => $loanId]);
        $this->as($this->librarian)->postJson("{$this->host}/library/loans/{$loanId}/return")->assertStatus(409);
    }

    public function test_stock_cannot_drop_below_the_copies_out(): void
    {
        DB::table('books')->where('id', $this->book)->update(['total_copies' => 3, 'available_copies' => 3]);
        $this->lend($this->ada);
        $this->lend($this->tunde);

        $this->as($this->librarian)->putJson("{$this->host}/library/books/{$this->book}", ['total_copies' => 1])->assertStatus(409);
        $this->as($this->librarian)->putJson("{$this->host}/library/books/{$this->book}", ['total_copies' => 5])->assertOk()->assertJsonPath('book.available_copies', 3);
    }

    public function test_reminders_go_once_and_parents_hear_about_overdue_books(): void
    {
        $this->lend($this->ada);

        Carbon::setTestNow('2026-10-20 07:00:00');
        $this->artisan('library:daily');
        $this->artisan('library:daily');
        $this->assertSame(1, DB::table('notification_logs')->where('user_id', $this->ada->id)->where('body', 'like', 'Library book due tomorrow:%')->count());

        Carbon::setTestNow('2026-10-23 07:00:00');
        $this->artisan('library:daily');
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->parent->id, 'category' => 'library']);

        $child = $this->as($this->parent)->getJson("{$this->host}/library/children/{$this->adaStudent->id}")->assertOk()->json();
        $this->assertSame(2, $child['loans'][0]['days_overdue']);
        $this->assertFalse($child['can_reserve']);
        $this->as($this->user('Mrs Stranger', 'parent'))->getJson("{$this->host}/library/children/{$this->adaStudent->id}")->assertForbidden();
    }

    public function test_only_current_members_of_the_school_borrow(): void
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $stranger = User::create(['name' => 'Stranger', 'email' => 's@other.test', 'password' => bcrypt('password123')]);
        $stranger->userProfile()->create(['school_id' => $other->id, 'role' => 'student']);
        $this->lend($stranger)->assertStatus(409)->assertJsonPath('message', 'That person is not a member of this school.');

        $this->adaStudent->update(['status' => 'graduated']);
        $this->lend($this->ada)->assertStatus(409);
        $this->lend($this->parent)->assertStatus(409)->assertJsonPath('message', 'Only students and staff borrow from the library.');

        $teacher = $this->user('Mr Chidi', 'teacher');
        Staff::create(['school_id' => $this->school->id, 'user_id' => $teacher->id, 'staff_id' => 'S001', 'designation' => 'Teacher']);
        $this->assertSame('S001', collect($this->as($this->librarian)->getJson("{$this->host}/library/borrowers?q=chidi")->json('data'))->firstWhere('kind', 'staff')['number']);
        $this->lend($teacher)->assertCreated();
    }
}
