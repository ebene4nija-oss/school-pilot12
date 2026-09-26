import PlatformSettings from '@/components/PlatformSettings';

export default function PlatformSettingsPage() {
  return (
    <PlatformSettings
      section="integrations"
      title="Platform settings"
      subtitle="SMS, WhatsApp, email and push keys for every school. Values set here override the server's configuration."
    />
  );
}
