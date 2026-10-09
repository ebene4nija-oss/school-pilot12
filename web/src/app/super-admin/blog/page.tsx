'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * The marketing site's blog (operator side).
 *
 * Posts are Markdown. A video is a YouTube or Vimeo link: the site embeds it
 * click-to-load, so nothing heavy is stored here or downloaded by a visitor
 * until they press play. A post goes live on the public site at its next
 * rebuild, like everything under Website.
 */

interface PostRow {
  id: number;
  slug: string;
  title: string;
  status: 'draft' | 'published';
  published_at: string | null;
  updated_at: string;
  cover_url: string | null;
}

interface Post extends PostRow {
  excerpt: string;
  body: string;
  cover_alt: string | null;
  video_url: string | null;
  video: { provider: string; id: string } | null;
  meta_title: string | null;
  meta_description: string | null;
  author_name: string;
}

interface Draft {
  title: string;
  slug: string;
  excerpt: string;
  body: string;
  cover_alt: string;
  video_url: string;
  meta_title: string;
  meta_description: string;
  author_name: string;
  status: 'draft' | 'published';
}

const blank: Draft = {
  title: '',
  slug: '',
  excerpt: '',
  body: '',
  cover_alt: '',
  video_url: '',
  meta_title: '',
  meta_description: '',
  author_name: 'SchoolPilot team',
  status: 'draft',
};

const toDraft = (p: Post): Draft => ({
  title: p.title,
  slug: p.slug,
  excerpt: p.excerpt,
  body: p.body,
  cover_alt: p.cover_alt ?? '',
  video_url: p.video_url ?? '',
  meta_title: p.meta_title ?? '',
  meta_description: p.meta_description ?? '',
  author_name: p.author_name,
  status: p.status,
});

function Counter({ value, max }: { value: string; max: number }) {
  return <span className={`text-xs ${value.length > max ? 'text-negative' : 'text-ink-muted'}`}>{value.length}/{max}</span>;
}

