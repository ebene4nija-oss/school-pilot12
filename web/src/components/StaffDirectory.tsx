'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, Table, inputClass } from '@/components/Page';

/**
 * Who works at the school, and adding them — one at a time or from a CSV.
 *
 * There was no way to add a teacher from the portal. Each person added here
 * gets a login, their role and a staff record together, and is emailed a link
 * to set their own password; nobody types a password for someone else.
 */

type Role = 'school_admin' | 'teacher' | 'nurse' | 'bursar';

type Member = {
  user_id: number;
  name: string;
  email: string | null;
  phone: string | null;
  role: Role;
  /** Teachers: sees every student, not just their own classes. */
  sees_all_students: boolean;
  staff_id: string | null;
  designation: string | null;
  qualification: string | null;
  employment_date: string | null;
};

type ImportRow = { line: number; status: 'ready' | 'error'; errors: string[]; name: string | null; email: string | null; role: string | null };

type ImportResult = {
  summary: { total: number; ready: number; errors: number };
  created: number;
  rows: ImportRow[];
  message: string;
};

export const ROLE_LABEL: Record<Role, string> = {
  school_admin: 'Administrator',
  teacher: 'Teacher',
  nurse: 'Nurse',
  bursar: 'Bursar',
};

const ROLE_TONE: Record<Role, 'blue' | 'green' | 'amber' | 'slate'> = {
  school_admin: 'amber',
  teacher: 'blue',
  nurse: 'green',
  bursar: 'slate',
};

export function StaffList({ refreshKey }: { refreshKey: number }) {
  const [members, setMembers] = useState<Member[]>([]);
  const [counts, setCounts] = useState<Partial<Record<Role, number>>>({});
  const [total, setTotal] = useState(0);
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async (term: string, roleFilter: string) => {
    const params = new URLSearchParams();
    if (term.trim()) params.set('search', term.trim());
    if (roleFilter) params.set('role', roleFilter);

    try {
      const res = await fetchApi<{ data: Member[]; counts: Partial<Record<Role, number>>; meta: { total: number } }>(
        `/staff/directory?${params}`,
      );
      setMembers(res.data ?? []);
      setCounts(res.counts ?? {});
      setTotal(res.meta?.total ?? 0);
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load the staff list.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => void load(search, role), search ? 250 : 0);
    return () => clearTimeout(timer);
  }, [load, search, role, refreshKey]);

  /**
   * A teacher sees the classes they teach. Some — an exams officer, a head of
   * section — need every student; this grants it, on the record.
   */
  async function setWholeSchool(member: Member, enabled: boolean) {
    if (
      enabled &&
      !window.confirm(`Let ${member.name} see every student in the school, not just the classes they teach?`)
    ) {
      return;
    }

    setBusyId(member.user_id);
    setError(null);
    setNotice(null);

    try {
      const res = await fetchApi<{ message: string }>(`/staff/${member.user_id}/student-access`, {
        method: 'PUT',
        body: JSON.stringify({ sees_all_students: enabled }),
      });
      setNotice(res.message);
      setMembers((prev) => prev.map((m) => (m.user_id === member.user_id ? { ...m, sees_all_students: enabled } : m)));
    } catch (err) {
      setError(errorText(err, 'That change could not be saved.'));
    } finally {
      setBusyId(null);
    }
  }

  return (
    <Card title="Staff directory" description="Everyone with a staff login at this school.">
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search by name or email"
          className={`${inputClass} max-w-xs`}
        />
        <select value={role} onChange={(e) => setRole(e.target.value)} className={`${inputClass} max-w-[12rem]`}>
          <option value="">All roles</option>
          {(Object.keys(ROLE_LABEL) as Role[]).map((r) => (
            <option key={r} value={r}>
              {ROLE_LABEL[r]} ({counts[r] ?? 0})
            </option>
          ))}
        </select>
        <span className="text-xs text-ink-muted">{total} shown</span>
      </div>

      {loading ? (
        <Loading />
      ) : members.length === 0 ? (
        <Empty>{search || role ? 'Nobody matches that.' : 'No staff added yet. Add one, or import a CSV.'}</Empty>
      ) : (
        <Table columns={['Name', 'Role', 'Sees students', 'Staff ID', 'Designation', 'Phone', 'Email']}>
          {members.map((m) => (
            <tr key={m.user_id}>
              <Cell className="font-medium text-brand">{m.name}</Cell>
              <Cell>
                <Badge tone={ROLE_TONE[m.role] ?? 'slate'}>{ROLE_LABEL[m.role] ?? m.role}</Badge>
              </Cell>
              <Cell className="text-xs">
                {m.role === 'teacher' ? (
                  <div className="flex items-center gap-2">
                    <span>{m.sees_all_students ? 'Whole school' : 'Own classes'}</span>
                    <button
                      disabled={busyId === m.user_id}
                      onClick={() => setWholeSchool(m, !m.sees_all_students)}
                      className="font-medium text-info underline hover:text-brand disabled:opacity-60"
                    >
                      {m.sees_all_students ? 'Limit' : 'Grant all'}
                    </button>
                  </div>
                ) : m.role === 'school_admin' ? (
                  'Whole school'
                ) : (
                  <span className="text-ink-faint">None</span>
                )}
              </Cell>
              <Cell className="font-mono text-xs">{m.staff_id ?? '—'}</Cell>
              <Cell>{m.designation ?? '—'}</Cell>
              <Cell className="text-xs">{m.phone ?? '—'}</Cell>
              <Cell className="text-xs text-ink-muted">{m.email ?? '—'}</Cell>
            </tr>
          ))}
        </Table>
      )}
    </Card>
  );
}

