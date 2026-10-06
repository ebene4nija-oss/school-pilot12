'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * Sections, heads, approval chains and assessment-scheme versions.
 *
 * The baseline decisions (D1–D3) live here: who heads each level, which steps
 * a class's results pass through, and which scheme applies from which term.
 * Editing is for the school admin (the owner); heads see it read-only and
 * propose schemes for their own level.
 */

interface Step { role: string; enabled: boolean; required: boolean; label: string; can_disable: boolean }
interface Head { id: number; position: string; level_group: string | null; level_label: string | null; department: string | null; name: string; current: boolean; started_at: string; ended_at: string | null }
interface Settings {
  secondary_heads_mode: 'single_principal' | 'separate_heads';
  result_workflow_enabled: boolean;
  approval_chains: Record<string, Step[]>;
  sections: Record<string, string>;
  level_groups: Record<string, string>;
  heads: Head[];
}
interface SchemeRow {
  id: number; name: string; version: number; level_label: string; status: string; is_versioned: boolean;
  components: { name: string; max: number; kind: string }[];
  effective_term: { name: string; session: string } | null;
  proposed_by: string | null; approved_by: string | null; decision_note: string | null;
}
interface Option { id: number; name: string }

const POSITION_LABEL: Record<string, string> = { principal: 'Principal', level_head: 'Section head', hod: 'Head of department' };

function isAdmin(): boolean {
  return typeof document !== 'undefined' && /(?:^|;\s*)sp_role=school_admin(?:;|$)/.test(document.cookie);
}

