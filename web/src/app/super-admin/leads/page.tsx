'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * Leads from the marketing site (Landing PRD §12): schools that asked for a
 * demo, asked to start, or sent a message.
 *
 * Hot leads (score 8+) are the ones to answer first: a proprietor of a
 * larger school who looked at pricing. The WhatsApp ref code box joins a chat
 * that started with "(Ref: SP-…)" to the web visit behind it.
 */

type Status =
  | 'new' | 'contacted' | 'qualified' | 'demo_scheduled' | 'demo_done'
  | 'onboarding' | 'live' | 'nurture' | 'lost';

interface Lead {
  id: number;
  intent: 'demo' | 'start' | 'contact';
  full_name: string;
  phone: string;
  email: string | null;
  school_name: string;
  role: string;
  student_band: string | null;
  sections: string[];
  preferred_contact: string;
  current_method: string | null;
  message: string | null;
  preferred_subdomain: string | null;
  migrate_what: string | null;
  ref_code: string | null;
  landing_page: string | null;
  utm_source: string | null;
  utm_campaign: string | null;
  pages_viewed: number;
  status: Status;
  score: number;
  hot: boolean;
  next_action_at: string | null;
  notes: string | null;
  lost_reason: string | null;
  created_at: string | null;
}

const STATUS_LABEL: Record<Status, string> = {
  new: 'New',
  contacted: 'Contacted',
  qualified: 'Qualified',
  demo_scheduled: 'Demo scheduled',
  demo_done: 'Demo done',
  onboarding: 'Onboarding',
  live: 'Live',
  nurture: 'Nurture',
  lost: 'Lost',
};

const INTENT_LABEL = { demo: 'Demo request', start: 'Wants to start', contact: 'Message' } as const;

const ROLE_LABEL: Record<string, string> = {
  proprietor: 'Proprietor / Director',
  principal: 'Principal / Head',
  admin: 'Administrator',
  bursar: 'Bursar',
  ict: 'ICT / Exams officer',
  teacher: 'Teacher',
  other: 'Other',
};

