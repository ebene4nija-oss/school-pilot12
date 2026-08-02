'use client';

import React from 'react';
import Sidebar from '@/components/Sidebar';

export default function SchoolDirectorDashboard() {
  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        {/* Top Header */}
        <div className="flex justify-between items-center mb-8 border-b border-slate-800 pb-6">
          <div>
            <div className="flex items-center space-x-2">
              <span className="bg-amber-500/20 text-amber-400 text-xs font-bold px-2.5 py-1 rounded-md">EXECUTIVE EXECUTIVE CONSOLE</span>
              <h1 className="text-2xl font-bold text-white">School Director & Proprietor Dashboard</h1>
            </div>
            <p className="text-slate-400 text-sm mt-1">Grace Land International Group of Schools — Financial Health, Enrollment Metrics, and AI Executive Insights</p>
          </div>
          <div className="flex items-center space-x-3">
            <span className="text-xs bg-slate-900 border border-slate-800 text-slate-300 px-3 py-1.5 rounded-lg">
              📅 Term 1, 2025/2026 Session
            </span>
            <button
              onClick={() => alert('Exporting Director Executive Board Briefing PDF...')}
              className="px-4 py-2 bg-amber-500 hover:bg-amber-600 font-bold text-slate-950 rounded-lg text-xs transition"
            >
              📊 Export Board Briefing
            </button>
          </div>
        </div>

        {/* 1. Executive Metric Cards */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-slate-400 text-xs font-medium uppercase">Total Student Population</span>
            <div className="text-2xl font-bold text-white mt-1">1,248</div>
            <span className="text-emerald-400 text-[11px] font-semibold">↑ +12.4% vs Last Term</span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-slate-400 text-xs font-medium uppercase">Termly Tuition Collections</span>
            <div className="text-2xl font-bold text-emerald-400 mt-1">₦ 48,650,000</div>
            <span className="text-slate-400 text-[11px]">84% of Total Billed Revenue</span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-slate-400 text-xs font-medium uppercase">Outstanding Fee Defaults</span>
            <div className="text-2xl font-bold text-rose-400 mt-1">₦ 9,280,000</div>
            <span className="text-rose-400 text-[11px] font-semibold">160 Defaulters Flagged</span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-slate-400 text-xs font-medium uppercase">Average Daily Attendance</span>
            <div className="text-2xl font-bold text-amber-400 mt-1">96.8 %</div>
            <span className="text-emerald-400 text-[11px] font-semibold">Staff Check-in Rate: 98%</span>
          </div>
        </div>

        {/* 2. AI Rule-Based Insights Feed */}
        <div className="mb-8 bg-slate-900 border border-amber-500/30 p-6 rounded-xl shadow-lg">
          <div className="flex items-center space-x-2 mb-4">
            <span className="material-symbols-outlined text-amber-400">auto_awesome</span>
            <h2 className="text-lg font-bold text-white">AI Rule-Based Executive Insights & Risk Feed</h2>
          </div>

          <div className="space-y-3">
            <div className="p-3.5 bg-slate-950 rounded-lg border border-slate-800 flex items-start space-x-3">
              <span className="text-amber-400 font-bold text-xs mt-0.5">⚠️ FEE DEFAULT RISK:</span>
              <div className="text-xs text-slate-300">
                <strong>JSS 3 Stream</strong> shows a 22% fee default rate higher than historical average. Recommendation: Dispatch automated WhatsApp reminders prior to CBT exams.
              </div>
            </div>

            <div className="p-3.5 bg-slate-950 rounded-lg border border-slate-800 flex items-start space-x-3">
              <span className="text-emerald-400 font-bold text-xs mt-0.5">📈 ACADEMIC EXCELLENCE:</span>
              <div className="text-xs text-slate-300">
                <strong>SS 2 Science Department</strong> achieved an overall 88.4% mean score in CA Assessments, outperforming last session by +6.2%.
              </div>
            </div>
          </div>
        </div>

        {/* 3. Financial & Defaulters Snapshot */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
          {/* Revenue Breakdown */}
          <div className="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h3 className="font-semibold text-white mb-4">Term Revenue Breakdown by Class</h3>
            <div className="space-y-4 text-xs text-slate-300">
              <div>
                <div className="flex justify-between mb-1">
                  <span>Senior Secondary (SS1 - SS3)</span>
                  <span className="font-bold text-emerald-400">₦ 24,100,000 (92% Collected)</span>
                </div>
                <div className="w-full bg-slate-950 h-2 rounded-full overflow-hidden">
                  <div className="bg-emerald-500 h-full w-[92%]"></div>
                </div>
              </div>

              <div>
                <div className="flex justify-between mb-1">
                  <span>Junior Secondary (JSS1 - JSS3)</span>
                  <span className="font-bold text-amber-400">₦ 18,300,000 (78% Collected)</span>
                </div>
                <div className="w-full bg-slate-950 h-2 rounded-full overflow-hidden">
                  <div className="bg-amber-500 h-full w-[78%]"></div>
                </div>
              </div>

              <div>
                <div className="flex justify-between mb-1">
                  <span>Nursery & Primary Sections</span>
                  <span className="font-bold text-emerald-400">₦ 6,250,000 (85% Collected)</span>
                </div>
                <div className="w-full bg-slate-950 h-2 rounded-full overflow-hidden">
                  <div className="bg-emerald-500 h-full w-[85%]"></div>
                </div>
              </div>
            </div>
          </div>

          {/* Top Defaulters List */}
          <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div className="p-4 border-b border-slate-800 flex justify-between items-center">
              <h3 className="font-semibold text-white">Top Outstanding Accounts</h3>
              <span className="text-xs text-rose-400 font-bold">160 Defaulters Total</span>
            </div>

            <table className="w-full text-left text-xs text-slate-300">
              <thead className="bg-slate-950 text-slate-400 uppercase font-semibold">
                <tr>
                  <th className="p-3">Student</th>
                  <th className="p-3">Class</th>
                  <th className="p-3">Outstanding</th>
                  <th className="p-3">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                <tr>
                  <td className="p-3 font-medium text-white">Chidimma Okoro</td>
                  <td className="p-3">JSS 2 Gold</td>
                  <td className="p-3 font-bold text-rose-400">₦ 145,000</td>
                  <td className="p-3">
                    <button
                      onClick={() => alert('Sending automated WhatsApp fee reminder to guardian...')}
                      className="px-2.5 py-1 bg-amber-500/10 text-amber-400 border border-amber-500/30 rounded font-bold hover:bg-amber-500/20"
                    >
                      WhatsApp Alert
                    </button>
                  </td>
                </tr>
                <tr>
                  <td className="p-3 font-medium text-white">Tunde Bakare</td>
                  <td className="p-3">SS 1 Science</td>
                  <td className="p-3 font-bold text-rose-400">₦ 120,000</td>
                  <td className="p-3">
                    <button
                      onClick={() => alert('Sending automated WhatsApp fee reminder to guardian...')}
                      className="px-2.5 py-1 bg-amber-500/10 text-amber-400 border border-amber-500/30 rounded font-bold hover:bg-amber-500/20"
                    >
                      WhatsApp Alert
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
  );
}