export default function BlogAdminPage() {
  const [rows, setRows] = useState<PostRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [editing, setEditing] = useState<{ id: number | null; cover: string | null } | null>(null);
  const [draft, setDraft] = useState<Draft>(blank);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const res = await fetchApi<{ data: PostRow[] }>('/platform/blog');
    setRows(res.data);
  }, []);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (err) {
        setError(errorText(err, 'Could not load the posts.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  const set = (patch: Partial<Draft>) => setDraft((d) => ({ ...d, ...patch }));

  async function open(id: number | 'new') {
    setError(null);
    setNotice(null);
    if (id === 'new') {
      setDraft(blank);
      setEditing({ id: null, cover: null });
      return;
    }
    try {
      const res = await fetchApi<{ data: Post }>(`/platform/blog/${id}`);
      setDraft(toDraft(res.data));
      setEditing({ id, cover: res.data.cover_url });
    } catch (err) {
      setError(errorText(err, 'Could not open that post.'));
    }
  }

  async function save() {
    if (!editing) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const body = JSON.stringify(draft);
      const res =
        editing.id === null
          ? await fetchApi<{ data: Post }>('/platform/blog', { method: 'POST', body })
          : await fetchApi<{ data: Post }>(`/platform/blog/${editing.id}`, { method: 'PUT', body });
      setDraft(toDraft(res.data));
      setEditing({ id: res.data.id, cover: res.data.cover_url });
      setNotice(
        res.data.status === 'published'
          ? 'Saved. It shows on the site after the next rebuild.'
          : 'Saved as a draft. Drafts never appear on the site.',
      );
      await load();
    } catch (err) {
      setError(errorText(err, 'That post could not be saved.'));
    } finally {
      setBusy(false);
    }
  }

  async function uploadCover(file: File) {
    if (editing?.id == null) return;
    setBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.append('image', file);
      const res = await uploadApi<{ data: Post }>(`/platform/blog/${editing.id}/cover`, form);
      setEditing({ id: editing.id, cover: res.data.cover_url });
      await load();
    } catch (err) {
      setError(errorText(err, 'That image could not be saved.'));
    } finally {
      setBusy(false);
    }
  }

  async function removeCover() {
    if (editing?.id == null) return;
    setBusy(true);
    try {
      await fetchApi(`/platform/blog/${editing.id}/cover`, { method: 'DELETE' });
      setEditing({ id: editing.id, cover: null });
      await load();
    } catch (err) {
      setError(errorText(err, 'That image could not be removed.'));
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (editing?.id == null || !window.confirm('Delete this post for good? It disappears from the site at the next rebuild.')) return;
    setBusy(true);
    try {
      await fetchApi(`/platform/blog/${editing.id}`, { method: 'DELETE' });
      setEditing(null);
      await load();
    } catch (err) {
      setError(errorText(err, 'That post could not be deleted.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell
      title="Blog"
      subtitle="Guides for the public site. Write in Markdown; add a YouTube or Vimeo link for video. Changes appear after the next site rebuild."
      actions={
        !editing && (
          <Button variant="accent" onClick={() => void open('new')}>
            New post
          </Button>
        )
      }
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {editing ? (
        <Card title={editing.id === null ? 'New post' : 'Edit post'}>
          <div className="grid gap-4 lg:grid-cols-2">
            <div className="space-y-4 lg:col-span-2">
              <Field label="Title">
                <input className={inputClass} value={draft.title} maxLength={160} onChange={(e) => set({ title: e.target.value })} />
              </Field>
              <Field label="Summary (shown in the post list and search results)">
                <textarea className={inputClass} rows={2} maxLength={300} value={draft.excerpt} onChange={(e) => set({ excerpt: e.target.value })} />
              </Field>
              <Field label="Body (Markdown: ## headings, - lists, **bold**, [links](https://…))">
                <textarea
                  className={`${inputClass} font-mono`}
                  rows={18}
                  value={draft.body}
                  onChange={(e) => set({ body: e.target.value })}
                />
              </Field>
            </div>

            <Field label="Video link (YouTube or Vimeo, optional)">
              <input
                className={inputClass}
                inputMode="url"
                placeholder="https://www.youtube.com/watch?v=…"
                value={draft.video_url}
                onChange={(e) => set({ video_url: e.target.value })}
              />
            </Field>
            <Field label="Author name">
              <input className={inputClass} value={draft.author_name} maxLength={80} onChange={(e) => set({ author_name: e.target.value })} />
            </Field>

            <div className="lg:col-span-2">
              <p className="mb-1 text-sm font-medium text-ink">Cover image</p>
              {editing.id === null ? (
                <p className="text-sm text-ink-muted">Save the post first, then add a cover.</p>
              ) : (
                <div className="flex flex-wrap items-center gap-4">
                  {editing.cover && (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={editing.cover} alt="" className="h-24 rounded-lg border border-line-soft object-cover" />
                  )}
                  <label className={`cursor-pointer ${busy ? 'pointer-events-none opacity-60' : ''}`}>
                    <span className="inline-flex rounded-lg border border-brand px-3 py-1.5 text-sm font-semibold text-brand hover:bg-surface-sunken">
                      {editing.cover ? 'Replace' : 'Upload'}
                    </span>
                    <input
                      type="file"
                      accept="image/png,image/jpeg,image/webp"
                      className="sr-only"
                      onChange={(e) => {
                        const file = e.target.files?.[0];
                        e.target.value = '';
                        if (file) void uploadCover(file);
                      }}
                    />
                  </label>
                  {editing.cover && (
                    <Button variant="secondary" disabled={busy} onClick={() => void removeCover()}>
                      Remove
                    </Button>
                  )}
                  <p className="text-xs text-ink-muted">At least 1200 × 630. It is also the preview shown when the post is shared, and the video thumbnail.</p>
                </div>
              )}
            </div>
            <div className="lg:col-span-2">
              <Field label="Cover description (for screen readers and search)">
                <input className={inputClass} value={draft.cover_alt} maxLength={200} onChange={(e) => set({ cover_alt: e.target.value })} />
              </Field>
            </div>

            <div className="space-y-4 lg:col-span-2">
              <p className="text-sm font-semibold text-brand">Search engines</p>
              <Field label="Page title (optional, otherwise the post title)">
                <input className={inputClass} value={draft.meta_title} maxLength={70} onChange={(e) => set({ meta_title: e.target.value })} />
                <Counter value={draft.meta_title} max={60} />
              </Field>
              <Field label="Description (optional, otherwise the summary)">
                <textarea className={inputClass} rows={2} maxLength={170} value={draft.meta_description} onChange={(e) => set({ meta_description: e.target.value })} />
                <Counter value={draft.meta_description} max={160} />
              </Field>
              <Field label="Web address ending (leave empty to make it from the title)">
                <input className={inputClass} value={draft.slug} placeholder="how-to-compute-ca" onChange={(e) => set({ slug: e.target.value.toLowerCase() })} />
              </Field>
            </div>

            <Field label="Status">
              <select className={inputClass} value={draft.status} onChange={(e) => set({ status: e.target.value as Draft['status'] })}>
                <option value="draft">Draft: not on the site</option>
                <option value="published">Published</option>
              </select>
            </Field>
          </div>

          <div className="mt-6 flex flex-wrap gap-3">
            <Button disabled={busy || !draft.title || !draft.excerpt || !draft.body} onClick={() => void save()}>
              {busy ? 'Saving…' : 'Save'}
            </Button>
            <Button variant="secondary" disabled={busy} onClick={() => setEditing(null)}>
              Back to posts
            </Button>
            {editing.id !== null && (
              <Button variant="danger" disabled={busy} className="ml-auto" onClick={() => void remove()}>
                Delete
              </Button>
            )}
          </div>
        </Card>
      ) : loading ? (
        <Loading />
      ) : rows.length === 0 ? (
        <Empty>No posts yet. Write the first one.</Empty>
      ) : (
        <Table columns={['Post', 'Status', 'Published', 'Updated']}>
          {rows.map((r) => (
            <tr key={r.id} className="cursor-pointer hover:bg-surface-sunken" onClick={() => void open(r.id)}>
              <Cell>
                <span className="font-medium text-brand">{r.title}</span>
                <span className="block text-xs text-ink-muted">/blog/{r.slug}/</span>
              </Cell>
              <Cell>
                <Badge tone={r.status === 'published' ? 'green' : 'slate'}>{r.status === 'published' ? 'Published' : 'Draft'}</Badge>
              </Cell>
              <Cell>{r.published_at ? new Date(r.published_at).toLocaleDateString('en-NG') : '—'}</Cell>
              <Cell>{new Date(r.updated_at).toLocaleDateString('en-NG')}</Cell>
            </tr>
          ))}
        </Table>
      )}
    </PageShell>
  );
}
