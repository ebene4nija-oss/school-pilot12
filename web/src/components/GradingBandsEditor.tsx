'use client';

import React from 'react';
import { inputClass } from '@/components/Page';

/**
 * Edit one grading scale: rows of lowest mark, highest mark, grade, remark.
 *
 * The server checks the rows cover 0–100 with no gap or overlap; this shows
 * the same problem as the rows are typed, so a school does not find out only
 * when it presses Save.
 */

export type Band = { min: number | string; max: number | string; grade: string; remark: string | null };

export const WAEC: Band[] = [
  { min: 75, max: 100, grade: 'A1', remark: 'Excellent' },
  { min: 70, max: 74, grade: 'B2', remark: 'Very Good' },
  { min: 65, max: 69, grade: 'B3', remark: 'Good' },
  { min: 60, max: 64, grade: 'C4', remark: 'Credit' },
  { min: 55, max: 59, grade: 'C5', remark: 'Credit' },
  { min: 50, max: 54, grade: 'C6', remark: 'Credit' },
  { min: 45, max: 49, grade: 'D7', remark: 'Pass' },
  { min: 40, max: 44, grade: 'E8', remark: 'Pass' },
  { min: 0, max: 39, grade: 'F9', remark: 'Fail' },
];

export const PRIMARY_EXAMPLE: Band[] = [
  { min: 70, max: 100, grade: 'A', remark: 'Excellent' },
  { min: 60, max: 69, grade: 'B', remark: 'Very good' },
  { min: 50, max: 59, grade: 'C', remark: 'Good' },
  { min: 40, max: 49, grade: 'D', remark: 'Fair' },
  { min: 0, max: 39, grade: 'E', remark: 'Poor' },
];

/** The first problem with a scale, in the server's words, or null. */
export function bandProblem(bands: Band[]): string | null {
  if (bands.length === 0) return 'Add at least one grade.';

  const seen = new Set<string>();
  for (const [i, b] of bands.entries()) {
    const min = Number(b.min);
    const max = Number(b.max);
    if (!b.grade.trim()) return `Row ${i + 1} has no grade.`;
    if (seen.has(b.grade.trim().toUpperCase())) return `Grade ${b.grade} appears twice.`;
    seen.add(b.grade.trim().toUpperCase());
    if (b.min === '' || b.max === '' || !Number.isInteger(min) || !Number.isInteger(max)) return `Row ${i + 1} needs whole-number lowest and highest marks.`;
    if (min < 0 || max > 100 || min > max) return `Row ${i + 1}: ${min} to ${max} is not a range between 0 and 100.`;
  }

  let expectedMax = 100;
  for (const b of [...bands].sort((a, z) => Number(z.max) - Number(a.max))) {
    if (Number(b.max) > expectedMax) return `${b.grade} (${b.min} to ${b.max}) overlaps the grade above it.`;
    if (Number(b.max) < expectedMax) return `No grade covers ${Number(b.max) + 1} to ${expectedMax}.`;
    expectedMax = Number(b.min) - 1;
  }

  return expectedMax === -1 ? null : `No grade covers 0 to ${expectedMax}.`;
}

/** Numbers as numbers, blank remarks as null — the shape the API takes. */
export function cleanBands(bands: Band[]) {
  return bands.map((b) => ({ min: Number(b.min), max: Number(b.max), grade: b.grade.trim(), remark: b.remark?.trim() || null }));
}

export default function GradingBandsEditor({
  bands,
  onChange,
  disabled = false,
}: {
  bands: Band[];
  onChange: (bands: Band[]) => void;
  disabled?: boolean;
}) {
  const set = (i: number, patch: Partial<Band>) => onChange(bands.map((b, j) => (j === i ? { ...b, ...patch } : b)));
  const problem = bandProblem(bands);

  return (
    <div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[28rem] text-sm">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wide text-ink-faint">
              <th className="pb-2 pr-2 font-medium">From</th>
              <th className="pb-2 pr-2 font-medium">To</th>
              <th className="pb-2 pr-2 font-medium">Grade</th>
              <th className="pb-2 pr-2 font-medium">Remark</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {bands.map((b, i) => (
              <tr key={i}>
                <td className="pb-2 pr-2">
                  <input type="number" min={0} max={100} value={b.min} disabled={disabled} onChange={(e) => set(i, { min: e.target.value })} className={`${inputClass} w-20`} aria-label={`Row ${i + 1} lowest mark`} />
                </td>
                <td className="pb-2 pr-2">
                  <input type="number" min={0} max={100} value={b.max} disabled={disabled} onChange={(e) => set(i, { max: e.target.value })} className={`${inputClass} w-20`} aria-label={`Row ${i + 1} highest mark`} />
                </td>
                <td className="pb-2 pr-2">
                  <input value={b.grade} maxLength={5} disabled={disabled} onChange={(e) => set(i, { grade: e.target.value.toUpperCase() })} className={`${inputClass} w-20`} aria-label={`Row ${i + 1} grade`} />
                </td>
                <td className="pb-2 pr-2">
                  <input value={b.remark ?? ''} maxLength={40} disabled={disabled} onChange={(e) => set(i, { remark: e.target.value })} className={inputClass} aria-label={`Row ${i + 1} remark`} />
                </td>
                <td className="pb-2">
                  {!disabled && (
                    <button type="button" onClick={() => onChange(bands.filter((_, j) => j !== i))} className="text-xs text-negative hover:underline">
                      Remove
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {!disabled && (
        <div className="mt-2 flex flex-wrap gap-3 text-xs">
          <button type="button" onClick={() => onChange([...bands, { min: '', max: '', grade: '', remark: '' }])} className="font-semibold text-brand hover:underline">
            + Add a grade
          </button>
          <button type="button" onClick={() => onChange(WAEC)} className="text-ink-muted hover:underline">
            Start from WAEC (A1–F9)
          </button>
          <button type="button" onClick={() => onChange(PRIMARY_EXAMPLE)} className="text-ink-muted hover:underline">
            Start from A–E
          </button>
        </div>
      )}

      {problem && <p className="mt-2 text-xs text-negative">{problem}</p>}
    </div>
  );
}
