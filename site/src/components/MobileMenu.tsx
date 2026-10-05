"use client";

import Link from "next/link";
import { useState } from "react";
import { usePathname } from "next/navigation";
import { nav } from "@/lib/site";
import { PrimaryCta, SecondaryCta } from "./Cta";

export function MobileMenu() {
  const pathname = usePathname();
  // Open for the page it was opened on; navigating closes it.
  const [openOn, setOpenOn] = useState<string | null>(null);
  const open = openOn === pathname;
  const setOpen = (fn: (v: boolean) => boolean) => setOpenOn(fn(open) ? pathname : null);

  return (
    <div className="lg:hidden">
      <button
        type="button"
        className="flex h-11 w-11 items-center justify-center rounded-lg text-brand"
        aria-expanded={open}
        aria-controls="mobile-menu"
        aria-label={open ? "Close menu" : "Open menu"}
        onClick={() => setOpen((v) => !v)}
      >
        <svg aria-hidden="true" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
          {open ? <path d="M6 6l12 12M18 6L6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
        </svg>
      </button>
      {open && (
        <div id="mobile-menu" className="absolute inset-x-0 top-16 border-b border-line-soft bg-canvas shadow-lg">
          <nav aria-label="Mobile" className="container-site py-4">
            <ul className="flex flex-col text-lg font-medium text-brand">
              {nav.map((item) => (
                <li key={item.href}>
                  <Link href={item.href} className="block py-3">
                    {item.label}
                  </Link>
                </li>
              ))}
              <li>
                <Link href="/schools/" className="block py-3">
                  Login to your school
                </Link>
              </li>
            </ul>
            <div className="mt-4 flex flex-col gap-3">
              <PrimaryCta where="nav" className="w-full" />
              <SecondaryCta where="nav" className="w-full" />
            </div>
          </nav>
        </div>
      )}
    </div>
  );
}
