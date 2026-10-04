'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { roleFromCookie } from '@/components/Sidebar';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';

/**
 * Learning: the school's library of materials, lesson plans, and how
 * learning is going (TASK 14). Staff publish and plan; students and parents
 * see what is shared with them and their own learning record.
 *
 * Materials are shared with a class, an arm or a section, never "everyone":
 * the API refuses a teacher who shares outside the classes they teach.
 * Downloads go through SchoolPilot (and resume on a weak connection); there
 * is no public link to a file.
 */

type Tab = 'materials' | 'plans' | 'overview' | 'settings';

interface Material { id: number; title: string; description: string | null; type: string; file_name: string | null; size_bytes: number | null; class: string | null; subject: string | null; topic: string | null; status: string; uploaded_by: string | null; mine: boolean; published_at: string | null; review_note: string | null; url: string | null }
interface Plan { id: number; topic: string; subtopic: string | null; week: number | null; status: string; teacher: string | null; mine: boolean; review_note: string | null }
interface ClassOption { id: number; name: string; arms?: { id: number; name: string }[] }
interface Subject { id: number; name: string }
interface Overview {
  scope: { sections: string[] | null; teacher_only: boolean; from: string; to: string; term: string | null };
  metrics: Record<string, number | null>;
  needs_support: { student_id: number; name: string | null; class: string | null; missed_assignments: number; missed_virtual_classes: number }[];
}
interface Record_ {
  upcoming_classes: { id: number; title: string; subject: string | null; scheduled_for: string }[];
  materials: { id: number; title: string; type: string; subject: string | null; topic: string | null }[] | null;
  assignments: { counts: Record<string, number>; pending: { id: number; title: string; subject: string | null; due_date: string; state: string; return_note: string | null }[]; graded: { id: number; title: string; marks_awarded: number | null; marks_available: number | null; feedback: string | null }[] };
  virtual_attendance: { counts: Record<string, number> };
  lessons_attended: number;
}
interface Settings { require_material_approval: boolean; parents_see_materials: boolean; parent_assist_sections: string[] | null; present_percent: number; late_grace_minutes: number; recording_allowed: boolean; recording_notice: string | null; max_upload_mb: number }

const TYPE_ICON: Record<string, string> = { pdf: '📄', document: '📝', presentation: '📊', image: '🖼️', video: '🎬', audio: '🎧', link: '🔗' };
const size = (b: number | null) => (b ? (b > 1_048_576 ? `${(b / 1_048_576).toFixed(1)} MB` : `${Math.ceil(b / 1024)} KB`) : '');
const when = (iso: string) => new Date(iso).toLocaleString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

async function openMaterial(m: { id: number; type: string }) {
  if (m.type === 'link') {
    const res = await fetchApi<{ url: string }>(`/learning-materials/${m.id}/download`);
    window.open(res.url, '_blank', 'noopener');
  } else {
    window.open(`/api/backend/learning-materials/${m.id}/download`, '_blank');
  }
}

export default function LearningPage() {
  const [role] = useState(roleFromCookie);
  const staff = role === 'school_admin' || role === 'teacher' || role === 'super_admin';
  return staff ? <StaffLearning admin={role !== 'teacher'} /> : <LearnerView parent={role === 'parent'} />;
}

