'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';
import GradingBandsEditor, { type Band, bandProblem, cleanBands } from '@/components/GradingBandsEditor';

/**
 * Report-card design requests from schools.
 *
 * Open a request, read the brief, download the school's files, build the
 * template to docs/report-card-template-contract.md (see
 * docs/report-card-design-brief.md), and upload it. It lands as a draft for
 * that school; the school previews it and approves it. Nothing here makes a
 * design live.
 */

type Row = {
  id: number;
  school: string | null;
  status: string;
  submitted_at: string | null;
  files: number;
  handled_by: string | null;
  template_uploaded_at: string | null;
};

type Detail = {
  request: {
    id: number;
    school: { id: number; name: string; subdomain: string } | null;
    status: string;
    requirements: {
      contact_name: string;
      contact_phone: string;
      paper_size?: string;
      orientation?: string;
      sections: Record<string, boolean>;
      ca_components: { name: string; max: number }[];
      affective_traits?: string[];
      psychomotor_skills?: string[];
      trait_rating_scale?: string | null;
      colours?: string | null;
      motto?: string | null;
      notes?: string | null;
    };
    grading_scales: Record<string, Band[]> | null;
    submitted_by: string | null;
    submitted_at: string | null;
    handled_by: string | null;
    operator_notes: string | null;
    school_feedback: string | null;
    template: { id: number; name: string; version: number; status: string } | null;
    template_uploaded_at: string | null;
    files_purged_at: string | null;
    files: { id: number; kind: string; label: string | null; original_name: string; size: number }[];
  };
  sections: Record<string, string>;
  levels: Record<string, string>;
};

const STATUS: Record<string, { label: string; tone: 'amber' | 'blue' | 'green' | 'red' | 'slate' }> = {
  submitted: { label: 'New', tone: 'amber' },
  in_progress: { label: 'In progress', tone: 'blue' },
  template_ready: { label: 'With the school', tone: 'green' },
  changes_requested: { label: 'Changes asked', tone: 'red' },
  approved: { label: 'Approved', tone: 'green' },
  cancelled: { label: 'Cancelled', tone: 'slate' },
};

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
const kb = (n: number) => (n > 1_048_576 ? `${(n / 1_048_576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`);

