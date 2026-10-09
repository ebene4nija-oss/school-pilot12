'use client';

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, Empty, ErrorNote, Field, Loading, inputClass } from '@/components/Page';
import { useChildren } from '@/components/ChildSwitcher';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * Parent ↔ teacher chat, for both sides.
 *
 * Every thread is between one parent and one member of staff, usually about
 * one child. A parent starts from their child and picks a teacher; a teacher
 * starts from a pupil and picks a parent. While a conversation is open it
 * refreshes on its own, so it reads like a chat rather than a mailbox.
 */

type Person = { id: number; name: string };

type Thread = {
  id: number;
  subject: string | null;
  teacher: Person | null;
  parent: Person | null;
  student: { id: number; user?: { name: string } | null } | null;
  last_message_at: string | null;
  unread_count: number;
  messages: { body: string }[];
};

type Message = { id: number; body: string; sender_id: number; sender: Person | null; created_at: string; read_at: string | null };

export type Side = 'parent' | 'staff';

/** How often an open conversation, and the list, check for new messages. */
const THREAD_POLL_MS = 8000;
const LIST_POLL_MS = 30000;

const when = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';

/** Runs `fn` every `ms` while the tab is visible, and once when it becomes visible again. */
function usePolling(fn: () => void, ms: number) {
  const ref = useRef(fn);
  useEffect(() => {
    ref.current = fn;
  });
  useEffect(() => {
    const tick = () => {
      if (document.visibilityState === 'visible') ref.current();
    };
    const id = window.setInterval(tick, ms);
    document.addEventListener('visibilitychange', tick);
    return () => {
      window.clearInterval(id);
      document.removeEventListener('visibilitychange', tick);
    };
  }, [ms]);
}

