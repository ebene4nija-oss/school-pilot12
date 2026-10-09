import type { Metadata, Viewport } from "next";
import { Inter, Work_Sans } from "next/font/google";
import { Header } from "@/components/Header";
import { Footer } from "@/components/Footer";
import { StickyBar } from "@/components/StickyBar";
import { PageView } from "@/components/PageView";
import { site } from "@/lib/site";
import { RESULTS, SUBSCRIPTION } from "@/lib/pricing";
import "./globals.css";

// next/font downloads at build and serves the files from the site itself:
// no request to Google from a visitor's phone (Landing PRD §14.3).
const inter = Inter({ subsets: ["latin"], variable: "--font-inter", display: "swap" });
const workSans = Work_Sans({ subsets: ["latin"], variable: "--font-work-sans", display: "swap", weight: ["600", "700"] });

const description =
  "School management software for Nigerian private schools: result computation and report cards, CBT with an offline lab client, LMS, school fees and HR in one system. From ₦300 per student per term.";

export const metadata: Metadata = {
  metadataBase: new URL(site.url),
  title: {
    default: "School Management System in Nigeria | SchoolPilot",
    template: "%s | SchoolPilot",
  },
  description,
  alternates: { canonical: "/", languages: { "en-NG": "/" } },
  openGraph: {
    type: "website",
    locale: "en_NG",
    siteName: "SchoolPilot",
    title: "School Management System for Nigerian Schools | SchoolPilot",
    description,
    url: "/",
  },
  twitter: { card: "summary_large_image", title: "School Management System for Nigerian Schools | SchoolPilot", description },
  icons: { icon: "/brand/mark.webp" },
};

export const viewport: Viewport = {
  themeColor: "#002447",
  width: "device-width",
  initialScale: 1,
};

const organization = {
  "@context": "https://schema.org",
  "@type": "Organization",
  name: "SchoolPilot",
  url: site.url,
  logo: `${site.url}/brand/logo.webp`,
};

const software = {
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  name: "SchoolPilot",
  applicationCategory: "EducationalApplication",
  operatingSystem: "Web, Android, iOS",
  description,
  url: site.url,
  // Real, approved prices only; see src/lib/pricing.ts.
  offers: {
    "@type": "AggregateOffer",
    priceCurrency: "NGN",
    lowPrice: SUBSCRIPTION,
    highPrice: RESULTS,
    offerCount: 2,
    url: `${site.url}/pricing/`,
  },
  // No aggregateRating until there are real, consented reviews (§14.4).
};

const website = {
  "@context": "https://schema.org",
  "@type": "WebSite",
  name: "SchoolPilot",
  url: site.url,
  inLanguage: "en-NG",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en-NG" className={`${inter.variable} ${workSans.variable}`}>
      <head>
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify([organization, software, website]) }} />
        {site.analyticsDomain && site.analyticsScript && (
          // Cookieless, self-hosted; no consent banner needed (§15).
          <script defer data-domain={site.analyticsDomain} src={site.analyticsScript} />
        )}
      </head>
      <body className="min-h-screen antialiased">
        <Header />
        <main id="main">{children}</main>
        <Footer />
        <StickyBar />
        <PageView />
      </body>
    </html>
  );
}
