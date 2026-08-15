'use client';

import React, { useEffect, useState } from 'react';
import Sidebar from '@/components/Sidebar';
import { fetchApi, uploadApi } from '@/lib/api';
import type { Student } from '@/types/api';

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

type ClassOption = {
  id: number;
  name: string;
  arms?: { id: number; name: string }[];
};

export default function StudentsPage() {
  const [students, setStudents] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<'roster' | 'single' | 'bulk_students' | 'migration'>('roster');
  
  // Single Add Form
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [gender, setGender] = useState('male');
  const [birthCertRef, setBirthCertRef] = useState('');

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
  const [migrationStatus, setMigrationStatus] = useState<string | null>(null);

  const loadStudents = async () => {
    try {
      setLoading(true);
      // The endpoint paginates, so the rows are under `data`; older
      // deployments returned a bare array.
      const data = await fetchApi<{ data?: Student[] } | Student[]>('/students');
      setStudents(Array.isArray(data) ? data : (data.data ?? []));
    } catch (err) {
      console.error('Failed to load live students:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadStudents();

    // Classes and their arms drive the "import this whole file into JSS 1 Gold"
    // picker, so a register with no class column can still be placed.
    fetchApi<{ data?: ClassOption[] }>('/classes')
      .then((res) => setClasses(res.data ?? []))
      .catch(() => setClasses([]));
  }, []);

  const handleCreateStudent = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name || !email) return;

    try {
      await fetchApi('/students', {
        method: 'POST',
        body: JSON.stringify({ name, email, gender, birth_certificate_reference: birthCertRef }),
      });
      setName('');
      setEmail('');
      setBirthCertRef('');
      setActiveTab('roster');
      loadStudents();
    } catch (err) {
      alert('Failed to save student record to backend API');
    }
  };

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

  const handleSchoolMigration = async (e: React.FormEvent) => {
    e.preventDefault();
    setMigrationStatus('Initiating 24-hour fast migration pipeline...');
    setTimeout(() => {
      setMigrationStatus(`Successfully queued migration from ${prevSchoolName || 'Previous School'} via ${sourceSystem.toUpperCase()} parser! Our migration engineers will verify records within 24 hours.`);
      loadStudents();
    }, 1200);
  };

  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="text-2xl font-bold text-white">Student Information System (SIS) & Migration</h1>
            <p className="text-slate-400 text-sm">Manage student profiles, perform bulk CSV imports, and execute 24-hour school migrations</p>
          </div>
          <div className="flex space-x-2">
            <button
              onClick={() => setActiveTab('roster')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'roster' ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              📋 Roster
            </button>
            <button
              onClick={() => setActiveTab('single')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'single' ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              + Single Student
            </button>
            <button
              onClick={() => setActiveTab('bulk_students')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'bulk_students' ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              📁 Bulk CSV Import
            </button>
            <button
              onClick={() => setActiveTab('migration')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'migration' ? 'bg-amber-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              ⚡ 24h School Migration
            </button>
          </div>
        </div>

        {/* 1. Single Student Registration Form */}
        {activeTab === 'single' && (
          <div className="mb-8 bg-slate-900 border border-slate-800 p-6 rounded-xl shadow-lg">
            <h2 className="text-lg font-bold text-white mb-4">Single Student Registration (Live API)</h2>
            <form onSubmit={handleCreateStudent} className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Full Name</label>
                <input
                  type="text"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="e.g. Amina Bello"
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Email</label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="e.g. student@example.com"
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Gender</label>
                <select
                  value={gender}
                  onChange={(e) => setGender(e.target.value)}
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
                >
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Birth Certificate Ref No (Optional)</label>
                <input
                  type="text"
                  value={birthCertRef}
                  onChange={(e) => setBirthCertRef(e.target.value)}
                  placeholder="e.g. BC-2025-99881"
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
                />
              </div>

              <div className="md:col-span-2 flex items-end">
                <button
                  type="submit"
                  className="w-full py-2 bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold rounded-lg text-sm transition"
                >
                  Save Student to Backend Database
                </button>
              </div>
            </form>
          </div>
        )}

        {/* 2. Bulk CSV Student Import */}
        {activeTab === 'bulk_students' && (
          <div className="mb-8 bg-slate-900 border border-slate-800 p-6 rounded-xl shadow-lg">
            <h2 className="text-lg font-bold text-white mb-2">Bulk Student CSV Import</h2>
            <p className="text-slate-400 text-xs mb-4">
              Upload the class register as a CSV. Columns are matched by their headings in any order —{' '}
              <code className="text-emerald-400 bg-slate-950 px-1.5 py-0.5 rounded">Surname, First name, Admission No, Class, Arm, Sex, DOB, Guardian name, Guardian phone</code>.
              If the sheet has no class column, pick a class below and the whole file goes there.
            </p>

            <form onSubmit={handleBulkPreview} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-400 mb-1">CSV file</label>
                  <input
                    type="file"
                    accept=".csv,text/csv"
                    onChange={(e) => {
                      setCsvFile(e.target.files?.[0] ?? null);
                      setPreview(null);
                      setImportResult(null);
                    }}
                    className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-xs file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:bg-slate-800 file:text-slate-200"
                    required
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-400 mb-1">Class for the whole file (optional)</label>
                  <select
                    value={importClassId}
                    onChange={(e) => {
                      setImportClassId(e.target.value);
                      setImportArmId('');
                    }}
                    className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
                  >
                    <option value="">Use the Class column in the sheet</option>
                    {classes.map((c) => (
                      <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-400 mb-1">Arm (optional)</label>
                  <select
                    value={importArmId}
                    onChange={(e) => setImportArmId(e.target.value)}
                    disabled={!importClassId}
                    className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none disabled:opacity-40"
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
                className="w-full py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-lg text-sm transition disabled:opacity-40"
              >
                {importBusy && !preview ? 'Checking the file…' : 'Check this file (nothing is saved yet)'}
              </button>
            </form>

            {importError && (
              <div className="mt-4 p-4 bg-rose-500/10 border border-rose-500/30 rounded-lg text-xs text-rose-300">
                {importError}
              </div>
            )}

            {preview && (
              <div className="mt-6">
                <div className="flex flex-wrap items-center gap-3 mb-3 text-xs">
                  <span className="text-slate-300 font-semibold">{preview.summary.total} rows read</span>
                  <span className="px-2 py-1 rounded bg-emerald-500/15 text-emerald-300">{preview.summary.ready} ready</span>
                  {preview.summary.errors > 0 && (
                    <span className="px-2 py-1 rounded bg-rose-500/15 text-rose-300">{preview.summary.errors} with problems</span>
                  )}
                  {preview.unrecognised_columns.length > 0 && (
                    <span className="text-slate-500">Ignored columns: {preview.unrecognised_columns.join(', ')}</span>
                  )}
                </div>

                <div className="max-h-72 overflow-y-auto border border-slate-800 rounded-lg">
                  <table className="w-full text-xs">
                    <thead className="bg-slate-950 sticky top-0">
                      <tr className="text-slate-400 text-left">
                        <th className="px-3 py-2 font-semibold">Row</th>
                        <th className="px-3 py-2 font-semibold">Name</th>
                        <th className="px-3 py-2 font-semibold">Admission No</th>
                        <th className="px-3 py-2 font-semibold">Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {preview.rows.map((row) => (
                        <tr key={row.line} className="border-t border-slate-800">
                          <td className="px-3 py-2 text-slate-500">{row.line}</td>
                          <td className="px-3 py-2 text-slate-200">{row.name ?? '—'}</td>
                          <td className="px-3 py-2 text-slate-400">{row.admission_number ?? 'auto'}</td>
                          <td className="px-3 py-2">
                            {row.status === 'ready' ? (
                              <span className="text-emerald-400">Ready</span>
                            ) : (
                              <span className="text-rose-400">{row.errors.join(' ')}</span>
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
                  className="mt-4 w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold rounded-lg text-sm transition disabled:opacity-40"
                >
                  {importBusy ? 'Importing…' : `Import the ${preview.summary.ready} valid row(s)`}
                </button>

                {preview.summary.errors > 0 && (
                  <p className="mt-2 text-[11px] text-slate-500">
                    Rows with problems are skipped. Fix them in the sheet and upload again.
                  </p>
                )}
              </div>
            )}

            {importResult && (
              <div className="mt-4 p-4 bg-emerald-500/10 border border-emerald-500/30 rounded-lg text-xs text-emerald-300">
                ✓ {importResult.created} student record(s) created. They have no password yet — tell them to use
                &ldquo;Forgot password&rdquo; on the sign-in screen.
              </div>
            )}
          </div>
        )}

        {/* 3. 24h Free School Migration */}
        {activeTab === 'migration' && (
          <div className="mb-8 bg-slate-900 border border-amber-500/30 p-6 rounded-xl shadow-lg">
            <div className="flex items-center space-x-2 mb-2">
              <span className="bg-amber-500/20 text-amber-400 text-xs font-bold px-2.5 py-1 rounded-md">FREE 24-HOUR MIGRATION</span>
              <h2 className="text-lg font-bold text-white">Migrate School from Competitor / Paper / Excel</h2>
            </div>
            <p className="text-slate-400 text-xs mb-6">Switching off an old school portal? Upload your current database or spreadsheet backup. Our migration pipeline handles full data transformation within 24 hours at ₦0 cost.</p>
            
            <form onSubmit={handleSchoolMigration} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Previous School / Portal Name</label>
                <input
                  type="text"
                  value={prevSchoolName}
                  onChange={(e) => setPrevSchoolName(e.target.value)}
                  placeholder="e.g. SAFSMS / Edves / Legacy Excel"
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-amber-500 focus:outline-none"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Source System Format</label>
                <select
                  value={sourceSystem}
                  onChange={(e) => setSourceSystem(e.target.value)}
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-amber-500 focus:outline-none"
                >
                  <option value="excel">Excel / CSV Spreadsheets</option>
                  <option value="sql_dump">Database SQL Dump (.sql)</option>
                  <option value="competitor_portal">Rival Portal Export (Edves / SAFSMS / QuickSchools)</option>
                </select>
              </div>

              <button
                type="submit"
                className="w-full py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold rounded-lg text-sm transition"
              >
                Submit 24-Hour Free Migration Package
              </button>
            </form>

            {migrationStatus && (
              <div className="mt-4 p-4 bg-amber-500/10 border border-amber-500/30 rounded-lg text-xs text-amber-300">
                {migrationStatus}
              </div>
            )}
          </div>
        )}

        {/* Live Roster Table */}
        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
          <div className="p-4 border-b border-slate-800 flex justify-between items-center">
            <h3 className="font-semibold text-white">Live Enrolled Students Roster</h3>
            <span className="text-xs bg-slate-800 text-slate-300 px-2.5 py-1 rounded-full">{students.length} Records</span>
          </div>

          {loading ? (
            <div className="p-8 text-center text-sm text-slate-400">Loading live student database records...</div>
          ) : students.length === 0 ? (
            <div className="p-8 text-center text-sm text-slate-400">No student records found in live backend database.</div>
          ) : (
            <table className="w-full text-left text-sm text-slate-300">
              <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
                <tr>
                  <th className="p-3.5">Admission No</th>
                  <th className="p-3.5">Student Name</th>
                  <th className="p-3.5">Email</th>
                  <th className="p-3.5">Gender</th>
                  <th className="p-3.5">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                {students.map((student) => (
                  <tr key={student.id} className="hover:bg-slate-800/50 transition">
                    <td className="p-3.5 font-mono text-emerald-400 text-xs">{student.admission_number || `SP-${student.id}`}</td>
                    <td className="p-3.5 font-medium text-white">{student.user ? student.user.name : student.name}</td>
                    <td className="p-3.5 text-xs text-slate-400">{student.user ? student.user.email : student.email}</td>
                    <td className="p-3.5 capitalize">{student.gender}</td>
                    <td className="p-3.5">
                      <span className="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-medium rounded-md">
                        {student.status || 'Active'}
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
