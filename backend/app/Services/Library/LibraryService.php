<?php

namespace App\Services\Library;

use App\Exceptions\DomainRefusal;
use App\Models\AuditLog;
use App\Models\LibrarySetting;
use App\Models\Student;
use App\Models\User;
use App\Support\InAppNotifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Library circulation (doc §9.2): lending, returns, renewals and
 * reservations. Books are found by their barcode, typed or read with the
 * phone camera — no scanner hardware (doc §3).
 *
 * `books.available_copies` is the shelf count. A copy held for a reader whose
 * reservation is ready is still on the shelf but can only go to that reader,
 * so "free" copies are available minus ready holds.
 */
class LibraryService
{
    public const STAFF_ROLES = ['school_admin', 'teacher', 'nurse', 'bursar'];

    /**
     * Who may borrow: an active student or a member of staff of this school.
     *
     * @return array{kind: string, user: User, student: ?Student}
     */
    public function borrower(int $schoolId, int $userId): array
    {
        $user = User::find($userId);
        $profile = $user ? DB::table('user_profiles')->where('user_id', $userId)->where('school_id', $schoolId)->first() : null;
        if (! $profile) {
            throw new DomainRefusal('That person is not a member of this school.');
        }
        if ($profile->role === 'student') {
            $student = Student::withoutGlobalScopes()->where('school_id', $schoolId)->where('user_id', $userId)->first();
            if (! $student || ($student->status && $student->status !== 'active')) {
                throw new DomainRefusal('Only students currently in the school can borrow.');
            }

            return ['kind' => 'student', 'user' => $user, 'student' => $student];
        }
        if (in_array($profile->role, self::STAFF_ROLES, true)) {
            $status = DB::table('staff')->where('school_id', $schoolId)->where('user_id', $userId)->value('employment_status');
            if ($status === 'separated') {
                throw new DomainRefusal('This staff member has left the school.');
            }

            return ['kind' => 'staff', 'user' => $user, 'student' => null];
        }

        throw new DomainRefusal('Only students and staff borrow from the library.');
    }

    /** Copies on the shelf that are not held for someone's reservation. */
    public function freeCopies(object $book, ?int $forUserId = null): int
    {
        $held = DB::table('book_reservations')->where('book_id', $book->id)->where('status', 'ready')
            ->when($forUserId, fn ($q) => $q->where('user_id', '!=', $forUserId))->count();

        return max((int) $book->available_copies - $held, 0);
    }

