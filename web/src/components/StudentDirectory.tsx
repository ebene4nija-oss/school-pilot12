'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Cell, Empty, ErrorNote, Field, inputClass } from '@/components/Page';
import StudentEditPanel from '@/components/StudentEditPanel';

/**
 * The student list (View Students page instructions).
 *
 * Everything runs on the server: search, filters, sorting, paging and what
 * each role may see. The page shows only what the API returned. Filters,
 * sort and page live in the URL, so returning from a profile restores the
 * list exactly (§3 rule 12).
 */

interface Row {
  id: number; name: string; display_name: string; admission_number: string; photo: string | null; gender: string | null;
  class: string | null; arm: string | null; status: string; admission_date: string | null;
  guardian_name: string | null; guardian_phone: string | null; siblings: number;
  attendance_rate: number | null; latest_average: number | null; fee_status: string | null;
  date_of_birth: string | null; completeness: string | null; missing_fields: string[];
  transfer: { reference: string; status: string } | null;
}
interface Counter { key: string; label: string; count: number; filter?: Record<string, string | boolean>; link?: string }
interface Perm {
  role: string; see_guardian_name: boolean; see_guardian_phone: boolean; see_fee_status: boolean;
  see_date_of_birth: boolean; see_completeness: boolean; can_bulk: boolean; can_export: boolean; export_fields: string[];
}
interface Listing { data: Row[]; meta: { total: number; current_page: number; last_page: number; per_page: number; session_id: number | null; scope_note: string | null }; cards: Counter[]; chips: Counter[]; permissions: Perm }
interface Option { id: number; name: string; is_current?: boolean }

const TABS = [
  ['active', 'Active'], ['pending', 'Pending'], ['transferred', 'Transferred'], ['graduated', 'Graduated'],
  ['withdrawn', 'Withdrawn'], ['all', 'All'],
] as const;

// Status shown as text and an icon, never colour alone (§4.3).
const STATUS: Record<string, { tone: 'green' | 'amber' | 'red' | 'slate' | 'blue'; icon: string; label: string }> = {
  active: { tone: 'green', icon: '●', label: 'Active' },
  pending: { tone: 'amber', icon: '◔', label: 'Pending' },
  transferred: { tone: 'blue', icon: '→', label: 'Transferred' },
  graduated: { tone: 'blue', icon: '🎓', label: 'Graduated' },
  withdrawn: { tone: 'slate', icon: '○', label: 'Withdrawn' },
  suspended: { tone: 'red', icon: '!', label: 'Suspended' },
};

const FILTER_KEYS = ['tab', 'search', 'class_id', 'arm_id', 'gender', 'admission_type', 'fee_status', 'record_status', 'no_class', 'admitted_from', 'admitted_to', 'session_id', 'sort', 'dir', 'page', 'per_page'];

function readUrl(): Record<string, string> {
  if (typeof window === 'undefined') return { tab: 'active' };
  const p = new URLSearchParams(window.location.search);
  const out: Record<string, string> = {};
  FILTER_KEYS.forEach((k) => { const v = p.get(k); if (v) out[k] = v; });
  return { tab: 'active', ...out };
}