export default function Conversations({ side }: { side: Side }) {
  const [threads, setThreads] = useState<Thread[] | null>(null);
  const [openId, setOpenId] = useState<number | null>(null);
  const [composing, setComposing] = useState(false);
  const [search, setSearch] = useState('');
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ data: Thread[] }>('/messages/threads?per_page=100');
      setThreads(res.data ?? []);
      setError(null);
    } catch (err) {
      setThreads((t) => t ?? []);
      setError(errorText(err, 'Could not load your messages.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);
  usePolling(() => void load(), LIST_POLL_MS);

  const other = (t: Thread) => (side === 'parent' ? t.teacher : t.parent);
  const childName = (t: Thread) => t.student?.user?.name ?? null;
  const shown = (threads ?? []).filter((t) => {
    const q = search.trim().toLowerCase();
    if (!q) return true;
    return [other(t)?.name, childName(t), t.subject].some((v) => v?.toLowerCase().includes(q));
  });

  return (
    <>
      <div className="mb-4 flex justify-end">
        <Button onClick={() => { setComposing(true); setOpenId(null); }}>New message</Button>
      </div>
      <ErrorNote message={error} />
      <div className="grid gap-6 lg:grid-cols-[21rem_1fr]">
        <Card title="Conversations">
          {threads && threads.length > 5 && (
            <input className={`${inputClass} mb-3`} type="search" placeholder="Search by name or child" value={search} onChange={(e) => setSearch(e.target.value)} />
          )}
          {!threads ? (
            <Loading />
          ) : shown.length === 0 ? (
            <Empty>{threads.length === 0 ? 'No messages yet.' : 'No conversations match.'}</Empty>
          ) : (
            <ul className="max-h-[65vh] space-y-2 overflow-y-auto pr-1">
              {shown.map((t) => {
                const active = openId === t.id;
                return (
                  <li key={t.id}>
                    <button
                      type="button"
                      onClick={() => { setOpenId(t.id); setComposing(false); }}
                      className={`w-full rounded-lg px-3 py-2.5 text-left transition ${active ? 'bg-brand text-white' : 'bg-surface-sunken text-ink hover:bg-surface-raised'}`}
                    >
                      <span className="flex items-center justify-between gap-2">
                        <span className="truncate text-sm font-semibold">{other(t)?.name ?? (side === 'parent' ? 'Teacher' : 'Parent')}</span>
                        {t.unread_count > 0 && <span className="rounded-full bg-accent px-2 text-xs font-bold text-accent-ink">{t.unread_count}</span>}
                      </span>
                      {childName(t) && <span className={`block truncate text-xs font-medium ${active ? 'text-white/90' : 'text-brand'}`}>About {childName(t)}</span>}
                      <span className={`block truncate text-xs ${active ? 'text-white/80' : 'text-ink-muted'}`}>{t.messages?.[0]?.body ?? t.subject ?? ''}</span>
                      <span className={`block text-[11px] ${active ? 'text-white/70' : 'text-ink-faint'}`}>{when(t.last_message_at)}</span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>

        {composing ? (
          side === 'parent' ? (
            <ParentCompose onSent={(id) => { setComposing(false); void load(); setOpenId(id); }} />
          ) : (
            <StaffCompose onSent={(id) => { setComposing(false); void load(); setOpenId(id); }} />
          )
        ) : openId ? (
          <ThreadView key={openId} id={openId} side={side} onChange={load} />
        ) : (
          <Card><Empty>Choose a conversation, or start a new message.</Empty></Card>
        )}
      </div>
    </>
  );
}

function ThreadView({ id, side, onChange }: { id: number; side: Side; onChange: () => void }) {
  const [thread, setThread] = useState<{ teacher: Person | null; parent: Person | null; subject: string | null; student: Thread['student'] } | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [me, setMe] = useState<number | null>(null);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const scroller = useRef<HTMLDivElement>(null);
  const lastId = useRef<number | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ thread: NonNullable<typeof thread>; messages: Message[] }>(`/messages/threads/${id}`);
      setThread(res.thread);
      const list = res.messages ?? [];
      setMessages(list);
      const newest = list.length ? list[list.length - 1].id : null;
      // Scroll down only when something new arrived, not on every quiet refresh.
      if (newest !== lastId.current) {
        lastId.current = newest;
        window.setTimeout(() => scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' }), 50);
        onChange();
      }
    } catch (err) {
      setError(errorText(err, 'Could not open this conversation.'));
    }
  }, [id, onChange]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ id: number }>('/user').then((u) => setMe(u.id)).catch(() => undefined);
  }, [load]);
  usePolling(() => void load(), THREAD_POLL_MS);

  const send = async () => {
    const text = body.trim();
    if (!text) return;
    setBusy(true);
    setError(null);
    try {
      await fetchApi('/messages/send', { method: 'POST', body: JSON.stringify({ thread_id: id, body: text }) });
      setBody('');
      await load();
    } catch (err) {
      setError(errorText(err, 'Your message was not sent.'));
    } finally {
      setBusy(false);
    }
  };

  if (!thread) return error ? <ErrorNote message={error} /> : <Loading />;

  const other = side === 'parent' ? thread.teacher : thread.parent;
  const child = thread.student?.user?.name;

  return (
    <Card title={other?.name ?? 'Conversation'} description={[child && `About ${child}`, thread.subject !== 'Direct Message' ? thread.subject : null].filter(Boolean).join(' · ') || undefined}>
      <div ref={scroller} className="max-h-[58vh] space-y-3 overflow-y-auto pr-1">
        {messages.length === 0 && <p className="text-sm text-ink-muted">No messages yet.</p>}
        {messages.map((m) => {
          const mine = m.sender_id === me;
          return (
            <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
              <div className={`max-w-[85%] rounded-2xl px-3.5 py-2 text-sm ${mine ? 'rounded-br-sm bg-brand text-white' : 'rounded-bl-sm bg-surface-sunken text-ink'}`}>
                <p className="whitespace-pre-line">{m.body}</p>
                <p className={`mt-1 text-[10px] ${mine ? 'text-white/70' : 'text-ink-faint'}`}>
                  {when(m.created_at)}
                  {mine && <> · {m.read_at ? 'Seen' : 'Sent'}</>}
                </p>
              </div>
            </div>
          );
        })}
      </div>
      <ErrorNote message={error} />
      <form
        onSubmit={(e) => { e.preventDefault(); void send(); }}
        className="mt-4 flex items-end gap-2"
      >
        <textarea
          rows={2}
          value={body}
          onChange={(e) => setBody(e.target.value)}
          // Enter sends; Shift+Enter starts a new line, as in any chat.
          onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void send(); } }}
          className={inputClass}
          placeholder="Write a message. Enter to send, Shift+Enter for a new line."
          maxLength={10000}
        />
        <Button type="submit" disabled={busy || !body.trim()}>{busy ? 'Sending…' : 'Send'}</Button>
      </form>
    </Card>
  );
}

type Contact = { user_id: number; name: string; role?: string; relationship?: string | null };

