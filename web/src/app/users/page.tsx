'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * Accounts at this school (doc §5): every staff member, student and parent
 * who can sign in. Invite one or a CSV of many, suspend or archive, send a
 * password-reset link, unlock after too many failed attempts, sign someone
 * out everywhere, and read their sign-in and activity history.
 *
 * Nobody is ever shown or given a password here: an invite or reset sends a
 * setup link, and the old password stops working at once. Every action is
 * written to the audit log.
 */

type Tab = 'people' | 'invite' | 'roles';
interface U { id: number; name: string; email: string; status: string | null; last_login_at: string | null; user_profile: { role: string; phone: string | null } | null }
interface Page_ { data: U[]; current_page: number; last_page: number; total: number }
interface Security { two_factor_enabled: boolean; last_login_at: string | null; failed_login_attempts: number; locked_until: string | null; password_changed_at: string | null; must_change_password: boolean; active_sessions: number; account_status: string; email_verified: boolean }
interface Detail { id: number; name: string; email: string; status: string; user_profile: Record<string, string | number | null> & { role: string }; custom_roles: { id: number; name: string }[] }
interface Role { id: number; name: string; slug: string; description: string | null; permissions: string[]; users_count: number }

const ROLES = ['school_admin', 'teacher', 'bursar', 'nurse', 'student', 'parent'];
const STATUS_TONE: Record<string, 'green' | 'amber' | 'red' | 'slate'> = { active: 'green', pending: 'amber', suspended: 'red', archived: 'slate' };
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
const label = (r: string) => r.replaceAll('_', ' ');