export default function StudentDirectory() {
  const [filters, setFilters] = useState<Record<string, string>>(readUrl);
  const [searchText, setSearchText] = useState(() => readUrl().search ?? '');
  const [listing, setListing] = useState<Listing | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [classes, setClasses] = useState<Option[]>([]);
  const [sessions, setSessions] = useState<Option[]>([]);
  const [selected, setSelected] = useState<number[]>([]);
  const [quick, setQuick] = useState<Row | null>(null);
  const [bulk, setBulk] = useState<{ action: string; class_id: string; status: string; reason: string; preview?: { count: number; destination: string | null; students: { name: string; class: string }[] }; results?: { succeeded: number; failed: number; results: { name?: string; ok: boolean; message: string }[] } } | null>(null);
  const [showMore, setShowMore] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        const [c, s] = await Promise.all([
          fetchApi<{ data?: Option[] }>('/classes'),
          fetchApi<{ data?: Option[] }>('/student-directory/sessions').catch(() => ({ data: [] as Option[] })),
        ]);
        setClasses(c.data ?? []);
        setSessions(s.data ?? []);
      } catch {
        // Filters degrade to free search.
      }
    })();
  }, []);

  // Debounced search: one request after typing stops (§5.1).
  useEffect(() => {
    const timer = setTimeout(() => {
      setFilters((f) => (f.search ?? '') === searchText ? f : { ...f, search: searchText, page: '1' });
    }, 400);
    return () => clearTimeout(timer);
  }, [searchText]);

  const query = useMemo(() => {
    const p = new URLSearchParams();
    Object.entries(filters).forEach(([k, v]) => { if (v !== '' && v != null) p.set(k, v); });
    return p.toString();
  }, [filters]);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setListing(await fetchApi<Listing>(`/student-directory?${query}`));
    } catch (err) {
      setError(errorText(err, 'Could not load students. Try again.'));
    } finally {
      setLoading(false);
    }
  }, [query]);

  useEffect(() => {
    window.history.replaceState(null, '', `${window.location.pathname}?${query}`);
    (async () => { await load(); })();
  }, [query, load]);

  function set(next: Record<string, string | undefined>) {
    setSelected([]);
    setFilters((f) => {
      const merged: Record<string, string> = { ...f, page: '1' };
      Object.entries(next).forEach(([k, v]) => { if (v === undefined || v === '') delete merged[k]; else merged[k] = v; });
      return merged;
    });
  }

  function applyCounter(c: Counter) {
    if (c.link) { window.location.assign(c.link); return; }
    const f: Record<string, string> = {};
    Object.entries(c.filter ?? {}).forEach(([k, v]) => { f[k] = String(v === true ? 1 : v); });
    setSearchText('');
    setFilters({ ...f, page: '1' });
  }

  async function runBulk(confirm: boolean) {
    if (!bulk) return;
    const body = {
      action: bulk.action, student_ids: selected, reason: bulk.reason,
      ...(bulk.action === 'move_class' ? { class_id: Number(bulk.class_id) } : { status: bulk.status }),
      ...(confirm ? { confirm: true } : {}),
    };
    try {
      if (!confirm) {
        setBulk({ ...bulk, preview: await fetchApi(`/student-directory/bulk/preview`, { method: 'POST', body: JSON.stringify(body) }) });
      } else {
        setBulk({ ...bulk, results: await fetchApi(`/student-directory/bulk`, { method: 'POST', body: JSON.stringify(body) }) });
        setSelected([]);
        await load();
      }
    } catch (err) {
      setError(errorText(err, 'The bulk action failed.'));
    }
  }

  const perm = listing?.permissions;
  const rows = listing?.data ?? [];
  const activeChips = FILTER_KEYS.filter((k) => !['tab', 'page', 'per_page', 'sort', 'dir', 'session_id'].includes(k) && filters[k]);

  return (
    <section aria-label="Students">
      <ErrorNote message={error} />

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <select className={`${inputClass} max-w-[12rem]`} aria-label="Session" value={filters.session_id ?? ''} onChange={(e) => set({ session_id: e.target.value })}>
          <option value="">Current session</option>
          {sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        {perm?.can_export && (
          <a className="rounded-lg border border-line px-3 py-2 text-sm" href={`/api/backend/student-directory/export?${query}`}>Export CSV</a>
        )}
        {filters.class_id && (perm?.can_export || perm?.can_bulk) && (
          <>
            <a className="rounded-lg border border-line px-3 py-2 text-sm" href={`/api/backend/student-directory/print?kind=class_list&class_id=${filters.class_id}`}>Print class list</a>
            <a className="rounded-lg border border-line px-3 py-2 text-sm" href={`/api/backend/student-directory/print?kind=register&class_id=${filters.class_id}`}>Print register</a>
          </>
        )}
      </div>

      {listing && (
        <div className="mb-3 flex gap-3 overflow-x-auto pb-1" role="list" aria-label="Summary">
          {listing.cards.map((c) => (
            <button key={c.key} role="listitem" onClick={() => applyCounter(c)} className="min-w-[9rem] shrink-0 rounded-xl bg-surface p-4 text-left shadow-sm ring-1 ring-line-soft hover:ring-brand">
              <p className="text-xs uppercase tracking-wide text-ink-faint">{c.label}</p>
              <p className="font-headline mt-1 text-xl font-bold text-brand">{c.count.toLocaleString('en-NG')}</p>
            </button>
          ))}
        </div>
      )}

      {listing && listing.chips.length > 0 && (
        <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
          <span className="text-ink-muted">Needs attention:</span>
          {listing.chips.map((c) => (
            <button key={c.key} onClick={() => applyCounter(c)} className="rounded-full bg-caution-soft px-3 py-1 text-caution hover:underline">{c.label} {c.count}</button>
          ))}
        </div>
      )}

      <nav className="mb-4 flex flex-wrap gap-1" aria-label="Enrolment status">
        {TABS.map(([key, label]) => (
          <Button key={key} variant={(filters.tab ?? 'active') === key ? 'primary' : 'secondary'} onClick={() => set({ tab: key })}>{label}</Button>
        ))}
      </nav>

      <div className="sticky top-0 z-10 mb-3 bg-canvas pb-2">
        <input
          className={inputClass}
          type="search"
          value={searchText}
          onChange={(e) => setSearchText(e.target.value)}
          placeholder={perm?.see_guardian_name ? 'Search by student name, admission number or guardian name…' : 'Search by student name or admission number…'}
          aria-label="Search students"
        />
      </div>

      <div className="mb-3 flex flex-wrap gap-2">
        <select className={`${inputClass} max-w-[10rem]`} aria-label="Class" value={filters.class_id ?? ''} onChange={(e) => set({ class_id: e.target.value, arm_id: undefined })}>
          <option value="">All classes</option>
          {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select className={`${inputClass} max-w-[9rem]`} aria-label="Gender" value={filters.gender ?? ''} onChange={(e) => set({ gender: e.target.value })}>
          <option value="">Any gender</option><option value="female">Female</option><option value="male">Male</option>
        </select>
        <select className={`${inputClass} max-w-[11rem]`} aria-label="Admission type" value={filters.admission_type ?? ''} onChange={(e) => set({ admission_type: e.target.value })}>
          <option value="">Any admission type</option><option value="new">New admission</option><option value="returning">Returning</option><option value="transfer_in">Transfer-in</option>
        </select>
        <Button variant="secondary" onClick={() => setShowMore(!showMore)}>{showMore ? 'Fewer filters' : 'More filters'}</Button>
        {activeChips.length > 0 && <Button variant="secondary" onClick={() => { setSearchText(''); setFilters({ tab: filters.tab ?? 'active', page: '1' }); }}>Clear filters</Button>}
      </div>

      {showMore && (
        <div className="mb-3 grid gap-2 rounded-lg p-3 ring-1 ring-line-soft md:grid-cols-4">
          <Field label="Admitted from"><input type="date" className={inputClass} value={filters.admitted_from ?? ''} onChange={(e) => set({ admitted_from: e.target.value })} /></Field>
          <Field label="Admitted to"><input type="date" className={inputClass} value={filters.admitted_to ?? ''} onChange={(e) => set({ admitted_to: e.target.value })} /></Field>
          {perm?.see_fee_status && (
            <Field label="Fee status">
              <select className={inputClass} value={filters.fee_status ?? ''} onChange={(e) => set({ fee_status: e.target.value })}>
                <option value="">Any</option><option value="paid">Paid</option><option value="partial">Partly paid</option><option value="outstanding">Outstanding</option>
              </select>
            </Field>
          )}
          {perm?.see_completeness && (
            <Field label="Record status">
              <select className={inputClass} value={filters.record_status ?? ''} onChange={(e) => set({ record_status: e.target.value })}>
                <option value="">Any</option><option value="complete">Complete</option><option value="incomplete">Incomplete</option><option value="requires_review">Requires review</option>
              </select>
            </Field>
          )}
        </div>
      )}

      {activeChips.length > 0 && (
        <div className="mb-3 flex flex-wrap gap-2">
          {activeChips.map((k) => (
            <button key={k} onClick={() => { if (k === 'search') setSearchText(''); set({ [k]: undefined }); }} className="rounded-full bg-surface-raised px-3 py-1 text-xs">
              {k.replace('_', ' ')}: {k === 'class_id' ? classes.find((c) => String(c.id) === filters[k])?.name : filters[k]} ✕
            </button>
          ))}
        </div>
      )}

      <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
        <span>{listing ? `${listing.meta.total.toLocaleString('en-NG')} students` : ''}{listing?.meta.scope_note ? ` · ${listing.meta.scope_note}` : ''}</span>
        {perm?.can_bulk && (
          <div className="flex items-center gap-2">
            <span className="text-ink-muted">{selected.length} selected</span>
            <select className={`${inputClass} max-w-[12rem]`} disabled={selected.length === 0} value="" onChange={(e) => setBulk({ action: e.target.value, class_id: '', status: 'active', reason: '' })} aria-label="Bulk actions">
              <option value="">Bulk actions</option>
              <option value="move_class">Move class or arm</option>
              <option value="change_status">Change status</option>
            </select>
          </div>
        )}
      </div>

      {loading && !listing ? (
        <div className="space-y-2" aria-busy="true">{[...Array(6)].map((_, i) => <div key={i} className="h-12 animate-pulse rounded-lg bg-surface-raised" />)}</div>
      ) : rows.length === 0 ? (
        <Empty>
          {activeChips.length > 0 || filters.search
            ? 'No students match your search or filters. Try changing your search criteria.'
            : 'Your school has not registered any students for this session yet.'}
        </Empty>
      ) : (
        <>
          {/* Phones: one card per student (§12.2). */}
          <ul className="space-y-2 md:hidden">
            {rows.map((r) => (
              <li key={r.id} className="rounded-xl bg-surface p-3 ring-1 ring-line-soft">
                <div className="flex items-start justify-between gap-2">
                  <button className="text-left" onClick={() => setQuick(r)}>
                    <p className="font-semibold">{r.display_name}</p>
                    <p className="text-xs text-ink-muted">{r.admission_number} · {[r.class, r.arm].filter(Boolean).join(' ') || 'No class'}</p>
                  </button>
                  <Badge tone={STATUS[r.status]?.tone ?? 'slate'}>{STATUS[r.status]?.icon} {STATUS[r.status]?.label ?? r.status}</Badge>
                </div>
                <div className="mt-2 flex gap-3 text-sm">
                  <a className="text-brand underline" href={`/academic-history?student_id=${r.id}`}>View profile</a>
                  {r.guardian_phone && <a className="text-brand underline" href={`tel:${r.guardian_phone}`}>Call guardian</a>}
                </div>
              </li>
            ))}
          </ul>

          <div className="hidden overflow-x-auto md:block">
            <table className="w-full min-w-[48rem] text-left text-sm">
              <thead className="sticky top-0 bg-canvas">
                <tr className="border-b border-line-soft text-xs uppercase tracking-wide text-ink-faint">
                  {perm?.can_bulk && <th className="px-3 py-2"><input type="checkbox" aria-label="Select all" checked={selected.length === rows.length} onChange={(e) => setSelected(e.target.checked ? rows.map((r) => r.id) : [])} /></th>}
                  <th className="px-3 py-2"><button onClick={() => set({ sort: 'name', dir: filters.sort === 'name' && filters.dir !== 'desc' ? 'desc' : 'asc' })}>Student</button></th>
                  <th className="px-3 py-2">Gender</th>
                  <th className="px-3 py-2"><button onClick={() => set({ sort: 'class', dir: filters.sort === 'class' && filters.dir !== 'desc' ? 'desc' : 'asc' })}>Class</button></th>
                  {perm?.see_guardian_name && <th className="px-3 py-2">Guardian</th>}
                  {perm?.see_guardian_phone && <th className="px-3 py-2">Phone</th>}
                  <th className="px-3 py-2">Status</th>
                  {perm?.see_fee_status && <th className="px-3 py-2">Fees</th>}
                  {perm?.see_completeness && <th className="px-3 py-2">Record</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-line-soft">
                {rows.map((r) => (
                  <tr key={r.id} className="cursor-pointer hover:bg-surface-sunken" onClick={() => setQuick(r)}>
                    {perm?.can_bulk && (
                      <Cell><input type="checkbox" aria-label={`Select ${r.name}`} checked={selected.includes(r.id)} onClick={(e) => e.stopPropagation()} onChange={(e) => setSelected(e.target.checked ? [...selected, r.id] : selected.filter((id) => id !== r.id))} /></Cell>
                    )}
                    <Cell className="sticky left-0 bg-surface">
                      <a className="font-medium text-brand hover:underline" href={`/academic-history?student_id=${r.id}`} onClick={(e) => e.stopPropagation()}>{r.display_name}</a>
                      <span className="block text-xs text-ink-muted">{r.admission_number}{r.siblings > 0 ? ` · ${r.siblings} sibling${r.siblings > 1 ? 's' : ''}` : ''}</span>
                    </Cell>
                    <Cell className="capitalize">{r.gender ?? '—'}</Cell>
                    <Cell>{[r.class, r.arm].filter(Boolean).join(' ') || <span className="text-caution">No class</span>}</Cell>
                    {perm?.see_guardian_name && <Cell>{r.guardian_name ?? '—'}</Cell>}
                    {perm?.see_guardian_phone && <Cell>{r.guardian_phone ?? '—'}</Cell>}
                    <Cell>
                      <Badge tone={STATUS[r.status]?.tone ?? 'slate'}>{STATUS[r.status]?.icon} {STATUS[r.status]?.label ?? r.status}</Badge>
                      {r.transfer && <span className="block text-xs text-ink-muted">{r.transfer.reference}</span>}
                    </Cell>
                    {perm?.see_fee_status && <Cell>{r.fee_status?.replace('_', ' ') ?? '—'}</Cell>}
                    {perm?.see_completeness && <Cell>{r.completeness === 'complete' ? '✓ Complete' : <span title={r.missing_fields.join(', ')} className="text-caution">! Incomplete</span>}</Cell>}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {listing && listing.meta.last_page > 1 && (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm">
              <span>Page {listing.meta.current_page} of {listing.meta.last_page}</span>
              <div className="flex items-center gap-2">
                <select className={`${inputClass} max-w-[6rem]`} aria-label="Page size" value={filters.per_page ?? '25'} onChange={(e) => set({ per_page: e.target.value })}>
                  {['10', '25', '50', '100'].map((n) => <option key={n} value={n}>{n}</option>)}
                </select>
                <Button variant="secondary" disabled={listing.meta.current_page <= 1} onClick={() => setFilters({ ...filters, page: String(listing.meta.current_page - 1) })}>Previous</Button>
                <Button variant="secondary" disabled={listing.meta.current_page >= listing.meta.last_page} onClick={() => setFilters({ ...filters, page: String(listing.meta.current_page + 1) })}>Next</Button>
              </div>
            </div>
          )}
        </>
      )}

      {quick && (
        <div className="fixed inset-0 z-30 flex justify-end bg-black/30" onClick={() => setQuick(null)}>
          <aside className="h-full w-full max-w-md overflow-y-auto bg-surface p-6 shadow-xl" onClick={(e) => e.stopPropagation()} aria-label="Quick view">
            <Button variant="secondary" onClick={() => setQuick(null)}>Close</Button>
            <h2 className="font-headline mt-4 text-xl font-bold">{quick.display_name}</h2>
            <p className="text-sm text-ink-muted">{quick.admission_number} · {[quick.class, quick.arm].filter(Boolean).join(' ') || 'No class'}</p>
            <Badge tone={STATUS[quick.status]?.tone ?? 'slate'}>{STATUS[quick.status]?.label ?? quick.status}</Badge>
            <StudentEditPanel key={quick.id} studentId={quick.id} />
            <dl className="mt-4 space-y-2 text-sm">
              {quick.guardian_name && <div><dt className="text-ink-muted">Guardian</dt><dd>{quick.guardian_name}{quick.guardian_phone ? ` · ${quick.guardian_phone}` : ''}</dd></div>}
              {quick.attendance_rate != null && <div><dt className="text-ink-muted">Attendance this session</dt><dd>{quick.attendance_rate}%</dd></div>}
              {quick.latest_average != null && <div><dt className="text-ink-muted">Latest term average (provisional)</dt><dd>{quick.latest_average}</dd></div>}
              {quick.date_of_birth && <div><dt className="text-ink-muted">Date of birth</dt><dd>{quick.date_of_birth}</dd></div>}
              {quick.missing_fields.length > 0 && <div><dt className="text-ink-muted">Missing</dt><dd className="text-caution">{quick.missing_fields.join(', ')}</dd></div>}
            </dl>
            <div className="mt-6 flex flex-col gap-2">
              <a className="text-brand underline" href={`/academic-history?student_id=${quick.id}`}>Academic history</a>
              <a className="text-brand underline" href={`/progress?student_id=${quick.id}`}>Progress and analysis</a>
            </div>
          </aside>
        </div>
      )}

      {bulk && (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" role="dialog" aria-modal="true">
          <div className="w-full max-w-lg rounded-xl bg-surface p-6 shadow-xl">
            <h2 className="font-headline text-lg font-bold">{bulk.action === 'move_class' ? 'Move class or arm' : 'Change status'} · {selected.length} student(s)</h2>
            {!bulk.results && (
              <div className="mt-4 space-y-3">
                {bulk.action === 'move_class' ? (
                  <Field label="Destination class">
                    <select className={inputClass} value={bulk.class_id} onChange={(e) => setBulk({ ...bulk, class_id: e.target.value, preview: undefined })}>
                      <option value="">Choose…</option>
                      {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                  </Field>
                ) : (
                  <Field label="New status">
                    <select className={inputClass} value={bulk.status} onChange={(e) => setBulk({ ...bulk, status: e.target.value, preview: undefined })}>
                      <option value="active">Active</option><option value="suspended">Suspended</option><option value="withdrawn">Withdrawn</option>
                    </select>
                  </Field>
                )}
                <Field label="Reason (recorded)"><input className={inputClass} value={bulk.reason} onChange={(e) => setBulk({ ...bulk, reason: e.target.value, preview: undefined })} /></Field>
                {bulk.preview && (
                  <div className="max-h-48 overflow-y-auto rounded-lg p-3 text-sm ring-1 ring-line-soft">
                    <p className="font-semibold">{bulk.preview.count} student(s){bulk.preview.destination ? ` → ${bulk.preview.destination}` : ''}</p>
                    <ul className="mt-1 list-disc pl-5">{bulk.preview.students.map((s, i) => <li key={i}>{s.name} ({s.class ?? 'no class'})</li>)}</ul>
                  </div>
                )}
                <div className="flex gap-2">
                  <Button variant="secondary" onClick={() => setBulk(null)}>Cancel</Button>
                  {!bulk.preview ? (
                    <Button disabled={bulk.reason.length < 3 || (bulk.action === 'move_class' && !bulk.class_id)} onClick={() => runBulk(false)}>Preview</Button>
                  ) : (
                    <Button variant="accent" onClick={() => runBulk(true)}>Confirm for {bulk.preview.count}</Button>
                  )}
                </div>
              </div>
            )}
            {bulk.results && (
              <div className="mt-4 text-sm">
                <p>{bulk.results.succeeded} succeeded · {bulk.results.failed} failed</p>
                <ul className="mt-2 max-h-60 overflow-y-auto">{bulk.results.results.map((r, i) => <li key={i} className={r.ok ? '' : 'text-negative'}>{r.ok ? '✓' : '✕'} {r.name ?? 'Unknown'} — {r.message}</li>)}</ul>
                <Button className="mt-3" onClick={() => setBulk(null)}>Done</Button>
              </div>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
