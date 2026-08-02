"use client";

import React, { useState } from "react";

export default function AdminDashboard() {
  const [activeTab, setActiveTab] = useState("Dashboard");

  return (
    <div className="flex min-h-screen bg-[#fcf9f8] text-[#1b1c1c] font-body">
      {/* SideNavBar */}
      <aside className="hidden md:flex h-screen w-64 fixed left-0 top-0 bg-[#002447] border-r border-[#c3c6cf] flex-col p-4 gap-4 z-40">
        <div className="mb-4 px-4 pt-2">
          <h1 className="font-headline text-2xl font-bold text-white">SchoolPilot</h1>
          <p className="text-xs text-white/70">Admin Portal</p>
        </div>
        <nav className="flex-1 space-y-2">
          {[
            { name: "Dashboard", icon: "dashboard" },
            { name: "Students", icon: "group" },
            { name: "Staff", icon: "badge" },
            { name: "Timetable", icon: "calendar_month" },
            { name: "Results", icon: "grading" },
            { name: "CBT", icon: "quiz" },
            { name: "Fees", icon: "payments" },
            { name: "AI Studio", icon: "auto_awesome" },
            { name: "Analytics", icon: "analytics" },
            { name: "Settings", icon: "settings" },
          ].map((item) => (
            <button
              key={item.name}
              onClick={() => setActiveTab(item.name)}
              className={`w-full flex items-center gap-3 px-4 py-2 rounded-lg text-sm transition-colors text-left ${
                activeTab === item.name
                  ? "bg-[#feae2c] text-[#6b4500] font-bold"
                  : "text-white/70 hover:text-white hover:bg-white/10"
              }`}
            >
              <span className="material-symbols-outlined">{item.icon}</span>
              <span>{item.name}</span>
            </button>
          ))}
        </nav>
        <div className="mt-auto">
          <button className="w-full bg-[#feae2c] text-[#6b4500] hover:bg-amber-400 text-xs font-bold py-2.5 px-4 rounded-lg flex items-center justify-center gap-1 transition-colors">
            <span className="material-symbols-outlined text-[18px]">add</span>
            New Assessment
          </button>
        </div>
      </aside>

      {/* Main Content Area */}
      <div className="md:ml-64 flex flex-col flex-1 min-h-screen">
        {/* TopNavBar */}
        <header className="flex justify-between items-center h-16 px-6 sticky top-0 bg-[#fcf9f8] border-b border-[#c3c6cf] z-30">
          <div className="flex items-center gap-4">
            <h2 className="font-headline text-lg font-bold text-[#002447]">
              Grace Land International College
            </h2>
            <span className="bg-[#eae7e7] text-[#002447] text-xs font-bold px-3 py-1 rounded-full">
              Term 1, 2025/2026
            </span>
          </div>

          <div className="flex items-center gap-4">
            <div className="relative">
              <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">
                search
              </span>
              <input
                type="text"
                placeholder="Search students, staff..."
                className="bg-[#eae7e7] rounded-full pl-9 pr-4 py-1.5 text-xs text-gray-800 focus:outline-none w-48"
              />
            </div>
            <button className="p-2 text-gray-600 hover:bg-gray-200 rounded-full transition-colors relative">
              <span className="material-symbols-outlined">notifications</span>
              <span className="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"></span>
            </button>
            <div className="flex items-center gap-2 border-l pl-4 border-gray-300">
              <div className="w-8 h-8 rounded-full bg-[#002447] text-white flex items-center justify-center font-bold text-xs">
                SA
              </div>
              <div className="hidden lg:block text-left">
                <p className="text-xs font-bold text-[#1b1c1c]">School Admin</p>
                <p className="text-[10px] text-gray-500">admin@graceland.edu.ng</p>
              </div>
            </div>
          </div>
        </header>

        {/* Dashboard Body */}
        <main className="flex-1 p-6 space-y-6">
          {/* Welcome Banner */}
          <div className="bg-[#002447] text-white rounded-2xl p-6 relative overflow-hidden shadow-sm flex justify-between items-center">
            <div>
              <h1 className="font-headline text-2xl font-bold mb-1">
                Welcome back, Administrator 👋
              </h1>
              <p className="text-sm text-white/80 max-w-xl">
                Result compilation for First Term 2025/2026 is currently open. 84% of scores have been uploaded by teachers.
              </p>
            </div>
            <div className="flex gap-3">
              <button className="bg-[#feae2c] text-[#6b4500] font-bold text-xs px-4 py-2.5 rounded-lg hover:bg-amber-400 transition-colors">
                Compile Results
              </button>
            </div>
          </div>

          {/* Quick Metrics */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            {[
              { title: "Total Students", value: "1,248", change: "+4.2% from last term", icon: "groups", color: "border-blue-500" },
              { title: "Active Staff", value: "86", change: "100% attendance today", icon: "badge", color: "border-emerald-500" },
              { title: "Fees Collected", value: "₦14.2M", change: "78% of expected revenue", icon: "payments", color: "border-amber-500" },
              { title: "Today's Attendance", value: "96.4%", change: "1,203 marked present", icon: "how_to_reg", color: "border-purple-500" },
            ].map((metric, idx) => (
              <div key={idx} className={`bg-white rounded-xl p-5 border-l-4 ${metric.color} border border-gray-100 shadow-sm`}>
                <div className="flex justify-between items-center mb-2">
                  <span className="text-xs text-gray-500 font-medium">{metric.title}</span>
                  <span className="material-symbols-outlined text-gray-400 text-lg">{metric.icon}</span>
                </div>
                <div className="font-headline text-2xl font-bold text-[#1b1c1c] mb-1">{metric.value}</div>
                <div className="text-[11px] text-gray-500">{metric.change}</div>
              </div>
            ))}
          </div>

          {/* Feature Highlights Grid */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Broadsheet & Results Overview */}
            <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm col-span-2 space-y-4">
              <div className="flex justify-between items-center">
                <h3 className="font-headline font-bold text-[#002447] text-base flex items-center gap-2">
                  <span className="material-symbols-outlined text-amber-600">grading</span>
                  Academic Broadsheet Status
                </h3>
                <span className="text-xs text-blue-600 font-semibold cursor-pointer">View Broadsheet &rarr;</span>
              </div>
              <div className="space-y-3">
                {[
                  { class: "JSS 1 (Gold)", progress: 95, status: "Ready for Compilation" },
                  { class: "JSS 2 (Silver)", progress: 80, status: "15 Pending CA Entries" },
                  { class: "SS 1 (Science)", progress: 100, status: "Compiled & Approved" },
                  { class: "SS 3 (Arts)", progress: 60, status: "Awaiting Exam Marks" },
                ].map((item, idx) => (
                  <div key={idx} className="flex items-center justify-between p-3 bg-[#fcf9f8] rounded-lg">
                    <div>
                      <p className="text-xs font-bold text-[#1b1c1c]">{item.class}</p>
                      <p className="text-[11px] text-gray-500">{item.status}</p>
                    </div>
                    <div className="flex items-center gap-3">
                      <div className="w-32 bg-gray-200 h-2 rounded-full overflow-hidden">
                        <div
                          className="bg-[#002447] h-full rounded-full"
                          style={{ width: `${item.progress}%` }}
                        ></div>
                      </div>
                      <span className="text-xs font-bold text-gray-700">{item.progress}%</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* AI Insights & Approvals Queue */}
            <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm space-y-4">
              <h3 className="font-headline font-bold text-[#002447] text-base flex items-center gap-2">
                <span className="material-symbols-outlined text-purple-600">auto_awesome</span>
                AI Pending Approvals
              </h3>
              <p className="text-xs text-gray-500">
                12 AI-generated report card comments require teacher or admin review before printing.
              </p>
              <div className="space-y-3">
                {[
                  { student: "Chidi Nnamdi", class: "JSS 3", comment: "Demonstrates exceptional problem-solving in Mathematics..." },
                  { student: "Amina Bello", class: "SS 2", comment: "Consistent effort shown in Chemistry practicals..." },
                ].map((aiItem, idx) => (
                  <div key={idx} className="p-3 border border-gray-200 rounded-lg bg-gray-50 text-xs space-y-2">
                    <div className="flex justify-between font-bold text-[#002447]">
                      <span>{aiItem.student} ({aiItem.class})</span>
                      <span className="text-amber-600 bg-amber-100 px-2 py-0.5 rounded text-[10px]">Pending</span>
                    </div>
                    <p className="text-gray-600 italic">"{aiItem.comment}"</p>
                    <div className="flex justify-end gap-2 pt-1">
                      <button className="px-2.5 py-1 bg-emerald-600 text-white rounded text-[10px] font-bold hover:bg-emerald-700">
                        Approve
                      </button>
                      <button className="px-2.5 py-1 bg-gray-300 text-gray-700 rounded text-[10px] hover:bg-gray-400">
                        Edit
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
