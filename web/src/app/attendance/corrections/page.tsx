'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * Every change made to a register after it was first taken (TASK 12): who
 * changed which child's mark, from what to what, and why. Read-only — the
 * register itself is where marks are changed, and a reason is required.
 */

interface Row { date: string; from: string | null; to: string; reason: string | null; created_at: string; by: string | null; student: string | null }

export default function AttendanceCorrections() {
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([]);
  const [classId, setClassId] = useState('');
  const [rows, setRows] = useState<Row[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { fetchApi<{ data: { id: number; name: string }[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined); }, []);

  const load = useCallback(async () => {
    try {
      setRows((await fetchApi<{ data: Row[] }>(`/attendance/corrections${classId ? `?class_id=${classId}` : ''}`)).data);
    } catch (err) {
      setError(errorText(err, 'Could not load the changes.'));
    }
  }, [classId]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  return (
    <PageShell title="Attendance changes" subtitle="Marks changed after the register was taken, with who changed them and why.">
      <ErrorNote message={error} />
      <div className="mb-4 max-w-xs"><Field label="Class"><select className={inputClass} value={classId} onChange={(e) => setClassId(e.target.value)}><option value="">All classes</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field></div>
      {!rows ? <Loading /> : rows.length === 0 ? <Empty>No marks have been changed.</Empty> : (
        <Table columns={['Day', 'Student', 'Change', 'Reason', 'By', 'When']}>
          {rows.map((r, i) => (
            <tr key={i}>
              <Cell>{r.date?.slice(0, 10)}</Cell>
              <Cell className="font-medium">{r.student}</Cell>
              <Cell>{r.from ?? 'unmarked'} → <span className="font-medium">{r.to}</span></Cell>
              <Cell className="text-sm">{r.reason}</Cell>
              <Cell>{r.by}</Cell>
              <Cell className="text-xs text-ink-muted">{r.created_at?.slice(0, 16).replace('T', ' ')}</Cell>
            </tr>
          ))}
        </Table>
      )}
    </PageShell>
  );
}
