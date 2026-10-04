'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, inputClass } from '@/components/Page';

/**
 * A reader's library books (doc §9.2): what they have out, when it is due,
 * and their reservations. A student renews and reserves here; a parent
 * (studentId given) only sees their child's books.
 */

interface Loan { id: number; title: string; author: string; due_date: string; days_overdue: number; renewals: number; can_renew?: boolean }
interface Reservation { id: number; title: string; status: 'waiting' | 'ready'; ready_until: string | null }
interface View { loans: Loan[]; reservations: Reservation[]; can_reserve: boolean }
interface Book { id: number; title: string; author: string; available_copies: number }

export default function LibraryBooks({ studentId, title = 'My library books' }: { studentId?: number; title?: string }) {
  const [view, setView] = useState<View | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [q, setQ] = useState('');
  const [found, setFound] = useState<Book[] | null>(null);

  const path = studentId ? `/library/children/${studentId}` : '/library/me';
  const fetchView = useCallback(() => fetchApi<View>(path), [path]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const v = await fetchView();
        if (!cancelled) setView(v);
      } catch {
        // The library module is off for this school, or nothing to show.
      }
    })();
    return () => { cancelled = true; };
  }, [fetchView]);

  async function act(fn: () => Promise<unknown>) {
    setError(null);
    try {
      await fn();
      setView(await fetchView());
    } catch (err) {
      setError(errorText(err, "That did not work."));
    }
  }

  async function search(e: React.FormEvent) {
    e.preventDefault();
    try {
      setFound((await fetchApi<{ data: Book[] }>(`/library/books?search=${encodeURIComponent(q.trim())}`)).data);
    } catch (err) {
      setError(errorText(err, "That did not work."));
    }
  }

  if (!view) return null;
  if (studentId && view.loans.length === 0 && view.reservations.length === 0) return null;

  return (
    <Card title={title} className="mt-6">
      {error && <p role="alert" className="mb-3 text-sm text-negative">{error}</p>}
      {view.loans.length === 0 ? <Empty>No books out.</Empty> : (
        <ul className="space-y-2 text-sm">
          {view.loans.map((l) => (
            <li key={l.id} className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{l.title}</span>
              <span className="text-ink-muted">· {l.author} · due {l.due_date}</span>
              {l.days_overdue > 0 && <Badge tone="red">{l.days_overdue} day(s) late</Badge>}
              {l.can_renew && (
                <Button variant="secondary" onClick={() => act(() => fetchApi(`/library/loans/${l.id}/renew`, { method: 'POST' }))}>Renew</Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {view.reservations.length > 0 && (
        <ul className="mt-4 space-y-2 text-sm">
          {view.reservations.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center gap-2">
              <span>{r.title}</span>
              {r.status === 'ready' ? <Badge tone="green">ready to collect until {r.ready_until}</Badge> : <Badge tone="amber">reserved, waiting</Badge>}
              {view.can_reserve && (
                <Button variant="secondary" onClick={() => act(() => fetchApi(`/library/reservations/${r.id}`, { method: 'DELETE' }))}>Cancel</Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {view.can_reserve && (
        <div className="mt-5 border-t border-line-soft pt-4">
          <form onSubmit={search} className="flex flex-wrap gap-2">
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Find a book to reserve" aria-label="Search the library" className={`${inputClass} max-w-xs`} />
            <Button type="submit" variant="secondary" disabled={!q.trim()}>Search</Button>
          </form>
          {found && (found.length === 0 ? <p className="mt-2 text-sm text-ink-muted">No book matches.</p> : (
            <ul className="mt-3 space-y-1 text-sm">
              {found.map((b) => (
                <li key={b.id} className="flex flex-wrap items-center gap-2">
                  {b.title} <span className="text-ink-muted">· {b.author}</span>
                  {b.available_copies > 0 ? <Badge tone="green">on the shelf</Badge> : (
                    <Button variant="secondary" onClick={() => act(() => fetchApi('/library/reservations', { method: 'POST', body: JSON.stringify({ book_id: b.id }) }))}>Reserve</Button>
                  )}
                </li>
              ))}
            </ul>
          ))}
        </div>
      )}
    </Card>
  );
}

/** Each child's library books, on the parent page. */
export function ParentLibrary({ kids }: { kids: { id: number; name: string | null }[] }) {
  return (
    <>
      {kids.map((c) => <LibraryBooks key={c.id} studentId={c.id} title={`${c.name ?? 'Child'}'s library books`} />)}
    </>
  );
}
