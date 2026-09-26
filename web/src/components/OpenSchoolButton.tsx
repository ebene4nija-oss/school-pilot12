'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import { viewSchool } from '@/app/actions/auth';
import { Button, inputClass } from '@/components/Page';

/**
 * "Open" for a school in the operator console, with the reason it now needs.
 *
 * Opening a school gives an operator its children's records, so it is on the
 * record: the reason is stored with a time-limited grant and audited, and the
 * school can later be told who looked and why.
 */
export default function OpenSchoolButton({
  schoolId,
  schoolName,
  label = 'Open',
}: {
  schoolId: number;
  schoolName: string;
  label?: string;
}) {
  const router = useRouter();
  const [asking, setAsking] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const res = await viewSchool(schoolId, reason.trim());

      if (res.error) {
        setError(res.error);
        return;
      }

      router.push('/');
      router.refresh();
    } finally {
      setBusy(false);
    }
  }

  if (!asking) {
    return (
      <Button variant="secondary" onClick={() => setAsking(true)}>
        {label}
      </Button>
    );
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby={`open-school-${schoolId}`}
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onClick={() => !busy && setAsking(false)}
    >
      <form
        onSubmit={submit}
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-md rounded-xl bg-surface p-5 text-left shadow-lg ring-1 ring-line-soft"
      >
        <h2 id={`open-school-${schoolId}`} className="font-headline text-base font-semibold text-brand">
          Open {schoolName}
        </h2>
        <p className="mt-1 text-sm text-ink-muted">
          You will see this school&apos;s records, including its pupils&apos;. Say why: it is recorded
          with your name, and access ends automatically after a couple of hours.
        </p>

        <label className="mt-4 block">
          <span className="mb-1 block text-sm font-medium text-ink">Reason</span>
          <textarea
            autoFocus
            required
            minLength={10}
            maxLength={500}
            rows={3}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="e.g. Proprietor called: report cards for JSS2 not generating"
            className={inputClass}
          />
        </label>

        {error && <p className="mt-2 text-sm text-negative">{error}</p>}

        <div className="mt-4 flex justify-end gap-2">
          <Button type="button" variant="secondary" disabled={busy} onClick={() => setAsking(false)}>
            Cancel
          </Button>
          <Button type="submit" disabled={busy || reason.trim().length < 10}>
            {busy ? 'Opening…' : 'Open school'}
          </Button>
        </div>
      </form>
    </div>
  );
}
