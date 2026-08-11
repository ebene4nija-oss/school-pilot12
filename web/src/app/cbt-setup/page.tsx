'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * CBT exam setup (§7.16).
 *
 * Authoring questions — including images and LaTeX — is documented in
 * docs/cbt-authoring-guide.md and fully supported by CbtController. There was
 * no screen for any of it: the sidebar linked to /cbt-setup and the route did
 * not exist, so a school could not create an exam without calling the API by
 * hand.
 */

type Exam = {
  id: number;
  title: string;
  subject?: string;
  class_name?: string;
  duration_minutes?: number;
  status?: string;
  question_count?: number;
  starts_at?: string;
};

export default function CbtSetupPage() {
  const [exams, setExams] = useState<Exam[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);

  const [title, setTitle] = useState('');
  const [subject, setSubject] = useState('');
  const [duration, setDuration] = useState('45');

  // No state is set before the first await — a synchronous setState in the
  // effect body below would trigger a cascading re-render.
  const load = useCallback(async () => {
    try {
      const data = await fetchApi<{ data?: Exam[] } | Exam[]>('/cbt/exams');
      setExams(Array.isArray(data) ? data : (data.data ?? []));
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not load the exam list.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setCreating(true);
    setError(null);
    setNotice(null);

    try {
      await fetchApi('/cbt/exams', {
        method: 'POST',
        body: JSON.stringify({
          title,
          subject,
          duration_minutes: Number(duration) || 45,
        }),
      });

      setNotice('Draft exam created. Add questions to it before publishing.');
      setTitle('');
      setSubject('');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not create that exam.');
    } finally {
      setCreating(false);
    }
  };

  const act = async (id: number, action: 'publish' | 'close') => {
    setBusyId(id);
    setError(null);
    setNotice(null);

    try {
      await fetchApi(`/cbt/exams/${id}/${action}`, { method: 'POST', body: JSON.stringify({}) });
      setNotice(
        action === 'publish'
          ? 'Published. Students in the assigned class can now sit this exam.'
          : 'Closed. No further attempts can be started.',
      );
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : `Could not ${action} that exam.`);
    } finally {
      setBusyId(null);
    }
  };

  return (
    <PageShell
      title="CBT exams"
      subtitle="Create exams, publish them to a class, and close them when the window ends."
    >
      <ErrorNote message={error} />
      {notice && <p className="mb-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}

      <div className="grid gap-6 xl:grid-cols-3">
        <Card title="New exam" className="h-fit">
          <form onSubmit={create} className="space-y-4">
            <Field label="Title">
              <input
                required
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="Second term Mathematics CA"
                className={inputClass}
              />
            </Field>

            <Field label="Subject">
              <input
                required
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                placeholder="Mathematics"
                className={inputClass}
              />
            </Field>

            <Field label="Duration (minutes)">
              <input
                type="number"
                min={5}
                max={240}
                value={duration}
                onChange={(e) => setDuration(e.target.value)}
                className={inputClass}
              />
            </Field>

            <Button type="submit" disabled={creating} className="w-full">
              {creating ? 'Creating…' : 'Create draft'}
            </Button>

            <p className="text-xs text-slate-500">
              Questions support images and LaTeX — see the authoring guide in{' '}
              <code className="rounded bg-slate-100 px-1">docs/cbt-authoring-guide.md</code>.
            </p>
          </form>
        </Card>

        <Card title="Exams" className="xl:col-span-2">
          {loading ? (
            <Loading />
          ) : exams.length === 0 ? (
            <Empty>No exams yet. Create a draft to get started.</Empty>
          ) : (
            <Table columns={['Title', 'Subject', 'Questions', 'Length', 'Status', '']}>
              {exams.map((exam) => {
                const status = exam.status ?? 'draft';

                return (
                  <tr key={exam.id}>
                    <Cell className="font-medium text-slate-900">{exam.title}</Cell>
                    <Cell>{exam.subject ?? '—'}</Cell>
                    <Cell>{exam.question_count ?? 0}</Cell>
                    <Cell>{exam.duration_minutes ? `${exam.duration_minutes} min` : '—'}</Cell>
                    <Cell>
                      <Badge tone={status === 'published' ? 'green' : status === 'closed' ? 'slate' : 'amber'}>
                        {status}
                      </Badge>
                    </Cell>
                    <Cell>
                      {status === 'draft' && (
                        <Button
                          variant="secondary"
                          disabled={busyId === exam.id || (exam.question_count ?? 0) === 0}
                          // Publishing an exam with no questions would let a
                          // class sit an empty paper.
                          title={
                            (exam.question_count ?? 0) === 0 ? 'Add at least one question first' : undefined
                          }
                          onClick={() => act(exam.id, 'publish')}
                        >
                          Publish
                        </Button>
                      )}
                      {status === 'published' && (
                        <Button
                          variant="secondary"
                          disabled={busyId === exam.id}
                          onClick={() => act(exam.id, 'close')}
                        >
                          Close
                        </Button>
                      )}
                    </Cell>
                  </tr>
                );
              })}
            </Table>
          )}
        </Card>
      </div>
    </PageShell>
  );
}
