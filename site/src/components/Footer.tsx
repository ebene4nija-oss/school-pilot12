import Link from "next/link";
import { hasBlog } from "@/lib/blog";
import { hasComparison } from "@/lib/comparison";
import { footer as baseFooter, hasWhatsApp, site } from "@/lib/site";
import { Logo } from "./Logo";

// Blog and Compare appear once the console has content for them.
const footer = baseFooter.map((group) =>
  group.title === "Company"
    ? {
        ...group,
        links: [
          ...(hasBlog ? [{ href: "/blog/", label: "Blog" }] : []),
          ...(hasComparison ? [{ href: "/compare/", label: "Compare" }] : []),
          ...group.links,
        ],
      }
    : group,
);

export function Footer() {
  return (
    <footer className="bg-navy-deep text-white/85">
      <div className="container-site grid gap-10 py-14 md:grid-cols-[1.4fr_repeat(4,1fr)]">
        <div>
          <Logo white />
          <p className="mt-4 max-w-xs text-sm leading-relaxed">
            School management for Nigerian private schools, Nursery to SS3. Priced per student, per term.
          </p>
          <ul className="mt-4 space-y-1 text-sm">
            {hasWhatsApp && <li>WhatsApp: +{site.whatsappNumber}</li>}
            {site.contactEmail && (
              <li>
                <a href={`mailto:${site.contactEmail}`} className="underline underline-offset-4">
                  {site.contactEmail}
                </a>
              </li>
            )}
            {site.contactAddress && <li>{site.contactAddress}</li>}
          </ul>
        </div>
        {footer.map((group) => (
          <nav key={group.title} aria-label={group.title}>
            <h2 className="font-display text-sm font-semibold uppercase tracking-wide text-accent-light">{group.title}</h2>
            <ul className="mt-3 space-y-2 text-sm">
              {group.links.map((link) => (
                <li key={link.href}>
                  <Link href={link.href} className="hover:text-white hover:underline underline-offset-4">
                    {link.label}
                  </Link>
                </li>
              ))}
            </ul>
          </nav>
        ))}
      </div>
      <div className="border-t border-white/10">
        <p className="container-site py-5 text-xs text-white/60">
          © {new Date().getFullYear()} SchoolPilot. Made for Nigerian schools.
        </p>
      </div>
    </footer>
  );
}
