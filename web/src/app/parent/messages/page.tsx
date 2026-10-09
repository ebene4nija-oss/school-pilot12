'use client';

import React from 'react';
import { PageShell } from '@/components/Page';
import Conversations from '@/components/chat/Conversations';

/** A parent's conversations with their children's teachers. */
export default function ParentMessagesPage() {
  return (
    <PageShell title="Messages" subtitle="Chat with your child's teachers. Teachers are notified when you write.">
      <Conversations side="parent" />
    </PageShell>
  );
}
