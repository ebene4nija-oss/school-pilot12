'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Sidebar from '@/components/Sidebar';
import { fetchApi, ApiError } from '@/lib/api';

/**
 * ID card printing and design.
 *
 * The screen is built around the one thing that goes wrong in practice:
 * printing before the data is ready. A school queues "JSS 2", walks to the
 * printer, and finds out on sheet eleven that four children have no photo.
 * So preflight is not a hidden validation step — it is the main event. You
 * check first, you see exactly who is missing and how many sheets you are
 * about to consume, and only then is the Queue button worth pressing.
 */

type HolderType = 'student' | 'staff';

type Blocked = { holder_id: number; label: string; reason: string; detail: string };

type Preflight = {
  total: number;
  eligible: number;
  printable: number;
  blocked: Blocked[];
  warnings: { holder_id: number; label: string; detail: string }[];
  truncated: boolean;
  max_per_run: number;
  estimated_sheets: number;
  layout: {
    columns: number;
    rows: number;
    per_sheet: number;
    sheet: string;
    duplex: string;
    cut_guides: string;
  };
  template: { id: number; name: string; version: number; double_sided: boolean } | null;
};

type PrintRun = {
  id: number;
  holder_type: HolderType;
  status: string;
  card_count: number;
  sheet_count: number;
  error: string | null;
  downloadable: boolean;
  expires_in_days: number | null;
  created_at: string;
};

type Template = {
  id: number;
  name: string;
  version: number;
  holder_type: HolderType;
  status: string;
  description: string | null;
  card_geometry: { width_mm: number; height_mm: number; orientation: string } | null;
  validation_report: {
    warnings?: string[];
    markup_removals?: string[];
    style_removals?: string[];
    double_sided?: boolean;
  } | null;
};

type SchoolClass = { id: number; name: string };

const REASON_TONE: Record<string, string> = {
  no_photo: 'bg-amber-500/10 text-amber-300 border-amber-500/30',
  photo_not_found: 'bg-amber-500/10 text-amber-300 border-amber-500/30',
  no_name: 'bg-rose-500/10 text-rose-300 border-rose-500/30',
};

