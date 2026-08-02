'use client';

import React from 'react';
import Sidebar from '@/components/Sidebar';

export default function LiveClassPage() {
  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <div className="flex items-center space-x-2">
              <span className="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded text-xs font-bold uppercase">
                Zoom & Google Meet Integration
              </span>
            </div>
            <h1 className="text-2xl font-bold text-white mt-1">Virtual / Live Classrooms</h1>
            <p className="text-slate-400 text-sm">Schedule and join live video sessions with attendance sync</p>
          </div>
          <button className="px-4 py-2.5 bg-emerald-500 hover:bg-emerald-600 font-bold text-slate-950 rounded-lg text-sm transition">
            + Schedule Virtual Class
          </button>
        </div>

        <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-lg mb-8">
          <h3 className="font-semibold text-white mb-4">Scheduled Upcoming Virtual Classes</h3>
          <div className="p-4 bg-slate-950 rounded-lg border border-slate-800 flex justify-between items-center">
            <div>
              <span className="text-xs bg-emerald-500/10 text-emerald-400 px-2 py-0.5 rounded font-medium">JSS 1 Mathematics</span>
              <h4 className="font-bold text-white text-lg mt-1">Algebra & Linear Equations Review</h4>
              <p className="text-xs text-slate-400">Teacher: Mr. Chidi | Today at 02:00 PM (45 Mins)</p>
            </div>
            <button className="px-5 py-2.5 bg-emerald-500 hover:bg-emerald-600 font-bold text-slate-950 text-sm rounded-lg transition">
              🎥 Launch Zoom Session
            </button>
          </div>
        </div>
      </main>
    </div>
  );
}
