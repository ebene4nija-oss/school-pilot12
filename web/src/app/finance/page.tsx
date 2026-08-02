'use client';

import React, { useState } from 'react';
import Sidebar from '@/components/Sidebar';

export default function FeesPage() {
  const [structures] = useState([
    { id: 1, classLevel: 'JSS 1', tuition: 45000, lab: 5000, sports: 3000, total: 53000 },
    { id: 2, classLevel: 'SS 1', tuition: 55000, lab: 8000, sports: 3000, total: 66000 },
  ]);

  const [defaulters] = useState([
    { id: 1, name: 'Tunde Bakare', class: 'JSS 1 Gold', balance: 25000, parentPhone: '08023456789' },
    { id: 2, name: 'Chidimma Okoro', class: 'SS 2 Silver', balance: 18000, parentPhone: '08098765432' },
  ]);

  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="text-2xl font-bold text-white">Fees & Finance Management</h1>
            <p className="text-slate-400 text-sm">Fee structures, Paystack/Flutterwave invoicing, and defaulters tracking</p>
          </div>
          <button className="px-4 py-2.5 bg-emerald-500 hover:bg-emerald-600 font-semibold text-slate-950 rounded-lg text-sm transition">
            + Create Fee Structure
          </button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Total Term Revenue</span>
            <h2 className="text-2xl font-extrabold text-white mt-1">₦4,250,000</h2>
            <span className="text-xs text-emerald-400 mt-2 block">↑ 12% vs last term</span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Collected via Gateways</span>
            <h2 className="text-2xl font-extrabold text-emerald-400 mt-1">₦3,100,000</h2>
            <span className="text-xs text-slate-400 mt-2 block">Paystack & Flutterwave</span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Outstanding Defaulter Balance</span>
            <h2 className="text-2xl font-extrabold text-rose-400 mt-1">₦1,150,000</h2>
            <span className="text-xs text-rose-400 mt-2 block">23 Defaulter Students</span>
          </div>
        </div>

        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg mb-8">
          <div className="p-4 border-b border-slate-800 flex justify-between items-center">
            <h3 className="font-semibold text-white">Fee Structure Definitions</h3>
          </div>
          <table className="w-full text-left text-sm text-slate-300">
            <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
              <tr>
                <th className="p-3.5">Class Level</th>
                <th className="p-3.5">Tuition (₦)</th>
                <th className="p-3.5">Lab / ICT (₦)</th>
                <th className="p-3.5">Sports (₦)</th>
                <th className="p-3.5">Total Fee (₦)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {structures.map((item) => (
                <tr key={item.id} className="hover:bg-slate-800/50 transition">
                  <td className="p-3.5 font-medium text-white">{item.classLevel}</td>
                  <td className="p-3.5">{item.tuition.toLocaleString()}</td>
                  <td className="p-3.5">{item.lab.toLocaleString()}</td>
                  <td className="p-3.5">{item.sports.toLocaleString()}</td>
                  <td className="p-3.5 font-bold text-emerald-400">{item.total.toLocaleString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
          <div className="p-4 border-b border-slate-800 flex justify-between items-center">
            <h3 className="font-semibold text-white text-rose-400">Fee Defaulters List</h3>
          </div>
          <table className="w-full text-left text-sm text-slate-300">
            <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
              <tr>
                <th className="p-3.5">Student Name</th>
                <th className="p-3.5">Class & Arm</th>
                <th className="p-3.5">Outstanding Debt</th>
                <th className="p-3.5">Parent Contact</th>
                <th className="p-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {defaulters.map((item) => (
                <tr key={item.id} className="hover:bg-slate-800/50 transition">
                  <td className="p-3.5 font-medium text-white">{item.name}</td>
                  <td className="p-3.5">{item.class}</td>
                  <td className="p-3.5 font-bold text-rose-400">₦{item.balance.toLocaleString()}</td>
                  <td className="p-3.5 font-mono text-xs">{item.parentPhone}</td>
                  <td className="p-3.5 text-right">
                    <button className="px-3 py-1 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 rounded-md text-xs font-medium transition">
                      Send SMS Reminder
                    </button>
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
