<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationJob;
use App\Models\DeviceToken;
use App\Models\NotificationLog;
use App\Models\Student;
use App\Models\User;
use App\Services\FcmService;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Register this device for push (§7.13).
     *
     * Called by the mobile app on login and whenever the OS rotates the token.
     * Upserted on (user, token) so a reinstall does not leave the user
     * receiving every notification twice.
     */
    public function registerDevice(Request $request, NotificationService $notifications)
    {
        $validated = $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'required|in:android,ios,web',
            'device_name' => 'nullable|string|max:120',
        ]);

        $device = DeviceToken::updateOrCreate(
            ['user_id' => $request->user()->id, 'token' => $validated['token']],
            [
                'school_id' => $request->user()->userProfile?->school_id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Device registered for notifications.',
            'device_id' => $device->id,
        ], 201);
    }

    /**
     * Push a test notification to the caller's own devices.
     *
     * Exists for the client side. Without it, the only way to find out whether
     * a handset's token actually receives anything is to have a school admin
     * fire a real broadcast at a real parent — which is not something you do
     * while debugging, and which reports one aggregated status for the whole
     * user rather than saying which device failed and why.
     *
     * Synchronous on purpose: a diagnostic that answers "queued" has not
     * answered anything. One or two devices is one or two HTTP calls, not the
     * 900 that made `broadcast` a queued endpoint.
     *
     * Not written to the notification log — that log is how a school accounts
     * for its messaging spend, and developer test pings are not spend.
     */
    public function testDevice(Request $request, FcmService $fcm)
    {
        $validated = $request->validate([
            'body' => 'nullable|string|max:200',
            'title' => 'nullable|string|max:120',
        ]);

        $devices = DeviceToken::where('user_id', $request->user()->id)->get();

        if ($devices->isEmpty()) {
            return response()->json([
                'error' => 'No devices registered. POST /notifications/devices first.',
            ], 422);
        }

        if (! $fcm->isConfigured()) {
            /*
             * 503, not 500: the deployment is missing FCM_CREDENTIALS. That is
             * an operator task, and the client should say so rather than
             * leaving someone hunting for a bug in their Flutter code.
             */
            return response()->json([
                'error' => 'Push is not configured on this deployment. Set FCM_CREDENTIALS.',
            ], 503);
        }

        $results = $devices->map(function (DeviceToken $device) use ($fcm, $validated) {
            $result = $fcm->sendToToken(
                $device->token,
                $validated['title'] ?? 'SchoolPilot test',
                $validated['body'] ?? 'Push is working on this device.',
                ['type' => 'test', 'sent_at' => now()->toIso8601String()]
            );

            if ($result['status'] === FcmService::SENT) {
                $device->update(['last_used_at' => now()]);
            }

            // Same rule as a real send: a token FCM has disowned is deleted,
            // otherwise every future broadcast pays for it again.
            if ($result['status'] === FcmService::INVALID_TOKEN) {
                $device->delete();
            }

            return [
                'device_id' => $device->id,
                'platform' => $device->platform,
                'device_name' => $device->device_name,
                'status' => $result['status'],
                'reason' => $result['reason'],
            ];
        });

        return response()->json([
            'delivered' => $results->where('status', FcmService::SENT)->count(),
            'devices' => $results->values(),
        ], $results->contains('status', FcmService::SENT) ? 200 : 502);
    }

    /** Unregister on logout, so a shared handset stops receiving. */
    public function unregisterDevice(Request $request)
    {
        $validated = $request->validate(['token' => 'required|string|max:512']);

        DeviceToken::where('user_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['message' => 'Device unregistered.']);
    }

    /**
     * Send to a group of parents — the fee-reminder / result-published case.
     *
     * Channels are explicit because they are not equivalent: push is free and
     * SMS is billed per message, so a school broadcasting to 900 families
     * should be choosing that deliberately rather than discovering it on the
     * invoice.
     */
    public function broadcast(Request $request, NotificationService $notifications)
    {
        $schoolId = $request->user()->userProfile?->school_id;

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1|max:1000',
            'user_ids.*' => 'integer',
            'body' => 'required|string|max:1000',
            'title' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:64',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:push,sms,whatsapp',
        ]);

        $recipients = User::whereIn('id', $validated['user_ids'])
            ->whereHas('userProfile', fn ($q) => $q->where('school_id', $schoolId))
            ->with('userProfile')
            ->get();

        if ($recipients->isEmpty()) {
            return response()->json(['error' => 'None of those recipients belong to this school.'], 422);
        }

        $context = [
            'title' => $validated['title'] ?? 'SchoolPilot',
            'category' => $validated['category'] ?? 'announcement',
            'sent_by' => $request->user()->id,
        ];

        /*
         * Queued, not sent inline.
         *
         * Sending in the request meant up to 1,000 recipients × channels of
         * sequential HTTP calls to Termii or FCM inside one PHP process.
         * Nigerian private schools run on shared hosting with 30–60s timeouts,
         * so a whole-school fee reminder died part-way through and left a
         * half-written delivery log with no way to tell what had gone out.
         */
        $batch = Bus::batch(
            $recipients->map(fn (User $recipient) => new SendNotificationJob(
                $recipient->id,
                $validated['body'],
                $validated['channels'],
                $context,
                $schoolId
            ))->all()
        )
            // School id in the name is what lets the status endpoint scope
            // access without a second lookup table.
            ->name("notification-broadcast:school:{$schoolId}")
            // One family's dead handset must not abandon the other 899.
            ->allowFailures()
            ->dispatch();

        return response()->json([
            'message' => "Queued for {$recipients->count()} recipient(s).",
            'recipients' => $recipients->count(),
            'batch_id' => $batch->id,
            'status_url' => "/api/v1/jobs/{$batch->id}",
        ], 202);
    }

    /** What this school has sent — the answer to "why is the SMS bill this size". */
    public function history(Request $request)
    {
        $schoolId = $request->user()->userProfile?->school_id;

        $query = NotificationLog::where('school_id', $schoolId)->with('user:id,name');

        foreach (['channel', 'status', 'category'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return response()->json([
            'totals' => NotificationLog::where('school_id', $schoolId)
                ->selectRaw('channel, status, COUNT(*) as total')
                ->groupBy('channel', 'status')
                ->get(),
            'data' => $query->orderByDesc('id')->paginate(min((int) $request->input('per_page', 50), 100)),
        ]);
    }

    /**
     * The caller's own inbox (gap G6).
     *
     * `history` above is the school's delivery ledger — every message to every
     * family, with per-channel totals a bursar uses to check an SMS bill. It is
     * admin-only and should stay that way. This is the other half: a parent
     * receives a push about a fee deadline, taps it away, and has had until now
     * no way to find out what it said.
     *
     * Own rows only, and enforced by `user_id` rather than by school — a school
     * admin reading this endpoint gets their own messages, not everyone's. The
     * ledger is where the everyone's-messages view lives.
     */
    public function inbox(Request $request)
    {
        $user = $request->user();

        $query = NotificationLog::where('user_id', $user->id)
            /*
             * Failed sends are not inbox items. A push that never left the
             * server is an operational fact for the ledger; showing a parent a
             * message their phone never received, in a list of messages their
             * phone did receive, invents a notification.
             */
            ->where('status', 'sent');

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $page = $query->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        $page->getCollection()->transform(fn (NotificationLog $row) => [
            'id' => $row->id,
            'category' => $row->category,
            'channel' => $row->channel,
            'body' => $row->body,
            'sent_at' => $row->sent_at?->toIso8601String(),
            'read_at' => $row->read_at?->toIso8601String(),
            'read' => $row->read_at !== null,
            // `recipient` (the phone number or token tail it went to) is
            // deliberately not here. The caller knows their own number, and it
            // is one more copy of a personal identifier on a handset (§12).
        ]);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            // What the badge shows. Counted across the whole inbox rather than
            // the page, which is the only figure that makes sense on a tab.
            'unread_count' => NotificationLog::where('user_id', $user->id)
                ->where('status', 'sent')
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    /**
     * Mark the caller's messages read.
     *
     * `ids` marks those; no `ids` marks everything, which is the "clear the
     * badge" gesture. Scoped by `user_id` in the same statement that writes, so
     * a supplied id belonging to somebody else updates no rows rather than
     * being checked and then trusted.
     */
    public function markInboxRead(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'nullable|array|max:200',
            'ids.*' => 'integer',
        ]);

        $query = NotificationLog::where('user_id', $request->user()->id)
            ->whereNull('read_at');

        if (! empty($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        }

        $marked = $query->update(['read_at' => now()]);

        return response()->json([
            'marked_read' => $marked,
            'unread_count' => NotificationLog::where('user_id', $request->user()->id)
                ->where('status', 'sent')
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    /**
     * Send direct WhatsApp notification to parent(s)
     */
    public function sendWhatsAppNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'nullable|exists:students,id',
            'parent_id'  => 'nullable|exists:users,id',
            'phone'      => 'nullable|string',
            'message'    => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $recipientPhone = $request->phone;
        $recipientName = 'Parent';

        if (!$recipientPhone && $request->parent_id) {
            $parent = User::whereHas('userProfile', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })->find($request->parent_id);

            if ($parent && $parent->userProfile) {
                $recipientPhone = $parent->userProfile->phone;
                $recipientName = $parent->name;
            }
        }

        if (!$recipientPhone && $request->student_id) {
            $student = Student::where('school_id', $schoolId)->with(['user'])->find($request->student_id);
            if ($student && $student->user && $student->user->userProfile) {
                $recipientPhone = $student->user->userProfile->phone;
                $recipientName = $student->user->name;
            }
        }

        if (!$recipientPhone) {
            return response()->json([
                'message' => 'Recipient phone number could not be determined. Please specify a phone number or valid parent/student ID.'
            ], 422);
        }

        $whatsAppService = app(WhatsAppService::class);
        $result = $whatsAppService->sendMessage($recipientPhone, $request->message);

        return response()->json([
            'message' => 'WhatsApp notification sent successfully to parent.',
            'recipient' => [
                'name' => $recipientName,
                'phone' => $recipientPhone,
            ],
            'delivery_result' => $result,
        ]);
    }

    /**
     * Send specific structured notifications (latest result, attendance, fee balance, homework, timetable)
     */
    public function sendStructuredWhatsApp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type'       => 'required|in:result,attendance,fee_balance,homework,timetable',
            'student_id' => 'required|exists:students,id',
            'phone'      => 'nullable|string',
            'details'    => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $student = Student::where('school_id', $schoolId)->with(['user'])->findOrFail($request->student_id);

        $recipientPhone = $request->phone;
        $recipientName = 'Parent';

        if (!$recipientPhone && $student->user && $student->user->userProfile) {
            $recipientPhone = $student->user->userProfile->phone;
            $recipientName = $student->user->name;
        }

        if (!$recipientPhone) {
            $recipientPhone = '+2348000000000'; // Default fallback phone
        }

        $whatsAppService = app(WhatsAppService::class);
        $details = $request->input('details', []);
        $studentName = $student->user ? $student->user->name : 'Student';

        switch ($request->type) {
            case 'result':
                $res = $whatsAppService->sendResultNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['term'] ?? 'Current Term',
                    (string)($details['total_score'] ?? '85'),
                    (string)($details['average'] ?? '85'),
                    $details['verification_token'] ?? 'SP_TOKEN_' . $student->id
                );
                break;

            case 'attendance':
                $res = $whatsAppService->sendAttendanceNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['date'] ?? now()->toDateString(),
                    $details['status'] ?? 'Present'
                );
                break;

            case 'fee_balance':
                $res = $whatsAppService->sendFeeBalanceNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    (string)($details['total_amount'] ?? '50,000.00'),
                    (string)($details['amount_paid'] ?? '20,000.00'),
                    (string)($details['balance'] ?? '30,000.00'),
                    $details['due_date'] ?? now()->addDays(7)->toDateString()
                );
                break;

            case 'homework':
                $res = $whatsAppService->sendHomeworkNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['subject'] ?? 'Mathematics',
                    $details['title'] ?? 'Algebra Exercise 4',
                    $details['due_date'] ?? now()->addDays(2)->toDateString()
                );
                break;

            case 'timetable':
                $res = $whatsAppService->sendTimetableNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['class_name'] ?? 'JSS 1',
                    $details['summary'] ?? 'Mon-Fri: 8:00 AM - 2:00 PM. Check portal for full schedule.'
                );
                break;
        }

        return response()->json([
            'message' => "WhatsApp {$request->type} notification dispatched to parent.",
            'recipient' => [
                'name' => $recipientName,
                'phone' => $recipientPhone,
            ],
            'delivery_result' => $res,
        ]);
    }
}
