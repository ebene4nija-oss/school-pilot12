"use client";

import { useEffect, useId, useState, type FormEvent } from "react";
import { track } from "@/lib/attribution";
import { site } from "@/lib/site";

type Errors = Record<string, string>;

const inputClass =
  "mt-1.5 block w-full rounded-lg border border-line bg-surface px-3.5 py-3 text-base text-ink placeholder:text-ink-muted/70 focus:border-brand focus:outline-none aria-[invalid=true]:border-negative";

/**
 * The partner application. Name, email and phone are required, plus consent.
 * An application only asks to join: nothing is approved or promised here, and
 * the form says nothing about rates beyond what the page already states.
 */
export function PartnerForm() {
  const uid = useId();
  const id = (name: string) => `${uid}-${name}`;
  const [errors, setErrors] = useState<Errors>({});
  const [status, setStatus] = useState<"idle" | "sending" | "done" | "offline">("idle");
  const [firstName, setFirstName] = useState("");

  useEffect(() => {
    if (!site.turnstileSiteKey || document.getElementById("cf-turnstile-script")) return;
    const s = document.createElement("script");
    s.id = "cf-turnstile-script";
    s.src = "https://challenges.cloudflare.com/turnstile/v0/api.js";
    s.async = true;
    s.defer = true;
    document.head.appendChild(s);
  }, []);

  function validate(data: FormData): Errors {
    const e: Errors = {};
    if (String(data.get("name") ?? "").trim().length < 2) e.name = "Enter your name.";
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(data.get("email") ?? "").trim())) e.email = "Enter a valid email address.";
    const phone = String(data.get("phone") ?? "").replace(/[^\d+]/g, "");
    if (!/^(\+?234|0)[789][01]\d{8}$/.test(phone)) e.phone = "Enter a Nigerian phone number, e.g. 0803 123 4567.";
    if (!data.get("consent")) e.consent = "Please tick the box so we can contact you about this application.";
    return e;
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const found = validate(data);
    setErrors(found);
    if (Object.keys(found).length) return;

    setStatus("sending");

    try {
      const res = await fetch(`${site.apiBase}/public/partners/apply`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          name: String(data.get("name")).trim(),
          email: String(data.get("email")).trim(),
          phone: String(data.get("phone")).trim(),
          pitch: String(data.get("pitch") ?? "").trim() || null,
          consent: true,
          website: data.get("website"),
          turnstile_token: data.get("cf-turnstile-response") || null,
        }),
      });

      if (res.status === 201) {
        const body = await res.json().catch(() => ({}));
        setFirstName(body.first_name ?? String(data.get("name")).split(" ")[0]);
        setStatus("done");
        track("form_submit", { form: "partner" });
        return;
      }

      if (res.status === 422) {
        const body = await res.json().catch(() => ({}));
        const server: Errors = {};
        for (const [k, v] of Object.entries((body.errors ?? {}) as Record<string, string[]>)) server[k.split(".")[0]] = v[0];
        setErrors(Object.keys(server).length ? server : { form: "Please check the form and try again." });
        setStatus("idle");
        return;
      }

      if (res.status === 429) {
        setErrors({ form: "Too many attempts from this connection. Please wait a minute and try again." });
        setStatus("idle");
        return;
      }

      throw new Error(`HTTP ${res.status}`);
    } catch {
      setStatus("offline");
    }
  }

  if (status === "done") {
    return (
      <div role="status" className="rounded-2xl border border-line-soft bg-surface p-6 md:p-8">
        <h2 className="text-2xl font-bold">Thanks, {firstName}. We&apos;ve received your application.</h2>
        <p className="mt-3 text-lg text-ink-muted">We review each application and will contact you by phone or email once it is approved.</p>
      </div>
    );
  }

  const errorList = Object.values(errors);

  return (
    <form noValidate onSubmit={onSubmit} className="space-y-5 rounded-2xl border border-line-soft bg-surface p-5 md:p-8">
      <div aria-live="assertive">
        {errorList.length > 0 && (
          <div className="rounded-lg border border-negative/40 bg-negative/5 p-4 text-sm text-negative">
            <p className="font-semibold">Please fix {errorList.length === 1 ? "this" : "these"} and send again:</p>
            <ul className="mt-1 list-disc pl-5">
              {errorList.map((m) => (
                <li key={m}>{m}</li>
              ))}
            </ul>
          </div>
        )}
        {status === "offline" && (
          <p className="rounded-lg border border-negative/40 bg-negative/5 p-4 text-sm font-semibold text-negative">
            We couldn&apos;t send this. Check your connection and try again.
          </p>
        )}
      </div>

      <div>
        <label htmlFor={id("name")} className="block font-semibold text-brand">Your full name</label>
        <input id={id("name")} name="name" autoComplete="name" className={inputClass} aria-invalid={!!errors.name} />
      </div>

      <div>
        <label htmlFor={id("email")} className="block font-semibold text-brand">Email</label>
        <input id={id("email")} name="email" type="email" autoComplete="email" className={inputClass} aria-invalid={!!errors.email} />
      </div>

      <div>
        <label htmlFor={id("phone")} className="block font-semibold text-brand">Phone (WhatsApp)</label>
        <p className="mt-0.5 text-sm text-ink-muted">e.g. 0803 123 4567</p>
        <input id={id("phone")} name="phone" type="tel" inputMode="tel" autoComplete="tel" className={inputClass} aria-invalid={!!errors.phone} />
      </div>

      <div>
        <label htmlFor={id("pitch")} className="block font-semibold text-brand">
          How would you introduce schools to SchoolPilot? <span className="font-normal text-ink-muted">(optional)</span>
        </label>
        <p className="mt-0.5 text-sm text-ink-muted">For example: you advise schools, sell to them, or run a school association.</p>
        <textarea id={id("pitch")} name="pitch" rows={4} maxLength={1000} className={inputClass} />
      </div>

      {/* Honeypot: hidden from people and screen readers; bots fill it. */}
      <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
        <label>
          Website
          <input name="website" tabIndex={-1} autoComplete="off" />
        </label>
      </div>

      <label className="flex cursor-pointer items-start gap-3">
        <input type="checkbox" name="consent" className="mt-1 h-5 w-5 shrink-0 accent-[#002447]" aria-invalid={!!errors.consent} />
        <span>
          SchoolPilot may contact me about this application. See our{" "}
          <a href="/privacy/" className="font-semibold text-brand underline underline-offset-4">privacy notice</a>.
        </span>
      </label>

      {site.turnstileSiteKey && <div className="cf-turnstile" data-sitekey={site.turnstileSiteKey} />}

      <button type="submit" disabled={status === "sending"} className="btn btn-primary w-full text-lg disabled:opacity-60 md:w-auto">
        {status === "sending" ? "Sending…" : "Apply to become a partner"}
      </button>
    </form>
  );
}
