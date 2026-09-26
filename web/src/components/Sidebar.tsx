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

type Role = 'super_admin' | 'school_admin' | 'teacher' | 'nurse' | 'student' | 'parent';

type Item = {
  href: string;
  icon: string;
  label: string;
  /** Roles that see this link. Absent means every signed-in role. */
  roles?: Role[];
  /** An optional module SchoolPilot can switch off per school (SchoolFeatures). */
  module?: string;
  className?: string;
};

const SECTIONS: { heading?: string; items: Item[] }[] = [
  /*
   * The operator's own menu (docs/super-admin-menu.md). First, so it stays on
   * top while an operator has a school open and that school's menu appears
   * beneath it.
   */
  {
    heading: 'Operator',
    items: [
      { href: '/super-admin', icon: '🛡️', label: 'Schools', roles: ['super_admin'], className: 'font-semibold' },
      { href: '/super-admin/migrations', icon: '📥', label: 'Onboarding & migrations', roles: ['super_admin'] },
      { href: '/super-admin/result-pins', icon: '🎟️', label: 'Result PINs', roles: ['super_admin'] },
      { href: '/super-admin/announcements', icon: '📣', label: 'Announcements', roles: ['super_admin'] },
    ],
  },
  {
    heading: 'Platform',
    items: [
      { href: '/super-admin/ai', icon: '🤖', label: 'AI service', roles: ['super_admin'] },
      { href: '/super-admin/messaging', icon: '✉️', label: 'Messaging', roles: ['super_admin'] },
      { href: '/super-admin/security', icon: '🔐', label: 'Security', roles: ['super_admin'] },
      { href: '/super-admin/audit-log', icon: '📜', label: 'Audit log', roles: ['super_admin'] },
      { href: '/super-admin/settings', icon: '🔧', label: 'Platform settings', roles: ['super_admin'] },
    ],
  },
  {
    items: [
      { href: '/', icon: '📊', label: 'Dashboard', roles: ['school_admin', 'teacher'] },
      { href: '/setup-wizard', icon: '⚙️', label: 'Setup checklist', roles: ['school_admin'] },
    ],
  },
  {
    heading: 'People',
    items: [
      { href: '/students', icon: '🎓', label: 'Students', roles: ['school_admin', 'teacher'] },
      { href: '/staff', icon: '👩‍🏫', label: 'Staff & leave', roles: ['school_admin'] },
      { href: '/id-cards', icon: '🪪', label: 'ID cards', roles: ['school_admin'] },
    ],
  },
  {
    heading: 'Academics',
    items: [
      { href: '/subjects', icon: '📘', label: 'Subjects', roles: ['school_admin'] },
      { href: '/timetable', icon: '🗓️', label: 'Timetable', roles: ['school_admin', 'teacher'] },
      { href: '/exam-timetable', icon: '🗒️', label: 'Exam timetable', roles: ['school_admin'] },
      { href: '/attendance', icon: '⏱️', label: 'Attendance', roles: ['school_admin', 'teacher'] },
      { href: '/results', icon: '📝', label: 'Results & broadsheet', roles: ['school_admin', 'teacher'] },
      { href: '/cbt-setup', icon: '💻', label: 'CBT exams', roles: ['school_admin', 'teacher'], module: 'cbt' },
      { href: '/live-class', icon: '🎥', label: 'Virtual classes', roles: ['school_admin', 'teacher', 'student', 'parent'], module: 'live_classes' },
    ],
  },
  {
    heading: 'Operations',
    items: [
      { href: '/finance', icon: '💳', label: 'Fees & finance', roles: ['school_admin'] },
      { href: '/result-pins', icon: '🎟️', label: 'Result PINs', roles: ['school_admin'] },
      { href: '/library', icon: '📚', label: 'Library', roles: ['school_admin', 'teacher', 'student', 'parent'], module: 'library' },
      { href: '/hostel', icon: '🛏️', label: 'Hostel', roles: ['school_admin', 'teacher'], module: 'hostel' },
      { href: '/bus-tracking', icon: '🚌', label: 'Transport', roles: ['school_admin', 'teacher'], module: 'transport' },
      { href: '/health', icon: '🩺', label: 'Clinic log', roles: ['school_admin', 'nurse'], module: 'clinic' },
      { href: '/notifications', icon: '📢', label: 'Notifications', roles: ['school_admin'] },
      { href: '/analytics', icon: '📈', label: 'Analytics', roles: ['school_admin'] },
    ],
  },
  {
    heading: 'Administration',
    items: [
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
function roleFromCookie(): Role | null {
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
    <aside className="flex min-h-screen w-64 shrink-0 flex-col justify-between bg-brand p-4 text-white/75">
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
