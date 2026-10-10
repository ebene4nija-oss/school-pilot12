'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * The offline CBT lab client's releases. Each installer is hosted elsewhere
 * (GitHub Releases, or a file on the server) and registered here by link,
 * version and SHA-256. The site's download button points at one stable link
 * that always serves the highest published version, so publishing a release
 * updates every download button at once, with no site rebuild. Installed
 * clients check the same record when they next go online.
 */

interface Release {
  id: number; version: string; download_url: string; sha256: string; size_bytes: number | null; notes: string | null;
  is_required: boolean; status: 'draft' | 'published' | 'retired'; published_at: string | null; downloads: number;
  is_current?: boolean;
}

const blank = { id: null as number | null, version: '', download_url: '', sha256: '', size_mb: '', notes: '', is_required: false };

const STATUS_TONE = { draft: 'slate', published: 'green', retired: 'amber' } as const;

const mb = (bytes: number | null) => (bytes ? `${(bytes / 1048576).toFixed(1)} MB` : '—');

export default function DownloadsAdminPage() {
  const [items, setItems] = useState<Release[] | null>(null);
  const [link, setLink] = useState('');
  const [form, setForm] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ data: Release[]; download_link: string }>('/platform/downloads/offline-cbt');
      setItems(res.data);
      setLink(res.download_link);
    } catch (err) {
      setError(errorText(err, 'Could not load the releases.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const locked = form.id !== null && items?.find((r) => r.id === form.id)?.status !== 'draft';

  async function save(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    // Two submit buttons, so the browser checks the fields for either one.
    const publish = (e.nativeEvent as SubmitEvent).submitter?.dataset.publish === '1';
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const body: Record<string, unknown> = {
        notes: form.notes.trim() || null,
        is_required: form.is_required,
        size_bytes: form.size_mb ? Math.round(Number(form.size_mb) * 1048576) : null,
      };
      if (!locked) Object.assign(body, { version: form.version.trim(), download_url: form.download_url.trim(), sha256: form.sha256.trim() });
      if (publish) body.status = 'published';
      await fetchApi(form.id ? `/platform/downloads/offline-cbt/${form.id}` : '/platform/downloads/offline-cbt', {
        method: form.id ? 'PUT' : 'POST',
        body: JSON.stringify(body),
      });
      setNotice(publish ? `Version ${form.version} is live. Every download button now serves it.` : 'Saved as a draft. Nobody can download it until you publish it.');
      setForm(blank);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not save.'));
    } finally {
      setBusy(false);
    }
  }

  async function act(r: Release, method: 'PUT' | 'DELETE', body: unknown, done: string) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      await fetchApi(`/platform/downloads/offline-cbt/${r.id}`, { method, body: body ? JSON.stringify(body) : undefined });
      setNotice(done);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not save.'));
    } finally {
      setBusy(false);
    }
  }

  const edit = (r: Release) =>
    setForm({ id: r.id, version: r.version, download_url: r.download_url, sha256: r.sha256, size_mb: r.size_bytes ? (r.size_bytes / 1048576).toFixed(1) : '', notes: r.notes ?? '', is_required: r.is_required });

  return (
    <PageShell
      title="Downloads"
      subtitle="The offline CBT lab client schools download from the website. Publish a new version and every download button serves it straight away; installed clients are told about it the next time they go online."
    >
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {link && (
        <Card title="The download link" description="This link never changes. It is what the website, WhatsApp messages and the runbook should use.">
          <div className="flex flex-wrap items-center gap-2">
            <code className="break-all rounded bg-surface-sunken px-3 py-2 text-sm">{link}</code>
            <Button variant="secondary" onClick={() => void navigator.clipboard?.writeText(link).then(() => setNotice('Link copied.'))}>Copy</Button>
          </div>
        </Card>
      )}

      <div className="mt-5 grid gap-5 xl:grid-cols-[1fr_26rem]">
        <Card title="Releases">
          {!items ? <Loading /> : items.length === 0 ? (
            <Empty>No releases yet. Until one is published, the website&apos;s download page says the client is on its way and offers a demo instead.</Empty>
          ) : (
            <Table columns={['Version', 'Status', 'Size', 'Downloads', '']}>
              {items.map((r) => (
                <tr key={r.id} className={r.status === 'retired' ? 'opacity-60' : ''}>
                  <Cell className="font-medium">
                    {r.version}
                    {r.is_current && <span className="ml-2"><Badge tone="blue">Served now</Badge></span>}
                    {r.is_required && <span className="ml-2"><Badge tone="red">Required</Badge></span>}
                    <span className="block max-w-xs truncate text-xs text-ink-muted" title={r.download_url}>{r.download_url}</span>
                    {r.published_at && <span className="block text-xs text-ink-muted">Published {new Date(r.published_at).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' })}</span>}
                  </Cell>
                  <Cell><Badge tone={STATUS_TONE[r.status]}>{r.status}</Badge></Cell>
                  <Cell>{mb(r.size_bytes)}</Cell>
                  <Cell>{r.downloads}</Cell>
                  <Cell>
                    <div className="flex flex-wrap gap-2">
                      <Button variant="secondary" onClick={() => edit(r)}>Edit</Button>
                      {r.status === 'draft' && (
                        <>
                          <Button disabled={busy} onClick={() => { if (window.confirm(`Publish ${r.version}? If it is the highest version, every download button serves it at once.`)) void act(r, 'PUT', { status: 'published' }, `Version ${r.version} published.`); }}>Publish</Button>
                          <Button variant="danger" disabled={busy} onClick={() => { if (window.confirm(`Delete the draft ${r.version}?`)) void act(r, 'DELETE', null, 'Draft deleted.'); }}>Delete</Button>
                        </>
                      )}
                      {r.status === 'published' && (
                        <Button variant="danger" disabled={busy} onClick={() => { if (window.confirm(`Retire ${r.version}? The link falls back to the highest version still published.`)) void act(r, 'PUT', { status: 'retired' }, `Version ${r.version} retired.`); }}>Retire</Button>
                      )}
                      {r.status === 'retired' && (
                        <Button variant="secondary" disabled={busy} onClick={() => void act(r, 'PUT', { status: 'published' }, `Version ${r.version} published again.`)}>Restore</Button>
                      )}
                    </div>
                  </Cell>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        <Card title={form.id ? `Edit ${form.version}` : 'Add a release'}>
          <form className="space-y-3" onSubmit={(e) => void save(e)}>
            <Field label="Version">
              <input className={inputClass} required disabled={locked} pattern="\d+\.\d+\.\d+" placeholder="1.0.0" value={form.version} onChange={(e) => setForm({ ...form, version: e.target.value })} />
              <span className="mt-1 block text-xs text-ink-muted">The version in desktop/exam-app/Cargo.toml; Build-Package.ps1 prints it with the SHA-256.</span>
            </Field>
            <Field label="Installer link (https)">
              <input className={inputClass} type="url" required disabled={locked} placeholder="https://github.com/…/SchoolPilot-Setup-1.0.0.exe" value={form.download_url} onChange={(e) => setForm({ ...form, download_url: e.target.value })} />
              <span className="mt-1 block text-xs text-ink-muted">Where the .exe is hosted: a GitHub release, or a file uploaded to the server by FTP. Installers can&apos;t be uploaded here.</span>
            </Field>
            <Field label="SHA-256 checksum">
              <input className={`${inputClass} font-mono text-xs`} required disabled={locked} placeholder="64 characters" value={form.sha256} onChange={(e) => setForm({ ...form, sha256: e.target.value })} />
              <span className="mt-1 block text-xs text-ink-muted">In PowerShell: <code>Get-FileHash .\SchoolPilot-Setup-1.0.0.exe</code>. Shown on the site so schools can check their copy.</span>
            </Field>
            <Field label="Size in MB (optional)">
              <input className={inputClass} type="number" min={0} step="0.1" value={form.size_mb} onChange={(e) => setForm({ ...form, size_mb: e.target.value })} />
            </Field>
            <Field label="What's new (optional)">
              <textarea className={inputClass} rows={4} maxLength={3000} placeholder="Shown on the download page and to installed clients." value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
            </Field>
            <label className="flex items-start gap-2 text-sm">
              <input type="checkbox" className="mt-1" checked={form.is_required} onChange={(e) => setForm({ ...form, is_required: e.target.checked })} />
              <span>Required update: clients older than this are told to update before their next exam. Use for fixes that affect marking or saved answers.</span>
            </label>
            {locked && <p className="text-xs text-ink-muted">A published release keeps its version, link and checksum. For a new file, add a new release.</p>}
            <div className="flex flex-wrap gap-2">
              <Button type="submit" variant="secondary" disabled={busy}>{form.id ? 'Save' : 'Save as draft'}</Button>
              {!locked && <Button type="submit" data-publish="1" disabled={busy}>Publish</Button>}
              {form.id && <Button type="button" variant="secondary" onClick={() => setForm(blank)}>Cancel</Button>}
            </div>
          </form>
        </Card>
      </div>
    </PageShell>
  );
}