export default function UsersPage() {
  const [tab, setTab] = useState<Tab>('people');
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const done = useCallback((m: string) => { setNotice(m); setError(null); }, []);
  const fail = useCallback((e: unknown, f: string) => { setError(errorText(e, f)); setNotice(null); }, []);

  return (
    <PageShell title="Users & accounts" subtitle="Everyone who can sign in to your school, and what they can do.">
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['people', 'People'], ['invite', 'Invite'], ['roles', 'Custom roles']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setNotice(null); setError(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {tab === 'people' && <People onDone={done} onError={fail} />}
      {tab === 'invite' && <Invite onDone={done} onError={fail} />}
      {tab === 'roles' && <Roles onDone={done} onError={fail} />}
    </PageShell>
  );
}

type H = { onDone: (m: string) => void; onError: (e: unknown, f: string) => void };

function People({ onDone, onError }: H) {
  const [filters, setFilters] = useState({ search: '', role: '', status: '' });
  const [page, setPage] = useState(1);
  const [list, setList] = useState<Page_ | null>(null);
  const [picked, setPicked] = useState<number[]>([]);
  const [open, setOpen] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const qs = new URLSearchParams({ page: String(page), per_page: '25', ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) });
    try { setList(await fetchApi<Page_>(`/users?${qs}`)); } catch (err) { onError(err, 'Could not load accounts.'); }
  }, [filters, page, onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function bulk(status: string) {
    if (!window.confirm(`Set ${picked.length} account(s) to ${status}?${status === 'suspended' ? ' They are signed out at once.' : ''}`)) return;
    setBusy(true);
    try {
      const r = await fetchApi<{ message: string }>('/users/bulk-status', { method: 'POST', body: JSON.stringify({ user_ids: picked, status }) });
      onDone(r.message);
      setPicked([]);
      await load();
    } catch (err) { onError(err, 'Could not update them.'); } finally { setBusy(false); }
  }

  async function exportCsv() {
    try {
      const r = await fetchApi<{ rows: (string | null)[][] }>('/users/export');
      const csv = r.rows.map((row) => row.map((v) => `"${String(v ?? '').replaceAll('"', '""')}"`).join(',')).join('\r\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
      a.download = 'accounts.csv';
      a.click();
    } catch (err) { onError(err, 'Could not export.'); }
  }

  const set = (k: keyof typeof filters, v: string) => { setPage(1); setFilters((f) => ({ ...f, [k]: v })); };

  return (
    <div className="grid gap-5 xl:grid-cols-[1fr_28rem]">
      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-3">
          <Field label="Search"><input className={inputClass} placeholder="Name or email" value={filters.search} onChange={(e) => set('search', e.target.value)} /></Field>
          <Field label="Role"><select className={inputClass} value={filters.role} onChange={(e) => set('role', e.target.value)}><option value="">All</option>{ROLES.map((r) => <option key={r} value={r}>{label(r)}</option>)}</select></Field>
          <Field label="Status"><select className={inputClass} value={filters.status} onChange={(e) => set('status', e.target.value)}><option value="">All</option>{['active', 'pending', 'suspended', 'archived'].map((s) => <option key={s}>{s}</option>)}</select></Field>
        </div>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          {picked.length > 0 && <>
            <span className="text-sm">{picked.length} selected</span>
            <Button variant="secondary" disabled={busy} onClick={() => bulk('active')}>Activate</Button>
            <Button variant="secondary" disabled={busy} onClick={() => bulk('suspended')}>Suspend</Button>
            <Button variant="secondary" disabled={busy} onClick={() => bulk('archived')}>Archive</Button>
          </>}
          <Button variant="secondary" className="ml-auto" onClick={exportCsv}>Export CSV</Button>
        </div>
        {!list ? <Loading /> : list.data.length === 0 ? <Empty>No accounts match.</Empty> : (
          <Table columns={['', 'Name', 'Role', 'Status', 'Last sign-in', '']}>
            {list.data.map((u) => (
              <tr key={u.id} className={open === u.id ? 'bg-surface-sunken' : ''}>
                <Cell><input type="checkbox" aria-label={`Select ${u.name}`} checked={picked.includes(u.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, u.id] : picked.filter((x) => x !== u.id))} /></Cell>
                <Cell className="font-medium">{u.name}<span className="block text-xs text-ink-muted">{u.email}</span></Cell>
                <Cell className="capitalize">{label(u.user_profile?.role ?? '—')}</Cell>
                <Cell><Badge tone={STATUS_TONE[u.status ?? 'active'] ?? 'slate'}>{u.status ?? 'active'}</Badge></Cell>
                <Cell className="text-xs">{when(u.last_login_at)}</Cell>
                <Cell><button type="button" className="text-sm text-brand underline" onClick={() => setOpen(u.id)}>Manage</button></Cell>
              </tr>
            ))}
          </Table>
        )}
        {list && list.last_page > 1 && (
          <div className="mt-4 flex items-center gap-3">
            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
            <span className="text-sm text-ink-muted">Page {page} of {list.last_page} · {list.total} accounts</span>
            <Button variant="secondary" disabled={page >= list.last_page} onClick={() => setPage(page + 1)}>Next</Button>
          </div>
        )}
      </Card>
      {open ? <Account key={open} id={open} onDone={(m) => { onDone(m); void load(); }} onError={onError} onClose={() => setOpen(null)} /> : <Card><p className="text-sm text-ink-muted">Choose an account to manage it.</p></Card>}
    </div>
  );
}

