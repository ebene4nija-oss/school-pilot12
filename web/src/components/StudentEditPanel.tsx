'use client';

import React, { useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Button, Field, inputClass } from '@/components/Page';
import { useCookieRole } from '@/components/Sidebar';

/**
 * A student's passport photo and record corrections, inside the directory's
 * quick view.
 *
 * Photos are private: the <img> goes through the signed-in proxy to
 * /students/{id}/photo, never a public link. Who may change what is the
 * server's decision (record: admins; photo: admins and the class's form
 * teacher); this only hides buttons that would certainly be refused.
 *
 * Mounted with key={studentId}, so each student starts with a fresh panel.
 */

interface Record_ {
  name?: string | null;
  admission_number?: string | null;
  gender?: string | null;
  date_of_birth?: string | null;
  state_of_origin?: string | null;
  lga?: string | null;
}

export default function StudentEditPanel({ studentId, onSaved }: { studentId: number; onSaved?: () => void }) {
  const role = useCookieRole();
  const admin = role === 'school_admin' || role === 'super_admin';
  const teacher = role === 'teacher';
  const [photoVersion, setPhotoVersion] = useState(0);
  const [hasPhoto, setHasPhoto] = useState(true);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<Record_>({});
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null);

  async function startEdit() {
    setNote(null);
    try {
      const { student } = await fetchApi<{ student: Record_ & { date_of_birth?: string | null } }>(`/students/${studentId}`);
      setForm({ ...student, date_of_birth: student.date_of_birth?.slice(0, 10) ?? '' });
      setEditing(true);
    } catch (err) {
      setNote({ ok: false, text: errorText(err, 'Could not load the record.') });
    }
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setNote(null);
    try {
      await fetchApi(`/students/${studentId}`, {
        method: 'PUT',
        body: JSON.stringify({
          name: form.name, admission_number: form.admission_number,
          gender: form.gender || null, date_of_birth: form.date_of_birth || null,
          state_of_origin: form.state_of_origin, lga: form.lga,
        }),
      });
      setEditing(false);
      setNote({ ok: true, text: 'Saved.' });
      onSaved?.();
    } catch (err) {
      setNote({ ok: false, text: errorText(err, 'Could not save.') });
    } finally {
      setBusy(false);
    }
  }

  async function upload(file: File) {
    setBusy(true);
    setNote(null);
    try {
      const fd = new FormData();
      fd.append('photo', file);
      await uploadApi(`/students/${studentId}/photo`, fd);
      setHasPhoto(true);
      setPhotoVersion((v) => v + 1);
      setNote({ ok: true, text: 'Photo saved.' });
    } catch (err) {
      setNote({ ok: false, text: errorText(err, 'Could not save the photo.') });
    } finally {
      setBusy(false);
    }
  }

  const set = (k: keyof Record_) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value });

  return (
    <div className="mt-4 space-y-3">
      <div className="flex items-center gap-4">
        {hasPhoto ? (
          // eslint-disable-next-line @next/next/no-img-element -- a private, session-gated image; next/image would try to optimise it unauthenticated
          <img
            src={`/api/backend/students/${studentId}/photo?v=${photoVersion}`}
            alt="Passport photo"
            className="h-28 w-24 rounded-lg object-cover ring-1 ring-line-soft"
            onError={() => setHasPhoto(false)}
          />
        ) : (
          <div className="flex h-28 w-24 items-center justify-center rounded-lg bg-surface-sunken text-xs text-ink-muted ring-1 ring-line-soft">No photo</div>
        )}
        <div className="space-y-2">
          {(admin || teacher) && (
            <label className="block cursor-pointer text-sm font-medium text-brand underline">
              {hasPhoto ? 'Replace photo' : 'Upload photo'}
              <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" disabled={busy} onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = ''; }} />
            </label>
          )}
          {admin && !editing && <Button variant="secondary" disabled={busy} onClick={startEdit}>Edit details</Button>}
        </div>
      </div>
      {(admin || teacher) && <p className="text-xs text-ink-muted">Head and shoulders, plain light background. Location data is removed and the photo is never public.</p>}

      {editing && (
        <form onSubmit={save} className="space-y-3 rounded-lg bg-surface-sunken p-4">
          <Field label="Full name"><input className={inputClass} required value={form.name ?? ''} onChange={set('name')} /></Field>
          <Field label="Admission number"><input className={inputClass} value={form.admission_number ?? ''} onChange={set('admission_number')} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Gender">
              <select className={inputClass} value={form.gender ?? ''} onChange={set('gender')}>
                <option value="">—</option><option value="female">Female</option><option value="male">Male</option>
              </select>
            </Field>
            <Field label="Date of birth"><input type="date" className={inputClass} value={form.date_of_birth ?? ''} onChange={set('date_of_birth')} /></Field>
            <Field label="State of origin"><input className={inputClass} value={form.state_of_origin ?? ''} onChange={set('state_of_origin')} /></Field>
            <Field label="LGA"><input className={inputClass} value={form.lga ?? ''} onChange={set('lga')} /></Field>
          </div>
          <p className="text-xs text-ink-muted">Class changes go through Move class in the list. Health details are a separate, audited record.</p>
          <div className="flex gap-2">
            <Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save changes'}</Button>
            <Button type="button" variant="secondary" disabled={busy} onClick={() => setEditing(false)}>Cancel</Button>
          </div>
        </form>
      )}
      {note && <p className={`text-sm ${note.ok ? 'text-positive' : 'text-negative'}`}>{note.text}</p>}
    </div>
  );
}
