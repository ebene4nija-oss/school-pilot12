'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { useClassSubjects } from '@/lib/useClassSubjects';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';
import { RichText } from '@/components/RichContent';
import QuestionEditor, { TYPES, blankQuestion, type Media, type QuestionInput } from '@/components/cbt/QuestionEditor';

/**
 * The question bank (doc §7.9, docs/cbt-authoring-guide.md): every question
 * a school writes, reused across papers and years. Questions of all eleven
 * types, passages that hold a group of questions, and the image library.
 *
 * Questions are filed by class, then subject, then topic, and the browse
 * panel down the side is that filing. Rows can be ticked, a page at a time
 * or every question matching the filters, and moved, retopicked, retired,
 * deleted or added to a paper together.
 *
 * The list never carries a marking scheme; the editor fetches one question in
 * full when its author opens it.
 */

type Tab = 'questions' | 'groups' | 'images' | 'import';
interface Row { id: number; subject: string | null; subject_id: number; class_id: number | null; class: string | null; group_title: string | null; topic: string | null; question: string; question_type: string; content_format: 'plain' | 'latex'; difficulty: string | null; status: string; marks: number; times_answered: number | null; difficulty_index: number | null }
interface Group { id: number; title: string; subject_id: number | null; stimulus: string | null; instructions: string | null; content_format: string; questions_count?: number }
interface Version { version: number; created_at: string; change_summary: string | null; affects_scoring: boolean; payload?: { question?: string } }
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }
interface ClassRow { id: number; name: string; level_category: string }
interface Summary { total: number; unsorted: number; classes: { id: number; name: string; level_group: string; total: number }[]; rows: { class_id: number | null; subject_id: number; subject: string | null; topic: string | null; total: number }[] }
type Option = { id: number; name: string };

const TYPE_NAME = Object.fromEntries(TYPES);
const LEVELS: [string, string][] = [['nursery', 'Nursery'], ['primary', 'Primary'], ['junior_secondary', 'JSS'], ['senior_secondary', 'SS'], ['secondary', 'Secondary']];
const BLANK_FILTERS = { class_id: '', level_group: '', subject_id: '', question_type: '', difficulty: '', status: '', topic: '', search: '' };
type Filters = typeof BLANK_FILTERS;

