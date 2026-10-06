<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StudentGamification;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class GamificationController extends Controller
{
    private function getSchoolId(Request $request)
    {
        /*
         * Tenant first — see Controller::schoolId(), which prefers the resolved
         * tenant, falls back to the acting user's own profile, and returns null
         * for a request that is explicitly unscoped.
         *
         * The guard here used to test the user's profile *first*. A platform
         * operator has no school on their profile, so for them the guard was
         * always false and this fell through to `tenant_school` — the school
         * resolved from the host, which for an operator is the internal `admin`
         * subdomain they signed in through, not the tenant they are inspecting.
         * These endpoints therefore answered for the wrong school entirely.
         */
        $schoolId = $this->schoolId();

        if ($schoolId !== null) {
            return $schoolId;
        }

        // Host-resolved tenant, for routes reached without an authenticated
        // profile at all.
        $tenant = $request->attributes->get('tenant_school');

        return $tenant ? $tenant->id : $request->attributes->get('school_id');
    }

    /**
     * Get student gamification profile
     */
    public function getProfile(Request $request, $studentId = null)
    {
        $schoolId = $this->getSchoolId($request);
        
        // Default to student user's linked student model if studentId not passed
        if (!$studentId && $request->user()) {
            $student = Student::where('school_id', $schoolId)
                ->where('user_id', $request->user()->id)
                ->first();
            $studentId = $student ? $student->id : null;
        }

        if (!$studentId) {
            return response()->json(['error' => 'Student ID is required'], 400);
        }

        // Only someone who may see this child (themselves, a guardian, their teachers).
        $this->authorize('view', Student::where('school_id', $schoolId)->findOrFail($studentId));

        $gamification = StudentGamification::firstOrCreate(
            ['school_id' => $schoolId, 'student_id' => $studentId],
            ['points' => 0, 'current_streak' => 0, 'badges' => []]
        );

        // Name only: this used to carry the child's whole record and account email.
        return response()->json(['data' => $gamification->load('student.user:id,name')]);
    }

    /**
     * Record academic/CBT activity to earn points & streaks
     */
    /**
     * Points a given activity is worth. The server decides — not the client.
     *
     * `points_earned` used to be taken straight from the request body, and
     * `student_id` was unrestricted, so any student could POST themselves (or
     * anyone else) a million points and top the leaderboard. A leaderboard
     * anyone can write to is worse than no leaderboard: it actively
     * misinforms, and children notice immediately.
     */
    private const ACTIVITY_POINTS = [
        'daily_login' => 2,
        'assignment_submitted' => 10,
        'cbt_completion' => 25,
        'homework_graded_full_marks' => 15,
        'perfect_attendance_week' => 20,
    ];

    /** Once-per-day activities, so a refresh loop cannot farm points. */
    private const DAILY_ONCE = ['daily_login'];

    public function recordActivity(Request $request)
    {
        $validated = $request->validate([
            'activity_type' => ['required', 'string', Rule::in(array_keys(self::ACTIVITY_POINTS))],
            'student_id'    => 'nullable|integer',
        ]);

        $schoolId = $this->getSchoolId($request);
        $user = $request->user();
        $role = $user->userProfile?->role;
        $isStaff = in_array($role, ['super_admin', 'school_admin', 'teacher'], true);

        // A student may only ever record activity against themselves. Staff may
        // record on a pupil's behalf (an offline achievement, say).
        if ($isStaff) {
            if (! $validated['student_id']) {
                return response()->json(['errors' => ['student_id' => ['Staff must name the student.']]], 422);
            }

            $student = Student::where('school_id', $schoolId)->find($validated['student_id']);
        } else {
            $student = Student::where('user_id', $user->id)->first();
        }

        if (! $student) {
            return response()->json(['error' => 'Student record not found.'], 404);
        }

        $studentId = $student->id;
        $points = self::ACTIVITY_POINTS[$validated['activity_type']];

        $gamification = StudentGamification::firstOrCreate(
            ['school_id' => $schoolId, 'student_id' => $studentId],
            ['points' => 0, 'current_streak' => 0, 'badges' => []]
        );

        $today = Carbon::today();
        $lastActivity = $gamification->last_activity_date ? Carbon::parse($gamification->last_activity_date) : null;

        // Calculate streak
        if (!$lastActivity) {
            $streak = 1;
        } elseif ($lastActivity->isYesterday()) {
            $streak = $gamification->current_streak + 1;
        } elseif ($lastActivity->isToday()) {
            $streak = $gamification->current_streak; // Same day activity keep current streak
        } else {
            $streak = 1; // Broken streak reset to 1
        }

        // Daily-once activities cannot be farmed by re-posting.
        if (in_array($validated['activity_type'], self::DAILY_ONCE, true)
            && $lastActivity && $lastActivity->isToday()) {
            return response()->json([
                'message' => 'Already recorded for today.',
                'data' => $gamification,
            ]);
        }

        $newPoints = $gamification->points + $points;
        $badges = $gamification->badges ?? [];

        // Check & unlock badge achievements
        if ($newPoints >= 100 && !in_array('Century Scholar', $badges)) {
            $badges[] = 'Century Scholar';
        }
        if ($streak >= 7 && !in_array('7-Day Streak Master', $badges)) {
            $badges[] = '7-Day Streak Master';
        }
        if ($validated['activity_type'] === 'cbt_completion' && !in_array('CBT Champion', $badges)) {
            $badges[] = 'CBT Champion';
        }

        $gamification->update([
            'points' => $newPoints,
            'current_streak' => $streak,
            'last_activity_date' => $today,
            'badges' => $badges,
        ]);

        return response()->json([
            'message' => 'Activity recorded successfully. Points and streak updated!',
            'points_awarded' => $points,
            'data'    => $gamification->fresh()
        ]);
    }

    /**
     * Get class/school leaderboard
     */
    public function getLeaderboard(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        /*
         * Points and a short name — nothing else. Every row used to carry the
         * child's whole student record and account email, sent to every
         * signed-in user, parents included (NDPA data minimisation, §12).
         */
        $leaderboard = StudentGamification::where('school_id', $schoolId)
            ->with(['student.user:id,name', 'student.currentClass:id,name'])
            ->orderBy('points', 'desc')
            ->limit(20)
            ->get()
            ->values()
            ->map(function ($row, $i) use ($request) {
                $parts = preg_split('/\s+/', trim((string) $row->student?->user?->name)) ?: [];
                $short = trim(($parts[0] ?? '').' '.(isset($parts[1]) ? mb_substr($parts[1], 0, 1).'.' : ''));

                return [
                    'rank' => $i + 1,
                    'name' => $short ?: 'Student',
                    'class' => $row->student?->currentClass?->name,
                    'points' => (int) $row->points,
                    'badges' => $row->badges ?? [],
                    'is_me' => $row->student?->user_id === $request->user()->id,
                ];
            });

        return response()->json(['data' => $leaderboard]);
    }
}
