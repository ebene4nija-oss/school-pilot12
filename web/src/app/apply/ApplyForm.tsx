'use client';

import React, { useState, type FormEvent } from 'react';
import { inputClass } from '@/components/Page';

export interface AdmissionForm {
  school: string;
  session: string | null;
  instructions: string | null;
  sections: Record<string, string>;
  required_documents: Record<string, string[]>;
  privacy: string;
}

/*
 * Posted straight to the API from the parent's browser, not through a server
 * action: in production every school subdomain serves /api/ from Laravel, so
 * the school is identified by the address and the API's per-visitor limits
 * see the parent's own connection. In local development NEXT_PUBLIC_API_URL
 * points at the API and the header names the school.
 */
const PUBLIC_API = (process.env.NEXT_PUBLIC_API_URL ?? '/api/v1').replace(/\/$/, '');

type Errors = Record<string, string>;

async function post(path: string, subdomain: string, body: unknown) {
  const res = await fetch(`${PUBLIC_API}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-School-Subdomain': subdomain },
    body: JSON.stringify(body),
  });
  const json = await res.json().catch(() => ({}));

  return { status: res.status, json };
}

function Field({ label, error, optional, children }: { label: string; error?: string; optional?: boolean; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-ink">
        {label}
        {optional && <span className="font-normal text-ink-muted"> (optional)</span>}
      </span>
      {children}
      {error && <span className="mt-1 block text-sm text-negative">{error}</span>}
    </label>
  );
}

export default function ApplyForm({ form, subdomain, accent }: { form: AdmissionForm; subdomain: string; accent: string }) {
  const sectionKeys = Object.keys(form.sections);
  const [section, setSection] = useState(sectionKeys.length === 1 ? sectionKeys[0] : '');
  const [secondGuardian, setSecondGuardian] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [reference, setReference] = useState<string | null>(null);

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);

    if (!data.get('consent')) {
      setErrors({ consent: 'Please confirm you have read how the school uses these details.' });
      return;
    }

    const guardian = (n: number) => ({
      name: String(data.get(`g${n}_name`) ?? '').trim(),
      phone: String(data.get(`g${n}_phone`) ?? '').trim(),
      email: String(data.get(`g${n}_email`) ?? '').trim() || null,
      relationship: String(data.get(`g${n}_relationship`) ?? '') || null,
    });
    const guardians = [guardian(1), ...(secondGuardian ? [guardian(2)] : [])];

    setBusy(true);
    setErrors({});
    setProblem(null);

    try {
      const { status, json } = await post('/public/admissions/apply', subdomain, {
        section,
        applicant_name: String(data.get('applicant_name') ?? '').trim(),
        date_of_birth: data.get('date_of_birth'),
        gender: data.get('gender'),
        previous_school: String(data.get('previous_school') ?? '').trim() || null,
        previous_class: String(data.get('previous_class') ?? '').trim() || null,
        guardians,
      });

      if (status === 201) {
        setReference(json.reference);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } else if (status === 422 && json.errors) {
        const mapped: Errors = {};
        for (const [key, messages] of Object.entries(json.errors as Record<string, string[]>)) mapped[key] = messages[0];
        setErrors(mapped);
        setProblem('Please check the highlighted answers.');
      } else if (status === 429) {
        setProblem('Too many attempts. Please wait a minute and try again.');
      } else {
        setProblem(json.message ?? 'The application could not be sent. Please try again.');
      }
    } catch {
      setProblem('We could not send the application. Check your connection and try again; nothing you typed has been lost.');
    } finally {
      setBusy(false);
    }
  }

  if (reference) {
    return (
      <div className="mt-6 space-y-6">
        <div role="status" className="rounded-xl border border-line-soft bg-white p-6">
          <h2 className="text-xl font-semibold" style={{ color: accent }}>
            Application received
          </h2>
          <p className="mt-2 text-ink">Your reference is</p>
          <p className="mt-1 font-mono text-2xl font-bold tracking-wider text-ink">{reference}</p>
          <p className="mt-3 text-ink-muted">
            Keep it safe. {form.school} will contact you about the next step. You can check the application with this reference and
            the phone number you gave.
          </p>
        </div>
        <StatusCheck subdomain={subdomain} accent={accent} initialReference={reference} />
      </div>
    );
  }

  const documents = section ? form.required_documents[section] ?? [] : [];

  return (
    <div className="mt-6 space-y-6">
      <form onSubmit={submit} noValidate className="space-y-6 rounded-xl border border-line-soft bg-white p-5 sm:p-6">
        {problem && (
          <p role="alert" className="rounded-lg bg-negative/5 px-4 py-3 text-sm text-negative">
            {problem}
          </p>
        )}

        <fieldset className="space-y-4">
          <legend className="text-base font-semibold text-ink">The child</legend>
          {sectionKeys.length > 1 && (
            <Field label="Applying for" error={errors.section}>
              <select value={section} onChange={(e) => setSection(e.target.value)} className={inputClass} required>
                <option value="" disabled>
                  Choose a section
                </option>
                {sectionKeys.map((key) => (
                  <option key={key} value={key}>
                    {form.sections[key]}
                  </option>
                ))}
              </select>
            </Field>
          )}
          <Field label="Child's full name" error={errors.applicant_name}>
            <input name="applicant_name" autoComplete="off" className={inputClass} required />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Date of birth" error={errors.date_of_birth}>
              <input name="date_of_birth" type="date" className={inputClass} required />
            </Field>
            <Field label="Gender" error={errors.gender}>
              <select name="gender" defaultValue="" className={inputClass} required>
                <option value="" disabled>
                  Choose
                </option>
                <option value="female">Female</option>
                <option value="male">Male</option>
              </select>
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Current or last school" optional error={errors.previous_school}>
              <input name="previous_school" className={inputClass} />
            </Field>
            <Field label="Current class" optional error={errors.previous_class}>
              <input name="previous_class" placeholder="e.g. Primary 5" className={inputClass} />
            </Field>
          </div>
        </fieldset>

        {[1, ...(secondGuardian ? [2] : [])].map((n) => (
          <fieldset key={n} className="space-y-4">
            <legend className="text-base font-semibold text-ink">{n === 1 ? 'Parent or guardian' : 'Second parent or guardian'}</legend>
            <Field label="Full name" error={errors[`guardians.${n - 1}.name`]}>
              <input name={`g${n}_name`} autoComplete={n === 1 ? 'name' : 'off'} className={inputClass} required />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Phone number" error={errors[`guardians.${n - 1}.phone`]}>
                <input name={`g${n}_phone`} type="tel" inputMode="tel" placeholder="0803 123 4567" className={inputClass} required />
              </Field>
              <Field label="Relationship" optional>
                <select name={`g${n}_relationship`} defaultValue="" className={inputClass}>
                  <option value="">Choose</option>
                  <option value="mother">Mother</option>
                  <option value="father">Father</option>
                  <option value="guardian">Guardian</option>
                  <option value="other">Other</option>
                </select>
              </Field>
            </div>
            <Field label="Email" optional error={errors[`guardians.${n - 1}.email`]}>
              <input name={`g${n}_email`} type="email" className={inputClass} />
            </Field>
          </fieldset>
        ))}
        {!secondGuardian && (
          <button type="button" onClick={() => setSecondGuardian(true)} className="text-sm font-semibold underline" style={{ color: accent }}>
            + Add a second parent or guardian
          </button>
        )}

        {documents.length > 0 && (
          <div className="rounded-lg bg-surface-sunken px-4 py-3 text-sm">
            <p className="font-medium text-ink">The school will ask for:</p>
            <ul className="mt-1 list-disc pl-5 text-ink-muted">
              {documents.map((d) => (
                <li key={d}>{d}</li>
              ))}
            </ul>
          </div>
        )}

        <label className="flex items-start gap-3 text-sm">
          <input type="checkbox" name="consent" className="mt-0.5 h-5 w-5 shrink-0" />
          <span className="text-ink-muted">{form.privacy}</span>
        </label>
        {errors.consent && <p className="text-sm text-negative">{errors.consent}</p>}

        <button
          type="submit"
          disabled={busy || !section}
          className="w-full rounded-lg px-5 py-3 text-base font-semibold text-white disabled:opacity-60 sm:w-auto"
          style={{ backgroundColor: accent }}
        >
          {busy ? 'Sending…' : 'Send application'}
        </button>
      </form>

      <StatusCheck subdomain={subdomain} accent={accent} />
    </div>
  );
}

/** "Already applied?" — status by reference and the phone number given. */
function StatusCheck({ subdomain, accent, initialReference = '' }: { subdomain: string; accent: string; initialReference?: string }) {
  const [answer, setAnswer] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function check(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    setBusy(true);
    try {
      const { status, json } = await post('/public/admissions/status', subdomain, {
        reference: String(data.get('reference') ?? '').trim(),
        phone: String(data.get('phone') ?? '').trim(),
      });
      setAnswer(
        status === 200
          ? `Application ${json.reference}: ${json.status}${json.offer_expires_at ? ` (offer open until ${json.offer_expires_at})` : ''}.`
          : json.message ?? 'No application matches that reference and phone number.',
      );
    } catch {
      setAnswer('We could not check just now. Please try again.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={check} className="rounded-xl border border-line-soft bg-white p-5 sm:p-6">
      <h2 className="text-base font-semibold text-ink">Already applied? Check your application</h2>
      <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
        <input name="reference" defaultValue={initialReference} placeholder="Reference" aria-label="Reference" className={inputClass} required />
        <input name="phone" type="tel" placeholder="Phone number you gave" aria-label="Phone number" className={inputClass} required />
        <button type="submit" disabled={busy} className="rounded-lg border px-4 py-2 text-sm font-semibold disabled:opacity-60" style={{ borderColor: accent, color: accent }}>
          {busy ? 'Checking…' : 'Check'}
        </button>
      </div>
      {answer && (
        <p role="status" className="mt-3 text-sm text-ink">
          {answer}
        </p>
      )}
    </form>
  );
}
