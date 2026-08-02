'use client';

import React, { useState } from 'react';
import Sidebar from '@/components/Sidebar';

export default function ResultsPage() {
  const [activeTab, setActiveTab] = useState<'broadsheet' | 'bulk_import' | 'report_card'>('broadsheet');

  const [scores, setScores] = useState([
    { id: 1, name: 'Abebe Bikila', adm: 'ADM-001', ca1: 18, ca2: 17, exam: 54, total: 89, grade: 'A1', status: 'Approved' },
    { id: 2, name: 'Chidimma Okoro', adm: 'ADM-002', ca1: 15, ca2: 14, exam: 45, total: 74, grade: 'B2', status: 'Pending Review' },
    { id: 3, name: 'Tunde Bakare', adm: 'ADM-003', ca1: 12, ca2: 10, exam: 38, total: 60, grade: 'C4', status: 'Approved' },
  ]);

  const [aiComment, setAiComment] = useState(
    'Abebe Bikila has demonstrated outstanding academic comprehension in Mathematics this term. Recommended to continue practicing advanced equations.'
  );

  // Bulk Broadsheet Import
  const [broadsheetCsv, setBroadsheetCsv] = useState('');
  const [importNotice, setImportNotice] = useState<string | null>(null);

  const handleBulkBroadsheetImport = (e: React.FormEvent) => {
    e.preventDefault();
    if (!broadsheetCsv) return;

    const parsed = broadsheetCsv.trim().split('\n').map((line, idx) => {
      const parts = line.split(',').map(p => p.trim());
      const ca1 = parseInt(parts[2]) || 15;
      const ca2 = parseInt(parts[3]) || 15;
      const exam = parseInt(parts[4]) || 45;
      const total = ca1 + ca2 + exam;
      return {
        id: scores.length + idx + 1,
        name: parts[0] || `Student ${idx + 1}`,
        adm: parts[1] || `ADM-${100 + idx}`,
        ca1,
        ca2,
        exam,
        total,
        grade: total >= 75 ? 'A1' : total >= 70 ? 'B2' : total >= 60 ? 'C4' : 'C6',
        status: 'Pending Review'
      };
    });

    setScores([...scores, ...parsed]);
    setImportNotice(`Successfully imported ${parsed.length} broadsheet score rows!`);
    setBroadsheetCsv('');
    setActiveTab('broadsheet');
  };

  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="text-2xl font-bold text-white">Assessment, Broadsheet & Report Cards</h1>
            <p className="text-slate-400 text-sm">Continuous Assessment entry, Bulk Broadsheet CSV Import, and Branded QR Verification PDF Report Cards</p>
          </div>
          <div className="flex space-x-2">
            <button
              onClick={() => setActiveTab('broadsheet')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'broadsheet' ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              📊 Broadsheet View
            </button>
            <button
              onClick={() => setActiveTab('bulk_import')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'bulk_import' ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              📁 Bulk Broadsheet Import
            </button>
            <button
              onClick={() => setActiveTab('report_card')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'report_card' ? 'bg-amber-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              📜 Branded Report Card Preview
            </button>
          </div>
        </div>

        {/* 1. Bulk Broadsheet CSV Import */}
        {activeTab === 'bulk_import' && (
          <div className="mb-8 bg-slate-900 border border-slate-800 p-6 rounded-xl shadow-lg">
            <h2 className="text-lg font-bold text-white mb-2">Bulk Broadsheet Score CSV Import</h2>
            <p className="text-slate-400 text-xs mb-4">Paste whole-class score entries in format: <code className="text-emerald-400 bg-slate-950 px-1.5 py-0.5 rounded">StudentName, AdmissionNo, CA1(20), CA2(20), Exam(60)</code></p>
            <form onSubmit={handleBulkBroadsheetImport}>
              <textarea
                value={broadsheetCsv}
                onChange={(e) => setBroadsheetCsv(e.target.value)}
                placeholder="Amina Bello, ADM-004, 18, 19, 52&#10;Koffi Annan, ADM-005, 14, 15, 42"
                className="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-sm text-slate-200 focus:border-emerald-500 focus:outline-none h-32 mb-4 font-mono text-xs"
                required
              />
              <button
                type="submit"
                className="w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold rounded-lg text-sm transition"
              >
                Compile & Import Broadsheet Scores
              </button>
            </form>
          </div>
        )}

        {/* 2. Branded PDF Report Card Preview */}
        {activeTab === 'report_card' && (
          <div className="mb-8 bg-slate-900 border border-amber-500/30 p-8 rounded-xl shadow-xl max-w-4xl mx-auto">
            <div className="border-4 border-amber-500/20 p-6 rounded-lg bg-slate-950">
              {/* Header */}
              <div className="flex justify-between items-center border-b border-slate-800 pb-4 mb-6">
                <div>
                  <h2 className="text-xl font-bold text-amber-400">GRACE LAND INTERNATIONAL COLLEGE</h2>
                  <p className="text-xs text-slate-400">Official Student Termly Academic Progress Report</p>
                </div>
                <div className="text-right">
                  <span className="text-xs bg-amber-500/10 text-amber-400 border border-amber-500/30 px-3 py-1 rounded-full font-bold">
                    Term 1, 2025/2026 Session
                  </span>
                </div>
              </div>

              {/* Student Biodata Snapshot */}
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs mb-6 bg-slate-900 p-4 rounded-lg border border-slate-800">
                <div><span className="text-slate-400">Name:</span> <strong className="text-white block">Abebe Bikila</strong></div>
                <div><span className="text-slate-400">Admission No:</span> <strong className="text-emerald-400 block font-mono">ADM-001</strong></div>
                <div><span className="text-slate-400">Class:</span> <strong className="text-white block">JSS 1 Gold</strong></div>
                <div><span className="text-slate-400">Class Position:</span> <strong className="text-amber-400 block font-bold">1st out of 34</strong></div>
              </div>

              {/* Academic Performance Table */}
              <table className="w-full text-left text-xs text-slate-300 mb-6 border border-slate-800">
                <thead className="bg-slate-900 text-slate-400 font-semibold uppercase">
                  <tr>
                    <th className="p-2 border-b border-slate-800">Subject</th>
                    <th className="p-2 border-b border-slate-800">CA1 (20)</th>
                    <th className="p-2 border-b border-slate-800">CA2 (20)</th>
                    <th className="p-2 border-b border-slate-800">Exam (60)</th>
                    <th className="p-2 border-b border-slate-800">Total (100)</th>
                    <th className="p-2 border-b border-slate-800">Grade</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800">
                  <tr>
                    <td className="p-2 font-medium text-white">Mathematics</td>
                    <td className="p-2">18</td>
                    <td className="p-2">17</td>
                    <td className="p-2">54</td>
                    <td className="p-2 font-bold text-emerald-400">89</td>
                    <td className="p-2 font-bold">A1</td>
                  </tr>
                  <tr>
                    <td className="p-2 font-medium text-white">English Language</td>
                    <td className="p-2">16</td>
                    <td className="p-2">18</td>
                    <td className="p-2">51</td>
                    <td className="p-2 font-bold text-emerald-400">85</td>
                    <td className="p-2 font-bold">A1</td>
                  </tr>
                  <tr>
                    <td className="p-2 font-medium text-white">Basic Science</td>
                    <td className="p-2">15</td>
                    <td className="p-2">16</td>
                    <td className="p-2">48</td>
                    <td className="p-2 font-bold text-emerald-400">79</td>
                    <td className="p-2 font-bold">A1</td>
                  </tr>
                </tbody>
              </table>

              {/* Approved Teacher Remark */}
              <div className="bg-slate-900 p-4 rounded-lg border border-slate-800 mb-6">
                <h4 className="text-xs font-bold text-emerald-400 uppercase tracking-wider mb-1">Approved Class Teacher Remark</h4>
                <p className="text-xs text-slate-300 italic">"{aiComment}"</p>
              </div>

              {/* QR Authenticity Token Stamp */}
              <div className="flex justify-between items-center border-t border-slate-800 pt-4">
                <div className="flex items-center space-x-3">
                  <div className="w-12 h-12 bg-white text-slate-950 flex items-center justify-center font-mono font-bold text-[10px] rounded p-1 text-center leading-tight">
                    [QR CODE SCAN]
                  </div>
                  <div className="text-[10px] text-slate-400">
                    <span className="text-emerald-400 font-bold block">VERIFIED AUTHENTIC</span>
                    Signed Token: <code className="text-slate-300">SP_VERIFY_99812A</code>
                  </div>
                </div>

                <button
                  onClick={() => alert('Downloading official stamped PDF report card...')}
                  className="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold rounded-lg text-xs transition"
                >
                  📥 Download Official PDF Report Card
                </button>
              </div>
            </div>
          </div>
        )}

        {/* 3. Broadsheet Table View */}
        {activeTab === 'broadsheet' && (
          <>
            {importNotice && (
              <div className="mb-4 p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-lg text-xs text-emerald-300">
                {importNotice}
              </div>
            )}

            {/* AI Comment Approval Box */}
            <div className="mb-8 bg-slate-900 border border-emerald-500/30 p-6 rounded-xl shadow-lg">
              <div className="flex items-center justify-between mb-3">
                <span className="text-xs font-bold uppercase tracking-wider text-emerald-400 bg-emerald-500/10 px-2.5 py-1 rounded-md">
                  🤖 AI Generated Teacher Remark (Pending Approval)
                </span>
                <span className="text-xs text-slate-400">Student: Abebe Bikila (JSS 1 Gold)</span>
              </div>

              <textarea
                value={aiComment}
                onChange={(e) => setAiComment(e.target.value)}
                className="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-sm text-slate-200 focus:border-emerald-500 focus:outline-none mb-4 h-20"
              />

              <div className="flex justify-end space-x-3">
                <button
                  onClick={() => alert('Remark approved and locked for report card PDF!')}
                  className="px-4 py-1.5 bg-emerald-500 hover:bg-emerald-600 font-bold text-slate-950 rounded-lg text-xs transition"
                >
                  ✓ Approve Remark for Report Card
                </button>
              </div>
            </div>

            <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
              <div className="p-4 border-b border-slate-800 flex justify-between items-center">
                <h3 className="font-semibold text-white">JSS 1 Mathematics Broadsheet</h3>
                <span className="text-xs text-slate-400">CA Scheme: CA1 (20%) + CA2 (20%) + Exam (60%)</span>
              </div>

              <table className="w-full text-left text-sm text-slate-300">
                <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
                  <tr>
                    <th className="p-3.5">Admission No</th>
                    <th className="p-3.5">Student Name</th>
                    <th className="p-3.5">CA 1 (20)</th>
                    <th className="p-3.5">CA 2 (20)</th>
                    <th className="p-3.5">Exam (60)</th>
                    <th className="p-3.5">Total (100)</th>
                    <th className="p-3.5">Grade</th>
                    <th className="p-3.5">AI Comment Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800">
                  {scores.map((score) => (
                    <tr key={score.id} className="hover:bg-slate-800/50 transition">
                      <td className="p-3.5 font-mono text-emerald-400 text-xs">{score.adm}</td>
                      <td className="p-3.5 font-medium text-white">{score.name}</td>
                      <td className="p-3.5">{score.ca1}</td>
                      <td className="p-3.5">{score.ca2}</td>
                      <td className="p-3.5">{score.exam}</td>
                      <td className="p-3.5 font-bold text-emerald-400">{score.total}</td>
                      <td className="p-3.5 font-bold">{score.grade}</td>
                      <td className="p-3.5">
                        <span
                          className={`px-2 py-0.5 text-xs font-medium rounded-md border ${
                            score.status === 'Approved'
                              ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                              : 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                          }`}
                        >
                          {score.status}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </main>
    </div>
  );
}
