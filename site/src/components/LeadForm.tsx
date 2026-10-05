"use client";

import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from "react";
import { getAttribution, track } from "@/lib/attribution";
import { hasDemoSchool, hasWhatsApp, site } from "@/lib/site";

type Intent = "demo" | "start" | "contact";
type Errors = Record<string, string>;

const ROLES = [
  ["proprietor", "Proprietor / Director"],
  ["principal", "Principal / Head teacher"],
  ["admin", "School administrator"],
  ["bursar", "Bursar / Accountant"],
  ["ict", "ICT / Exams officer"],
  ["teacher", "Teacher"],
  ["other", "Other"],
] as const;

const BANDS = [
  ["1-50", "Up to 50 students"],
  ["51-200", "51–200"],
  ["201-500", "201–500"],
  ["501-1000", "501–1,000"],
  ["1001-3000", "1,001–3,000"],
  ["3000+", "More than 3,000"],
] as const;

const SECTIONS = [
  ["nursery", "Nursery"],
  ["primary", "Primary"],
  ["jss", "JSS"],
  ["ss", "SS"],
] as const;

const responseMinutes = site.responseMinutes;

/** What the visitor is told to expect: only the promise the team can keep. */
function responsePromise() {
  if (responseMinutes > 0) {
    return `We'll message you within ${responseMinutes} minutes during working hours (${site.responseHours}).`;
  }
  return "We'll be in touch by the next working day.";
}

const inputClass =
  "mt-1.5 block w-full rounded-lg border border-line bg-surface px-3.5 py-3 text-base text-ink placeholder:text-ink-muted/70 focus:border-brand focus:outline-none aria-[invalid=true]:border-negative";

