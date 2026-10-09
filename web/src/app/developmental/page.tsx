'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, Empty, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';
import { useCookieRole } from '@/components/Sidebar';

/**
 * Developmental ratings for Nursery and early years: no CA or exam scores,
 * but a rating per child against the school's own indicators (social skills,
 * fine motor, listening…) with an optional observation, for the report card.
 *
 * A teacher rates the classes they teach (the API refuses others). The
 * school's indicator list is the admin's to set.
 */

interface Sheet {
  class: { name: string }; term: { name: string };
  indicators: { id: number; name: string }[];
  scale: Record<string, string>;
  students: { id: number; name: string; admission_number: string | null; ratings: Record<string, { rating: string; observation: string | null }> }[];
}
type Cell = { rating: string; observation: string };

export default function DevelopmentalPage() {
  const admin = useCookieRole() === 'school_admin';
  const [classes, setClasses] = useState<{ id: number; name: string; level_category?: string | null }[]>([]);
  const [terms, setTerms] = useState<{ id: number; name: string; session_name?: string | null }[]>([]);
  const [pick, setPick] = useState({ class_id: '', term_id: '' });
  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [cells, setCells] = useState<Record<string, Cell>>({});
  const [indicators, setIndicators] = useState<{ id?: number; name: string; is_active: boolean }[]>([]);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<{ data: { id: number; name: string; level_category?: string | null }[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string; session_name?: string | null }[]; current_term_id: number | null }>('/terms').then((r) => {
      setTerms(r.data ?? []);
      if (r.current_term_id) setPick((p) => ({ ...p, term_id: String(r.current_term_id) }));
    }).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string; is_active: boolean }[] }>('/developmental/indicators').then((r) => setIndicators(r.data)).catch(() => undefined);
  }, []);

  const load = useCallback(async () => {
    if (!pick.class_id || !pick.term_id) return;
    try {
      const s = await fetchApi<Sheet>(`/developmental/sheet?class_id=${pick.class_id}&term_id=${pick.term_id}`);
      setSheet(s);
      const next: Record<string, Cell> = {};
      s.students.forEach((st) => s.indicators.forEach((ind) => {
        const r = st.ratings?.[ind.id];
        if (r) next[`${st.id}:${ind.id}`] = { rating: r.rating, observation: r.observation ?? '' };
      }));
      setCells(next);
      setError(null);
    } catch (err) {
      setSheet(null);
      setError(errorText(err, 'Could not open this class.'));
    }
  }, [pick]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function save() {
    setBusy(true);
    setError(null);
    try {
      const ratings = Object.entries(cells).filter(([, c]) => c.rating).map(([k, c]) => {
        const [student_id, indicator_id] = k.split(':').map(Number);
        return { student_id, indicator_id, rating: c.rating, observation: c.observation || null };
      });
      const r = await fetchApi<{ message: string }>('/developmental/ratings', { method: 'PUT', body: JSON.stringify({ class_id: Number(pick.class_id), term_id: Number(pick.term_id), ratings }) });
      setNotice(r.message);
    } catch (err) {
      setError(errorText(err, 'Could not save the ratings.'));
    } finally {
      setBusy(false);
    }
  }

  async function saveIndicators() {
    setBusy(true);
    try {
      await fetchApi('/developmental/indicators', { method: 'PUT', body: JSON.stringify({ indicators: indicators.filter((i) => i.name.trim()) }) });
      setNotice('Indicators saved.');
      await load();
    } catch (err) {
      setError(errorText(err, 'Could not save the indicators.'));
    } finally {
      setBusy(false);
    }
  }

  const early = classes.filter((c) => !c.level_category || /nursery|pre|early|creche|kg/i.test(c.level_category));

  return (
    <PageShell title="Developmental ratings" subtitle="Nursery and early-years progress, rated against the school's own indicators.">
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      <div className="mb-5 grid max-w-xl gap-3 sm:grid-cols-2">
        <Field label="Class"><select className={inputClass} value={pick.class_id} onChange={(e) => setPick({ ...pick, class_id: e.target.value })}><option value="">Choose…</option>{(early.length ? early : classes).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
        <Field label="Term"><select className={inputClass} value={pick.term_id} onChange={(e) => setPick({ ...pick, term_id: e.target.value })}>{terms.map((t) => <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` — ${t.session_name}` : ''}</option>)}</select></Field>
      </div>

      {!pick.class_id ? <Empty>Choose a class to rate.</Empty> : !sheet ? (!error && <Loading />) : sheet.indicators.length === 0 ? <Empty>The school has not set any indicators yet.</Empty> : (
        <Card title={`${sheet.class.name} · ${sheet.term.name}`}>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[40rem] text-sm">
              <thead><tr className="border-b border-line-soft text-left text-xs uppercase tracking-wide text-ink-faint"><th className="px-2 py-2">Child</th>{sheet.indicators.map((i) => <th key={i.id} className="px-2 py-2">{i.name}</th>)}</tr></thead>
              <tbody className="divide-y divide-line-soft">
                {sheet.students.map((st) => (
                  <tr key={st.id}>
                    <td className="px-2 py-2 font-medium">{st.name}</td>
                    {sheet.indicators.map((ind) => {
                      const k = `${st.id}:${ind.id}`;
                      const c = cells[k] ?? { rating: '', observation: '' };
                      return (
                        <td key={ind.id} className="px-2 py-2 align-top">
                          <select className={`${inputClass} min-w-[9rem]`} value={c.rating} aria-label={`${st.name}: ${ind.name}`} onChange={(e) => setCells({ ...cells, [k]: { ...c, rating: e.target.value } })}>
                            <option value="">—</option>{Object.entries(sheet.scale).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                          </select>
                          {c.rating && <input className={`${inputClass} mt-1 text-xs`} placeholder="Observation" value={c.observation} onChange={(e) => setCells({ ...cells, [k]: { ...c, observation: e.target.value } })} />}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Button className="mt-4" disabled={busy} onClick={save}>Save ratings</Button>
        </Card>
      )}

      {admin && (
        <Card title="Indicators" description="What every early-years child is rated on. Turning one off keeps past ratings." className="mt-5">
          <ul className="space-y-2">
            {indicators.map((ind, i) => (
              <li key={ind.id ?? `new${i}`} className="flex items-center gap-2">
                <input className={inputClass} value={ind.name} maxLength={100} onChange={(e) => setIndicators(indicators.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
                <label className="flex items-center gap-1 text-sm"><input type="checkbox" checked={ind.is_active} onChange={(e) => setIndicators(indicators.map((x, j) => (j === i ? { ...x, is_active: e.target.checked } : x)))} /> in use</label>
              </li>
            ))}
          </ul>
          <div className="mt-3 flex gap-2">
            <Button variant="secondary" onClick={() => setIndicators([...indicators, { name: '', is_active: true }])}>Add indicator</Button>
            <Button disabled={busy} onClick={saveIndicators}>Save indicators</Button>
          </div>
        </Card>
      )}
    </PageShell>
  );
}
