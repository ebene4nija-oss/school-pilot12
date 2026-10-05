import Link from "next/link";
import { nav } from "@/lib/site";
import { Logo } from "./Logo";
import { PrimaryCta, SecondaryCta } from "./Cta";
import { MobileMenu } from "./MobileMenu";

export function Header() {
  return (
    <header className="sticky top-0 z-40 border-b border-line-soft bg-canvas/95 backdrop-blur">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-2 focus:z-50 focus:bg-surface focus:px-3 focus:py-2">
        Skip to content
      </a>
      <div className="container-site flex h-16 items-center justify-between gap-4">
        <Logo />
        <nav aria-label="Main" className="hidden lg:block">
          <ul className="flex items-center gap-6 text-[0.95rem] font-medium text-brand">
            {nav.map((item) => (
              <li key={item.href}>
                <Link href={item.href} className="hover:underline underline-offset-4">
                  {item.label}
                </Link>
              </li>
            ))}
            <li>
              <Link href="/schools/" className="hover:underline underline-offset-4">
                Login
              </Link>
            </li>
          </ul>
        </nav>
        <div className="hidden items-center gap-3 lg:flex">
          <SecondaryCta where="nav" />
          <PrimaryCta where="nav" />
        </div>
        <MobileMenu />
      </div>
    </header>
  );
}
