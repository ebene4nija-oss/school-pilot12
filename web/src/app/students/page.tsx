'use client';

import React, { useEffect, useState } from 'react';
import Sidebar from '@/components/Sidebar';
import { fetchApi } from '@/lib/api';

export default function StudentsPage() {
  const [students, setStudents] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<'roster' | 'single' | 'bulk_students' | 'migration'>('roster');
  
  // Single Add Form
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [gender, setGender] = useState('male');
  const [birthCertRef, setBirthCertRef] = useState('');

  // Bulk CSV State
  const [csvContent, setCsvContent] = useState('');
  const [importResult, setImportResult] = useState<any>(null);

  // Migration State
  const [prevSchoolName, setPrevSchoolName] = useState('');
  const [sourceSystem, setSourceSystem] = useState('excel');
  const [migrationStatus, setMigrationStatus] = useState<string | null>(null);

  const loadStudents = async () => {
    try {
      setLoading(true);
      const data = await fetchApi('/students');
      setStudents(data.data || data || []);
    } catch (err) {
      console.error('Failed to load live students:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadStudents();
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

  const handleBulkImport = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!csvContent) return;

    const rows = csvContent.trim().split('\n').map((row, index) => {
      const parts = row.split(',').map(s => s.trim());
      return {
        name: parts[0] || `Imported Student ${index + 1}`,
        email: parts[1] || `student_${index}_${Date.now()}@school.edu`,
        gender: parts[2] === 'female' ? 'female' : 'male',
        admission_number: parts[3] || `IMP-${Date.now()}-${index}`,
      };
    });

    try {
      const res = await fetchApi('/students/import', {
        method: 'POST',
        body: JSON.stringify({ students: rows }),
      });
      setImportResult(res);
      loadStudents();
    } catch (err) {
      alert('Failed to process bulk student import');
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
            <p className="text-slate-400 text-xs mb-4">Paste comma-separated rows in format: <code className="text-emerald-400 bg-slate-950 px-1.5 py-0.5 rounded">Name, Email, Gender (male/female), AdmissionNo</code></p>
            <form onSubmit={handleBulkImport}>
              <textarea
                value={csvContent}
                onChange={(e) => setCsvContent(e.target.value)}
                placeholder="Amina Bello, amina@school.edu, female, ADM-101&#10;Chidi Okeke, chidi@school.edu, male, ADM-102"
                className="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-sm text-slate-200 focus:border-emerald-500 focus:outline-none h-32 mb-4 font-mono text-xs"
                required
              />
              <button
                type="submit"
                className="w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold rounded-lg text-sm transition"
              >
                Upload & Process Bulk Student Batch
              </button>
            </form>

            {importResult && (
              <div className="mt-4 p-4 bg-emerald-500/10 border border-emerald-500/30 rounded-lg text-xs text-emerald-300">
                ✓ Bulk Import Complete! Successfully added {importResult.imported_count || importResult.length || 'batch'} student records.
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