export function AddStaffForm({ onAdded }: { onAdded: () => void }) {
  const [form, setForm] = useState({
    name: '', email: '', phone: '', role: 'teacher' as Role, staff_id: '', designation: '', qualification: '', employment_date: '',
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setDone(null);

    // Blank optional fields are left out rather than sent as "".
    const body = Object.fromEntries(Object.entries(form).filter(([, v]) => String(v).trim() !== ''));

    try {
      const res = await fetchApi<{ message: string; setup_link_sent: boolean }>('/staff', {
        method: 'POST',
        body: JSON.stringify(body),
      });
      setDone(
        res.message +
          (res.setup_link_sent
            ? ' They have been emailed a link to set their password.'
            : ' The setup email could not be sent — they can use “Forgot password” on the sign-in page.'),
      );
      setForm({ name: '', email: '', phone: '', role: form.role, staff_id: '', designation: '', qualification: '', employment_date: '' });
      onAdded();
    } catch (err) {
      setError(errorText(err, 'That staff member could not be added.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="Add a staff member" description="They get a login for their role and an email to set their own password.">
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Full name">
          <input required value={form.name} onChange={(e) => set({ name: e.target.value })} className={inputClass} />
        </Field>
        <Field label="Email (used to sign in)">
          <input required type="email" value={form.email} onChange={(e) => set({ email: e.target.value })} className={inputClass} />
        </Field>
        <Field label="Role">
          <select value={form.role} onChange={(e) => set({ role: e.target.value as Role })} className={inputClass}>
            <option value="teacher">Teacher</option>
            <option value="nurse">Nurse — clinic log only</option>
            <option value="bursar">Bursar — fees and accounts only</option>
            <option value="school_admin">Administrator — full access</option>
          </select>
        </Field>
        <Field label="Phone (optional)">
          <input type="tel" value={form.phone} onChange={(e) => set({ phone: e.target.value })} className={inputClass} placeholder="0803 123 4567" />
        </Field>
        <Field label="Staff ID (optional)">
          <input value={form.staff_id} onChange={(e) => set({ staff_id: e.target.value })} className={inputClass} />
        </Field>
        <Field label="Designation (optional)">
          <input
            value={form.designation}
            onChange={(e) => set({ designation: e.target.value })}
            className={inputClass}
            placeholder={form.role === 'nurse' ? 'School nurse' : 'e.g. Mathematics teacher'}
          />
        </Field>
        <Field label="Qualification (optional)">
          <input value={form.qualification} onChange={(e) => set({ qualification: e.target.value })} className={inputClass} placeholder="e.g. B.Sc. Ed, NCE, RN" />
        </Field>
        <Field label="Employment date (optional)">
          <input type="date" value={form.employment_date} onChange={(e) => set({ employment_date: e.target.value })} className={inputClass} max={new Date().toISOString().slice(0, 10)} />
        </Field>

        <div className="sm:col-span-2 lg:col-span-4">
          {form.role === 'school_admin' && (
            <p className="mb-3 rounded-lg bg-caution-soft px-3 py-2 text-xs text-caution">
              An administrator can see and change everything in this school, including fees and results.
            </p>
          )}
          {error && <p role="alert" className="mb-3 rounded-lg bg-negative-soft px-3 py-2 text-sm text-negative">{error}</p>}
          {done && <p role="status" className="mb-3 rounded-lg bg-positive-soft px-3 py-2 text-sm text-positive">✓ {done}</p>}
          <Button type="submit" disabled={busy}>{busy ? 'Adding…' : 'Add staff member'}</Button>
        </div>
      </form>
    </Card>
  );
}

export function ImportStaff({ onImported }: { onImported: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<ImportResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const send = async (dryRun: boolean) => {
    if (!file) return;

    setBusy(true);
    setError(null);
    setDone(null);

    const form = new FormData();
    form.append('file', file);
    if (dryRun) form.append('dry_run', '1');

    try {
      const res = await uploadApi<ImportResult>('/staff/import', form);

      if (dryRun) {
        setPreview(res);
      } else {
        setDone(res.message);
        setPreview(null);
        setFile(null);
        onImported();
      }
    } catch (err) {
      // A real import refused for bad rows returns the same row report.
      const payload = (err as { payload?: ImportResult })?.payload;
      if (payload?.rows) setPreview(payload);
      setError(errorText(err, 'That file could not be imported.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card
      title="Import staff from a CSV"
      description="Check the file first — nothing is created until every row is ready, and then all rows are created together."
    >
      <a
        href="/api/backend/staff/import/template"
        download
        className="mb-4 inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-1.5 text-xs font-semibold text-brand transition hover:bg-surface-sunken"
      >
        ⬇ Download the CSV template
      </a>
      <p className="mb-4 text-xs text-ink-muted">
        Columns: <code>name, email, phone, role, staff_id, designation, qualification, employment_date</code>. Role is{' '}
        <code>teacher</code>, <code>nurse</code>, <code>bursar</code> or <code>school_admin</code>. Up to 500 people per file.
      </p>

      <div className="flex flex-wrap items-center gap-3">
        <input
          type="file"
          accept=".csv,text/csv"
          onChange={(e) => {
            setFile(e.target.files?.[0] ?? null);
            setPreview(null);
            setDone(null);
            setError(null);
          }}
          className="text-xs file:mr-3 file:rounded file:border-0 file:bg-surface-raised file:px-3 file:py-1.5 file:text-ink"
        />
        <Button variant="secondary" disabled={!file || busy} onClick={() => send(true)}>
          {busy && !preview ? 'Checking…' : 'Check file'}
        </Button>
      </div>

      {error && <p role="alert" className="mt-4 rounded-lg bg-negative-soft px-3 py-2 text-sm text-negative">{error}</p>}
      {done && <p role="status" className="mt-4 rounded-lg bg-positive-soft px-3 py-2 text-sm text-positive">✓ {done}</p>}

      {preview && (
        <div className="mt-5">
          <div className="mb-3 flex flex-wrap items-center gap-3 text-xs">
            <span className="font-semibold text-ink">{preview.summary.total} rows</span>
            <Badge tone="green">{preview.summary.ready} ready</Badge>
            {preview.summary.errors > 0 && <Badge tone="red">{preview.summary.errors} with problems</Badge>}
          </div>

          <div className="max-h-72 overflow-y-auto">
            <Table columns={['Row', 'Name', 'Email', 'Role', 'Status']}>
              {preview.rows.map((r) => (
                <tr key={r.line}>
                  <Cell className="text-ink-faint">{r.line}</Cell>
                  <Cell>{r.name ?? '—'}</Cell>
                  <Cell className="text-xs">{r.email ?? '—'}</Cell>
                  <Cell className="text-xs">{r.role ?? '—'}</Cell>
                  <Cell className="text-xs">
                    {r.status === 'ready' ? <span className="text-positive">Ready</span> : <span className="text-negative">{r.errors.join(' ')}</span>}
                  </Cell>
                </tr>
              ))}
            </Table>
          </div>

          {preview.summary.errors > 0 ? (
            <p className="mt-3 text-xs text-ink-muted">Fix the rows with problems in your sheet, then choose the file again.</p>
          ) : (
            <Button className="mt-4" disabled={busy} onClick={() => send(false)}>
              {busy ? 'Adding…' : `Add all ${preview.summary.ready} staff`}
            </Button>
          )}
        </div>
      )}
    </Card>
  );
}
