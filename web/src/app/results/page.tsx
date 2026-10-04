'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi, uploadApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  Table,
  inputClass,
} from '@/components/Page';
import PublishResults from './PublishResults';
import CourseworkCa from './CourseworkCa';

/**
 * Scores, the broadsheet, and the AI comment queue.
 *
 * The old page invented three children with invented marks, "parsed" a pasted
 * CSV into local state that no endpoint ever saw, and displayed a pre-written
 * AI comment about a student who does not exist. Nothing was saved anywhere.
 *
 * The CA weighting shown here is the school's own active scheme, not a fixed
 * 20/20/60: the API rejects a mark above the scheme's ceiling, so the form has
 * to know what that ceiling is rather than letting a teacher type 60 into a
 * field worth 40.
 */

type Tab = 'broadsheet' | 'enter' | 'coursework' | 'import' | 'comments' | 'publish';

interface CaPart {
  key: string;
  name: string;
  max: number;
  kind: 'ca' | 'exam';
}

interface CaScheme {
  name: string;
  first_ca_weight: number;
  second_ca_weight: number;
  exam_weight: number;
  /** Every part of the school's scheme, any number of CAs. */
  components?: CaPart[];
}

interface BroadsheetScore {
  subject_id?: number;
  subject?: string;
  subject_name?: string | null;
  component_scores?: Record<string, number> | null;
  first_ca?: number;
  second_ca?: number;
  exam?: number;
  total_score?: number;
  grade?: string | null;
}

interface BroadsheetRow {
  student_id: number;
  student_name: string;
  admission_number: string | null;
  scores: BroadsheetScore[];
  total_score: number;
  average: number;
  position_in_class: number | null;
}

interface Broadsheet {
  class_id: number;
  term_id: number;
  ca_scheme: CaScheme;
  broadsheet: BroadsheetRow[];
}

interface PendingComment {
  score_entry_id: number;
  student_name: string;
  class: string | null;
  subject: string;
  term: string;
  grade: string | null;
  comment: string | null;
}

interface Option {
  id: number;
  name: string;
  is_current?: boolean;
}

/**
 * Display only — the role cookie is readable on purpose and the API enforces
 * who may generate and release. Teachers share this page and are not offered
 * the publishing tab.
 */
function isSchoolAdmin(): boolean {
  if (typeof document === 'undefined') return false;
  return /(?:^|;\s*)sp_role=school_admin(?:;|$)/.test(document.cookie);
}

