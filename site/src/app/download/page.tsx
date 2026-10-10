import type { Metadata } from "next";
import Link from "next/link";
import { CtaBand, FaqList, Section } from "@/components/Blocks";
import { PrimaryCta } from "@/components/Cta";
import { Icon, IconTile, type IconName } from "@/components/Icon";
import { JsonLd, breadcrumbs, faqPage } from "@/components/JsonLd";
import { WatchItWork } from "@/components/WatchItWork";
import { hasOfflineClient, site } from "@/lib/site";

export const metadata: Metadata = {
  title: "Download the Offline CBT Lab Client",
  description:
    "Download SchoolPilot's CBT lab client for Windows: run CBT exams in your computer lab with no internet during the paper. One school laptop serves every lab PC. Included in every plan.",
  alternates: { canonical: "/download/" },
};

/*
 * The offline CBT lab client's download page. The release is published in
 * Super Admin > Downloads. The button uses the API's stable link, which always
 * redirects to the current release, so a new version is served without a
 * rebuild; the version, size and checksum below refresh on the next rebuild.
 *
 * Claims stay within what /features/cbt/ already says (Landing PRD §2). The
 * client is being reworked in desktop/; check this page against it before
 * adding anything more specific.
 */

/** What the installer runs on. Keep in step with desktop/packaging. */
const REQUIREMENTS = "Windows 10 or 11, 64-bit";

const release = site.offlineClient;
const sizeMb = release?.size_bytes ? `${(release.size_bytes / 1048576).toFixed(1)} MB` : null;
const released = release?.published_at
  ? new Date(release.published_at).toLocaleDateString("en-NG", { day: "numeric", month: "long", year: "numeric", timeZone: "Africa/Lagos" })
  : null;

const need: { icon: IconName; title: string; text: string }[] = [
  { icon: "apps", title: "One school laptop", text: "The invigilator's laptop carries the paper into the lab and holds every answer. So a lab PC going off loses nothing that was answered." },
  { icon: "cbt", title: "The lab PCs you already have", text: `Any lab computer running ${REQUIREMENTS}. Nothing new to buy.` },
  { icon: "portal", title: "Your lab's network, or none", text: "Wired or Wi-Fi. If the lab has no network at all, the laptop can create one." },
  { icon: "shield", title: "Your SchoolPilot account", text: "Papers come from your school's question bank and results go back to it. CBT and the lab client are included in every plan." },
];

const steps: { title: string; text: string }[] = [
  { title: "Install it once", text: "Run the same installer on the invigilator's laptop and on each lab PC. It asks which one it is on." },
  { title: "Prepare the day before", text: "While you're online, the laptop downloads the encrypted paper and the lab PCs are paired to it." },
  { title: "Sit the exam with no internet", text: "Each PC opens the paper fullscreen over the lab's network: shuffled questions, a countdown, and every answer saved as it's chosen." },
  { title: "Upload when you're back online", text: "Answers upload in one go. Objective answers are marked automatically and scores flow into the broadsheet." },
];

const faq = [
  { id: "internet", q: "Does the exam itself need internet?", a: "No. Internet is needed the day before, to download the paper, and afterwards, to upload answers. Never during the exam." },
  { id: "power", q: "What happens if a lab PC goes off mid-exam?", a: "Answers are kept on the school laptop as candidates choose them. The candidate resumes where they stopped, on that PC or another one." },
  { id: "cost", q: "What does the lab client cost?", a: "Nothing extra. CBT and the lab client are part of every SchoolPilot plan, with no per-exam or per-candidate fees." },
  { id: "account", q: "Can we use it without a SchoolPilot account?", a: "The client runs papers set in your school's SchoolPilot question bank, so you need your school on SchoolPilot. Book a demo and we'll show it to you running." },
  { id: "windows", q: "Windows says “Windows protected your PC”. Is that safe?", a: "Check the file first: its SHA-256 checksum should match the one on this page. If it does, choose “More info”, then “Run anyway”." },
  { id: "update", q: "How do we get a newer version?", a: "Download again from this page: it always serves the newest version. If you're unsure whether to update before an exam, ask us on WhatsApp." },
];

