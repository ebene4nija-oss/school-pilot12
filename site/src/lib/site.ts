/**
 * Site-wide settings.
 *
 * What an operator decides (Landing PRD §24) comes from the console: Super
 * Admin > Website. scripts/fetch-site-config.mjs pulls it into
 * src/generated/site-config.json before every build, so a console change
 * shows after the next rebuild. NEXT_PUBLIC_* variables are only a fallback
 * for local development without the API.
 *
 * Anything not set is left off the site rather than shown as a placeholder.
 */
import generated from "@/generated/site-config.json";

type Console = Partial<{
  whatsapp_number: string | null;
  response_minutes: number;
  demo_school_url: string | null;
  contact_email: string | null;
  contact_address: string | null;
  privacy_email: string | null;
  trust_show_ai: boolean;
  trust_show_backups: boolean;
  legal_reviewed: boolean;
}>;

const fromConsole = generated as Console;
const env = process.env;

export const site = {
  name: "SchoolPilot",
  url: env.NEXT_PUBLIC_SITE_URL ?? "https://schoolpilot.org.ng",
  tenantDomain: env.NEXT_PUBLIC_TENANT_DOMAIN ?? "schoolpilot.org.ng",
  apiBase: (env.NEXT_PUBLIC_API_BASE ?? "http://localhost:8000/api/v1").replace(/\/$/, ""),

  /** Digits only, international form, e.g. 2348031234567. Unset = no WhatsApp CTA. */
  whatsappNumber: (fromConsole.whatsapp_number ?? env.NEXT_PUBLIC_WHATSAPP_NUMBER ?? "").replace(/\D/g, ""),

  /** Minutes the forms promise a reply within; 0 = "by the next working day". */
  responseMinutes: fromConsole.response_minutes ?? Number(env.NEXT_PUBLIC_RESPONSE_MINUTES ?? 0),

  /** The public Greenfield Academy demo. Unset = no demo-school links. */
  demoSchoolUrl: fromConsole.demo_school_url ?? env.NEXT_PUBLIC_DEMO_SCHOOL_URL ?? "",

  contactEmail: fromConsole.contact_email ?? env.NEXT_PUBLIC_CONTACT_EMAIL ?? "",
  contactAddress: fromConsole.contact_address ?? env.NEXT_PUBLIC_CONTACT_ADDRESS ?? "",
  privacyEmail: fromConsole.privacy_email ?? env.NEXT_PUBLIC_PRIVACY_EMAIL ?? "",

  /** /trust sections held back until their precondition is met (§13). */
  trustShowAi: fromConsole.trust_show_ai ?? env.NEXT_PUBLIC_TRUST_SHOW_AI === "1",
  trustShowBackups: fromConsole.trust_show_backups ?? env.NEXT_PUBLIC_TRUST_SHOW_BACKUPS === "1",

  /** Drops the "draft awaiting legal review" note on the legal pages. */
  legalReviewed: fromConsole.legal_reviewed ?? env.NEXT_PUBLIC_LEGAL_REVIEWED === "1",

  turnstileSiteKey: env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? "",

  /** Self-hosted Plausible or Umami, cookieless. Unset = no analytics script. */
  analyticsDomain: env.NEXT_PUBLIC_ANALYTICS_DOMAIN ?? "",
  analyticsScript: env.NEXT_PUBLIC_ANALYTICS_SCRIPT ?? "",

  /** Working hours stated on every success screen (Landing PRD §11.3). */
  responseHours: "8am–6pm, Monday to Saturday",
} as const;

export const hasWhatsApp = site.whatsappNumber.length >= 12;
export const hasDemoSchool = site.demoSchoolUrl.length > 0;

export const nav = [
  { href: "/features/", label: "Features" },
  { href: "/solutions/school-owners/", label: "For school leaders" },
  { href: "/pricing/", label: "Pricing" },
  { href: "/migrate/", label: "Switching" },
];

export const footer = [
  {
    title: "Product",
    links: [
      { href: "/features/", label: "Features" },
      { href: "/pricing/", label: "Pricing" },
      { href: "/migrate/", label: "Free migration" },
      { href: "/trust/", label: "Security & trust" },
    ],
  },
  {
    title: "Solutions",
    links: [
      { href: "/solutions/school-owners/", label: "School leaders" },
      { href: "/features/results-report-cards/", label: "Results & report cards" },
      { href: "/features/cbt/", label: "CBT" },
      { href: "/features/fees-finance/", label: "Fees & finance" },
      { href: "/features/lms/", label: "LMS" },
      { href: "/features/school-administration/", label: "School administration" },
      { href: "/features/student-management/", label: "Student management" },
    ],
  },
  {
    title: "Company",
    links: [
      { href: "/about/", label: "About" },
      { href: "/contact/", label: "Contact" },
      { href: "/faq/", label: "FAQ" },
      { href: "/partners/", label: "Partners" },
      { href: "/schools/", label: "School login" },
    ],
  },
  {
    title: "Legal",
    links: [
      { href: "/privacy/", label: "Privacy" },
      { href: "/terms/", label: "Terms" },
      { href: "/data-policy/", label: "Data policy" },
    ],
  },
];
