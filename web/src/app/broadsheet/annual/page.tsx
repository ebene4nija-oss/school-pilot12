'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';
import { useCookieRole } from '@/components/Sidebar';

/**
 * The session view of a class (end of the third term): each student's
 * average across the year's terms, then promotion recommendations against
 * the school's rules. Recommendations only — every promotion is confirmed by
 * a member of staff. Admins also set how broadsheets are calculated here.
 */

type Tab = 'annual' | 'promotion' | 'settings';
interface Annual { class: { name: string }; terms: { id: number; name: string; provisional: boolean }[]; pass_mark: number; students: { id: number; name: string | null; admission_number: string | null; term_averages: Record<string, number | null>; session_average: number | null; terms_missing: number; failed_core_subjects: string[] | number }[] }
interface Promotion { rules: { min_average: number; max_failed_core: number }; note: string; students: (Annual['students'][number] & { recommendation: string; reasons: string[] })[] }
type Config = Record<string, unknown>;

const SETTINGS: [string, string, string[] | 'bool' | 'number'][] = [
  ['display_mode', 'What each cell shows', ['total', 'breakdown', 'grade', 'score_grade', 'detailed']],
  ['ranking_basis', 'Rank students by', ['average', 'total', 'none']],
  ['ranking_method', 'Ties', ['competition', 'dense']],
  ['ranking_scope', 'Who is ranked', ['all', 'complete_only']],
  // class: all of SS 2 together · arm: SS 2A among SS 2A · stream: all science students together
  ['ranking_group', 'Positions within', ['class', 'arm', 'stream']],
  ['absence_policy', 'A missed exam counts as', ['exclude', 'zero']],
  ['pass_mark', 'Pass mark', 'number'],
  ['show_gender', 'Show gender', 'bool'],
  ['show_attendance', 'Show attendance', 'bool'],
  ['show_remarks', 'Show remarks', 'bool'],
  ['numeric_averages', 'Averages as numbers', 'bool'],
];