export default function QuestionBankPage() {
  const [tab, setTab] = useState<Tab>('questions');
  const [subjects, setSubjects] = useState<Option[]>([]);
  const [classes, setClasses] = useState<ClassRow[]>([]);
  const [groups, setGroups] = useState<Group[]>([]);
  const [media, setMedia] = useState<Media[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const loadGroups = useCallback(() => fetchApi<Page<Group>>('/cbt/question-groups?per_page=100').then((r) => setGroups(r.data)).catch(() => undefined), []);
  const loadMedia = useCallback(() => fetchApi<Page<Media>>('/cbt/media?per_page=100').then((r) => setMedia(r.data)).catch(() => undefined), []);

  useEffect(() => {
    fetchApi<{ data: Option[] }>('/subjects').then((r) => setSubjects(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: ClassRow[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined);
    void loadGroups();
    void loadMedia();
  }, [loadGroups, loadMedia]);

  const done = useCallback((m: string) => { setNotice(m); setError(null); }, []);
  const fail = useCallback((e: unknown, f: string) => { setError(errorText(e, f)); setNotice(null); }, []);

  return (
    <PageShell title="Question bank" subtitle="Write questions once and use them on any paper. Filed by class, subject and topic.">
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['questions', 'Questions'], ['groups', 'Passages'], ['images', 'Images'], ['import', 'Import']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setNotice(null); setError(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {tab === 'questions' && <Questions subjects={subjects} classes={classes} groups={groups} media={media} onDone={done} onError={fail} />}
      {tab === 'groups' && <Groups subjects={subjects} groups={groups} reload={loadGroups} onDone={done} onError={fail} />}
      {tab === 'images' && <Images media={media} reload={loadMedia} onDone={done} onError={fail} />}
      {tab === 'import' && <Import classes={classes} onDone={done} onError={fail} />}
    </PageShell>
  );
}

type Handlers = { onDone: (m: string) => void; onError: (e: unknown, f: string) => void };

function Questions({ subjects, classes, groups, media, onDone, onError }: { subjects: Option[]; classes: ClassRow[]; groups: Group[]; media: Media[] } & Handlers) {
  const [filters, setFilters] = useState<Filters>(BLANK_FILTERS);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [list, setList] = useState<Page<Row> | null>(null);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [selected, setSelected] = useState<number[]>([]);
  const [allMatching, setAllMatching] = useState(false);
  const [editing, setEditing] = useState<{ q: QuestionInput; frozen: boolean } | null>(null);
  const [versions, setVersions] = useState<{ id: number; rows: Version[] } | null>(null);
  const filterSubjects = useClassSubjects(filters.class_id && filters.class_id !== 'none' ? filters.class_id : null);

  const active = useMemo(() => Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), [filters]);

  const load = useCallback(async () => {
    const qs = new URLSearchParams({ page: String(page), per_page: String(perPage), ...active });
    try { setList(await fetchApi<Page<Row>>(`/cbt/questions?${qs}`)); } catch (err) { onError(err, 'Could not load questions.'); }
  }, [active, page, perPage, onError]);

  const loadSummary = useCallback(() => fetchApi<Summary>('/cbt/questions/summary').then(setSummary).catch(() => undefined), []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  useEffect(() => {
    void (async () => {
      await loadSummary();
    })();
  }, [loadSummary]);

  const refresh = async () => { setSelected([]); setAllMatching(false); await Promise.all([load(), loadSummary()]); };

  async function open(id: number) {
    try {
      const res = await fetchApi<{ question: QuestionInput & { explanation: string | null; topic: string | null; class_id: number | null; media: QuestionInput['media'] | null; options: Record<string, string> | null }; frozen: boolean }>(`/cbt/questions/${id}`);
      const q = res.question;
      setEditing({ frozen: res.frozen, q: { ...q, class_id: q.class_id ?? '', topic: q.topic ?? '', explanation: q.explanation ?? '', media: q.media ?? [], marks: Number(q.marks), negative_marks: Number(q.negative_marks ?? 0) } });
    } catch (err) { onError(err, 'Could not open that question.'); }
  }

  async function remove(id: number) {
    if (!window.confirm('Delete this question from the bank?')) return;
    try { const r = await fetchApi<{ message?: string }>(`/cbt/questions/${id}`, { method: 'DELETE' }); onDone(r.message ?? 'Deleted.'); await refresh(); } catch (err) { onError(err, 'Could not delete it.'); }
  }

  async function history(id: number) {
    try { const r = await fetchApi<{ data: Version[] }>(`/cbt/questions/${id}/versions`); setVersions({ id, rows: r.data }); } catch (err) { onError(err, 'Could not load its history.'); }
  }

  // Any change of filter or page starts the selection again: "select all
  // matching" must mean what is on screen now.
  const set = (patch: Partial<Filters>) => { setPage(1); setSelected([]); setAllMatching(false); setFilters((f) => ({ ...f, ...patch })); };

  if (editing) {
    return (
      <Card title={editing.q.id ? `Edit question #${editing.q.id}` : 'New question'}>
        <QuestionEditor key={editing.q.id ?? 'new'} initial={editing.q} frozen={editing.frozen} classes={classes} groups={groups} media={media}
          onSaved={(m) => { onDone(m); setEditing(null); void refresh(); }} onCancel={() => setEditing(null)} />
      </Card>
    );
  }

  const rows = list?.data ?? [];
  const pageIds = rows.map((r) => r.id);
  const pageAllTicked = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
  const count = allMatching ? list?.total ?? 0 : selected.length;
  const togglePage = (on: boolean) => { setAllMatching(false); setSelected(on ? Array.from(new Set([...selected, ...pageIds])) : selected.filter((id) => !pageIds.includes(id))); };
  const toggleRow = (id: number, on: boolean) => { setAllMatching(false); setSelected(on ? [...selected, id] : selected.filter((x) => x !== id)); };

  return (
    <div className="grid gap-5 lg:grid-cols-[16rem_1fr]">
      <Browse summary={summary} filters={filters} onPick={set} />

      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-4">
          <Field label="Class">
            <select className={inputClass} value={filters.class_id} onChange={(e) => set({ class_id: e.target.value, level_group: '', subject_id: '', topic: '' })}>
              <option value="">All</option>
              <option value="none">Unsorted (no class)</option>
              {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <Field label="Level">
            <select className={inputClass} value={filters.level_group} onChange={(e) => set({ level_group: e.target.value, class_id: '' })}>
              <option value="">All</option>
              {LEVELS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </Field>
          <Field label="Subject"><select className={inputClass} value={filters.subject_id} onChange={(e) => set({ subject_id: e.target.value, topic: '' })}><option value="">All</option>{(filters.class_id && filters.class_id !== 'none' ? filterSubjects : subjects).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
          <Field label="Topic"><input className={inputClass} value={filters.topic} onChange={(e) => set({ topic: e.target.value })} /></Field>
          <Field label="Type"><select className={inputClass} value={filters.question_type} onChange={(e) => set({ question_type: e.target.value })}><option value="">All</option>{TYPES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
          <Field label="Difficulty"><select className={inputClass} value={filters.difficulty} onChange={(e) => set({ difficulty: e.target.value })}><option value="">All</option><option>easy</option><option>medium</option><option>hard</option></select></Field>
          <Field label="Status"><select className={inputClass} value={filters.status} onChange={(e) => set({ status: e.target.value })}><option value="">All</option><option value="approved">Ready</option><option value="draft">Draft</option><option value="retired">Retired</option></select></Field>
          <Field label="Search"><input className={inputClass} value={filters.search} onChange={(e) => set({ search: e.target.value })} /></Field>
        </div>

        <div className="mb-4 flex flex-wrap items-center gap-3">
          <Button variant="accent" onClick={() => setEditing({ frozen: false, q: blankQuestion(filters.subject_id ? Number(filters.subject_id) : '', filters.class_id && filters.class_id !== 'none' ? Number(filters.class_id) : '') })}>New question</Button>
          {Object.keys(active).length > 0 && <Button variant="secondary" onClick={() => set(BLANK_FILTERS)}>Clear filters</Button>}
        </div>

        {count > 0 && (
          <BulkBar
            count={count}
            selection={allMatching ? { filters: active } : { ids: selected }}
            classes={classes}
            subjects={subjects}
            onClear={() => { setSelected([]); setAllMatching(false); }}
            onDone={async (m) => { onDone(m); await refresh(); }}
            onError={onError}
          />
        )}

        {list && pageAllTicked && !allMatching && list.total > pageIds.length && (
          <p className="mb-3 rounded-lg bg-info-soft px-4 py-2 text-sm text-info">
            All {pageIds.length} on this page are ticked.{' '}
            <button type="button" className="font-semibold underline" onClick={() => setAllMatching(true)}>Select all {list.total} matching these filters</button>
          </p>
        )}
        {allMatching && list && (
          <p className="mb-3 rounded-lg bg-info-soft px-4 py-2 text-sm text-info">
            All {list.total} matching questions are selected.{' '}
            <button type="button" className="font-semibold underline" onClick={() => { setAllMatching(false); setSelected([]); }}>Clear selection</button>
          </p>
        )}

        {!list ? <Loading /> : rows.length === 0 ? <Empty>No questions match.</Empty> : (
          <Table columns={['', '#', 'Question', 'Type', 'Class / subject / topic', 'Marks', 'Used', '']}>
            <tr className="bg-surface-sunken">
              <Cell><input type="checkbox" className="h-4 w-4 accent-brand" aria-label="Tick every question on this page" checked={pageAllTicked || allMatching} onChange={(e) => togglePage(e.target.checked)} /></Cell>
              <Cell className="text-xs text-ink-muted" >{null}</Cell>
              <Cell className="text-xs text-ink-muted">Tick all on this page</Cell>
              <Cell>{null}</Cell><Cell>{null}</Cell><Cell>{null}</Cell><Cell>{null}</Cell><Cell>{null}</Cell>
            </tr>
            {rows.map((q) => (
              <tr key={q.id} className={selected.includes(q.id) || allMatching ? 'bg-brand/5' : ''}>
                <Cell><input type="checkbox" className="h-4 w-4 accent-brand" aria-label={`Tick question ${q.id}`} checked={allMatching || selected.includes(q.id)} onChange={(e) => toggleRow(q.id, e.target.checked)} /></Cell>
                <Cell className="text-xs text-ink-muted">{q.id}</Cell>
                <Cell className="max-w-md"><RichText content={q.question.length > 160 ? `${q.question.slice(0, 160)}…` : q.question} contentFormat={q.content_format} />{q.group_title && <span className="block text-xs text-ink-muted">Passage: {q.group_title}</span>}</Cell>
                <Cell><span className="text-xs">{TYPE_NAME[q.question_type] ?? q.question_type}</span>{q.status !== 'approved' && <Badge tone="amber">{q.status}</Badge>}</Cell>
                <Cell className="text-sm">
                  {q.class ?? <Badge tone="amber">Unsorted</Badge>}
                  <span className="block">{q.subject}</span>
                  <span className="block text-xs text-ink-muted">{[q.topic, q.difficulty].filter(Boolean).join(' · ')}</span>
                </Cell>
                <Cell>{q.marks}</Cell>
                <Cell className="text-xs text-ink-muted">{q.times_answered ? `${q.times_answered}× · p=${q.difficulty_index ?? '—'}` : '—'}</Cell>
                <Cell className="space-x-2 whitespace-nowrap text-right">
                  <button type="button" className="text-sm text-brand underline" onClick={() => open(q.id)}>Edit</button>
                  <button type="button" className="text-sm text-ink-muted underline" onClick={() => history(q.id)}>History</button>
                  <button type="button" className="text-sm text-negative underline" onClick={() => remove(q.id)}>Delete</button>
                </Cell>
              </tr>
            ))}
          </Table>
        )}
        {list && (
          <div className="mt-4 flex flex-wrap items-center gap-3">
            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
            <span className="text-sm text-ink-muted">Page {page} of {Math.max(1, list.last_page)} · {list.total} questions</span>
            <Button variant="secondary" disabled={page >= list.last_page} onClick={() => setPage(page + 1)}>Next</Button>
            <select className={`${inputClass} ml-auto w-32`} value={perPage} onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1); }} aria-label="Questions per page">
              {[25, 50, 100].map((n) => <option key={n} value={n}>{n} per page</option>)}
            </select>
          </div>
        )}

        {versions && (
          <div className="mt-5 rounded-lg border border-line-soft p-4">
            <p className="mb-2 text-sm font-semibold">History of question #{versions.id}</p>
            {versions.rows.length === 0 ? <p className="text-sm text-ink-muted">Never edited after being answered.</p> : (
              <ul className="space-y-1 text-sm">{versions.rows.map((v) => <li key={v.version}>Version {v.version} · {v.created_at?.slice(0, 10)}{v.affects_scoring && <Badge tone="amber">changed marking</Badge>}{v.change_summary && <span className="text-ink-muted"> · {v.change_summary}</span>}{v.payload?.question && <span className="block text-xs text-ink-faint">“{v.payload.question.slice(0, 100)}”</span>}</li>)}</ul>
            )}
            <button type="button" className="mt-2 text-xs underline" onClick={() => setVersions(null)}>Close</button>
          </div>
        )}
      </Card>
    </div>
  );
}

/** The bank's table of contents: level, class, subject, topic, with counts. */
function Browse({ summary, filters, onPick }: { summary: Summary | null; filters: Filters; onPick: (patch: Partial<Filters>) => void }) {
  if (!summary) return <Card><Loading /></Card>;

  const item = (label: string, total: number, on: boolean, pick: () => void, depth = 0) => (
    <button type="button" onClick={pick}
      className={`flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left text-sm ${on ? 'bg-brand text-white' : 'text-ink hover:bg-surface-sunken'}`}
      style={{ paddingLeft: `${0.5 + depth * 0.9}rem` }}>
      <span className="truncate">{label}</span><span className={`ml-2 text-xs ${on ? 'text-white/80' : 'text-ink-muted'}`}>{total}</span>
    </button>
  );

  const reset = { class_id: '', level_group: '', subject_id: '', topic: '' };
  const classId = filters.class_id && filters.class_id !== 'none' ? Number(filters.class_id) : null;
  const subjectsHere = classId ? Object.values(summary.rows.filter((r) => r.class_id === classId).reduce<Record<number, { id: number; name: string; total: number }>>((acc, r) => {
    acc[r.subject_id] = { id: r.subject_id, name: r.subject ?? 'Subject', total: (acc[r.subject_id]?.total ?? 0) + r.total };
    return acc;
  }, {})) : [];
  const topicsHere = classId && filters.subject_id ? summary.rows.filter((r) => r.class_id === classId && String(r.subject_id) === filters.subject_id && r.topic) : [];

  return (
    <Card title="Browse">
      <div className="space-y-0.5">
        {item('All questions', summary.total, !filters.class_id && !filters.level_group, () => onPick(reset))}
        {summary.unsorted > 0 && item('Unsorted — file these', summary.unsorted, filters.class_id === 'none', () => onPick({ ...reset, class_id: 'none' }))}
        {LEVELS.map(([level, label]) => {
          const inLevel = summary.classes.filter((c) => c.level_group === level);
          if (inLevel.length === 0) return null;
          return (
            <div key={level} className="pt-2">
              {item(label, inLevel.reduce((n, c) => n + c.total, 0), filters.level_group === level, () => onPick({ ...reset, level_group: level }))}
              {inLevel.map((c) => (
                <React.Fragment key={c.id}>
                  {item(c.name, c.total, classId === c.id && !filters.subject_id, () => onPick({ ...reset, class_id: String(c.id) }), 1)}
                  {classId === c.id && subjectsHere.map((s) => (
                    <React.Fragment key={s.id}>
                      {item(s.name, s.total, filters.subject_id === String(s.id) && !filters.topic, () => onPick({ ...reset, class_id: String(c.id), subject_id: String(s.id) }), 2)}
                      {filters.subject_id === String(s.id) && topicsHere.map((t) => (
                        <React.Fragment key={t.topic}>{item(t.topic ?? '', t.total, filters.topic === t.topic, () => onPick({ ...reset, class_id: String(c.id), subject_id: String(s.id), topic: t.topic ?? '' }), 3)}</React.Fragment>
                      ))}
                    </React.Fragment>
                  ))}
                </React.Fragment>
              ))}
            </div>
          );
        })}
      </div>
    </Card>
  );
}

const BULK_ACTIONS: [string, string][] = [
  ['set_class', 'Move to class…'],
  ['set_subject', 'Change subject…'],
  ['set_topic', 'Set topic…'],
  ['set_difficulty', 'Set difficulty…'],
  ['ready', 'Mark ready'],
  ['draft', 'Mark draft'],
  ['retired', 'Retire'],
  ['add_to_exam', 'Add to a draft paper…'],
  ['delete', 'Delete'],
];

/** One action applied to the ticked questions, or to every matching one. */
function BulkBar({ count, selection, classes, subjects, onClear, onDone, onError }: {
  count: number;
  selection: { ids: number[] } | { filters: Record<string, string> };
  classes: ClassRow[];
  subjects: Option[];
  onClear: () => void;
  onDone: (m: string) => Promise<void>;
  onError: (e: unknown, f: string) => void;
}) {
  const [action, setAction] = useState('set_class');
  const [value, setValue] = useState('');
  const [exams, setExams] = useState<{ id: number; title: string }[]>([]);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (action !== 'add_to_exam') return;
    fetchApi<{ data: { id: number; title: string; status: string }[] }>('/cbt/exams?status=draft&per_page=100')
      .then((r) => setExams((r.data ?? []).filter((x) => x.status === 'draft')))
      .catch(() => undefined);
  }, [action]);

  const needsValue = ['set_class', 'set_subject', 'set_difficulty', 'add_to_exam'].includes(action);

  async function apply() {
    const status = { ready: 'approved', draft: 'draft', retired: 'retired' }[action];
    const body: Record<string, unknown> = { ...selection, action: status ? 'set_status' : action };
    if (status) body.status = status;
    if (action === 'set_class') body.class_id = Number(value);
    if (action === 'set_subject') body.subject_id = Number(value);
    if (action === 'set_topic') body.topic = value.trim() || null;
    if (action === 'set_difficulty') body.difficulty = value;
    if (action === 'add_to_exam') body.exam_id = Number(value);
    if (action === 'delete' && !window.confirm(`Delete ${count} question(s)? Any already on a paper are retired instead.`)) return;

    setBusy(true);
    try {
      const r = await fetchApi<{ message: string }>('/cbt/questions/bulk', { method: 'POST', body: JSON.stringify(body) });
      setValue('');
      await onDone(r.message);
    } catch (err) {
      onError(err, 'Could not apply that to the selection.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-brand/30 bg-brand/5 px-4 py-3">
      <span className="text-sm font-semibold text-brand">{count} selected</span>
      <select className={`${inputClass} w-52`} value={action} onChange={(e) => { setAction(e.target.value); setValue(''); }} aria-label="Bulk action">
        {BULK_ACTIONS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
      </select>
      {action === 'set_class' && <select className={`${inputClass} w-44`} value={value} onChange={(e) => setValue(e.target.value)} aria-label="Class"><option value="">Choose class…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>}
      {action === 'set_subject' && <select className={`${inputClass} w-44`} value={value} onChange={(e) => setValue(e.target.value)} aria-label="Subject"><option value="">Choose subject…</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>}
      {action === 'set_topic' && <input className={`${inputClass} w-52`} value={value} onChange={(e) => setValue(e.target.value)} placeholder="Topic (blank clears it)" aria-label="Topic" />}
      {action === 'set_difficulty' && <select className={`${inputClass} w-36`} value={value} onChange={(e) => setValue(e.target.value)} aria-label="Difficulty"><option value="">Choose…</option><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select>}
      {action === 'add_to_exam' && <select className={`${inputClass} w-56`} value={value} onChange={(e) => setValue(e.target.value)} aria-label="Paper"><option value="">Choose a draft paper…</option>{exams.map((x) => <option key={x.id} value={x.id}>{x.title}</option>)}</select>}
      <Button variant={action === 'delete' ? 'danger' : 'primary'} disabled={busy || (needsValue && !value)} onClick={apply}>Apply</Button>
      <button type="button" className="text-sm text-ink-muted underline" onClick={onClear}>Clear</button>
    </div>
  );
}

function Groups({ subjects, groups, reload, onDone, onError }: { subjects: { id: number; name: string }[]; groups: Group[]; reload: () => Promise<unknown> } & Handlers) {
  const blank = { id: 0, title: '', subject_id: '', stimulus: '', instructions: '' };
  const [form, setForm] = useState(blank);
  const [busy, setBusy] = useState(false);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const body = JSON.stringify({ title: form.title, subject_id: form.subject_id ? Number(form.subject_id) : null, stimulus: form.stimulus || null, instructions: form.instructions || null });
      const r = await fetchApi<{ message?: string }>(form.id ? `/cbt/question-groups/${form.id}` : '/cbt/question-groups', { method: form.id ? 'PUT' : 'POST', body });
      onDone(r.message ?? 'Saved.');
      setForm(blank);
      await reload();
    } catch (err) { onError(err, 'Could not save the passage.'); } finally { setBusy(false); }
  }

  async function remove(g: Group) {
    if (!window.confirm(`Delete "${g.title}"?`)) return;
    try {
      await fetchApi(`/cbt/question-groups/${g.id}`, { method: 'DELETE' });
      onDone('Deleted.');
    } catch (err) {
      // A passage with questions asks to be confirmed twice (the API says so).
      if (window.confirm(`${errorText(err, 'It has questions.')}\n\nDelete it anyway? Its questions become standalone.`)) {
        try { await fetchApi(`/cbt/question-groups/${g.id}?detach=1`, { method: 'DELETE' }); onDone('Deleted.'); } catch (e2) { onError(e2, 'Could not delete it.'); }
      }
    }
    await reload();
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_24rem]">
      <Card description="A passage (comprehension text, a case study, a data table) shared by several questions. Students see it above each of its questions.">
        {groups.length === 0 ? <Empty>No passages yet.</Empty> : (
          <ul className="divide-y divide-line-soft">
            {groups.map((g) => (
              <li key={g.id} className="flex flex-wrap items-start justify-between gap-2 py-3 text-sm">
                <span><span className="font-medium">{g.title}</span>{g.questions_count !== undefined && <span className="text-ink-muted"> · {g.questions_count} question(s)</span>}<span className="block text-xs text-ink-muted">{(g.stimulus ?? '').slice(0, 120)}</span></span>
                <span className="space-x-2">
                  <button type="button" className="text-brand underline" onClick={() => setForm({ id: g.id, title: g.title, subject_id: g.subject_id ? String(g.subject_id) : '', stimulus: g.stimulus ?? '', instructions: g.instructions ?? '' })}>Edit</button>
                  <button type="button" className="text-negative underline" onClick={() => remove(g)}>Delete</button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <Card title={form.id ? 'Edit passage' : 'New passage'}>
        <form onSubmit={save} className="space-y-3">
          <Field label="Title"><input className={inputClass} required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Comprehension: The Harmattan" /></Field>
          <Field label="Subject"><select className={inputClass} value={form.subject_id} onChange={(e) => setForm({ ...form, subject_id: e.target.value })}><option value="">Any</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
          <Field label="Instructions"><input className={inputClass} value={form.instructions} onChange={(e) => setForm({ ...form, instructions: e.target.value })} placeholder="Read the passage and answer questions 1–5." /></Field>
          <Field label="Passage text"><textarea className={`${inputClass} min-h-[10rem]`} value={form.stimulus} onChange={(e) => setForm({ ...form, stimulus: e.target.value })} /></Field>
          <div className="flex gap-2"><Button type="submit" disabled={busy}>Save</Button>{form.id > 0 && <Button type="button" variant="secondary" onClick={() => setForm(blank)}>Cancel</Button>}</div>
          <p className="text-xs text-ink-muted">Add questions to a passage from the question editor’s “Passage / group” field.</p>
        </form>
      </Card>
    </div>
  );
}

function Images({ media, reload, onDone, onError }: { media: Media[]; reload: () => Promise<unknown> } & Handlers) {
  const [file, setFile] = useState<File | null>(null);
  const [alt, setAlt] = useState('');
  const [caption, setCaption] = useState('');
  const [busy, setBusy] = useState(false);

  async function upload(e: React.FormEvent) {
    e.preventDefault();
    if (!file) return;
    setBusy(true);
    const form = new FormData();
    form.append('file', file);
    form.append('alt_text', alt);
    if (caption) form.append('caption', caption);
    try {
      const r = await uploadApi<{ message: string }>('/cbt/media', form);
      onDone(r.message);
      setFile(null); setAlt(''); setCaption('');
      await reload();
    } catch (err) { onError(err, 'Could not upload the image.'); } finally { setBusy(false); }
  }

  async function remove(m: Media) {
    if (!window.confirm(`Delete image #${m.asset_id}?`)) return;
    try { await fetchApi(`/cbt/media/${m.asset_id}`, { method: 'DELETE' }); onDone('Image deleted.'); await reload(); } catch (err) { onError(err, 'Could not delete it.'); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
      <Card description="Upload a diagram once and use it on any question. Location data in phone photos is removed on upload.">
        {media.length === 0 ? <Empty>No images yet.</Empty> : (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            {media.map((m) => (
              <figure key={m.asset_id} className="rounded-lg border border-line-soft p-2 text-xs">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={m.thumbnail_url ?? m.url} alt={m.alt_text} className="h-28 w-full rounded bg-white object-contain" />
                <figcaption className="mt-1"><span className="font-mono">#{m.asset_id}</span> {m.alt_text}</figcaption>
                <button type="button" className="mt-1 text-negative underline" onClick={() => remove(m)}>Delete</button>
              </figure>
            ))}
          </div>
        )}
      </Card>
      <Card title="Upload an image">
        <form onSubmit={upload} className="space-y-3">
          <Field label="Picture (JPEG, PNG, WebP or GIF, up to 5 MB)"><input type="file" accept="image/jpeg,image/png,image/webp,image/gif" required onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="block text-sm" /></Field>
          <Field label="Describe it (read aloud to a student using a screen reader)"><input className={inputClass} required minLength={3} maxLength={255} value={alt} onChange={(e) => setAlt(e.target.value)} placeholder="Cross-section of a hibiscus flower" /></Field>
          <Field label="Caption (optional)"><input className={inputClass} maxLength={255} value={caption} onChange={(e) => setCaption(e.target.value)} /></Field>
          <Button type="submit" disabled={busy || !file}>Upload</Button>
        </form>
      </Card>
    </div>
  );
}

/**
 * Bulk import from a spreadsheet: one multiple-choice question per row,
 * pasted as CSV. Parsed here into the JSON the API takes, and shown before
 * anything is saved.
 */
function Import({ classes, onDone, onError }: { classes: ClassRow[] } & Handlers) {
  const [classId, setClassId] = useState('');
  const [subjectId, setSubjectId] = useState('');
  const subjects = useClassSubjects(classId || null);
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);

  const rows = parseCsv(text).filter((r) => r.length >= 3);
  const header = rows[0]?.map((h) => h.toLowerCase().trim()) ?? [];
  const hasHeader = header.includes('question');
  const col = (name: string, fallback: number) => (hasHeader ? header.indexOf(name) : fallback);
  const data = (hasHeader ? rows.slice(1) : rows).map((r) => {
    const options: Record<string, string> = {};
    ['a', 'b', 'c', 'd', 'e'].forEach((k, i) => { const v = r[col(k, i + 1)]; if (v?.trim()) options[k.toUpperCase()] = v.trim(); });
    return {
      question: (r[col('question', 0)] ?? '').trim(),
      options,
      correct_answer: (r[col('answer', 5)] ?? '').trim().toUpperCase(),
      topic: (r[col('topic', 6)] ?? '').trim() || undefined,
      difficulty: (['easy', 'medium', 'hard'].includes((r[col('difficulty', 7)] ?? '').trim().toLowerCase()) ? (r[col('difficulty', 7)] ?? '').trim().toLowerCase() : undefined),
      marks: Number(r[col('marks', 8)]) || undefined,
      question_type: 'multiple_choice',
    };
  }).filter((q) => q.question);
  const bad = data.filter((q) => !q.options[q.correct_answer]);

  async function submit() {
    setBusy(true);
    try {
      const r = await fetchApi<{ message?: string; imported?: number; skipped?: unknown[] }>('/cbt/questions/import', { method: 'POST', body: JSON.stringify({ class_id: Number(classId), subject_id: Number(subjectId), questions: data }) });
      onDone(r.message ?? `Imported ${r.imported ?? data.length} question(s).`);
      setText('');
    } catch (err) { onError(err, 'Could not import.'); } finally { setBusy(false); }
  }

  return (
    <Card title="Import multiple-choice questions" description="Paste from Excel or a CSV file: question, A, B, C, D, E, answer, topic, difficulty, marks. A header row with those names is optional. Up to 500 at a time.">
      <div className="mb-3 grid max-w-xl gap-3 sm:grid-cols-2">
        <Field label="Class"><select className={inputClass} value={classId} onChange={(e) => { setClassId(e.target.value); setSubjectId(''); }}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
        <Field label="Subject"><select className={inputClass} value={subjectId} disabled={!classId} onChange={(e) => setSubjectId(e.target.value)}><option value="">Choose…</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
      </div>
      <textarea className={`${inputClass} min-h-[12rem] font-mono text-xs`} value={text} onChange={(e) => setText(e.target.value)} placeholder={'question,A,B,C,D,E,answer,topic,difficulty,marks\n"What is 2 + 2?",3,4,5,6,,B,Addition,easy,1'} />
      {data.length > 0 && (
        <div className="mt-3 text-sm">
          <p>{data.length} question(s) read{bad.length ? <span className="text-negative"> · {bad.length} with an answer that is not one of their options</span> : ''}.</p>
          <ol className="mt-2 max-h-48 list-decimal overflow-y-auto pl-5 text-xs text-ink-muted">{data.slice(0, 20).map((q, i) => <li key={i} className={q.options[q.correct_answer] ? '' : 'text-negative'}>{q.question.slice(0, 90)} — answer {q.correct_answer || '?'}</li>)}</ol>
        </div>
      )}
      <Button className="mt-3" disabled={busy || !classId || !subjectId || data.length === 0 || bad.length > 0 || data.length > 500} onClick={submit}>Import {data.length || ''} question(s)</Button>
    </Card>
  );
}

/** Minimal RFC 4180 CSV: quoted fields, escaped quotes, commas or tabs (Excel paste). */
function parseCsv(text: string): string[][] {
  const sep = text.includes('\t') && !text.includes(',') ? '\t' : ',';
  const out: string[][] = [];
  let row: string[] = [];
  let field = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { field += '"'; i++; } else if (c === '"') quoted = false; else field += c;
    } else if (c === '"') quoted = true;
    else if (c === sep) { row.push(field); field = ''; }
    else if (c === '\n' || c === '\r') { if (c === '\r' && text[i + 1] === '\n') i++; row.push(field); out.push(row); row = []; field = ''; }
    else field += c;
  }
  if (field || row.length) { row.push(field); out.push(row); }
  return out;
}
