"use client";

import Link from "next/link";
import { useRef, useState, type KeyboardEvent, type ReactNode } from "react";
import type { Role } from "@/content/home";

/**
 * Homepage role tabs. Screenshots are rendered on the server and passed in as
 * `shots`, so this client component only switches which one shows.
 * Keyboard: arrow keys move between tabs (WAI-ARIA tabs pattern).
 */
export function RoleTabs({ roles, shots }: { roles: Role[]; shots: Record<string, ReactNode> }) {
  const [active, setActive] = useState(0);
  const tabs = useRef<(HTMLButtonElement | null)[]>([]);

  const onKey = (e: KeyboardEvent) => {
    const step = e.key === "ArrowRight" ? 1 : e.key === "ArrowLeft" ? -1 : 0;
    if (!step) return;
    e.preventDefault();
    const next = (active + step + roles.length) % roles.length;
    setActive(next);
    tabs.current[next]?.focus();
  };

  return (
    <div>
      <div role="tablist" aria-label="Who SchoolPilot is for" className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-2" onKeyDown={onKey}>
        {roles.map((role, i) => (
          <button
            key={role.id}
            ref={(el) => {
              tabs.current[i] = el;
            }}
            role="tab"
            id={`tab-${role.id}`}
            aria-selected={i === active}
            aria-controls={`panel-${role.id}`}
            tabIndex={i === active ? 0 : -1}
            onClick={() => setActive(i)}
            className={`min-h-11 shrink-0 rounded-full border px-5 text-[0.95rem] font-semibold ${
              i === active ? "border-brand bg-brand text-white" : "border-line bg-surface text-brand hover:border-brand"
            }`}
          >
            {role.label}
          </button>
        ))}
      </div>

      {roles.map((role, i) => (
        <div
          key={role.id}
          role="tabpanel"
          id={`panel-${role.id}`}
          aria-labelledby={`tab-${role.id}`}
          hidden={i !== active}
          className="mt-6 grid items-center gap-8 md:grid-cols-2"
        >
          <div className="order-2 md:order-1">{shots[role.id]}</div>
          <div className="order-1 md:order-2">
            <h3 className="text-2xl font-bold md:text-3xl">{role.promise}</h3>
            <ul className="mt-5 space-y-3">
              {role.outcomes.map((o) => (
                <li key={o} className="flex gap-3">
                  <span aria-hidden="true" className="mt-2 h-2 w-2 shrink-0 rounded-full bg-accent" />
                  <span>{o}</span>
                </li>
              ))}
            </ul>
            {role.href && (
              <Link href={role.href} className="mt-6 inline-block font-semibold text-brand underline underline-offset-4">
                More for {role.label.toLowerCase()}s →
              </Link>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}
