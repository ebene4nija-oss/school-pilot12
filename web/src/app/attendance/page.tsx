'use client';

import React, { useState } from 'react';
import Sidebar from '@/components/Sidebar';

export default function AttendancePage() {
  const [activeTab, setActiveTab] = useState<'qr_scanner' | 'manual_roster' | 'staff_gps'>('qr_scanner');
  const [scannedResult, setScannedResult] = useState<string | null>(null);

  const [students, setStudents] = useState([
    { id: 1, adm: 'ADM-001', name: 'Abebe Bikila', gender: 'Male', status: 'Present', time: '07:45 AM' },
    { id: 2, adm: 'ADM-002', name: 'Chidimma Okoro', gender: 'Female', status: 'Present', time: '07:52 AM' },
    { id: 3, adm: 'ADM-003', name: 'Tunde Bakare', gender: 'Male', status: 'Absent', time: '-' },
    { id: 4, adm: 'ADM-004', name: 'Amina Bello', gender: 'Female', status: 'Late', time: '08:15 AM' },
  ]);

  const toggleStatus = (id: number) => {
    setStudents(students.map(s => {
      if (s.id === id) {
        const nextStatus = s.status === 'Present' ? 'Absent' : s.status === 'Absent' ? 'Late' : 'Present';
        return { ...s, status: nextStatus, time: nextStatus === 'Absent' ? '-' : new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) };
      }
      return s;
    }));
  };

  const handleSimulateScan = () => {
    setScannedResult('Scanning QR Badge...');
    setTimeout(() => {
      setScannedResult('✓ SUCCESS: Attendance Marked for Abebe Bikila (ADM-001) at ' + new Date().toLocaleTimeString());
    }, 1000);
  };

  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="text-2xl font-bold text-white">Hardware-Free Attendance & Clock-In Engine</h1>
            <p className="text-slate-400 text-sm">QR Code Camera Scanning, Manual Roster Marking, and Staff GPS Clock-In</p>
          </div>
          <div className="flex space-x-2">
            <button
              onClick={() => setActiveTab('qr_scanner')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'qr_scanner' ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              📷 Camera QR Scan
            </button>
            <button
              onClick={() => setActiveTab('manual_roster')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'manual_roster' ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              📝 Manual Roster Mark
            </button>
            <button
              onClick={() => setActiveTab('staff_gps')}
              className={`px-3.5 py-2 rounded-lg text-xs font-semibold transition ${
                activeTab === 'staff_gps' ? 'bg-amber-500 text-slate-950 font-bold' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'
              }`}
            >
              📍 Staff Phone GPS Clock-In
            </button>
          </div>
        </div>

        {/* 1. Camera QR Scanner View */}
        {activeTab === 'qr_scanner' && (
          <div className="mb-8 bg-slate-900 border border-slate-800 p-8 rounded-xl shadow-lg max-w-2xl mx-auto text-center">
            <h2 className="text-lg font-bold text-white mb-2">Hardware-Free Student QR Scanner</h2>
            <p className="text-slate-400 text-xs mb-6">Scan printed student ID badge or mobile app QR token using device camera. No hardware scanners required.</p>

            <div className="w-64 h-64 mx-auto border-2 border-dashed border-emerald-500/50 rounded-2xl bg-slate-950 flex flex-col items-center justify-center p-4 mb-6 relative overflow-hidden">
              <div className="w-48 h-48 border-2 border-emerald-400 rounded-lg flex items-center justify-center relative animate-pulse">
                <span className="material-symbols-outlined text-4xl text-emerald-400">qr_code_scanner</span>
              </div>
              <span className="text-[10px] text-emerald-400 font-mono mt-3">ALIGN QR CODE WITHIN FRAME</span>
            </div>

            <button
              onClick={handleSimulateScan}
              className="px-6 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold rounded-lg text-xs transition"
            >
              Simulate Camera Scan Event
            </button>

            {scannedResult && (
              <div className="mt-4 p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-lg text-xs text-emerald-300 font-mono">
                {scannedResult}
              </div>
            )}
          </div>
        )}

        {/* 2. Staff Phone GPS Clock-In */}
        {activeTab === 'staff_gps' && (
          <div className="mb-8 bg-slate-900 border border-amber-500/30 p-8 rounded-xl shadow-lg max-w-2xl mx-auto text-center">
            <h2 className="text-lg font-bold text-white mb-2">Staff Phone GPS Location Clock-In</h2>
            <p className="text-slate-400 text-xs mb-6">Staff clock in directly from their mobile phone built-in GPS. Logs precise lat/lng without biometric hardware.</p>

            <div className="p-4 bg-slate-950 rounded-lg border border-slate-800 text-left text-xs mb-6 space-y-2">
              <div className="flex justify-between"><span className="text-slate-400">Target Campus:</span> <strong className="text-white">Grace Land Main Campus</strong></div>
              <div className="flex justify-between"><span className="text-slate-400">Campus GPS Center:</span> <code className="text-amber-400">6.5244° N, 3.3792° E</code></div>
              <div className="flex justify-between"><span className="text-slate-400">Your Phone Position:</span> <code className="text-emerald-400">6.5245° N, 3.3791° E (Accurate to 3m)</code></div>
            </div>

            <button
              onClick={() => alert('GPS Clock-In Logged Cleanly! Sent Lat/Lng ping to SchoolPilot Backend Audit Trail.')}
              className="w-full py-3 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold rounded-lg text-xs transition"
            >
              📍 Clock In Now via Device GPS
            </button>
          </div>
        )}

        {/* 3. Manual Roster Marking */}
        {activeTab === 'manual_roster' && (
          <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
            <div className="p-4 border-b border-slate-800 flex justify-between items-center">
              <div>
                <h3 className="font-semibold text-white">JSS 1 Gold Attendance Register</h3>
                <p className="text-xs text-slate-400">Click status badge to toggle Present / Absent / Late</p>
              </div>
              <span className="text-xs bg-slate-800 text-slate-300 px-2.5 py-1 rounded-full">{students.length} Students</span>
            </div>

            <table className="w-full text-left text-sm text-slate-300">
              <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
                <tr>
                  <th className="p-3.5">Admission No</th>
                  <th className="p-3.5">Student Name</th>
                  <th className="p-3.5">Gender</th>
                  <th className="p-3.5">Check-In Time</th>
                  <th className="p-3.5">Attendance Status (Click to Toggle)</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                {students.map((student) => (
                  <tr key={student.id} className="hover:bg-slate-800/50 transition">
                    <td className="p-3.5 font-mono text-emerald-400 text-xs">{student.adm}</td>
                    <td className="p-3.5 font-medium text-white">{student.name}</td>
                    <td className="p-3.5">{student.gender}</td>
                    <td className="p-3.5 font-mono text-xs text-slate-400">{student.time}</td>
                    <td className="p-3.5">
                      <button
                        onClick={() => toggleStatus(student.id)}
                        className={`px-3 py-1 text-xs font-bold rounded-md border transition ${
                          student.status === 'Present'
                            ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/20'
                            : student.status === 'Late'
                            ? 'bg-amber-500/10 text-amber-400 border-amber-500/30 hover:bg-amber-500/20'
                            : 'bg-rose-500/10 text-rose-400 border-rose-500/30 hover:bg-rose-500/20'
                        }`}
                      >
                        {student.status}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </main>
    </div>
  );
}