function ParentCompose({ onSent }: { onSent: (threadId: number) => void }) {
  const { children, selected, select } = useChildren();
  const [contacts, setContacts] = useState<Contact[] | null>(null);
  const [recipient, setRecipient] = useState('');

  useEffect(() => {
    if (!selected) return;
    let cancelled = false;
    fetchApi<{ data: Contact[] }>(`/parent/children/${selected.id}/contacts`)
      .then((r) => { if (!cancelled) { setContacts(r.data ?? []); setRecipient(''); } })
      .catch(() => { if (!cancelled) setContacts([]); });
    return () => { cancelled = true; };
  }, [selected]);

  return (
    <ComposeForm
      studentId={selected?.id ?? null}
      recipient={recipient}
      onSent={onSent}
      top={
        <>
          {children && children.length > 1 && (
            <Field label="About">
              <select value={selected?.id ?? ''} onChange={(e) => select(Number(e.target.value))} className={inputClass}>
                {children.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
          )}
          <Field label="To">
            <select required value={recipient} onChange={(e) => setRecipient(e.target.value)} className={inputClass}>
              <option value="">{contacts === null ? 'Loading…' : contacts.length === 0 ? 'No teachers assigned yet' : 'Choose a teacher…'}</option>
              {(contacts ?? []).map((c) => <option key={c.user_id} value={c.user_id}>{c.name}{c.role ? ` — ${c.role}` : ''}</option>)}
            </select>
          </Field>
        </>
      }
    />
  );
}

function StaffCompose({ onSent }: { onSent: (threadId: number) => void }) {
  const [student, setStudent] = useState<PickedStudent | null>(null);
  const [contacts, setContacts] = useState<Contact[] | null>(null);
  const [recipient, setRecipient] = useState('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!student) return;
    let cancelled = false;
    fetchApi<{ recipients: Contact[] }>(`/students/${student.id}/message-recipients`)
      .then((r) => { if (!cancelled) { setContacts(r.recipients ?? []); setRecipient(r.recipients?.length === 1 ? String(r.recipients[0].user_id) : ''); setError(null); } })
      .catch((err) => { if (!cancelled) { setContacts([]); setError(errorText(err, 'Could not load this pupil’s parents.')); } });
    return () => { cancelled = true; };
  }, [student]);

  return (
    <ComposeForm
      studentId={student?.id ?? null}
      recipient={recipient}
      onSent={onSent}
      top={
        <>
          <Field label="About which pupil?">
            <StudentPicker value={student} onChange={(s) => { setStudent(s); setContacts(null); setRecipient(''); }} />
          </Field>
          {student && (
            <Field label="To">
              <select required value={recipient} onChange={(e) => setRecipient(e.target.value)} className={inputClass}>
                <option value="">{contacts === null ? 'Loading…' : contacts.length === 0 ? 'No parent on SchoolPilot yet' : 'Choose a parent…'}</option>
                {(contacts ?? []).map((c) => <option key={c.user_id} value={c.user_id}>{c.name}{c.relationship ? ` (${c.relationship})` : ''}</option>)}
              </select>
            </Field>
          )}
          <ErrorNote message={error} />
        </>
      }
    />
  );
}

function ComposeForm({ top, studentId, recipient, onSent }: { top: React.ReactNode; studentId: number | null; recipient: string; onSent: (threadId: number) => void }) {
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await fetchApi<{ data?: { thread_id: number } }>('/messages/send', {
        method: 'POST',
        body: JSON.stringify({ recipient_id: Number(recipient), student_id: studentId, subject: subject.trim() || null, body: body.trim() }),
      });
      onSent(res.data?.thread_id ?? 0);
    } catch (err) {
      setError(errorText(err, 'Your message was not sent.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="New message">
      <form onSubmit={send} className="space-y-4">
        {top}
        <Field label="Subject (optional)">
          <input value={subject} maxLength={255} onChange={(e) => setSubject(e.target.value)} className={inputClass} />
        </Field>
        <Field label="Message">
          <textarea required rows={5} maxLength={10000} value={body} onChange={(e) => setBody(e.target.value)} className={inputClass} />
        </Field>
        <ErrorNote message={error} />
        <Button type="submit" disabled={busy || !recipient || !studentId || !body.trim()}>{busy ? 'Sending…' : 'Send'}</Button>
      </form>
    </Card>
  );
}
