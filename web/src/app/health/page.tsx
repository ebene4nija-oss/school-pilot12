'use client';

import React, { useState } from 'react';
import Sidebar from '@/components/Sidebar';

export default function HealthPage() {
  const [visits] = useState([
    { id: 1, studentName: 'Abebe Bikila', symptoms: 'Mild fever & headache', treatment: 'Paracetamol 500mg administered', nurse: 'Nurse Grace', visitedAt: 'Today, 10:30 AM' },
    { id: 2, studentName: 'Chidimma Okoro', symptoms: 'Minor sports scrape on elbow', treatment: 'Cleaned with antiseptic & bandaged', nurse: 'Nurse Grace', visitedAt: 'Yesterday, 02:15 PM' },
  ]);

  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="text-2xl font-bold text-white">School Health & Clinic Log</h1>
            <p className="text-slate-400 text-sm">Medical visit logs, treatments, and student health records</p>
          </div>
          <button className="px-4 py-2.5 bg-emerald-500 hover:bg-emerald-600 font-bold text-slate-950 rounded-lg text-sm transition">
            + Log Clinic Visit
          </button>
        </div>

        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
          <div className="p-4 border-b border-slate-800">
            <h3 className="font-semibold text-white">Recent Clinic Visits Log</h3>
          </div>
          <table className="w-full text-left text-sm text-slate-300">
            <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
              <tr>
                <th className="p-3.5">Student Name</th>
                <th className="p-3.5">Reported Symptoms</th>
                <th className="p-3.5">Treatment Given</th>
                <th className="p-3.5">Attending Nurse</th>
                <th className="p-3.5">Timestamp</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {visits.map((visit) => (
                <tr key={visit.id} className="hover:bg-slate-800/50 transition">
                  <td className="p-3.5 font-medium text-white">{visit.studentName}</td>
                  <td className="p-3.5 text-rose-400">{visit.symptoms}</td>
                  <td className="p-3.5 text-emerald-400">{visit.treatment}</td>
                  <td className="p-3.5">{visit.nurse}</td>
                  <td className="p-3.5 text-xs text-slate-400">{visit.visitedAt}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>
    </div>
  );
}
