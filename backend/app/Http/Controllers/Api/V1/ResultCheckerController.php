<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ResultPin;
use App\Models\ResultPinSale;
use App\Models\ResultRelease;
use App\Models\School;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Term;
use App\Services\ReportCard\TemplateException;
use App\Services\ReportCardPdfService;
use App\Services\ResultPinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * The guardian and student side of the result checker.
 *
 * Three things a guardian can do here: see for free whether a result is ready
 * and roughly how the child did, pay to open the full report card, or redeem a
 * PIN they bought over the counter at the school.
 *
 * Staff never come through this controller — an admin or teacher reads report
 * cards through ReportCardTemplateController, which is not metered. Metering
 * the people who produce the results would be absurd.
 */
class ResultCheckerController extends Controller
{
    public function __construct(
        private ResultPinService $pins,
        private ReportCardPdfService $pdf
    ) {
    }

    /**
     * Free tier: is the result ready, and the headline numbers.
     *
     * Deliberately shows average, overall grade and position without payment.
     * A pure "a result exists" wall generates a steady stream of guardians who
     * pay, see a blank card because no scores were entered for their child, and
     * open a dispute. Showing the shape of the result first makes the purchase
     * an informed one; the subject-by-subject breakdown, teacher comments and
     * the printable card are what the PIN buys.
     */
    public function summary(Request $request, $studentId, $termId)
    {
        [$student, $term, $error] = $this->resolve($request, $studentId, $termId);

        if ($error) {
            return $error;
        }

        $school = School::find($student->school_id);
        $released = ResultRelease::isReleasedFor($student->school_id, $term->id, $student->class_id);

        if (! $released) {
            return response()->json([
                'student_id' => $student->id,
                'term_id' => $term->id,
                'status' => 'not_released',
                'message' => 'Results for this term have not been released yet. The school will notify you.',
            ]);
        }

        $scores = ScoreEntry::withoutGlobalScopes()
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->get();

        if ($scores->isEmpty()) {
            return response()->json([
                'student_id' => $student->id,
                'term_id' => $term->id,
                'status' => 'no_scores',
                'message' => 'No scores have been recorded for this student this term. Please contact the school.',
            ]);
        }

        $access = $this->pins->accessFor($student->school_id, $student->id, $term->id);
        $average = round($scores->avg('total_score'), 2);

        return response()->json([
            'student_id' => $student->id,
            'student_name' => $student->user->name ?? null,
            'term_id' => $term->id,
            'term_name' => $term->name,
            'status' => 'released',
            'unlocked' => (bool) $access,
            'preview' => [
                'subjects_count' => $scores->count(),
                'average' => $average,
                'position' => $this->overallPosition($student, $term),
                'class_size' => Student::withoutGlobalScopes()
                    ->where('school_id', $student->school_id)
                    ->where('class_id', $student->class_id)
                    ->count(),
            ],
            'access' => $access ? [
                'serial' => $access->serial,
                'views_remaining' => $access->viewsRemaining(),
            ] : null,
            'unlock_options' => $access ? null : $this->unlockOptions($school),
        ]);
    }

    /**
     * Paid tier: the full report card.
     *
     * Burns one view per successful render. The view is consumed only after the
     * card renders — a template error that produces no card must not cost the
     * guardian one of their five looks.
     */
    public function show(Request $request, $studentId, $termId)
    {
        [$student, $term, $error] = $this->resolve($request, $studentId, $termId);

        if ($error) {
            return $error;
        }

        $school = School::find($student->school_id);

        if (! ResultRelease::isReleasedFor($student->school_id, $term->id, $student->class_id)) {
            return response()->json([
                'error' => 'Results for this term have not been released yet.',
            ], 404);
        }

        /*
         * A school that has not switched the checker on is not selling results;
         * its guardians read report cards free, exactly as before this feature
         * existed. Defaulting the other way would paywall every school on the
         * platform the moment this deploys.
         */
        if (! $school->result_checker_enabled) {
            return $this->renderCard($request, $student, $term, null);
        }

        $access = $this->pins->accessFor($student->school_id, $student->id, $term->id);

        if (! $access) {
            return response()->json([
                'error' => 'A result-checker PIN is required to open this report card.',
                'student_id' => $student->id,
                'term_id' => $term->id,
                'unlock_options' => $this->unlockOptions($school),
            ], 402);
        }

        return $this->renderCard($request, $student, $term, $access);
    }