function Field({ id, label, error, hint, required, children }: { id: string; label: string; error?: string; hint?: string; required?: boolean; children: ReactNode }) {
  return (
    <div>
      <label htmlFor={id} className="block font-semibold text-brand">
        {label}
        {!required && <span className="font-normal text-ink-muted"> (optional)</span>}
      </label>
      {hint && <p id={`${id}-hint`} className="mt-0.5 text-sm text-ink-muted">{hint}</p>}
      {children}
      {error && (
        <p id={`${id}-error`} className="mt-1.5 text-sm font-medium text-negative">
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * Demo, start and contact forms (Landing PRD §11.3–11.4). Five required
 * fields plus consent, Nigerian phone formats, inline errors that never clear
 * what was typed, and a WhatsApp fallback if the network fails.
 */
export function LeadForm({ intent, submitLabel }: { intent: Intent; submitLabel: string }) {
  const uid = useId();
  const id = (name: string) => `${uid}-${name}`;
  const formRef = useRef<HTMLFormElement>(null);
  const summaryRef = useRef<HTMLDivElement>(null);
  const started = useRef(false);

  const [errors, setErrors] = useState<Errors>({});
  const [status, setStatus] = useState<"idle" | "sending" | "done" | "offline">("idle");
  const [firstName, setFirstName] = useState("");
  const [offlineText, setOfflineText] = useState("");

  // Cloudflare Turnstile, on form pages only, and only when configured.
  useEffect(() => {
    if (!site.turnstileSiteKey || document.getElementById("cf-turnstile-script")) return;
    const s = document.createElement("script");
    s.id = "cf-turnstile-script";
    s.src = "https://challenges.cloudflare.com/turnstile/v0/api.js";
    s.async = true;
    s.defer = true;
    document.head.appendChild(s);
  }, []);

  const needsBand = intent !== "contact";

  function validate(data: FormData): Errors {
    const e: Errors = {};
    if (String(data.get("full_name") ?? "").trim().length < 2) e.full_name = "Enter your name.";
    const phone = String(data.get("phone") ?? "").replace(/[^\d+]/g, "");
    if (!/^(\+?234|0)[789][01]\d{8}$/.test(phone)) e.phone = "Enter a Nigerian phone number, e.g. 0803 123 4567.";
    if (String(data.get("school_name") ?? "").trim().length < 2) e.school_name = "Enter your school's name.";
    if (!data.get("role")) e.role = "Choose your role.";
    if (needsBand && !data.get("student_band")) e.student_band = "Choose roughly how many students you have.";
    const email = String(data.get("email") ?? "").trim();
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) e.email = "Check the email address, or leave it blank.";
    const sub = String(data.get("preferred_subdomain") ?? "").trim();
    if (sub && !/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(sub)) e.preferred_subdomain = "Use lowercase letters, numbers and hyphens only.";
    if (!data.get("consent")) e.consent = "Please tick the box so we can contact you about this request.";
    return e;
  }

  function showErrors(e: Errors) {
    setErrors(e);
    track("form_error", { form: intent, field: Object.keys(e)[0] ?? "", error_type: "validation" });
    requestAnimationFrame(() => summaryRef.current?.focus());
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const found = validate(data);
    if (Object.keys(found).length) return showErrors(found);

    setErrors({});
    setStatus("sending");

    const attribution = getAttribution();
    const payload = {
      intent,
      full_name: String(data.get("full_name")).trim(),
      phone: String(data.get("phone")).trim(),
      school_name: String(data.get("school_name")).trim(),
      role: data.get("role"),
      student_band: data.get("student_band") || null,
      sections: data.getAll("sections"),
      email: String(data.get("email") ?? "").trim() || null,
      preferred_contact: data.get("preferred_contact") || "whatsapp",
      current_method: data.get("current_method") || null,
      message: String(data.get("message") ?? "").trim() || null,
      preferred_subdomain: String(data.get("preferred_subdomain") ?? "").trim() || null,
      migrate_what: String(data.get("migrate_what") ?? "").trim() || null,
      consent: true,
      website: data.get("website"),
      turnstile_token: data.get("cf-turnstile-response") || null,
      ...attribution,
    };

    try {
      const res = await fetch(`${site.apiBase}/public/leads`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(payload),
      });

      if (res.status === 201) {
        const body = await res.json().catch(() => ({}));
        setFirstName(body.first_name ?? payload.full_name.split(" ")[0]);
        setStatus("done");
        track("form_submit", { form: intent });
        window.scrollTo({ top: 0, behavior: "smooth" });
        return;
      }

      if (res.status === 422) {
        const body = await res.json().catch(() => ({}));
        const server: Errors = {};
        for (const [k, v] of Object.entries((body.errors ?? {}) as Record<string, string[]>)) {
          server[k.split(".")[0]] = v[0];
        }
        setStatus("idle");
        return showErrors(Object.keys(server).length ? server : { form: "Please check the form and try again." });
      }

      if (res.status === 429) {
        setStatus("idle");
        return showErrors({ form: "Too many attempts from this connection. Please wait a minute and try again." });
      }

      throw new Error(`HTTP ${res.status}`);
    } catch {
      // Keep everything they typed, and give them a way to reach us anyway.
      setOfflineText(
        `Hello SchoolPilot, I tried to send a ${intent} request from your website. ` +
          `Name: ${payload.full_name}. School: ${payload.school_name}. Phone: ${payload.phone}.`,
      );
      setStatus("offline");
      track("form_error", { form: intent, field: "", error_type: "network" });
    }
  }

  if (status === "done") {
    return (
      <div role="status" className="rounded-2xl border border-line-soft bg-surface p-6 md:p-8">
        <h2 className="text-2xl font-bold">Thanks, {firstName}. We&apos;ve received your request.</h2>
        <p className="mt-3 text-lg text-ink-muted">{responsePromise()}</p>
        {hasDemoSchool && (
          <p className="mt-5">
            While you wait:{" "}
            <a href={site.demoSchoolUrl} className="font-semibold text-brand underline underline-offset-4">
              try the demo school →
            </a>
          </p>
        )}
      </div>
    );
  }

  const errorList = Object.entries(errors);
  const describedBy = (name: string, hint = false) =>
    [hint ? `${id(name)}-hint` : "", errors[name] ? `${id(name)}-error` : ""].filter(Boolean).join(" ") || undefined;

  return (
    <form
      ref={formRef}
      noValidate
      onSubmit={onSubmit}
      onFocus={() => {
        if (!started.current) {
          started.current = true;
          track("form_start", { form: intent });
        }
      }}
      className="space-y-5 rounded-2xl border border-line-soft bg-surface p-5 md:p-8"
    >
      <div ref={summaryRef} tabIndex={-1} aria-live="assertive" className="focus:outline-none">
        {errorList.length > 0 && (
          <div className="rounded-lg border border-negative/40 bg-negative/5 p-4 text-sm text-negative">
            <p className="font-semibold">Please fix {errorList.length === 1 ? "this" : "these"} and send again:</p>
            <ul className="mt-1 list-disc pl-5">
              {errorList.map(([k, v]) => (
                <li key={k}>{v}</li>
              ))}
            </ul>
          </div>
        )}
        {status === "offline" && (
          <div className="rounded-lg border border-negative/40 bg-negative/5 p-4 text-sm">
            <p className="font-semibold text-negative">We couldn&apos;t send this. Check your connection and try again.</p>
            {hasWhatsApp && (
              <p className="mt-1 text-ink">
                Or{" "}
                <a
                  className="font-semibold text-brand underline underline-offset-4"
                  href={`https://wa.me/${site.whatsappNumber}?text=${encodeURIComponent(offlineText)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  send your details to us on WhatsApp
                </a>
                .
              </p>
            )}
          </div>
        )}
      </div>

      <Field id={id("full_name")} label="Your full name" error={errors.full_name} required>
        <input id={id("full_name")} name="full_name" autoComplete="name" className={inputClass} aria-invalid={!!errors.full_name} aria-describedby={describedBy("full_name")} />
      </Field>

      <Field id={id("phone")} label="Phone (WhatsApp)" hint="e.g. 0803 123 4567" error={errors.phone} required>
        <input id={id("phone")} name="phone" type="tel" inputMode="tel" autoComplete="tel" className={inputClass} aria-invalid={!!errors.phone} aria-describedby={describedBy("phone", true)} />
      </Field>

      <Field id={id("school_name")} label="School name" error={errors.school_name} required>
        <input id={id("school_name")} name="school_name" autoComplete="organization" className={inputClass} aria-invalid={!!errors.school_name} aria-describedby={describedBy("school_name")} />
      </Field>

      <div className="grid gap-5 md:grid-cols-2">
        <Field id={id("role")} label="Your role" error={errors.role} required>
          <select id={id("role")} name="role" defaultValue="" className={inputClass} aria-invalid={!!errors.role} aria-describedby={describedBy("role")}>
            <option value="" disabled>Choose…</option>
            {ROLES.map(([v, l]) => (
              <option key={v} value={v}>{l}</option>
            ))}
          </select>
        </Field>
        {needsBand && (
          <Field id={id("student_band")} label="Number of students" error={errors.student_band} required>
            <select id={id("student_band")} name="student_band" defaultValue="" className={inputClass} aria-invalid={!!errors.student_band} aria-describedby={describedBy("student_band")}>
              <option value="" disabled>Choose…</option>
              {BANDS.map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </Field>
        )}
      </div>

      {intent !== "contact" && (
        <fieldset>
          <legend className="font-semibold text-brand">
            Sections <span className="font-normal text-ink-muted">(optional)</span>
          </legend>
          <div className="mt-2 flex flex-wrap gap-2">
            {SECTIONS.map(([v, l]) => (
              <label key={v} className="flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-line px-4 has-[:checked]:border-brand has-[:checked]:bg-brand has-[:checked]:text-white">
                <input type="checkbox" name="sections" value={v} className="sr-only" />
                {l}
              </label>
            ))}
          </div>
        </fieldset>
      )}

      {intent === "start" && (
        <>
          <Field id={id("preferred_subdomain")} label="Preferred SchoolPilot address" hint={`Your school's link will be <address>.${site.tenantDomain}`} error={errors.preferred_subdomain}>
            <div className="mt-1.5 flex items-stretch overflow-hidden rounded-lg border border-line bg-surface focus-within:border-brand">
              <input id={id("preferred_subdomain")} name="preferred_subdomain" placeholder="brightstars" className="w-0 min-w-0 flex-1 px-3.5 py-3 text-base focus:outline-none" aria-invalid={!!errors.preferred_subdomain} aria-describedby={describedBy("preferred_subdomain", true)} />
              <span className="flex shrink-0 items-center bg-surface-sunken px-2 text-xs text-ink-muted sm:px-3 sm:text-sm">.{site.tenantDomain}</span>
            </div>
          </Field>
          <Field id={id("migrate_what")} label="What should we move in for you?" hint="e.g. students and guardians, last session's results, fee balances">
            <textarea id={id("migrate_what")} name="migrate_what" rows={3} className={inputClass} aria-describedby={describedBy("migrate_what", true)} />
          </Field>
        </>
      )}

      <Field id={id("email")} label="Email" error={errors.email}>
        <input id={id("email")} name="email" type="email" autoComplete="email" className={inputClass} aria-invalid={!!errors.email} aria-describedby={describedBy("email")} />
      </Field>

      <div className="grid gap-5 md:grid-cols-2">
        <Field id={id("preferred_contact")} label="How should we contact you?">
          <select id={id("preferred_contact")} name="preferred_contact" defaultValue="whatsapp" className={inputClass}>
            <option value="whatsapp">WhatsApp</option>
            <option value="call">Phone call</option>
            <option value="email">Email</option>
          </select>
        </Field>
        <Field id={id("current_method")} label="What do you use today?">
          <select id={id("current_method")} name="current_method" defaultValue="" className={inputClass}>
            <option value="">Choose…</option>
            <option value="paper">Paper</option>
            <option value="excel">Excel</option>
            <option value="portal">Another portal</option>
            <option value="mixed">A mix</option>
          </select>
        </Field>
      </div>

      <Field id={id("message")} label={intent === "contact" ? "Your message" : "Anything we should know?"}>
        <textarea id={id("message")} name="message" rows={4} className={inputClass} />
      </Field>

      {/* Honeypot: hidden from people and screen readers; bots fill it. */}
      <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
        <label>
          Website
          <input name="website" tabIndex={-1} autoComplete="off" />
        </label>
      </div>

      <div>
        <label className="flex cursor-pointer items-start gap-3">
          <input
            type="checkbox"
            name="consent"
            className="mt-1 h-5 w-5 shrink-0 accent-[#002447]"
            aria-invalid={!!errors.consent}
            aria-describedby={errors.consent ? `${id("consent")}-error` : undefined}
          />
          <span>
            SchoolPilot may contact me about this request. See our{" "}
            <a href="/privacy/" className="font-semibold text-brand underline underline-offset-4">privacy notice</a>.
          </span>
        </label>
        {errors.consent && (
          <p id={`${id("consent")}-error`} className="mt-1.5 text-sm font-medium text-negative">
            {errors.consent}
          </p>
        )}
      </div>

      {site.turnstileSiteKey && <div className="cf-turnstile" data-sitekey={site.turnstileSiteKey} />}

      <button type="submit" disabled={status === "sending"} className="btn btn-primary w-full text-lg disabled:opacity-60 md:w-auto">
        {status === "sending" ? "Sending…" : submitLabel}
      </button>
    </form>
  );
}
