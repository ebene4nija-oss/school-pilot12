'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * The partner sales kit: brochures, social images, message scripts and
 * links. Published items appear on every partner's page; scripts can carry
 * {link}, {code} and {name}, filled in with each partner's own details.
 */

interface Material {
  id: number; title: string; description: string | null; category: string; kind: 'file' | 'text' | 'link';
  file_name: string | null; mime: string | null; size_bytes: number | null; url: string | null; body: string | null;
  sort: number; is_published: boolean; updated_at: string | null;
}

const CATEGORIES: Record<string, string> = { brochure: 'Brochure', script: 'Message script', social: 'Social image', video: 'Video', other: 'Other' };
const KINDS: Record<string, string> = { file: 'File', text: 'Text / script', link: 'Link' };

const blank = { id: null as number | null, title: '', description: '', category: 'brochure', kind: 'file' as Material['kind'], body: '', url: '', sort: '', is_published: true };

export default function PartnerMaterialsAdminPage() {
  const [items, setItems] = useState<Material[] | null>(null);
  const [form, setForm] = useState(blank);
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      setItems((await fetchApi<{ data: Material[] }>('/platform/partner-materials')).data);
    } catch (err) {
      setError(errorText(err, 'Could not load partner materials.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const fields: Record<string, string> = {
        title: form.title, description: form.description, category: form.category, kind: form.kind,
        is_published: form.is_published ? '1' : '0',
      };
      if (form.sort !== '') fields.sort = form.sort;
      if (form.kind === 'text') fields.body = form.body;
      if (form.kind === 'link') fields.url = form.url;

      if (file || (form.kind === 'file' && !form.id)) {
        const data = new FormData();
        Object.entries(fields).forEach(([k, v]) => data.append(k, v));
        if (file) data.append('file', file);
        await uploadApi(form.id ? `/platform/partner-materials/${form.id}` : '/platform/partner-materials', data);
      } else {
        await fetchApi(form.id ? `/platform/partner-materials/${form.id}` : '/platform/partner-materials', {
          method: form.id ? 'PUT' : 'POST',
          body: JSON.stringify({ ...fields, is_published: form.is_published, sort: form.sort === '' ? undefined : Number(form.sort) }),
        });
      }
      setNotice(form.id ? 'Saved.' : 'Added. Partners see it on their page now.');
      setForm(blank);
      setFile(null);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not save.'));
    } finally {
      setBusy(false);
    }
  }

  async function act(path: string, method: 'PUT' | 'DELETE', body: unknown, done: string) {
    setBusy(true);
    setError(null);
    try {
      await fetchApi(path, { method, body: body ? JSON.stringify(body) : undefined });
      setNotice(done);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not save.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell
      title="Partner materials"
      subtitle="Brochures, social images and message scripts partners use to introduce SchoolPilot. Published items show on every partner's page."
      actions={<Link href="/super-admin/partners"><Button variant="secondary">Partners</Button></Link>}
    >
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <div className="grid gap-5 xl:grid-cols-[1fr_24rem]">
        <Card>
          {!items ? <Loading /> : items.length === 0 ? (
            <Empty>Nothing yet. Add the feature guide and a WhatsApp script on the right, or run <code>php artisan partners:seed-materials</code> for the starter kit.</Empty>
          ) : (
            <Table columns={['Material', 'Kind', 'Order', 'Shown', '']}>
              {items.map((m) => (
                <tr key={m.id} className={m.is_published ? '' : 'opacity-60'}>
                  <Cell className="font-medium">
                    {m.title}
                    <span className="block text-xs text-ink-muted">{CATEGORIES[m.category] ?? m.category}{m.file_name ? ` · ${m.file_name}` : ''}</span>
                  </Cell>
                  <Cell>
                    {m.kind === 'file'
                      ? <a className="text-sm text-brand underline" href={`/api/backend/platform/partner-materials/${m.id}/download`}>{KINDS[m.kind]}</a>
                      : KINDS[m.kind]}
                  </Cell>
                  <Cell>{m.sort}</Cell>
                  <Cell>
                    <button type="button" disabled={busy} onClick={() => void act(`/platform/partner-materials/${m.id}`, 'PUT', { is_published: !m.is_published }, m.is_published ? 'Hidden from partners.' : 'Shown to partners.')}>
                      <Badge tone={m.is_published ? 'green' : 'slate'}>{m.is_published ? 'Published' : 'Hidden'}</Badge>
                    </button>
                  </Cell>
                  <Cell>
                    <div className="flex gap-2">
                      <Button variant="secondary" onClick={() => { setForm({ id: m.id, title: m.title, description: m.description ?? '', category: m.category, kind: m.kind, body: m.body ?? '', url: m.url ?? '', sort: String(m.sort), is_published: m.is_published }); setFile(null); }}>Edit</Button>
                      <Button variant="danger" disabled={busy} onClick={() => { if (window.confirm(`Remove “${m.title}”? Partners stop seeing it.`)) void act(`/platform/partner-materials/${m.id}`, 'DELETE', null, 'Removed.'); }}>Remove</Button>
                    </div>
                  </Cell>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        <Card title={form.id ? 'Edit material' : 'Add material'}>
          <form className="space-y-3" onSubmit={(e) => void save(e)}>
            <Field label="Title"><input className={inputClass} required maxLength={160} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Feature guide for schools" /></Field>
            <Field label="Short description (optional)"><input className={inputClass} maxLength={500} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="When to use it" /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Section">
                <select className={inputClass} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
                  {Object.entries(CATEGORIES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              </Field>
              <Field label="Kind">
                <select className={inputClass} value={form.kind} disabled={!!form.id} onChange={(e) => setForm({ ...form, kind: e.target.value as Material['kind'] })}>
                  {Object.entries(KINDS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              </Field>
            </div>
            {form.kind === 'file' && (
              <Field label={form.id ? 'Replace the file (optional)' : 'File'}>
                <input type="file" required={!form.id} accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.mp4,.docx,.pptx,.xlsx,.zip" className="block w-full text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                <span className="mt-1 block text-xs text-ink-muted">PDF, image, MP4, Office document or ZIP, up to 50 MB.</span>
              </Field>
            )}
            {form.kind === 'text' && (
              <Field label="Message">
                <textarea className={inputClass} rows={8} required maxLength={5000} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
                <span className="mt-1 block text-xs text-ink-muted">{'{link}'} becomes the partner&apos;s referral link, {'{code}'} their code, {'{name}'} their name.</span>
              </Field>
            )}
            {form.kind === 'link' && (
              <Field label="Link (https)"><input className={inputClass} type="url" required value={form.url} onChange={(e) => setForm({ ...form, url: e.target.value })} placeholder="https://youtube.com/…" /></Field>
            )}
            <div className="grid grid-cols-2 gap-3">
              <Field label="Order"><input className={inputClass} type="number" min={0} value={form.sort} onChange={(e) => setForm({ ...form, sort: e.target.value })} placeholder="0 = first" /></Field>
              <label className="flex items-end gap-2 pb-2 text-sm"><input type="checkbox" checked={form.is_published} onChange={(e) => setForm({ ...form, is_published: e.target.checked })} /> Show to partners</label>
            </div>
            <div className="flex gap-2">
              <Button type="submit" disabled={busy}>{form.id ? 'Save' : 'Add'}</Button>
              {form.id && <Button type="button" variant="secondary" onClick={() => { setForm(blank); setFile(null); }}>Cancel</Button>}
            </div>
          </form>
        </Card>
      </div>
    </PageShell>
  );
}