    /**
     * Start an online PIN purchase.
     *
     * Returns a reference for the client to hand to the gateway. Nothing is
     * unlocked here — the PIN is allocated when the signed webhook confirms
     * payment, because a client telling us it paid is not evidence that it did.
     */
    public function purchase(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'term_id' => 'required|integer',
            'gateway' => 'required|in:paystack,flutterwave',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        [$student, $term, $error] = $this->resolve($request, $request->student_id, $request->term_id);

        if ($error) {
            return $error;
        }

        $school = School::find($student->school_id);
        $price = $this->pins->retailPrice($school);

        if ($price === null) {
            return response()->json([
                'error' => 'This school is not selling result-checker PINs online. Please contact the school.',
            ], 409);
        }

        if (! ResultRelease::isReleasedFor($student->school_id, $term->id, $student->class_id)) {
            return response()->json([
                'error' => 'Results for this term have not been released yet.',
            ], 409);
        }

        // Refuse to sell a second PIN for a result the guardian can already
        // open. Without this the dashboard will happily charge a parent who
        // tapped "check result" twice.
        if ($this->pins->accessFor($student->school_id, $student->id, $term->id)) {
            return response()->json([
                'error' => 'This result is already unlocked. No payment is needed.',
            ], 409);
        }

        $sale = ResultPinSale::create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'term_id' => $term->id,
            'purchased_by' => $request->user()->id,
            'reference' => $this->pins->generateReference('SPRP'),
            'amount' => $price,
            'gateway' => $request->gateway,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Purchase initiated. Complete payment to unlock the result.',
            'sale' => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'amount' => $sale->amount,
                'currency' => 'NGN',
                'gateway' => $sale->gateway,
                'status' => $sale->status,
            ],
            // The gateway is driven client-side with the school's own public
            // key; the backend's role is to mint the reference and to trust
            // only the signed webhook that comes back against it.
            'stock_warning' => $this->pins->availableStock($student->school_id) < 1
                ? 'The school has no PINs in stock. Your payment will still be honoured.'
                : null,
        ], 201);
    }

    /**
     * Redeem a PIN bought over the counter at the school.
     */
    public function redeem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pin' => 'required|string|max:64',
            'student_id' => 'required|integer',
            'term_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        [$student, $term, $error] = $this->resolve($request, $request->student_id, $request->term_id);

        if ($error) {
            return $error;
        }

        if (! ResultRelease::isReleasedFor($student->school_id, $term->id, $student->class_id)) {
            return response()->json([
                'error' => 'Results for this term have not been released yet. Your PIN has not been used.',
            ], 409);
        }

        try {
            $pin = $this->pins->redeem($student->school_id, $request->pin, $student->id, $term->id);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        AuditLog::create([
            'school_id' => $student->school_id,
            'user_id' => $request->user()->id,
            'action' => 'result_pin.redeemed',
            'auditable_type' => ResultPin::class,
            'auditable_id' => $pin->id,
            // Serial, never the code.
            'new_values' => [
                'serial' => $pin->serial,
                'student_id' => $student->id,
                'term_id' => $term->id,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'PIN accepted. The result is now unlocked.',
            'serial' => $pin->serial,
            'views_remaining' => $pin->viewsRemaining(),
        ]);
    }

    /**
     * PINs this guardian has bought, so a purchase is recoverable if the
     * receipt screen was closed before the code was written down.
     */
    public function myPins(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $pins = ResultPin::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('sold_to', $user->id)
            ->with(['student.user', 'term'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ResultPin $pin) => [
                'serial' => $pin->serial,
                'status' => $pin->status,
                'student' => $pin->student->user->name ?? null,
                'term' => $pin->term->name ?? null,
                'views_used' => $pin->views_used,
                'views_remaining' => $pin->viewsRemaining(),
                'sold_at' => $pin->sold_at,
                'amount' => $pin->sold_amount,
            ]);

        return response()->json(['pins' => $pins]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Load the child and term, and confirm this user may see this child.
     *
     * Returns [student, term, errorResponse]. Authorization is StudentPolicy,
     * the same gate the rest of the student surface uses, so a guardian cannot
     * buy a PIN for a child who is not theirs and thereby read the result.
     */
    private function resolve(Request $request, $studentId, $termId): array
    {
        $student = Student::withoutGlobalScopes()->with('user')->find($studentId);

        if (! $student) {
            return [null, null, response()->json(['error' => 'Student not found.'], 404)];
        }

        if ($request->user()->cannot('view', $student)) {
            return [null, null, response()->json(['error' => 'You are not permitted to view this student.'], 403)];
        }

        $term = Term::withoutGlobalScopes()
            ->where('school_id', $student->school_id)
            ->find($termId);

        if (! $term) {
            return [null, null, response()->json(['error' => 'Term not found.'], 404)];
        }

        return [$student, $term, null];
    }

    /**
     * Render the card, then spend the view.
     *
     * Order matters: a template that throws must leave the guardian's view
     * count untouched, or a broken school template quietly eats every PIN a
     * parent owns.
     */
    private function renderCard(Request $request, Student $student, Term $term, ?ResultPin $access)
    {
        try {
            if ($request->get('format') === 'pdf') {
                $body = $this->pdf->renderPdf((int) $student->id, (int) $term->id);
                $contentType = 'application/pdf';
            } else {
                $body = $this->pdf->renderHtml((int) $student->id, (int) $term->id);
                $contentType = 'text/html; charset=UTF-8';
            }
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($access) {
            $this->pins->consumeView($access);
        }

        $headers = ['Content-Type' => $contentType];

        if ($access) {
            // Lets the dashboard show "3 of 5 views left" without a second call.
            $headers['X-Result-Views-Remaining'] = (string) max(0, $access->viewsRemaining() - 1);
        }

        if ($request->get('format') === 'pdf') {
            $headers['Content-Disposition'] = 'inline; filename="report-card-' . $student->id . '-' . $term->id . '.pdf"';
        }

        return response($body, 200, $headers);
    }

    private function unlockOptions(School $school): array
    {
        $price = $this->pins->retailPrice($school);

        return [
            'online_purchase' => $price !== null ? [
                'available' => true,
                'amount' => $price,
                'currency' => 'NGN',
                'endpoint' => '/api/v1/result-checker/purchase',
            ] : [
                'available' => false,
                'reason' => 'This school does not sell PINs online.',
            ],
            'redeem_pin' => [
                'available' => true,
                'description' => 'Buy a PIN at the school office and enter the code here.',
                'endpoint' => '/api/v1/result-checker/redeem',
            ],
        ];
    }

    private function overallPosition(Student $student, Term $term): ?int
    {
        $classmateIds = Student::withoutGlobalScopes()
            ->where('school_id', $student->school_id)
            ->where('class_id', $student->class_id)
            ->pluck('id');

        $ranked = ScoreEntry::withoutGlobalScopes()
            ->where('term_id', $term->id)
            ->whereIn('student_id', $classmateIds)
            ->get()
            ->groupBy('student_id')
            ->map(fn ($group) => $group->avg('total_score'))
            ->sortDesc()
            ->keys()
            ->values();

        $index = $ranked->search($student->id);

        return $index === false ? null : $index + 1;
    }
}
