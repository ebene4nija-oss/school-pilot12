<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MarketingLead;
use App\Models\MarketingTouch;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The operator console's lead list (Landing PRD §12): what came in from the
 * marketing site, and where each school is in the sale.
 *
 * Super admins only. A lead's contact details are decrypted here and nowhere
 * else, and every change is written to the audit log.
 */
class PlatformLeadController extends Controller
{
    /** Newest first; hot leads first when asked. */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(MarketingLead::STATUSES)],
            'hot' => 'nullable|boolean',
        ]);

        $query = MarketingLead::query()->with(['owner:id,name', 'partner:id,name']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if ($request->boolean('hot')) {
            $query->where('score', '>=', MarketingLead::HOT_SCORE)->orderByDesc('score');
        }

        $leads = $query->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($leads->items())->map(fn (MarketingLead $lead) => $this->present($lead)),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
                'total' => $leads->total(),
            ],
            'counts' => MarketingLead::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]);
    }

    /** Move a lead along: status, owner, next action, notes. */
    public function update(Request $request, int $id)
    {
        $lead = MarketingLead::findOrFail($id);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(MarketingLead::STATUSES)],
            'owner_id' => 'sometimes|nullable|integer',
            'next_action_at' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string|max:5000',
            'lost_reason' => 'required_if:status,lost|nullable|string|max:255',
        ], [
            'lost_reason.required_if' => 'Say why this lead was lost.',
        ]);

        if (array_key_exists('owner_id', $validated) && $validated['owner_id'] !== null) {
            $isOperator = UserProfile::query()
                ->where('user_id', $validated['owner_id'])
                ->whereNull('school_id')
                ->where('role', 'super_admin')
                ->exists();

            if (! $isOperator) {
                return response()->json(['errors' => ['owner_id' => ['A lead can only be owned by a SchoolPilot operator.']]], 422);
            }
        }

        if (isset($validated['status']) && $validated['status'] !== $lead->status && $validated['status'] !== 'new') {
            $validated['last_contact_at'] = now();
        }

        $lead->update($validated);
        $this->audit($request, $lead, 'marketing_lead.updated', collect($validated)->except('notes')->all());

        return response()->json(['data' => $this->present($lead->fresh('owner'))]);
    }

    /**
     * Join a WhatsApp conversation to the web visit behind it: the rep pastes
     * the ref code from the chat, and the touch's attribution is copied onto
     * the lead where the lead has none of its own.
     */
    public function attachRef(Request $request, int $id)
    {
        $lead = MarketingLead::findOrFail($id);

        $validated = $request->validate([
            'ref_code' => ['required', 'string', 'regex:/^SP-[A-Z0-9]{4,8}$/'],
        ]);

        $touch = MarketingTouch::where('ref_code', $validated['ref_code'])->first();

        if (! $touch) {
            return response()->json(['errors' => ['ref_code' => ['No website visit has that ref code.']]], 422);
        }

        $fill = ['ref_code' => $touch->ref_code];

        foreach (['landing_page', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
            if (blank($lead->{$field}) && filled($touch->{$field})) {
                $fill[$field] = $touch->{$field};
            }
        }

        $fill['pages'] = array_values(array_unique(array_merge($lead->pages ?? [], $touch->pages ?? [])));

        $lead->update($fill);
        $this->audit($request, $lead, 'marketing_lead.ref_attached', ['ref_code' => $touch->ref_code]);

        return response()->json(['data' => $this->present($lead->fresh('owner'))]);
    }

    private function present(MarketingLead $lead): array
    {
        return [
            'id' => $lead->id,
            'intent' => $lead->intent,
            'full_name' => $lead->full_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'school_name' => $lead->school_name,
            'role' => $lead->role,
            'student_band' => $lead->student_band,
            'sections' => $lead->sections ?? [],
            'preferred_contact' => $lead->preferred_contact,
            'current_method' => $lead->current_method,
            'message' => $lead->message,
            'preferred_subdomain' => $lead->preferred_subdomain,
            'migrate_what' => $lead->migrate_what,
            'ref_code' => $lead->ref_code,
            'partner_id' => $lead->partner_id,
            'partner' => $lead->partner?->name,
            'landing_page' => $lead->landing_page,
            'utm_source' => $lead->utm_source,
            'utm_medium' => $lead->utm_medium,
            'utm_campaign' => $lead->utm_campaign,
            'pages_viewed' => count($lead->pages ?? []),
            'status' => $lead->status,
            'score' => $lead->score,
            'hot' => $lead->isHot(),
            'owner' => $lead->owner ? ['id' => $lead->owner->id, 'name' => $lead->owner->name] : null,
            'next_action_at' => $lead->next_action_at?->toIso8601String(),
            'notes' => $lead->notes,
            'lost_reason' => $lead->lost_reason,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }

    private function audit(Request $request, MarketingLead $lead, string $action, array $values): void
    {
        AuditLog::create([
            'school_id' => null,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => MarketingLead::class,
            'auditable_id' => $lead->id,
            'new_values' => $values,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
