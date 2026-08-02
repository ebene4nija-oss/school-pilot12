'use client';

import React, { useState } from 'react';
import Sidebar from '@/components/Sidebar';

export default function HostelPage() {
  const [rooms] = useState([
    { id: 1, hostel: 'Unity House (Boys)', roomNumber: 'RM-101', capacity: 4, occupied: 3 },
    { id: 2, hostel: 'Freedom House (Girls)', roomNumber: 'RM-204', capacity: 4, occupied: 4 },
  ]);

  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="text-2xl font-bold text-white">Hostel & Boarding Allocation</h1>
            <p className="text-slate-400 text-sm">Room allocation, bed assignments, and visitor logging</p>
          </div>
          <button className="px-4 py-2.5 bg-emerald-500 hover:bg-emerald-600 font-bold text-slate-950 rounded-lg text-sm transition">
            + Assign Student Bed
          </button>
        </div>

        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
          <div className="p-4 border-b border-slate-800">
            <h3 className="font-semibold text-white">Hostel Rooms Overview</h3>
          </div>
          <table className="w-full text-left text-sm text-slate-300">
            <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
              <tr>
                <th className="p-3.5">Hostel Building</th>
                <th className="p-3.5">Room Number</th>
                <th className="p-3.5">Occupancy Rate</th>
                <th className="p-3.5">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {rooms.map((room) => (
                <tr key={room.id} className="hover:bg-slate-800/50 transition">
                  <td className="p-3.5 font-medium text-white">{room.hostel}</td>
                  <td className="p-3.5 font-mono text-emerald-400 text-xs">{room.roomNumber}</td>
                  <td className="p-3.5">{room.occupied} / {room.capacity} Beds Occupied</td>
                  <td className="p-3.5">
                    <span
                      className={`px-2 py-0.5 text-xs font-medium rounded-md border ${
                        room.occupied < room.capacity
                          ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                          : 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                      }`}
                    >
                      {room.occupied < room.capacity ? 'Space Available' : 'Fully Occupied'}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>
    </div>
  );
}
