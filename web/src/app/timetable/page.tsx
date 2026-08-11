'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Loading, PageShell, Table } from '@/components/Page';

/**
 * Timetable builder (§7.5).
 *
 * The solver already exists server-side; this is the screen that runs it,
 * shows the versions it produced, and publishes one. Until now the sidebar
 * linked here and the route 404'd.
 *
 * Generating is deliberately a two-step flow — generate, inspect, then publish.
 * A published timetable is what teachers and parents see, so overwriting it
 * from a single click would be the wrong shape for the one screen where a
 * mistake is visible to the whole school at once.
 */

type Version = {
  id: number;
  created_at?: string;
  status?: string;
  is_published?: boolean;
  clashes?: number;
  conflicts?: number;
  label?: string;
};

type Slot = {
  day?: string;
  period?: string | number;
  subject?: string;
  teacher?: string;
  class_name?: string;
  start_time?: string;
  end_time?: string;
};

export default function TimetablePage() {
  const [versions, setVersions] = useState<Version[]>([]);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [publishingId, setPublishingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // No state is set before the first await — a synchronous setState in the
  // effect body below would trigger a cascading re-render.
  const load = useCallback(async () => {
    try {
      const [list, view] = await Promise.all([
        fetchApi<{ data?: Version[] } | Version[]>('/timetable/versions'),
        // The published view is what everyone else sees; an empty one is a
        // normal state before the first publish, not an error.
        fetchApi<{ data?: Slot[] } | Slot[]>('/timetable/view').catch(() => [] as Slot[]),
      ]);

      setVersions(Array.isArray(list) ? list : (list.data ?? []));
      setSlots(Array.isArray(view) ? view : (view.data ?? []));
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not load the timetable.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const generate = async () => {
    setGenerating(true);
    setError(null);
    setNotice(null);

    try {
      await fetchApi('/timetable/generate', { method: 'POST', body: JSON.stringify({}) });
      setNotice('A new draft version has been generated. Review it below, then publish when it looks right.');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The solver could not produce a timetable.');
    } finally {
      setGenerating(false);
    }
  };

  const publish = async (id: number) => {
    setPublishingId(id);
    setError(null);
    setNotice(null);

    try {
      await fetchApi(`/timetable/versions/${id}/publish`, { method: 'POST', body: JSON.stringify({}) });
      setNotice('Published. Teachers and parents now see this version.');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not publish that version.');
    } finally {
      setPublishingId(null);
    }
  };

  const isPublished = (v: Version) => v.is_published === true || v.status === 'published';

  return (
    <PageShell
      title="Timetable"
      subtitle="Generate draft timetables, review them, and publish one."
      actions={
        <Button onClick={generate} disabled={generating}>
          {generating ? 'Generating…' : 'Generate a new draft'}
        </Button>
      }
    >
      <ErrorNote message={error} />
      {notice && (
        <p className="mb-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>
      )}

      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          <Card title="Versions" description="Only one version is live at a time. Publishing replaces the current one.">
            {versions.length === 0 ? (
              <Empty>No timetable has been generated yet. Use “Generate a new draft” to produce one.</Empty>
            ) : (
              <Table columns={['Version', 'Created', 'Clashes', 'Status', '']}>
                {versions.map((v) => (
                  <tr key={v.id}>
                    <Cell className="font-medium text-slate-900">{v.label ?? `Version ${v.id}`}</Cell>
                    <Cell>{v.created_at?.slice(0, 16).replace('T', ' ') ?? '—'}</Cell>
                    <Cell>
                      {/* A draft with clashes can still be published, but the
                          bursar should see the number before deciding. */}
                      {(v.clashes ?? v.conflicts ?? 0) > 0 ? (
                        <Badge tone="amber">{v.clashes ?? v.conflicts}</Badge>
                      ) : (
                        <Badge tone="green">none</Badge>
                      )}
                    </Cell>
                    <Cell>
                      {isPublished(v) ? <Badge tone="green">Live</Badge> : <Badge tone="slate">Draft</Badge>}
                    </Cell>
                    <Cell>
                      {!isPublished(v) && (
                        <Button
                          variant="secondary"
                          disabled={publishingId === v.id}
                          onClick={() => publish(v.id)}
                        >
                          {publishingId === v.id ? 'Publishing…' : 'Publish'}
                        </Button>
                      )}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>

          <Card title="Currently published" description="What teachers, students and parents see right now.">
            {slots.length === 0 ? (
              <Empty>Nothing is published yet.</Empty>
            ) : (
              <Table columns={['Day', 'Period', 'Class', 'Subject', 'Teacher']}>
                {slots.map((s, i) => (
                  <tr key={i}>
                    <Cell className="capitalize">{s.day ?? '—'}</Cell>
                    <Cell>
                      {s.start_time && s.end_time ? `${s.start_time}–${s.end_time}` : (s.period ?? '—')}
                    </Cell>
                    <Cell>{s.class_name ?? '—'}</Cell>
                    <Cell className="font-medium text-slate-900">{s.subject ?? '—'}</Cell>
                    <Cell>{s.teacher ?? '—'}</Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}
    </PageShell>
  );
}
