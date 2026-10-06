import PlatformSettings from '@/components/PlatformSettings';

/**
 * AI service (doc §5), on its own because "the AI stopped working for us" is
 * the question an operator is asked most — and today's spend against the cap
 * is nearly always the answer. The per-school on/off switch lives on each
 * school's page.
 */
export default function AiServicePage() {
  return (
    <PlatformSettings
      section="ai"
      title="AI service"
      subtitle="Claude, ChatGPT and Gemini keys and models, the default provider, the daily spend cap every school shares, and today's spend by school."
    />
  );
}
