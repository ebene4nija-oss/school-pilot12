'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Sidebar from '@/components/Sidebar';
import { fetchApi, ApiError } from '@/lib/api';
import IdCardRegister from '@/components/IdCardRegister';
import DesignGallery from './DesignGallery';

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
  physical_status?: string;
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
  no_photo: 'bg-caution-soft text-caution border-accent/30',
  photo_not_found: 'bg-caution-soft text-caution border-accent/30',
  no_name: 'bg-negative-soft text-negative border-negative/30',
};

export default function IdCardsPage() {
  const [tab, setTab] = useState<'print' | 'cards' | 'designs'>('print');

  return (
    <div className="flex min-h-screen bg-canvas text-ink">
      <Sidebar />
      <main className="flex-1 overflow-x-hidden p-8">
        <header className="mb-6">
          <h1 className="text-2xl font-bold text-ink">ID Cards</h1>
          <p className="mt-1 max-w-3xl text-sm text-ink-muted">
            Printable PDF sheets, ten cards to a page, for the printer you already have.
            Each card carries a QR code that anyone can scan to check the card is genuine
            and still current — no reader, no scanner, no special hardware.
          </p>
        </header>

        <nav className="mb-6 flex gap-1 border-b border-line-soft">
          {(
            [
              ['print', 'Print cards'],
              ['cards', 'Cards & issuance'],
              ['designs', 'Card designs'],
            ] as const
          ).map(([key, label]) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium transition ${
                tab === key
                  ? 'border-brand text-ink'
                  : 'border-transparent text-ink-muted hover:text-ink'
              }`}
            >
              {label}
            </button>
          ))}
        </nav>

        {tab === 'print' ? <PrintPanel /> : tab === 'cards' ? <IdCardRegister /> : <DesignPanel />}
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
      <section className="space-y-4 rounded-lg border border-line-soft bg-surface p-5">
        <h2 className="font-semibold text-ink">Who and how</h2>

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

        <label className="flex items-start gap-2 text-sm text-ink">
          <input
            type="checkbox"
            checked={allowMissingPhotos}
            onChange={(e) => setAllowMissingPhotos(e.target.checked)}
            className="mt-1"
          />
          <span>
            Print cards for people with no photo
            <span className="block text-xs text-ink-faint">
              Leaves an empty photo window to be filled in later.
            </span>
          </span>
        </label>

        {holderType === 'student' && (
          <label className="flex items-start gap-2 text-sm text-ink">
            <input
              type="checkbox"
              checked={includeBloodGroup}
              onChange={(e) => setIncludeBloodGroup(e.target.checked)}
              className="mt-1"
            />
            <span>
              Print blood group
              <span className="block text-xs text-ink-faint">
                Useful in an emergency, but it is protected health data on a card that can be
                lost. Off unless you choose it.
              </span>
            </span>
          </label>
        )}

        <button
          onClick={runPreflight}
          disabled={busy}
          className="w-full rounded bg-surface-raised px-4 py-2 text-sm font-semibold text-ink hover:bg-line disabled:opacity-50"
        >
          {busy ? 'Checking…' : 'Check before printing'}
        </button>
      </section>

      <div className="space-y-6">
        {error && <Banner tone="error">{error}</Banner>}
        {notice && <Banner tone="ok">{notice}</Banner>}

        {preflight && (
          <section className="rounded-lg border border-line-soft bg-surface p-5">
            <h2 className="mb-4 font-semibold text-ink">Before you print</h2>

            <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Stat label="Ready to print" value={preflight.printable} tone="ok" />
              <Stat label="Cannot print" value={blockedCount} tone={blockedCount ? 'warn' : 'muted'} />
              <Stat label="Sheets of paper" value={preflight.estimated_sheets} />
              <Stat label="Per sheet" value={preflight.layout.per_sheet} />
            </div>

            <p className="mb-4 text-xs text-ink-muted">
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
              <div className="mb-4 rounded border border-accent/30 bg-accent/5 p-4">
                <h3 className="mb-2 text-sm font-semibold text-caution">
                  {blockedCount} {blockedCount === 1 ? 'person' : 'people'} cannot be printed yet
                </h3>
                <ul className="max-h-56 space-y-1 overflow-y-auto text-sm">
                  {preflight.blocked.map((b) => (
                    <li key={`${b.holder_id}-${b.reason}`} className="flex items-center gap-2">
                      <span
                        className={`rounded border px-1.5 py-0.5 text-[10px] uppercase tracking-wide ${
                          REASON_TONE[b.reason] ?? 'border-line bg-surface-raised text-ink'
                        }`}
                      >
                        {b.reason.replace(/_/g, ' ')}
                      </span>
                      <span className="text-ink">{b.label}</span>
                      <span className="truncate text-xs text-ink-faint">{b.detail}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {preflight.warnings.length > 0 && (
              <details className="mb-4 rounded border border-line bg-canvas/50 p-3 text-sm">
                <summary className="cursor-pointer text-ink">
                  {preflight.warnings.length} photo{preflight.warnings.length === 1 ? '' : 's'} will
                  print but could be better
                </summary>
                <ul className="mt-2 space-y-1 text-xs text-ink-muted">
                  {preflight.warnings.map((w, i) => (
                    <li key={i}>
                      <span className="text-ink">{w.label}</span> — {w.detail}
                    </li>
                  ))}
                </ul>
              </details>
            )}

            <div className="flex flex-wrap gap-3">
              <button
                onClick={() => queueRun(blockedCount > 0)}
                disabled={busy || preflight.printable < 1}
                className="rounded bg-brand-soft px-4 py-2 text-sm font-semibold text-white hover:bg-brand disabled:opacity-50"
              >
                {blockedCount > 0
                  ? `Print the ${preflight.printable} that are ready`
                  : `Print ${preflight.printable} card${preflight.printable === 1 ? '' : 's'}`}
              </button>
              {blockedCount > 0 && (
                <span className="self-center text-xs text-ink-faint">
                  The {blockedCount} above will be left out. Fix them and run again to catch up.
                </span>
              )}
            </div>
          </section>
        )}

        <section className="rounded-lg border border-line-soft bg-surface p-5">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="font-semibold text-ink">Recent print runs</h2>
            <button onClick={loadRuns} className="text-xs text-ink-muted hover:text-ink">
              Refresh
            </button>
          </div>

          {runs.length === 0 ? (
            <p className="text-sm text-ink-faint">Nothing printed yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="text-xs uppercase tracking-wide text-ink-faint">
                  <tr>
                    <th className="py-2">Run</th>
                    <th>For</th>
                    <th>Status</th>
                    <th>Cards</th>
                    <th>Sheets</th>
                    <th />
                  </tr>
                </thead>
                <tbody className="divide-y divide-line-soft">
                  {runs.map((run) => (
                    <tr key={run.id}>
                      <td className="py-2 text-ink-muted">#{run.id}</td>
                      <td className="capitalize">{run.holder_type}</td>
                      <td>
                        <RunStatus run={run} />
                      </td>
                      <td>{run.card_count || '—'}</td>
                      <td>{run.sheet_count || '—'}</td>
                      <td className="space-x-2 whitespace-nowrap text-right">
                        {run.status === 'completed' && run.physical_status !== 'printed' && (
                          <button
                            onClick={async () => {
                              // Downloading the PDF prints nothing: this is the step that says the sheets exist.
                              if (!window.confirm(`Confirm run #${run.id} has been physically printed? Its cards move to "awaiting collection".`)) return;
                              try {
                                await fetchApi(`/id-cards/print-runs/${run.id}/confirm-printed`, { method: 'POST', body: '{}' });
                                await loadRuns();
                              } catch (err) {
                                setError(err instanceof ApiError ? err.message : 'Could not confirm the print.');
                              }
                            }}
                            className="rounded bg-brand px-3 py-1 text-xs font-semibold text-white hover:bg-brand-soft"
                          >
                            Mark printed
                          </button>
                        )}
                        {run.physical_status === 'printed' && <span className="text-xs text-positive">Printed</span>}
                        {run.downloadable ? (
                          <a
                            href={`/api/backend/id-cards/print-runs/${run.id}/download`}
                            className="rounded bg-surface-raised px-3 py-1 text-xs font-semibold text-ink hover:bg-line"
                          >
                            Download PDF
                            {run.expires_in_days !== null && run.expires_in_days <= 2 && (
                              <span className="ml-1 text-caution">
                                ({run.expires_in_days}d left)
                              </span>
                            )}
                          </a>
                        ) : (
                          <span className="text-xs text-ink-muted">—</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <p className="mt-3 text-xs text-ink-faint">
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
    completed: 'text-positive',
    queued: 'text-info',
    rendering: 'text-info',
    failed: 'text-negative',
    preflight_failed: 'text-caution',
  };

  return (
    <span className={map[run.status] ?? 'text-ink-muted'} title={run.error ?? undefined}>
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
    <div className="space-y-6">
    <DesignGallery onChanged={() => { load().catch(() => undefined); }} />
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
      <section className="space-y-4">
        {error && <Banner tone="error">{error}</Banner>}
        {notice && <Banner tone="ok">{notice}</Banner>}

        {templates.length === 0 ? (
          <div className="rounded-lg border border-line-soft bg-surface p-5 text-sm text-ink-muted">
            No designs saved yet. Cards print with the built-in design until you choose one above
            or import your own.
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
                className="rounded-lg border border-line-soft bg-surface p-5"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h3 className="font-semibold text-ink">
                      {template.name}{' '}
                      <span className="text-sm font-normal text-ink-faint">v{template.version}</span>
                    </h3>
                    <p className="mt-1 text-xs text-ink-muted">
                      <span className="capitalize">{template.holder_type}</span> cards
                      {template.card_geometry &&
                        ` · ${template.card_geometry.width_mm} × ${template.card_geometry.height_mm}mm ${template.card_geometry.orientation}`}
                      {template.validation_report?.double_sided ? ' · double-sided' : ' · single-sided'}
                    </p>
                    {template.description && (
                      <p className="mt-2 text-sm text-ink-muted">{template.description}</p>
                    )}
                  </div>
                  <span
                    className={`rounded px-2 py-0.5 text-xs font-semibold ${
                      template.status === 'active'
                        ? 'bg-brand/15 text-positive'
                        : 'bg-surface-raised/50 text-ink-muted'
                    }`}
                  >
                    {template.status}
                  </span>
                </div>

                {removals.length > 0 && (
                  <details className="mt-3 rounded border border-accent/30 bg-accent/5 p-3 text-xs">
                    <summary className="cursor-pointer text-caution">
                      {removals.length} thing{removals.length === 1 ? '' : 's'} were stripped from
                      this design on import
                    </summary>
                    <ul className="mt-2 space-y-1 text-ink-muted">
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
                    className="rounded bg-surface-raised px-3 py-1.5 font-semibold text-ink hover:bg-line"
                  >
                    Preview card
                  </a>
                  <a
                    href={`/api/backend/id-cards/templates/${template.id}/preview?view=sheet`}
                    target="_blank"
                    rel="noreferrer"
                    className="rounded bg-surface-raised px-3 py-1.5 font-semibold text-ink hover:bg-line"
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
                        className="rounded bg-brand-soft px-3 py-1.5 font-semibold text-white hover:bg-brand disabled:opacity-50"
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
                        className="rounded px-3 py-1.5 text-ink-faint hover:text-negative disabled:opacity-50"
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

      <section className="space-y-3 rounded-lg border border-line-soft bg-surface p-5">
        <h2 className="font-semibold text-ink">Import a design (for designers)</h2>
        <p className="text-sm text-ink-muted">
          Paste a design bundle. It lands as a draft — preview it, then make it live when you are
          happy. Nothing changes what you print until you activate it.
        </p>
        <a
          href="/api/backend/id-cards/template-contract"
          target="_blank"
          rel="noreferrer"
          className="inline-block text-xs text-positive hover:underline"
        >
          Read the design contract and copy the built-in design →
        </a>
        <textarea
          value={bundle}
          onChange={(e) => setBundle(e.target.value)}
          rows={14}
          spellCheck={false}
          placeholder='{ "engine": "schoolpilot-id-card/v1", "name": "…", "front": "…" }'
          className="w-full rounded border border-line bg-canvas p-3 font-mono text-xs text-ink"
        />
        <button
          onClick={importBundle}
          disabled={busy || !bundle.trim()}
          className="w-full rounded bg-brand-soft px-4 py-2 text-sm font-semibold text-white hover:bg-brand disabled:opacity-50"
        >
          {busy ? 'Importing…' : 'Import as draft'}
        </button>
      </section>
    </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Small shared pieces
// ---------------------------------------------------------------------------

const inputClass =
  'w-full rounded border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand focus:outline-none';

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
      <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-faint">
        {label}
      </span>
      {children}
      {hint && <span className="mt-1 block text-xs text-ink-faint">{hint}</span>}
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
    tone === 'ok' ? 'text-positive' : tone === 'warn' ? 'text-caution' : 'text-ink';

  return (
    <div className="rounded border border-line-soft bg-canvas p-3">
      <div className={`text-2xl font-bold ${colour}`}>{value}</div>
      <div className="text-xs text-ink-faint">{label}</div>
    </div>
  );
}

function Banner({ tone, children }: { tone: 'ok' | 'warn' | 'error'; children: React.ReactNode }) {
  const styles = {
    ok: 'border-positive/30 bg-positive-soft text-positive',
    warn: 'border-accent/30 bg-caution-soft text-caution',
    error: 'border-negative/30 bg-negative-soft text-negative',
  }[tone];

  return <div className={`mb-4 rounded border p-3 text-sm ${styles}`}>{children}</div>;
}
