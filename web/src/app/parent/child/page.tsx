'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, StatTile, inputClass, nairaAmount } from '@/components/Page';
import ChildSwitcher, { useChildren } from '@/components/ChildSwitcher';

/**
 * One child, for their parent: attendance, released results, subjects,
 * conduct notes, badges, the academic record and its documents, the health
 * record the school holds, and the two requests a parent makes about the
 * record itself — correct a mistake, or move schools.
 *
 * Every figure is the parent's own child's, checked by the API; results
 * appear only once released (and unlocked, where the school sells PINs).
 * Opening the health record is logged.
 */

interface Dash { attendance_trend: { total_tracked_days: number; present_days: number; attendance_percentage: number }; grades_over_time: { term_id: number; term: string | null; subject: string | null; total: number | null; grade: string | null; comment: string | null }[]; fee_history: { outstanding_balance: number } }
interface Conduct { id: number; type: 'positive' | 'warning' | 'incident'; title: string; description: string; incident_date: string; recorder: { name: string } | null }
interface History { terms: { id: number; session: string; term: string; class: string; figures: { subjects: { subject: string; total: number | null; grade: string | null }[]; average: number | null; position: number | null; class_size: number | null } }[]; sessions: { session: string; session_average: number | null }[]; documents: { id: number; type: string; reference: string; issued_at: string }[] }
interface Medical { blood_group: string | null; allergies: string[]; medical_notes: string | null; emergency_contacts: { name?: string; phone?: string; relationship?: string }[] }

const TONE: Record<string, 'green' | 'amber' | 'red'> = { positive: 'green', warning: 'amber', incident: 'red' };

