import type { Metadata } from "next";
import { LegalPage } from "@/components/LegalPage";

export const metadata: Metadata = { title: "Data policy", alternates: { canonical: "/data-policy/" } };

export default function DataPolicyPage() {
  return (
    <LegalPage title="Data policy: your school's data" updated="5 October 2026">
      <h2>Who is responsible</h2>
      <p>Your school controls its students&apos;, parents&apos; and staff&apos;s data. SchoolPilot processes it on the school&apos;s behalf, to run the services the school uses.</p>

      <h2>What we hold</h2>
      <p>Only the fields a school needs to run: records, attendance, results, fees, staff and communication. We don&apos;t add personal-data fields without checking they are necessary.</p>

      <h2>What we never collect</h2>
      <p>Biometrics of any kind: no fingerprints, no face scans.</p>

      <h2>How it is protected</h2>
      <ul>
        <li>Access by role, with custom roles your school controls.</li>
        <li>Two-factor sign-in for administrators.</li>
        <li>Audit logs and login history.</li>
        <li>Sensitive health records encrypted field by field.</li>
      </ul>

      <h2>Your school can leave with its data</h2>
      <p>A school can export all of its data in one click, at any time.</p>
    </LegalPage>
  );
}