function StaffLearning({ admin }: { admin: boolean }) {
  const [tab, setTab] = useState<Tab>('materials');
  const [materials, setMaterials] = useState<Material[]>([]);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [overview, setOverview] = useState<Overview | null>(null);
  const [settings, setSettings] = useState<Settings | null>(null);
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [filter, setFilter] = useState({ q: '', class_id: '', subject_id: '' });

  const [up, setUp] = useState({ title: '', description: '', topic: '', class_id: '', subject_id: '', target: 'class', arm_id: '', section: 'secondary', url: '', draft: false });
  const [file, setFile] = useState<File | null>(null);
  const [plan, setPlan] = useState({ class_id: '', subject_id: '', week: '', topic: '', subtopic: '', objectives: '', activities: '', assessment: '', duration_minutes: '40' });
  const [section, setSection] = useState('');

  const load = useCallback(async () => {
    if (tab === 'materials') {
      const qs = new URLSearchParams(Object.entries(filter).filter(([, v]) => v));
      setMaterials((await fetchApi<{ data: Material[] }>(`/learning-materials?${qs}`)).data);
    }
    if (tab === 'plans') setPlans((await fetchApi<{ data: Plan[] }>('/lesson-plans')).data);
    if (tab === 'overview') setOverview(await fetchApi<Overview>(`/lms/overview${section ? `?section=${section}` : ''}`));
    if (tab === 'settings') setSettings(await fetchApi<Settings>('/lms/settings'));
  }, [tab, filter, section]);

  useEffect(() => {
    (async () => {
      const [c, s] = await Promise.all([
        fetchApi<{ data?: ClassOption[] }>('/classes').catch(() => null),
        fetchApi<{ data?: Subject[] }>('/subjects').catch(() => null),
      ]);
      setClasses(c?.data ?? []);
      setSubjects(s?.data ?? []);
    })();
  }, []);

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        await load();
        setError(null);
      } catch (err) {
        setError(errorText(err, 'Could not load.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function act(path: string, body: unknown, method: 'POST' | 'PUT' = 'POST') {
    setBusy(true);
    try {
      const res = await fetchApi<{ message?: string }>(path, { method, body: JSON.stringify(body) });
      setNotice(res?.message ?? 'Saved.');
      setError(null);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
    } finally {
      setBusy(false);
    }
  }

  async function upload(e: React.FormEvent) {
    e.preventDefault();
    const form = new FormData();
    form.append('title', up.title);
    if (up.description) form.append('description', up.description);
    if (up.topic) form.append('topic', up.topic);
    if (up.class_id) form.append('class_id', up.class_id);
    if (up.subject_id) form.append('subject_id', up.subject_id);
    if (up.draft) form.append('draft', '1');
    if (file) form.append('file', file);
    else form.append('url', up.url);
    form.append('targets[0][target_type]', up.target);
    if (up.target === 'section') form.append('targets[0][section]', up.section);
    else form.append('targets[0][class_id]', up.class_id);
    if (up.target === 'arm') form.append('targets[0][arm_id]', up.arm_id);

    setBusy(true);
    try {
      const res = await uploadApi<{ message: string }>('/learning-materials', form);
      setNotice(res.message);
      setUp({ ...up, title: '', description: '', topic: '', url: '' });
      setFile(null);
      setError(null);
      await load();
    } catch (err) {
      setError(errorText(err, 'Upload failed.'));
    } finally {
      setBusy(false);
    }
  }

  async function showReach(m: Material) {
    try {
      const r = await fetchApi<{ targeted: number; opened: number }>(`/learning-materials/${m.id}/reach`);
      setNotice(`"${m.title}": opened by ${r.opened} of ${r.targeted} students.`);
    } catch (err) {
      setError(errorText(err, 'Could not load.'));
    }
  }

  const arms = classes.find((c) => String(c.id) === up.class_id)?.arms ?? [];
  const tabs: [Tab, string][] = [['materials', 'Materials'], ['plans', 'Lesson plans'], ['overview', 'Overview'], ...(admin ? [['settings', 'Settings'] as [Tab, string]] : [])];

  return (
    <PageShell title="Learning" subtitle="Materials, lesson plans and how learning is going.">
      <nav className="mb-5 flex flex-wrap gap-2" aria-label="Learning sections">
        {tabs.map(([key, label]) => (
          <Button key={key} variant={tab === key ? 'primary' : 'secondary'} onClick={() => { setTab(key); setNotice(null); }}>{label}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p className="mb-4 rounded-lg bg-positive-soft px-4 py-2 text-sm text-positive">{notice}</p>}

      {tab === 'materials' && (
        <div className="space-y-5">
          <Card title="Share a material" description="PDF, Word, PowerPoint, images, video, audio, or a link. Shared only with the class, arm or section you choose.">
            <form className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" onSubmit={upload}>
              <Field label="Title"><input className={inputClass} required value={up.title} onChange={(e) => setUp({ ...up, title: e.target.value })} /></Field>
              <Field label="Subject"><select className={inputClass} value={up.subject_id} onChange={(e) => setUp({ ...up, subject_id: e.target.value })}><option value="">—</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
              <Field label="Topic"><input className={inputClass} value={up.topic} onChange={(e) => setUp({ ...up, topic: e.target.value })} /></Field>
              <Field label="Share with">
                <select className={inputClass} value={up.target} onChange={(e) => setUp({ ...up, target: e.target.value })}>
                  <option value="class">A class</option>
                  <option value="arm">One arm of a class</option>
                  <option value="section">A whole section (section heads)</option>
                </select>
              </Field>
              {up.target === 'section' ? (
                <Field label="Section"><select className={inputClass} value={up.section} onChange={(e) => setUp({ ...up, section: e.target.value })}>{['nursery', 'primary', 'secondary'].map((s) => <option key={s}>{s}</option>)}</select></Field>
              ) : (
                <Field label="Class"><select className={inputClass} required value={up.class_id} onChange={(e) => setUp({ ...up, class_id: e.target.value, arm_id: '' })}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
              )}
              {up.target === 'arm' && (
                <Field label="Arm"><select className={inputClass} required value={up.arm_id} onChange={(e) => setUp({ ...up, arm_id: e.target.value })}><option value="">Choose…</option>{arms.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select></Field>
              )}
              <Field label="File"><input className={inputClass} type="file" accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.ppt,.pptx,.odp,.jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.3gp,.mov,.mp3,.m4a,.aac,.ogg,.wav" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></Field>
              {!file && <Field label="…or a link"><input className={inputClass} type="url" placeholder="https://" value={up.url} onChange={(e) => setUp({ ...up, url: e.target.value })} /></Field>}
              <Field label="Description"><input className={inputClass} value={up.description} onChange={(e) => setUp({ ...up, description: e.target.value })} /></Field>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={up.draft} onChange={(e) => setUp({ ...up, draft: e.target.checked })} /> Save as draft</label>
              <div className="flex items-end"><Button disabled={busy || (!file && !up.url)}>{busy ? 'Uploading…' : 'Share'}</Button></div>
            </form>
          </Card>

          <Card title="Library">
            <div className="mb-4 grid gap-3 sm:grid-cols-3">
              <input className={inputClass} placeholder="Search title or topic…" defaultValue={filter.q} onKeyDown={(e) => { if (e.key === 'Enter') setFilter({ ...filter, q: (e.target as HTMLInputElement).value }); }} />
              <select className={inputClass} value={filter.class_id} onChange={(e) => setFilter({ ...filter, class_id: e.target.value })}><option value="">All classes</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
              <select className={inputClass} value={filter.subject_id} onChange={(e) => setFilter({ ...filter, subject_id: e.target.value })}><option value="">All subjects</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
            </div>
            {loading ? <Loading /> : materials.length === 0 ? <Empty>No materials yet.</Empty> : (
              <Table columns={['Material', 'For', 'Status', '']}>
                {materials.map((m) => (
                  <tr key={m.id}>
                    <Cell>
                      <button type="button" className="text-left font-medium text-brand hover:underline" onClick={() => openMaterial(m).catch((err) => setError(errorText(err, 'Could not open.')))}>{TYPE_ICON[m.type] ?? '📁'} {m.title}</button>
                      <span className="block text-xs text-ink-muted">{[m.subject, m.topic, size(m.size_bytes), m.uploaded_by].filter(Boolean).join(' · ')}</span>
                    </Cell>
                    <Cell>{m.class ?? '—'}</Cell>
                    <Cell>
                      <Badge tone={m.status === 'published' ? 'green' : m.status === 'rejected' ? 'red' : 'amber'}>{m.status.replaceAll('_', ' ')}</Badge>
                      {m.review_note && <span className="block text-xs text-ink-muted">{m.review_note}</span>}
                    </Cell>
                    <Cell className="space-x-2 whitespace-nowrap text-right">
                      {m.status === 'pending_approval' && !m.mine && <>
                        <Button variant="secondary" disabled={busy} onClick={() => act(`/learning-materials/${m.id}/review`, { approve: true })}>Approve</Button>
                        <Button variant="danger" disabled={busy} onClick={() => { const note = window.prompt('What should the teacher change?'); if (note) act(`/learning-materials/${m.id}/review`, { approve: false, note }); }}>Return</Button>
                      </>}
                      {m.mine && ['draft', 'rejected'].includes(m.status) && <Button variant="secondary" disabled={busy} onClick={() => act(`/learning-materials/${m.id}`, { publish: true }, 'PUT')}>Publish</Button>}
                      {m.status === 'published' && (m.mine || admin) && <Button variant="secondary" onClick={() => showReach(m)}>Reach</Button>}
                      {(m.mine || admin) && m.status !== 'archived' && <Button variant="secondary" disabled={busy} onClick={() => { if (window.confirm('Archive? Students will no longer see it.')) act(`/learning-materials/${m.id}/archive`, {}); }}>Archive</Button>}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}

      {tab === 'plans' && (
        <div className="space-y-5">
          <Card title="New lesson plan" description="One plan serves a classroom lesson or a virtual class. Submit it for review when ready.">
            <form className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" onSubmit={(e) => { e.preventDefault(); act('/lesson-plans', { ...plan, class_id: Number(plan.class_id), subject_id: Number(plan.subject_id), week: plan.week ? Number(plan.week) : null, duration_minutes: Number(plan.duration_minutes) }); }}>
              <Field label="Class"><select className={inputClass} required value={plan.class_id} onChange={(e) => setPlan({ ...plan, class_id: e.target.value })}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
              <Field label="Subject"><select className={inputClass} required value={plan.subject_id} onChange={(e) => setPlan({ ...plan, subject_id: e.target.value })}><option value="">Choose…</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
              <Field label="Week"><input className={inputClass} type="number" min={1} max={20} value={plan.week} onChange={(e) => setPlan({ ...plan, week: e.target.value })} /></Field>
              <Field label="Duration (min)"><input className={inputClass} type="number" min={5} value={plan.duration_minutes} onChange={(e) => setPlan({ ...plan, duration_minutes: e.target.value })} /></Field>
              <Field label="Topic"><input className={inputClass} required value={plan.topic} onChange={(e) => setPlan({ ...plan, topic: e.target.value })} /></Field>
              <Field label="Subtopic"><input className={inputClass} value={plan.subtopic} onChange={(e) => setPlan({ ...plan, subtopic: e.target.value })} /></Field>
              <div className="sm:col-span-2"><Field label="Learning objectives"><input className={inputClass} value={plan.objectives} onChange={(e) => setPlan({ ...plan, objectives: e.target.value })} /></Field></div>
              <div className="sm:col-span-2"><Field label="Activities"><textarea className={inputClass} rows={2} value={plan.activities} onChange={(e) => setPlan({ ...plan, activities: e.target.value })} /></Field></div>
              <div className="sm:col-span-2"><Field label="Assessment"><textarea className={inputClass} rows={2} value={plan.assessment} onChange={(e) => setPlan({ ...plan, assessment: e.target.value })} /></Field></div>
              <div className="flex items-end"><Button disabled={busy}>Save draft</Button></div>
            </form>
          </Card>
          <Card title="Plans">
            {loading ? <Loading /> : plans.length === 0 ? <Empty>No lesson plans yet.</Empty> : (
              <Table columns={['Topic', 'Teacher', 'Status', '']}>
                {plans.map((p) => (
                  <tr key={p.id}>
                    <Cell>{p.week ? `Wk ${p.week} · ` : ''}{p.topic}{p.subtopic && <span className="block text-xs text-ink-muted">{p.subtopic}</span>}</Cell>
                    <Cell>{p.teacher}</Cell>
                    <Cell><Badge tone={p.status === 'approved' ? 'green' : p.status === 'returned' ? 'red' : p.status === 'submitted' ? 'blue' : 'slate'}>{p.status}</Badge>{p.review_note && <span className="block text-xs text-ink-muted">{p.review_note}</span>}</Cell>
                    <Cell className="space-x-2 whitespace-nowrap text-right">
                      {p.mine && ['draft', 'returned'].includes(p.status) && <Button variant="secondary" disabled={busy} onClick={() => act(`/lesson-plans/${p.id}/submit`, {})}>Submit</Button>}
                      {!p.mine && p.status === 'submitted' && <>
                        <Button variant="secondary" disabled={busy} onClick={() => act(`/lesson-plans/${p.id}/review`, { approve: true })}>Approve</Button>
                        <Button variant="danger" disabled={busy} onClick={() => { const note = window.prompt('What should change?'); if (note) act(`/lesson-plans/${p.id}/review`, { approve: false, note }); }}>Return</Button>
                      </>}
                      {p.mine && <Button variant="secondary" disabled={busy} onClick={() => act(`/lesson-plans/${p.id}/copy`, {})}>Copy</Button>}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}

      {tab === 'overview' && (
        <div className="space-y-5">
          {!overview?.scope.teacher_only && (
            <div className="flex items-center gap-3">
              <span className="text-sm text-ink-muted">Section</span>
              <select className={`${inputClass} max-w-xs`} value={section} onChange={(e) => setSection(e.target.value)}><option value="">All I can see</option>{['nursery', 'primary', 'secondary'].map((s) => <option key={s}>{s}</option>)}</select>
            </div>
          )}
          {loading || !overview ? <Loading /> : (
            <>
              <p className="text-sm text-ink-muted">{overview.scope.teacher_only ? 'Your classes' : 'Aggregated'}, {overview.scope.term ?? 'last 30 days to the next 30'} ({overview.scope.from} to {overview.scope.to}).</p>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Virtual lessons" value={`${overview.metrics.virtual_lessons_completed} / ${overview.metrics.virtual_lessons_scheduled}`} hint="completed / scheduled" />
                <StatTile label="Virtual attendance" value={overview.metrics.virtual_attendance_rate !== null ? `${overview.metrics.virtual_attendance_rate}%` : '—'} tone="blue" hint="present or late" />
                <StatTile label="Submission rate" value={overview.metrics.submission_rate !== null ? `${overview.metrics.submission_rate}%` : '—'} tone="green" hint={`${overview.metrics.assignments_set} assignments set`} />
                <StatTile label="Average score" value={overview.metrics.average_assignment_score !== null ? `${overview.metrics.average_assignment_score}%` : '—'} hint={`${overview.metrics.students_with_missed_work} students with missed work`} tone={overview.metrics.students_with_missed_work ? 'amber' : 'slate'} />
              </div>
              <p className="text-sm text-ink-muted">{overview.metrics.materials_published} materials published; {overview.metrics.students_opening_materials} students have opened at least one.</p>
              <Card title="Students who may need support" description="Two or more missed assignments or virtual classes in this period.">
                {overview.needs_support.length === 0 ? <Empty>Nobody flagged.</Empty> : (
                  <Table columns={['Student', 'Class', 'Missed assignments', 'Missed virtual classes']}>
                    {overview.needs_support.map((s) => (
                      <tr key={s.student_id}><Cell>{s.name}</Cell><Cell>{s.class}</Cell><Cell>{s.missed_assignments}</Cell><Cell>{s.missed_virtual_classes}</Cell></tr>
                    ))}
                  </Table>
                )}
              </Card>
            </>
          )}
        </div>
      )}

      {tab === 'settings' && settings && (
        <Card title="Learning settings" description="Recording stays off until you set a policy and write the notice families will see.">
          <form className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); act('/lms/settings', settings, 'PUT'); }}>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.require_material_approval} onChange={(e) => setSettings({ ...settings, require_material_approval: e.target.checked })} /> Materials need a reviewer&apos;s approval</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.parents_see_materials} onChange={(e) => setSettings({ ...settings, parents_see_materials: e.target.checked })} /> Parents can see their child&apos;s materials</label>
            <Field label="Present if in the lesson at least (%)"><input className={inputClass} type="number" min={10} max={100} value={settings.present_percent} onChange={(e) => setSettings({ ...settings, present_percent: Number(e.target.value) })} /></Field>
            <Field label="Late after (minutes)"><input className={inputClass} type="number" min={0} max={60} value={settings.late_grace_minutes} onChange={(e) => setSettings({ ...settings, late_grace_minutes: Number(e.target.value) })} /></Field>
            <Field label="Largest upload (MB)"><input className={inputClass} type="number" min={1} max={200} value={settings.max_upload_mb} onChange={(e) => setSettings({ ...settings, max_upload_mb: Number(e.target.value) })} /></Field>
            <div>
              <span className="mb-1 block text-sm font-medium">Parents may submit for their child in</span>
              {['nursery', 'primary', 'secondary'].map((s) => (
                <label key={s} className="mr-4 inline-flex items-center gap-1 text-sm">
                  <input type="checkbox" checked={(settings.parent_assist_sections ?? []).includes(s)} onChange={(e) => setSettings({ ...settings, parent_assist_sections: e.target.checked ? [...(settings.parent_assist_sections ?? []), s] : (settings.parent_assist_sections ?? []).filter((x) => x !== s) })} /> {s}
                </label>
              ))}
            </div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.recording_allowed} onChange={(e) => setSettings({ ...settings, recording_allowed: e.target.checked })} /> Teachers may record lessons</label>
            <Field label="Recording notice for families"><textarea className={inputClass} rows={2} value={settings.recording_notice ?? ''} onChange={(e) => setSettings({ ...settings, recording_notice: e.target.value })} /></Field>
            <div><Button disabled={busy}>Save settings</Button></div>
          </form>
        </Card>
      )}
    </PageShell>
  );
}

function LearnerView({ parent }: { parent: boolean }) {
  const [records, setRecords] = useState<({ student_id?: number; name?: string | null } & Record_)[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        if (parent) setRecords((await fetchApi<{ children: ({ student_id: number; name: string | null } & Record_)[] }>('/parent/learning')).children);
        else setRecords([await fetchApi<Record_>('/me/learning')]);
      } catch (err) {
        setError(errorText(err, 'Could not load learning.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [parent]);

  return (
    <PageShell title="Learning" subtitle={parent ? 'Your children’s classes, materials and assignments.' : 'Your classes, materials and assignments.'}>
      <ErrorNote message={error} />
      {loading ? <Loading /> : records.map((r, i) => (
        <div key={r.student_id ?? i} className="mb-8 space-y-4">
          {r.name && <h2 className="font-headline text-lg font-semibold text-brand">{r.name}</h2>}
          <div className="grid gap-4 sm:grid-cols-4">
            <StatTile label="To do" value={(r.assignments.counts.pending ?? 0) + (r.assignments.counts.returned ?? 0)} tone="amber" hint={r.assignments.counts.missed ? `${r.assignments.counts.missed} missed` : undefined} />
            <StatTile label="Handed in" value={r.assignments.counts.submitted ?? 0} tone="blue" />
            <StatTile label="Marked" value={r.assignments.counts.graded ?? 0} tone="green" />
            <StatTile label="Live lessons attended" value={r.lessons_attended} hint={r.virtual_attendance.counts.absent ? `${r.virtual_attendance.counts.absent} missed` : undefined} />
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card title="Upcoming virtual classes">
              {r.upcoming_classes.length === 0 ? <Empty>None scheduled.</Empty> : (
                <ul className="space-y-2 text-sm">{r.upcoming_classes.map((c) => <li key={c.id}><span className="font-medium">{c.title}</span> · {c.subject} · {when(c.scheduled_for)}</li>)}</ul>
              )}
              <a href="/live-class" className="mt-3 inline-block text-sm text-brand underline">Go to virtual classes</a>
            </Card>
            <Card title="Assignments to do">
              {r.assignments.pending.length === 0 ? <Empty>Nothing outstanding.</Empty> : (
                <ul className="space-y-2 text-sm">
                  {r.assignments.pending.map((a) => (
                    <li key={a.id}>
                      <span className="font-medium">{a.title}</span> · {a.subject} · due {a.due_date}{' '}
                      <Badge tone={a.state === 'missed' ? 'red' : a.state === 'returned' ? 'amber' : 'blue'}>{a.state === 'returned' ? 'returned for correction' : a.state}</Badge>
                      {a.return_note && <span className="block text-xs text-ink-muted">Teacher: {a.return_note}</span>}
                    </li>
                  ))}
                </ul>
              )}
            </Card>
            {r.materials && (
              <Card title="Learning materials" description="Downloads resume if the connection drops.">
                {r.materials.length === 0 ? <Empty>Nothing shared yet.</Empty> : (
                  <ul className="space-y-2 text-sm">
                    {r.materials.map((m) => (
                      <li key={m.id}>
                        <button type="button" className="text-left text-brand hover:underline" onClick={() => openMaterial(m).catch((err) => setError(errorText(err, 'Could not open.')))}>{TYPE_ICON[m.type] ?? '📁'} {m.title}</button>
                        <span className="text-ink-muted"> · {[m.subject, m.topic].filter(Boolean).join(' · ')}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            )}
            <Card title="Feedback">
              {r.assignments.graded.length === 0 ? <Empty>No marked work yet.</Empty> : (
                <ul className="space-y-2 text-sm">
                  {r.assignments.graded.map((g) => (
                    <li key={g.id}><span className="font-medium">{g.title}</span> · {g.marks_awarded ?? '—'} / {g.marks_available ?? '—'}{g.feedback && <span className="block text-xs text-ink-muted">{g.feedback}</span>}</li>
                  ))}
                </ul>
              )}
            </Card>
          </div>
        </div>
      ))}
    </PageShell>
  );
}
