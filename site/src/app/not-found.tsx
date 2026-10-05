import Link from "next/link";
import { PrimaryCta } from "@/components/Cta";

export default function NotFound() {
  return (
    <section data-hero className="bg-surface py-20">
      <div className="container-site max-w-2xl text-center">
        <p className="eyebrow">Page not found</p>
        <h1 className="mt-3 text-4xl font-bold">We couldn&apos;t find that page.</h1>
        <p className="mt-4 text-lg text-ink-muted">
          It may have moved. Try one of these, or talk to us directly.
        </p>
        <ul className="mt-6 flex flex-wrap justify-center gap-x-6 gap-y-2 font-semibold text-brand">
          <li><Link href="/features/" className="underline underline-offset-4">Features</Link></li>
          <li><Link href="/pricing/" className="underline underline-offset-4">Pricing</Link></li>
          <li><Link href="/schools/" className="underline underline-offset-4">School login</Link></li>
          <li><Link href="/faq/" className="underline underline-offset-4">FAQ</Link></li>
        </ul>
        <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
          <Link href="/" className="btn btn-secondary">Back to SchoolPilot</Link>
          <PrimaryCta where="page" />
        </div>
      </div>
    </section>
  );
}
