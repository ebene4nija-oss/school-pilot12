'use client';

import React, { useState } from 'react';
import Sidebar from '@/components/Sidebar';

export default function BusTrackingPage() {
  const [buses] = useState([
    { id: 1, routeName: 'Lekki Phase 1 Route', busNumber: 'BUS-001', driver: 'Driver John', phone: '08012345678', lat: 6.458985, lng: 3.424355, status: 'Active (On Route)', lastPing: '2 mins ago' },
    { id: 2, routeName: 'Ikeja / Maryland Route', busNumber: 'BUS-002', driver: 'Driver Samuel', phone: '08087654321', lat: 6.59651, lng: 3.34209, status: 'Inactive', lastPing: '1 hour ago' },
  ]);

  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <div className="flex items-center space-x-2">
              <span className="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded text-xs font-bold uppercase">
                Hardware-Free GPS (Driver Phone)
              </span>
            </div>
            <h1 className="text-2xl font-bold text-white mt-1">Bus Fleet GPS Tracking</h1>
            <p className="text-slate-400 text-sm">Real-time driver phone GPS location broadcasting for parents and school ops</p>
          </div>
          <button className="px-4 py-2.5 bg-emerald-500 hover:bg-emerald-600 font-bold text-slate-950 rounded-lg text-sm transition">
            + Register New Bus Route
          </button>
        </div>

        {/* Live GPS Broadcast Map Mock */}
        <div className="mb-8 bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-lg">
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-lg font-bold text-white">Live Route Radar</h2>
            <span className="text-xs font-mono text-emerald-400 animate-pulse">● Live Broadcasting</span>
          </div>

          <div className="h-64 bg-slate-950 rounded-lg border border-slate-800 flex flex-col items-center justify-center relative overflow-hidden">
            <div className="absolute inset-0 bg-emerald-500/5 flex items-center justify-center">
              <div className="w-48 h-48 rounded-full border border-emerald-500/20 animate-ping"></div>
            </div>

            <div className="z-10 text-center">
              <span className="text-4xl mb-2 block">🚌</span>
              <p className="text-sm font-bold text-white">BUS-001 (Lekki Phase 1 Route)</p>
              <p className="text-xs font-mono text-emerald-400">Lat: 6.458985, Lng: 3.424355</p>
              <p className="text-xs text-slate-500 mt-1">Broadcasted from Driver John's App GPS</p>
            </div>
          </div>
        </div>

        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
          <div className="p-4 border-b border-slate-800">
            <h3 className="font-semibold text-white">Active Bus Fleet Roster</h3>
          </div>
          <table className="w-full text-left text-sm text-slate-300">
            <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
              <tr>
                <th className="p-3.5">Bus Code</th>
                <th className="p-3.5">Route Name</th>
                <th className="p-3.5">Driver & Contact</th>
                <th className="p-3.5">Current GPS Status</th>
                <th className="p-3.5">Last Ping</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {buses.map((bus) => (
                <tr key={bus.id} className="hover:bg-slate-800/50 transition">
                  <td className="p-3.5 font-mono text-emerald-400 text-xs">{bus.busNumber}</td>
                  <td className="p-3.5 font-medium text-white">{bus.routeName}</td>
                  <td className="p-3.5">{bus.driver} ({bus.phone})</td>
                  <td className="p-3.5">
                    <span
                      className={`px-2 py-0.5 text-xs font-medium rounded-md border ${
                        bus.status.includes('Active')
                          ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                          : 'bg-slate-800 text-slate-400 border-slate-700'
                      }`}
                    >
                      {bus.status}
                    </span>
                  </td>
                  <td className="p-3.5 text-xs text-slate-400">{bus.lastPing}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>
    </div>
  );
}
