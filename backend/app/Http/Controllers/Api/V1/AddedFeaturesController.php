<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ParentalConsent;
use App\Models\Student;
use App\Models\MessageThread;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use ZipArchive;

class AddedFeaturesController extends Controller
{
    private function getSchoolId(Request $request)
    {
        if ($request->user() && $request->user()->userProfile && $request->user()->userProfile->school_id) {
            return $request->user()->userProfile->school_id;
        }

        $tenant = $request->attributes->get('tenant_school');
        if ($tenant) {
            return $tenant->id;
        }

        return $request->attributes->get('school_id');
    }

    /**
     * Record NDPA Parental Consent
     */
    public function recordParentalConsent(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validated = $request->validate([
            'guardian_id' => ['required', \Illuminate\Validation\Rule::exists('guardians', 'id')->where('school_id', $schoolId)],
            'student_id'  => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'consent_given' => 'required|boolean',
            'ai_cross_border_consent_given' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $consent = ParentalConsent::updateOrCreate(
            [
                'school_id' => $schoolId,
                'guardian_id' => $validated['guardian_id'],
                'student_id' => $validated['student_id'],
            ],
            [
                'consent_given' => $validated['consent_given'],
                'ai_cross_border_consent_given' => $validated['ai_cross_border_consent_given'] ?? true,
                'ip_address' => $request->ip(),
                'consented_at' => now(),
                'withdrawn_at' => $validated['consent_given'] ? null : now(),
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Parental consent recorded successfully under NDPA provisions',
            'data' => $consent
        ], 200);
    }

    /**
     * Withdraw NDPA Parental Consent
     */
    public function withdrawParentalConsent(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);

        $consent = ParentalConsent::where('school_id', $schoolId)->findOrFail($id);
        $consent->update([
            'consent_given' => false,
            'withdrawn_at' => now(),
        ]);

        return response()->json([
            'message' => 'Parental consent withdrawn successfully',
            'data' => $consent
        ]);
    }

    /**
     * One-Click School Data Export (Data Portability)
     */
    public function exportSchoolData(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $students = Student::where('school_id', $schoolId)->with(['user', 'class', 'arm'])->get();

        $data = [
            'export_timestamp' => now()->toIso8601String(),
            'school_id' => $schoolId,
            'total_students' => $students->count(),
            'students' => $students->toArray(),
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'School data export archive created',
            'export' => $data
        ]);
    }

