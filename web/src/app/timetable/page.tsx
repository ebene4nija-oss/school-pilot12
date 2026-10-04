'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Loading, PageShell, Table, inputClass } from '@/components/Page';
import TimetableCover from '@/components/TimetableCover';
import TimetableVenues from '@/components/TimetableVenues';

/**
 * Timetable builder (§7.5).
 *
 * Generating is deliberately a two-step flow — generate, inspect, then publish.
 * A published timetable is what teachers and parents see, so overwriting it
 * from a single click would be the wrong shape for the one screen where a
 * mistake is visible to the whole school at once.
 *
 * The generator takes the school's classes and subject teachers from its own
 * records, so the request body is empty. A class with no subject teachers is
 * skipped and named in the notice.
 */

type Version = {
  id: number;
  name?: string;
  created_at?: string;
  status?: string;
  entries_count?: number;
};

type Entry = {
  school_class_id: number;
  class_name?: string | null;
  day_of_week: string;
  slot_name: string;
  start_time?: string | null;
  end_time?: string | null;
  subject_name?: string | null;
  teacher_name?: string | null;
  venue_name?: string | null;
  is_break: boolean | number;
};

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

const hhmm = (t?: string | null) => (t ? t.slice(0, 5) : '');

export default function TimetablePage() {
  const [versions, setVersions] = useState<Version[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [entries, setEntries] = useState<Entry[]>([]);
  const [classId, setClassId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingEntries, setLoadingEntries] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [publishingId, setPublishingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // No state is set before the first await — a synchronous setState in the
  // effect body below would trigger a cascading re-render.
  const loadVersions = useCallback(async (select?: number) => {
    try {
      const res = await fetchApi<{ versions?: Version[] }>('/timetable/versions');
      const list = res.versions ?? [];
      setVersions(list);
      // Show what was asked for, else what is live, else the newest draft.
      const live = list.find((v) => v.status === 'published');
      setSelectedId((current) => select ?? current ?? live?.id ?? list[0]?.id ?? null);
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load the timetable.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await loadVersions();
    })();
  }, [loadVersions]);

  useEffect(() => {
    if (selectedId === null) return;

    let cancelled = false;
    void (async () => {
      setLoadingEntries(true);
      try {
        const res = await fetchApi<{ entries?: Entry[] }>(`/timetable/view?version_id=${selectedId}`);
        if (!cancelled) setEntries(res.entries ?? []);
      } catch (err) {
        if (!cancelled) setError(errorText(err, 'Could not load that version.'));
      } finally {
        if (!cancelled) setLoadingEntries(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [selectedId]);

  // Classes in this version, in the order the generator produced them.
  const classes = useMemo(() => {
    const seen = new Map<number, string>();
    entries.forEach((e) => {
      if (!seen.has(e.school_class_id)) seen.set(e.school_class_id, e.class_name ?? `Class ${e.school_class_id}`);
    });
    return [...seen.entries()].map(([id, name]) => ({ id, name }));
  }, [entries]);

  const shownClassId = classId !== null && classes.some((c) => c.id === classId) ? classId : (classes[0]?.id ?? null);

  // Rows of the grid: one per period, in time order; each cell by day.
  const grid = useMemo(() => {
    const forClass = entries.filter((e) => e.school_class_id === shownClassId);
    const slots: { name: string; start: string; end: string; isBreak: boolean }[] = [];
    const cells = new Map<string, Entry>();

    forClass.forEach((e) => {
      if (!slots.some((s) => s.name === e.slot_name)) {
        slots.push({ name: e.slot_name, start: hhmm(e.start_time), end: hhmm(e.end_time), isBreak: Boolean(e.is_break) });
      }
      cells.set(`${e.day_of_week}|${e.slot_name}`, e);
    });

    return { slots, cells };
  }, [entries, shownClassId]);

  const generate = async () => {
    setGenerating(true);
    setError(null);
    setNotice(null);

    try {
      const res = await fetchApi<{ version?: Version; skipped?: string[] }>('/timetable/generate', {
        method: 'POST',
        body: JSON.stringify({}),
      });
      const skipped = res.skipped ?? [];
      setNotice(
        'A new draft has been generated. Check it below, then publish when it looks right.' +
          (skipped.length > 0
            ? ` Not included, because no subject teachers are assigned: ${skipped.join(', ')}.`
            : ''),
      );
      await loadVersions(res.version?.id);
    } catch (err) {
      setError(errorText(err, 'The timetable could not be generated.'));
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
      setNotice('Published. Teachers, students and parents now see this version.');
      await loadVersions(id);
    } catch (err) {
      setError(errorText(err, 'Could not publish that version.'));
    } finally {
      setPublishingId(null);
    }
  };

  const selected = versions.find((v) => v.id === selectedId);

  return (
    <PageShell
      title="Timetable"
      subtitle="Generate draft timetables, check them, and publish one."
      actions={
        <Button onClick={generate} disabled={generating}>
          {generating ? 'Generating…' : 'Generate a new draft'}
        </Button>
      }
    >
      <ErrorNote message={error} />
      {notice && (
        <p className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>
      )}

      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          <Card title="Versions" description="Only one version is live at a time. Publishing replaces the current one.">
            {versions.length === 0 ? (
              <Empty>
                No timetable has been generated yet. Use “Generate a new draft” to build one from your classes and
                subject teachers.
              </Empty>
            ) : (
              <Table columns={['Version', 'Created', 'Status', '']}>
                {versions.map((v) => (
                  <tr key={v.id} className={v.id === selectedId ? 'bg-surface-sunken' : ''}>
                    <Cell className="font-medium text-brand">{v.name ?? `Version ${v.id}`}</Cell>
                    <Cell>{v.created_at?.slice(0, 16).replace('T', ' ') ?? '—'}</Cell>
                    <Cell>
                      {v.status === 'published' ? <Badge tone="green">Live</Badge> : <Badge tone="slate">Draft</Badge>}
                    </Cell>
                    <Cell>
                      <div className="flex justify-end gap-2">
                        {v.id !== selectedId && (
                          <Button variant="secondary" onClick={() => setSelectedId(v.id)}>
                            View
                          </Button>
                        )}
                        {v.status !== 'published' && (
                          <Button disabled={publishingId === v.id} onClick={() => publish(v.id)}>
                            {publishingId === v.id ? 'Publishing…' : 'Publish'}
                          </Button>
                        )}
                      </div>
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>

          {selected && (
            <Card
              title={`${selected.name ?? `Version ${selected.id}`}${selected.status === 'published' ? ' (live)' : ' (draft)'}`}
              description="One class at a time, Monday to Friday."
            >
              {loadingEntries ? (
                <Loading />
              ) : classes.length === 0 ? (
                <Empty>This version has no periods.</Empty>
              ) : (
                <>
                  <label className="mb-4 block max-w-xs text-sm">
                    <span className="mb-1 block text-ink-muted">Class</span>
                    <select
                      value={shownClassId ?? ''}
                      onChange={(e) => setClassId(Number(e.target.value))}
                      className={inputClass}
                    >
                      {classes.map((c) => (
                        <option key={c.id} value={c.id}>
                          {c.name}
                        </option>
                      ))}
                    </select>
                  </label>

                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[44rem] border-collapse text-left text-sm">
                      <thead>
                        <tr className="border-b border-line-soft text-xs uppercase tracking-wide text-ink-faint">
                          <th className="px-3 py-2 font-medium">Period</th>
                          {DAYS.map((d) => (
                            <th key={d} className="px-3 py-2 font-medium">
                              {d}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {grid.slots.map((slot) =>
                          slot.isBreak ? (
                            <tr key={slot.name} className="border-b border-line-soft bg-surface-sunken">
                              <td className="px-3 py-2 text-ink-muted">
                                {slot.start && `${slot.start}–${slot.end}`}
                              </td>
                              <td colSpan={DAYS.length} className="px-3 py-2 text-center text-xs uppercase tracking-wide text-ink-faint">
                                {slot.name}
                              </td>
                            </tr>
                          ) : (
                            <tr key={slot.name} className="border-b border-line-soft align-top">
                              <td className="whitespace-nowrap px-3 py-2">
                                <div className="font-medium text-ink">{slot.name}</div>
                                {slot.start && (
                                  <div className="text-xs text-ink-faint">
                                    {slot.start}–{slot.end}
                                  </div>
                                )}
                              </td>
                              {DAYS.map((d) => {
                                const cell = grid.cells.get(`${d}|${slot.name}`);
                                return (
                                  <td key={d} className="px-3 py-2">
                                    <div className="font-medium text-brand">{cell?.subject_name ?? '—'}</div>
                                    {cell?.teacher_name && <div className="text-xs text-ink-muted">{cell.teacher_name}</div>}
                                    {cell?.venue_name && <div className="text-xs text-ink-faint">{cell.venue_name}</div>}
                                  </td>
                                );
                              })}
                            </tr>
                          ),
                        )}
                      </tbody>
                    </table>
                  </div>
                </>
              )}
            </Card>
          )}
        </div>
      )}
      <TimetableVenues />
      <TimetableCover />
    </PageShell>
  );
}