export default function ResultsPage() {
  const [tab, setTab] = useState<Tab>('broadsheet');
  const [canPublish] = useState(isSchoolAdmin);

  const [classes, setClasses] = useState<Option[]>([]);
  const [terms, setTerms] = useState<Option[]>([]);
  const [subjects, setSubjects] = useState<Option[]>([]);
  const [classId, setClassId] = useState('');
  const [termId, setTermId] = useState('');

  const [sheet, setSheet] = useState<Broadsheet | null>(null);
  const [pending, setPending] = useState<PendingComment[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadingSheet, setLoadingSheet] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Single score entry
  const [studentId, setStudentId] = useState('');
  const [subjectId, setSubjectId] = useState('');
  // A mark per part of the school's scheme, keyed by part.
  const [marks, setMarks] = useState<Record<string, string>>({});

  // CSV import
  const [csvFile, setCsvFile] = useState<File | null>(null);
  const [importSubjectId, setImportSubjectId] = useState('');

  // Comment review
  const [editing, setEditing] = useState<number | null>(null);
  const [draft, setDraft] = useState('');

  useEffect(() => {
    (async () => {
      try {
        const [cls, trm, subj] = await Promise.all([
          fetchApi<{ data?: Option[] }>('/classes'),
          fetchApi<{ data?: Option[] }>('/terms'),
          fetchApi<{ data?: Option[] }>('/subjects').catch(() => null),
        ]);

        const classList = cls.data ?? [];
        const termList = trm.data ?? [];

        setClasses(classList);
        setTerms(termList);
        setSubjects(subj?.data ?? []);

        if (classList[0]) setClassId(String(classList[0].id));

        const current = termList.find((t) => t.is_current) ?? termList[0];
        if (current) setTermId(String(current.id));
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load classes and terms.');
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  /** Fetchers return data; callers own the state. */
  const fetchSheet = useCallback(
    async () =>
      fetchApi<Broadsheet>(`/assessment/broadsheet?class_id=${classId}&term_id=${termId}`),
    [classId, termId],
  );

  const fetchPending = useCallback(
    async () =>
      (await fetchApi<{ data: PendingComment[] }>('/assessment/ai-comments/pending')).data ?? [],
    [],
  );

  const loadSheet = useCallback(async () => {
    setSheet(await fetchSheet());
  }, [fetchSheet]);

  const loadPending = useCallback(async () => {
    setPending(await fetchPending());
  }, [fetchPending]);

  useEffect(() => {
    if (!classId || !termId) return;

    let cancelled = false;

    (async () => {
      setLoadingSheet(true);
      setError(null);

      try {
        const next = await fetchSheet();
        if (!cancelled) setSheet(next);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load the broadsheet.');
          setSheet(null);
        }
      } finally {
        if (!cancelled) setLoadingSheet(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [classId, termId, fetchSheet]);

  useEffect(() => {
    if (tab !== 'comments') return;

    let cancelled = false;

    (async () => {
      try {
        const queue = await fetchPending();
        if (!cancelled) setPending(queue);
      } catch {
        // A teacher with no queue is normal; the tab shows its empty state.
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [tab, fetchPending]);

  async function saveScore(e: React.FormEvent) {
    e.preventDefault();
    await run(async () => {
      await fetchApi('/assessment/score', {
        method: 'POST',
        body: JSON.stringify({
          term_id: Number(termId),
          student_id: Number(studentId),
          subject_id: Number(subjectId),
          // Parts left blank keep any mark already entered.
          components: Object.fromEntries(
            Object.entries(marks).filter(([, v]) => v !== '').map(([k, v]) => [k, Number(v)]),
          ),
        }),
      });

      setNotice('Score saved.');
      setMarks({});
      await loadSheet();
    });
  }

  async function importScores(e: React.FormEvent) {
    e.preventDefault();

    if (!csvFile) return;

    await run(async () => {
      const form = new FormData();
      form.append('file', csvFile);
      form.append('term_id', termId);
      // The endpoint needs the subject; the form never sent it, so every
      // import from this screen was refused.
      form.append('subject_id', importSubjectId);

      // Multipart, not a JSON array: the endpoint takes a real uploaded file.
      const res = await uploadApi<{ message?: string; imported_count?: number; errors?: string[] }>(
        '/teacher/import-scores',
        form,
      );

      setNotice(res.message ?? `Imported ${res.imported_count ?? 0} score(s).`);
      setCsvFile(null);
      await loadSheet();
    });
  }

  async function review(id: number, action: 'approve' | 'edit' | 'reject', edited?: string) {
    await run(async () => {
      await fetchApi(`/assessment/score/${id}/review-comment`, {
        method: 'POST',
        body: JSON.stringify(action === 'edit' ? { action, edited_comment: edited } : { action }),
      });

      setEditing(null);
      setDraft('');
      await loadPending();
    });
  }

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      await action();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That action could not be completed.');
    } finally {
      setBusy(false);
    }
  }

  const scheme = sheet?.ca_scheme;
  const parts: CaPart[] =
    scheme?.components ??
    (scheme
      ? [
          { key: 'ca1', name: '1st CA', max: scheme.first_ca_weight, kind: 'ca' },
          { key: 'ca2', name: '2nd CA', max: scheme.second_ca_weight, kind: 'ca' },
          { key: 'exam', name: 'Exam', max: scheme.exam_weight, kind: 'exam' },
        ]
      : []);

  // Subject columns are taken from whatever the broadsheet returned, so a
  // school with nine subjects is not truncated to a fixed three.
  const subjectColumns = Array.from(
    new Set((sheet?.broadsheet ?? []).flatMap((row) => row.scores.map((s) => s.subject_name ?? s.subject ?? ''))),
  ).filter(Boolean);

  const students = (sheet?.broadsheet ?? []).map((row) => ({
    id: row.student_id,
    label: `${row.student_name}${row.admission_number ? ` (${row.admission_number})` : ''}`,
  }));

  return (
    <PageShell
      title="Results"
      subtitle="Enter scores, read the broadsheet, review AI comments, then generate and release report cards."
    >
      <ErrorNote message={error} />

      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {loading ? (
        <Loading />
      ) : classes.length === 0 || terms.length === 0 ? (
        <Empty>
          Set up classes and terms before entering results. Both live under the setup checklist.
        </Empty>
      ) : (
        <div className="space-y-6">
          <Card title="Class and term">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Class">
                <select value={classId} onChange={(e) => setClassId(e.target.value)} className={inputClass}>
                  {classes.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Term">
                <select value={termId} onChange={(e) => setTermId(e.target.value)} className={inputClass}>
                  {terms.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.name}
                      {t.is_current ? ' (current)' : ''}
                    </option>
                  ))}
                </select>
              </Field>
            </div>

            {scheme && (
              <p className="mt-3 text-xs text-ink-muted">
                Marking scheme <strong>{scheme.name}</strong>: {parts.map((p) => `${p.name} out of ${p.max}`).join(', ')}.
              </p>
            )}
          </Card>

          <nav className="flex flex-wrap gap-2" aria-label="Results sections">
            {(
              [
                ['broadsheet', 'Broadsheet'],
                ['enter', 'Enter a score'],
                ['coursework', 'CA from coursework'],
                ['import', 'Import scores'],
                ['comments', 'Comments awaiting review'],
                ...(canPublish ? [['publish', 'Report cards & release']] : []),
              ] as [Tab, string][]
            ).map(([key, label]) => (
              <button
                key={key}
                onClick={() => setTab(key)}
                aria-current={tab === key ? 'page' : undefined}
                className={`rounded-lg px-3.5 py-2 text-sm font-medium transition ${
                  tab === key
                    ? 'bg-brand text-white'
                    : 'border border-line text-ink hover:bg-surface-sunken'
                }`}
              >
                {label}
                {key === 'comments' && pending.length > 0 && (
                  <span className="ml-2 rounded-full bg-caution-soft px-2 text-xs text-caution">
                    {pending.length}
                  </span>
                )}
              </button>
            ))}
          </nav>

          {tab === 'broadsheet' && (
            <Card title="Broadsheet">
              {loadingSheet ? (
                <Loading />
              ) : !sheet || sheet.broadsheet.length === 0 ? (
                <Empty>No scores entered for this class and term yet.</Empty>
              ) : (
                <Table
                  columns={['Student', ...subjectColumns, 'Total', 'Average', 'Position']}
                >
                  {sheet.broadsheet.map((row) => (
                    <tr key={row.student_id}>
                      <Cell className="font-medium text-brand">
                        {row.student_name}
                        {row.admission_number && (
                          <span className="block text-xs text-ink-muted">{row.admission_number}</span>
                        )}
                      </Cell>

                      {subjectColumns.map((subject) => {
                        const score = row.scores.find((s) => (s.subject_name ?? s.subject) === subject);

                        return (
                          <Cell key={subject}>
                            {score ? (
                              <>
                                {score.total_score ?? '—'}
                                {score.grade && (
                                  <span className="ml-1 text-xs text-ink-muted">{score.grade}</span>
                                )}
                              </>
                            ) : (
                              <span className="text-line">—</span>
                            )}
                          </Cell>
                        );
                      })}

                      <Cell>{row.total_score}</Cell>
                      <Cell>{row.average}%</Cell>
                      <Cell>{row.position_in_class ?? '—'}</Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>
          )}

          {tab === 'enter' && (
            <Card
              title="Enter a score"
              description="Saving again for the same student, subject and term updates that entry rather than adding a second one."
            >
              <form onSubmit={saveScore} className="grid gap-4 sm:grid-cols-2">
                <Field label="Student">
                  <select
                    required
                    value={studentId}
                    onChange={(e) => setStudentId(e.target.value)}
                    className={inputClass}
                  >
                    <option value="">Select a student…</option>
                    {students.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.label}
                      </option>
                    ))}
                  </select>
                </Field>

                <Field label="Subject">
                  <select
                    required
                    value={subjectId}
                    onChange={(e) => setSubjectId(e.target.value)}
                    className={inputClass}
                  >
                    <option value="">Select a subject…</option>
                    {subjects.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name}
                      </option>
                    ))}
                  </select>
                </Field>

                {parts.map((part) => (
                  <Field key={part.key} label={`${part.name} (out of ${part.max})`}>
                    <input
                      type="number"
                      step="0.01"
                      min={0}
                      max={part.max}
                      value={marks[part.key] ?? ''}
                      onChange={(e) => setMarks({ ...marks, [part.key]: e.target.value })}
                      className={inputClass}
                    />
                  </Field>
                ))}

                <div className="sm:col-span-2">
                  <Button type="submit" disabled={busy || !studentId || !subjectId}>
                    {busy ? 'Saving…' : 'Save score'}
                  </Button>
                </div>
              </form>
            </Card>
          )}

          {tab === 'coursework' && <CourseworkCa classId={classId} termId={termId} subjects={subjects} parts={parts} />}

          {tab === 'import' && (
            <Card
              title="Import scores"
              description="Upload the CSV exported from this school's own score sheet. The file is uploaded as a file — not pasted text — because that is what the endpoint reads."
            >
              <form onSubmit={importScores} className="space-y-4">
                <Field label="Subject">
                  <select required value={importSubjectId} onChange={(e) => setImportSubjectId(e.target.value)} className={inputClass}>
                    <option value="">Select a subject…</option>
                    {subjects.map((s) => (
                      <option key={s.id} value={s.id}>{s.name}</option>
                    ))}
                  </select>
                </Field>

                {classId && (
                  <a
                    href={`/api/backend/teacher/score-template?class_id=${classId}`}
                    download
                    className="inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-1.5 text-xs font-semibold text-brand transition hover:bg-surface-sunken"
                  >
                    ⬇ Download a score sheet for this class
                  </a>
                )}

                <Field label="CSV file">
                  <input
                    type="file"
                    accept=".csv,text/csv"
                    onChange={(e) => setCsvFile(e.target.files?.[0] ?? null)}
                    className={inputClass}
                  />
                </Field>

                <Button type="submit" disabled={busy || !csvFile || !importSubjectId}>
                  {busy ? 'Uploading…' : 'Import'}
                </Button>
              </form>

              <p className="mt-4 text-xs text-ink-muted">
                The score sheet lists every pupil in the class with a column for each part of your
                marking scheme ({parts.map((p) => p.name).join(', ')}). Fill it in, keep the headings, and
                upload it here. Columns are matched by heading, so their order does not matter.
              </p>
            </Card>
          )}

          {tab === 'publish' && canPublish && classId && termId && (
            <PublishResults
              key={`${classId}:${termId}`}
              classId={classId}
              termId={termId}
              className={classes.find((c) => String(c.id) === classId)?.name ?? 'this class'}
              termName={terms.find((t) => String(t.id) === termId)?.name ?? 'this term'}
              onReviewComments={() => setTab('comments')}
            />
          )}

          {tab === 'comments' && (
            <Card
              title="Comments awaiting review"
              description="An AI-written remark never reaches a report card until a teacher approves or edits it here."
            >
              {pending.length === 0 ? (
                <Empty>Nothing waiting for review.</Empty>
              ) : (
                <ul className="space-y-3">
                  {pending.map((item) => (
                    <li
                      key={item.score_entry_id}
                      className="rounded-lg border border-line-soft bg-surface-sunken p-4"
                    >
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                          <p className="text-sm font-semibold text-brand">
                            {item.student_name}
                            {item.class && <span className="font-normal text-ink-muted"> · {item.class}</span>}
                          </p>
                          <p className="text-xs text-ink-muted">
                            {item.subject} · {item.term}
                            {item.grade && ` · ${item.grade}`}
                          </p>
                        </div>
                        <Badge tone="amber">Pending</Badge>
                      </div>

                      {editing === item.score_entry_id ? (
                        <div className="mt-3 space-y-2">
                          <textarea
                            rows={3}
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            className={inputClass}
                            aria-label={`Edit the comment for ${item.student_name}`}
                          />
                          <div className="flex justify-end gap-2">
                            <Button
                              variant="secondary"
                              onClick={() => {
                                setEditing(null);
                                setDraft('');
                              }}
                            >
                              Cancel
                            </Button>
                            <Button
                              disabled={busy || !draft.trim()}
                              onClick={() => review(item.score_entry_id, 'edit', draft.trim())}
                            >
                              Save &amp; approve
                            </Button>
                          </div>
                        </div>
                      ) : (
                        <>
                          <p className="mt-2 text-sm italic text-ink">
                            {item.comment ?? 'No comment text was generated.'}
                          </p>
                          <div className="mt-3 flex flex-wrap justify-end gap-2">
                            <Button
                              variant="secondary"
                              disabled={busy}
                              onClick={() => {
                                setEditing(item.score_entry_id);
                                setDraft(item.comment ?? '');
                              }}
                            >
                              Edit
                            </Button>
                            <Button
                              variant="danger"
                              disabled={busy}
                              onClick={() => review(item.score_entry_id, 'reject')}
                            >
                              Reject
                            </Button>
                            <Button disabled={busy} onClick={() => review(item.score_entry_id, 'approve')}>
                              Approve
                            </Button>
                          </div>
                        </>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}
        </div>
      )}
    </PageShell>
  );
}
