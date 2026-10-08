import type { Metadata } from "next";
import { CtaBand, PageHero } from "@/components/Blocks";
import { site } from "@/lib/site";

export const metadata: Metadata = {
  title: "Security & trust",
  description: "How SchoolPilot protects your school's and children's data: NDPA 2023, no biometrics, encryption, two-factor sign-in, audit logs and one-click export.",
  alternates: { canonical: "/trust/" },
};

/*
 * Every statement here must be true (Landing PRD §13). Two sections are held
 * back until their condition is met, each switched on in the console
 * (Super Admin > Website):
 *  - "AI and your data": once the cross-border transfer documentation is
 *    confirmed.
 *  - Backups and restore testing: once the first restore drill is logged.
 */
const showAi = site.trustShowAi;
const showBackups = site.trustShowBackups;

export default function TrustPage() {
  return (
    <>
      <PageHero
        eyebrow="Security & trust"
        title="Your school's data, and your children's, handled with care."
        sub="Written for proprietors and for the parents who ask them. Plain language, and every statement true."
      >
        <></>
      </PageHero>
      <section className="bg-canvas py-12 md:py-16">
        <div className="container-site prose-site max-w-3xl text-lg">
          <h2>The Nigeria Data Protection Act 2023</h2>
          <p>
            Your school is responsible for its students&apos; and staff&apos;s data; SchoolPilot processes it on your school&apos;s behalf.
            We collect only the fields a school needs, and parents&apos; consent is recorded and can be withdrawn.
          </p>

          <h2>Children&apos;s data</h2>
          <ul>
            <li>We treat children&apos;s data as the most sensitive data we hold.</li>
            <li><strong>No biometrics, ever.</strong> No fingerprints, no face scans. Attendance and transport use ordinary phones.</li>
            <li>Sensitive health records, such as sick-bay visits, are encrypted field by field.</li>
          </ul>

          {showAi && (
            <>
              <h2>AI and your data</h2>
              <p>
                Some features use AI: report-comment drafts, lesson plans and worksheets, and the student tutor. These send limited data, never a
                pupil&apos;s name, to an AI provider outside Nigeria: Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini), whichever
                the school uses. <strong>No AI-drafted comment reaches a report card until a teacher approves or edits it.</strong>
              </p>
            </>
          )}

          <h2>Who can see what</h2>
          <ul>
            <li>Every account has a role: admin, teacher, bursar, parent, student and more. Your school can create custom roles.</li>
            <li>Two-factor sign-in for administrators.</li>
            <li>Audit logs and login history, so you can see who changed what.</li>
          </ul>

          {showBackups && (
            <>
              <h2>Backups</h2>
              <p>Your data is backed up nightly, encrypted, and restore drills are run regularly.</p>
            </>
          )}

          <h2>If you leave</h2>
          <p>Export all of your school&apos;s data in one click, any time. It&apos;s yours.</p>

          <h2>Contact</h2>
          <p>
            Questions about data protection:{" "}
            {site.privacyEmail ? <a href={`mailto:${site.privacyEmail}`}>{site.privacyEmail}</a> : <a href="/contact/">contact us</a>}.
          </p>
        </div>
      </section>
      <CtaBand />
    </>
  );
}
