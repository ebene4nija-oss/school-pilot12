'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';
import GradingBandsEditor, { type Band, WAEC, bandProblem, cleanBands } from '@/components/GradingBandsEditor';
import ReportSetup from '@/components/ReportSetup';

/**
 * Report card design and grading.
 *
 * A school sends SchoolPilot what its report card should look like — its
 * current card, crest, signatures and a short structured brief — plus the
 * grading it uses. SchoolPilot builds the design and uploads it here; the
 * school previews it on sample data and approves it (it goes live) or sends it
 * back with comments. The Grading tab sets the scale per class level directly.
 */

type DesignFile = { id: number; kind: string; label: string | null; original_name: string; size: number };

type DesignRequest = {
  id: number;
  status: 'submitted' | 'in_progress' | 'template_ready' | 'changes_requested' | 'approved' | 'cancelled';
  requirements: Record<string, unknown>;
  grading_scales: Record<string, Band[]> | null;
  submitted_at: string | null;
  operator_notes: string | null;
  school_feedback: string | null;
  template: { id: number; name: string; version: number } | null;
  approved_at: string | null;
  files: DesignFile[];
};

type Meta = {
  open: DesignRequest | null;
  history: DesignRequest[];
  sections: Record<string, string>;
  levels: Record<string, string>;
  file_kinds: Record<string, string>;
  limits: { max_files: number; max_file_mb: number; types: string };
};

const STATUS: Record<DesignRequest['status'], { label: string; tone: 'amber' | 'blue' | 'green' | 'red' | 'slate' }> = {
  submitted: { label: 'Sent to SchoolPilot', tone: 'amber' },
  in_progress: { label: 'SchoolPilot is designing it', tone: 'blue' },
  template_ready: { label: 'Ready for you to check', tone: 'green' },
  changes_requested: { label: 'Sent back for changes', tone: 'amber' },
  approved: { label: 'Approved and in use', tone: 'green' },
  cancelled: { label: 'Cancelled', tone: 'slate' },
};

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

