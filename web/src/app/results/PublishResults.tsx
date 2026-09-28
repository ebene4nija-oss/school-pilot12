'use client';

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Loading, Meter, Table } from '@/components/Page';

/**
 * Term-end: turn the scores into report cards, check them, and release them.
 *
 * Generation is queued on the server — a class of 60 is too many dompdf
 * renders for one request — so this starts a batch and polls it. Releasing is
 * a separate, deliberate step: generating a card does not show it to a parent.
 */

interface GeneratedRow {
  student_id: number;
  name: string | null;
  admission_number: string | null;
  ready: boolean;
  generated_at: string | null;
  pending_ai_comments: number;
}

interface BatchStatus {
  batch_id: string;
  total: number;
  processed: number;
  failed: number;
  progress_percentage: number;
  finished: boolean;
  cancelled: boolean;
}

interface Release {
  term_id: number;
  class_id: number | null;
  is_released: boolean;
  released_at: string | null;
}

export default function PublishResults({
  classId,
  termId,
  className,
  termName,
  onReviewComments,
}: {
  classId: string;
  termId: string;
  className: string;
  termName: string;
  onReviewComments: () => void;
}) {
  const [rows, setRows] = useState<GeneratedRow[] | null>(null);
  const [releases, setReleases] = useState<Release[]>([]);
  const [batch, setBatch] = useState<BatchStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const poll = useRef<ReturnType<typeof setTimeout> | null>(null);

  /** Fetchers return data; callers own the state. */
  const fetchAll = useCallback(async () => {
    const [list, rel] = await Promise.all([
      fetchApi<{ data: GeneratedRow[] }>(`/report-cards/generated?term_id=${termId}&class_id=${classId}`),
      fetchApi<{ releases: Release[] }>('/results/releases'),
    ]);
    return { rows: list.data ?? [], releases: rel.releases ?? [] };
  }, [classId, termId]);

  const load = useCallback(async () => {
    const next = await fetchAll();
    setRows(next.rows);
    setReleases(next.releases);
  }, [fetchAll]);

  useEffect(() => {
    // The parent keys this component by class and term, so a change of
    // either remounts it with fresh state rather than resetting here.
    let cancelled = false;

    (async () => {
      try {
        const next = await fetchAll();
        if (cancelled) return;
        setRows(next.rows);
        setReleases(next.releases);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Could not load report cards.');
      }
    })();

    return () => {
      cancelled = true;
      if (poll.current) clearTimeout(poll.current);
    };
  }, [fetchAll]);

  const watch = useCallback(
    (batchId: string) => {
      const tick = async () => {
        try {
          const status = await fetchApi<BatchStatus>(`/jobs/${batchId}`);
          setBatch(status);

          if (status.finished || status.cancelled) {
            await load();
            setNotice(
              status.failed > 0
                ? `${status.processed} card(s) generated, ${status.failed} could not be. See the list below for why.`
                : `${status.processed} report card(s) generated.`,
            );
            return;
          }
        } catch (err) {
          setError(err instanceof Error ? err.message : 'Lost track of the generation run.');
          return;
        }

        poll.current = setTimeout(tick, 2000);
      };

      tick();
    },
    [load],
  );

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      await action();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That action could not be completed.');
    } finally {
      setBusy(false);
    }
  }

  function generate() {
    run(async () => {
      const res = await fetchApi<{ batch_id: string }>('/report-cards/generate', {
        method: 'POST',
        body: JSON.stringify({ term_id: Number(termId), class_id: Number(classId) }),
      });
      watch(res.batch_id);
    });
  }

  /** `wholeSchool` targets the school-wide release row rather than this class's. */
  function setReleased(isReleased: boolean, wholeSchool = false) {
    const scope = wholeSchool ? 'the whole school' : className;
    const prompt = isReleased
      ? `Release ${termName} results for ${scope}? Parents will be able to check them.`
      : `Withdraw ${termName} results for ${scope}? Parents will no longer be able to open them.`;

    if (!window.confirm(prompt)) return;

    run(async () => {
      const res = await fetchApi<{ message: string }>('/results/release', {
        method: 'POST',
        body: JSON.stringify({
          term_id: Number(termId),
          class_id: wholeSchool ? null : Number(classId),
          is_released: isReleased,
        }),
      });
      setNotice(res.message);
      await load();
    });
  }

  const running = batch !== null && !batch.finished && !batch.cancelled;
  const ready = rows?.filter((r) => r.ready).length ?? 0;
  const blocked = rows?.reduce((n, r) => n + r.pending_ai_comments, 0) ?? 0;

  // Mirrors ResultRelease::isReleasedFor: a school-wide release covers every
  // class, so withdrawing only this class cannot undo it.
  const classReleased = releases.some(
    (r) => r.term_id === Number(termId) && r.class_id === Number(classId) && r.is_released,
  );
  const schoolReleased = releases.some((r) => r.term_id === Number(termId) && r.class_id === null && r.is_released);
  const released = classReleased || schoolReleased;

  return (
    <div className="space-y-6">
      <ErrorNote message={error} />

      {notice && (
        <p role="status" className="rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      <Card
        title="1. Generate report cards"
        description={`Builds a PDF report card for every active student in ${className} for ${termName}, using your active template. Run it again after correcting a score — it replaces the old cards.`}
      >
        {blocked > 0 && (
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-caution-soft px-4 py-3 text-sm text-caution">
            <span>
              {blocked} AI comment(s) in this class are still awaiting review. Those students&apos; cards
              cannot be generated until a teacher approves or edits them.
            </span>
            <Button variant="secondary" onClick={onReviewComments}>
              Review comments
            </Button>
          </div>
        )}

        {running ? (
          <div className="space-y-2">
            <p className="text-sm text-ink">
              Generating… {batch.processed + batch.failed} of {batch.total}
            </p>
            <Meter value={batch.progress_percentage} tone="green" />
            <p className="text-xs text-ink-muted">You can leave this page; the run carries on without it.</p>
          </div>
        ) : (
          <Button variant="accent" disabled={busy} onClick={generate}>
            {busy ? 'Starting…' : ready > 0 ? 'Regenerate report cards' : 'Generate report cards'}
          </Button>
        )}
      </Card>

      <Card
        title="2. Check the cards"
        description={rows ? `${ready} of ${rows.length} ready.` : undefined}
      >
        {rows === null ? (
          <Loading />
        ) : rows.length === 0 ? (
          <Empty>No active students in this class.</Empty>
        ) : (
          <Table columns={['Student', 'Status', '']}>
            {rows.map((r) => (
              <tr key={r.student_id}>
                <Cell className="font-medium text-brand">
                  {r.name ?? `Student #${r.student_id}`}
                  {r.admission_number && <span className="block text-xs text-ink-muted">{r.admission_number}</span>}
                </Cell>
                <Cell>
                  {r.ready ? (
                    <Badge tone="green">Ready</Badge>
                  ) : r.pending_ai_comments > 0 ? (
                    <Badge tone="amber">{r.pending_ai_comments} comment(s) awaiting review</Badge>
                  ) : (
                    <Badge>Not generated</Badge>
                  )}
                  {r.ready && r.generated_at && (
                    <span className="ml-2 text-xs text-ink-muted">
                      {new Date(r.generated_at).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' })}
                    </span>
                  )}
                </Cell>
                <Cell className="text-right">
                  {r.ready && (
                    <a
                      href={`/api/backend/report-cards/generated/${r.student_id}/${termId}`}
                      target="_blank"
                      rel="noopener"
                      className="text-sm font-semibold text-brand hover:underline"
                    >
                      Open PDF
                    </a>
                  )}
                </Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card
        title="3. Release to parents"
        description="Parents can only check results once they are released. You can withdraw them again if a mistake turns up."
      >
        <div className="flex flex-wrap items-center gap-3">
          {released ? (
            <Badge tone="green">Released{schoolReleased ? ' (whole school)' : ''}</Badge>
          ) : (
            <Badge>Not released</Badge>
          )}

          {released ? (
            <Button variant="danger" disabled={busy || running} onClick={() => setReleased(false, schoolReleased)}>
              {schoolReleased ? 'Withdraw for the whole school' : 'Withdraw results'}
            </Button>
          ) : (
            <Button disabled={busy || running} onClick={() => setReleased(true)}>
              Release results for {className}
            </Button>
          )}
        </div>

        {!released && rows && rows.length > 0 && ready < rows.length && (
          <p className="mt-3 text-xs text-caution">
            {rows.length - ready} student(s) in this class do not have a generated card yet.
          </p>
        )}
      </Card>
    </div>
  );
}
