import type { Metadata } from "next";
import { LegalPage } from "@/components/LegalPage";

export const metadata: Metadata = { title: "Terms", alternates: { canonical: "/terms/" } };

export default function TermsPage() {
  return (
    <LegalPage title="Terms of use: this website" updated="5 October 2026">
      <p>These terms cover the SchoolPilot website. A school&apos;s use of SchoolPilot itself is governed by its agreement with us.</p>
      <h2>Information on this site</h2>
      <p>We describe SchoolPilot as it works today. Prices are confirmed in writing for each school; nothing on this site is a quote.</p>
      <h2>Contacting us</h2>
      <p>Please don&apos;t send other people&apos;s personal data, such as students&apos; details, through our contact forms.</p>
      <h2>Changes</h2>
      <p>We may update these terms. The date above shows the latest version.</p>
    </LegalPage>
  );
}
