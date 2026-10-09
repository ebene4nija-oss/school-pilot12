'use client';

import { useEffect, useState } from 'react';
import { fetchApi } from './api';

export type SubjectOption = { id: number; name: string; code?: string | null };

/**
 * The subjects a class takes, narrowed to one arm's stream when an arm is
 * given; every active subject when no class is chosen yet.
 *
 * A school keeps one subject list for Nursery 1 to SS 3, so a picker that
 * loads `/subjects` offers Physics to a nursery teacher. Every picker that
 * already knows its class asks through this instead.
 */
export function useClassSubjects(classId?: string | number | null, armId?: string | number | null): SubjectOption[] {
  const [subjects, setSubjects] = useState<SubjectOption[]>([]);

  useEffect(() => {
    let live = true;
    const qs = classId ? `?class_id=${classId}${armId ? `&arm_id=${armId}` : ''}` : '';

    fetchApi<{ data?: SubjectOption[] }>(`/subjects${qs}`)
      .then((r) => { if (live) setSubjects(r.data ?? []); })
      .catch(() => undefined);

    return () => { live = false; };
  }, [classId, armId]);

  return subjects;
}