export default function IdCardsPage() {
  const [tab, setTab] = useState<'print' | 'designs'>('print');

  return (
    <div className="flex min-h-screen bg-slate-950 text-slate-200">
      <Sidebar />
      <main className="flex-1 overflow-x-hidden p-8">
        <header className="mb-6">
          <h1 className="text-2xl font-bold text-white">ID Cards</h1>
          <p className="mt-1 max-w-3xl text-sm text-slate-400">
            Printable PDF sheets, ten cards to a page, for the printer you already have.
            Each card carries a QR code that anyone can scan to check the card is genuine
            and still current — no reader, no scanner, no special hardware.
          </p>
        </header>

        <nav className="mb-6 flex gap-1 border-b border-slate-800">
          {(
            [
              ['print', 'Print cards'],
              ['designs', 'Card designs'],
            ] as const
          ).map(([key, label]) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium transition ${
                tab === key
                  ? 'border-emerald-500 text-white'
                  : 'border-transparent text-slate-400 hover:text-slate-200'
              }`}
            >
              {label}
            </button>
          ))}
        </nav>

        {tab === 'print' ? <PrintPanel /> : <DesignPanel />}
      </main>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Printing
// ---------------------------------------------------------------------------

function PrintPanel() {
  const [holderType, setHolderType] = useState<HolderType>('student');
  const [classId, setClassId] = useState('');
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [sheet, setSheet] = useState('A4');
  const [duplex, setDuplex] = useState('long-edge');
  const [cutGuides, setCutGuides] = useState('border');
  const [marginMm, setMarginMm] = useState('10');
  const [allowMissingPhotos, setAllowMissingPhotos] = useState(false);
  const [includeBloodGroup, setIncludeBloodGroup] = useState(false);

  const [runs, setRuns] = useState<PrintRun[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const payload = {
    holder_type: holderType,
    class_id: holderType === 'student' && classId ? Number(classId) : undefined,
    sheet,
    duplex,
    cut_guides: cutGuides,
    margin_mm: Number(marginMm) || 10,
    allow_missing_photos: allowMissingPhotos,
    include_blood_group: includeBloodGroup,
  };

  /*
   * The report is stored with the selection that produced it, and shown only
   * while the two still agree.
   *
   * Deriving staleness rather than clearing it from an effect matters here:
   * leaving a stale report on screen is the exact failure this panel exists to
   * prevent. "42 ready to print" left over from the previous class is worse
   * than no number at all, and an effect that clears it runs a render too late.
   */
  const signature = JSON.stringify(payload);
  const [result, setResult] = useState<{ signature: string; report: Preflight } | null>(null);
  const preflight = result?.signature === signature ? result.report : null;

  const loadRuns = async () => {
    try {
      const data = await fetchApi<{ runs: PrintRun[] }>('/id-cards/print-runs');
      setRuns(data.runs ?? []);
    } catch {
      /* the runs table is secondary; a failure here should not blank the page */
    }
  };

  useEffect(() => {
    let cancelled = false;

    fetchApi<{ classes?: SchoolClass[] } | SchoolClass[]>('/classes')
      .then((data) => {
        if (!cancelled) setClasses(Array.isArray(data) ? data : (data.classes ?? []));
      })
      .catch(() => {
        if (!cancelled) setClasses([]);
      });

    fetchApi<{ runs: PrintRun[] }>('/id-cards/print-runs')
      .then((data) => {
        if (!cancelled) setRuns(data.runs ?? []);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, []);

  const runPreflight = async () => {
    setBusy(true);
    setError(null);
    try {
      const report = await fetchApi<Preflight>('/id-cards/preflight', {
        method: 'POST',
        body: JSON.stringify(payload),
      });
      setResult({ signature, report });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not run the check.');
    } finally {
      setBusy(false);
    }
  };

  const queueRun = async (proceedWithBlocked: boolean) => {
    setBusy(true);
    setError(null);
    try {
      const data = await fetchApi<{ message: string }>('/id-cards/print-runs', {
        method: 'POST',
        body: JSON.stringify({ ...payload, proceed_with_blocked: proceedWithBlocked }),
      });
      setNotice(data.message);
      setResult(null);
      // The job runs on the queue, so the row appears as "queued" and settles
      // a few seconds later. Poll briefly rather than making the user refresh.
      await loadRuns();
      setTimeout(loadRuns, 4000);
      setTimeout(loadRuns, 12000);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not queue the run.');
    } finally {
      setBusy(false);
    }
  };

  const blockedCount = preflight?.blocked.length ?? 0;

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
      <section className="space-y-4 rounded-lg border border-slate-800 bg-slate-900 p-5">
        <h2 className="font-semibold text-white">Who and how</h2>

        <Field label="Cards for">
          <select
            value={holderType}
            onChange={(e) => setHolderType(e.target.value as HolderType)}
            className={inputClass}
          >
            <option value="student">Students</option>
            <option value="staff">Staff</option>
          </select>
        </Field>

        {holderType === 'student' && (
          <Field label="Class" hint="Leave blank for every active student.">
            <select value={classId} onChange={(e) => setClassId(e.target.value)} className={inputClass}>
              <option value="">All classes</option>
              {classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </Field>
        )}

        <div className="grid grid-cols-2 gap-3">
          <Field label="Paper">
            <select value={sheet} onChange={(e) => setSheet(e.target.value)} className={inputClass}>
              {['A4', 'A3', 'LETTER', 'LEGAL'].map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Margin (mm)">
            <input
              type="number"
              min={5}
              max={40}
              value={marginMm}
              onChange={(e) => setMarginMm(e.target.value)}
              className={inputClass}
            />
          </Field>
        </div>

        <Field
          label="Double-sided flip"
          hint="Must match your printer. If backs land on the wrong cards, switch this."
        >
          <select value={duplex} onChange={(e) => setDuplex(e.target.value)} className={inputClass}>
            <option value="long-edge">Flip on long edge (most printers)</option>
            <option value="short-edge">Flip on short edge</option>
            <option value="none">Fronts only</option>
          </select>
        </Field>

        <Field label="Cut guides">
          <select value={cutGuides} onChange={(e) => setCutGuides(e.target.value)} className={inputClass}>
            <option value="border">Hairline on the cut line</option>
            <option value="marks">Corner marks (for a guillotine)</option>
            <option value="none">None</option>
          </select>
        </Field>

        <label className="flex items-start gap-2 text-sm text-slate-300">
          <input
            type="checkbox"
            checked={allowMissingPhotos}
            onChange={(e) => setAllowMissingPhotos(e.target.checked)}
            className="mt-1"
          />
          <span>
            Print cards for people with no photo
            <span className="block text-xs text-slate-500">
              Leaves an empty photo window to be filled in later.
            </span>
          </span>
        </label>

        {holderType === 'student' && (
          <label className="flex items-start gap-2 text-sm text-slate-300">
            <input
              type="checkbox"
              checked={includeBloodGroup}
              onChange={(e) => setIncludeBloodGroup(e.target.checked)}
              className="mt-1"
            />
            <span>
              Print blood group
              <span className="block text-xs text-slate-500">
                Useful in an emergency, but it is protected health data on a card that can be
                lost. Off unless you choose it.
              </span>
            </span>
          </label>
        )}

        <button
          onClick={runPreflight}
          disabled={busy}
          className="w-full rounded bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-600 disabled:opacity-50"
        >
          {busy ? 'Checking…' : 'Check before printing'}
        </button>
      </section>

      <div className="space-y-6">
        {error && <Banner tone="error">{error}</Banner>}
        {notice && <Banner tone="ok">{notice}</Banner>}

        {preflight && (
          <section className="rounded-lg border border-slate-800 bg-slate-900 p-5">
            <h2 className="mb-4 font-semibold text-white">Before you print</h2>

            <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Stat label="Ready to print" value={preflight.printable} tone="ok" />
              <Stat label="Cannot print" value={blockedCount} tone={blockedCount ? 'warn' : 'muted'} />
              <Stat label="Sheets of paper" value={preflight.estimated_sheets} />
              <Stat label="Per sheet" value={preflight.layout.per_sheet} />
            </div>

            <p className="mb-4 text-xs text-slate-400">
              {preflight.layout.columns} × {preflight.layout.rows} on {preflight.layout.sheet}
              {preflight.template
                ? ` · design “${preflight.template.name}” v${preflight.template.version}${
                    preflight.template.double_sided ? ', double-sided' : ''
                  }`
                : ' · the built-in design (no custom design activated)'}
              {preflight.layout.duplex !== 'none' && ` · ${preflight.layout.duplex} flip`}
            </p>

            {preflight.truncated && (
              <Banner tone="warn">
                This selection is larger than {preflight.max_per_run} cards. Only the first{' '}
                {preflight.max_per_run} will be printed — run it class by class instead.
              </Banner>
            )}

            {blockedCount > 0 && (
              <div className="mb-4 rounded border border-amber-500/30 bg-amber-500/5 p-4">
                <h3 className="mb-2 text-sm font-semibold text-amber-300">
                  {blockedCount} {blockedCount === 1 ? 'person' : 'people'} cannot be printed yet
                </h3>
                <ul className="max-h-56 space-y-1 overflow-y-auto text-sm">
                  {preflight.blocked.map((b) => (
                    <li key={`${b.holder_id}-${b.reason}`} className="flex items-center gap-2">
                      <span
                        className={`rounded border px-1.5 py-0.5 text-[10px] uppercase tracking-wide ${
                          REASON_TONE[b.reason] ?? 'border-slate-600 bg-slate-800 text-slate-300'
                        }`}
                      >
                        {b.reason.replace(/_/g, ' ')}
                      </span>
                      <span className="text-slate-200">{b.label}</span>
                      <span className="truncate text-xs text-slate-500">{b.detail}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {preflight.warnings.length > 0 && (
              <details className="mb-4 rounded border border-slate-700 bg-slate-950/50 p-3 text-sm">
                <summary className="cursor-pointer text-slate-300">
                  {preflight.warnings.length} photo{preflight.warnings.length === 1 ? '' : 's'} will
                  print but could be better
                </summary>
                <ul className="mt-2 space-y-1 text-xs text-slate-400">
                  {preflight.warnings.map((w, i) => (
                    <li key={i}>
                      <span className="text-slate-300">{w.label}</span> — {w.detail}
                    </li>
                  ))}
                </ul>
              </details>
            )}

            <div className="flex flex-wrap gap-3">
              <button
                onClick={() => queueRun(blockedCount > 0)}
                disabled={busy || preflight.printable < 1}
                className="rounded bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
              >
                {blockedCount > 0
                  ? `Print the ${preflight.printable} that are ready`
                  : `Print ${preflight.printable} card${preflight.printable === 1 ? '' : 's'}`}
              </button>
              {blockedCount > 0 && (
                <span className="self-center text-xs text-slate-500">
                  The {blockedCount} above will be left out. Fix them and run again to catch up.
                </span>
              )}
            </div>
          </section>
        )}

        <section className="rounded-lg border border-slate-800 bg-slate-900 p-5">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="font-semibold text-white">Recent print runs</h2>
            <button onClick={loadRuns} className="text-xs text-slate-400 hover:text-slate-200">
              Refresh
            </button>
          </div>

          {runs.length === 0 ? (
            <p className="text-sm text-slate-500">Nothing printed yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="py-2">Run</th>
                    <th>For</th>
                    <th>Status</th>
                    <th>Cards</th>
                    <th>Sheets</th>
                    <th />
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800">
                  {runs.map((run) => (
                    <tr key={run.id}>
                      <td className="py-2 text-slate-400">#{run.id}</td>
                      <td className="capitalize">{run.holder_type}</td>
                      <td>
                        <RunStatus run={run} />
                      </td>
                      <td>{run.card_count || '—'}</td>
                      <td>{run.sheet_count || '—'}</td>
                      <td className="text-right">
                        {run.downloadable ? (
                          <a
                            href={`/api/backend/id-cards/print-runs/${run.id}/download`}
                            className="rounded bg-slate-700 px-3 py-1 text-xs font-semibold text-white hover:bg-slate-600"
                          >
                            Download PDF
                            {run.expires_in_days !== null && run.expires_in_days <= 2 && (
                              <span className="ml-1 text-amber-300">
                                ({run.expires_in_days}d left)
                              </span>
                            )}
                          </a>
                        ) : (
                          <span className="text-xs text-slate-600">—</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <p className="mt-3 text-xs text-slate-500">
                Print-run PDFs are deleted after 7 days — they hold every child&rsquo;s name and
                face. Queue the run again if you need to reprint.
              </p>
            </div>
          )}
        </section>
      </div>
    </div>
  );
}

function RunStatus({ run }: { run: PrintRun }) {
  const map: Record<string, string> = {
    completed: 'text-emerald-400',
    queued: 'text-sky-400',
    rendering: 'text-sky-400',
    failed: 'text-rose-400',
    preflight_failed: 'text-amber-400',
  };

  return (
    <span className={map[run.status] ?? 'text-slate-400'} title={run.error ?? undefined}>
      {run.status.replace(/_/g, ' ')}
    </span>
  );
}

// ---------------------------------------------------------------------------
// Designs
// ---------------------------------------------------------------------------

function DesignPanel() {
  const [templates, setTemplates] = useState<Template[]>([]);
  const [bundle, setBundle] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const data = await fetchApi<{ templates: Template[] }>('/id-cards/templates');
      setTemplates(data.templates ?? []);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not load designs.');
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    fetchApi<{ templates: Template[] }>('/id-cards/templates')
      .then((data) => {
        if (!cancelled) setTemplates(data.templates ?? []);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Could not load designs.');
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const act = async (fn: () => Promise<{ message: string }>) => {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const data = await fn();
      setNotice(data.message);
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'That did not work.');
    } finally {
      setBusy(false);
    }
  };

  const importBundle = () => {
    let parsed: unknown;
    try {
      parsed = JSON.parse(bundle);
    } catch {
      setError('That is not valid JSON.');
      return;
    }

    act(async () => {
      const data = await fetchApi<{ message: string }>('/id-cards/templates', {
        method: 'POST',
        body: JSON.stringify({ bundle: parsed }),
      });
      setBundle('');
      return data;
    });
  };

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
      <section className="space-y-4">
        {error && <Banner tone="error">{error}</Banner>}
        {notice && <Banner tone="ok">{notice}</Banner>}

        {templates.length === 0 ? (
          <div className="rounded-lg border border-slate-800 bg-slate-900 p-5 text-sm text-slate-400">
            No custom designs yet. Cards print with the built-in design, which is a complete,
            correct card — importing one is how a school puts its own crest and colours on it.
          </div>
        ) : (
          templates.map((template) => {
            const removals = [
              ...(template.validation_report?.markup_removals ?? []),
              ...(template.validation_report?.style_removals ?? []),
            ];

            return (
              <article
                key={template.id}
                className="rounded-lg border border-slate-800 bg-slate-900 p-5"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h3 className="font-semibold text-white">
                      {template.name}{' '}
                      <span className="text-sm font-normal text-slate-500">v{template.version}</span>
                    </h3>
                    <p className="mt-1 text-xs text-slate-400">
                      <span className="capitalize">{template.holder_type}</span> cards
                      {template.card_geometry &&
                        ` · ${template.card_geometry.width_mm} × ${template.card_geometry.height_mm}mm ${template.card_geometry.orientation}`}
                      {template.validation_report?.double_sided ? ' · double-sided' : ' · single-sided'}
                    </p>
                    {template.description && (
                      <p className="mt-2 text-sm text-slate-400">{template.description}</p>
                    )}
                  </div>
                  <span
                    className={`rounded px-2 py-0.5 text-xs font-semibold ${
                      template.status === 'active'
                        ? 'bg-emerald-500/15 text-emerald-300'
                        : 'bg-slate-700/50 text-slate-400'
                    }`}
                  >
                    {template.status}
                  </span>
                </div>

                {removals.length > 0 && (
                  <details className="mt-3 rounded border border-amber-500/30 bg-amber-500/5 p-3 text-xs">
                    <summary className="cursor-pointer text-amber-300">
                      {removals.length} thing{removals.length === 1 ? '' : 's'} were stripped from
                      this design on import
                    </summary>
                    <ul className="mt-2 space-y-1 text-slate-400">
                      {removals.map((r, i) => (
                        <li key={i}>{r}</li>
                      ))}
                    </ul>
                  </details>
                )}

                <div className="mt-4 flex flex-wrap gap-2 text-xs">
                  <a
                    href={`/api/backend/id-cards/templates/${template.id}/preview`}
                    target="_blank"
                    rel="noreferrer"
                    className="rounded bg-slate-700 px-3 py-1.5 font-semibold text-white hover:bg-slate-600"
                  >
                    Preview card
                  </a>
                  <a
                    href={`/api/backend/id-cards/templates/${template.id}/preview?view=sheet`}
                    target="_blank"
                    rel="noreferrer"
                    className="rounded bg-slate-700 px-3 py-1.5 font-semibold text-white hover:bg-slate-600"
                  >
                    Preview full sheet
                  </a>
                  {template.status !== 'active' && (
                    <>
                      <button
                        disabled={busy}
                        onClick={() =>
                          act(() =>
                            fetchApi(`/id-cards/templates/${template.id}/activate`, {
                              method: 'POST',
                            }),
                          )
                        }
                        className="rounded bg-emerald-600 px-3 py-1.5 font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                      >
                        Make this the live design
                      </button>
                      <button
                        disabled={busy}
                        onClick={() =>
                          act(() =>
                            fetchApi(`/id-cards/templates/${template.id}`, { method: 'DELETE' }),
                          )
                        }
                        className="rounded px-3 py-1.5 text-slate-500 hover:text-rose-300 disabled:opacity-50"
                      >
                        Delete
                      </button>
                    </>
                  )}
                </div>
              </article>
            );
          })
        )}
      </section>

      <section className="space-y-3 rounded-lg border border-slate-800 bg-slate-900 p-5">
        <h2 className="font-semibold text-white">Import a design</h2>
        <p className="text-sm text-slate-400">
          Paste a design bundle. It lands as a draft — preview it, then make it live when you are
          happy. Nothing changes what you print until you activate it.
        </p>
        <a
          href="/api/backend/id-cards/template-contract"
          target="_blank"
          rel="noreferrer"
          className="inline-block text-xs text-emerald-400 hover:underline"
        >
          Read the design contract and copy the built-in design →
        </a>
        <textarea
          value={bundle}
          onChange={(e) => setBundle(e.target.value)}
          rows={14}
          spellCheck={false}
          placeholder='{ "engine": "schoolpilot-id-card/v1", "name": "…", "front": "…" }'
          className="w-full rounded border border-slate-700 bg-slate-950 p-3 font-mono text-xs text-slate-200"
        />
        <button
          onClick={importBundle}
          disabled={busy || !bundle.trim()}
          className="w-full rounded bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
        >
          {busy ? 'Importing…' : 'Import as draft'}
        </button>
      </section>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Small shared pieces
// ---------------------------------------------------------------------------

const inputClass =
  'w-full rounded border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 focus:border-emerald-500 focus:outline-none';

function Field({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">
        {label}
      </span>
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
    </label>
  );
}

function Stat({
  label,
  value,
  tone = 'muted',
}: {
  label: string;
  value: number;
  tone?: 'ok' | 'warn' | 'muted';
}) {
  const colour =
    tone === 'ok' ? 'text-emerald-400' : tone === 'warn' ? 'text-amber-400' : 'text-slate-200';

  return (
    <div className="rounded border border-slate-800 bg-slate-950 p-3">
      <div className={`text-2xl font-bold ${colour}`}>{value}</div>
      <div className="text-xs text-slate-500">{label}</div>
    </div>
  );
}

function Banner({ tone, children }: { tone: 'ok' | 'warn' | 'error'; children: React.ReactNode }) {
  const styles = {
    ok: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200',
    warn: 'border-amber-500/30 bg-amber-500/10 text-amber-200',
    error: 'border-rose-500/30 bg-rose-500/10 text-rose-200',
  }[tone];

  return <div className={`mb-4 rounded border p-3 text-sm ${styles}`}>{children}</div>;
}