export default function AnnualBroadsheet() {
  const admin = useCookieRole() === 'school_admin';
  const [tab, setTab] = useState<Tab>('annual');
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([]);
  const [sessions, setSessions] = useState<{ id: number; name: string; is_current: boolean }[]>([]);
  const [pick, setPick] = useState({ class_id: '', session_id: '', min_average: '', max_failed_core: '1' });
  const [annual, setAnnual] = useState<Annual | null>(null);
  const [promotion, setPromotion] = useState<Promotion | null>(null);
  const [config, setConfig] = useState<{ level_groups: Record<string, unknown>; effective: Record<string, Config> } | null>(null);
  const [group, setGroup] = useState('default');
  const [draft, setDraft] = useState<Config>({});
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<{ data: { id: number; name: string }[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string; is_current: boolean }[] }>('/student-directory/sessions').then((r) => {
      setSessions(r.data ?? []);
      const cur = r.data?.find((s) => s.is_current) ?? r.data?.[0];
      if (cur) setPick((p) => ({ ...p, session_id: String(cur.id) }));
    }).catch(() => undefined);
  }, []);

  const load = useCallback(async () => {
    setError(null);
    try {
      if (tab === 'settings') {
        const c = await fetchApi<{ level_groups: Record<string, unknown>; effective: Record<string, Config> }>('/broadsheets/config');
        setConfig(c);
        return;
      }
      if (!pick.class_id || !pick.session_id) return;
      const base = `class_id=${pick.class_id}&session_id=${pick.session_id}`;
      if (tab === 'annual') setAnnual(await fetchApi<Annual>(`/broadsheets/annual?${base}`));
      else setPromotion(await fetchApi<Promotion>(`/broadsheets/promotion?${base}${pick.min_average ? `&min_average=${pick.min_average}` : ''}&max_failed_core=${pick.max_failed_core}`));
    } catch (err) {
      setError(errorText(err, 'Could not load this.'));
    }
  }, [tab, pick]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function saveConfig() {
    setBusy(true);
    try {
      await fetchApi('/broadsheets/config', { method: 'PUT', body: JSON.stringify({ ...draft, level_group: group === 'default' ? null : group }) });
      setNotice('Broadsheet settings saved. They apply to the next calculation, not to approved versions.');
      setDraft({});
      await load();
    } catch (err) {
      setError(errorText(err, 'Could not save the settings.'));
    } finally {
      setBusy(false);
    }
  }

  const failed = (f: string[] | number) => (Array.isArray(f) ? f.join(', ') : f ? String(f) : '');
  const effective = { ...(config?.effective[group] ?? {}), ...draft };

  return (
    <PageShell title="Session results & promotion" subtitle="The year's averages per student, and who is recommended to move up." actions={<Link href="/broadsheet" className="rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium hover:bg-surface-sunken">Term broadsheets</Link>}>
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['annual', 'Session averages'], ['promotion', 'Promotion'], ...(admin ? [['settings', 'Broadsheet settings']] : [])] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => setTab(k)}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {tab !== 'settings' && (
        <div className="mb-5 grid max-w-3xl gap-3 sm:grid-cols-4">
          <Field label="Class"><select className={inputClass} value={pick.class_id} onChange={(e) => setPick({ ...pick, class_id: e.target.value })}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
          <Field label="Session"><select className={inputClass} value={pick.session_id} onChange={(e) => setPick({ ...pick, session_id: e.target.value })}>{sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
          {tab === 'promotion' && <>
            <Field label="Minimum average"><input className={inputClass} type="number" min={0} max={100} placeholder="pass mark" value={pick.min_average} onChange={(e) => setPick({ ...pick, min_average: e.target.value })} /></Field>
            <Field label="Core subjects failed, at most"><input className={inputClass} type="number" min={0} max={20} value={pick.max_failed_core} onChange={(e) => setPick({ ...pick, max_failed_core: e.target.value })} /></Field>
          </>}
        </div>
      )}

      {tab === 'annual' && (!pick.class_id ? <Empty>Choose a class.</Empty> : !annual ? <Loading /> : (
        <Card title={`${annual.class.name} · pass mark ${annual.pass_mark}`} description={annual.terms.some((t) => t.provisional) ? 'Terms marked provisional have no approved broadsheet yet.' : undefined}>
          <Table columns={['Student', ...annual.terms.map((t) => `${t.name}${t.provisional ? ' *' : ''}`), 'Session', 'Core failed']}>
            {annual.students.map((s) => (
              <tr key={s.id}>
                <Cell className="font-medium">{s.name}<span className="block text-xs text-ink-muted">{s.admission_number}</span></Cell>
                {annual.terms.map((t) => <Cell key={t.id}>{s.term_averages[t.id] ?? '—'}</Cell>)}
                <Cell><span className={s.session_average != null && s.session_average < annual.pass_mark ? 'font-semibold text-negative' : 'font-semibold'}>{s.session_average ?? '—'}</span>{s.terms_missing > 0 && <span className="block text-xs text-ink-muted">{s.terms_missing} term(s) missing</span>}</Cell>
                <Cell className="text-xs">{failed(s.failed_core_subjects)}</Cell>
              </tr>
            ))}
          </Table>
          <Button variant="secondary" className="mt-3 print:hidden" onClick={() => window.print()}>Print</Button>
        </Card>
      ))}

      {tab === 'promotion' && (!pick.class_id ? <Empty>Choose a class.</Empty> : !promotion ? <Loading /> : (
        <Card title={`Recommendations · average ${promotion.rules.min_average}+, at most ${promotion.rules.max_failed_core} core subject(s) failed`} description={promotion.note}>
          <Table columns={['Student', 'Session average', 'Recommendation', 'Why']}>
            {promotion.students.map((s) => (
              <tr key={s.id}>
                <Cell className="font-medium">{s.name}</Cell>
                <Cell>{s.session_average ?? '—'}</Cell>
                <Cell><Badge tone={/promot/i.test(s.recommendation) ? 'green' : /repeat|withheld/i.test(s.recommendation) ? 'red' : 'amber'}>{s.recommendation.replaceAll('_', ' ')}</Badge></Cell>
                <Cell className="text-xs text-ink-muted">{s.reasons.join('; ')}</Cell>
              </tr>
            ))}
          </Table>
          <p className="mt-3 text-sm"><Link href="/students" className="text-brand underline">Move students to their new class at session rollover</Link></p>
        </Card>
      ))}

      {tab === 'settings' && admin && (!config ? <Loading /> : (
        <Card description="How broadsheets are calculated and shown, for the whole school or one level of it.">
          <div className="mb-4 max-w-xs">
            <Field label="Applies to"><select className={inputClass} value={group} onChange={(e) => { setGroup(e.target.value); setDraft({}); }}><option value="default">Whole school (default)</option>{Object.keys(config.level_groups).map((g) => <option key={g} value={g}>{g.replaceAll('_', ' ')}</option>)}</select></Field>
          </div>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {SETTINGS.map(([key, label, kind]) => (
              kind === 'bool'
                ? <label key={key} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!effective[key]} onChange={(e) => setDraft({ ...draft, [key]: e.target.checked })} /> {label}</label>
                : kind === 'number'
                  ? <Field key={key} label={label}><input className={inputClass} type="number" min={0} max={100} value={String(effective[key] ?? '')} onChange={(e) => setDraft({ ...draft, [key]: Number(e.target.value) })} /></Field>
                  : <Field key={key} label={label}><select className={inputClass} value={String(effective[key] ?? '')} onChange={(e) => setDraft({ ...draft, [key]: e.target.value })}>{kind.map((o) => <option key={o} value={o}>{o.replaceAll('_', ' ')}</option>)}</select></Field>
            ))}
          </div>
          <Field label="Confidentiality footer"><input className={`${inputClass} mt-3`} maxLength={200} value={String(effective.confidentiality_footer ?? '')} onChange={(e) => setDraft({ ...draft, confidentiality_footer: e.target.value })} /></Field>
          <Button className="mt-4" disabled={busy || Object.keys(draft).length === 0} onClick={saveConfig}>Save settings</Button>
        </Card>
      ))}
    </PageShell>
  );
}