function Account({ id, onDone, onError, onClose }: H & { id: number; onClose: () => void }) {
  const [d, setD] = useState<Detail | null>(null);
  const [sec, setSec] = useState<Security | null>(null);
  const [logins, setLogins] = useState<{ id: number; login_at: string; ip_address: string | null; user_agent: string | null; status?: string; successful?: boolean }[]>([]);
  const [timeline, setTimeline] = useState<{ id: number; action: string; created_at: string }[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [roleId, setRoleId] = useState('');
  const [prof, setProf] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const [u, s, l, t] = await Promise.all([
        fetchApi<{ data: Detail }>(`/users/${id}`),
        fetchApi<Security>(`/users/${id}/security`),
        fetchApi<{ data: typeof logins }>(`/users/${id}/login-history`).catch(() => ({ data: [] })),
        fetchApi<{ data: typeof timeline }>(`/users/${id}/timeline`).catch(() => ({ data: [] })),
      ]);
      setD(u.data); setSec(s); setLogins(l.data); setTimeline(t.data);
      const p = u.data.user_profile;
      setProf(Object.fromEntries(['phone', 'address', 'gender', 'state_of_origin', 'emergency_contact_name', 'emergency_contact_phone'].map((k) => [k, p[k] != null ? String(p[k]) : ''])));
    } catch (err) { onError(err, 'Could not open the account.'); }
  }, [id, onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ data: Role[] }>('/roles').then((r) => setRoles(r.data)).catch(() => undefined);
  }, [load]);

  async function act(path: string, method: string, body: unknown, confirmText?: string) {
    if (confirmText && !window.confirm(confirmText)) return;
    setBusy(true);
    try {
      const r = await fetchApi<{ message?: string }>(path, { method, body: JSON.stringify(body) });
      onDone(r.message ?? 'Done.');
      await load();
    } catch (err) { onError(err, 'That did not go through.'); } finally { setBusy(false); }
  }

  if (!d || !sec) return <Card><Loading /></Card>;
  const locked = sec.locked_until && new Date(sec.locked_until) > new Date();

  return (
    <Card title={d.name} description={`${d.email} · ${label(d.user_profile.role)}`}>
      <button type="button" className="mb-3 text-xs underline" onClick={onClose}>Close</button>
      <dl className="grid grid-cols-2 gap-y-1 text-sm">
        <dt className="text-ink-muted">Status</dt><dd><Badge tone={STATUS_TONE[sec.account_status] ?? 'slate'}>{sec.account_status}</Badge>{locked && <Badge tone="red">locked</Badge>}</dd>
        <dt className="text-ink-muted">Two-step sign-in</dt><dd>{sec.two_factor_enabled ? 'on' : 'off'}</dd>
        <dt className="text-ink-muted">Signed in on</dt><dd>{sec.active_sessions} device(s)</dd>
        <dt className="text-ink-muted">Last sign-in</dt><dd className="text-xs">{when(sec.last_login_at)}</dd>
        <dt className="text-ink-muted">Failed attempts</dt><dd>{sec.failed_login_attempts}</dd>
        <dt className="text-ink-muted">Password set</dt><dd className="text-xs">{sec.must_change_password ? 'not yet — setup link pending' : when(sec.password_changed_at)}</dd>
      </dl>

      <div className="mt-4 flex flex-wrap gap-2">
        <Button variant="secondary" disabled={busy} onClick={() => act(`/users/${id}/reset-password`, 'POST', {}, `Send ${d.name} a password-reset link? Their current password and every signed-in device stop working now.`)}>Send reset link</Button>
        <Button variant="secondary" disabled={busy || sec.active_sessions === 0} onClick={() => act(`/users/${id}/revoke-sessions`, 'POST', {}, 'Sign this person out of every device?')}>Sign out everywhere</Button>
        {(locked || sec.failed_login_attempts > 0) && <Button variant="secondary" disabled={busy} onClick={() => act(`/users/${id}/unlock`, 'POST', {}, 'Unlock this account? This also reactivates it if it was suspended.')}>Unlock and reactivate</Button>}
        {sec.account_status !== 'active' && <Button disabled={busy} onClick={() => act(`/users/${id}/status`, 'POST', { status: 'active' })}>Activate</Button>}
        {sec.account_status === 'active' && <Button variant="danger" disabled={busy} onClick={() => { const reason = window.prompt(`Why is ${d.name} being suspended? They are signed out at once.`); if (reason !== null) void act(`/users/${id}/status`, 'POST', { status: 'suspended', reason: reason || null }); }}>Suspend</Button>}
        {sec.account_status !== 'archived' && <Button variant="secondary" disabled={busy} onClick={() => { const reason = window.prompt('Archive this account (they have left the school)? Reason:'); if (reason !== null) void act(`/users/${id}/status`, 'POST', { status: 'archived', reason: reason || null }); }}>Archive</Button>}
      </div>

      <details className="mt-5">
        <summary className="cursor-pointer text-sm font-semibold text-brand">Contact details</summary>
        <form className="mt-3 grid gap-2 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); void act(`/users/${id}/profile`, 'PUT', Object.fromEntries(Object.entries(prof).map(([k, v]) => [k, v.trim() || null]))); }}>
          {Object.keys(prof).map((k) => (
            k === 'gender'
              ? <Field key={k} label="Gender"><select className={inputClass} value={prof[k]} onChange={(e) => setProf({ ...prof, [k]: e.target.value })}><option value="">—</option><option value="male">Male</option><option value="female">Female</option></select></Field>
              : <Field key={k} label={label(k)}><input className={inputClass} value={prof[k]} onChange={(e) => setProf({ ...prof, [k]: e.target.value })} /></Field>
          ))}
          <div className="sm:col-span-2"><Button type="submit" variant="secondary" disabled={busy}>Save details</Button></div>
        </form>
      </details>

      <details className="mt-3">
        <summary className="cursor-pointer text-sm font-semibold text-brand">Custom roles</summary>
        <p className="mt-2 text-xs text-ink-muted">Labels for your records. They do not change what this person can open.</p>
        <ul className="mt-2 space-y-1 text-sm">{d.custom_roles.map((r) => <li key={r.id}>{r.name} <button type="button" className="text-xs text-negative underline" onClick={() => act(`/users/${id}/roles/${r.id}`, 'DELETE', {})}>remove</button></li>)}</ul>
        {roles.length > 0 && (
          <div className="mt-2 flex gap-2">
            <select className={inputClass} value={roleId} onChange={(e) => setRoleId(e.target.value)}><option value="">Add a role…</option>{roles.filter((r) => !d.custom_roles.some((c) => c.id === r.id)).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select>
            <Button variant="secondary" disabled={!roleId || busy} onClick={() => act(`/users/${id}/roles`, 'POST', { custom_role_id: Number(roleId) }).then(() => setRoleId(''))}>Add</Button>
          </div>
        )}
      </details>

      <details className="mt-3">
        <summary className="cursor-pointer text-sm font-semibold text-brand">Sign-in history</summary>
        {logins.length === 0 ? <p className="mt-2 text-sm text-ink-muted">No sign-ins recorded.</p> : (
          <ul className="mt-2 space-y-1 text-xs">{logins.map((l) => <li key={l.id}>{when(l.login_at)} · {l.status === 'failed' ? <Badge tone="red">failed</Badge> : null} {l.ip_address ?? '—'} <span className="text-ink-muted">{(l.user_agent ?? '').slice(0, 50)}</span></li>)}</ul>
        )}
      </details>
      <details className="mt-3">
        <summary className="cursor-pointer text-sm font-semibold text-brand">Account activity</summary>
        {timeline.length === 0 ? <p className="mt-2 text-sm text-ink-muted">Nothing recorded.</p> : (
          <ul className="mt-2 space-y-1 text-xs">{timeline.map((t) => <li key={t.id}>{when(t.created_at)} · {t.action.replaceAll('_', ' ').replaceAll('.', ' · ')}</li>)}</ul>
        )}
      </details>
    </Card>
  );
}

