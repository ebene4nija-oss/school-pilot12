'use client';

import React from 'react';
import { Badge, Card, Meter } from '@/components/Page';
import { RichText } from '@/components/RichContent';

/**
 * A submitted paper's result, as the candidate is allowed to see it.
 *
 * Before the school releases it there is only "submitted". After release:
 * the score, a topic breakdown, and each answer marked right or wrong with
 * the teacher's explanation — never the marking scheme itself, which the API
 * keeps for staff.
 */

export interface ResultPayload {
  exam_title: string;
  status: string;
  submitted_at: string | null;
  requires_manual_grading: boolean;
  message?: string;
  raw_score?: number;
  max_score?: number;
  percentage?: number;
  grade?: string | null;
  passed?: boolean;
  topic_breakdown?: { topic: string; answered: number; correct: number; mastery_percentage: number }[];
  questions?: { question_id: number; question: string | null; is_correct: boolean | null; awarded_marks: number; explanation: string | null; feedback: string | null }[];
}
export interface ResultResponse { released?: boolean; message?: string; result?: ResultPayload }

export default function CbtResult({ data }: { data: ResultResponse }) {
  const r = data.result;
  if (!r || r.percentage === undefined) {
    return (
      <Card title={r?.exam_title ?? 'Submitted'}>
        <p className="text-sm">{data.message ?? r?.message ?? 'Your paper was submitted. Results will be released by your school.'}</p>
      </Card>
    );
  }

  return (
    <Card title={r.exam_title}>
      <div className="flex flex-wrap items-end gap-6">
        <div>
          <p className="text-xs uppercase tracking-wide text-ink-faint">Score</p>
          <p className={`font-headline text-3xl font-bold ${r.passed ? 'text-positive' : 'text-negative'}`}>{r.percentage}%</p>
        </div>
        <div>
          <p className="text-xs uppercase tracking-wide text-ink-faint">Marks</p>
          <p className="font-headline text-xl font-semibold text-brand">{r.raw_score} / {r.max_score}</p>
        </div>
        {r.grade && <Badge tone="blue">Grade {r.grade}</Badge>}
        {r.requires_manual_grading && <Badge tone="amber">Written answers still being marked</Badge>}
      </div>

      {r.topic_breakdown && r.topic_breakdown.length > 0 && (
        <div className="mt-5">
          <p className="mb-2 text-sm font-semibold text-brand">By topic</p>
          <ul className="grid gap-3 sm:grid-cols-2">
            {r.topic_breakdown.map((t) => (
              <li key={t.topic} className="text-sm">
                <div className="mb-1 flex justify-between"><span>{t.topic}</span><span className="text-ink-muted">{t.correct}/{t.answered}</span></div>
                <Meter value={t.mastery_percentage} tone={t.mastery_percentage >= 75 ? 'green' : t.mastery_percentage >= 50 ? 'amber' : 'red'} />
              </li>
            ))}
          </ul>
        </div>
      )}

      {r.questions && r.questions.length > 0 && (
        <details className="mt-5">
          <summary className="cursor-pointer text-sm font-semibold text-brand">Review your answers</summary>
          <ol className="mt-3 space-y-3 text-sm">
            {r.questions.map((q, i) => (
              <li key={q.question_id} className="rounded-lg border border-line-soft p-3">
                <div className="flex items-start justify-between gap-2">
                  <span><span className="text-ink-muted">{i + 1}. </span><RichText content={q.question} /></span>
                  {q.is_correct === null ? <Badge tone="amber">to be marked</Badge> : q.is_correct ? <Badge tone="green">correct · {q.awarded_marks}</Badge> : <Badge tone="red">{q.awarded_marks > 0 ? `part · ${q.awarded_marks}` : 'wrong'}</Badge>}
                </div>
                {q.explanation && <p className="mt-2 text-ink-muted">{q.explanation}</p>}
                {q.feedback && <p className="mt-1">Teacher: {q.feedback}</p>}
              </li>
            ))}
          </ol>
        </details>
      )}
    </Card>
  );
}
