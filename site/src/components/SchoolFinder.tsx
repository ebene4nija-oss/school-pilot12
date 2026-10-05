"use client";

import { useEffect, useState, type FormEvent } from "react";
import { site } from "@/lib/site";
import { track } from "@/lib/attribution";

const KEY = "sp-last-school";

/**
 * Find-your-school (Landing PRD §5.1). Exact address only: the API answers
 * yes or no for the address typed, never suggestions, so it cannot be used to
 * list SchoolPilot's schools. The last school found is remembered on this
 * device for a one-tap return.
 */
export function SchoolFinder({ purpose }: { purpose: "login" | "results" }) {
  const [slug, setSlug] = useState("");
  const [saved, setSaved] = useState<string | null>(null);
  const [state, setState] = useState<"idle" | "checking" | "missing" | "error">("idle");

  useEffect(() => {
    try {
      const last = window.localStorage.getItem(KEY);
      // Reading storage only exists in the browser, so this runs after mount.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      if (last) setSaved(last);
    } catch {
      // storage unavailable
    }
  }, []);

  // Results live behind the parent's sign-in (Results → enter PIN), so both
  // purposes land on the school's login page.
  const destination = (s: string) => `https://${s}.${site.tenantDomain}/login`;

  async function go(e: FormEvent) {
    e.preventDefault();
    const clean = slug.trim().toLowerCase().replace(/^https?:\/\//, "").split(".")[0];
    if (!/^[a-z0-9-]{2,63}$/.test(clean)) {
      setState("missing");
      return;
    }

    setState("checking");
    try {
      const res = await fetch(`${site.apiBase}/public/schools/resolve?slug=${encodeURIComponent(clean)}`, {
        headers: { Accept: "application/json" },
      });
      const body = await res.json();
      if (!res.ok || !body.exists) {
        setState("missing");
        return;
      }
      try {
        window.localStorage.setItem(KEY, clean);
      } catch {
        // fine without it
      }
      track("cta_click", { cta: "login", location: "page" });
      window.location.href = destination(clean);
    } catch {
      setState("error");
    }
  }

  return (
    <div className="rounded-2xl border border-line-soft bg-surface p-5 md:p-8">
      {saved && (
        <a href={destination(saved)} className="btn btn-primary mb-6 w-full">
          Continue to {saved}.{site.tenantDomain}
        </a>
      )}
      <form onSubmit={go} noValidate>
        <label htmlFor="school-slug" className="block font-semibold text-brand">
          Your school&apos;s SchoolPilot address
        </label>
        <p id="school-slug-hint" className="mt-0.5 text-sm text-ink-muted">
          Ask your school if you don&apos;t know it. It&apos;s on your login details.
        </p>
        <div className="mt-2 flex items-stretch overflow-hidden rounded-lg border border-line focus-within:border-brand">
          <input
            id="school-slug"
            value={slug}
            onChange={(e) => {
              setSlug(e.target.value);
              setState("idle");
            }}
            placeholder="yourschool"
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
            className="w-0 min-w-0 flex-1 px-3.5 py-3 text-base focus:outline-none"
            aria-describedby="school-slug-hint school-slug-status"
            aria-invalid={state === "missing"}
          />
          <span className="flex shrink-0 items-center bg-surface-sunken px-2 text-xs text-ink-muted sm:px-3 sm:text-sm">.{site.tenantDomain}</span>
        </div>
        <p id="school-slug-status" aria-live="polite" className="mt-2 min-h-6 text-sm">
          {state === "missing" && (
            <span className="font-medium text-negative">
              We couldn&apos;t find that school address. Check the spelling, or ask your school for its SchoolPilot link.
            </span>
          )}
          {state === "error" && <span className="font-medium text-negative">We couldn&apos;t check just now. Check your connection and try again.</span>}
        </p>
        <button type="submit" disabled={state === "checking"} className="btn btn-secondary mt-2 w-full disabled:opacity-60">
          {state === "checking" ? "Checking…" : purpose === "results" ? "Go to my school" : "Go to login"}
        </button>
      </form>
    </div>
  );
}
