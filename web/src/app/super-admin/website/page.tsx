import PlatformSettings from '@/components/PlatformSettings';
import WebsiteScreens from '@/components/WebsiteScreens';

export default function WebsiteSettingsPage() {
  return (
    <PlatformSettings
      section="website"
      title="Website"
      subtitle="What the public site at schoolpilot.org.ng shows: contacts, the WhatsApp number, trust and legal switches, and product screenshots. Changes appear after the next site rebuild."
    >
      <WebsiteScreens />
    </PlatformSettings>
  );
}
