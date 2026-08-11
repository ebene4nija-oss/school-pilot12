'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';

/**
 * Admin navigation.
 *
 * Every link here now resolves. Six of them — setup, staff, timetable, CBT,
 * notifications and analytics — pointed at routes that had never been built,
 * so the portal advertised most of its own feature set and 404'd on it.
 */

type Item = { href: string; icon: string; label: string; className?: string };

const SECTIONS: { heading?: string; items: Item[] }[] = [
  {
    items: [
      { href: '/', icon: '📊', label: 'Dashboard' },
      { href: '/setup-wizard', icon: '⚙️', label: 'Setup checklist' },
    ],
  },
  {
    heading: 'People',
    items: [
      { href: '/students', icon: '🎓', label: 'Students Directory' },
      { href: '/staff', icon: '👨‍🏫', label: 'Staff & Leave' },
    ],
  },
  {
    heading: 'Academics',
    items: [
      { href: '/timetable', icon: '🗓️', label: 'Timetable Builder' },
      { href: '/attendance', icon: '⏱️', label: 'Attendance' },
      { href: '/results', icon: '📝', label: 'Results & Broadsheet' },
      { href: '/cbt-setup', icon: '💻', label: 'CBT Exams' },
    ],
  },
  {
    heading: 'Operations',
    items: [
      { href: '/finance', icon: '💳', label: 'Fees & Finance' },
      { href: '/notifications', icon: '📢', label: 'Notifications' },
      { href: '/analytics', icon: '📈', label: 'Analytics & Insights' },
    ],
  },
  {
    heading: 'Administration',
    items: [
      { href: '/security', icon: '🔐', label: 'Security' },
      { href: '/director', icon: '👑', label: 'Director Console', className: 'text-amber-400 font-bold' },
      { href: '/super-admin', icon: '🛡️', label: 'Super Admin', className: 'text-indigo-400' },
    ],
  },
];

export default function Sidebar() {
  const pathname = usePathname();

  return (
    <aside className="flex min-h-screen w-64 shrink-0 flex-col justify-between border-r border-slate-800 bg-slate-900 p-4 text-slate-300">
      <div>
        <div className="mb-8 flex items-center space-x-3 px-2">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500 text-lg font-bold text-slate-950">
            S
          </div>
          <div>
            <h1 className="font-bold leading-none text-white">SchoolPilot</h1>
            <span className="text-xs text-slate-500">K-12 Management</span>
          </div>
        </div>

        <nav className="space-y-4 text-sm font-medium">
          {SECTIONS.map((section, i) => (
            <div key={section.heading ?? i} className="space-y-1">
              {section.heading && (
                <p className="px-3 pb-1 text-[0.65rem] uppercase tracking-wider text-slate-600">
                  {section.heading}
                </p>
              )}
              {section.items.map((item) => {
                // Exact match for the dashboard, prefix match elsewhere — "/"
                // is a prefix of every route.
                const active = item.href === '/' ? pathname === '/' : pathname.startsWith(item.href);

                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    aria-current={active ? 'page' : undefined}
                    className={`flex items-center space-x-3 rounded-lg px-3 py-2.5 transition ${
                      active ? 'bg-slate-800 text-white' : 'hover:bg-slate-800 hover:text-white'
                    } ${item.className ?? ''}`}
                  >
                    <span aria-hidden="true">{item.icon}</span>
                    <span>{item.label}</span>
                  </Link>
                );
              })}
            </div>
          ))}
        </nav>
      </div>

      <div className="border-t border-slate-800 px-2 pt-4 text-xs text-slate-500">
        <p className="font-semibold text-slate-400">Greenfield Academy</p>
        <p>subdomain: greenfield.schoolpilot.ng</p>
      </div>
    </aside>
  );
}