export default function ParentChild() {
  const { children, selected, select, error: childError } = useChildren();
  const [dash, setDash] = useState<Dash | null>(null);
  const [subjects, setSubjects] = useState<{ compulsory: { name?: string; subject_name?: string }[]; elective: { name?: string; subject_name?: string }[] } | null>(null);
  const [conduct, setConduct] = useState<Conduct[]>([]);
  const [badges, setBadges] = useState<{ points: number; current_streak: number; badges: string[] | null } | null>(null);
  const [history, setHistory] = useState<History | null>(null);
  const [medical, setMedical] = useState<Medical | null>(null);
  const [form, setForm] = useState<'error' | 'transfer' | null>(null);
  const [errorText_, setErrorText] = useState('');
  const [transfer, setTransfer] = useState({ other_school_name: '', requested_transfer_date: '', reason: '' });
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!selected) return;
    const id = selected.id;
    setMedical(null);
    try {
      const [d, s, c, g, h] = await Promise.all([
        fetchApi<Dash>(`/analytics/parent/${id}`),
        fetchApi<{ compulsory: { name?: string; subject_name?: string }[]; elective: { name?: string; subject_name?: string }[] }>(`/students/${id}/subjects`).catch(() => null),
        fetchApi<{ data: Conduct[] }>(`/students/${id}/behavior-reports?per_page=30`).catch(() => ({ data: [] })),
        fetchApi<{ data: { points: number; current_streak: number; badges: string[] | null } }>(`/gamification/profile/${id}`).catch(() => null),
        fetchApi<History>(`/parent/children/${id}/academic-history`).catch(() => null),
      ]);
      setDash(d); setSubjects(s); setConduct(c.data); setBadges(g?.data ?? null); setHistory(h);
    } catch (err) {
      setError(errorText(err, 'Could not load your child’s record.'));
    }
  }, [selected]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function send(path: string, body: unknown, done: string) {
    setBusy(true);
    setError(null);
    try {
      const r = await fetchApi<{ message?: string }>(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(r.message ?? done);
      setForm(null);
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
    } finally {
      setBusy(false);
    }
  }

  const byTerm = (dash?.grades_over_time ?? []).reduce<Record<string, Dash['grades_over_time']>>((acc, g) => { (acc[g.term ?? 'Term'] ??= []).push(g); return acc; }, {});
  const subjectName = (x: { name?: string; subject_name?: string }) => x.subject_name ?? x.name ?? '';

  return (
    <PageShell title="My child" subtitle="Attendance, results, conduct and records, all in one place.">
      <ErrorNote message={childError ?? error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {!children ? <Loading /> : children.length === 0 ? <Empty>No child is linked to your account yet. Ask the school office to link your child.</Empty> : (
        <>
          <ChildSwitcher items={children} selected={selected} onSelect={select} />
          {!dash ? <Loading /> : (
            <div className="space-y-5">
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Attendance (last 30 days marked)" value={`${dash.attendance_trend.attendance_percentage}%`} tone="green" hint={`${dash.attendance_trend.present_days} of ${dash.attendance_trend.total_tracked_days} days`} />
                <StatTile label="Fees outstanding" value={nairaAmount(dash.fee_history.outstanding_balance)} tone={dash.fee_history.outstanding_balance > 0 ? 'amber' : 'green'} hint={<Link href="/parent/fees" className="underline">Pay fees</Link>} />
                <StatTile label="Conduct notes" value={conduct.length} tone={conduct.some((c) => c.type !== 'positive') ? 'amber' : 'slate'} />
                <StatTile label="Points" value={badges?.points ?? 0} tone="blue" hint={badges?.badges?.length ? badges.badges.join(', ') : undefined} />
              </div>

              <div className="grid gap-5 lg:grid-cols-2">
                <Card title="Released results" description="Each subject’s total once the school releases the term.">
                  {Object.keys(byTerm).length === 0 ? <p className="text-sm text-ink-muted">Nothing released yet, or the term needs a result PIN. <Link href="/parent/results" className="text-brand underline">Results</Link></p> : Object.entries(byTerm).map(([term, rows]) => (
                    <div key={term} className="mb-3 text-sm">
                      <p className="font-semibold">{term}</p>
                      <ul className="grid gap-x-4 sm:grid-cols-2">{rows.map((r, i) => <li key={i}>{r.subject}: <span className="font-medium">{r.total ?? '—'}</span> {r.grade && <Badge>{r.grade}</Badge>}</li>)}</ul>
                    </div>
                  ))}
                </Card>

                <Card title="Conduct" description="Commendations and notes recorded by teachers.">
                  {conduct.length === 0 ? <p className="text-sm text-ink-muted">Nothing recorded.</p> : (
                    <ul className="space-y-3 text-sm">
                      {conduct.map((c) => <li key={c.id}><p><Badge tone={TONE[c.type]}>{c.type === 'positive' ? 'commendation' : c.type}</Badge> <span className="font-medium">{c.title}</span> <span className="text-xs text-ink-muted">{c.incident_date?.slice(0, 10)} · {c.recorder?.name}</span></p><p className="mt-1 whitespace-pre-wrap text-ink-muted">{c.description}</p></li>)}
                    </ul>
                  )}
                </Card>

                <Card title="Subjects">
                  {!subjects ? <p className="text-sm text-ink-muted">Not set yet.</p> : (
                    <p className="text-sm">{subjects.compulsory.map(subjectName).join(', ') || '—'}{subjects.elective.length > 0 && <><br /><span className="text-ink-muted">Electives: </span>{subjects.elective.map(subjectName).join(', ')}</>}</p>
                  )}
                </Card>

                <Card title="Academic record" description="Past terms the school has closed and released, and documents issued.">
                  {!history || history.terms.length === 0 ? <p className="text-sm text-ink-muted">No closed terms yet.</p> : (
                    <ul className="space-y-1 text-sm">
                      {history.terms.map((t) => <li key={t.id}>{t.session} · {t.term} · {t.class}: average <span className="font-medium">{t.figures.average ?? '—'}</span>{t.figures.position ? ` · ${t.figures.position} of ${t.figures.class_size}` : ''}</li>)}
                    </ul>
                  )}
                  {history?.documents && history.documents.length > 0 && (
                    <ul className="mt-3 space-y-1 text-sm">{history.documents.map((d) => <li key={d.id}><a className="text-brand underline" href={`/api/backend/parent/academic-documents/${d.id}/pdf`}>{d.type.replaceAll('_', ' ')} {d.reference}</a> <span className="text-xs text-ink-muted">{d.issued_at?.slice(0, 10)}</span></li>)}</ul>
                  )}
                </Card>

                <Card title="Health record" description="What the school holds about allergies and emergencies. Opening it is logged.">
                  {!medical ? (
                    <Button variant="secondary" onClick={() => fetchApi<{ medical: Medical }>(`/students/${selected?.id}/medical`).then((r) => setMedical(r.medical)).catch((err) => setError(errorText(err, 'Could not open it.')))}>Show health record</Button>
                  ) : (
                    <dl className="space-y-1 text-sm">
                      <div><dt className="inline font-medium">Blood group: </dt><dd className="inline">{medical.blood_group ?? 'not recorded'}</dd></div>
                      <div><dt className="inline font-medium">Allergies: </dt><dd className="inline">{medical.allergies.length ? medical.allergies.join(', ') : 'none recorded'}</dd></div>
                      {medical.medical_notes && <div><dt className="font-medium">Notes</dt><dd>{medical.medical_notes}</dd></div>}
                      <div><dt className="font-medium">Emergency contacts</dt><dd>{medical.emergency_contacts.length ? medical.emergency_contacts.map((c) => [c.name, c.relationship, c.phone].filter(Boolean).join(' · ')).join('; ') : 'none recorded'}</dd></div>
                      <p className="pt-2 text-xs text-ink-muted">Something wrong or out of date? Tell the school below.</p>
                    </dl>
                  )}
                </Card>

                <Card title="Something wrong, or moving schools?">
                  <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={() => setForm(form === 'error' ? null : 'error')}>Report a mistake in the record</Button>
                    <Button variant="secondary" onClick={() => setForm(form === 'transfer' ? null : 'transfer')}>Request a transfer</Button>
                  </div>
                  {form === 'error' && (
                    <form className="mt-3 space-y-2" onSubmit={(e) => { e.preventDefault(); void send(`/students/${selected?.id}/record-errors`, { description: errorText_ }, 'Sent to the school.'); setErrorText(''); }}>
                      <Field label="What is wrong (a name, date of birth, a result…)"><textarea className={`${inputClass} min-h-[5rem]`} required minLength={10} maxLength={2000} value={errorText_} onChange={(e) => setErrorText(e.target.value)} /></Field>
                      <Button type="submit" disabled={busy}>Send to the school</Button>
                    </form>
                  )}
                  {form === 'transfer' && (
                    <form className="mt-3 space-y-2" onSubmit={(e) => { e.preventDefault(); void send(`/parent/children/${selected?.id}/transfer-request`, { other_school_name: transfer.other_school_name || null, requested_transfer_date: transfer.requested_transfer_date || null, reason: transfer.reason || null }, 'Transfer requested. The school will be in touch.'); }}>
                      <Field label="New school (if known)"><input className={inputClass} maxLength={255} value={transfer.other_school_name} onChange={(e) => setTransfer({ ...transfer, other_school_name: e.target.value })} /></Field>
                      <Field label="From"><input className={inputClass} type="date" value={transfer.requested_transfer_date} onChange={(e) => setTransfer({ ...transfer, requested_transfer_date: e.target.value })} /></Field>
                      <Field label="Reason (optional)"><textarea className={inputClass} maxLength={1000} value={transfer.reason} onChange={(e) => setTransfer({ ...transfer, reason: e.target.value })} /></Field>
                      <Button type="submit" disabled={busy}>Request transfer</Button>
                    </form>
                  )}
                </Card>
              </div>
            </div>
          )}
        </>
      )}
    </PageShell>
  );
}
