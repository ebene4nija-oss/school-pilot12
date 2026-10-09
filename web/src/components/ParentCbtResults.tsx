'use client';

import React, { useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Card, Cell, Table } from '@/components/Page';

/**
 * A parent's view of each child's CBT scores, on papers the school has
 * released. One paper's score, not the term's report card. Renders nothing
 * for a child with no released papers, or a school without CBT.
 */

interface Row {
  exam_id: number; title: string; subject: string | null; submitted_at: string | null;
  raw_score: number; max_score: number; percentage: number; grade: string | null; passed: boolean; provisional: boolean;
}

function ChildCbt({ studentId, name }: { studentId: number; name: string | null }) {
  const [rows, setRows] = useState<Row[]>([]);

  useEffect(() => {
    fetchApi<{ data: Row[] }>(`/parent/children/${studentId}/cbt-results`)
      .then((r) => setRows(r.data))
      .catch(() => setRows([]));
  }, [studentId]);

  if (rows.length === 0) return null;

  return (
    <Card className="mt-6" title={`${name ?? 'Child'}'s CBT results`} description="Scores on tests the school has released. These are separate from the term report card.">
      <Table columns={['Test', 'Score', 'Result']}>
        {rows.map((r) => (
          <tr key={r.exam_id}>
            <Cell className="font-medium">
              {r.title}
              <span className="block text-xs text-ink-muted">
                {[r.subject, r.submitted_at && new Date(r.submitted_at).toLocaleDateString('en-NG', { day: 'numeric', month: 'short' })].filter(Boolean).join(' · ')}
              </span>
            </Cell>
            <Cell>
              {r.raw_score} / {r.max_score} <span className="text-ink-muted">({Math.round(r.percentage)}%)</span>
              {r.provisional && <span className="block text-xs text-ink-muted">Written answers still being marked</span>}
            </Cell>
            <Cell><Badge tone={r.passed ? 'green' : 'amber'}>{r.grade ?? (r.passed ? 'Pass' : 'Below pass mark')}</Badge></Cell>
          </tr>
        ))}
      </Table>
    </Card>
  );
}

export default function ParentCbtResults({ kids }: { kids: { id: number; name: string | null }[] }) {
  return (
    <>
      {kids.map((c) => <ChildCbt key={c.id} studentId={c.id} name={c.name} />)}
    </>
  );
}
