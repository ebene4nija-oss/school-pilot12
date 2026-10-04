<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LibrarySetting;
use App\Models\Student;
use App\Services\Library\LibraryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Library circulation (doc §9.2). Staff at the desk lend and take back books,
 * found by barcode (phone camera or typed); readers see their own loans and
 * reserve books that are out; parents see their child's loans.
 */
class LibraryController extends Controller
{
    public function __construct(private LibraryService $library)
    {
    }

    /** Every refusal from the desk rules is a 409 with a readable message. */
    public function callAction($method, $parameters)
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function settings(): JsonResponse
    {
        return response()->json(LibrarySetting::for($this->schoolId()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'student_loan_days' => 'sometimes|integer|min:1|max:180',
            'staff_loan_days' => 'sometimes|integer|min:1|max:365',
            'student_max_loans' => 'sometimes|integer|min:1|max:20',
            'staff_max_loans' => 'sometimes|integer|min:1|max:50',
            'max_renewals' => 'sometimes|integer|min:0|max:10',
            'hold_days' => 'sometimes|integer|min:1|max:14',
            'students_reserve' => 'sometimes|boolean',
            'notify_parents' => 'sometimes|boolean',
        ]);
        $s = LibrarySetting::updateOrCreate(['school_id' => $schoolId], $data);
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'action' => 'library.settings_updated', 'auditable_type' => LibrarySetting::class, 'auditable_id' => $s->id, 'new_values' => $data]);

        return response()->json($s->fresh());
    }

    /** Edit a title. Copies can't drop below the number out on loan. */
    public function updateBook(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $book = DB::table('books')->where('school_id', $schoolId)->where('id', $id)->first();
        abort_unless($book, 404);
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'author' => 'sometimes|string|max:255',
            'isbn' => 'sometimes|nullable|string|max:32',
            'barcode' => ['sometimes', 'string', 'max:64', Rule::unique('books', 'barcode')->where('school_id', $schoolId)->ignore($id)],
            'total_copies' => 'sometimes|integer|min:0|max:10000',
        ]);

        if (array_key_exists('total_copies', $data)) {
            $out = (int) $book->total_copies - (int) $book->available_copies;
            if ($data['total_copies'] < $out) {
                throw new DomainRefusal("{$out} copy/copies are out on loan; the stock can't be less than that.");
            }
            $data['available_copies'] = $data['total_copies'] - $out;
        }
        DB::table('books')->where('id', $id)->update($data + ['updated_at' => now()]);
        if (array_key_exists('total_copies', $data)) {
            $this->library->promoteNext($schoolId, $id);
        }

        return response()->json(['book' => DB::table('books')->find($id)]);
    }

    /** Find a student or member of staff to lend to, by name or number. */
    public function borrowers(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $q = trim((string) $request->input('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }
        $like = '%'.$q.'%';

        $students = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->leftJoin('classes as c', 'c.id', '=', 's.class_id')
            ->where('s.school_id', $schoolId)->where('s.status', 'active')
            ->where(fn ($w) => $w->where('u.name', 'like', $like)->orWhere('s.admission_number', 'like', $like))
            ->limit(10)->get(['u.id as user_id', 'u.name', 's.admission_number as number', 'c.name as class'])
            ->map(fn ($r) => (array) $r + ['kind' => 'student']);
        $staff = DB::table('staff as st')->join('users as u', 'u.id', '=', 'st.user_id')
            ->where('st.school_id', $schoolId)->where(fn ($w) => $w->whereNull('st.employment_status')->orWhere('st.employment_status', '!=', 'separated'))
            ->where(fn ($w) => $w->where('u.name', 'like', $like)->orWhere('st.staff_id', 'like', $like))
            ->limit(10)->get(['u.id as user_id', 'u.name', 'st.staff_id as number'])
            ->map(fn ($r) => (array) $r + ['kind' => 'staff', 'class' => null]);

        return response()->json(['data' => $students->concat($staff)->values()]);
    }

    public function loans(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $status = $request->input('status', 'out');
        $rows = $this->loanQuery($schoolId)
            ->when($status === 'out', fn ($q) => $q->whereNull('l.returned_at'))
            ->when($status === 'overdue', fn ($q) => $q->whereNull('l.returned_at')->whereDate('l.due_date', '<', Carbon::today()))
            ->when($status === 'returned', fn ($q) => $q->whereNotNull('l.returned_at'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('l.user_id', $request->integer('user_id')))
            ->when($request->filled('book_id'), fn ($q) => $q->where('l.book_id', $request->integer('book_id')))
            ->orderBy($status === 'returned' ? 'l.returned_at' : 'l.due_date', $status === 'returned' ? 'desc' : 'asc')
            ->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->presentLoan($r))]);
    }

    /** Lend a copy: the book by barcode or id, the reader by user id. */
    public function lend(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'book_id' => 'required_without:barcode|integer',
            'barcode' => 'required_without:book_id|string|max:64',
            'borrower_user_id' => 'required|integer',
        ]);
        $bookId = $data['book_id'] ?? DB::table('books')->where('school_id', $schoolId)->where('barcode', $data['barcode'])->value('id');
        if (! $bookId) {
            throw new DomainRefusal('No book in this library has that barcode.');
        }

        $loan = $this->library->lend($schoolId, (int) $bookId, (int) $data['borrower_user_id'], $request->user());

        return response()->json(['loan' => $this->presentLoan($this->loanQuery($schoolId)->where('l.id', $loan->id)->first())], 201);
    }

    public function giveBack(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['condition' => 'sometimes|in:good,damaged,lost']);
        $schoolId = $this->schoolId();
        $this->library->giveBack($schoolId, $id, $data['condition'] ?? 'good', $request->user());

        return response()->json(['loan' => $this->presentLoan($this->loanQuery($schoolId)->where('l.id', $id)->first())]);
    }

    /** Return by scanning the book: the one open loan of that title, or the reader's. */
    public function giveBackByBarcode(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate(['barcode' => 'required|string|max:64', 'borrower_user_id' => 'nullable|integer', 'condition' => 'sometimes|in:good,damaged,lost']);
        $open = $this->loanQuery($schoolId)->where('b.barcode', $data['barcode'])->whereNull('l.returned_at')
            ->when($data['borrower_user_id'] ?? null, fn ($q, $u) => $q->where('l.user_id', $u))->get();
        if ($open->isEmpty()) {
            throw new DomainRefusal('No copy of that book is out on loan.');
        }
        if ($open->count() > 1) {
            return response()->json(['message' => 'More than one copy of this book is out. Choose whose copy this is.', 'loans' => $open->map(fn ($r) => $this->presentLoan($r))], 409);
        }

        return $this->giveBack($request, (int) $open->first()->id);
    }

    public function renew(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $loan = DB::table('borrow_records')->where('school_id', $schoolId)->where('id', $id)->first();
        abort_unless($loan, 404);
        abort_unless($this->isDesk($request) || (int) $loan->user_id === $request->user()->id, 403, 'You can renew only your own books.');
        $this->library->renew($schoolId, $id, $request->user());

        return response()->json(['loan' => $this->presentLoan($this->loanQuery($schoolId)->where('l.id', $id)->first())]);
    }

    public function reservations(Request $request): JsonResponse
    {
        $rows = DB::table('book_reservations as r')->join('books as b', 'b.id', '=', 'r.book_id')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.school_id', $this->schoolId())->whereIn('r.status', ['waiting', 'ready'])
            ->orderBy('r.book_id')->orderBy('r.id')
            ->get(['r.id', 'r.status', 'r.ready_until', 'r.created_at', 'r.book_id', 'b.title', 'b.author', 'u.id as user_id', 'u.name']);

        return response()->json(['data' => $rows]);
    }

    /** A reader reserves for themselves; the desk may reserve for a reader. */
    public function reserve(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate(['book_id' => 'required|integer', 'user_id' => 'nullable|integer']);
        $userId = $request->user()->id;
        if (! empty($data['user_id']) && $data['user_id'] !== $userId) {
            abort_unless($this->isDesk($request), 403, 'You can reserve only for yourself.');
            $userId = (int) $data['user_id'];
        }
        if ($request->user()->userProfile?->role === 'student') {
            abort_unless(LibrarySetting::for($schoolId)->students_reserve, 403, 'Ask at the library to reserve a book.');
        }

        return response()->json(['reservation' => $this->library->reserve($schoolId, (int) $data['book_id'], $userId)], 201);
    }

    public function cancelReservation(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $r = DB::table('book_reservations')->where('school_id', $schoolId)->where('id', $id)->first();
        abort_unless($r, 404);
        abort_unless($this->isDesk($request) || (int) $r->user_id === $request->user()->id, 403, 'You can cancel only your own reservation.');
        $this->library->cancelReservation($schoolId, $id);

        return response()->json(['message' => 'Reservation cancelled.']);
    }

    /** A reader's own loans, reservations and recent history. */
    public function mine(Request $request): JsonResponse
    {
        return response()->json($this->readerView($this->schoolId(), $request->user()->id, true));
    }

    /** A parent's view of one child's library books. */
    public function child(Request $request, int $studentId): JsonResponse
    {
        $student = Student::where('school_id', $this->schoolId())->findOrFail($studentId);
        abort_unless($student->isGuardedBy($request->user()), 403, 'You can only view your own child.');

        return response()->json($student->user_id ? $this->readerView($this->schoolId(), (int) $student->user_id, false) : ['loans' => [], 'reservations' => [], 'history' => []]);
    }

    private function readerView(int $schoolId, int $userId, bool $self): array
    {
        $settings = LibrarySetting::for($schoolId);

        return [
            'loans' => $this->loanQuery($schoolId)->where('l.user_id', $userId)->whereNull('l.returned_at')->orderBy('l.due_date')->get()
                ->map(fn ($r) => $this->presentLoan($r) + ['can_renew' => $self && (int) $r->renewals < $settings->max_renewals]),
            'reservations' => DB::table('book_reservations as r')->join('books as b', 'b.id', '=', 'r.book_id')
                ->where('r.school_id', $schoolId)->where('r.user_id', $userId)->whereIn('r.status', ['waiting', 'ready'])
                ->get(['r.id', 'r.status', 'r.ready_until', 'b.title', 'b.author']),
            'history' => $this->loanQuery($schoolId)->where('l.user_id', $userId)->whereNotNull('l.returned_at')->orderByDesc('l.returned_at')->limit(20)->get()
                ->map(fn ($r) => $this->presentLoan($r)),
            'can_reserve' => $self,
        ];
    }

    private function isDesk(Request $request): bool
    {
        return in_array($request->user()->userProfile?->role, ['super_admin', 'school_admin', 'teacher'], true);
    }

    private function loanQuery(int $schoolId)
    {
        return DB::table('borrow_records as l')->join('books as b', 'b.id', '=', 'l.book_id')->join('users as u', 'u.id', '=', 'l.user_id')
            ->leftJoin('students as s', fn ($j) => $j->on('s.user_id', '=', 'l.user_id')->where('s.school_id', $schoolId))
            ->leftJoin('classes as c', 'c.id', '=', 's.class_id')
            ->where('l.school_id', $schoolId)
            ->select(['l.id', 'l.book_id', 'l.user_id', 'l.borrowed_at', 'l.due_date', 'l.returned_at', 'l.renewals', 'l.return_condition',
                'b.title', 'b.author', 'b.barcode', 'u.name as borrower', 's.admission_number', 'c.name as class']);
    }

    private function presentLoan(object $r): array
    {
        $daysLate = ! $r->returned_at && Carbon::parse($r->due_date)->lt(Carbon::today()) ? (int) Carbon::parse($r->due_date)->diffInDays(Carbon::today()) : 0;

        return [
            'id' => (int) $r->id, 'book_id' => (int) $r->book_id, 'title' => $r->title, 'author' => $r->author, 'barcode' => $r->barcode,
            'user_id' => (int) $r->user_id, 'borrower' => $r->borrower, 'admission_number' => $r->admission_number, 'class' => $r->class,
            'borrowed_at' => Carbon::parse($r->borrowed_at)->toDateString(), 'due_date' => Carbon::parse($r->due_date)->toDateString(),
            'returned_at' => $r->returned_at ? Carbon::parse($r->returned_at)->toDateString() : null,
            'renewals' => (int) $r->renewals, 'return_condition' => $r->return_condition, 'days_overdue' => $daysLate,
        ];
    }
}
