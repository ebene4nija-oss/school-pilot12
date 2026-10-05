'use client';

import React, { useEffect, useRef, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Loading, Meter, PageShell, inputClass } from '@/components/Page';
import { day } from '@/lib/student';

/**
 * The student AI tutor (TASK 15, doc §7.11).
 *
 * Framed as a tutor, not an answer service: it explains, hints and sets
 * practice, and it says plainly that it does not mark work. The school's
 * rules (sections, allowed subjects, daily limit, the lock during a CBT
 * exam) are enforced by the API; this page shows the reason it gives.
 */

interface Turn { role: 'student' | 'tutor'; body: string }
interface Conversation { id: number; title: string | null; subject: { name: string } | null; last_message_at: string | null; message_count: number }
interface Mastery { subject: string | null; topic: string; mastery_percentage: number; questions_attempted: number }

const STARTERS = ['Explain simultaneous equations step by step', 'Give me a practice question on fractions', 'Help me revise photosynthesis', 'Make this lesson simpler for me'];

export default function StudentTutor() {
  const [available, setAvailable] = useState<boolean | null>(null);
  const [subjects, setSubjects] = useState<{ id: number; name: string }[]>([]);
  const [subjectId, setSubjectId] = useState('');
  const [mode, setMode] = useState<'chat' | 'practice'>('chat');
  const [conversationId, setConversationId] = useState<number | null>(null);
  const [turns, setTurns] = useState<Turn[]>([]);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [history, setHistory] = useState<Conversation[] | null>(null);
  const [mastery, setMastery] = useState<{ mastered: Mastery[]; developing: Mastery[]; needs_work: Mastery[] } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const end = useRef<HTMLDivElement>(null);

  const loadHistory = () =>
    fetchApi<{ data: Conversation[] }>('/ai/tutor/conversations?per_page=30').then((r) => setHistory(r.data)).catch(() => setHistory([]));

  useEffect(() => {
    fetchApi<{ tutor_available: boolean }>('/me/dashboard').then((d) => setAvailable(d.tutor_available)).catch(() => setAvailable(true));
    fetchApi<{ data: { id: number; name: string }[] }>('/me/subjects').then((r) => setSubjects(r.data)).catch(() => undefined);
    fetchApi<{ mastered: Mastery[]; developing: Mastery[]; needs_work: Mastery[] }>('/ai/tutor/mastery').then(setMastery).catch(() => undefined);
    void loadHistory();
  }, []);

  useEffect(() => { end.current?.scrollIntoView({ behavior: 'smooth', block: 'end' }); }, [turns, sending]);

  async function send(preset?: string) {
    const message = (preset ?? input).trim();
    if (!message || sending) return;
    setError(null);
    setTurns((t) => [...t, { role: 'student', body: message }]);
    setInput('');
    setSending(true);
    try {
      const res = await fetchApi<{ conversation_id: number; response: string }>('/ai/tutor-chat', {
        method: 'POST',
        body: JSON.stringify({
          message,
          mode,
          ...(conversationId ? { conversation_id: conversationId } : subjectId ? { subject_id: Number(subjectId) } : {}),
        }),
      });
      if (!conversationId) void loadHistory();
      setConversationId(res.conversation_id);
      setTurns((t) => [...t, { role: 'tutor', body: res.response }]);
    } catch (err) {
      // The school's own reason (limit reached, exam in progress, subject not allowed).
      setError(errorText(err, 'The tutor could not answer just now.'));
      setTurns((t) => t.slice(0, -1));
      setInput(message);
    } finally {
      setSending(false);
    }
  }

  async function open(id: number) {
    setError(null);
    try {
      const res = await fetchApi<{ conversation: { id: number; subject_id: number | null; messages: Turn[] } }>(`/ai/tutor/conversations/${id}`);
      setConversationId(res.conversation.id);
      setSubjectId(res.conversation.subject_id ? String(res.conversation.subject_id) : '');
      setTurns(res.conversation.messages.map((m) => ({ role: m.role, body: m.body })));
    } catch (err) {
      setError(errorText(err, 'Could not open that conversation.'));
    }
  }

  function fresh() {
    setConversationId(null);
    setTurns([]);
    setError(null);
  }

  if (available === false) {
    return (
      <PageShell title="AI tutor">
        <Empty>Your school has not turned the AI tutor on. Ask your class teacher if you would like to use it.</Empty>
      </PageShell>
    );
  }

  return (
    <PageShell title="AI tutor" subtitle="Ask for explanations, hints and practice questions. It helps you learn; your teacher still marks your work.">
      <div className="grid gap-5 lg:grid-cols-[1fr_18rem]">
        <section className="flex min-h-[32rem] flex-col rounded-xl bg-surface shadow-sm ring-1 ring-line-soft">
          <div className="flex flex-wrap items-end gap-3 border-b border-line-soft p-4">
            <label className="block">
              <span className="mb-1 block text-xs font-medium text-ink-muted">Subject</span>
              <select className={`${inputClass} w-48`} value={subjectId} disabled={conversationId !== null} onChange={(e) => setSubjectId(e.target.value)}>
                <option value="">Any subject</option>
                {subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </label>
            <div className="flex gap-1" role="group" aria-label="Mode">
              <Button variant={mode === 'chat' ? 'primary' : 'secondary'} onClick={() => setMode('chat')}>Explain</Button>
              <Button variant={mode === 'practice' ? 'primary' : 'secondary'} onClick={() => setMode('practice')}>Practise</Button>
            </div>
            {conversationId && <Button variant="secondary" className="ml-auto" onClick={fresh}>New conversation</Button>}
          </div>

          <div className="flex-1 space-y-3 overflow-y-auto p-4" aria-live="polite">
            {turns.length === 0 ? (
              <div className="py-8 text-center">
                <p className="font-headline font-semibold text-brand">What are you working on?</p>
                <p className="mt-1 text-sm text-ink-muted">{mode === 'practice' ? 'Name a topic and the tutor will set you questions one at a time.' : 'Ask about anything from your lessons.'}</p>
                <div className="mt-4 flex flex-wrap justify-center gap-2">
                  {STARTERS.map((s) => (
                    <button key={s} type="button" onClick={() => send(s)} className="rounded-full border border-line px-3 py-1.5 text-sm hover:border-brand hover:text-brand">{s}</button>
                  ))}
                </div>
              </div>
            ) : turns.map((t, i) => (
              <div key={i} className={`flex ${t.role === 'student' ? 'justify-end' : 'justify-start'}`}>
                <p className={`max-w-[80%] whitespace-pre-wrap rounded-2xl px-4 py-2.5 text-sm ${t.role === 'student' ? 'bg-brand text-white' : 'bg-surface-raised text-ink'}`}>{t.body}</p>
              </div>
            ))}
            {sending && <p className="text-xs text-ink-muted">Thinking…</p>}
            <div ref={end} />
          </div>

          <div className="border-t border-line-soft p-4">
            <ErrorNote message={error} />
            <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); void send(); }}>
              <textarea
                className={`${inputClass} min-h-[2.75rem] resize-y`}
                rows={2}
                maxLength={4000}
                value={input}
                placeholder={mode === 'practice' ? 'A topic to practise, e.g. "indices"' : 'Ask a question'}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void send(); } }}
              />
              <Button type="submit" disabled={sending || !input.trim()}>Send</Button>
            </form>
            <p className="mt-2 text-xs text-ink-faint">The tutor is an AI and can make mistakes. Check important answers with your teacher.</p>
          </div>
        </section>

        <aside className="space-y-5">
          <Card title="Past conversations">
            {!history ? <Loading /> : history.length === 0 ? <p className="text-sm text-ink-muted">None yet.</p> : (
              <ul className="space-y-1 text-sm">
                {history.map((c) => (
                  <li key={c.id}>
                    <button type="button" onClick={() => open(c.id)} className={`w-full rounded-lg px-2 py-1.5 text-left hover:bg-surface-sunken ${c.id === conversationId ? 'bg-surface-sunken' : ''}`}>
                      <span className="block truncate font-medium">{c.title || 'Conversation'}</span>
                      <span className="text-xs text-ink-muted">{[c.subject?.name, c.last_message_at && day(c.last_message_at)].filter(Boolean).join(' · ')}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <Card title="Topic mastery" description="From your marked work and practice.">
            {!mastery ? <Loading /> : [...mastery.needs_work, ...mastery.developing, ...mastery.mastered].length === 0 ? <p className="text-sm text-ink-muted">Nothing measured yet.</p> : (
              <ul className="space-y-3 text-sm">
                {[...mastery.needs_work, ...mastery.developing, ...mastery.mastered].slice(0, 12).map((m) => (
                  <li key={`${m.subject}-${m.topic}`}>
                    <div className="mb-1 flex justify-between gap-2">
                      <span className="truncate">{m.topic}{m.subject && <span className="text-ink-muted"> · {m.subject}</span>}</span>
                      <Badge tone={m.mastery_percentage >= 75 ? 'green' : m.mastery_percentage >= 50 ? 'amber' : 'red'}>{Math.round(m.mastery_percentage)}%</Badge>
                    </div>
                    <Meter value={m.mastery_percentage} tone={m.mastery_percentage >= 75 ? 'green' : m.mastery_percentage >= 50 ? 'amber' : 'red'} />
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </aside>
      </div>
    </PageShell>
  );
}
