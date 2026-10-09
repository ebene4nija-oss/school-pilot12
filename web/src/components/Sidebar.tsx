'use client';

import React, { useEffect, useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { logout, viewSchool } from '@/app/actions/auth';
import { fetchApi } from '@/lib/api';

/**
 * Admin navigation.
 *
 * Two things this used to get wrong. The footer hardcoded "Greenfield Academy"
 * and its subdomain, so every school on the platform saw another school's name
 * on every screen. And every role was offered every link, including the
 * Director console and the platform operator's console — a school's own admin
 * was invited into SchoolPilot's cross-tenant screens, which simply 403 for
 * them.
 *
 * Role here decides what is *shown*, never what is permitted: the role cookie
 * is readable by the browser and the API re-checks every request, so hiding a
 * link is a courtesy and not a control.
 */

export type Role = 'super_admin' | 'school_admin' | 'teacher' | 'nurse' | 'bursar' | 'student' | 'parent' | 'partner';

type Item = {
  href: string;
  icon: string;
  label: string;
  /** Roles that see this link. Absent means every signed-in role. */
  roles?: Role[];
  /** An optional module SchoolPilot can switch off per school (SchoolFeatures). */
  module?: string;
  /** A count shown beside the label, from `badges`. */
  badge?: string;
  className?: string;
};

const SECTIONS: { heading?: string; items: Item[] }[] = [
  // A partner's whole menu: they belong to no school.
  {
    heading: 'Partner',
    items: [{ href: '/partner', icon: '🤝', label: 'My referrals', roles: ['partner'] }],
  },
  /*
   * The operator's own menu (docs/super-admin-menu.md). First, so it stays on
   * top while an operator has a school open and that school's menu appears
   * beneath it.
   */
  {
    heading: 'Operator',
    items: [
      { href: '/super-admin', icon: '🛡️', label: 'Schools', roles: ['super_admin'], className: 'font-semibold' },
      { href: '/super-admin/leads', icon: '📞', label: 'Website leads', roles: ['super_admin'] },
      { href: '/super-admin/website', icon: '🌐', label: 'Website', roles: ['super_admin'] },
      { href: '/super-admin/blog', icon: '✍️', label: 'Blog', roles: ['super_admin'] },
      { href: '/super-admin/compare', icon: '⚖️', label: 'Compare', roles: ['super_admin'] },
      { href: '/super-admin/migrations', icon: '📥', label: 'Onboarding & migrations', roles: ['super_admin'] },
      { href: '/super-admin/billing', icon: '💳', label: 'Billing', roles: ['super_admin'] },
      { href: '/super-admin/partners', icon: '🤝', label: 'Partners', roles: ['super_admin'] },
      { href: '/super-admin/result-pins', icon: '🎟️', label: 'Result PINs', roles: ['super_admin'] },
      { href: '/super-admin/report-card-requests', icon: '🖨️', label: 'Report card requests', roles: ['super_admin'], badge: 'rc_requests' },
      { href: '/super-admin/announcements', icon: '📣', label: 'Announcements', roles: ['super_admin'] },
    ],
  },
  {
    heading: 'Platform',
    items: [
      { href: '/super-admin/ai', icon: '🤖', label: 'AI service', roles: ['super_admin'] },
      { href: '/super-admin/ai-remarks', icon: '📝', label: 'AI remarks', roles: ['super_admin'] },
      { href: '/super-admin/messaging', icon: '✉️', label: 'Messaging', roles: ['super_admin'] },
      { href: '/super-admin/security', icon: '🔐', label: 'Security', roles: ['super_admin'] },
      { href: '/super-admin/audit-log', icon: '📜', label: 'Audit log', roles: ['super_admin'] },
      { href: '/super-admin/compliance', icon: '💾', label: 'Backups & data requests', roles: ['super_admin'] },
      { href: '/super-admin/settings', icon: '🔧', label: 'Platform settings', roles: ['super_admin'] },
      { href: '/super-admin/maintenance', icon: '🧹', label: 'Cache & maintenance', roles: ['super_admin'] },
    ],
  },
  {
    items: [
      { href: '/', icon: '📊', label: 'Dashboard', roles: ['school_admin'] },
      { href: '/teacher', icon: '📊', label: 'My dashboard', roles: ['teacher'] },
      { href: '/teacher/homework', icon: '📝', label: 'Homework', roles: ['school_admin', 'teacher'] },
      { href: '/teacher/ai-studio', icon: '✨', label: 'AI Studio', roles: ['school_admin', 'teacher'] },
      { href: '/teacher/students', icon: '🗂️', label: 'Student file', roles: ['teacher'] },
      { href: '/teacher/leave', icon: '🌴', label: 'My leave & record', roles: ['teacher'] },
      { href: '/parent', icon: '👨‍👩‍👧', label: 'My children', roles: ['parent'] },
      { href: '/parent/child', icon: '🧒', label: 'My child', roles: ['parent'] },
      { href: '/parent/homework', icon: '📝', label: 'Homework', roles: ['parent'] },
      { href: '/parent/safety', icon: '🛡️', label: 'Pickup & consent', roles: ['parent'] },
      { href: '/student', icon: '🏠', label: 'My school day', roles: ['student'] },
      { href: '/parent/results', icon: '📄', label: 'Results', roles: ['parent'] },
      { href: '/parent/fees', icon: '💳', label: 'Fees', roles: ['parent'] },
      { href: '/parent/messages', icon: '💬', label: 'Messages', roles: ['parent'] },
      { href: '/inbox', icon: '🔔', label: 'Notifications', roles: ['parent'] },
      { href: '/account', icon: '👤', label: 'My account', roles: ['parent'] },
      { href: '/setup-wizard', icon: '⚙️', label: 'Setup checklist', roles: ['school_admin'] },
    ],
  },
  /*
   * The student's own menu: every student screen the API offers (TASK 02–15),
   * which used to be three links and a line telling them to use the app.
   */
  {
    heading: 'My school',
    items: [
      { href: '/student/timetable', icon: '🗓️', label: 'Timetable', roles: ['student'] },
      { href: '/student/homework', icon: '📝', label: 'Homework', roles: ['student'] },
      { href: '/student/tests', icon: '💻', label: 'Tests (CBT)', roles: ['student'], module: 'cbt' },
      { href: '/student/results', icon: '📄', label: 'Results & report card', roles: ['student'] },
      { href: '/student/progress', icon: '📈', label: 'My progress', roles: ['student'] },
      { href: '/student/tutor', icon: '🤖', label: 'AI tutor', roles: ['student'] },
      { href: '/student/notifications', icon: '🔔', label: 'Notifications', roles: ['student'] },
    ],
  },
  {
    heading: 'Me',
    items: [
      { href: '/student/profile', icon: '👤', label: 'My profile', roles: ['student'] },
      { href: '/student/fees', icon: '💳', label: 'My fees', roles: ['student'] },
      { href: '/student/hostel', icon: '🛏️', label: 'Hostel', roles: ['student'], module: 'hostel' },
    ],
  },
  {
    heading: 'People',
    items: [
      { href: '/students', icon: '🎓', label: 'Students', roles: ['school_admin', 'teacher'] },
      { href: '/students/lifecycle', icon: '🔄', label: 'Rollover & leavers', roles: ['school_admin'] },
      { href: '/admissions', icon: '📝', label: 'Admissions', roles: ['school_admin', 'teacher'] },
      { href: '/progress', icon: '📈', label: 'Progress & analysis', roles: ['school_admin', 'teacher'] },
      { href: '/academic-history', icon: '📚', label: 'Academic history', roles: ['school_admin', 'teacher'] },
      { href: '/transfers', icon: '🔁', label: 'Transfers', roles: ['school_admin'] },
      { href: '/staff', icon: '👩‍🏫', label: 'Staff & leave', roles: ['school_admin'] },
      { href: '/id-cards', icon: '🪪', label: 'ID cards', roles: ['school_admin'] },
    ],
  },
  {
    heading: 'Academics',
    items: [
      { href: '/classes', icon: '🏷️', label: 'Classes & arms', roles: ['school_admin'] },
      { href: '/subjects', icon: '📘', label: 'Subjects', roles: ['school_admin'] },
      { href: '/teaching', icon: '🧑‍🏫', label: 'Who teaches what', roles: ['school_admin'] },
      { href: '/timetable', icon: '🗓️', label: 'Timetable', roles: ['school_admin', 'teacher'] },
      { href: '/exam-timetable', icon: '🗒️', label: 'Exam timetable', roles: ['school_admin'] },
      { href: '/attendance', icon: '⏱️', label: 'Attendance', roles: ['school_admin', 'teacher'] },
      { href: '/attendance/corrections', icon: '✏️', label: 'Attendance changes', roles: ['school_admin', 'teacher'] },
      { href: '/results', icon: '📝', label: 'Score entry', roles: ['school_admin', 'teacher'] },
      { href: '/broadsheet', icon: '📋', label: 'Broadsheet & approval', roles: ['school_admin', 'teacher'] },
      { href: '/broadsheet/annual', icon: '🎓', label: 'Session results & promotion', roles: ['school_admin', 'teacher'] },
      { href: '/sections', icon: '🏫', label: 'Sections & schemes', roles: ['school_admin', 'teacher'] },
      { href: '/report-card-design', icon: '🎨', label: 'Report cards & grading', roles: ['school_admin'] },
      { href: '/report-entries', icon: '⭐', label: 'Ratings & comments', roles: ['school_admin', 'teacher'] },
      { href: '/developmental', icon: '🧸', label: 'Early-years ratings', roles: ['school_admin', 'teacher'] },
      { href: '/cbt-setup', icon: '💻', label: 'CBT exams', roles: ['school_admin', 'teacher'], module: 'cbt' },
      { href: '/cbt-bank', icon: '🗃️', label: 'Question bank', roles: ['school_admin', 'teacher'], module: 'cbt' },
      { href: '/cbt-oversight', icon: '🛰️', label: 'CBT monitor & regrade', roles: ['school_admin', 'teacher'], module: 'cbt' },
      { href: '/learning', icon: '📖', label: 'Learning', roles: ['school_admin', 'teacher', 'student', 'parent'] },
      { href: '/live-class', icon: '🎥', label: 'Virtual classes', roles: ['school_admin', 'teacher', 'student', 'parent'], module: 'live_classes' },
    ],
  },
  {
    heading: 'Operations',
    items: [
      { href: '/finance', icon: '💳', label: 'Fees & finance', roles: ['school_admin', 'bursar'] },
      { href: '/finance/fee-status', icon: '✅', label: 'Who has paid', roles: ['school_admin', 'bursar'] },
      { href: '/finance/collect', icon: '🧾', label: 'Collect payments', roles: ['school_admin', 'bursar'] },
      { href: '/finance/payments', icon: '📒', label: 'Payments ledger', roles: ['school_admin', 'bursar'] },
      { href: '/finance/scholarships', icon: '🎓', label: 'Scholarships', roles: ['school_admin', 'bursar'] },
      { href: '/finance/hostel-charges', icon: '🛏️', label: 'Hostel charges', roles: ['school_admin', 'bursar'], module: 'hostel' },
      { href: '/accounting', icon: '📊', label: 'Accounts', roles: ['school_admin', 'bursar'] },
      { href: '/finance/students', icon: '🔎', label: 'Students', roles: ['bursar'] },
      { href: '/payroll', icon: '💼', label: 'Payroll', roles: ['school_admin', 'bursar', 'teacher', 'nurse'] },
      { href: '/result-pins', icon: '🎟️', label: 'Result PINs', roles: ['school_admin', 'bursar'] },
      { href: '/library', icon: '📚', label: 'Library', roles: ['school_admin', 'teacher', 'student', 'parent'], module: 'library' },
      { href: '/hostel', icon: '🛏️', label: 'Hostel', roles: ['school_admin', 'teacher', 'nurse'], module: 'hostel' },
      { href: '/hostel/operations', icon: '🧺', label: 'Hostel operations', roles: ['school_admin', 'teacher', 'nurse'], module: 'hostel' },
      { href: '/bus-tracking', icon: '🚌', label: 'Transport', roles: ['school_admin', 'teacher'], module: 'transport' },
      { href: '/health', icon: '🩺', label: 'Clinic log', roles: ['school_admin', 'nurse'], module: 'clinic' },
      { href: '/notifications', icon: '📢', label: 'Notifications', roles: ['school_admin'] },
      { href: '/inbox', icon: '🔔', label: 'My notifications', roles: ['school_admin', 'teacher', 'bursar', 'nurse'] },
      { href: '/account', icon: '👤', label: 'My account', roles: ['school_admin', 'teacher', 'bursar', 'nurse'] },
      { href: '/analytics', icon: '📈', label: 'Analytics', roles: ['school_admin'] },
    ],
  },
  {
    heading: 'Administration',
    items: [
      { href: '/school-profile', icon: '🏫', label: 'School profile', roles: ['school_admin'] },
      { href: '/settings', icon: '🛠️', label: 'Module settings', roles: ['school_admin'] },
      { href: '/admin-tools', icon: '🧰', label: 'Admin tools', roles: ['school_admin'] },
      { href: '/records', icon: '🗃️', label: 'Record requests', roles: ['school_admin'] },
      { href: '/users', icon: '👥', label: 'Users & accounts', roles: ['school_admin'] },
      { href: '/admissions/settings', icon: '📨', label: 'Admissions settings', roles: ['school_admin'] },
      { href: '/payment-gateways', icon: '🏦', label: 'Online payments', roles: ['school_admin'] },
      { href: '/ai-features', icon: '🤖', label: 'AI features', roles: ['school_admin'] },
      { href: '/security', icon: '🔐', label: 'Security', roles: ['school_admin'] },
      { href: '/director', icon: '👑', label: 'Director console', roles: ['school_admin'], className: 'font-semibold' },
    ],
  },
];

interface Me {
  name: string;
  role: Role | null;
  is_platform_operator: boolean;
  school: { id: number; name: string; subdomain: string; status: string } | null;
}

/** The role cookie is set non-httpOnly on purpose, for exactly this. */
export function roleFromCookie(): Role | null {
  if (typeof document === 'undefined') return null;

  const match = document.cookie.match(/(?:^|;\s*)sp_role=([^;]+)/);

  return match ? (decodeURIComponent(match[1]) as Role) : null;
}

export default function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const [me, setMe] = useState<Me | null>(null);
  // Read synchronously from the cookie so the nav renders correctly on the
  // first paint rather than flashing the wrong set of links.
  const [role, setRole] = useState<Role | null>(roleFromCookie);
  const [leaving, setLeaving] = useState(false);
  // Modules SchoolPilot has switched off for this school. Their endpoints
  // already refuse; this just stops the menu offering them.
  const [modulesOff, setModulesOff] = useState<string[]>([]);
  // Operator: design requests waiting for SchoolPilot (new or sent back).
  const [badges, setBadges] = useState<Record<string, number>>({});

  useEffect(() => {
    fetchApi<Me>('/user')
      .then((user) => {
        setMe(user);
        if (user.role) setRole(user.role);
      })
      .catch(() => {
        // The chrome must still render if this fails; it degrades to the role
        // cookie and no school name, rather than an empty sidebar.
      });

    fetchApi<{ modules: { key: string; enabled: boolean }[] }>('/school/features')
      .then((res) => setModulesOff(res.modules.filter((m) => !m.enabled).map((m) => m.key)))
      // No school (an operator on their console): nothing to hide.
      .catch(() => undefined);

    if (roleFromCookie() === 'super_admin') {
      fetchApi<{ counts: Record<string, number> }>('/platform/report-card-requests?status=open')
        .then((res) => setBadges({ rc_requests: (res.counts?.submitted ?? 0) + (res.counts?.changes_requested ?? 0) }))
        .catch(() => undefined);
    }
  }, []);

  /** A platform operator who has stepped into a school steps back out. */
  async function leaveSchool() {
    setLeaving(true);

    try {
      await viewSchool(null);
      router.push('/super-admin');
      router.refresh();
    } finally {
      setLeaving(false);
    }
  }

  const inspecting = me?.is_platform_operator && me.school !== null;

  /*
   * The one link that owns this page: the longest href the path sits under.
   * A plain prefix test lit up several at once — /super-admin/settings is under
   * /super-admin — and "/" is a prefix of every route, so it matches only
   * exactly.
   */
  const activeHref = SECTIONS.flatMap((section) => section.items)
    .map((item) => item.href)
    .filter((href) =>
      href === '/' ? pathname === '/' : pathname === href || pathname.startsWith(`${href}/`),
    )
    .sort((a, b) => b.length - a.length)[0];

  return (
    <aside className="flex min-h-screen w-64 shrink-0 print:hidden flex-col justify-between bg-brand p-4 text-white/75">
      <div>
        <div className="mb-6 flex items-center space-x-3 px-2">
          <Image src="/brand/mark-white.png" alt="" width={32} height={32} className="h-8 w-8" priority />
          <div>
            <h1 className="font-headline font-bold leading-none text-white">SchoolPilot</h1>
            <span className="text-xs text-white/60">K-12 Management</span>
          </div>
        </div>

        {/*
          A platform operator working inside someone else's school is told so,
          plainly and at all times, with one click out. Without this the console
          and a school's own screens are indistinguishable, which is how an
          operator ends up changing the wrong school's data.
        */}
        {inspecting && (
          <div className="mb-4 rounded-lg border border-accent/30 bg-white/10 p-3">
            <p className="text-[0.65rem] font-semibold uppercase tracking-wide text-accent">
              Viewing as operator
            </p>
            <p className="mt-0.5 text-sm font-semibold text-white">{me?.school?.name}</p>
            <button
              onClick={leaveSchool}
              disabled={leaving}
              className="mt-2 text-xs font-medium text-white/80 underline hover:text-white disabled:opacity-60"
            >
              {leaving ? 'Leaving…' : 'Leave this school'}
            </button>
          </div>
        )}

        <nav className="space-y-4 text-sm font-medium">
          {SECTIONS.map((section, i) => {
            const items = section.items.filter((item) => {
              if (item.module && modulesOff.includes(item.module)) return false;
              if (!item.roles) return true;
              if (!role) return false;

              /*
               * An operator always keeps their own console, and gains the
               * school's screens while inside one. The previous version swapped
               * the two sets, so stepping into a school hid the console — the
               * way back out was a link the operator could no longer see.
               */
              if (role === 'super_admin') {
                return (
                  item.roles.includes('super_admin') ||
                  (inspecting && item.roles.includes('school_admin'))
                );
              }

              return item.roles.includes(role);
            });

            if (items.length === 0) return null;

            return (
              <div key={section.heading ?? i} className="space-y-1">
                {section.heading && (
                  <p className="px-3 pb-1 text-[0.65rem] font-semibold uppercase tracking-wider text-white/40">
                    {section.heading}
                  </p>
                )}
                {items.map((item) => {
                  const active = item.href === activeHref;

                  return (
                    <Link
                      key={item.href}
                      href={item.href}
                      aria-current={active ? 'page' : undefined}
                      className={`flex items-center space-x-3 rounded-lg px-3 py-2.5 transition ${
                        active
                          ? 'bg-accent font-semibold text-accent-ink'
                          : 'hover:bg-white/10 hover:text-white'
                      } ${item.className ?? ''}`}
                    >
                      <span aria-hidden="true">{item.icon}</span>
                      <span>{item.label}</span>
                      {item.badge && (badges[item.badge] ?? 0) > 0 && (
                        <span className="ml-auto rounded-full bg-accent px-2 text-xs font-bold text-accent-ink" aria-label={`${badges[item.badge]} waiting`}>
                          {badges[item.badge]}
                        </span>
                      )}
                    </Link>
                  );
                })}
              </div>
            );
          })}
        </nav>
      </div>

      <div className="mt-6 border-t border-white/15 px-2 pt-4 text-xs text-white/60">
        {me ? (
          <>
            <p className="font-semibold text-white">{me.name}</p>
            {me.school ? (
              <p className="truncate" title={`${me.school.subdomain}.schoolpilot.org.ng`}>
                {me.school.name}
              </p>
            ) : (
              <p>SchoolPilot platform</p>
            )}
          </>
        ) : (
          <p className="text-white/40">Loading…</p>
        )}

        <form action={logout} className="mt-3">
          <button type="submit" className="font-medium text-white/70 underline hover:text-white">
            Sign out
          </button>
        </form>
      </div>
    </aside>
  );
}
