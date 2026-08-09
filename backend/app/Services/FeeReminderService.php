<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * Chasing the debtor list (§7.12).
 *
 * The defaulter dashboard could tell a bursar who had not paid and then left
 * them to do something about it by hand — the web UI had a "Send SMS Reminder"
 * button wired to nothing at all.
 *
 * Two decisions worth stating, because both cost money if made the other way:
 *
 *  - Reminders are grouped by recipient, not by invoice. A parent with three
 *    children owing gets one message listing all three, not three messages.
 *    SMS is billed per message and three texts in a row reads as harassment.
 *  - Channels are never defaulted. Push is free and SMS is not, so a bursar
 *    reminding 400 families chooses that explicitly rather than discovering it
 *    on the month's gateway invoice.
 */
class FeeReminderService
{
    /**
     * Queue a reminder to the guardians behind each outstanding invoice.
     *
     * @param  Collection<int,Invoice>  $invoices  Already tenant-scoped by the caller.
     * @param  array<int,string>  $channels
     */
    public function remind(
        Collection $invoices,
        array $channels,
        int $schoolId,
        User $sender,
        ?string $note = null
    ): array {
        $groups = $this->groupByRecipient($invoices, $schoolId);

        $unreachable = $groups['unreachable'];
        $recipients = $groups['recipients'];

        if ($recipients->isEmpty()) {
            return [
                'message' => 'Nobody could be reached — none of these students has a guardian or account on file.',
                'reminders_queued' => 0,
                'batch_id' => null,
                'unreachable' => $unreachable->values(),
            ];
        }

        $context = [
            'title' => 'Outstanding school fees',
            'category' => 'fee_reminder',
            'sent_by' => $sender->id,
        ];

        /*
         * Queued in a batch, for the same reason the announcement broadcast is:
         * a whole-school sweep is hundreds of sequential gateway calls, and
         * these schools run on shared hosting with 30–60s request timeouts.
         */
        $batch = Bus::batch(
            $recipients->map(fn (array $group) => new SendNotificationJob(
                $group['user_id'],
                $this->compose($group, $note),
                $channels,
                $context,
                $schoolId
            ))->values()->all()
        )
            ->name("fee-reminders:school:{$schoolId}")
            // One family's dead handset must not abandon the rest of the run.
            ->allowFailures()
            ->dispatch();

        return [
            'message' => sprintf(
                'Reminders queued for %d recipient(s) covering %d invoice(s).',
                $recipients->count(),
                $invoices->count()
            ),
            'reminders_queued' => $recipients->count(),
            'channels' => $channels,
            'batch_id' => $batch->id,
            'status_url' => "/api/v1/jobs/{$batch->id}",
            // The bursar has to chase these by phone; saying so is the point.
            'unreachable' => $unreachable->values(),
        ];
    }

    /**
     * Fold invoices into one entry per person we are going to message.
     *
     * Guardians of record are the target. Where a student has none on file the
     * student's own account is used — common for SS3 students, and better than
     * silently not chasing the debt.
     */
    private function groupByRecipient(Collection $invoices, int $schoolId): array
    {
        $recipients = collect();
        $unreachable = collect();

        foreach ($invoices as $invoice) {
            $student = $invoice->student;

            if (! $student) {
                continue;
            }

            $contacts = $this->contactsFor($student, $schoolId);

            if ($contacts->isEmpty()) {
                $unreachable->push([
                    'student_id' => $student->id,
                    'student_name' => $student->user?->name,
                    'admission_number' => $student->admission_number,
                    'balance' => $invoice->balance(),
                    'reason' => 'No guardian or account with a contact on file.',
                ]);

                continue;
            }

            $line = [
                'student_name' => $student->user?->name ?? 'your child',
                'balance' => $invoice->balance(),
                'total' => (float) $invoice->total_amount,
                'paid' => (float) $invoice->amount_paid,
                'due_date' => $invoice->due_date?->toDateString(),
                'term' => $invoice->term?->name,
            ];

            foreach ($contacts as $contact) {
                $existing = $recipients->get($contact->id, [
                    'user_id' => $contact->id,
                    'name' => $contact->name,
                    'children' => [],
                ]);

                $existing['children'][] = $line;
                $recipients->put($contact->id, $existing);
            }
        }

        return ['recipients' => $recipients, 'unreachable' => $unreachable];
    }

    /**
     * Who to message about this student.
     *
     * A contact with no phone number is still included: NotificationService
     * records that as a failed send with a reason, which is how the school finds
     * out its records are incomplete. Dropping them here would hide it.
     */
    private function contactsFor($student, int $schoolId): Collection
    {
        $guardians = Guardian::where('school_id', $schoolId)
            ->whereHas('students', fn ($q) => $q->where('students.id', $student->id))
            ->with('user')
            ->get()
            ->map(fn (Guardian $g) => $g->user)
            ->filter();

        if ($guardians->isNotEmpty()) {
            return $guardians;
        }

        return collect([$student->user])->filter();
    }

    /**
     * The message itself.
     *
     * Naira, plain wording, and the balance stated before the ask — a parent
     * reading this on a feature phone should get the number in the first line.
     */
    private function compose(array $group, ?string $note): string
    {
        $children = collect($group['children']);
        $total = $children->sum('balance');

        $lines = ["Dear {$group['name']},"];

        if ($children->count() === 1) {
            $child = $children->first();
            $lines[] = sprintf(
                'Outstanding school fees for %s: %s of %s%s.',
                $child['student_name'],
                $this->naira($child['balance']),
                $this->naira($child['total']),
                $child['term'] ? " for {$child['term']}" : ''
            );

            if ($child['due_date']) {
                $lines[] = "Payment was due on {$child['due_date']}.";
            }
        } else {
            $lines[] = 'Outstanding school fees:';

            foreach ($children as $child) {
                $lines[] = sprintf(
                    '- %s: %s%s',
                    $child['student_name'],
                    $this->naira($child['balance']),
                    $child['due_date'] ? " (due {$child['due_date']})" : ''
                );
            }

            $lines[] = 'Total outstanding: ' . $this->naira($total) . '.';
        }

        if ($note) {
            $lines[] = $note;
        }

        $lines[] = 'Kindly settle at the school office or through the parent portal. Thank you.';

        return implode("\n", $lines);
    }

    private function naira(float $amount): string
    {
        return '₦' . number_format($amount, 2);
    }
}
