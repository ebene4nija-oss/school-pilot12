'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';

/**
 * A parent's children, and which one the page is showing.
 *
 * Most parents have more than one child at the school. The choice is kept for
 * the browser tab so moving from Results to Fees keeps the same child; if
 * storage is blocked it simply starts with the first child.
 */

export type Child = {
  id: number;
  name: string | null;
  admission_number: string | null;
  class_name: string | null;
  arm_name: string | null;
};

const KEY = 'sp_parent_child';

export function useChildren() {
  const [children, setChildren] = useState<Child[] | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<{ data: Child[] }>('/parent/children')
      .then((res) => {
        const list = res.data ?? [];
        setChildren(list);

        let remembered: number | null = null;
        try {
          remembered = Number(sessionStorage.getItem(KEY)) || null;
        } catch {
          // Storage blocked: start with the first child.
        }
        setSelectedId(list.find((c) => c.id === remembered)?.id ?? list[0]?.id ?? null);
      })
      .catch((err) => {
        setChildren([]);
        setError(errorText(err, 'Could not load your children.'));
      });
  }, []);

  const select = (id: number) => {
    setSelectedId(id);
    try {
      sessionStorage.setItem(KEY, String(id));
    } catch {
      // Not remembered; harmless.
    }
  };

  return { children, selected: children?.find((c) => c.id === selectedId) ?? null, select, error };
}

export default function ChildSwitcher({
  items,
  selected,
  onSelect,
}: {
  items: Child[];
  selected: Child | null;
  onSelect: (id: number) => void;
}) {
  if (items.length <= 1) return null;

  return (
    <div role="tablist" aria-label="Choose a child" className="mb-6 flex flex-wrap gap-2">
      {items.map((c) => (
        <button
          key={c.id}
          role="tab"
          aria-selected={selected?.id === c.id}
          onClick={() => onSelect(c.id)}
          className={`rounded-lg px-3.5 py-2 text-left text-sm transition ${
            selected?.id === c.id ? 'bg-brand text-white' : 'bg-surface text-ink ring-1 ring-line-soft hover:bg-surface-sunken'
          }`}
        >
          <span className="block font-semibold">{c.name ?? 'Your child'}</span>
          <span className={`block text-xs ${selected?.id === c.id ? 'text-white/80' : 'text-ink-muted'}`}>
            {[c.class_name, c.arm_name].filter(Boolean).join(' ') || '—'}
          </span>
        </button>
      ))}
    </div>
  );
}
