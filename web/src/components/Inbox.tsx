'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Empty, ErrorNote, Loading, PageShell } from '@/components/Page';
import { when } from '@/lib/student';

/**
 * The signed-in user's own notifications, newest first: what the school has
 * sent this account (homework returned, results released, payroll ready,
 * announcements). Any role; `/me/notifications` is scoped to the caller.
 */

interface Item { id: number; category: string | null; body: string; sent_at: string | null; read: boolean }

export default function Inbox({ subtitle }: { subtitle: string }) {
  const [items, setItems] = useState<Item[] | null>(null);
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ data: Item[]; meta: { last_page: number } }>(`/me/notifications?page=${page}&per_page=30${unreadOnly ? '&unread_only=1' : ''}`);
      setItems(res.data);
      setLastPage(res.meta.last_page);
    } catch (err) {
      setError(errorText(err, 'Could not load your notifications.'));
    }
  }, [page, unreadOnly]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function markRead(ids?: number[]) {
    try {
      await fetchApi('/me/notifications/read', { method: 'POST', body: JSON.stringify(ids ? { ids } : {}) });
      setItems((prev) => prev?.map((i) => (!ids || ids.includes(i.id) ? { ...i, read: true } : i)) ?? null);
    } catch (err) {
      setError(errorText(err, 'Could not mark as read.'));
    }
  }

  return (
    <PageShell
      title="Notifications"
      subtitle={subtitle}
      actions={<div className="flex gap-2">
        <Button variant="secondary" onClick={() => { setPage(1); setUnreadOnly(!unreadOnly); }}>{unreadOnly ? 'Show all' : 'Unread only'}</Button>
        <Button variant="secondary" onClick={() => markRead()}>Mark all read</Button>
      </div>}
    >
      <ErrorNote message={error} />
      {!items ? (!error && <Loading />) : items.length === 0 ? <Empty>{unreadOnly ? 'Nothing unread.' : 'No notifications yet.'}</Empty> : (
        <ul className="space-y-2">
          {items.map((n) => (
            <li key={n.id} className={`rounded-xl p-4 shadow-sm ring-1 ring-line-soft ${n.read ? 'bg-surface' : 'border-l-4 border-l-brand bg-info-soft'}`}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <p className="whitespace-pre-wrap text-sm">{n.body}</p>
                {!n.read && <button type="button" className="text-xs text-brand underline" onClick={() => markRead([n.id])}>Mark read</button>}
              </div>
              <p className="mt-1 text-xs text-ink-muted">{n.category && <Badge>{n.category}</Badge>} {n.sent_at && when(n.sent_at)}</p>
            </li>
          ))}
        </ul>
      )}
      {lastPage > 1 && (
        <div className="mt-4 flex items-center gap-3">
          <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Newer</Button>
          <span className="text-sm text-ink-muted">Page {page} of {lastPage}</span>
          <Button variant="secondary" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>Older</Button>
        </div>
      )}
    </PageShell>
  );
}