export default function SectionsPage() {
  const [admin] = useState(isAdmin);
  const [settings, setSettings] = useState<Settings | null>(null);
  const [schemes, setSchemes] = useState<{ data: SchemeRow[]; can_approve: boolean; can_propose_for: string[] | null } | null>(null);
  const [staff, setStaff] = useState<Option[]>([]);
  const [terms, setTerms] = useState<Option[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [head, setHead] = useState({ position: 'level_head', level_group: 'junior_secondary', department: '', user_id: '' });
  const [scheme, setScheme] = useState({ name: '', level_group: '', effective_term_id: '' });
  const [parts, setParts] = useState([
    { name: '1st CA', max: '20', kind: 'ca' },
    { name: '2nd CA', max: '20', kind: 'ca' },
    { name: 'Exam', max: '60', kind: 'exam' },
  ]);

  const load = useCallback(async () => {
    const [s, v, t] = await Promise.all([
      fetchApi<Settings>('/sections/settings'),
      fetchApi<{ data: SchemeRow[]; can_approve: boolean; can_propose_for: string[] | null }>('/assessment/scheme-versions'),
      fetchApi<{ data?: Option[] }>('/terms'),
    ]);
    setSettings(s);
    setSchemes(v);
    setTerms(t.data ?? []);
  }, []);

  useEffect(() => {
    (async () => {
      try {
        await load();
      } catch (err) {
        setError(errorText(err, 'Could not load section settings.'));
      }

      if (isAdmin()) {
        try {
          const r = await fetchApi<{ data?: { user_id?: number; id: number; name: string }[] }>('/staff/directory');
          setStaff((r.data ?? []).map((x) => ({ id: x.user_id ?? x.id, name: x.name })));
        } catch {
          // The heads form simply offers no names; nothing else depends on it.
        }
      }
    })();
  }, [load]);

  async function call(path: string, method: string, body: unknown, done: string) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message?: string }>(path, { method, body: JSON.stringify(body) });
      setNotice(res?.message ?? done);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not work.'));
    } finally {
      setBusy(false);
    }
  }

  if (!settings || !schemes) return <PageShell title="Sections & schemes"><ErrorNote message={error} /><Loading /></PageShell>;

  const total = parts.reduce((n, p) => n + Number(p.max || 0), 0);

  return (
    <PageShell title="Sections & assessment schemes" subtitle="Who heads each level, how results are approved, and which marking scheme applies from which term.">
      <ErrorNote message={error} />
      {notice && <p className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="Secondary heads" description="Option A: one principal over JSS and SS. Option B: separate JSS and SS heads. Switching changes who approves, and moves no data.">
          <div className="flex flex-wrap gap-2">
            {(['single_principal', 'separate_heads'] as const).map((mode) => (
              <Button key={mode} disabled={!admin || busy} variant={settings.secondary_heads_mode === mode ? 'primary' : 'secondary'}
                onClick={() => call('/sections/settings', 'PUT', { secondary_heads_mode: mode }, 'Saved.')}>
                {mode === 'single_principal' ? 'One principal' : 'Separate JSS and SS heads'}
              </Button>
            ))}
          </div>
          <label className="mt-4 flex items-center gap-2 text-sm">
            <input type="checkbox" disabled={!admin || busy} checked={settings.result_workflow_enabled}
              onChange={(e) => call('/sections/settings', 'PUT', { result_workflow_enabled: e.target.checked }, 'Saved.')} />
            Results must clear the approval chain before they can be released to parents
          </label>
        </Card>

        <Card title="Approval chains" description="Each step is a role, resolved per class. Required steps cannot be switched off.">
          {Object.entries(settings.approval_chains).map(([section, steps]) => (
            <div key={section} className="mb-4">
              <h3 className="mb-2 text-sm font-semibold">{settings.sections[section]}</h3>
              <ol className="flex flex-wrap gap-2">
                {steps.map((step, i) => (
                  <li key={step.role}>
                    <label className={`flex items-center gap-1 rounded-lg px-2 py-1 text-xs ring-1 ${step.enabled ? 'ring-brand/40' : 'ring-line-soft text-ink-faint'}`}>
                      <input type="checkbox" checked={step.enabled} disabled={!admin || busy || !step.can_disable}
                        onChange={(e) => {
                          const next = steps.map((s, j) => ({ role: s.role, enabled: j === i ? e.target.checked : s.enabled }));
                          call('/sections/settings', 'PUT', { approval_chains: { [section]: next } }, 'Chain saved.');
                        }} />
                      {i + 1}. {step.label}{!step.can_disable && ' (required)'}
                    </label>
                  </li>
                ))}
              </ol>
            </div>
          ))}
        </Card>
      </div>

      <Card className="mt-6" title="Heads" description="Assignments are kept when ended, so who approved what under which head stays on record.">
        {admin && (
          <div className="mb-4 grid gap-3 md:grid-cols-5">
            <Field label="Position">
              <select className={inputClass} value={head.position} onChange={(e) => setHead({ ...head, position: e.target.value })}>
                {Object.entries(POSITION_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </Field>
            {head.position !== 'principal' && (
              <Field label="Level">
                <select className={inputClass} value={head.level_group} onChange={(e) => setHead({ ...head, level_group: e.target.value })}>
                  {head.position === 'hod' && <option value="">All levels</option>}
                  {Object.entries(settings.level_groups).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              </Field>
            )}
            {head.position === 'hod' && (
              <Field label="Department">
                <input className={inputClass} value={head.department} onChange={(e) => setHead({ ...head, department: e.target.value })} placeholder="e.g. Sciences" />
              </Field>
            )}
            <Field label="Staff member">
              <select className={inputClass} value={head.user_id} onChange={(e) => setHead({ ...head, user_id: e.target.value })}>
                <option value="">Choose…</option>
                {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </Field>
            <div className="flex items-end">
              <Button disabled={busy || !head.user_id} onClick={() => call('/sections/heads', 'POST', {
                position: head.position,
                level_group: head.position === 'principal' ? null : head.level_group || null,
                department: head.position === 'hod' ? head.department : null,
                user_id: Number(head.user_id),
              }, 'Assigned.')}>Assign</Button>
            </div>
          </div>
        )}
        {settings.heads.length === 0 ? <Empty>No heads assigned. Until there are, the school admin acts for every head role.</Empty> : (
          <Table columns={['Position', 'Level', 'Department', 'Name', 'Status', '']}>
            {settings.heads.map((h) => (
              <tr key={h.id}>
                <Cell>{POSITION_LABEL[h.position]}</Cell>
                <Cell>{h.level_label ?? 'Whole school'}</Cell>
                <Cell>{h.department ?? '—'}</Cell>
                <Cell>{h.name}</Cell>
                <Cell>{h.current ? <Badge tone="green">Current</Badge> : <Badge>Ended {h.ended_at ? new Date(h.ended_at).toLocaleDateString('en-NG') : ''}</Badge>}</Cell>
                <Cell>{admin && h.current && <Button variant="secondary" disabled={busy} onClick={() => call(`/sections/heads/${h.id}/end`, 'POST', {}, 'Ended.')}>End</Button>}</Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card className="mt-6" title="Assessment schemes" description="A head proposes; the owner approves. A scheme applies from its term onward and never reaches a term that already has marks.">
        {(schemes.can_propose_for === null || (schemes.can_propose_for?.length ?? 0) > 0) && (
          <div className="mb-6 rounded-lg p-4 ring-1 ring-line-soft">
            <div className="grid gap-3 md:grid-cols-3">
              <Field label="Name"><input className={inputClass} value={scheme.name} onChange={(e) => setScheme({ ...scheme, name: e.target.value })} placeholder="e.g. 10/10/10/70" /></Field>
              <Field label="Section">
                <select className={inputClass} value={scheme.level_group} onChange={(e) => setScheme({ ...scheme, level_group: e.target.value })}>
                  {schemes.can_propose_for === null && <option value="">All sections</option>}
                  {Object.entries(settings.level_groups)
                    .filter(([k]) => schemes.can_propose_for === null || schemes.can_propose_for.includes(k))
                    .map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              </Field>
              <Field label="Applies from term">
                <select className={inputClass} value={scheme.effective_term_id} onChange={(e) => setScheme({ ...scheme, effective_term_id: e.target.value })}>
                  <option value="">Choose…</option>
                  {terms.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </Field>
            </div>
            <div className="mt-3 space-y-2">
              {parts.map((p, i) => (
                <div key={i} className="flex flex-wrap gap-2">
                  <input className={`${inputClass} max-w-[12rem]`} value={p.name} onChange={(e) => setParts(parts.map((x, j) => j === i ? { ...x, name: e.target.value } : x))} aria-label="Part name" />
                  <input className={`${inputClass} max-w-[6rem]`} type="number" min={1} max={100} value={p.max} onChange={(e) => setParts(parts.map((x, j) => j === i ? { ...x, max: e.target.value } : x))} aria-label="Marks" />
                  <select className={`${inputClass} max-w-[8rem]`} value={p.kind} onChange={(e) => setParts(parts.map((x, j) => j === i ? { ...x, kind: e.target.value } : x))} aria-label="Kind">
                    <option value="ca">CA</option><option value="exam">Exam</option>
                  </select>
                  <Button variant="secondary" disabled={parts.length <= 2} onClick={() => setParts(parts.filter((_, j) => j !== i))}>Remove</Button>
                </div>
              ))}
              <div className="flex flex-wrap items-center gap-3">
                <Button variant="secondary" disabled={parts.length >= 10} onClick={() => setParts([...parts, { name: `CA ${parts.length}`, max: '10', kind: 'ca' }])}>Add part</Button>
                <span className={`text-sm ${total === 100 ? 'text-positive' : 'text-negative'}`}>Total {total} {total === 100 ? '✓' : '(must be 100)'}</span>
                <Button variant="accent" disabled={busy || total !== 100 || !scheme.name || !scheme.effective_term_id} onClick={() => call('/assessment/scheme-versions', 'POST', {
                  name: scheme.name,
                  level_group: scheme.level_group || null,
                  effective_term_id: Number(scheme.effective_term_id),
                  components: parts.map((p) => ({ name: p.name, max: Number(p.max), kind: p.kind })),
                  propose: true,
                }, 'Proposed.')}>Propose</Button>
              </div>
            </div>
          </div>
        )}

        {schemes.data.length === 0 ? <Empty>No schemes yet. The school marks on the standard 20/20/60 until one is approved.</Empty> : (
          <Table columns={['Scheme', 'Section', 'Parts', 'From', 'Status', 'Proposed / decided', '']}>
            {schemes.data.map((s) => (
              <tr key={s.id}>
                <Cell>{s.name}{s.is_versioned && <span className="ml-1 text-xs text-ink-muted">v{s.version}</span>}</Cell>
                <Cell>{s.level_label}</Cell>
                <Cell>{s.components.map((c) => `${c.name} ${c.max}`).join(' · ')}</Cell>
                <Cell>{s.effective_term ? `${s.effective_term.name} ${s.effective_term.session ?? ''}` : '—'}</Cell>
                <Cell><Badge tone={s.status === 'active' ? 'green' : s.status === 'proposed' ? 'amber' : s.status === 'rejected' ? 'red' : 'slate'}>{s.status}</Badge></Cell>
                <Cell>{[s.proposed_by, s.approved_by].filter(Boolean).join(' → ')}{s.decision_note && <span className="block text-xs text-ink-muted">{s.decision_note}</span>}</Cell>
                <Cell>
                  {/* A saved draft goes forward for approval when its author is ready. */}
                  {s.status === 'draft' && (
                    <Button variant="secondary" disabled={busy} onClick={() => call(`/assessment/scheme-versions/${s.id}/propose`, 'POST', {}, 'Proposed for approval.')}>Propose</Button>
                  )}
                  {s.status === 'proposed' && schemes.can_approve && (
                    <div className="flex gap-2">
                      <Button disabled={busy} onClick={() => call(`/assessment/scheme-versions/${s.id}/approve`, 'POST', {}, 'Approved.')}>Approve</Button>
                      <Button variant="danger" disabled={busy} onClick={() => {
                        const note = window.prompt('Why is this being rejected?');
                        if (note) call(`/assessment/scheme-versions/${s.id}/reject`, 'POST', { note }, 'Rejected.');
                      }}>Reject</Button>
                    </div>
                  )}
                </Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </PageShell>
  );
}