export default function ReportCardRequestsPage() {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [filter, setFilter] = useState('open');
  const [selected, setSelected] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ data: Row[] }>(`/platform/report-card-requests${filter ? `?status=${filter}` : ''}`);
      setRows(res.data);
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load requests.'));
    }
  }, [filter]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  return (
    <PageShell title="Report card requests" subtitle="Schools waiting for a report card design. Build it, upload it, and the school approves it.">
      <ErrorNote message={error} />
      <div className="grid gap-6 xl:grid-cols-[24rem_1fr]">
        <Card title="Queue">
          <select value={filter} onChange={(e) => setFilter(e.target.value)} className={`${inputClass} mb-4`}>
            <option value="open">Open</option>
            <option value="approved">Approved</option>
            <option value="cancelled">Cancelled</option>
            <option value="">All</option>
          </select>
          {!rows ? (
            <Loading />
          ) : rows.length === 0 ? (
            <Empty>Nothing here.</Empty>
          ) : (
            <ul className="space-y-2">
              {rows.map((r) => (
                <li key={r.id}>
                  <button
                    onClick={() => setSelected(r.id)}
                    className={`w-full rounded-lg px-3 py-2.5 text-left transition ${selected === r.id ? 'bg-brand text-white' : 'bg-surface-sunken text-ink hover:bg-surface-raised'}`}
                  >
                    <span className="flex items-center justify-between gap-2">
                      <span className="text-sm font-semibold">{r.school ?? `School #${r.id}`}</span>
                      <Badge tone={STATUS[r.status]?.tone ?? 'slate'}>{STATUS[r.status]?.label ?? r.status}</Badge>
                    </span>
                    <span className={`block text-xs ${selected === r.id ? 'text-white/80' : 'text-ink-muted'}`}>
                      #{r.id} · {when(r.submitted_at)} · {r.files} file{r.files === 1 ? '' : 's'}
                      {r.handled_by ? ` · ${r.handled_by}` : ''}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {selected ? (
          <RequestDetail key={selected} id={selected} onChanged={load} />
        ) : (
          <Card>
            <Empty>Choose a request.</Empty>
          </Card>
        )}
      </div>
    </PageShell>
  );
}

function RequestDetail({ id, onChanged }: { id: number; onChanged: () => void }) {
  const [data, setData] = useState<Detail | null>(null);
  const [scales, setScales] = useState<Record<string, Band[]>>({});
  const [notes, setNotes] = useState('');
  const [bundle, setBundle] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [previewKey, setPreviewKey] = useState(0);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<Detail>(`/platform/report-card-requests/${id}`);
      setData(res);
      setScales(res.request.grading_scales ?? {});
      setNotes(res.request.operator_notes ?? '');
    } catch (err) {
      setError(errorText(err, 'Could not load that request.'));
    }
  }, [id]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const run = async (fn: () => Promise<{ message?: string } | unknown>) => {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = (await fn()) as { message?: string };
      setNotice(res?.message ?? 'Done.');
      await load();
      onChanged();
    } catch (err) {
      setError(errorText(err, 'That did not work.'));
    } finally {
      setBusy(false);
    }
  };

  if (!data) return error ? <ErrorNote message={error} /> : <Loading />;

  const r = data.request;
  const req = r.requirements;
  const open = ['submitted', 'in_progress', 'template_ready', 'changes_requested'].includes(r.status);
  const scaleProblem = Object.entries(scales).map(([l, b]) => (bandProblem(b) ? `${data.levels[l]}: ${bandProblem(b)}` : null)).find(Boolean);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="font-headline text-lg font-bold text-brand">{r.school?.name}</h2>
          <p className="text-xs text-ink-muted">
            Request #{r.id} · sent {when(r.submitted_at)} by {r.submitted_by ?? '—'} · contact {req.contact_name}, {req.contact_phone}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Badge tone={STATUS[r.status]?.tone ?? 'slate'}>{STATUS[r.status]?.label ?? r.status}</Badge>
          {['submitted', 'changes_requested'].includes(r.status) && (
            <Button disabled={busy} onClick={() => run(() => fetchApi(`/platform/report-card-requests/${id}/start`, { method: 'POST' }))}>
              I&rsquo;m working on this
            </Button>
          )}
        </div>
      </div>

      <ErrorNote message={error} />
      {notice && <p role="status" className="rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {r.school_feedback && r.status === 'changes_requested' && (
        <p className="rounded-lg bg-caution-soft px-4 py-3 text-sm text-caution"><strong>School asked for:</strong> {r.school_feedback}</p>
      )}

      <div className="grid gap-6 2xl:grid-cols-2">
        <Card title="The brief">
          <dl className="space-y-3 text-sm">
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Show on the card</dt>
              <dd className="mt-1 flex flex-wrap gap-1.5">
                {Object.entries(req.sections).filter(([, on]) => on).map(([k]) => (
                  <Badge key={k} tone="blue">{data.sections[k] ?? k}</Badge>
                ))}
              </dd>
            </div>
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Leave off</dt>
              <dd className="mt-1 text-ink-muted">{Object.entries(req.sections).filter(([, on]) => !on).map(([k]) => data.sections[k] ?? k).join(', ') || '—'}</dd>
            </div>
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Marks</dt>
              <dd className="mt-1">{req.ca_components.map((c) => `${c.name} ${c.max}`).join(' + ')} = 100</dd>
            </div>
            {!!req.affective_traits?.length && (
              <div>
                <dt className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Affective traits</dt>
                <dd className="mt-1">{req.affective_traits.join(', ')}</dd>
              </div>
            )}
            {!!req.psychomotor_skills?.length && (
              <div>
                <dt className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Psychomotor skills</dt>
                <dd className="mt-1">{req.psychomotor_skills.join(', ')}</dd>
              </div>
            )}
            {req.trait_rating_scale && (
              <div>
                <dt className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Trait rating</dt>
                <dd className="mt-1">{req.trait_rating_scale}</dd>
              </div>
            )}
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Paper</dt>
              <dd className="mt-1">{req.paper_size ?? 'A4'} {req.orientation ?? 'portrait'}{req.colours ? ` · colours: ${req.colours}` : ''}{req.motto ? ` · motto: “${req.motto}”` : ''}</dd>
            </div>
            {req.notes && (
              <div>
                <dt className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Notes</dt>
                <dd className="mt-1 whitespace-pre-line">{req.notes}</dd>
              </div>
            )}
          </dl>
        </Card>

        <Card title="Files" description={r.files_purged_at ? 'Deleted when the request closed.' : 'Download and work from these. They are deleted when the school approves or cancels.'}>
          {r.files.length === 0 ? (
            <Empty>No files.</Empty>
          ) : (
            <Table columns={['File', 'What', 'Size']}>
              {r.files.map((f) => (
                <tr key={f.id}>
                  <Cell>
                    <a href={`/api/backend/platform/report-card-requests/${id}/files/${f.id}`} className="text-brand hover:underline">{f.original_name}</a>
                  </Cell>
                  <Cell className="text-xs">{f.kind}{f.label ? ` · ${f.label}` : ''}</Cell>
                  <Cell className="text-xs">{kb(f.size)}</Cell>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>

      <Card title="Grading scales" description="What the school asked for. Correct them here if needed; they apply when the school approves.">
        {Object.keys(scales).length === 0 ? (
          <Empty>The school sent no grading scale; WAEC or its current scale stays.</Empty>
        ) : (
          <div className="space-y-6">
            {Object.entries(scales).map(([level, bands]) => (
              <div key={level}>
                <h3 className="mb-2 text-sm font-semibold text-ink">{data.levels[level] ?? level}</h3>
                <GradingBandsEditor bands={bands} disabled={!open} onChange={(b) => setScales({ ...scales, [level]: b })} />
              </div>
            ))}
            {open && (
              <Button
                variant="secondary"
                disabled={busy || !!scaleProblem}
                onClick={() => run(() => fetchApi(`/platform/report-card-requests/${id}/grading-scales`, {
                  method: 'PUT',
                  body: JSON.stringify({ grading_scales: Object.fromEntries(Object.entries(scales).map(([l, b]) => [l, cleanBands(b)])) }),
                }))}
              >
                Save scales
              </Button>
            )}
          </div>
        )}
      </Card>

      {open && (
        <Card title="Upload the template" description="The JSON bundle (engine schoolpilot-report-card/v1). It is checked and test-rendered on upload, then sent to the school to approve.">
          <div className="flex flex-wrap items-center gap-3">
            <input type="file" accept=".json,application/json" onChange={(e) => setBundle(e.target.files?.[0] ?? null)} className="text-xs file:mr-3 file:rounded file:border-0 file:bg-surface-raised file:px-3 file:py-1.5 file:text-ink" />
            <Button
              variant="accent"
              disabled={busy || !bundle}
              onClick={() => run(async () => {
                const form = new FormData();
                form.append('bundle', bundle as File);
                if (notes.trim()) form.append('note', notes.trim());
                const res = await uploadApi<{ message: string }>(`/platform/report-card-requests/${id}/template`, form);
                setBundle(null);
                setPreviewKey((k) => k + 1);
                return res;
              })}
            >
              Upload and send to school
            </Button>
          </div>
          <div className="mt-4">
            <Field label="Note to the school (shown with the design)">
              <textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} className={inputClass} />
            </Field>
            <Button className="mt-2" variant="secondary" disabled={busy} onClick={() => run(() => fetchApi(`/platform/report-card-requests/${id}/notes`, { method: 'PUT', body: JSON.stringify({ operator_notes: notes.trim() || null }) }))}>
              Save note
            </Button>
          </div>
        </Card>
      )}

      {r.template && (
        <Card title={`Uploaded design: ${r.template.name} v${r.template.version}`} description={`Status: ${r.template.status}. Sample data only.`}>
          <iframe
            key={previewKey}
            title="Template preview"
            src={`/api/backend/platform/report-card-requests/${id}/preview`}
            sandbox=""
            className="h-[70vh] w-full rounded-lg border border-line bg-white"
          />
        </Card>
      )}
    </div>
  );
}
