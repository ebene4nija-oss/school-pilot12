'use client';

import React from 'react';
import { PageShell } from '@/components/Page';
import Conversations from '@/components/chat/Conversations';

/**
 * Staff side of parent ↔ teacher chat. A teacher writes to the families of
 * pupils they teach (checked by the API); an admin to any family.
 */
export default function StaffMessagesPage() {
  return (
    <PageShell title="Messages" subtitle="Chat with parents about their children. Parents are notified in their app when you write.">
      <Conversations side="staff" />
    </PageShell>
  );
}
