import React from 'react';
import Link from 'next/link';

export default function Sidebar() {
  return (
    <aside className="w-64 bg-slate-900 text-slate-300 min-h-screen flex flex-col justify-between p-4 border-r border-slate-800">
      <div>
        <div className="flex items-center space-x-3 mb-8 px-2">
          <div className="w-8 h-8 rounded-lg bg-emerald-500 flex items-center justify-center text-slate-950 font-bold text-lg">
            S
          </div>
          <div>
            <h1 className="font-bold text-white leading-none">SchoolPilot</h1>
            <span className="text-xs text-slate-500">K-12 Management</span>
          </div>
        </div>

        <nav className="space-y-1 text-sm font-medium">
          <Link href="/" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 text-white transition">
            <span>📊</span>
            <span>Dashboard</span>
          </Link>
          <Link href="/setup-wizard" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>⚙️</span>
            <span>Setup Wizard</span>
          </Link>
          <Link href="/students" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>🎓</span>
            <span>Students Directory</span>
          </Link>
          <Link href="/staff" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>👨‍🏫</span>
            <span>Staff Directory</span>
          </Link>
          <Link href="/timetable" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>🗓️</span>
            <span>Timetable Builder</span>
          </Link>
          <Link href="/attendance" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>⏱️</span>
            <span>Attendance</span>
          </Link>
          <Link href="/results" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>📝</span>
            <span>Results & Broadsheet</span>
          </Link>
          <Link href="/cbt-setup" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>💻</span>
            <span>CBT Exams</span>
          </Link>
          <Link href="/finance" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>💳</span>
            <span>Fees & Finance</span>
          </Link>
          <Link href="/notifications" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>📢</span>
            <span>Notifications</span>
          </Link>
          <Link href="/analytics" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
            <span>📈</span>
            <span>Analytics & Insights</span>
          </Link>
          <Link href="/director" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white text-amber-400 font-bold transition">
            <span>👑</span>
            <span>Director Console</span>
          </Link>
          <Link href="/super-admin" className="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white text-indigo-400 transition">
            <span>🛡️</span>
            <span>Super Admin</span>
          </Link>
        </nav>
      </div>

      <div className="pt-4 border-t border-slate-800 px-2 text-xs text-slate-500">
        <p className="font-semibold text-slate-400">Greenfield Academy</p>
        <p>subdomain: greenfield.schoolpilot.ng</p>
      </div>
    </aside>
  );
}
