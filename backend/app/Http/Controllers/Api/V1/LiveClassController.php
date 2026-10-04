<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LiveClass;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Scheduling for virtual lessons.
 *
 * SchoolPilot is not a video provider: the teacher creates the room in Zoom or
 * Google Meet and the link is stored here. What this adds over pasting a link
 * into a WhatsApp group is that the session is attached to a class and a
 * subject, so the right students see it and nobody else does.
 */
class LiveClassController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /**
     * Sessions for the acting user, narrowed by who they are.
     *
     * A student is shown their own class's sessions only. That filter is here
     * rather than in the page, because a page-level filter is a suggestion —
     * the endpoint would still hand any student every class's joining link.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->userProfile?->role;

        $query = LiveClass::query()
            ->with(['teacher:id,name', 'schoolClass:id,name', 'arm:id,name', 'subject:id,name']);

        if ($role === 'student') {
            $student = \App\Models\Student::where('user_id', $user->id)->first();

            if (! $student) {
                return response()->json(['data' => []]);
            }

            $query->where('class_id', $student->class_id)
                ->where(function ($q) use ($student) {
                    // A session for the whole class has no arm; one for a
                    // specific arm is only that arm's.
                    $q->whereNull('arm_id')->orWhere('arm_id', $student->arm_id);
                });
        } elseif ($role === 'teacher') {
            $query->where('teacher_id', $user->id);
        }

        if ($request->boolean('upcoming')) {
            $query->whereIn('status', ['scheduled', 'live'])
                ->where('scheduled_for', '>=', now()->subHours(2));
        }

        if ($classId = $request->query('class_id')) {
            $query->where('class_id', $classId);
        }

        $sessions = $query->orderBy('scheduled_for')->paginate(50);

        return response()->json([
            'data' => collect($sessions->items())->map(fn (LiveClass $session) => $this->present($session, $role)),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        /*
         * Through the shared resolver, which returns null for an explicitly
         * unscoped request. Reading the tenant directly, as this did, misses
         * that: a platform operator who has not picked a school would have had
         * the session's own school id handed to them, and the session belongs
         * to the internal `admin` subdomain they signed in through.
         */
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json([
                'message' => 'Choose a school before scheduling a class for it.',
            ], 409);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'arm_id' => ['nullable', Rule::exists('arms', 'id')],
            'subject_id' => ['nullable', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'teacher_id' => ['nullable', Rule::exists('users', 'id')],
            'provider' => 'required|in:zoom,google_meet,other',
            // A joining link is the whole point of the record, so it must be a
            // URL and not a room number somebody typed from memory.
            'meeting_url' => 'required|url|max:2000',
            'meeting_id' => 'nullable|string|max:255',
            'passcode' => 'nullable|string|max:255',
            'scheduled_for' => 'required|date',
            'duration_minutes' => 'required|integer|min:5|max:480',
            'objectives' => 'nullable|string|max:4000',
            'lesson_plan_id' => ['nullable', Rule::exists('lesson_plans', 'id')->where('school_id', $schoolId)],
            'recording_enabled' => 'nullable|boolean',
            // Weekly on the same weekday and time, up to this date (§3.1).
            'repeat_weekly_until' => 'nullable|date|after:scheduled_for|before:+6 months',
            'material_ids' => 'nullable|array|max:20',
            'material_ids.*' => ['integer', Rule::exists('learning_materials', 'id')->where('school_id', $schoolId)],
        ]);

        $lms = app(\App\Services\Lms\LmsService::class);

        // A teacher schedules for the classes they teach (§18.3).
        if ($request->user()->userProfile?->role === 'teacher' && ! empty($validated['class_id'])
            && ! $lms->teachesClass($request->user(), (int) $validated['class_id'], isset($validated['arm_id']) ? (int) $validated['arm_id'] : null)) {
            return response()->json(['message' => 'You can only schedule virtual classes for classes you teach.'], 403);
        }

        // Recording stays off until the school has a policy (§16).
        if (($validated['recording_enabled'] ?? false) && ! \App\Models\LmsSetting::for($schoolId)->recording_allowed) {
            return response()->json(['message' => 'This school has not allowed lesson recording.', 'errors' => ['recording_enabled' => ['Recording is not allowed by school policy.']]], 422);
        }

        $dates = [\Illuminate\Support\Carbon::parse($validated['scheduled_for'])];
        if (! empty($validated['repeat_weekly_until'])) {
            $until = \Illuminate\Support\Carbon::parse($validated['repeat_weekly_until'])->endOfDay();
            while (($next = end($dates)->copy()->addWeek())->lte($until)) {
                $dates[] = $next;
            }
        }
        $seriesId = count($dates) > 1 ? (string) \Illuminate\Support\Str::uuid() : null;

        foreach (array_slice($dates, 1) as $date) {
            $this->createSession($request, $schoolId, $validated, $date, $seriesId);
        }
        $session = $this->createSession($request, $schoolId, $validated, $dates[0], $seriesId);

        $students = $lms->studentsOf($schoolId, $session->class_id, $session->arm_id);
        $when = $session->scheduled_for->format('D j M, g:ia');
        \App\Support\InAppNotifier::send($schoolId, $students->pluck('user_id')->map(fn ($id) => (int) $id)->all(), 'lms', 'Virtual class scheduled', "{$session->title}: {$when}".($seriesId ? ', weekly' : '').'.');
        \App\Support\InAppNotifier::send($schoolId, $lms->guardianUserIds($students->pluck('id')->all()), 'lms', 'Virtual class scheduled', "{$session->title} for your child: {$when}".($seriesId ? ', weekly' : '').'.');

        return response()->json([
            'message' => count($dates) > 1 ? 'Scheduled '.count($dates).' weekly sessions' : 'Virtual class scheduled',
            'sessions' => count($dates),
            'data' => $this->present($session->fresh(['teacher', 'schoolClass', 'arm', 'subject']), $request->user()->userProfile?->role),
        ], 201);
    }

    private function createSession(Request $request, int $schoolId, array $validated, \Illuminate\Support\Carbon $date, ?string $seriesId): LiveClass
    {
        $session = LiveClass::create([
            'scheduled_for' => $date,
            'series_id' => $seriesId,
            'objectives' => $validated['objectives'] ?? null,
            'lesson_plan_id' => $validated['lesson_plan_id'] ?? null,
            'recording_enabled' => (bool) ($validated['recording_enabled'] ?? false),
            'school_id' => $schoolId,
            'class_id' => $validated['class_id'] ?? null,
            'arm_id' => $validated['arm_id'] ?? null,
            'subject_id' => $validated['subject_id'] ?? null,
            // A teacher scheduling their own lesson is the common case, so the
            // field is optional and defaults to them.
            'teacher_id' => $validated['teacher_id'] ?? $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'provider' => $validated['provider'],
            'meeting_url' => $validated['meeting_url'],
            'meeting_id' => $validated['meeting_id'] ?? null,
            'passcode' => $validated['passcode'] ?? null,
            'duration_minutes' => $validated['duration_minutes'],
            'status' => 'scheduled',
        ]);

        // Materials the teacher attaches become visible to the class through it.
        $lms = app(\App\Services\Lms\LmsService::class);
        foreach (\App\Models\LearningMaterial::whereIn('id', $validated['material_ids'] ?? [])->get() as $m) {
            if ((int) $m->uploaded_by === $request->user()->id || $lms->isAdmin($request->user())) {
                \App\Models\LearningMaterialAccess::create(['school_id' => $schoolId, 'material_id' => $m->id, 'target_type' => 'live_class', 'live_class_id' => $session->id]);
            }
        }

        return $session;
    }

    public function update(Request $request, int $id)
    {
        $session = LiveClass::find($id);

        if (! $session) {
            return response()->json(['message' => 'Virtual class not found'], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'provider' => 'sometimes|in:zoom,google_meet,other',
            'meeting_url' => 'sometimes|url|max:2000',
            'meeting_id' => 'nullable|string|max:255',
            'passcode' => 'nullable|string|max:255',
            'scheduled_for' => 'sometimes|date',
            'duration_minutes' => 'sometimes|integer|min:5|max:480',
            'status' => 'sometimes|in:scheduled,live,ended,cancelled',
        ]);

        if (($validated['status'] ?? null) === 'live' && ! $session->started_at) {
            $validated['started_at'] = now();
        }

        if (in_array($validated['status'] ?? null, ['ended', 'cancelled'], true) && ! $session->ended_at) {
            $validated['ended_at'] = now();
        }

        $session->update($validated);

        return response()->json([
            'message' => 'Virtual class updated',
            'data' => $this->present($session->fresh(['teacher', 'schoolClass', 'arm', 'subject']), $request->user()->userProfile?->role),
        ]);
    }

    public function destroy(int $id)
    {
        $session = LiveClass::find($id);

        if (! $session) {
            return response()->json(['message' => 'Virtual class not found'], 404);
        }

        $session->delete();

        return response()->json(['message' => 'Virtual class removed']);
    }

    /**
     * Shape one session for the client.
     *
     * The joining link and passcode are withheld until the session is actually
     * joinable, and only for students. A link that sits in a student's browser
     * for a week is a link that ends up shared outside the school, and the room
     * it opens has no attendance check of its own.
     */
    private function present(LiveClass $session, ?string $role): array
    {
        $staff = in_array($role, ['super_admin', 'school_admin', 'teacher'], true);
        $revealJoinDetails = $staff || $session->is_joinable;

        return [
            'id' => $session->id,
            'title' => $session->title,
            'description' => $session->description,
            'provider' => $session->provider,
            'class' => $session->schoolClass?->name,
            'class_id' => $session->class_id,
            'arm' => $session->arm?->name,
            'subject' => $session->subject?->name,
            'teacher' => $session->teacher?->name,
            'scheduled_for' => $session->scheduled_for?->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'duration_minutes' => $session->duration_minutes,
            'status' => $session->status,
            'is_joinable' => $session->is_joinable,
            'meeting_url' => $revealJoinDetails ? $session->meeting_url : null,
            'meeting_id' => $revealJoinDetails ? $session->meeting_id : null,
            'passcode' => $revealJoinDetails ? $session->passcode : null,
            'objectives' => $session->objectives,
            'lesson_plan_id' => $session->lesson_plan_id,
            'series_id' => $session->series_id,
            'recording_enabled' => (bool) $session->recording_enabled,
            // Families are told before a recorded lesson, not after (§16).
            'recording_notice' => $session->recording_enabled ? \App\Models\LmsSetting::for((int) $session->school_id)->recording_notice : null,
            'cancel_reason' => $session->cancel_reason,
        ];
    }
}
