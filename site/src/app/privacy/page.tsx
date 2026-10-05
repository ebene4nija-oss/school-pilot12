import type { Metadata } from "next";
import { LegalPage } from "@/components/LegalPage";
import { site } from "@/lib/site";

export const metadata: Metadata = { title: "Privacy notice", alternates: { canonical: "/privacy/" } };

export default function PrivacyPage() {
  return (
    <LegalPage title="Privacy notice: this website" updated="5 October 2026">
      <p>This notice covers schoolpilot.org.ng, the website. How we handle a school&apos;s data inside SchoolPilot is set out in our <a href="/data-policy/">data policy</a>.</p>

      <h2>What we collect when you contact us</h2>
      <ul>
        <li>Your name, phone number and school name; your role; roughly how many students your school has.</li>
        <li>Optionally: your email, your school&apos;s sections, what you use today, and your message.</li>
        <li>Which pages of this site you viewed during that visit and how you arrived (for example, a campaign link). Not who you are.</li>
      </ul>
      <p>We use this only to reply to your request and to talk to you about SchoolPilot for your school. We ask for your consent on every form, and the box is never ticked for you.</p>

      <h2>How we protect it</h2>
      <p>Your name, phone, email and message are encrypted when stored, and only SchoolPilot staff who handle enquiries can see them.</p>

      <h2>How long we keep it</h2>
      <p>If your school doesn&apos;t become a SchoolPilot customer, we delete your details 12 months after we last spoke.</p>

      <h2>Cookies and analytics</h2>
      <p>This website sets no cookies. Our visitor statistics are cookieless and count visits, not people. We don&apos;t use advertising trackers.</p>

      <h2>Your rights</h2>
      <p>
        Under the Nigeria Data Protection Act 2023 you can ask to see, correct or delete what we hold about you, or withdraw consent. Contact{" "}
        {site.privacyEmail ? <a href={`mailto:${site.privacyEmail}`}>{site.privacyEmail}</a> : <a href="/contact/">us</a>}.
      </p>
    </LegalPage>
  );
}
