'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { ErrorNote, Loading, PageShell } from '@/components/Page';
import AcademicHistoryView, { type AcademicHistory } from '@/components/AcademicHistoryView';

/** A student's own academic record: released terms only (the server decides). */
export default function MyAcademicHistory() {
  const [data, setData] = useState<AcademicHistory | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<AcademicHistory>('/me/academic-history')
      .then(setData)
      .catch((err) => setError(errorText(err, 'Could not load your academic history.')));
  }, []);

  return (
    <PageShell title="My academic history" subtitle="Every term the school has released, subject by subject, and how you have progressed.">
      <ErrorNote message={error} />
      {!data ? !error && <Loading /> : <AcademicHistoryView data={data} />}
    </PageShell>
  );
}
