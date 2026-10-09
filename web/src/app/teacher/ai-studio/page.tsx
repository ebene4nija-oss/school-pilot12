'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, ErrorNote, Field, PageShell, inputClass } from '@/components/Page';
import { RichText } from '@/components/RichContent';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * AI Studio for teachers (doc §7.10): a lesson plan, draft CBT questions,
 * homework ideas, a translation for parents, and a summary of one student's
 * released results before a parent meeting.
 *
 * Everything here is a draft written by Claude for the teacher to check.
 * Questions reach the bank only when the teacher ticks them; nothing goes to
 * a student or a report card from this page. A student summary sends the
 * model marks and attendance only — never the child's name.
 */

type Tab = 'plan' | 'questions' | 'homework' | 'translate' | 'summary';
interface Plan { duration_minutes?: number; objectives?: string[]; materials?: string[]; introduction?: string; activities?: { title: string; minutes: number; description: string }[]; assessment?: string; homework?: string; differentiation?: string; stub?: boolean }
interface Q { question: string; options: Record<string, string>; correct_answer: string; topic?: string; difficulty?: string; explanation?: string }
interface Idea { title: string; instructions: string; type?: string; minutes?: number; marks?: number }
interface Summary { summary: string; strengths?: string[]; concerns?: string[]; recommendations?: string[]; stub?: boolean }

const LANGUAGES = ['Yoruba', 'Igbo', 'Hausa', 'Pidgin', 'French'];

