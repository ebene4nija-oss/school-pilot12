/**
 * Site-wide settings. Everything that differs between environments comes
 * from NEXT_PUBLIC_* variables baked in at build time (see .env.example).
 *
 * Several are open owner decisions (Landing PRD §24): the WhatsApp Business
 * number, the public demo school and the contact details. Until each is set,
 * the site leaves it out rather than showing a made-up value.
 */

export const site = {
  name: "SchoolPilot",
  url: process.env.NEXT_PUBLIC_SITE_URL ?? "https://schoolpilot.org.ng",
  tenantDomain: process.env.NEXT_PUBLIC_TENANT_DOMAIN ?? "schoolpilot.org.ng",
  apiBase: (process.env.NEXT_PUBLIC_API_BASE ?? "http://localhost:8000/api/v1").replace(/\/$/, ""),

  /** Digits only, international form, e.g. 2348031234567. Unset = no WhatsApp CTA. */
  whatsappNumber: (process.env.NEXT_PUBLIC_WHATSAPP_NUMBER ?? "").replace(/\D/g, ""),

  /** The public Greenfield Academy demo (Phase 1b). Unset = no demo-school links. */
  demoSchoolUrl: process.env.NEXT_PUBLIC_DEMO_SCHOOL_URL ?? "",

  contactEmail: process.env.NEXT_PUBLIC_CONTACT_EMAIL ?? "",
  contactAddress: process.env.NEXT_PUBLIC_CONTACT_ADDRESS ?? "",
  privacyEmail: process.env.NEXT_PUBLIC_PRIVACY_EMAIL ?? "",

  turnstileSiteKey: process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? "",

  /** Self-hosted Plausible or Umami, cookieless. Unset = no analytics script. */
  analyticsDomain: process.env.NEXT_PUBLIC_ANALYTICS_DOMAIN ?? "",
  analyticsScript: process.env.NEXT_PUBLIC_ANALYTICS_SCRIPT ?? "",

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
      { href: "/features/digital-learning/", label: "Digital learning" },
      { href: "/features/student-management/", label: "Student management" },
    ],
  },
  {
    title: "Company",
    links: [
      { href: "/about/", label: "About" },
      { href: "/contact/", label: "Contact" },
      { href: "/faq/", label: "FAQ" },
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