function Invite({ onDone, onError }: H) {
  const [f, setF] = useState({ name: '', email: '', role: 'teacher', phone: '' });
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<{ imported_count: number; errors: string[] } | null>(null);
  const [busy, setBusy] = useState(false);

  async function invite(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await fetchApi<{ message: string }>('/auth/invite', { method: 'POST', body: JSON.stringify({ ...f, phone: f.phone || null }) });
      onDone(r.message);
      setF({ ...f, name: '', email: '', phone: '' });
    } catch (err) { onError(err, 'Could not invite them.'); } finally { setBusy(false); }
  }

  async function importCsv(e: React.FormEvent) {
    e.preventDefault();
    if (!file) return;
    setBusy(true);
    const form = new FormData();
    form.append('file', file);
    try {
      const r = await uploadApi<{ message: string; imported_count: number; errors: string[] }>('/users/bulk-import', form);
      setResult(r);
      onDone(r.message);
    } catch (err) { onError(err, 'Could not import the file.'); } finally { setBusy(false); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card title="Invite one person" description="They get an email with a link to set their own password. Students are better added through Students, with their class.">
        <form className="space-y-3" onSubmit={invite}>
          <Field label="Full name"><input className={inputClass} required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          <Field label="Email"><input className={inputClass} type="email" required value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
          <Field label="Role"><select className={inputClass} value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })}>{ROLES.map((r) => <option key={r} value={r}>{label(r)}</option>)}</select></Field>
          <Field label="Phone (optional)"><input className={inputClass} inputMode="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
          <Button type="submit" variant="accent" disabled={busy}>Send invitation</Button>
        </form>
      </Card>
      <Card title="Add many from a spreadsheet" description="A CSV with columns: name, email, role, phone. Roles: school_admin, teacher, bursar, nurse, student, parent. Everyone then uses “Forgot password” to set theirs.">
        <form className="space-y-3" onSubmit={importCsv}>
          <input type="file" accept=".csv,text/csv" required className="block text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          <Button type="submit" disabled={busy || !file}>Import</Button>
        </form>
        {result && result.errors.length > 0 && (
          <div className="mt-4 text-sm">
            <p className="font-semibold">{result.errors.length} row(s) not imported</p>
            <ul className="mt-1 max-h-48 list-disc overflow-y-auto pl-5 text-xs text-negative">{result.errors.map((e) => <li key={e}>{e}</li>)}</ul>
          </div>
        )}
      </Card>
    </div>
  );
}

