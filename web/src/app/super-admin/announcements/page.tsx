'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * Notices from SchoolPilot to school administrators: maintenance, releases,
 * policy changes. They appear on the admin's dashboard until their end date.
 *
 * Administrators only, never parents or staff: those are the school's
 * contacts, not SchoolPilot's (NDPA, doc §12).
 */

interface Announcement {
  id: number;
  title: string;
  body: string;
  tone: 'info' | 'warning';
  published_at: string | null;
  shows_until: string;
  school: { id: number; name: string } | null;
  author: string | null;
  withdrawn_at: string | null;
  is_showing: boolean;
}

interface SchoolOption {
  id: number;
  name: string;
}

/** A datetime-local value a week from now, the default end. */
function inAWeek(): string {
  const d = new Date(Date.now() + 7 * 24 * 3_600_000);
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
}

const EMPTY = { title: '', body: '', tone: 'info', school_id: '', shows_until: '' };

function when(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

export default function AnnouncementsPage() {
  const [rows, setRows] = useState<Announcement[]>([]);
  const [schools, setSchools] = useState<SchoolOption[]>([]);
  const [form, setForm] = useState(EMPTY);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const [a, s] = await Promise.all([
      fetchApi<{ data: Announcement[] }>('/platform/announcements'),
      fetchApi<{ data: SchoolOption[] }>('/platform/schools'),
    ]);
    setRows(a.data);
    setSchools(s.data);
  }, []);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load announcements.');
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function publish(e: React.FormEvent) {
    e.preventDefault();
    const audience = form.school_id ? schools.find((s) => String(s.id) === form.school_id)?.name : 'every school';
    if (!window.confirm(`Publish "${form.title}" to the administrators of ${audience}?`)) return;

    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      await fetchApi('/platform/announcements', {
        method: 'POST',
        body: JSON.stringify({
          title: form.title,
          body: form.body,
          tone: form.tone,
          school_id: form.school_id ? Number(form.school_id) : null,
          shows_until: new Date(form.shows_until || inAWeek()).toISOString(),
        }),
      });
      setForm(EMPTY);
      setNotice('Published. School administrators see it on their dashboard.');
      await load();
    } catch (err) {
      const errors = (err as { payload?: { errors?: Record<string, string[]> } }).payload?.errors;
      setError(errors ? Object.values(errors)[0][0] : err instanceof Error ? err.message : 'That could not be published.');
    } finally {
      setBusy(false);
    }
  }

  async function withdraw(row: Announcement) {
    if (!window.confirm(`Take down "${row.title}"?`)) return;

    setBusy(true);
    setError(null);

    try {
      await fetchApi(`/platform/announcements/${row.id}/withdraw`, { method: 'POST' });
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That could not be withdrawn.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell
      title="Announcements"
      subtitle="Notices to school administrators, shown on their dashboard. Never sent to parents or staff."
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      <div className="space-y-6">
        <Card title="New announcement">
          <form onSubmit={publish} className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Field label="Title">
                <input
                  required
                  maxLength={150}
                  value={form.title}
                  onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
                  className={inputClass}
                  placeholder="e.g. Scheduled maintenance, Saturday 10pm–midnight"
                />
              </Field>
            </div>
            <div className="sm:col-span-2">
              <Field label="Message">
                <textarea
                  required
                  rows={4}
                  maxLength={2000}
                  value={form.body}
                  onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))}
                  className={inputClass}
                />
              </Field>
            </div>
            <Field label="To">
              <select
                value={form.school_id}
                onChange={(e) => setForm((f) => ({ ...f, school_id: e.target.value }))}
                className={inputClass}
              >
                <option value="">Every school</option>
                {schools.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name} only
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Kind">
              <select
                value={form.tone}
                onChange={(e) => setForm((f) => ({ ...f, tone: e.target.value }))}
                className={inputClass}
              >
                <option value="info">Information</option>
                <option value="warning">Warning (downtime, action needed)</option>
              </select>
            </Field>
            <Field label="Show until">
              <input
                type="datetime-local"
                value={form.shows_until || inAWeek()}
                onChange={(e) => setForm((f) => ({ ...f, shows_until: e.target.value }))}
                className={inputClass}
              />
            </Field>
            <div className="flex items-end">
              <Button type="submit" variant="accent" disabled={busy}>
                Publish
              </Button>
            </div>
          </form>
        </Card>

        <Card title="Published">
          {loading ? (
            <Loading />
          ) : rows.length === 0 ? (
            <Empty>Nothing published yet.</Empty>
          ) : (
            <ul className="divide-y divide-line-soft">
              {rows.map((row) => (
                <li key={row.id} className="flex flex-wrap items-start justify-between gap-3 py-4">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-medium text-brand">{row.title}</p>
                      {row.tone === 'warning' && <Badge tone="amber">Warning</Badge>}
                      <Badge tone={row.is_showing ? 'green' : 'slate'}>
                        {row.withdrawn_at ? 'Withdrawn' : row.is_showing ? 'Showing' : 'Ended'}
                      </Badge>
                    </div>
                    <p className="mt-1 whitespace-pre-wrap text-sm text-ink">{row.body}</p>
                    <p className="mt-1 text-xs text-ink-faint">
                      To {row.school?.name ?? 'every school'} · by {row.author ?? 'a former operator'} ·{' '}
                      {when(row.published_at)} until {when(row.shows_until)}
                    </p>
                  </div>
                  {row.is_showing && (
                    <Button variant="secondary" disabled={busy} onClick={() => withdraw(row)}>
                      Withdraw
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </PageShell>
  );
}
