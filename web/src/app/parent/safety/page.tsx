'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';
import ChildSwitcher, { useChildren } from '@/components/ChildSwitcher';

/**
 * Collection and consent, for a parent's own children.
 *
 * Who may collect the child from school: the gate checks this list, and a
 * parent can stop someone at once. And the parent's NDPA consents — whether
 * the school may process the child's data, and whether AI features may
 * send it to a provider abroad. Consent is never assumed: unticked is no.
 */

interface Pickup { id: number; student_id: number; authorized_person_name: string; authorized_person_phone: string; relationship: string; is_active: boolean; created_at: string }
interface Consent { id: number; student_id: number; consent_given: boolean; ai_cross_border_consent_given: boolean; consented_at: string | null; withdrawn_at: string | null }

export default function ParentSafety() {
  const { children, selected, select, error: childError } = useChildren();
  const [pickups, setPickups] = useState<Pickup[] | null>(null);
  const [consents, setConsents] = useState<Consent[]>([]);
  const [form, setForm] = useState({ authorized_person_name: '', authorized_person_phone: '', relationship: '' });
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [p, c] = await Promise.all([
        fetchApi<{ data: Pickup[] }>('/parent/pickup-authorizations'),
        fetchApi<{ data: Consent[] }>('/parent/consents').catch(() => ({ data: [] as Consent[] })),
      ]);
      setPickups(p.data);
      setConsents(c.data);
    } catch (err) {
      setError(errorText(err, 'Could not load.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const current = consents.find((c) => c.student_id === selected?.id);

  async function act(path: string, body: unknown, done: string) {
    setBusy(true);
    setError(null);
    try {
      const r = await fetchApi<{ message?: string }>(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(r.message ?? done);
      await load();
      return true;
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
      return false;
    } finally {
      setBusy(false);
    }
  }

  const mine = (pickups ?? []).filter((p) => p.student_id === selected?.id);

  return (
    <PageShell title="Pickup & consent" subtitle="Who may collect your child, and what you have agreed to.">
      <ErrorNote message={childError ?? error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {!children ? <Loading /> : children.length === 0 ? <Empty>No child is linked to your account yet.</Empty> : (
        <>
          <ChildSwitcher items={children} selected={selected} onSelect={select} />
          <div className="grid gap-5 lg:grid-cols-2">
            <Card title="Who may collect your child" description="The school checks this list at the gate. Stop someone the moment you need to.">
              {!pickups ? <Loading /> : mine.length === 0 ? <p className="text-sm text-ink-muted">Only you, until you add someone.</p> : (
                <ul className="space-y-2 text-sm">
                  {mine.map((p) => (
                    <li key={p.id} className="flex flex-wrap items-center justify-between gap-2">
                      <span><span className="font-medium">{p.authorized_person_name}</span> · {p.relationship} · {p.authorized_person_phone} {!p.is_active && <Badge>stopped</Badge>}</span>
                      {p.is_active && <Button variant="danger" disabled={busy} onClick={() => { if (window.confirm(`Stop ${p.authorized_person_name} collecting your child?`)) void act(`/parent/pickup-authorizations/${p.id}/revoke`, {}, 'Stopped.'); }}>Stop</Button>}
                    </li>
                  ))}
                </ul>
              )}
              <form className="mt-4 grid gap-2 border-t border-line-soft pt-4 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); if (!selected) return; void act('/parent/pickup-authorization', { ...form, student_id: selected.id }, 'Added.').then((ok) => ok && setForm({ authorized_person_name: '', authorized_person_phone: '', relationship: '' })); }}>
                <Field label="Full name"><input className={inputClass} required maxLength={255} value={form.authorized_person_name} onChange={(e) => setForm({ ...form, authorized_person_name: e.target.value })} /></Field>
                <Field label="Phone"><input className={inputClass} required inputMode="tel" value={form.authorized_person_phone} onChange={(e) => setForm({ ...form, authorized_person_phone: e.target.value })} /></Field>
                <Field label="Relationship"><input className={inputClass} required value={form.relationship} onChange={(e) => setForm({ ...form, relationship: e.target.value })} placeholder="Aunt, driver…" /></Field>
                <div className="sm:col-span-3"><Button type="submit" disabled={busy}>Add person</Button></div>
              </form>
            </Card>

            {selected && <ConsentForm key={`${selected.id}-${current?.id ?? 0}-${current?.consented_at ?? ''}-${current?.withdrawn_at ?? ''}`} studentId={selected.id} current={current} busy={busy} act={act} />}
          </div>
        </>
      )}
    </PageShell>
  );
}

/** Starts from what is on record for this child; remounted when that changes. */
function ConsentForm({ studentId, current, busy, act }: { studentId: number; current: Consent | undefined; busy: boolean; act: (path: string, body: unknown, done: string) => Promise<boolean> }) {
  const [choice, setChoice] = useState({ consent_given: !!current?.consent_given, ai_cross_border_consent_given: !!current?.ai_cross_border_consent_given });

  return (
    <Card title="Your consent" description="Under the Nigeria Data Protection Act. You can change your mind at any time.">
      {current && <p className="mb-3 text-xs text-ink-muted">On record: {current.consent_given ? 'given' : 'not given'}{current.consented_at ? ` on ${current.consented_at.slice(0, 10)}` : ''}{current.withdrawn_at ? ` · withdrawn ${current.withdrawn_at.slice(0, 10)}` : ''}</p>}
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void act('/compliance/parental-consent', { student_id: studentId, ...choice }, 'Saved.'); }}>
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={choice.consent_given} onChange={(e) => setChoice({ consent_given: e.target.checked, ai_cross_border_consent_given: e.target.checked && choice.ai_cross_border_consent_given })} /> I agree that the school may keep and use my child’s records to teach and report on them.</label>
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" disabled={!choice.consent_given} checked={choice.ai_cross_border_consent_given} onChange={(e) => setChoice({ ...choice, ai_cross_border_consent_given: e.target.checked })} /> I agree that AI features (the study tutor, draft report comments) may send my child’s schoolwork to an AI provider outside Nigeria. My child’s name is not sent.</label>
        <div className="flex gap-2">
          <Button type="submit" disabled={busy}>Save my choice</Button>
          {current?.consent_given && <Button type="button" variant="danger" disabled={busy} onClick={() => { if (window.confirm('Withdraw your consent?')) void act(`/compliance/parental-consent/${current.id}/withdraw`, {}, 'Consent withdrawn.'); }}>Withdraw</Button>}
        </div>
      </form>
    </Card>
  );
}
