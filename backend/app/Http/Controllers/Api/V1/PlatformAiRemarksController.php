<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * How schools are using AI report-card remarks — counts only.
 *
 * The question is whether the drafts are good enough: a school that rewrites
 * most of them, or leaves hundreds waiting, needs a conversation. Answering it
 * needs no remark text and no student, so this returns neither (doc §12).
 *
 * Read-only by design. Nothing here approves, rejects or edits a remark; that
 * is a teacher's decision alone (CLAUDE.md: remarks stay pending_approval
 * until a teacher acts).
 */
class PlatformAiRemarksController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate(['days' => 'nullable|integer|min:1|max:365']);
        $days = (int) ($validated['days'] ?? 90);
        $since = now()->subDays($days);

        /*
         * Pending is counted whenever it was generated: a draft waiting since
         * last term is exactly what an operator should see. The other figures
         * are for the window. Reviews made before the review column existed
         * have no outcome recorded and are reported as `approved_unrecorded`
         * rather than guessed at.
         */
        $rows = DB::table('score_entries')
            ->whereNull('deleted_at')
            ->where('ai_comment_status', '!=', 'none')
            ->select('school_id')
            ->selectRaw("SUM(CASE WHEN ai_comment_status = 'pending_approval' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("MIN(CASE WHEN ai_comment_status = 'pending_approval' THEN COALESCE(ai_comment_generated_at, updated_at) END) as oldest_pending_at")
            ->selectRaw('SUM(CASE WHEN ai_comment_generated_at >= ? THEN 1 ELSE 0 END) as generated', [$since])
            ->selectRaw("SUM(CASE WHEN ai_comment_review = 'approved_as_is' AND ai_comment_reviewed_at >= ? THEN 1 ELSE 0 END) as approved_as_is", [$since])
            ->selectRaw("SUM(CASE WHEN ai_comment_review = 'edited' AND ai_comment_reviewed_at >= ? THEN 1 ELSE 0 END) as edited", [$since])
            ->selectRaw("SUM(CASE WHEN ai_comment_review = 'rejected' AND ai_comment_reviewed_at >= ? THEN 1 ELSE 0 END) as rejected", [$since])
            ->selectRaw("SUM(CASE WHEN ai_comment_status IN ('approved', 'rejected') AND ai_comment_review IS NULL THEN 1 ELSE 0 END) as reviewed_unrecorded")
            ->groupBy('school_id')
            ->get()
            ->keyBy('school_id');

        $schools = School::query()->tenants()
            ->whereIn('id', $rows->keys())
            ->get(['id', 'name', 'subdomain', 'ai_enabled', 'ai_feature_flags'])
            ->map(function (School $s) use ($rows) {
                $r = $rows->get($s->id);
                $reviewed = (int) $r->approved_as_is + (int) $r->edited + (int) $r->rejected;

                return [
                    'school_id' => $s->id,
                    'name' => $s->name,
                    'subdomain' => $s->subdomain,
                    'ai_enabled' => (bool) $s->ai_enabled,
                    'remarks_feature_on' => (bool) (((array) ($s->ai_feature_flags ?? []))['report_comments'] ?? true),
                    'generated' => (int) $r->generated,
                    'approved_as_is' => (int) $r->approved_as_is,
                    'edited' => (int) $r->edited,
                    'rejected' => (int) $r->rejected,
                    'pending' => (int) $r->pending,
                    'oldest_pending_at' => $r->oldest_pending_at,
                    'reviewed_unrecorded' => (int) $r->reviewed_unrecorded,
                    // Share of reviewed drafts a teacher changed or threw away.
                    'rework_rate' => $reviewed > 0 ? round(((int) $r->edited + (int) $r->rejected) / $reviewed, 3) : null,
                ];
            })
            ->sortByDesc('pending')
            ->values();

        $sum = fn (string $key) => $schools->sum($key);
        $reviewed = $sum('approved_as_is') + $sum('edited') + $sum('rejected');

        return response()->json([
            'window_days' => $days,
            'totals' => [
                'schools' => $schools->count(),
                'generated' => $sum('generated'),
                'approved_as_is' => $sum('approved_as_is'),
                'edited' => $sum('edited'),
                'rejected' => $sum('rejected'),
                'pending' => $sum('pending'),
                'rework_rate' => $reviewed > 0 ? round(($sum('edited') + $sum('rejected')) / $reviewed, 3) : null,
            ],
            'schools' => $schools,
        ]);
    }
}
