/**
 * Where a visitor came from and what they looked at, kept in sessionStorage
 * for this visit only, and sent with a form or a WhatsApp click
 * (Landing PRD §11.2, §12.3). Paths and UTM tags only; never anything about
 * who the visitor is. No cookies.
 *
 * Storage can be missing or throw (private windows, blocked site data), so
 * every access is guarded and the site works the same without it.
 */
import { site } from "./site";

const KEY = "sp-attribution";
const UTM_KEYS = ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term"] as const;

export type Attribution = {
  landing_page?: string;
  referrer?: string;
  pages: string[];
} & Partial<Record<(typeof UTM_KEYS)[number], string>>;

function read(): Attribution {
  try {
    const raw = window.sessionStorage.getItem(KEY);
    if (raw) return JSON.parse(raw) as Attribution;
  } catch {
    // fall through
  }
  return { pages: [] };
}

function write(value: Attribution) {
  try {
    window.sessionStorage.setItem(KEY, JSON.stringify(value));
  } catch {
    // storage unavailable: attribution is best-effort
  }
}

/** Called once per page view. */
export function recordPageView(path: string) {
  const current = read();

  if (!current.landing_page) {
    current.landing_page = path;
    const ref = document.referrer;
    if (ref && !ref.startsWith(window.location.origin)) {
      current.referrer = ref.slice(0, 255);
    }
    const params = new URLSearchParams(window.location.search);
    for (const key of UTM_KEYS) {
      const v = params.get(key);
      if (v) current[key] = v.slice(0, 100);
    }
  }

  if (!current.pages.includes(path) && current.pages.length < 50) {
    current.pages.push(path);
  }

  write(current);
}

export function markPage(marker: string) {
  const current = read();
  if (!current.pages.includes(marker)) {
    current.pages.push(marker);
    write(current);
  }
}

export function getAttribution(): Attribution {
  return read();
}

/** A short code a sales rep can read out of a WhatsApp chat: SP-7KQ2. */
export function newRefCode(): string {
  // No 0/O or 1/I: the code is read by a person.
  const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  const bytes = new Uint8Array(4);
  crypto.getRandomValues(bytes);
  return "SP-" + Array.from(bytes, (b) => alphabet[b % alphabet.length]).join("");
}

/** Fire-and-forget: a lost touch must never block opening WhatsApp. */
export function sendTouch(refCode: string, cta: string) {
  const { pages, ...rest } = read();
  const body = JSON.stringify({ ref_code: refCode, cta, pages, ...rest });
  const url = `${site.apiBase}/public/leads/touch`;

  // fetch with keepalive, not sendBeacon: a cross-origin beacon with a JSON
  // body is refused outright by some browsers.
  try {
    void fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body,
      keepalive: true,
    }).catch(() => {});
  } catch {
    // ignore
  }
}

type PlausibleFn = (event: string, options?: { props?: Record<string, string | number> }) => void;

/** Analytics events (Landing PRD §15). A no-op when no analytics is configured. */
export function track(event: string, props?: Record<string, string | number>) {
  try {
    const fn = (window as unknown as { plausible?: PlausibleFn }).plausible;
    fn?.(event, props ? { props } : undefined);
  } catch {
    // ignore
  }
}
