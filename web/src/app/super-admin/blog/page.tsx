'use client';

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Empty, ErrorNote, Loading, PageShell, inputClass } from '@/components/Page';
import RichEditor from '@/components/blog/RichEditor';

/**
 * The marketing site's blog (operator side), laid out like WordPress: the
 * post in the middle, written visually, and a sidebar with publishing,
 * category and tags, featured image, summary, video and search settings.
 *
 * Posts are stored as Markdown, which the site renders. A video is a YouTube
 * or Vimeo link, embedded click-to-load. A post (or a scheduled one, once its
 * date has passed) goes live on the public site at its next rebuild.
 */

interface PostRow {
  id: number;
  slug: string;
  title: string;
  status: 'draft' | 'published';
  published_at: string | null;
  updated_at: string;
  cover_url: string | null;
  category: string | null;
}

interface Post extends PostRow {
  excerpt: string;
  body: string;
  cover_alt: string | null;
  video_url: string | null;
  meta_title: string | null;
  meta_description: string | null;
  author_name: string;
  tags: string[];
  reading_minutes: number;
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
  category: string;
  tags: string[];
  /** datetime-local value; empty = now (or unchanged). */
  publish_at: string;
}

const blank: Draft = {
  title: '', slug: '', excerpt: '', body: '', cover_alt: '', video_url: '', meta_title: '', meta_description: '',
  author_name: 'SchoolPilot team', status: 'draft', category: '', tags: [], publish_at: '',
};