function when(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function whatsappLink(phone: string): string {
  return `https://wa.me/${phone.replace(/\D/g, '')}`;
}

export default function LeadsPage() {
  const [rows, setRows] = useState<Lead[]>([]);
  const [counts, setCounts] = useState<Partial<Record<Status, number>>>({});
  const [filter, setFilter] = useState<Status | 'hot' | ''>('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [drafts, setDrafts] = useState<Record<number, { notes: string; lost_reason: string; ref: string }>>({});

  const load = useCallback(async () => {
    const query = filter === 'hot' ? '?hot=1' : filter ? `?status=${filter}` : '';
    const res = await fetchApi<{ data: Lead[]; counts: Partial<Record<Status, number>> }>(`/platform/leads${query}`);
    setRows(res.data);
    setCounts(res.counts ?? {});
    setDrafts(Object.fromEntries(res.data.map((l) => [l.id, { notes: l.notes ?? '', lost_reason: l.lost_reason ?? '', ref: '' }])));
  }, [filter]);

  useEffect(() => {
    void (async () => {
      setLoading(true);
      try {
        await load();
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load leads.');
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function save(lead: Lead, status: Status) {
    const draft = drafts[lead.id];
    if (status === 'lost' && !draft?.lost_reason.trim()) {
      setError('Say why this lead was lost before marking it lost.');
      return;
    }

    setBusyId(lead.id);
    setError(null);
    setNotice(null);
    try {
      await fetchApi(`/platform/leads/${lead.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          status,
          notes: draft?.notes.trim() || null,
          lost_reason: status === 'lost' ? draft?.lost_reason.trim() : null,
        }),
      });
      setNotice(`${lead.school_name}: ${STATUS_LABEL[status]}.`);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That change could not be saved.');
    } finally {
      setBusyId(null);
    }
  }

  async function attachRef(lead: Lead) {
    const ref = drafts[lead.id]?.ref.trim().toUpperCase();
    if (!ref) return;
    setBusyId(lead.id);
    setError(null);
    setNotice(null);
    try {
      await fetchApi(`/platform/leads/${lead.id}/ref`, { method: 'POST', body: JSON.stringify({ ref_code: ref }) });
      setNotice(`Linked ${ref} to ${lead.school_name}.`);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That ref code could not be linked.');
    } finally {
      setBusyId(null);
    }
  }

  const setDraft = (id: number, patch: Partial<{ notes: string; lost_reason: string; ref: string }>) =>
    setDrafts((d) => ({ ...d, [id]: { ...{ notes: '', lost_reason: '', ref: '' }, ...d[id], ...patch } }));

  const filters: { value: Status | 'hot' | ''; label: string }[] = [
    { value: '', label: 'All' },
    { value: 'hot', label: 'Hot' },
    ...(Object.keys(STATUS_LABEL) as Status[]).map((s) => ({ value: s, label: `${STATUS_LABEL[s]}${counts[s] ? ` (${counts[s]})` : ''}` })),
  ];

  return (
    <PageShell title="Website leads" subtitle="Schools that contacted SchoolPilot through the marketing site. Answer hot leads first.">
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      <div className="mb-6 flex flex-wrap gap-2" role="group" aria-label="Filter leads">
        {filters.map((f) => (
          <button
            key={f.value || 'all'}
            type="button"
            onClick={() => setFilter(f.value)}
            aria-pressed={filter === f.value}
            className={`rounded-full border px-3 py-1.5 text-sm font-medium ${
              filter === f.value ? 'border-brand bg-brand text-white' : 'border-line bg-surface text-brand'
            }`}
          >
            {f.label}
          </button>
        ))}
      </div>

      {loading ? (
        <Loading />
      ) : rows.length === 0 ? (
        <Empty>No leads here yet. Requests from the website&apos;s demo, start and contact forms appear in this list.</Empty>
      ) : (
        <div className="space-y-4">
          {rows.map((lead) => {
            const draft = drafts[lead.id] ?? { notes: '', lost_reason: '', ref: '' };
            return (
              <Card key={lead.id} className={lead.hot && lead.status === 'new' ? 'ring-2 ring-accent/60' : ''}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h2 className="font-headline text-base font-semibold text-brand">{lead.school_name}</h2>
                    <p className="text-sm text-ink">
                      {lead.full_name} · {ROLE_LABEL[lead.role] ?? lead.role}
                      {lead.student_band ? ` · ${lead.student_band} students` : ''}
                      {lead.sections.length > 0 ? ` · ${lead.sections.map((s) => s.toUpperCase()).join(', ')}` : ''}
                    </p>
                    <p className="mt-1 text-sm">
                      <a href={whatsappLink(lead.phone)} target="_blank" rel="noopener noreferrer" className="font-medium text-brand underline">
                        {lead.phone}
                      </a>
                      {lead.email && (
                        <>
                          {' · '}
                          <a href={`mailto:${lead.email}`} className="text-brand underline">{lead.email}</a>
                        </>
                      )}
                      <span className="text-ink-muted"> · prefers {lead.preferred_contact}</span>
                    </p>
                    <p className="mt-1 text-xs text-ink-faint">
                      {INTENT_LABEL[lead.intent]} on {when(lead.created_at)}
                      {lead.landing_page ? ` · landed on ${lead.landing_page}` : ''}
                      {lead.utm_source ? ` · via ${lead.utm_source}${lead.utm_campaign ? ` / ${lead.utm_campaign}` : ''}` : ''}
                      {` · ${lead.pages_viewed} page(s) viewed`}
                      {lead.ref_code ? ` · ref ${lead.ref_code}` : ''}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    {lead.hot && <Badge tone="amber">Hot · {lead.score}</Badge>}
                    {!lead.hot && <Badge tone="slate">Score {lead.score}</Badge>}
                    <Badge tone={lead.status === 'lost' ? 'red' : lead.status === 'live' ? 'green' : 'blue'}>{STATUS_LABEL[lead.status]}</Badge>
                  </div>
                </div>

                {(lead.message || lead.migrate_what || lead.preferred_subdomain || lead.current_method) && (
                  <div className="mt-3 space-y-1 rounded-lg bg-surface-sunken px-3 py-2 text-sm text-ink">
                    {lead.current_method && <p>Uses today: {lead.current_method}</p>}
                    {lead.preferred_subdomain && <p>Wants the address: {lead.preferred_subdomain}</p>}
                    {lead.migrate_what && <p className="whitespace-pre-wrap">To migrate: {lead.migrate_what}</p>}
                    {lead.message && <p className="whitespace-pre-wrap">“{lead.message}”</p>}
                  </div>
                )}

                <label className="mt-4 block">
                  <span className="mb-1 block text-xs font-medium text-ink-muted">Sales notes (operators only)</span>
                  <textarea rows={2} maxLength={5000} value={draft.notes} onChange={(e) => setDraft(lead.id, { notes: e.target.value })} className={inputClass} />
                </label>

                <div className="mt-3 grid gap-3 md:grid-cols-2">
                  <label className="block">
                    <span className="mb-1 block text-xs font-medium text-ink-muted">Lost reason (required to mark lost)</span>
                    <input value={draft.lost_reason} onChange={(e) => setDraft(lead.id, { lost_reason: e.target.value })} className={inputClass} placeholder="e.g. Signed with another portal" />
                  </label>
                  <div>
                    <span className="mb-1 block text-xs font-medium text-ink-muted">WhatsApp ref code from the chat</span>
                    <div className="flex gap-2">
                      <input value={draft.ref} onChange={(e) => setDraft(lead.id, { ref: e.target.value })} className={inputClass} placeholder="SP-7KQ2" />
                      <Button variant="secondary" disabled={busyId === lead.id || !draft.ref.trim()} onClick={() => attachRef(lead)}>
                        Link
                      </Button>
                    </div>
                  </div>
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <label className="text-xs font-medium text-ink-muted" htmlFor={`status-${lead.id}`}>
                    Move to
                  </label>
                  <select
                    id={`status-${lead.id}`}
                    value={lead.status}
                    disabled={busyId === lead.id}
                    onChange={(e) => save(lead, e.target.value as Status)}
                    className={`${inputClass} w-auto`}
                  >
                    {(Object.keys(STATUS_LABEL) as Status[]).map((s) => (
                      <option key={s} value={s}>{STATUS_LABEL[s]}</option>
                    ))}
                  </select>
                  <Button variant="secondary" disabled={busyId === lead.id} onClick={() => save(lead, lead.status)}>
                    Save notes
                  </Button>
                </div>
              </Card>
            );
          })}
        </div>
      )}
    </PageShell>
  );
}
