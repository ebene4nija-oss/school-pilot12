'use client';

import React, { useEffect, useId, useRef, useState } from 'react';
import { fetchApi } from '@/lib/api';

/**
 * Find a student by typing, not by scrolling.
 *
 * The clinic and hostel forms used to load the whole roster into a <select>.
 * With a few thousand students that is a list nobody can use — and the roster
 * endpoint is paginated, so past the first page the student simply was not in
 * it. This asks `/students/lookup` for at most 20 matches on name or admission
 * number as you type.
 */

export type PickedStudent = {
  id: number;
  name: string | null;
  admission_number: string | null;
  class: string | null;
};

const input =
  'w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20';

export default function StudentPicker({
  value,
  onChange,
  classId,
  placeholder = 'Type a name or admission number…',
}: {
  value: PickedStudent | null;
  onChange: (student: PickedStudent | null) => void;
  classId?: number | string;
  placeholder?: string;
}) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<PickedStudent[]>([]);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const [searching, setSearching] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const listId = useId();
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const term = query.trim();

    // Under two characters the list is not shown, so there is nothing to fetch.
    if (term.length < 2) return;

    let cancelled = false;

    // Debounced: one request after typing pauses, not one per keystroke.
    const timer = setTimeout(async () => {
      const params = new URLSearchParams({ search: term });
      if (classId) params.set('class_id', String(classId));

      try {
        const res = await fetchApi<{ data: PickedStudent[] }>(`/students/lookup?${params}`);
        if (!cancelled) {
          setResults(res.data ?? []);
          setActive(0);
          setError(null);
        }
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Search failed.');
      } finally {
        if (!cancelled) setSearching(false);
      }
    }, 250);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [query, classId]);

  const choose = (student: PickedStudent) => {
    onChange(student);
    setQuery('');
    setResults([]);
    setOpen(false);
  };

  if (value) {
    return (
      <div className="flex items-center justify-between gap-3 rounded-lg border border-brand/40 bg-brand/5 px-3 py-2 text-sm">
        <span className="min-w-0">
          <span className="font-semibold text-ink">{value.name ?? `Student #${value.id}`}</span>
          <span className="ml-2 text-xs text-ink-muted">
            {[value.admission_number, value.class].filter(Boolean).join(' · ')}
          </span>
        </span>
        <button
          type="button"
          onClick={() => {
            onChange(null);
            setTimeout(() => inputRef.current?.focus(), 0);
          }}
          className="shrink-0 text-xs font-semibold text-brand hover:underline"
        >
          Change
        </button>
      </div>
    );
  }

  const term = query.trim();

  return (
    <div className="relative">
      <input
        ref={inputRef}
        type="search"
        role="combobox"
        aria-expanded={open && results.length > 0}
        aria-controls={listId}
        aria-autocomplete="list"
        autoComplete="off"
        value={query}
        placeholder={placeholder}
        className={input}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
          // Set here, not in the effect: the effect only fetches.
          setSearching(e.target.value.trim().length >= 2);
          if (e.target.value.trim().length < 2) setResults([]);
        }}
        onFocus={() => setOpen(true)}
        // Delay so a click on a result lands before the list closes.
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => Math.min(i + 1, results.length - 1));
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
          } else if (e.key === 'Enter' && open && results[active]) {
            e.preventDefault();
            choose(results[active]);
          } else if (e.key === 'Escape') {
            setOpen(false);
          }
        }}
      />

      {open && term.length >= 2 && (
        <ul
          id={listId}
          role="listbox"
          className="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-line bg-surface py-1 shadow-lg"
        >
          {searching && results.length === 0 ? (
            <li className="px-3 py-2 text-xs text-ink-muted">Searching…</li>
          ) : error ? (
            <li className="px-3 py-2 text-xs text-negative">{error}</li>
          ) : results.length === 0 ? (
            <li className="px-3 py-2 text-xs text-ink-muted">No active student matches &ldquo;{term}&rdquo;.</li>
          ) : (
            results.map((s, i) => (
              <li
                key={s.id}
                role="option"
                aria-selected={i === active}
                onMouseDown={(e) => {
                  e.preventDefault();
                  choose(s);
                }}
                onMouseEnter={() => setActive(i)}
                className={`cursor-pointer px-3 py-2 text-sm ${i === active ? 'bg-brand/10' : ''}`}
              >
                <span className="font-medium text-ink">{s.name ?? `Student #${s.id}`}</span>
                <span className="ml-2 text-xs text-ink-muted">
                  {[s.admission_number, s.class].filter(Boolean).join(' · ')}
                </span>
              </li>
            ))
          )}
          {results.length === 20 && (
            <li className="border-t border-line-soft px-3 py-1.5 text-[11px] text-ink-faint">
              Showing the first 20 — keep typing to narrow it down.
            </li>
          )}
        </ul>
      )}
    </div>
  );
}
