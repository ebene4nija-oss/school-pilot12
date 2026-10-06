'use client';

import React from 'react';
import { PageShell } from '@/components/Page';
import StudentDirectory from '@/components/StudentDirectory';

/**
 * The student directory for the bursary: find a student, their class and
 * status, and export a class list where the school allows it. What a bursar
 * may do here (bulk changes, exports) is decided by the directory API's own
 * permissions; enrolment and imports stay on the admin's Students page.
 */
export default function BursarStudents() {
  return (
    <PageShell title="Students" subtitle="Look up a student before taking a payment or chasing a balance.">
      <StudentDirectory />
    </PageShell>
  );
}