export default function DownloadPage() {
  const software = release && {
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    name: "SchoolPilot CBT lab client",
    applicationCategory: "EducationalApplication",
    operatingSystem: REQUIREMENTS,
    softwareVersion: release.version,
    downloadUrl: release.download_url,
    ...(release.size_bytes ? { fileSize: sizeMb } : {}),
    publisher: { "@type": "Organization", name: site.name, url: site.url },
  };

  return (
    <>
      <JsonLd data={[breadcrumbs({ name: "Download", path: "/download/" }), faqPage(faq), ...(software ? [software] : [])]} />

      <section data-hero className="relative isolate overflow-hidden bg-surface py-14 md:py-20">
        <span aria-hidden="true" className="absolute -right-24 -top-24 -z-10 h-80 w-80 rounded-full bg-accent/15 blur-3xl" />
        <div className="container-site grid items-center gap-12 lg:grid-cols-[1.2fr_1fr]">
          <div>
            <div className="flex items-center gap-3">
              <IconTile name="cbt" />
              <p className="eyebrow">CBT lab client for Windows</p>
            </div>
            <h1 className="mt-4 text-4xl font-bold md:text-5xl">CBT that keeps going when the internet doesn&apos;t.</h1>
            <p className="mt-5 text-lg text-ink-muted md:text-xl">
              Download the SchoolPilot CBT lab client and run exams in your computer lab with no internet during the paper. One school laptop serves every
              lab PC over your own network, and answers upload when you&apos;re back online.
            </p>
          </div>

          <div className="rounded-3xl bg-gradient-to-br from-brand via-brand-soft to-navy-deep p-7 text-white shadow-xl md:p-9">
            {hasOfflineClient && release ? (
              <>
                <p className="font-display text-sm font-semibold uppercase tracking-wide text-accent-light">Latest version</p>
                <p className="mt-2 font-display text-4xl font-bold">{release.version}</p>
                <p className="mt-1 text-white/80">
                  {[REQUIREMENTS, sizeMb, released && `released ${released}`].filter(Boolean).join(" · ")}
                </p>
                <a href={release.download_url} className="btn btn-primary mt-6 w-full justify-center" data-cta="download">
                  <Icon name="export" size={20} />
                  Download for Windows
                </a>
                <p className="mt-4 text-sm text-white/75">
                  Free for SchoolPilot schools. This link always serves the newest version.
                </p>
              </>
            ) : (
              <>
                <p className="font-display text-sm font-semibold uppercase tracking-wide text-accent-light">Coming to this page</p>
                <p className="mt-2 font-display text-2xl font-bold">The download opens here shortly.</p>
                <p className="mt-3 text-white/80">Until then, we&apos;ll set it up in your lab with you.</p>
                <div className="mt-6">
                  <PrimaryCta where="hero" label="Ask for the lab client" message="Hello SchoolPilot, I'd like the CBT lab client for our computer lab." />
                </div>
              </>
            )}
          </div>
        </div>
      </section>

      <Section id="need" eyebrow="What you need" title="Nothing you don't already have.">
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {need.map((n) => (
            <li key={n.title} className="rounded-2xl border border-line-soft bg-surface p-6">
              <IconTile name={n.icon} tone="accent" />
              <h3 className="mt-4 text-lg font-semibold">{n.title}</h3>
              <p className="mt-2 text-[0.95rem] text-ink-muted">{n.text}</p>
            </li>
          ))}
        </ul>
      </Section>

      <Section id="how" tone="surface" eyebrow="How it works" title="Online the day before. Offline for the exam.">
        <ol className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {steps.map((step, i) => (
            <li key={step.title} className="rounded-xl border border-line-soft bg-canvas p-5">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-accent font-display font-bold text-navy-deep">{i + 1}</span>
              <h3 className="mt-3 text-lg font-semibold">{step.title}</h3>
              <p className="mt-1 text-ink-muted">{step.text}</p>
            </li>
          ))}
        </ol>
        <Link href="/features/cbt/" className="mt-8 inline-block font-semibold text-brand underline underline-offset-4">
          Everything CBT does, online and in the lab <span className="arrow-nudge">→</span>
        </Link>
      </Section>

      <WatchItWork placement="download" title="Watch a lab set up and sit a paper" />

      {release && (
        <Section id="verify" eyebrow="For your ICT teacher" title={`What's in ${release.version}, and how to check your copy`}>
          <div className="grid gap-6 lg:grid-cols-2">
            {release.notes && (
              <div className="rounded-xl border border-line-soft bg-surface p-5 md:p-6">
                <p className="font-display font-semibold text-brand">What&apos;s new</p>
                <p className="mt-2 whitespace-pre-line text-ink-muted">{release.notes}</p>
              </div>
            )}
            <div className="rounded-xl border border-line-soft bg-surface p-5 md:p-6">
              <p className="font-display font-semibold text-brand">SHA-256 checksum</p>
              <p className="mt-2 break-all font-mono text-sm text-ink">{release.sha256}</p>
              <p className="mt-3 text-sm text-ink-muted">
                In PowerShell, in the folder you downloaded to, run <code className="rounded bg-surface-sunken px-1.5 py-0.5">Get-FileHash .\SchoolPilot-Setup-{release.version}.exe</code>.
                The hash it prints should match this one.
              </p>
            </div>
          </div>
        </Section>
      )}

      <Section id="faq" tone="surface" eyebrow="Questions" title="Before you install">
        <div className="max-w-3xl">
          <FaqList items={faq} />
        </div>
      </Section>

      <CtaBand
        title="Want to see a paper run in your own lab?"
        text="We'll show you the lab client on a call, and help your ICT teacher set up the first exam."
      />
    </>
  );
}