export default function ReportCardDesignPage() {
  // Opens on the onboarding choices, which the setup checklist links to.
  const [tab, setTab] = useState<'setup' | 'design' | 'grading'>('setup');

  return (
    <PageShell title="Report cards & grading" subtitle="How marks are split, what is rated, your grading, and your report card's design.">
      <div role="tablist" className="mb-6 flex flex-wrap gap-2">
        {([
          ['setup', 'Marks, ratings & comments'],
          ['grading', 'Grading scales'],
          ['design', 'Design request'],
        ] as const).map(([id, label]) => (
          <button
            key={id}
            role="tab"
            aria-selected={tab === id}
            onClick={() => setTab(id)}
            className={`rounded-lg px-3.5 py-2 text-sm font-medium transition ${
              tab === id ? 'bg-brand text-white' : 'bg-surface text-ink ring-1 ring-line-soft hover:bg-surface-sunken'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'setup' ? <ReportSetup /> : tab === 'design' ? <DesignTab /> : <GradingTab />}
    </PageShell>
  );
}

// ---------------------------------------------------------------- design

function DesignTab() {
  const [meta, setMeta] = useState<Meta | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setMeta(await fetchApi<Meta>('/report-cards/design-requests'));
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load your design request.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  if (!meta) return error ? <ErrorNote message={error} /> : <Loading />;

  return (
    <div className="space-y-6">
      <ErrorNote message={error} />
      {meta.open ? <OpenRequest request={meta.open} meta={meta} onChanged={load} /> : <NewRequest meta={meta} onSent={load} />}

      {meta.history.length > 0 && (
        <Card title="Earlier requests">
          <ul className="space-y-2 text-sm">
            {meta.history.map((r) => (
              <li key={r.id} className="flex items-center justify-between rounded-lg bg-surface-sunken px-3 py-2">
                <span>Request #{r.id} · sent {when(r.submitted_at)}</span>
                <Badge tone={STATUS[r.status].tone}>{STATUS[r.status].label}</Badge>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  );
}

function Instructions({ meta }: { meta: Meta }) {
  return (
    <Card title="How it works">
      <ol className="list-decimal space-y-2 pl-5 text-sm text-ink">
        <li>
          <strong>Photograph or scan your current report card</strong> — a <em>blank</em> one if you have it. If it is a used card,
          cover or black out the pupil&rsquo;s name, photo and marks first. We only need the layout.
        </li>
        <li>
          <strong>Add your crest or logo</strong> as a clear image (PNG with a transparent background is best), and, if you want
          them printed, the principal&rsquo;s signature and school stamp — on white paper, photographed flat in good light.
        </li>
        <li>
          <strong>Fill in the form below:</strong> what the card must show, how marks are split between CAs and the exam, and your
          grading. If your primary section grades differently from your secondary, add a scale for each.
        </li>
        <li>
          <strong>SchoolPilot designs it</strong> and it appears here. You check it on sample data (never your pupils&rsquo; real
          records) and <strong>approve</strong> it — only then is it used — or send it back with what to change.
        </li>
      </ol>
      <p className="mt-3 text-xs text-ink-muted">
        Files: PDF, JPG, PNG, WEBP or Word, up to {meta.limits.max_file_mb} MB each, {meta.limits.max_files} at most. They are
        deleted once your design is approved or the request is cancelled. The verification QR code is always printed.
      </p>
    </Card>
  );
}

type FileRow = { file: File | null; kind: string; label: string };

function FilePicker({ rows, setRows, kinds }: { rows: FileRow[]; setRows: (r: FileRow[]) => void; kinds: Record<string, string> }) {
  return (
    <div className="space-y-2">
      {rows.map((row, i) => (
        <div key={i} className="grid gap-2 sm:grid-cols-[1fr_12rem_1fr_auto]">
          <input
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
            onChange={(e) => setRows(rows.map((r, j) => (j === i ? { ...r, file: e.target.files?.[0] ?? null } : r)))}
            className="text-xs file:mr-3 file:rounded file:border-0 file:bg-surface-raised file:px-3 file:py-1.5 file:text-ink"
          />
          <select value={row.kind} onChange={(e) => setRows(rows.map((r, j) => (j === i ? { ...r, kind: e.target.value } : r)))} className={inputClass}>
            {Object.entries(kinds).map(([k, label]) => (
              <option key={k} value={k}>{label}</option>
            ))}
          </select>
          <input value={row.label} maxLength={80} placeholder="Note (optional)" onChange={(e) => setRows(rows.map((r, j) => (j === i ? { ...r, label: e.target.value } : r)))} className={inputClass} />
          <button type="button" onClick={() => setRows(rows.filter((_, j) => j !== i))} className="text-xs text-negative hover:underline">
            Remove
          </button>
        </div>
      ))}
      <button type="button" onClick={() => setRows([...rows, { file: null, kind: 'other', label: '' }])} className="text-xs font-semibold text-brand hover:underline">
        + Add another file
      </button>
    </div>
  );
}

function appendFiles(form: FormData, rows: FileRow[]) {
  rows.filter((r) => r.file).forEach((r, i) => {
    form.append(`files[${i}][file]`, r.file as File);
    form.append(`files[${i}][kind]`, r.kind);
    if (r.label.trim()) form.append(`files[${i}][label]`, r.label.trim());
  });
}

const listFrom = (text: string) => text.split(/[,\n]/).map((s) => s.trim()).filter(Boolean);

function NewRequest({ meta, onSent }: { meta: Meta; onSent: () => void }) {
  const [contactName, setContactName] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [paper, setPaper] = useState('A4');
  const [orientation, setOrientation] = useState('portrait');
  const [sections, setSections] = useState<Record<string, boolean>>({
    position_in_class: true, class_average: true, attendance: true, affective_traits: true,
    class_teacher_remark: true, principal_remark: true, grading_key: true, next_term_date: true, verification_qr: true,
  });
  const [ca, setCa] = useState([{ name: '1st CA', max: '20' }, { name: '2nd CA', max: '20' }, { name: 'Exam', max: '60' }]);
  const [traits, setTraits] = useState('Punctuality, Neatness, Politeness, Honesty, Attentiveness');
  const [skills, setSkills] = useState('Handwriting, Sports, Drawing, Musical skills');
  const [ratingScale, setRatingScale] = useState('5 = Excellent … 1 = Poor');
  const [colours, setColours] = useState('');
  const [motto, setMotto] = useState('');
  const [notes, setNotes] = useState('');
  const [scales, setScales] = useState<Record<string, Band[]>>({ default: WAEC });
  const [files, setFiles] = useState<FileRow[]>([{ file: null, kind: 'sample', label: '' }, { file: null, kind: 'crest', label: '' }]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const caTotal = ca.reduce((s, c) => s + (Number(c.max) || 0), 0);
  const scaleProblem = Object.entries(scales).map(([level, b]) => (bandProblem(b) ? `${meta.levels[level]}: ${bandProblem(b)}` : null)).find(Boolean);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);

    const form = new FormData();
    form.append('brief', JSON.stringify({
      contact_name: contactName.trim(),
      contact_phone: contactPhone.trim(),
      paper_size: paper,
      orientation,
      sections,
      ca_components: ca.map((c) => ({ name: c.name.trim(), max: Number(c.max) })),
      affective_traits: sections.affective_traits ? listFrom(traits) : [],
      psychomotor_skills: sections.psychomotor_skills ? listFrom(skills) : [],
      trait_rating_scale: ratingScale.trim() || null,
      colours: colours.trim() || null,
      motto: motto.trim() || null,
      notes: notes.trim() || null,
    }));
    form.append('grading_scales', JSON.stringify(Object.fromEntries(Object.entries(scales).map(([l, b]) => [l, cleanBands(b)]))));
    appendFiles(form, files);

    try {
      await uploadApi('/report-cards/design-requests', form);
      onSent();
    } catch (err) {
      setError(errorText(err, 'Could not send your request.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <Instructions meta={meta} />

      <form onSubmit={submit} className="space-y-6">
        <Card title="1. Your files">
          <FilePicker rows={files} setRows={setFiles} kinds={meta.file_kinds} />
        </Card>

        <Card title="2. What the card shows">
          <div className="grid gap-2 sm:grid-cols-2">
            {Object.entries(meta.sections).map(([key, label]) => (
              <label key={key} className="flex items-center gap-2 text-sm text-ink">
                <input
                  type="checkbox"
                  className="h-4 w-4 accent-brand"
                  checked={key === 'verification_qr' || !!sections[key]}
                  disabled={key === 'verification_qr'}
                  onChange={(e) => setSections({ ...sections, [key]: e.target.checked })}
                />
                {label}
              </label>
            ))}
          </div>

          {sections.affective_traits && (
            <div className="mt-4">
              <Field label="Affective traits to rate (comma-separated)">
                <textarea rows={2} value={traits} onChange={(e) => setTraits(e.target.value)} className={inputClass} />
              </Field>
            </div>
          )}
          {sections.psychomotor_skills && (
            <div className="mt-4">
              <Field label="Psychomotor skills to rate (comma-separated)">
                <textarea rows={2} value={skills} onChange={(e) => setSkills(e.target.value)} className={inputClass} />
              </Field>
            </div>
          )}
          {(sections.affective_traits || sections.psychomotor_skills) && (
            <div className="mt-4">
              <Field label="How traits are rated">
                <input value={ratingScale} onChange={(e) => setRatingScale(e.target.value)} className={inputClass} placeholder="e.g. 5 = Excellent … 1 = Poor, or A–E" />
              </Field>
            </div>
          )}
        </Card>

        <Card title="3. How marks are split" description="Continuous assessments and the exam, as printed on your card. They must add up to 100.">
          <div className="space-y-2">
            {ca.map((c, i) => (
              <div key={i} className="flex flex-wrap items-center gap-2">
                <input value={c.name} onChange={(e) => setCa(ca.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} className={`${inputClass} max-w-xs`} aria-label="Component name" />
                <input type="number" min={1} max={100} value={c.max} onChange={(e) => setCa(ca.map((x, j) => (j === i ? { ...x, max: e.target.value } : x)))} className={`${inputClass} w-24`} aria-label="Marks" />
                <span className="text-xs text-ink-muted">marks</span>
                {ca.length > 1 && (
                  <button type="button" onClick={() => setCa(ca.filter((_, j) => j !== i))} className="text-xs text-negative hover:underline">Remove</button>
                )}
              </div>
            ))}
          </div>
          <div className="mt-2 flex items-center gap-4 text-xs">
            <button type="button" onClick={() => setCa([...ca.slice(0, -1), { name: `${ca.length}${['st', 'nd', 'rd'][ca.length - 1] ?? 'th'} CA`, max: '10' }, ca[ca.length - 1]])} className="font-semibold text-brand hover:underline">
              + Add a CA
            </button>
            <span className={caTotal === 100 ? 'text-positive' : 'text-negative'}>Total: {caTotal} / 100</span>
          </div>
        </Card>

        <Card title="4. Grading" description="The grade each mark range gets, and its remark. Add a separate scale for any section that grades differently.">
          <div className="space-y-6">
            {Object.entries(scales).map(([level, bands]) => (
              <div key={level}>
                <div className="mb-2 flex items-center justify-between">
                  <h3 className="text-sm font-semibold text-ink">{level === 'default' ? 'All classes (unless set below)' : meta.levels[level]}</h3>
                  {level !== 'default' && (
                    <button type="button" onClick={() => { const next = { ...scales }; delete next[level]; setScales(next); }} className="text-xs text-negative hover:underline">
                      Remove
                    </button>
                  )}
                </div>
                <GradingBandsEditor bands={bands} onChange={(b) => setScales({ ...scales, [level]: b })} />
              </div>
            ))}
          </div>
          <div className="mt-4 flex flex-wrap gap-2">
            {Object.entries(meta.levels)
              .filter(([level]) => !scales[level])
              .map(([level, label]) => (
                <button key={level} type="button" onClick={() => setScales({ ...scales, [level]: WAEC })} className="rounded-lg border border-line px-2.5 py-1 text-xs text-ink hover:bg-surface-sunken">
                  + Different scale for {label}
                </button>
              ))}
          </div>
        </Card>

        <Card title="5. Anything else">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Paper">
              <div className="flex gap-2">
                <select value={paper} onChange={(e) => setPaper(e.target.value)} className={inputClass}>
                  {['A4', 'A5', 'LETTER', 'LEGAL'].map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
                <select value={orientation} onChange={(e) => setOrientation(e.target.value)} className={inputClass}>
                  <option value="portrait">Portrait</option>
                  <option value="landscape">Landscape</option>
                </select>
              </div>
            </Field>
            <Field label="School colours (optional)">
              <input value={colours} onChange={(e) => setColours(e.target.value)} className={inputClass} placeholder="e.g. Navy blue and gold" />
            </Field>
            <Field label="Motto (optional)">
              <input value={motto} onChange={(e) => setMotto(e.target.value)} className={inputClass} />
            </Field>
            <div className="sm:col-span-2">
              <Field label="Notes for the designer (optional)">
                <textarea rows={4} value={notes} onChange={(e) => setNotes(e.target.value)} className={inputClass} placeholder="Anything special: a cumulative column for third term, a section for club activities, where signatures go…" />
              </Field>
            </div>
            <Field label="Who should we contact?">
              <input required value={contactName} onChange={(e) => setContactName(e.target.value)} className={inputClass} placeholder="Name" />
            </Field>
            <Field label="Their phone number">
              <input required type="tel" value={contactPhone} onChange={(e) => setContactPhone(e.target.value)} className={inputClass} placeholder="0803 123 4567" />
            </Field>
          </div>
        </Card>

        <ErrorNote message={error} />
        {scaleProblem && <p className="text-sm text-negative">{scaleProblem}</p>}

        <Button type="submit" variant="accent" disabled={busy || caTotal !== 100 || !!scaleProblem || !files.some((f) => f.file)}>
          {busy ? 'Sending…' : 'Send to SchoolPilot'}
        </Button>
      </form>
    </>
  );
}

function OpenRequest({ request, meta, onChanged }: { request: DesignRequest; meta: Meta; onChanged: () => void }) {
  const [feedback, setFeedback] = useState('');
  const [regrade, setRegrade] = useState(true);
  const [currentTerm, setCurrentTerm] = useState<{ id: number; name: string } | null>(null);
  const [more, setMore] = useState<FileRow[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<{ data: { id: number; name: string }[]; current_term_id: number | null }>('/terms')
      .then((res) => setCurrentTerm(res.data.find((t) => t.id === res.current_term_id) ?? null))
      .catch(() => undefined);
  }, []);

  const act = async (path: string, body: Record<string, unknown>, confirmText?: string) => {
    if (confirmText && !window.confirm(confirmText)) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fetchApi<{ message: string }>(`/report-cards/design-requests/${request.id}/${path}`, { method: 'POST', body: JSON.stringify(body) });
      setNotice(res.message);
      onChanged();
    } catch (err) {
      setError(errorText(err, 'That did not work.'));
    } finally {
      setBusy(false);
    }
  };

  const addFiles = async () => {
    const form = new FormData();
    appendFiles(form, more);
    setBusy(true);
    setError(null);
    try {
      await uploadApi(`/report-cards/design-requests/${request.id}/files`, form);
      setMore([]);
      onChanged();
    } catch (err) {
      setError(errorText(err, 'Could not add those files.'));
    } finally {
      setBusy(false);
    }
  };

  const status = STATUS[request.status];

  return (
    <div className="space-y-6">
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-sm font-semibold text-ink">Design request #{request.id}</p>
            <p className="text-xs text-ink-muted">Sent {when(request.submitted_at)}</p>
          </div>
          <Badge tone={status.tone}>{status.label}</Badge>
        </div>
        {request.operator_notes && (
          <p className="mt-3 rounded-lg bg-info-soft px-3 py-2 text-sm text-info">
            <strong>From SchoolPilot:</strong> {request.operator_notes}
          </p>
        )}
        {request.status === 'changes_requested' && request.school_feedback && (
          <p className="mt-3 rounded-lg bg-caution-soft px-3 py-2 text-sm text-caution">
            <strong>You asked for:</strong> {request.school_feedback}
          </p>
        )}
      </Card>

      <ErrorNote message={error} />
      {notice && <p role="status" className="rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {request.status === 'template_ready' && request.template && (
        <Card title="Check your new report card" description="Shown with made-up sample data. Look at every part before you approve it.">
          <iframe
            title="Report card preview"
            src={`/api/backend/report-cards/design-requests/${request.id}/preview`}
            sandbox=""
            className="h-[70vh] w-full rounded-lg border border-line bg-white"
          />
          <div className="mt-5 grid gap-6 lg:grid-cols-2">
            <div>
              <h3 className="mb-2 text-sm font-semibold text-ink">Happy with it?</h3>
              {request.grading_scales && currentTerm && (
                <label className="mb-3 flex items-start gap-2 text-sm text-ink">
                  <input type="checkbox" className="mt-1 h-4 w-4 accent-brand" checked={regrade} onChange={(e) => setRegrade(e.target.checked)} />
                  <span>
                    Re-grade {currentTerm.name}&rsquo;s scores on the new grading
                    <span className="block text-xs text-ink-muted">Earlier terms keep the grades already printed.</span>
                  </span>
                </label>
              )}
              <Button
                variant="accent"
                disabled={busy}
                onClick={() => act('approve', { regrade_term_id: regrade && currentTerm ? currentTerm.id : null }, 'Approve this design? It will be used for every report card from now on.')}
              >
                Approve and use this design
              </Button>
            </div>
            <div>
              <h3 className="mb-2 text-sm font-semibold text-ink">Needs changes?</h3>
              <textarea rows={3} value={feedback} onChange={(e) => setFeedback(e.target.value)} className={inputClass} placeholder="Tell SchoolPilot exactly what to change." />
              <Button className="mt-2" variant="secondary" disabled={busy || !feedback.trim()} onClick={() => act('request-changes', { feedback: feedback.trim() })}>
                Send back
              </Button>
            </div>
          </div>
        </Card>
      )}

      <Card title="Files you sent">
        {request.files.length === 0 ? (
          <Empty>No files.</Empty>
        ) : (
          <ul className="space-y-1 text-sm">
            {request.files.map((f) => (
              <li key={f.id} className="flex items-center justify-between gap-3">
                <a href={`/api/backend/report-cards/design-requests/${request.id}/files/${f.id}`} className="truncate text-brand hover:underline">
                  {f.original_name}
                </a>
                <span className="shrink-0 text-xs text-ink-muted">{meta.file_kinds[f.kind] ?? f.kind}{f.label ? ` · ${f.label}` : ''}</span>
              </li>
            ))}
          </ul>
        )}
        <div className="mt-4">
          <FilePicker rows={more} setRows={setMore} kinds={meta.file_kinds} />
          {more.some((r) => r.file) && (
            <Button className="mt-2" variant="secondary" disabled={busy} onClick={addFiles}>Add files</Button>
          )}
        </div>
      </Card>

      <div>
        <Button variant="secondary" disabled={busy} onClick={() => act('cancel', {}, 'Cancel this request? Its files will be deleted.')}>
          Cancel request
        </Button>
      </div>
    </div>
  );
}

// --------------------------------------------------------------- grading

type LevelScale = { level: string; label: string; custom: boolean; in_use: boolean; bands: Band[]; updated_at: string | null };

function GradingTab() {
  const [levels, setLevels] = useState<LevelScale[] | null>(null);
  const [scheme, setScheme] = useState<{ name: string; first_ca: number; second_ca: number; exam: number } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ levels: LevelScale[]; ca_scheme: typeof scheme }>('/grading-scales');
      setLevels(res.levels);
      setScheme(res.ca_scheme);
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load grading scales.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  if (!levels) return error ? <ErrorNote message={error} /> : <Loading />;

  return (
    <div className="space-y-6">
      <p className="text-sm text-ink-muted">
        Grades on report cards and the broadsheet come from these scales. A level without its own scale uses &ldquo;All
        classes&rdquo;; with nothing set, the WAEC A1–F9 scale is used.
        {scheme && ` Marks are split ${scheme.first_ca} / ${scheme.second_ca} / ${scheme.exam} (${scheme.name}).`}
      </p>
      {levels.filter((l) => l.in_use || l.custom).map((l) => (
        <LevelEditor key={l.level} scale={l} onSaved={load} />
      ))}
    </div>
  );
}

function LevelEditor({ scale, onSaved }: { scale: LevelScale; onSaved: () => void }) {
  const [bands, setBands] = useState<Band[]>(scale.bands);
  const [regrade, setRegrade] = useState(true);
  const [termId, setTermId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    fetchApi<{ current_term_id: number | null }>('/terms').then((r) => setTermId(r.current_term_id)).catch(() => undefined);
  }, []);

  const save = async () => {
    setBusy(true);
    setMessage(null);
    try {
      const res = await fetchApi<{ message: string }>(`/grading-scales/${scale.level}`, {
        method: 'PUT',
        body: JSON.stringify({ bands: cleanBands(bands), regrade_term_id: regrade ? termId : null }),
      });
      setMessage({ ok: true, text: res.message });
      onSaved();
    } catch (err) {
      setMessage({ ok: false, text: errorText(err, 'Could not save.') });
    } finally {
      setBusy(false);
    }
  };

  const reset = async () => {
    if (!window.confirm(`Stop using a separate scale for ${scale.label}?`)) return;
    try {
      await fetchApi(`/grading-scales/${scale.level}`, { method: 'DELETE' });
      onSaved();
    } catch (err) {
      setMessage({ ok: false, text: errorText(err, 'Could not remove it.') });
    }
  };

  return (
    <Card title={scale.label} description={scale.custom ? `Your own scale · saved ${when(scale.updated_at)}` : scale.level === 'default' ? 'Using WAEC A1–F9' : 'Using the All classes scale'}>
      <GradingBandsEditor bands={bands} onChange={setBands} />
      <div className="mt-4 flex flex-wrap items-center gap-4">
        {termId && (
          <label className="flex items-center gap-2 text-xs text-ink">
            <input type="checkbox" className="h-4 w-4 accent-brand" checked={regrade} onChange={(e) => setRegrade(e.target.checked)} />
            Re-grade this term&rsquo;s scores (earlier terms are left as printed)
          </label>
        )}
        <Button disabled={busy || !!bandProblem(bands)} onClick={save}>{busy ? 'Saving…' : 'Save scale'}</Button>
        {scale.custom && scale.level !== 'default' && (
          <button type="button" onClick={reset} className="text-xs text-ink-muted hover:underline">Use the All classes scale instead</button>
        )}
      </div>
      {message && <p className={`mt-2 text-sm ${message.ok ? 'text-positive' : 'text-negative'}`}>{message.text}</p>}
    </Card>
  );
}