    public function lend(int $schoolId, int $bookId, int $borrowerId, User $by): object
    {
        $settings = LibrarySetting::for($schoolId);
        $who = $this->borrower($schoolId, $borrowerId);

        return DB::transaction(function () use ($schoolId, $bookId, $borrowerId, $by, $settings, $who) {
            $book = DB::table('books')->where('school_id', $schoolId)->where('id', $bookId)->lockForUpdate()->first();
            if (! $book) {
                throw new DomainRefusal('That book is not in this library.');
            }

            $open = DB::table('borrow_records')->where('school_id', $schoolId)->where('user_id', $borrowerId)->whereNull('returned_at');
            if ((clone $open)->where('book_id', $bookId)->exists()) {
                throw new DomainRefusal('They already have a copy of this book.');
            }
            if ((clone $open)->whereDate('due_date', '<', Carbon::today())->exists()) {
                throw new DomainRefusal('They have an overdue book. It must come back before they borrow another.');
            }
            $max = $who['kind'] === 'student' ? $settings->student_max_loans : $settings->staff_max_loans;
            if ((clone $open)->count() >= $max) {
                throw new DomainRefusal("They already have {$max} book(s) out, the most the library allows.");
            }
            if ($this->freeCopies($book, $borrowerId) < 1) {
                throw new DomainRefusal((int) $book->available_copies > 0
                    ? 'The copies on the shelf are being held for readers who reserved them.'
                    : 'No copy is on the shelf. They can reserve it.');
            }

            $days = $who['kind'] === 'student' ? $settings->student_loan_days : $settings->staff_loan_days;
            $id = DB::table('borrow_records')->insertGetId([
                'school_id' => $schoolId, 'book_id' => $bookId, 'user_id' => $borrowerId, 'issued_by' => $by->id,
                'borrowed_at' => Carbon::today()->toDateString(), 'due_date' => $this->dueFrom(Carbon::today(), $days),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('books')->where('id', $bookId)->decrement('available_copies');
            DB::table('book_reservations')->where('book_id', $bookId)->where('user_id', $borrowerId)->whereIn('status', ['waiting', 'ready'])
                ->update(['status' => 'fulfilled', 'updated_at' => now()]);

            return DB::table('borrow_records')->find($id);
        });
    }

    /** A copy comes back: good, damaged, or reported lost. */
    public function giveBack(int $schoolId, int $loanId, string $condition, User $by): object
    {
        return DB::transaction(function () use ($schoolId, $loanId, $condition, $by) {
            $loan = DB::table('borrow_records')->where('school_id', $schoolId)->where('id', $loanId)->lockForUpdate()->first();
            if (! $loan) {
                throw new DomainRefusal('That loan is not in this library.');
            }
            if ($loan->returned_at) {
                throw new DomainRefusal('That book has already been returned.');
            }

            DB::table('borrow_records')->where('id', $loanId)->update([
                'returned_at' => Carbon::today()->toDateString(), 'returned_to' => $by->id, 'return_condition' => $condition, 'updated_at' => now(),
            ]);

            if ($condition === 'lost') {
                // The copy leaves the stock; the shelf count is unchanged.
                DB::table('books')->where('id', $loan->book_id)->where('total_copies', '>', 0)->decrement('total_copies');
            } else {
                DB::table('books')->where('id', $loan->book_id)->increment('available_copies');
            }
            if ($condition !== 'good') {
                AuditLog::create([
                    'school_id' => $schoolId, 'user_id' => $by->id, 'action' => "library.copy_{$condition}",
                    'auditable_type' => 'borrow_record', 'auditable_id' => $loanId,
                    'new_values' => ['book_id' => $loan->book_id, 'borrower_id' => $loan->user_id],
                ]);
            }
            $this->promoteNext($schoolId, (int) $loan->book_id);

            return DB::table('borrow_records')->find($loanId);
        });
    }

    /** Another loan period, unless someone is waiting for the book. */
    public function renew(int $schoolId, int $loanId, User $by): object
    {
        $settings = LibrarySetting::for($schoolId);
        $loan = DB::table('borrow_records')->where('school_id', $schoolId)->where('id', $loanId)->first();
        if (! $loan || $loan->returned_at) {
            throw new DomainRefusal('Only a book that is out can be renewed.');
        }
        if ((int) $loan->renewals >= $settings->max_renewals) {
            throw new DomainRefusal($settings->max_renewals === 0 ? 'This library does not renew loans.' : 'This loan has been renewed as many times as the library allows.');
        }
        if (DB::table('book_reservations')->where('book_id', $loan->book_id)->where('status', 'waiting')->where('user_id', '!=', $loan->user_id)->exists()) {
            throw new DomainRefusal('Someone has reserved this book, so it must come back on its due date.');
        }

        $kind = $this->borrower($schoolId, (int) $loan->user_id)['kind'];
        $days = $kind === 'student' ? $settings->student_loan_days : $settings->staff_loan_days;
        $from = Carbon::parse($loan->due_date)->max(Carbon::today());
        DB::table('borrow_records')->where('id', $loanId)->update([
            'due_date' => $this->dueFrom($from, $days), 'renewals' => (int) $loan->renewals + 1,
            'due_reminder_at' => null, 'overdue_notice_at' => null, 'updated_at' => now(),
        ]);

        return DB::table('borrow_records')->find($loanId);
    }

    /** Join the queue for a book that has no free copy. */
    public function reserve(int $schoolId, int $bookId, int $userId): object
    {
        $this->borrower($schoolId, $userId);
        $book = DB::table('books')->where('school_id', $schoolId)->where('id', $bookId)->first();
        if (! $book) {
            throw new DomainRefusal('That book is not in this library.');
        }
        if (DB::table('borrow_records')->where('book_id', $bookId)->where('user_id', $userId)->whereNull('returned_at')->exists()) {
            throw new DomainRefusal('You already have this book.');
        }
        if (DB::table('book_reservations')->where('book_id', $bookId)->where('user_id', $userId)->whereIn('status', ['waiting', 'ready'])->exists()) {
            throw new DomainRefusal('You have already reserved this book.');
        }
        if ($this->freeCopies($book) > 0) {
            throw new DomainRefusal('A copy is on the shelf. Ask at the library to borrow it.');
        }

        $id = DB::table('book_reservations')->insertGetId([
            'school_id' => $schoolId, 'book_id' => $bookId, 'user_id' => $userId, 'status' => 'waiting', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('book_reservations')->find($id);
    }

    public function cancelReservation(int $schoolId, int $id): void
    {
        $r = DB::table('book_reservations')->where('school_id', $schoolId)->where('id', $id)->whereIn('status', ['waiting', 'ready'])->first();
        if (! $r) {
            throw new DomainRefusal('That reservation is no longer open.');
        }
        DB::table('book_reservations')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        if ($r->status === 'ready') {
            $this->promoteNext($schoolId, (int) $r->book_id);
        }
    }

    /**
     * Hold a free copy for the next reader in the queue and tell them.
     * Called whenever a copy comes back or a hold lapses.
     */
    public function promoteNext(int $schoolId, int $bookId): void
    {
        $book = DB::table('books')->find($bookId);
        if (! $book || $this->freeCopies($book) < 1) {
            return;
        }
        $next = DB::table('book_reservations')->where('book_id', $bookId)->where('status', 'waiting')->orderBy('id')->first();
        if (! $next) {
            return;
        }

        $until = Carbon::today()->addDays(LibrarySetting::for($schoolId)->hold_days);
        DB::table('book_reservations')->where('id', $next->id)->update(['status' => 'ready', 'ready_until' => $until->toDateString(), 'updated_at' => now()]);
        InAppNotifier::send($schoolId, [(int) $next->user_id], 'library', 'Your reserved book is ready',
            "\"{$book->title}\" is being held for you at the library until {$until->format('D j M')}.", true);
    }

    /**
     * The daily run: lapse uncollected holds, remind readers of books due
     * tomorrow, and tell them (and a student's parents) once a book is late.
     *
     * @return array{expired: int, due: int, overdue: int}
     */
    public function dailyRun(): array
    {
        $out = ['expired' => 0, 'due' => 0, 'overdue' => 0];

        foreach (DB::table('book_reservations')->where('status', 'ready')->whereDate('ready_until', '<', Carbon::today())->get() as $r) {
            DB::table('book_reservations')->where('id', $r->id)->update(['status' => 'expired', 'updated_at' => now()]);
            $this->promoteNext((int) $r->school_id, (int) $r->book_id);
            $out['expired']++;
        }

        $open = DB::table('borrow_records as l')->join('books as b', 'b.id', '=', 'l.book_id')->whereNull('l.returned_at')->whereNotNull('l.school_id');

        foreach ((clone $open)->whereDate('l.due_date', Carbon::tomorrow())->whereNull('l.due_reminder_at')->get(['l.id', 'l.school_id', 'l.user_id', 'b.title']) as $l) {
            InAppNotifier::send((int) $l->school_id, [(int) $l->user_id], 'library', 'Library book due tomorrow', "Please return \"{$l->title}\" tomorrow, or ask to renew it.");
            DB::table('borrow_records')->where('id', $l->id)->update(['due_reminder_at' => now()]);
            $out['due']++;
        }

        foreach ((clone $open)->whereDate('l.due_date', '<', Carbon::today())->whereNull('l.overdue_notice_at')->get(['l.id', 'l.school_id', 'l.user_id', 'l.due_date', 'b.title']) as $l) {
            $to = [(int) $l->user_id];
            if (LibrarySetting::for((int) $l->school_id)->notify_parents) {
                $student = Student::withoutGlobalScopes()->where('school_id', $l->school_id)->where('user_id', $l->user_id)->first();
                $to = array_merge($to, $student ? $student->guardians()->pluck('guardians.user_id')->filter()->all() : []);
            }
            $due = Carbon::parse($l->due_date)->format('j M');
            InAppNotifier::send((int) $l->school_id, $to, 'library', 'Library book overdue', "\"{$l->title}\" was due back on {$due}.", true);
            DB::table('borrow_records')->where('id', $l->id)->update(['overdue_notice_at' => now()]);
            $out['overdue']++;
        }

        return $out;
    }

    /** Due dates never fall on a weekend, when the library is shut. */
    private function dueFrom(Carbon $from, int $days): string
    {
        $due = $from->copy()->addDays($days);
        while ($due->isWeekend()) {
            $due->addDay();
        }

        return $due->toDateString();
    }
}
