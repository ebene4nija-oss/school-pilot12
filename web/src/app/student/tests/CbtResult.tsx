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
  questions?: {
    question_id: number; question: string | null; is_correct: boolean | null; awarded_marks: number; explanation: string | null; feedback: string | null;
    your_response?: Record<string, unknown> | null;
    // Only when the school shows the correct options and the test has closed.
    correct_answer?: unknown; options?: Record<string, string> | null;
  }[];
  review?: { answers: boolean; correct_answers: boolean; correct_answers_after_close?: boolean };
}

/** "B. 48,000" for a chosen option; the typed text or value otherwise. */
function answerText(value: unknown, options?: Record<string, string> | null): string {
  if (value === null || value === undefined || value === '') return '— (left blank)';
  if (Array.isArray(value)) return value.map((x) => answerText(x, options)).join(', ');
  if (typeof value === 'object') {
    const v = value as Record<string, unknown>;
    const pick = v.option ?? v.selected_option ?? v.options ?? v.value ?? v.text;
    if (pick !== undefined) return answerText(pick, options);
    return JSON.stringify(value);
  }
  const key = String(value);
  return options?.[key] !== undefined ? `${key}. ${options[key]}` : key;
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

      {r.review?.correct_answers_after_close && (
        <p className="mt-4 rounded-lg bg-info-soft px-3 py-2 text-xs text-info">The correct answers will show here after the test closes.</p>
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
                {q.your_response !== undefined && <p className="mt-2">Your answer: <span className="font-medium">{answerText(q.your_response, q.options)}</span></p>}
                {q.correct_answer !== undefined && <p className="mt-1 text-positive">Correct answer: <span className="font-medium">{answerText(q.correct_answer, q.options)}</span></p>}
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
