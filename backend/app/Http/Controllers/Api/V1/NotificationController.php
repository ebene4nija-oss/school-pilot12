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
    /** Who a broadcast can be addressed to without knowing anyone's user id. */
    private const AUDIENCES = ['parents', 'students', 'teachers', 'staff'];

    /**
     * One message's ceiling. A whole-school send to every family is two or three
     * thousand SMS on the school's own SIM, which is both expensive and the
     * surest way to get that SIM flagged by the network. One class at a time.
     */
    private const MAX_RECIPIENTS = 3000;

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
        $schoolId = $this->schoolId();

        /*
         * An audience, or explicit user ids.
         *
         * The form used to ask a school admin to type recipient user ids —
         * "14, 22, 37" — which no school admin knows. Nobody could reach "all
         * JSS 2 parents" without a database session, so in practice nothing
         * was sent. `user_ids` stays for callers that already hold ids (the
         * mobile app, the defaulter list).
         */
        $validated = $request->validate([
            'user_ids' => 'required_without:audience|array|min:1|max:1000',
            'user_ids.*' => 'integer',
            'audience' => 'required_without:user_ids|array',
            'audience.type' => 'required_with:audience|in:'.implode(',', self::AUDIENCES),
            'audience.class_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'audience.arm_id' => 'nullable|integer',
            'body' => 'required|string|max:1000',
            'title' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:64',
            'channels' => 'required|array|min:1',
            // Email was offered on the page and refused here, so ticking it
            // failed the whole send with a validation error.
            'channels.*' => 'in:push,sms,whatsapp,email',
        ]);

        $recipients = isset($validated['audience'])
            ? $this->audience($validated['audience'], $schoolId)->with('userProfile')->get()
            : User::whereIn('id', $validated['user_ids'])
                ->whereHas('userProfile', fn ($q) => $q->where('school_id', $schoolId))
                ->with('userProfile')
                ->get();

        if ($recipients->isEmpty()) {
            return response()->json([
                'error' => isset($validated['audience'])
                    ? 'Nobody matches that audience yet.'
                    : 'None of those recipients belong to this school.',
            ], 422);
        }

        if ($recipients->count() > self::MAX_RECIPIENTS) {
            return response()->json([
                'error' => 'That audience has '.$recipients->count().' people. Send to one class at a time — at most '.self::MAX_RECIPIENTS.' per message.',
            ], 422);
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
    /**
     * How many people an audience reaches, and on which channels.
     *
     * Asked before sending so the admin sees "342 parents — 298 have a phone,
     * 121 have an email" instead of discovering the gap in the delivery log.
     */
    public function audiencePreview(Request $request)
    {
        $schoolId = $this->schoolId();

        $validated = $request->validate([
            'audience' => 'required|array',
            'audience.type' => 'required|in:'.implode(',', self::AUDIENCES),
            'audience.class_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'audience.arm_id' => 'nullable|integer',
        ]);

        $users = $this->audience($validated['audience'], $schoolId)
            ->with('userProfile:id,user_id,phone')
            ->get(['id', 'email']);

        return response()->json([
            'recipients' => $users->count(),
            'with_phone' => $users->filter(fn (User $u) => filled($u->userProfile?->phone))->count(),
            'with_email' => $users->filter(fn (User $u) => filled($u->email))->count(),
            'too_many' => $users->count() > self::MAX_RECIPIENTS,
            'max_recipients' => self::MAX_RECIPIENTS,
        ]);
    }

    /**
     * Which channels can deliver for this school right now, and why not.
     *
     * "Notifications are not working" was, underneath, four separate answers
     * nobody could see: no SMS account, no mail server, no push credentials,
     * and a form that rejected email. This names each one.
     */
    public function channels(\App\Services\SmsService $sms, FcmService $fcm, WhatsAppService $whatsapp)
    {
        $schoolId = $this->schoolId();
        $school = $schoolId ? \App\Models\School::find($schoolId) : null;

        $smsState = $sms->describe($schoolId);

        $mailer = config('mail.default');
        $host = config('mail.mailers.smtp.host');
        $mailReady = $mailer === 'smtp' && filled($host);

        $limit = $school ? NotificationService::emailDailyLimit($school) : null;
        $sentToday = $schoolId ? NotificationService::emailsSentToday($schoolId) : 0;

        return response()->json([
            'push' => [
                'ready' => $fcm->isConfigured(),
                'reason' => $fcm->isConfigured() ? null : 'Push credentials are not set up on the platform yet. Contact SchoolPilot.',
            ],
            'sms' => [
                'ready' => $smsState['configured'],
                'source' => $smsState['source'],
                'provider' => $smsState['provider'],
                'reason' => $smsState['configured'] ? null : 'Add your SMS account under SMS settings on this page.',
            ],
            'whatsapp' => [
                'ready' => $whatsapp->isConfigured(),
                'reason' => $whatsapp->isConfigured() ? null : 'WhatsApp is not set up on the platform yet. Contact SchoolPilot.',
            ],
            'email' => [
                'ready' => $mailReady && (bool) ($school?->email_enabled ?? true),
                'daily_limit' => $limit,
                'sent_today' => $sentToday,
                'remaining_today' => $limit === null ? null : max($limit - $sentToday, 0),
                'reason' => match (true) {
                    ! $mailReady => 'Email is not set up on the platform yet. Contact SchoolPilot.',
                    $school && ! $school->email_enabled => 'Email is switched off for this school by SchoolPilot.',
                    $limit !== null && $sentToday >= $limit => "Today's limit of {$limit} emails has been reached.",
                    default => null,
                },
            ],
        ]);
    }

    /**
     * The users an audience names, within this school.
     *
     * Parents are reached through the students they are guardians of, so
     * "JSS 2 parents" means the guardians of active JSS 2 students — not
     * everyone with a parent account, which would include families who have
     * left.
     */
    private function audience(array $audience, int $schoolId): \Illuminate\Database\Eloquent\Builder
    {
        $students = \Illuminate\Support\Facades\DB::table('students')
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->whereNull('deleted_at');

        if (! empty($audience['class_id'])) {
            $students->where('class_id', $audience['class_id']);
        }

        if (! empty($audience['arm_id'])) {
            $students->where('arm_id', $audience['arm_id']);
        }

        return match ($audience['type']) {
            'parents' => User::query()->whereIn('id', \Illuminate\Support\Facades\DB::table('guardians')
                ->where('guardians.school_id', $schoolId)
                ->whereIn('guardians.id', \Illuminate\Support\Facades\DB::table('student_guardian')
                    ->whereIn('student_id', (clone $students)->select('id'))
                    ->select('guardian_id'))
                ->select('guardians.user_id')),
            'students' => User::query()->whereIn('id', (clone $students)->select('user_id')),
            'teachers' => User::query()->whereHas('userProfile', fn ($q) => $q
                ->where('school_id', $schoolId)
                ->where('role', 'teacher')),
            // Everyone who works at the school.
            'staff' => User::query()->whereHas('userProfile', fn ($q) => $q
                ->where('school_id', $schoolId)
                ->whereIn('role', ['school_admin', 'teacher'])),
        };
    }

    public function history(Request $request)
    {
        $schoolId = $this->schoolId();

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

        $schoolId = $this->schoolId();
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

        $schoolId = $this->schoolId();
        $student = Student::where('school_id', $schoolId)->with(['user'])->findOrFail($request->student_id);

        $recipientPhone = $request->phone;
        $recipientName = 'Parent';

        if (!$recipientPhone && $student->user && $student->user->userProfile) {
            $recipientPhone = $student->user->userProfile->phone;
            $recipientName = $student->user->name;
        }

        if (!$recipientPhone) {
            return response()->json([
                'message' => 'No phone number is on file for this recipient. Enter the parent\'s number to send this.',
            ], 422);
        }

        /*
         * The message states real figures, so the caller must supply them.
         * This used to fill any gap with invented demo values (a score of 85,
         * a fee balance of ₦30,000, homework called "Algebra Exercise 4") and
         * send those to a parent as fact.
         */
        $required = [
            'result' => ['term', 'total_score', 'average', 'verification_token'],
            'attendance' => ['date', 'status'],
            'fee_balance' => ['total_amount', 'amount_paid', 'balance', 'due_date'],
            'homework' => ['subject', 'title', 'due_date'],
            'timetable' => ['class_name', 'summary'],
        ][$request->type];

        $missing = array_values(array_filter($required, fn ($k) => ! filled($request->input("details.{$k}"))));

        if ($missing !== []) {
            return response()->json([
                'message' => 'Missing details for a '.$request->type.' message: '.implode(', ', $missing).'.',
            ], 422);
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
                    $details['term'],
                    (string) $details['total_score'],
                    (string) $details['average'],
                    $details['verification_token']
                );
                break;

            case 'attendance':
                $res = $whatsAppService->sendAttendanceNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['date'],
                    $details['status']
                );
                break;

            case 'fee_balance':
                $res = $whatsAppService->sendFeeBalanceNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    (string) $details['total_amount'],
                    (string) $details['amount_paid'],
                    (string) $details['balance'],
                    $details['due_date']
                );
                break;

            case 'homework':
                $res = $whatsAppService->sendHomeworkNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['subject'],
                    $details['title'],
                    $details['due_date']
                );
                break;

            case 'timetable':
                $res = $whatsAppService->sendTimetableNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['class_name'],
                    $details['summary']
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