export default function AiStudio() {
  const [tab, setTab] = useState<Tab>('plan');
  const [subjects, setSubjects] = useState<{ id: number; name: string }[]>([]);
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [base, setBase] = useState({ subject: '', topic: '', class_level: '' });
  const [plan, setPlan] = useState<Plan | null>(null);
  const [qs, setQs] = useState<{ count: string; difficulty: string; list: Q[]; keep: Record<number, boolean>; stub?: boolean }>({ count: '5', difficulty: 'medium', list: [], keep: {} });
  const [ideas, setIdeas] = useState<{ list: Idea[]; stub?: boolean }>({ list: [] });
  const [tr, setTr] = useState({ text: '', language: 'Yoruba', out: '', notes: '' });
  const [student, setStudent] = useState<PickedStudent | null>(null);
  const [summary, setSummary] = useState<Summary | null>(null);

  useEffect(() => {
    fetchApi<{ data: { id: number; name: string }[] }>('/subjects').then((r) => setSubjects(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string }[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined);
  }, []);

  async function call<T>(fn: () => Promise<T>, then: (r: T) => void) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try { then(await fn()); } catch (err) { setError(errorText(err, 'The AI could not do that just now.')); } finally { setBusy(false); }
  }
  const post = <T,>(path: string, body: unknown) => fetchApi<T>(path, { method: 'POST', body: JSON.stringify(body) });

  async function saveQuestions() {
    const subjectId = subjects.find((s) => s.name === base.subject)?.id;
    // The bank is filed by class: the class these were drafted for.
    const classId = classes.find((c) => c.name === base.class_level)?.id;
    const chosen = qs.list.filter((_, i) => qs.keep[i]);
    if (!classId) { setError('Choose the class so the questions can be filed under it.'); return; }
    if (!subjectId) { setError('Choose the subject from the list so the questions can be filed under it.'); return; }
    await call(() => post<{ message?: string }>('/cbt/questions/import', { class_id: classId, subject_id: subjectId, questions: chosen.map((q) => ({ ...q, question_type: 'multiple_choice' })) }), (r) => setNotice(`${r.message ?? 'Saved.'} They are in the Question bank, ready to add to a paper.`));
  }

  const head = (
    <div className="mb-4 grid gap-3 sm:grid-cols-3">
      <Field label="Subject"><input className={inputClass} list="ai-subjects" required value={base.subject} onChange={(e) => setBase({ ...base, subject: e.target.value })} /></Field>
      <datalist id="ai-subjects">{subjects.map((s) => <option key={s.id} value={s.name} />)}</datalist>
      <Field label="Topic"><input className={inputClass} required value={base.topic} onChange={(e) => setBase({ ...base, topic: e.target.value })} placeholder="e.g. Simultaneous equations" /></Field>
      <Field label="Class"><select className={inputClass} value={base.class_level} onChange={(e) => setBase({ ...base, class_level: e.target.value })}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.name}>{c.name}</option>)}</select></Field>
    </div>
  );
  const stubNote = (stub?: boolean) => stub && <p className="mb-3 rounded-lg bg-caution-soft px-3 py-2 text-xs text-caution">Placeholder text: no AI key is set up for this school yet, so nothing was generated.</p>;
  const ready = base.subject && base.topic;

  return (
    <PageShell title="AI Studio" subtitle="Drafts written by AI for you to check and edit. Nothing here reaches a student until you put it there.">
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['plan', 'Lesson plan'], ['questions', 'Test questions'], ['homework', 'Homework ideas'], ['translate', 'Translate for parents'], ['summary', 'Student summary']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setError(null); setNotice(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {tab === 'plan' && (
        <Card>
          {head}
          <Button disabled={busy || !ready || !base.class_level} onClick={() => call(() => post<{ lesson_plan: Plan }>('/ai/lesson-plan', base), (r) => setPlan(r.lesson_plan))}>{busy ? 'Writing…' : 'Draft a lesson plan'}</Button>
          {plan && (
            <article className="mt-5 space-y-3 text-sm print:mt-0">
              {stubNote(plan.stub)}
              <h2 className="font-headline text-lg font-semibold text-brand">{base.topic} — {base.class_level} {base.subject}{plan.duration_minutes ? ` · ${plan.duration_minutes} min` : ''}</h2>
              {plan.objectives && <section><p className="font-semibold">Objectives</p><ul className="list-disc pl-5">{plan.objectives.map((o) => <li key={o}>{o}</li>)}</ul></section>}
              {plan.materials && <p><span className="font-semibold">Materials: </span>{plan.materials.join(', ')}</p>}
              {plan.introduction && <p><span className="font-semibold">Introduction: </span>{plan.introduction}</p>}
              {plan.activities && <section><p className="font-semibold">Activities</p><ol className="list-decimal pl-5">{plan.activities.map((a, i) => <li key={i}><span className="font-medium">{a.title}</span> ({a.minutes} min) — {a.description}</li>)}</ol></section>}
              {plan.assessment && <p><span className="font-semibold">Assessment: </span>{plan.assessment}</p>}
              {plan.homework && <p><span className="font-semibold">Homework: </span>{plan.homework}</p>}
              {plan.differentiation && <p><span className="font-semibold">Support and extension: </span>{plan.differentiation}</p>}
              <Button variant="secondary" className="print:hidden" onClick={() => window.print()}>Print</Button>
            </article>
          )}
        </Card>
      )}

      {tab === 'questions' && (
        <Card>
          {head}
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <Field label="How many"><input className={`${inputClass} w-20`} type="number" min={1} max={20} value={qs.count} onChange={(e) => setQs({ ...qs, count: e.target.value })} /></Field>
            <Field label="Difficulty"><select className={inputClass} value={qs.difficulty} onChange={(e) => setQs({ ...qs, difficulty: e.target.value })}><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></Field>
            <Button disabled={busy || !ready} onClick={() => call(() => post<{ exam: { questions: Q[]; stub?: boolean } }>('/ai/exam-generation', { ...base, count: Number(qs.count), difficulty: qs.difficulty }), (r) => setQs({ ...qs, list: r.exam.questions, keep: {}, stub: r.exam.stub }))}>{busy ? 'Writing…' : 'Draft questions'}</Button>
          </div>
          {stubNote(qs.stub)}
          {qs.list.length > 0 && (
            <>
              <p className="mb-2 text-sm text-ink-muted">Read each one. Tick the questions you would set, then save them to the bank — you can edit them there.</p>
              <ol className="space-y-3">
                {qs.list.map((q, i) => (
                  <li key={i} className="rounded-lg border border-line-soft p-3 text-sm">
                    <label className="flex gap-2"><input type="checkbox" checked={!!qs.keep[i]} onChange={(e) => setQs({ ...qs, keep: { ...qs.keep, [i]: e.target.checked } })} /><span className="font-medium"><RichText content={q.question} contentFormat="latex" /></span></label>
                    <ul className="mt-2 grid gap-1 pl-6 sm:grid-cols-2">{Object.entries(q.options).map(([k, v]) => <li key={k} className={k === q.correct_answer ? 'font-semibold text-positive' : ''}>{k}. <RichText content={v} contentFormat="latex" />{k === q.correct_answer && ' ✓'}</li>)}</ul>
                    {q.explanation && <p className="mt-1 pl-6 text-xs text-ink-muted">{q.explanation}</p>}
                  </li>
                ))}
              </ol>
              <Button className="mt-3" variant="accent" disabled={busy || !Object.values(qs.keep).some(Boolean)} onClick={saveQuestions}>Save {Object.values(qs.keep).filter(Boolean).length} to the Question bank</Button>
            </>
          )}
        </Card>
      )}

      {tab === 'homework' && (
        <Card>
          {head}
          <Button disabled={busy || !ready} onClick={() => call(() => post<{ homework_ideas: { ideas: Idea[]; stub?: boolean } }>('/ai/homework-ideas', base), (r) => setIdeas({ list: r.homework_ideas.ideas ?? [], stub: r.homework_ideas.stub }))}>{busy ? 'Thinking…' : 'Suggest homework'}</Button>
          <div className="mt-4">{stubNote(ideas.stub)}</div>
          <ul className="space-y-3">
            {ideas.list.map((i, n) => (
              <li key={n} className="rounded-lg border border-line-soft p-3 text-sm">
                <p className="font-medium">{i.title} {i.type && <Badge>{i.type}</Badge>}</p>
                <p className="mt-1 whitespace-pre-wrap">{i.instructions}</p>
                <p className="mt-1 text-xs text-ink-muted">{[i.minutes && `${i.minutes} min`, i.marks && `${i.marks} marks`].filter(Boolean).join(' · ')}</p>
                <button type="button" className="mt-1 text-xs text-brand underline" onClick={() => { void navigator.clipboard?.writeText(`${i.title}\n\n${i.instructions}`); setNotice('Copied. Paste it into Set homework.'); }}>Copy</button>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {tab === 'translate' && (
        <Card description="For a note to parents or simpler instructions. Have a fluent speaker check anything important before it is sent.">
          <div className="grid gap-4 lg:grid-cols-2">
            <div className="space-y-3">
              <Field label="Your text (English)"><textarea className={`${inputClass} min-h-[10rem]`} maxLength={8000} value={tr.text} onChange={(e) => setTr({ ...tr, text: e.target.value })} /></Field>
              <Field label="Into"><select className={inputClass} value={tr.language} onChange={(e) => setTr({ ...tr, language: e.target.value })}>{LANGUAGES.map((l) => <option key={l}>{l}</option>)}</select></Field>
              <Button disabled={busy || !tr.text.trim()} onClick={() => call(() => post<{ translation: { translated_text: string; notes?: string } }>('/ai/translate', { text: tr.text, target_language: tr.language }), (r) => setTr({ ...tr, out: r.translation.translated_text, notes: r.translation.notes ?? '' }))}>{busy ? 'Translating…' : 'Translate'}</Button>
            </div>
            <div>
              <Field label={`${tr.language} (draft)`}><textarea className={`${inputClass} min-h-[10rem]`} value={tr.out} onChange={(e) => setTr({ ...tr, out: e.target.value })} /></Field>
              {tr.notes && <p className="mt-2 text-xs text-ink-muted">Check: {tr.notes}</p>}
            </div>
          </div>
        </Card>
      )}

      {tab === 'summary' && (
        <Card description="From this student’s released results and attendance, for a parent meeting. The AI is sent their marks, not their name.">
          <div className="mb-3 max-w-md"><Field label="Student (one you teach)"><StudentPicker value={student} onChange={(s) => { setStudent(s); setSummary(null); }} /></Field></div>
          <Button disabled={busy || !student} onClick={() => call(() => fetchApi<{ summary: Summary }>(`/ai/performance-summary/${student?.id}`), (r) => setSummary(r.summary))}>{busy ? 'Reading the results…' : 'Summarise'}</Button>
          {summary && (
            <div className="mt-4 space-y-3 text-sm">
              {stubNote(summary.stub)}
              <p className="font-medium">{student?.name}</p>
              <p>{summary.summary}</p>
              {!!summary.strengths?.length && <div><p className="font-semibold text-positive">Strengths</p><ul className="list-disc pl-5">{summary.strengths.map((s) => <li key={s}>{s}</li>)}</ul></div>}
              {!!summary.concerns?.length && <div><p className="font-semibold text-caution">Concerns</p><ul className="list-disc pl-5">{summary.concerns.map((s) => <li key={s}>{s}</li>)}</ul></div>}
              {!!summary.recommendations?.length && <div><p className="font-semibold">Next steps</p><ul className="list-disc pl-5">{summary.recommendations.map((s) => <li key={s}>{s}</li>)}</ul></div>}
            </div>
          )}
        </Card>
      )}
    </PageShell>
  );
}
