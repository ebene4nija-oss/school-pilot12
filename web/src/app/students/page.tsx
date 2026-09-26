'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Sidebar from '@/components/Sidebar';
import { fetchApi, uploadApi } from '@/lib/api';
import type { Student } from '@/types/api';
import EnrolStudentForm, { type ClassOption } from '@/components/EnrolStudentForm';

type ImportRow = {
  line: number;
  status: 'ready' | 'error';
  errors: string[];
  name: string | null;
  admission_number: string | null;
  gender: string | null;
  class_id: number | null;
  arm_id: number | null;
};

type ImportPreview = {
  token: string;
  summary: { total: number; ready: number; errors: number };
  unrecognised_columns: string[];
  rows: ImportRow[];
};

type MigrationRequest = {
  id: number;
  previous_system: string;
  source_format: string;
  status: 'submitted' | 'in_progress' | 'completed' | 'cancelled';
  submitted_at: string | null;
  due_at: string | null;
  completed_at: string | null;
  operator_notes: string | null;
  is_overdue: boolean;
};

export default function StudentsPage() {
  const [students, setStudents] = useState<Student[]>([]);
  const [rosterError, setRosterError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<'roster' | 'single' | 'bulk_students' | 'migration'>('roster');
  
  // Bulk CSV State — upload, look at what it found, then confirm.
  const [csvFile, setCsvFile] = useState<File | null>(null);
  const [importClassId, setImportClassId] = useState('');
  const [importArmId, setImportArmId] = useState('');
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [importBusy, setImportBusy] = useState(false);
  const [importError, setImportError] = useState<string | null>(null);
  const [importResult, setImportResult] = useState<{ created: number } | null>(null);

  // Migration State
  const [prevSchoolName, setPrevSchoolName] = useState('');
  const [sourceSystem, setSourceSystem] = useState('excel');
  const [migrationNotes, setMigrationNotes] = useState('');
  const [migrationStatus, setMigrationStatus] = useState<string | null>(null);
  const [migrationError, setMigrationError] = useState<string | null>(null);
  const [migrationBusy, setMigrationBusy] = useState(false);
  const [migration, setMigration] = useState<MigrationRequest | null>(null);

  /**
   * Fetches the roster and returns it; the caller owns the state.
   *
   * The endpoint paginates, so the rows are under `data`; older deployments
   * returned a bare array.
   */
  const fetchStudents = useCallback(async () => {
    const data = await fetchApi<{ data?: Student[] } | Student[]>('/students');

    return Array.isArray(data) ? data : (data.data ?? []);
  }, []);

  const loadStudents = useCallback(async () => {
    setStudents(await fetchStudents());
  }, [fetchStudents]);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const roster = await fetchStudents();
        if (!cancelled) setStudents(roster);
      } catch (err) {
        // Shown, not swallowed into console.error: an admin staring at an empty
        // roster needs to know the request failed rather than assume the school
        // has no students.
        if (!cancelled) {
          setRosterError(err instanceof Error ? err.message : 'Could not load the roster.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    // Classes and their arms drive the "import this whole file into JSS 1 Gold"
    // picker, so a register with no class column can still be placed.
    fetchApi<{ data?: ClassOption[] }>('/classes')
      .then((res) => {
        if (!cancelled) setClasses(res.data ?? []);
      })
      .catch(() => {
        if (!cancelled) setClasses([]);
      });

    // Any migration already in flight, so the tab shows its real state instead
    // of an empty form that would be refused as a duplicate.
    fetchApi<{ data?: MigrationRequest[] }>('/school-migration')
      .then((res) => {
        const open = (res.data ?? []).find((r) =>
          ['submitted', 'in_progress'].includes(r.status),
        );

        if (!cancelled && open) setMigration(open);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [fetchStudents]);

  /**
   * Step one: send the file up and get back what the server made of it.
   * Nothing is created — this is the look-before-you-leap half.
   */
  const handleBulkPreview = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!csvFile) return;

    setImportBusy(true);
    setImportError(null);
    setImportResult(null);

    const form = new FormData();
    form.append('file', csvFile);
    if (importClassId) form.append('class_id', importClassId);
    if (importArmId) form.append('arm_id', importArmId);

    try {
      setPreview(await uploadApi<ImportPreview>('/students/import/preview', form));
    } catch (err) {
      setPreview(null);
      setImportError(err instanceof Error ? err.message : 'Could not read that file.');
    } finally {
      setImportBusy(false);
    }
  };

  /** Step two: redeem the token. The server creates the valid rows, or none. */
  const handleBulkCommit = async () => {
    if (!preview) return;

    setImportBusy(true);
    setImportError(null);

    try {
      const result = await fetchApi<{ created: number }>('/students/import/commit', {
        method: 'POST',
        body: JSON.stringify({ token: preview.token }),
      });

      setImportResult(result);
      // The token is single-use; clearing the preview stops a second confirm.
      setPreview(null);
      setCsvFile(null);
      loadStudents();
    } catch (err) {
      setImportError(err instanceof Error ? err.message : 'The import could not be completed.');
    } finally {
      setImportBusy(false);
    }
  };

  /**
   * Submit a real migration request.
   *
   * This used to be a `setTimeout` that told the school "Successfully queued"
   * without recording anything or telling anybody — a staffed commitment with a
   * 24-hour deadline that reached nobody who could honour it.
   */
  const handleSchoolMigration = async (e: React.FormEvent) => {
    e.preventDefault();
    setMigrationBusy(true);
    setMigrationStatus(null);
    setMigrationError(null);

    try {
      const res = await fetchApi<{ message: string; data: MigrationRequest }>('/school-migration', {
        method: 'POST',
        body: JSON.stringify({
          previous_system: prevSchoolName,
          source_format: sourceSystem,
          notes: migrationNotes.trim() || null,
        }),
      });

      setMigration(res.data);
      setMigrationStatus(
        res.data.due_at
          ? `Request received. SchoolPilot aims to complete this by ${new Date(res.data.due_at).toLocaleString('en-NG')}.`
          : 'Request received.',
      );
    } catch (err) {
      setMigrationError(
        err instanceof Error ? err.message : 'That migration request could not be submitted.',
      );
    } finally {
      setMigrationBusy(false);
    }
  };

  const cancelMigration = async () => {
    if (!migration) return;

    setMigrationBusy(true);
    setMigrationError(null);

    try {
      await fetchApi(`/school-migration/${migration.id}/cancel`, { method: 'POST' });
      setMigration(null);
      setMigrationStatus('Migration request cancelled.');
    } catch (err) {
      setMigrationError(err instanceof Error ? err.message : 'That request could not be cancelled.');
    } finally {
      setMigrationBusy(false);
    }
  };

  return (
    <div className="flex bg-canvas text-ink min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="text-2xl font-bold text-ink">Student Information System (SIS) & Migration</h1>
            <p className="text-ink-muted text-sm">Manage student profiles, perform bulk CSV imports, and execute 24-hour school migrations</p>
          </div>
          <div className="flex space-x-2">
            <button
              onClick={() => setActiveTab('roster')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'roster' ? 'bg-brand text-white font-bold' : 'bg-surface text-ink hover:bg-surface-sunken'
              }`}
            >
              📋 Roster
            </button>
            <button
              onClick={() => setActiveTab('single')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'single' ? 'bg-brand text-white font-bold' : 'bg-surface text-ink hover:bg-surface-sunken'
              }`}
            >
              + Enrol Student
            </button>
            <button
              onClick={() => setActiveTab('bulk_students')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'bulk_students' ? 'bg-brand text-white font-bold' : 'bg-surface text-ink hover:bg-surface-sunken'
              }`}
            >
              📁 Bulk CSV Import
            </button>
            <button
              onClick={() => setActiveTab('migration')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'migration' ? 'bg-accent text-accent-ink font-bold' : 'bg-surface text-ink hover:bg-surface-sunken'
              }`}
            >
              ⚡ 24h School Migration
            </button>
          </div>
        </div>

        {rosterError && (
          <p
            role="alert"
            className="mb-6 rounded-lg border border-negative/30 bg-negative-soft px-4 py-3 text-sm text-negative"
          >
            {rosterError}
          </p>
        )}

        {/* 1. Single Student Registration Form */}
        {activeTab === 'single' && (
          <div className="mb-8 bg-surface border border-line-soft p-6 rounded-xl shadow-lg">
            <h2 className="text-lg font-bold text-ink mb-1">Enrol a student</h2>
            <p className="text-ink-muted text-xs mb-5">
              Class and a parent&rsquo;s phone number are what put a child on the register, the broadsheet, the fee
              list and the SMS list. Email is optional for both.
            </p>
            <EnrolStudentForm classes={classes} onCreated={loadStudents} />
          </div>
        )}

        {/* 2. Bulk CSV Student Import */}
        {activeTab === 'bulk_students' && (
          <div className="mb-8 bg-surface border border-line-soft p-6 rounded-xl shadow-lg">
            <h2 className="text-lg font-bold text-ink mb-2">Bulk Student CSV Import</h2>
            <p className="text-ink-muted text-xs mb-4">
              Upload the class register as a CSV. Columns are matched by their headings in any order —{' '}
              <code className="text-positive bg-canvas px-1.5 py-0.5 rounded">Surname, First name, Admission No, Class, Arm, Sex, DOB, Guardian name, Guardian phone</code>.
              If the sheet has no class column, pick a class below and the whole file goes there.
            </p>
            <a
              href="/api/backend/students/import/template"
              download
              className="mb-4 inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-1.5 text-xs font-semibold text-brand transition hover:bg-surface-sunken"
            >
              ⬇ Download the CSV template
            </a>
            <p className="text-ink-faint text-[11px] mb-4">
              The template has every column the importer reads and one example row — replace it with your students.
              Parents are matched by phone number, so brothers and sisters share one parent account.
            </p>

            <form onSubmit={handleBulkPreview} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-ink-muted mb-1">CSV file</label>
                  <input
                    type="file"
                    accept=".csv,text/csv"
                    onChange={(e) => {
                      setCsvFile(e.target.files?.[0] ?? null);
                      setPreview(null);
                      setImportResult(null);
                    }}
                    className="w-full px-3 py-2 bg-canvas border border-line-soft rounded-lg text-ink text-xs file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:bg-surface-raised file:text-ink"
                    required
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-ink-muted mb-1">Class for the whole file (optional)</label>
                  <select
                    value={importClassId}
                    onChange={(e) => {
                      setImportClassId(e.target.value);
                      setImportArmId('');
                    }}
                    className="w-full px-3 py-2 bg-canvas border border-line-soft rounded-lg text-ink text-sm focus:border-brand focus:outline-none"
                  >
                    <option value="">Use the Class column in the sheet</option>
                    {classes.map((c) => (
                      <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-ink-muted mb-1">Arm (optional)</label>
                  <select
                    value={importArmId}
                    onChange={(e) => setImportArmId(e.target.value)}
                    disabled={!importClassId}
                    className="w-full px-3 py-2 bg-canvas border border-line-soft rounded-lg text-ink text-sm focus:border-brand focus:outline-none disabled:opacity-40"
                  >
                    <option value="">No arm</option>
                    {classes
                      .find((c) => String(c.id) === importClassId)
                      ?.arms?.map((a) => (
                        <option key={a.id} value={a.id}>{a.name}</option>
                      ))}
                  </select>
                </div>
              </div>

              <button
                type="submit"
                disabled={importBusy || !csvFile}
                className="w-full py-2.5 bg-surface-raised hover:bg-surface-raised text-ink font-bold rounded-lg text-sm transition disabled:opacity-40"
              >
                {importBusy && !preview ? 'Checking the file…' : 'Check this file (nothing is saved yet)'}
              </button>
            </form>

            {importError && (
              <div className="mt-4 p-4 bg-negative-soft border border-negative/30 rounded-lg text-xs text-negative">
                {importError}
              </div>
            )}

            {preview && (
              <div className="mt-6">
                <div className="flex flex-wrap items-center gap-3 mb-3 text-xs">
                  <span className="text-ink font-semibold">{preview.summary.total} rows read</span>
                  <span className="px-2 py-1 rounded bg-brand/15 text-positive">{preview.summary.ready} ready</span>
                  {preview.summary.errors > 0 && (
                    <span className="px-2 py-1 rounded bg-negative-soft text-negative">{preview.summary.errors} with problems</span>
                  )}
                  {preview.unrecognised_columns.length > 0 && (
                    <span className="text-ink-faint">Ignored columns: {preview.unrecognised_columns.join(', ')}</span>
                  )}
                </div>

                <div className="max-h-72 overflow-y-auto border border-line-soft rounded-lg">
                  <table className="w-full text-xs">
                    <thead className="bg-canvas sticky top-0">
                      <tr className="text-ink-muted text-left">
                        <th className="px-3 py-2 font-semibold">Row</th>
                        <th className="px-3 py-2 font-semibold">Name</th>
                        <th className="px-3 py-2 font-semibold">Admission No</th>
                        <th className="px-3 py-2 font-semibold">Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {preview.rows.map((row) => (
                        <tr key={row.line} className="border-t border-line-soft">
                          <td className="px-3 py-2 text-ink-faint">{row.line}</td>
                          <td className="px-3 py-2 text-ink">{row.name ?? '—'}</td>
                          <td className="px-3 py-2 text-ink-muted">{row.admission_number ?? 'auto'}</td>
                          <td className="px-3 py-2">
                            {row.status === 'ready' ? (
                              <span className="text-positive">Ready</span>
                            ) : (
                              <span className="text-negative">{row.errors.join(' ')}</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <button
                  onClick={handleBulkCommit}
                  disabled={importBusy || preview.summary.ready === 0}
                  className="mt-4 w-full py-2.5 bg-brand hover:bg-brand-soft text-white font-bold rounded-lg text-sm transition disabled:opacity-40"
                >
                  {importBusy ? 'Importing…' : `Import the ${preview.summary.ready} valid row(s)`}
                </button>

                {preview.summary.errors > 0 && (
                  <p className="mt-2 text-[11px] text-ink-faint">
                    Rows with problems are skipped. Fix them in the sheet and upload again.
                  </p>
                )}
              </div>
            )}

            {importResult && (
              <div className="mt-4 p-4 bg-positive-soft border border-positive/30 rounded-lg text-xs text-positive">
                ✓ {importResult.created} student record(s) created. Anyone imported with an email can use
                &ldquo;Forgot password&rdquo; on the sign-in screen to set one.
              </div>
            )}
          </div>
        )}

        {/* 3. 24h Free School Migration */}
        {activeTab === 'migration' && (
          <div className="mb-8 bg-surface border border-accent/30 p-6 rounded-xl shadow-lg">
            <div className="flex items-center space-x-2 mb-2">
              <span className="bg-accent/20 text-caution text-xs font-bold px-2.5 py-1 rounded-md">ASSISTED MIGRATION</span>
              <h2 className="text-lg font-bold text-ink">Migrate from a previous portal, spreadsheet or paper</h2>
            </div>
            <p className="text-ink-muted text-xs mb-6">
              Ask SchoolPilot to bring your existing records across. If your data is already a CSV,
              the Bulk CSV tab does it yourself, immediately, with a preview before anything is
              created.
            </p>

            {migrationError && (
              <p role="alert" className="mb-4 rounded-lg border border-negative/30 bg-negative-soft px-4 py-3 text-xs text-negative">
                {migrationError}
              </p>
            )}

            {migration ? (
              <div className="rounded-lg border border-accent/30 bg-canvas p-4 text-xs text-ink">
                <p className="font-bold text-ink">
                  Request open — {migration.status.replace('_', ' ')}
                  {migration.is_overdue && <span className="ml-2 text-negative">overdue</span>}
                </p>
                <dl className="mt-3 space-y-1">
                  <div className="flex justify-between">
                    <dt className="text-ink-muted">Previous system</dt>
                    <dd>{migration.previous_system}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-ink-muted">Submitted</dt>
                    <dd>{migration.submitted_at ? new Date(migration.submitted_at).toLocaleString('en-NG') : '—'}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-ink-muted">Target completion</dt>
                    <dd>{migration.due_at ? new Date(migration.due_at).toLocaleString('en-NG') : '—'}</dd>
                  </div>
                </dl>

                {migration.operator_notes && (
                  <p className="mt-3 border-t border-line-soft pt-3 text-ink-muted">
                    <span className="font-semibold text-ink">Update from SchoolPilot: </span>
                    {migration.operator_notes}
                  </p>
                )}

                <button
                  onClick={cancelMigration}
                  disabled={migrationBusy}
                  className="mt-4 rounded-lg border border-line px-3 py-1.5 font-semibold text-ink transition hover:bg-surface-sunken disabled:opacity-60"
                >
                  {migrationBusy ? 'Cancelling…' : 'Cancel this request'}
                </button>
              </div>
            ) : (
            <form onSubmit={handleSchoolMigration} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-ink-muted mb-1">Previous School / Portal Name</label>
                <input
                  type="text"
                  value={prevSchoolName}
                  onChange={(e) => setPrevSchoolName(e.target.value)}
                  placeholder="e.g. SAFSMS / Edves / Legacy Excel"
                  className="w-full px-3 py-2 bg-canvas border border-line-soft rounded-lg text-ink text-sm focus:border-accent focus:outline-none"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-ink-muted mb-1">Source System Format</label>
                <select
                  value={sourceSystem}
                  onChange={(e) => setSourceSystem(e.target.value)}
                  className="w-full px-3 py-2 bg-canvas border border-line-soft rounded-lg text-ink text-sm focus:border-accent focus:outline-none"
                >
                  <option value="excel">Excel / CSV spreadsheets</option>
                  <option value="sql_dump">Database dump (.sql)</option>
                  <option value="competitor_portal">Export from another portal</option>
                  <option value="paper">Paper records</option>
                  <option value="other">Something else</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-ink-muted mb-1">
                  Anything we should know (optional)
                </label>
                <textarea
                  rows={3}
                  value={migrationNotes}
                  onChange={(e) => setMigrationNotes(e.target.value)}
                  placeholder="How many students, which years, anything unusual about the data"
                  className="w-full px-3 py-2 bg-canvas border border-line-soft rounded-lg text-ink text-sm focus:border-accent focus:outline-none"
                />
              </div>

              <button
                type="submit"
                disabled={migrationBusy || !prevSchoolName.trim()}
                className="w-full py-2.5 bg-accent hover:bg-accent-strong text-ink font-bold rounded-lg text-sm transition disabled:opacity-60"
              >
                {migrationBusy ? 'Submitting…' : 'Request migration help'}
              </button>
            </form>
            )}

            {migrationStatus && (
              <div className="mt-4 p-4 bg-caution-soft border border-accent/30 rounded-lg text-xs text-caution">
                {migrationStatus}
              </div>
            )}
          </div>
        )}

        {/* Live Roster Table */}
        <div className="bg-surface border border-line-soft rounded-xl overflow-hidden shadow-lg">
          <div className="p-4 border-b border-line-soft flex justify-between items-center">
            <h3 className="font-semibold text-ink">Live Enrolled Students Roster</h3>
            <span className="text-xs bg-surface-raised text-ink px-2.5 py-1 rounded-full">{students.length} Records</span>
          </div>

          {loading ? (
            <div className="p-8 text-center text-sm text-ink-muted">Loading live student database records...</div>
          ) : students.length === 0 ? (
            <div className="p-8 text-center text-sm text-ink-muted">No student records found in live backend database.</div>
          ) : (
            <table className="w-full text-left text-sm text-ink">
              <thead className="bg-canvas text-ink-muted text-xs font-semibold uppercase">
                <tr>
                  <th className="p-3.5">Admission No</th>
                  <th className="p-3.5">Student Name</th>
                  <th className="p-3.5">Class</th>
                  <th className="p-3.5">Gender</th>
                  <th className="p-3.5">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line-soft">
                {students.map((student) => (
                  <tr key={student.id} className="hover:bg-surface-sunken transition">
                    <td className="p-3.5 font-mono text-positive text-xs">{student.admission_number || `SP-${student.id}`}</td>
                    {/* Flat on the resource: the endpoint folds the user in. */}
                    <td className="p-3.5 font-medium text-ink">{student.name ?? '—'}</td>
                    <td className="p-3.5 text-xs text-ink-muted">{[student.class, student.arm].filter(Boolean).join(' ') || '—'}</td>
                    <td className="p-3.5 capitalize">{student.gender}</td>
                    <td className="p-3.5">
                      <span className="px-2 py-0.5 bg-positive-soft text-positive border border-positive/20 text-xs font-medium rounded-md capitalize">
                        {student.status ?? 'active'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </main>
    </div>
  );
}
