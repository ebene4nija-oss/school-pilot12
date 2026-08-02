'use client';

import React from 'react';
import Sidebar from '@/components/Sidebar';

export default function SuperAdminPage() {
  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <div className="flex items-center space-x-2">
              <span className="px-2 py-0.5 bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded text-xs font-bold uppercase">
                Platform Operator Console
              </span>
            </div>
            <h1 className="text-2xl font-bold text-white mt-1">Super Admin Console</h1>
            <p className="text-slate-400 text-sm">Cross-school management, billing summary, system health, and AI spend caps</p>
          </div>
          <button className="px-4 py-2.5 bg-amber-400 hover:bg-amber-500 font-bold text-slate-950 rounded-lg text-sm transition">
            + Provision New Tenant School
          </button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Onboarded Schools</span>
            <h2 className="text-2xl font-extrabold text-white mt-1">42</h2>
            <span className="text-xs text-emerald-400 mt-2 block">Active Tenants</span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Total Active Students</span>
            <h2 className="text-2xl font-extrabold text-white mt-1">14,850</h2>
            <span className="text-xs text-slate-400 mt-2 block">K-12 Across Nigeria</span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Monthly Platform MRR</span>
            <h2 className="text-2xl font-extrabold text-emerald-400 mt-1">₦8,400,000</h2>
            <span className="text-xs text-emerald-400 mt-2 block">Result & CBT Fees</span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Claude AI API Spend (This Month)</span>
            <h2 className="text-2xl font-extrabold text-amber-400 mt-1">$412.80</h2>
            <span className="text-xs text-slate-400 mt-2 block">Cap: $1,000.00 (41% used)</span>
          </div>
        </div>

        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
          <div className="p-4 border-b border-slate-800 flex justify-between items-center">
            <h3 className="font-semibold text-white">All Tenant Schools Directory</h3>
          </div>
          <table className="w-full text-left text-sm text-slate-300">
            <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
              <tr>
                <th className="p-3.5">School Name</th>
                <th className="p-3.5">Subdomain</th>
                <th className="p-3.5">Students</th>
                <th className="p-3.5">Plan Band</th>
                <th className="p-3.5">Status</th>
                <th className="p-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              <tr className="hover:bg-slate-800/50 transition">
                <td className="p-3.5 font-medium text-white">Greenfield Academy</td>
                <td className="p-3.5 font-mono text-xs text-emerald-400">greenfield</td>
                <td className="p-3.5">450</td>
                <td className="p-3.5">Standard (201-500)</td>
                <td className="p-3.5">
                  <span className="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-medium rounded-md">
                    Active
                  </span>
                </td>
                <td className="p-3.5 text-right">
                  <button className="text-xs text-amber-400 hover:text-amber-300 font-medium">Manage Tenant</button>
                </td>
              </tr>
              <tr className="hover:bg-slate-800/50 transition">
                <td className="p-3.5 font-medium text-white">Grace International School</td>
                <td className="p-3.5 font-mono text-xs text-emerald-400">graceintl</td>
                <td className="p-3.5">820</td>
                <td className="p-3.5">Premium (501-1,000)</td>
                <td className="p-3.5">
                  <span className="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-medium rounded-md">
                    Active
                  </span>
                </td>
                <td className="p-3.5 text-right">
                  <button className="text-xs text-amber-400 hover:text-amber-300 font-medium">Manage Tenant</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </main>
    </div>
  );
}