    /**
     * Teacher-Parent Direct In-App Messaging
     */
    public function getThreads(Request $request)
    {
        $schoolId = $this->getSchoolId($request);
        $userId = $request->user()->id;

        // Paginated (gap G10). A form teacher accumulates one thread per family
        // and keeps them for years, so "every conversation I have ever had" is
        // not a payload to hand a handset on every open of the Messages tab.
        $threads = MessageThread::where('school_id', $schoolId)
            ->where(function ($query) use ($userId) {
                $query->where('teacher_id', $userId)
                      ->orWhere('parent_id', $userId);
            })
            ->with(['teacher', 'parent', 'student', 'messages' => function ($q) {
                $q->latest()->limit(1);
            }])
            ->latest('last_message_at')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        // Unread count per thread, counted in one query rather than per row.
        $unread = Message::whereIn('thread_id', $threads->pluck('id'))
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->selectRaw('thread_id, COUNT(*) as unread')
            ->groupBy('thread_id')
            ->pluck('unread', 'thread_id');

        $threads->getCollection()->each(function (MessageThread $thread) use ($unread) {
            $thread->setAttribute('unread_count', (int) ($unread[$thread->id] ?? 0));
        });

        return response()->json([
            /*
             * The rows stay a flat array under `data`.
             *
             * Handing back the paginator whole would move every row from
             * `data.N` to `data.data.N`, and this path is already published to
             * installed clients — the versioning rule in CLAUDE.md exists for
             * exactly this. Paging metadata goes in a sibling key, where adding
             * it breaks nobody.
             */
            'data' => $threads->items(),
            'meta' => [
                'current_page' => $threads->currentPage(),
                'last_page' => $threads->lastPage(),
                'per_page' => $threads->perPage(),
                'total' => $threads->total(),
            ],
            /*
             * Counted across every thread, not just this page — it is the badge
             * on the Messages tab, and a badge that only counts the first
             * twenty-five conversations is worse than no badge.
             */
            'total_unread' => Message::whereIn(
                'thread_id',
                MessageThread::where('school_id', $schoolId)
                    ->where(fn ($q) => $q->where('teacher_id', $userId)->orWhere('parent_id', $userId))
                    ->select('id')
            )
                ->where('sender_id', '!=', $userId)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    /**
     * Send a message in a teacher↔parent thread.
     *
     * Two things this now gets right that it did not before.
     *
     * **Participation is checked.** The thread was previously loaded by
     * `school_id` and id alone, so any authenticated user in the school could
     * post into any parent↔teacher conversation by guessing a thread id.
     *
     * **Roles are assigned by role, not by who typed first.** Thread creation
     * hardcoded `teacher_id => sender, parent_id => recipient`, so a parent who
     * opened the conversation was stored as the teacher and vice versa. The
     * columns then meant nothing, and nothing validated that the recipient was
     * a teacher at all — a parent could open a thread with another parent.
     */
    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'student_id'   => 'nullable|exists:students,id',
            'subject'      => 'nullable|string|max:255',
            'body'         => 'required|string|max:10000',
            'thread_id'    => 'nullable|exists:message_threads,id',
        ]);

        $schoolId = $this->getSchoolId($request);
        $sender = $request->user();
        $senderId = $sender->id;
        $senderRole = $sender->userProfile?->role;

        if (!empty($validated['thread_id'])) {
            $thread = MessageThread::where('school_id', $schoolId)->findOrFail($validated['thread_id']);

            if ((int) $thread->teacher_id !== (int) $senderId && (int) $thread->parent_id !== (int) $senderId) {
                return response()->json([
                    'error' => 'This conversation does not belong to you.',
                ], 403);
            }
        } else {
            $recipient = User::with('userProfile')->find($validated['recipient_id']);
            $recipientRole = $recipient?->userProfile?->role;

            if (! $recipient || (int) $recipient->userProfile?->school_id !== (int) $schoolId) {
                return response()->json(['error' => 'That recipient is not a member of this school.'], 422);
            }

            // A thread has one teacher side and one parent side. Anything else
            // (parent↔parent, teacher↔teacher) is not what this feature is.
            $sides = [$senderRole => $senderId, $recipientRole => $recipient->id];

            $teacherId = $sides['teacher'] ?? ($sides['school_admin'] ?? null) ?? ($sides['super_admin'] ?? null);
            $parentId = $sides['parent'] ?? null;

            if (! $teacherId || ! $parentId) {
                return response()->json([
                    'error' => 'A conversation must be between a parent and a member of staff.',
                ], 422);
            }

            $thread = MessageThread::create([
                'school_id'       => $schoolId,
                'teacher_id'      => $teacherId,
                'parent_id'       => $parentId,
                'student_id'      => $validated['student_id'] ?? null,
                'subject'         => $validated['subject'] ?? 'Direct Message',
                'last_message_at' => now(),
            ]);
        }

        $message = Message::create([
            'thread_id' => $thread->id,
            'sender_id' => $senderId,
            'body'      => $validated['body'],
        ]);

        $thread->update(['last_message_at' => now()]);

        return response()->json([
            'message' => 'Message sent successfully',
            'data'    => $message->load('sender')
        ], 201);
    }

    /**
     * Read a thread, and mark the other side's messages as seen.
     *
     * Without read state a parent cannot tell whether the teacher has seen the
     * message about their child's asthma inhaler, which is exactly the thing
     * this feature is for.
     */
    public function showThread(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $userId = $request->user()->id;

        $thread = MessageThread::where('school_id', $schoolId)->findOrFail($id);

        if ((int) $thread->teacher_id !== (int) $userId && (int) $thread->parent_id !== (int) $userId) {
            return response()->json(['error' => 'This conversation does not belong to you.'], 403);
        }

        Message::where('thread_id', $thread->id)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        /*
         * The most recent page, not the whole conversation (gap G10).
         *
         * `->load('messages.sender')` pulled every message ever exchanged about
         * a child — a form teacher and a parent over three years — on every
         * open of the thread, over a metered connection, to render the last
         * dozen. Paged backwards with `before_id` because a chat is read from
         * the bottom: the newest page is the one that is always wanted, and
         * older ones are fetched only when someone scrolls up.
         */
        $perPage = min((int) $request->input('per_page', 50), 100);

        $window = Message::where('thread_id', $thread->id)
            ->when(
                $request->filled('before_id'),
                fn ($q) => $q->where('id', '<', (int) $request->input('before_id'))
            )
            ->with('sender:id,name')
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        // One row over the page size answers "is there more" without a second
        // count query over the same rows.
        $hasMore = $window->count() > $perPage;
        $messages = $window->take($perPage)->reverse()->values();

        return response()->json([
            // Participants and subject, without the messages hanging off them.
            'thread' => $thread->load(['teacher:id,name', 'parent:id,name', 'student']),
            /*
             * Top level, and oldest-first.
             *
             * It used to be nested under `thread`, where the mobile client's
             * `listOf(data, ['messages'])` could not see it — the thread view
             * has been rendering "No messages yet" over a full conversation.
             * Ascending because that is the order a chat is drawn in; leaving
             * the client to reverse it is one more thing for three clients to
             * disagree about.
             */
            'messages' => $messages,
            'messages_meta' => [
                'per_page' => $perPage,
                'has_more' => $hasMore,
                // Pass back as `before_id` to fetch the page above this one.
                'next_before_id' => $hasMore ? $messages->first()?->id : null,
            ],
        ]);
    }
}
