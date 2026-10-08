'use client';

import React, { useState } from 'react';
import AiModelSwitcher from '@/components/AiModelSwitcher';
import PlatformSettings from '@/components/PlatformSettings';

/**
 * AI service (doc §5), on its own because "the AI stopped working for us" is
 * the question an operator is asked most — and today's spend against the cap
 * is nearly always the answer. The per-school on/off switch lives on each
 * school's page.
 *
 * The model switcher sits on top: default provider, presets and models per
 * provider, and a live test. Keys and the cap stay in the card below; saving
 * a key there refreshes the switcher so its "Key set" badges stay true.
 */
export default function AiServicePage() {
  const [version, setVersion] = useState(0);

  return (
    <PlatformSettings
      section="ai"
      title="AI service"
      subtitle="Choose Claude, ChatGPT or Gemini and their models, set keys and the daily spend cap every school shares, and see today's spend by school."
      before={<AiModelSwitcher key={version} />}
      onSaved={() => setVersion((v) => v + 1)}
    />
  );
}