/** ISO → the value a datetime-local input wants, in the browser's time zone. */
function toLocalInput(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const toDraft = (p: Post): Draft => ({
  title: p.title, slug: p.slug, excerpt: p.excerpt, body: p.body, cover_alt: p.cover_alt ?? '', video_url: p.video_url ?? '',
  meta_title: p.meta_title ?? '', meta_description: p.meta_description ?? '', author_name: p.author_name, status: p.status,
  category: p.category ?? '', tags: p.tags ?? [], publish_at: toLocalInput(p.published_at),
});

const NEW_KEY = 'sp-blog-new-draft';
const when = (iso: string) => new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const isFuture = (local: string) => !!local && new Date(local).getTime() > Date.now();

function rowState(r: PostRow): { label: string; tone: 'green' | 'slate' | 'blue' } {
  if (r.status === 'draft') return { label: 'Draft', tone: 'slate' };
  if (r.published_at && new Date(r.published_at).getTime() > Date.now()) return { label: 'Scheduled', tone: 'blue' };
  return { label: 'Published', tone: 'green' };
}

export default function BlogAdminPage() {
  const [rows, setRows] = useState<PostRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [editing, setEditing] = useState<{ id: number | null; cover: string | null; key: number } | null>(null);
  const [draft, setDraft] = useState<Draft>(blank);
  const [html, setHtml] = useState('');
  const [words, setWords] = useState(0);
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [savedAt, setSavedAt] = useState<Date | null>(null);
  const [preview, setPreview] = useState(false);
  const [filter, setFilter] = useState<'all' | 'published' | 'draft' | 'scheduled'>('all');
  const [search, setSearch] = useState('');

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

  const categories = useMemo(() => Array.from(new Set(rows.map((r) => r.category).filter(Boolean) as string[])).sort(), [rows]);

  const set = (patch: Partial<Draft>) => {
    setDraft((d) => ({ ...d, ...patch }));
    setDirty(true);
  };

  async function open(id: number | 'new') {
    setError(null);
    setNotice(null);
    setPreview(false);
    if (id === 'new') {
      // A new post's unsaved text survives a closed tab, on this browser.
      let restored: Draft | null = null;
      try {
        const raw = localStorage.getItem(NEW_KEY);
        restored = raw ? ({ ...blank, ...JSON.parse(raw) } as Draft) : null;
      } catch {
        restored = null;
      }
      setDraft(restored ?? blank);
      setDirty(!!restored);
      setSavedAt(null);
      setEditing({ id: null, cover: null, key: Date.now() });
      if (restored) setNotice('We restored the post you had not saved yet.');
      return;
    }
    try {
      const res = await fetchApi<{ data: Post }>(`/platform/blog/${id}`);
      setDraft(toDraft(res.data));
      setDirty(false);
      setSavedAt(new Date(res.data.updated_at));
      setEditing({ id, cover: res.data.cover_url, key: Date.now() });
    } catch (err) {
      setError(errorText(err, 'Could not open that post.'));
    }
  }

  const save = useCallback(
    async (status: Draft['status'], quiet = false) => {
      if (!editing) return false;
      if (!draft.title.trim() || !draft.body.trim()) {
        if (!quiet) setError('Give the post a title and some text first.');
        return false;
      }
      setBusy(true);
      if (!quiet) {
        setError(null);
        setNotice(null);
      }
      try {
        // The site lists posts by their summary; one is made from the text if left empty.
        const firstParagraph =
          draft.body
            .split(/\n{2,}/)
            .map((b) => b.trim())
            .find((b) => b && !/^(#|>|[-*+] |\d+\. |\||!\[|---)/.test(b)) ?? '';
        const excerpt =
          draft.excerpt.trim() ||
          firstParagraph.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/[*_`]/g, '').replace(/\s+/g, ' ').trim().slice(0, 240) ||
          draft.title.trim();
        const payload = {
          ...draft,
          excerpt,
          status,
          publish_at: draft.publish_at ? new Date(draft.publish_at).toISOString() : null,
          category: draft.category || null,
        };
        const body = JSON.stringify(payload);
        const res =
          editing.id === null
            ? await fetchApi<{ data: Post }>('/platform/blog', { method: 'POST', body })
            : await fetchApi<{ data: Post }>(`/platform/blog/${editing.id}`, { method: 'PUT', body });
        setDraft((d) => ({ ...d, status: res.data.status, slug: res.data.slug, excerpt: res.data.excerpt, publish_at: toLocalInput(res.data.published_at) }));
        setEditing((e) => (e ? { ...e, id: res.data.id, cover: res.data.cover_url } : e));
        setDirty(false);
        setSavedAt(new Date());
        try {
          localStorage.removeItem(NEW_KEY);
        } catch {
          // Storage unavailable: nothing to clear.
        }
        if (!quiet) {
          const future = res.data.published_at && new Date(res.data.published_at).getTime() > Date.now();
          setNotice(
            status === 'draft'
              ? 'Draft saved. Drafts never appear on the site.'
              : future
                ? `Scheduled for ${when(res.data.published_at!)}. It appears at the first site rebuild after that.`
                : 'Published. It shows on the site after the next rebuild.',
          );
        }
        await load();
        return true;
      } catch (err) {
        if (!quiet) setError(errorText(err, 'That post could not be saved.'));
        return false;
      } finally {
        setBusy(false);
      }
    },
    [draft, editing, load],
  );

  // Autosave: drafts save themselves a few seconds after you stop typing.
  // A published post is never changed behind your back; it waits for Update.
  const saveRef = useRef(save);
  useEffect(() => {
    saveRef.current = save;
  }, [save]);
  useEffect(() => {
    if (!editing || !dirty) return;
    if (editing.id === null) {
      try {
        localStorage.setItem(NEW_KEY, JSON.stringify(draft));
      } catch {
        // Private window: no local backup.
      }
      return;
    }
    if (draft.status !== 'draft') return;
    const t = window.setTimeout(() => void saveRef.current('draft', true), 4000);
    return () => window.clearTimeout(t);
  }, [draft, dirty, editing]);

  // Leaving with unsaved changes asks first.
  useEffect(() => {
    if (!dirty) return;
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  async function uploadInline(file: File): Promise<string> {
    const form = new FormData();
    form.append('image', file);
    const res = await uploadApi<{ url: string }>('/platform/blog/images', form);
    return res.url;
  }

  async function uploadCover(file: File) {
    if (editing?.id == null) return;
    setBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.append('image', file);
      const res = await uploadApi<{ data: Post }>(`/platform/blog/${editing.id}/cover`, form);
      setEditing({ ...editing, cover: res.data.cover_url });
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
      setEditing({ ...editing, cover: null });
      await load();
    } catch (err) {
      setError(errorText(err, 'That image could not be removed.'));
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (editing?.id == null || !window.confirm('Move this post to the bin for good? It disappears from the site at the next rebuild.')) return;
    setBusy(true);
    try {
      await fetchApi(`/platform/blog/${editing.id}`, { method: 'DELETE' });
      setEditing(null);
      setDirty(false);
      setNotice('Post deleted.');
      await load();
    } catch (err) {
      setError(errorText(err, 'That post could not be deleted.'));
    } finally {
      setBusy(false);
    }
  }

  function close() {
    if (dirty && !window.confirm('You have unsaved changes. Leave without saving?')) return;
    setEditing(null);
    setDirty(false);
    setPreview(false);
  }

  // ---------------------------------------------------------------- list

  if (!editing) {
    const counts = {
      all: rows.length,
      published: rows.filter((r) => rowState(r).label === 'Published').length,
      scheduled: rows.filter((r) => rowState(r).label === 'Scheduled').length,
      draft: rows.filter((r) => r.status === 'draft').length,
    };
    const shown = rows
      .filter((r) => filter === 'all' || rowState(r).label.toLowerCase() === filter)
      .filter((r) => !search.trim() || r.title.toLowerCase().includes(search.trim().toLowerCase()));

    return (
      <PageShell
        title="Posts"
        subtitle="The blog on the public site. Changes appear after the next site rebuild."
        actions={<Button variant="accent" onClick={() => void open('new')}>Add new post</Button>}
      >
        <ErrorNote message={error} />
        {notice && <p role="status" className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <nav aria-label="Filter posts" className="flex flex-wrap gap-1 text-sm">
            {(['all', 'published', 'scheduled', 'draft'] as const).map((f) => (
              <button
                key={f}
                type="button"
                onClick={() => setFilter(f)}
                className={`rounded-full px-3 py-1.5 capitalize ${filter === f ? 'bg-brand text-white' : 'text-ink hover:bg-surface-sunken'}`}
              >
                {f === 'draft' ? 'Drafts' : f} <span className="opacity-70">({counts[f]})</span>
              </button>
            ))}
          </nav>
          <input className={`${inputClass} max-w-xs`} type="search" placeholder="Search posts" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>

        {loading ? <Loading /> : shown.length === 0 ? (
          <Empty>{rows.length === 0 ? 'No posts yet. Write the first one.' : 'No posts match.'}</Empty>
        ) : (
          <ul className="divide-y divide-line-soft overflow-hidden rounded-xl bg-surface ring-1 ring-line-soft">
            {shown.map((r) => {
              const st = rowState(r);
              return (
                <li key={r.id}>
                  <button type="button" onClick={() => void open(r.id)} className="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-surface-sunken">
                    {r.cover_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={r.cover_url} alt="" className="h-14 w-24 shrink-0 rounded-md object-cover" />
                    ) : (
                      <span aria-hidden="true" className="flex h-14 w-24 shrink-0 items-center justify-center rounded-md bg-surface-sunken text-ink-faint">✎</span>
                    )}
                    <span className="min-w-0 flex-1">
                      <span className="block truncate font-medium text-brand">{r.title}</span>
                      <span className="block truncate text-xs text-ink-muted">
                        {[r.category, `/blog/${r.slug}/`].filter(Boolean).join(' · ')}
                      </span>
                    </span>
                    <span className="hidden text-right text-xs text-ink-muted sm:block">
                      {st.label === 'Draft' ? `Edited ${when(r.updated_at)}` : r.published_at ? `${st.label === 'Scheduled' ? 'Goes live' : 'Published'} ${when(r.published_at)}` : ''}
                    </span>
                    <Badge tone={st.tone}>{st.label}</Badge>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </PageShell>
    );
  }

  // ---------------------------------------------------------------- editor

  const published = draft.status === 'published';
  const scheduled = isFuture(draft.publish_at);
  const seoTitle = draft.meta_title || draft.title || 'Post title';
  const seoDescription = draft.meta_description || draft.excerpt || 'The summary shows here in search results.';
  const minutes = Math.max(1, Math.round(words / 220));

  return (
    <PageShell
      title={editing.id === null ? 'Add new post' : 'Edit post'}
      subtitle={savedAt ? `${dirty ? 'Unsaved changes · ' : ''}Last saved ${savedAt.toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit' })}` : dirty ? 'Not saved yet' : undefined}
      actions={
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={close}>← All posts</Button>
          <Button variant="secondary" onClick={() => setPreview(true)} disabled={!draft.title && !html}>Preview</Button>
        </div>
      }
    >
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        {/* The post */}
        <div className="min-w-0 space-y-4">
          <input
            aria-label="Title"
            className="w-full rounded-xl bg-surface px-6 py-4 font-headline text-3xl font-bold text-brand outline-none ring-1 ring-line-soft placeholder:text-ink-faint focus:ring-2 focus:ring-brand/30 md:px-10"
            placeholder="Add title"
            maxLength={160}
            value={draft.title}
            onChange={(e) => set({ title: e.target.value })}
          />
          <RichEditor
            key={editing.key}
            value={draft.body}
            onChange={(markdown) => set({ body: markdown })}
            onHtml={setHtml}
            onUpload={uploadInline}
            onStats={setWords}
          />
          <p className="text-xs text-ink-muted">
            {words.toLocaleString('en-NG')} words · about {minutes} min read · paste or drag images straight into the text
          </p>
        </div>

        {/* Sidebar */}
        <aside className="space-y-4 xl:sticky xl:top-4">
          <Panel title="Publish">
            <dl className="space-y-1 text-sm">
              <div className="flex justify-between"><dt className="text-ink-muted">Status</dt><dd className="font-medium">{published ? (scheduled ? 'Scheduled' : 'Published') : 'Draft'}</dd></div>
              <div className="flex justify-between"><dt className="text-ink-muted">Autosave</dt><dd>{editing.id === null ? 'after first save' : published ? 'off (live post)' : 'on'}</dd></div>
            </dl>
            <label className="mt-3 block text-sm">
              <span className="mb-1 block font-medium">Publish on</span>
              <input type="datetime-local" className={inputClass} value={draft.publish_at} onChange={(e) => set({ publish_at: e.target.value })} />
              <span className="mt-1 block text-xs text-ink-muted">Empty means now. A future date schedules the post.</span>
            </label>
            <div className="mt-4 flex flex-wrap gap-2">
              {!published && <Button variant="secondary" disabled={busy} onClick={() => void save('draft')}>Save draft</Button>}
              <Button variant="accent" disabled={busy} onClick={() => void save('published')}>
                {busy ? 'Saving…' : published ? 'Update' : scheduled ? 'Schedule' : 'Publish'}
              </Button>
              {published && <Button variant="secondary" disabled={busy} onClick={() => void save('draft')}>Switch to draft</Button>}
            </div>
            {editing.id !== null && (
              <button type="button" className="mt-3 text-sm text-negative underline" disabled={busy} onClick={() => void remove()}>Move to bin</button>
            )}
          </Panel>

          <Panel title="Category & tags">
            <input className={inputClass} list="blog-categories" placeholder="e.g. Results" maxLength={60} value={draft.category} onChange={(e) => set({ category: e.target.value })} />
            <datalist id="blog-categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
            <TagInput tags={draft.tags} onChange={(tags) => set({ tags })} />
          </Panel>

          <Panel title="Featured image">
            {editing.id === null ? (
              <p className="text-sm text-ink-muted">Save the post once, then add its featured image.</p>
            ) : (
              <>
                {editing.cover && (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={editing.cover} alt="" className="mb-3 aspect-[1200/630] w-full rounded-lg object-cover" />
                )}
                <div className="flex flex-wrap items-center gap-2">
                  <label className={`cursor-pointer ${busy ? 'pointer-events-none opacity-60' : ''}`}>
                    <span className="inline-flex rounded-lg border border-line px-3 py-1.5 text-sm font-medium hover:bg-surface-sunken">{editing.cover ? 'Replace image' : 'Set featured image'}</span>
                    <input type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void uploadCover(f); }} />
                  </label>
                  {editing.cover && <button type="button" className="text-sm text-negative underline" onClick={() => void removeCover()}>Remove</button>}
                </div>
                <p className="mt-2 text-xs text-ink-muted">At least 1200 × 630. Also the preview when the post is shared.</p>
                <input className={`${inputClass} mt-2`} placeholder="Describe the image (alt text)" maxLength={200} value={draft.cover_alt} onChange={(e) => set({ cover_alt: e.target.value })} />
              </>
            )}
          </Panel>

          <Panel title="Summary">
            <textarea className={inputClass} rows={3} maxLength={300} placeholder="Shown in the post list. Left empty, we use the opening lines." value={draft.excerpt} onChange={(e) => set({ excerpt: e.target.value })} />
            <Counter value={draft.excerpt} max={300} />
          </Panel>

          <Panel title="Video">
            <input className={inputClass} inputMode="url" placeholder="YouTube or Vimeo link" value={draft.video_url} onChange={(e) => set({ video_url: e.target.value })} />
          </Panel>

          <Panel title="Search engines (SEO)">
            <div className="mb-3 rounded-lg bg-surface-sunken p-3">
              <p className="truncate text-xs text-ink-muted">schoolpilot.org.ng › blog › {draft.slug || 'your-post'}</p>
              <p className="truncate text-[0.95rem] text-[#1a0dab]">{seoTitle}</p>
              <p className="line-clamp-2 text-xs text-ink-muted">{seoDescription}</p>
            </div>
            <label className="block text-sm">
              <span className="mb-1 block font-medium">SEO title</span>
              <input className={inputClass} maxLength={70} placeholder={draft.title} value={draft.meta_title} onChange={(e) => set({ meta_title: e.target.value })} />
              <Counter value={draft.meta_title} max={60} />
            </label>
            <label className="mt-2 block text-sm">
              <span className="mb-1 block font-medium">Meta description</span>
              <textarea className={inputClass} rows={2} maxLength={170} value={draft.meta_description} onChange={(e) => set({ meta_description: e.target.value })} />
              <Counter value={draft.meta_description} max={160} />
            </label>
            <label className="mt-2 block text-sm">
              <span className="mb-1 block font-medium">Permalink</span>
              <input className={inputClass} placeholder="made from the title" value={draft.slug} onChange={(e) => set({ slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-') })} />
            </label>
          </Panel>

          <Panel title="Author">
            <input className={inputClass} maxLength={80} value={draft.author_name} onChange={(e) => set({ author_name: e.target.value })} />
          </Panel>
        </aside>
      </div>

      {preview && (
        <div role="dialog" aria-modal="true" aria-label="Preview" className="fixed inset-0 z-50 overflow-y-auto bg-ink/60 p-4 md:p-10" onClick={() => setPreview(false)}>
          <article className="mx-auto max-w-3xl rounded-2xl bg-surface p-6 shadow-2xl md:p-12" onClick={(e) => e.stopPropagation()}>
            <div className="mb-6 flex items-center justify-between">
              <Badge tone="blue">Preview</Badge>
              <Button variant="secondary" onClick={() => setPreview(false)}>Close</Button>
            </div>
            {draft.category && <p className="text-sm font-semibold uppercase tracking-wide text-accent-deep">{draft.category}</p>}
            <h1 className="mt-2 font-headline text-4xl font-bold leading-tight text-brand">{draft.title || 'Untitled post'}</h1>
            <p className="mt-3 text-sm text-ink-muted">{draft.author_name} · {minutes} min read</p>
            {editing.cover && (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={editing.cover} alt={draft.cover_alt} className="mt-6 w-full rounded-xl" />
            )}
            {/* The editor's own HTML of this operator's text, shown back to them. */}
            <div className="sp-article mt-8" dangerouslySetInnerHTML={{ __html: html || '<p></p>' }} />
            {draft.tags.length > 0 && (
              <p className="mt-8 flex flex-wrap gap-2">{draft.tags.map((t) => <span key={t} className="rounded-full bg-surface-sunken px-3 py-1 text-xs">#{t}</span>)}</p>
            )}
          </article>
        </div>
      )}
    </PageShell>
  );
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-xl bg-surface p-4 ring-1 ring-line-soft">
      <h2 className="mb-3 font-headline text-sm font-semibold text-brand">{title}</h2>
      {children}
    </section>
  );
}

function Counter({ value, max }: { value: string; max: number }) {
  return <span className={`mt-1 block text-right text-xs ${value.length > max ? 'text-negative' : 'text-ink-muted'}`}>{value.length}/{max}</span>;
}

function TagInput({ tags, onChange }: { tags: string[]; onChange: (tags: string[]) => void }) {
  const [text, setText] = useState('');

  function add(raw: string) {
    const parts = raw.split(',').map((t) => t.trim()).filter(Boolean);
    const next = [...tags];
    for (const p of parts) if (!next.some((t) => t.toLowerCase() === p.toLowerCase()) && next.length < 12) next.push(p.slice(0, 40));
    onChange(next);
    setText('');
  }

  return (
    <div className="mt-3">
      <input
        className={inputClass}
        placeholder="Add tags, separated by commas"
        value={text}
        onChange={(e) => (e.target.value.endsWith(',') ? add(e.target.value) : setText(e.target.value))}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            add(text);
          }
          if (e.key === 'Backspace' && !text && tags.length) onChange(tags.slice(0, -1));
        }}
        onBlur={() => text.trim() && add(text)}
      />
      {tags.length > 0 && (
        <ul className="mt-2 flex flex-wrap gap-1.5">
          {tags.map((t) => (
            <li key={t} className="flex items-center gap-1 rounded-full bg-surface-sunken px-2.5 py-1 text-xs">
              {t}
              <button type="button" aria-label={`Remove ${t}`} className="text-ink-muted hover:text-negative" onClick={() => onChange(tags.filter((x) => x !== t))}>×</button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
