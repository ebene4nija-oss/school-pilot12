'use client';

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, Empty, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';
import { useChildren } from '@/components/ChildSwitcher';

/**
 * A parent's conversations with the child's teachers.
 *
 * Every thread is between one parent and one member of staff about one child.
 * A new message goes to the child's form teacher or a subject teacher; replies
 * stay in the thread.
 */

type Person = { id: number; name: string };

type Thread = {
  id: number;
  subject: string | null;
  teacher: Person | null;
  student: { id: number } | null;
  last_message_at: string | null;
  unread_count: number;
  messages: { body: string }[];
};

type Message = { id: number; body: string; sender_id: number; sender: Person | null; created_at: string };

type Contact = { user_id: number; name: string; role: string };

const when = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';

export default function ParentMessagesPage() {
  const [threads, setThreads] = useState<Thread[] | null>(null);
  const [openId, setOpenId] = useState<number | null>(null);
  const [composing, setComposing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ data: Thread[] }>('/messages/threads');
      setThreads(res.data ?? []);
      setError(null);
    } catch (err) {
      setThreads([]);
      setError(errorText(err, 'Could not load your messages.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  return (
    <PageShell
      title="Messages"
      subtitle="Conversations with your child's teachers."
      actions={<Button onClick={() => { setComposing(true); setOpenId(null); }}>New message</Button>}
    >
      <ErrorNote message={error} />
      <div className="grid gap-6 lg:grid-cols-[20rem_1fr]">
        <Card title="Conversations">
          {!threads ? (
            <Loading />
          ) : threads.length === 0 ? (
            <Empty>No messages yet.</Empty>
          ) : (
            <ul className="space-y-2">
              {threads.map((t) => (
                <li key={t.id}>
                  <button
                    onClick={() => { setOpenId(t.id); setComposing(false); }}
                    className={`w-full rounded-lg px-3 py-2.5 text-left transition ${openId === t.id ? 'bg-brand text-white' : 'bg-surface-sunken text-ink hover:bg-surface-raised'}`}
                  >
                    <span className="flex items-center justify-between gap-2">
                      <span className="truncate text-sm font-semibold">{t.teacher?.name ?? 'Teacher'}</span>
                      {t.unread_count > 0 && <span className="rounded-full bg-accent px-2 text-xs font-bold text-accent-ink">{t.unread_count}</span>}
                    </span>
                    <span className={`block truncate text-xs ${openId === t.id ? 'text-white/80' : 'text-ink-muted'}`}>
                      {t.subject ?? t.messages?.[0]?.body ?? ''}
                    </span>
                    <span className={`block text-[11px] ${openId === t.id ? 'text-white/70' : 'text-ink-faint'}`}>{when(t.last_message_at)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {composing ? (
          <Compose onSent={(threadId) => { setComposing(false); void load(); setOpenId(threadId); }} />
        ) : openId ? (
          <ThreadView key={openId} id={openId} onRead={load} />
        ) : (
          <Card><Empty>Choose a conversation, or start a new message.</Empty></Card>
        )}
      </div>
    </PageShell>
  );
}

function ThreadView({ id, onRead }: { id: number; onRead: () => void }) {
  const [thread, setThread] = useState<{ teacher: Person | null; subject: string | null } | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [me, setMe] = useState<number | null>(null);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const end = useRef<HTMLDivElement>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ thread: { teacher: Person | null; subject: string | null }; messages: Message[] }>(`/messages/threads/${id}`);
      setThread(res.thread);
      setMessages(res.messages ?? []);
      setTimeout(() => end.current?.scrollIntoView({ block: 'end' }), 50);
    } catch (err) {
      setError(errorText(err, 'Could not open this conversation.'));
    }
  }, [id]);

  useEffect(() => {
    void (async () => {
      await load();
      onRead();
    })();
    fetchApi<{ id: number }>('/user').then((u) => setMe(u.id)).catch(() => undefined);
  }, [load, onRead]);

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!thread?.teacher) return;
    setBusy(true);
    setError(null);
    try {
      await fetchApi('/messages/send', {
        method: 'POST',
        body: JSON.stringify({ thread_id: id, recipient_id: thread.teacher.id, body: body.trim() }),
      });
      setBody('');
      await load();
    } catch (err) {
      setError(errorText(err, 'Your message was not sent.'));
    } finally {
      setBusy(false);
    }
  };

  if (!thread) return error ? <ErrorNote message={error} /> : <Loading />;

  return (
    <Card title={thread.teacher?.name ?? 'Conversation'} description={thread.subject ?? undefined}>
      <div className="max-h-[55vh] space-y-3 overflow-y-auto pr-1">
        {messages.map((m) => {
          const mine = m.sender_id === me;
          return (
            <div key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
              <div className={`max-w-[85%] rounded-xl px-3 py-2 text-sm ${mine ? 'bg-brand text-white' : 'bg-surface-sunken text-ink'}`}>
                <p className="whitespace-pre-line">{m.body}</p>
                <p className={`mt-1 text-[10px] ${mine ? 'text-white/70' : 'text-ink-faint'}`}>{when(m.created_at)}</p>
              </div>
            </div>
          );
        })}
        <div ref={end} />
      </div>
      <ErrorNote message={error} />
      <form onSubmit={send} className="mt-4 flex gap-2">
        <textarea rows={2} value={body} onChange={(e) => setBody(e.target.value)} className={inputClass} placeholder="Write a reply" required />
        <Button type="submit" disabled={busy || !body.trim()}>{busy ? 'Sending…' : 'Send'}</Button>
      </form>
    </Card>
  );
}

function Compose({ onSent }: { onSent: (threadId: number) => void }) {
  const { children, selected, select } = useChildren();
  const [contacts, setContacts] = useState<Contact[] | null>(null);
  const [recipient, setRecipient] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!selected) return;
    let cancelled = false;
    fetchApi<{ data: Contact[] }>(`/parent/children/${selected.id}/contacts`)
      .then((r) => { if (!cancelled) { setContacts(r.data ?? []); setRecipient(''); } })
      .catch((err) => { if (!cancelled) { setContacts([]); setError(errorText(err, 'Could not load the teachers.')); } });
    return () => { cancelled = true; };
  }, [selected]);

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selected) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fetchApi<{ data?: { thread_id: number } }>('/messages/send', {
        method: 'POST',
        body: JSON.stringify({ recipient_id: Number(recipient), student_id: selected.id, subject: subject.trim() || null, body: body.trim() }),
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
            {(contacts ?? []).map((c) => <option key={c.user_id} value={c.user_id}>{c.name} — {c.role}</option>)}
          </select>
        </Field>
        <Field label="Subject (optional)">
          <input value={subject} maxLength={255} onChange={(e) => setSubject(e.target.value)} className={inputClass} />
        </Field>
        <Field label="Message">
          <textarea required rows={5} value={body} onChange={(e) => setBody(e.target.value)} className={inputClass} />
        </Field>
        <ErrorNote message={error} />
        <Button type="submit" disabled={busy || !recipient || !body.trim()}>{busy ? 'Sending…' : 'Send'}</Button>
      </form>
    </Card>
  );
}
