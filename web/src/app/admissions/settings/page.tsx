'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchApi } from '@/lib/api';
import { refreshPublicSchoolProfile } from '@/app/actions/school-profile';
import { Button, Card, ErrorNote, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * School Settings → Admissions: how parents reach this school about
 * admission. The school chooses any mix of the online form, WhatsApp, email
 * and phone; its public page shows only those.
 *
 * Choosing a method is separate from onboarding. Online applications go to
 * the Admissions list (review → offer → enrol creates the student). For
 * WhatsApp, email and phone, the school handles the enquiry and staff enrol
 * the accepted child themselves.
 */

interface Settings {
  admissions_status: 'open' | 'closed' | null;
  admission_session: string | null;
  instructions: string | null;
  closed_message: string | null;
  public_form_enabled: boolean;
  open_sections: string[] | null;
  sections: Record<string, string>;
  whatsapp_enabled: boolean;
  whatsapp_number: string | null;
  whatsapp_message: string | null;
  email_enabled: boolean;
  admissions_email: string | null;
  email_subject: string | null;
  phone_enabled: boolean;
  admissions_phone: string | null;
  school_contacts: { phone: string | null; email: string | null };
}

type Draft = Omit<Settings, 'sections' | 'school_contacts'>;

const FIELDS: (keyof Draft)[] = [
  'admissions_status', 'admission_session', 'instructions', 'closed_message',
  'public_form_enabled', 'open_sections',
  'whatsapp_enabled', 'whatsapp_number', 'whatsapp_message',
  'email_enabled', 'admissions_email', 'email_subject',
  'phone_enabled', 'admissions_phone',
];

function pick(s: Settings): Draft {
  return Object.fromEntries(FIELDS.map((f) => [f, s[f]])) as Draft;
}

/** "+2348031234567" back to the way people write it: 0803 123 4567. */
function local(number: string | null): string {
  if (!number) return '';
  const m = number.match(/^\+234(\d{3})(\d{3})(\d{4})$/);
  return m ? `0${m[1]} ${m[2]} ${m[3]}` : number;
}

export default function AdmissionsSettingsPage() {
  const [meta, setMeta] = useState<Pick<Settings, 'sections' | 'school_contacts'> | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    fetchApi<Settings>('/admissions/settings')
      .then((s) => {
        setMeta({ sections: s.sections, school_contacts: s.school_contacts });
        setDraft({ ...pick(s), whatsapp_number: local(s.whatsapp_number), admissions_phone: local(s.admissions_phone) });
      })
      .catch((err) => setError(err instanceof Error ? err.message : 'Could not load admissions settings.'));
  }, []);

  if (!draft || !meta) {
    return (
      <PageShell title="Admissions settings" subtitle="How parents reach your school about admission.">
        <ErrorNote message={error} />
        {!error && <Loading />}
      </PageShell>
    );
  }

  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => (d ? { ...d, [key]: value } : d));
  const anyMethod = draft.public_form_enabled || draft.whatsapp_enabled || draft.email_enabled || draft.phone_enabled;

  async function save() {
    if (!draft) return;
    setSaving(true);
    setErrors({});
    setError(null);
    setNotice(null);
    try {
      const saved = await fetchApi<Settings>('/admissions/settings', { method: 'PUT', body: JSON.stringify(draft) });
      setDraft({ ...pick(saved), whatsapp_number: local(saved.whatsapp_number), admissions_phone: local(saved.admissions_phone) });
      await refreshPublicSchoolProfile();
      setNotice('Saved. Your school page shows the change now.');
    } catch (err) {
      const payload = (err as { payload?: { errors?: Record<string, string[]>; message?: string } }).payload;
      if (payload?.errors) {
        setErrors(Object.fromEntries(Object.entries(payload.errors).map(([k, v]) => [k, v[0]])));
      }
      setError(payload?.message ?? (err instanceof Error ? err.message : 'Those settings could not be saved.'));
    } finally {
      setSaving(false);
    }
  }

  const fieldError = (key: string) => errors[key] && <p className="mt-1 text-xs text-negative">{errors[key]}</p>;

  return (
    <PageShell
      title="Admissions settings"
      subtitle="Open or close admissions, and choose how parents reach you. Your school page shows only the options you turn on."
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      <div className="space-y-6">
        <Card title="Admissions status">
          <div className="flex flex-wrap gap-3" role="radiogroup" aria-label="Admissions status">
            {(['open', 'closed'] as const).map((status) => (
              <label key={status} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium ${draft.admissions_status === status ? 'border-brand bg-brand text-white' : 'border-line text-ink'}`}>
                <input type="radio" name="status" className="sr-only" checked={draft.admissions_status === status} onChange={() => set('admissions_status', status)} />
                {status === 'open' ? 'Admissions open' : 'Admissions closed'}
              </label>
            ))}
          </div>
          {fieldError('admissions_status')}
          <div className="mt-4 grid gap-4 md:grid-cols-2">
            <label className="block">
              <span className="mb-1 block text-xs font-medium text-ink-muted">Academic session</span>
              <input value={draft.admission_session ?? ''} onChange={(e) => set('admission_session', e.target.value || null)} placeholder="2026/2027" className={inputClass} />
              {fieldError('admission_session')}
            </label>
          </div>
          {draft.admissions_status === 'closed' ? (
            <label className="mt-4 block">
              <span className="mb-1 block text-xs font-medium text-ink-muted">Message while closed (optional)</span>
              <textarea rows={2} maxLength={500} value={draft.closed_message ?? ''} onChange={(e) => set('closed_message', e.target.value || null)} placeholder="e.g. Admissions for 2027/2028 open in May. Call the school office to be told when." className={inputClass} />
            </label>
          ) : (
            <label className="mt-4 block">
              <span className="mb-1 block text-xs font-medium text-ink-muted">Application instructions (optional)</span>
              <textarea rows={3} maxLength={1000} value={draft.instructions ?? ''} onChange={(e) => set('instructions', e.target.value || null)} placeholder="e.g. Entrance assessment holds every Saturday in July, 9am. Bring the child's last report card." className={inputClass} />
            </label>
          )}
        </Card>

        <Card title="How parents reach you">
          <p className="mb-4 text-sm text-ink-muted">Turn on one or more. Parents see a button for each.</p>
          <div className="grid gap-4 md:grid-cols-2">
            <Method title="Online application form" on={draft.public_form_enabled} onToggle={(v) => set('public_form_enabled', v)}>
              <p className="text-xs text-ink-muted">Parents fill in a form; applications land in your Admissions list to review, offer and enrol.</p>
              <p className="mt-3 text-xs font-medium text-ink-muted">Sections taking applications</p>
              <div className="mt-1 flex flex-wrap gap-2">
                {Object.entries(meta.sections).map(([key, label]) => {
                  const open = draft.open_sections ?? Object.keys(meta.sections);
                  const checked = open.includes(key);
                  return (
                    <label key={key} className="flex items-center gap-1.5 text-sm">
                      <input type="checkbox" checked={checked} onChange={() => set('open_sections', checked ? open.filter((s) => s !== key) : [...open, key])} />
                      {label}
                    </label>
                  );
                })}
              </div>
            </Method>

            <Method title="WhatsApp" on={draft.whatsapp_enabled} onToggle={(v) => set('whatsapp_enabled', v)}>
              <ContactInput label="WhatsApp number" type="tel" value={draft.whatsapp_number} onChange={(v) => set('whatsapp_number', v)} suggestion={local(meta.school_contacts.phone)} placeholder="0803 123 4567" />
              {fieldError('whatsapp_number')}
              <label className="mt-3 block">
                <span className="mb-1 block text-xs font-medium text-ink-muted">Pre-filled message (optional)</span>
                <input maxLength={300} value={draft.whatsapp_message ?? ''} onChange={(e) => set('whatsapp_message', e.target.value || null)} placeholder="Hello, I'd like to ask about admission." className={inputClass} />
              </label>
            </Method>

            <Method title="Email" on={draft.email_enabled} onToggle={(v) => set('email_enabled', v)}>
              <ContactInput label="Admissions email" type="email" value={draft.admissions_email} onChange={(v) => set('admissions_email', v)} suggestion={meta.school_contacts.email ?? ''} placeholder="admissions@yourschool.com" />
              {fieldError('admissions_email')}
              <label className="mt-3 block">
                <span className="mb-1 block text-xs font-medium text-ink-muted">Email subject (optional)</span>
                <input maxLength={150} value={draft.email_subject ?? ''} onChange={(e) => set('email_subject', e.target.value || null)} placeholder="Admission enquiry" className={inputClass} />
              </label>
            </Method>

            <Method title="Phone" on={draft.phone_enabled} onToggle={(v) => set('phone_enabled', v)}>
              <ContactInput label="Admissions phone" type="tel" value={draft.admissions_phone} onChange={(v) => set('admissions_phone', v)} suggestion={local(meta.school_contacts.phone)} placeholder="0803 123 4567" />
              {fieldError('admissions_phone')}
            </Method>
          </div>
        </Card>

        <Card title="What parents will see">
          {draft.admissions_status === 'open' ? (
            anyMethod ? (
              <div className="flex flex-wrap gap-2">
                {draft.public_form_enabled && <Preview primary>Apply online</Preview>}
                {draft.whatsapp_enabled && <Preview primary={!draft.public_form_enabled}>WhatsApp us</Preview>}
                {draft.email_enabled && <Preview primary={!draft.public_form_enabled && !draft.whatsapp_enabled}>Email admissions</Preview>}
                {draft.phone_enabled && <Preview primary={!draft.public_form_enabled && !draft.whatsapp_enabled && !draft.email_enabled}>Call admissions</Preview>}
              </div>
            ) : (
              <p className="text-sm text-negative">Turn on at least one way for parents to reach you before opening admissions.</p>
            )
          ) : draft.admissions_status === 'closed' ? (
            <p className="text-sm text-ink-muted">“Admissions are closed{draft.admission_session ? ` for ${draft.admission_session}` : ''}”, with your message if you add one.</p>
          ) : (
            <p className="text-sm text-ink-muted">Nothing about admissions until you choose open or closed.</p>
          )}
          <p className="mt-3 text-xs text-ink-faint">
            Shown on your school page once it is published in <Link href="/school-profile" className="underline">School profile</Link>.
            Admissions you take by WhatsApp, email or phone are handled by your staff; enrol accepted children from the <Link href="/admissions" className="underline">Admissions</Link> or Students pages.
          </p>
        </Card>

        <div className="flex justify-end">
          <Button onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save admissions settings'}
          </Button>
        </div>
      </div>
    </PageShell>
  );
}

function Method({ title, on, onToggle, children }: { title: string; on: boolean; onToggle: (v: boolean) => void; children: React.ReactNode }) {
  return (
    <div className={`rounded-xl border p-4 ${on ? 'border-brand' : 'border-line-soft'}`}>
      <label className="flex cursor-pointer items-center justify-between gap-3">
        <span className="font-semibold text-ink">{title}</span>
        <span className="flex items-center gap-2 text-sm text-ink-muted">
          {on ? 'On' : 'Off'}
          <input type="checkbox" checked={on} onChange={(e) => onToggle(e.target.checked)} className="h-5 w-5" />
        </span>
      </label>
      {on && <div className="mt-3">{children}</div>}
    </div>
  );
}

function ContactInput({ label, type, value, onChange, suggestion, placeholder }: { label: string; type: string; value: string | null; onChange: (v: string | null) => void; suggestion: string; placeholder: string }) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-medium text-ink-muted">{label}</span>
      <input type={type} value={value ?? ''} onChange={(e) => onChange(e.target.value || null)} placeholder={placeholder} className={inputClass} />
      {!value && suggestion && (
        <button type="button" onClick={() => onChange(suggestion)} className="mt-1 text-xs font-medium text-brand underline">
          Use the school&apos;s {type === 'email' ? 'email' : 'number'} ({suggestion})
        </button>
      )}
    </label>
  );
}

function Preview({ children, primary = false }: { children: React.ReactNode; primary?: boolean }) {
  return (
    <span className={`rounded-lg px-4 py-2 text-sm font-semibold ${primary ? 'bg-brand text-white' : 'border border-line bg-white text-ink'}`}>{children}</span>
  );
}