function Roles({ onDone, onError }: H) {
  const [roles, setRoles] = useState<Role[] | null>(null);
  const [f, setF] = useState({ id: 0, name: '', slug: '', description: '' });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try { setRoles((await fetchApi<{ data: Role[] }>('/roles')).data); } catch (err) { onError(err, 'Could not load roles.'); }
  }, [onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const body = { name: f.name, slug: f.slug || f.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''), description: f.description || null, permissions: [] };
      const r = await fetchApi<{ message: string }>(f.id ? `/roles/${f.id}` : '/roles', { method: f.id ? 'PUT' : 'POST', body: JSON.stringify(body) });
      onDone(r.message);
      setF({ id: 0, name: '', slug: '', description: '' });
      await load();
    } catch (err) { onError(err, 'Could not save the role.'); } finally { setBusy(false); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
      <Card description="Extra titles you give people — Exams officer, Head of science, House master. They are labels for your records: what someone can open is decided by their main role (admin, teacher, bursar…).">
        {!roles ? <Loading /> : roles.length === 0 ? <Empty>No custom roles yet.</Empty> : (
          <Table columns={['Role', 'Description', 'People', '']}>
            {roles.map((r) => (
              <tr key={r.id}>
                <Cell className="font-medium">{r.name}</Cell>
                <Cell className="text-sm text-ink-muted">{r.description}</Cell>
                <Cell>{r.users_count}</Cell>
                <Cell className="space-x-2 whitespace-nowrap">
                  <button type="button" className="text-sm text-brand underline" onClick={() => setF({ id: r.id, name: r.name, slug: r.slug, description: r.description ?? '' })}>Edit</button>
                  <button type="button" className="text-sm text-negative underline" onClick={async () => { if (!window.confirm(`Delete "${r.name}"?`)) return; try { await fetchApi(`/roles/${r.id}`, { method: 'DELETE' }); onDone('Deleted.'); await load(); } catch (err) { onError(err, 'Could not delete it.'); } }}>Delete</button>
                </Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>
      <Card title={f.id ? 'Edit role' : 'New role'}>
        <form className="space-y-3" onSubmit={save}>
          <Field label="Name"><input className={inputClass} required maxLength={100} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="Exams officer" /></Field>
          <Field label="Description"><textarea className={inputClass} maxLength={500} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Field>
          <div className="flex gap-2"><Button type="submit" disabled={busy}>Save</Button>{f.id > 0 && <Button type="button" variant="secondary" onClick={() => setF({ id: 0, name: '', slug: '', description: '' })}>Cancel</Button>}</div>
        </form>
      </Card>
    </div>
  );
}
