# SchoolPilot marketing site

The public site at `schoolpilot.org.ng`: a static Next.js export, separate from `web/` so visitors never download the dashboard. Spec: `docs/SchoolPilot-Landing-Pages-PRD.md` (v2.0) and the Landing Page Specification doc.

## Commands

```bash
npm install
npm run dev            # http://localhost:3100
npm run build          # static export to out/
npm run check-budget   # homepage weight budget, after a build
npm run lint
```

Copy `.env.example` to `.env.local` and point `NEXT_PUBLIC_API_BASE` at the Laravel API (`.../api/v1`).

## How it talks to the backend

Only through four public endpoints, all under `/api/v1/public` (see `backend/app/Http/Controllers/Api/V1/PublicMarketingController.php`):

| Endpoint | Used by |
| --- | --- |
| `POST /public/leads` | /demo, /start, /contact forms |
| `POST /public/leads/touch` | Every "Chat on WhatsApp" click (anonymous ref code) |
| `GET /public/schools/resolve` | /schools and /schools/results |
| `GET /public/stats` | Not shown yet; totals stay hidden below their thresholds |

Leads appear for operators at `GET /api/v1/platform/leads`.

## Managed from the console

Operators set the site's contacts, switches and screenshots in the Super Admin console under **Website**. Before every build, `scripts/fetch-site-config.mjs` reads `GET /api/v1/public/site-config`, writes `src/generated/site-config.json`, and downloads the screenshots into `public/screens/` as WebP. **A change in the console shows on the site after the next rebuild.**

| Console setting | Until it's set |
| --- | --- |
| WhatsApp Business number | No WhatsApp button; "Book a demo" becomes the primary CTA |
| Reply promise (minutes) | Forms promise a reply "by the next working day" |
| Demo school link | No "try the demo school" links |
| Contact email, office address, data-protection email | Not shown |
| Show "AI and your data" | /trust omits it (needs cross-border transfer documentation) |
| Show backups | /trust omits it (needs the first restore drill) |
| Legal pages reviewed | Legal pages carry a "draft awaiting legal review" note |
| Product screenshots (9 slots) | Each slot shows a labelled "Screenshot to capture" frame, never a mock-up |

Set `SITE_CONFIG_REQUIRED=1` on the production build so it fails if the API can't be reached, instead of shipping without the console's values. Locally, the build keeps the last config it fetched and the `NEXT_PUBLIC_*` values in `.env.local` act as a fallback.

Prices are never shown on the site; bands say "Price confirmed on WhatsApp" until final prices are approved in writing.

## Content rules

Copy lives in `src/content/`. Every claim must map to something the product does today (Landing PRD §2). Never: hardware or biometrics, inventory or facilities, "AI writes report cards", invented numbers, testimonials or prices. There is no "owner" login; proprietors sign in as the school admin.

Pricing: the core is free, CBT included. Schools pay one charge per term, by student band, when they generate results.

## Screenshots

Uploaded in the console (Super Admin > Website > Product screenshots), one per slot, from the Greenfield Academy demo school only (`php artisan db:seed --class=DemoSchoolSeeder`). The build resizes them to 1280 px wide (desktop) or 390 px (phone) WebP.

| Slot | Screen |
| --- | --- |
| `admin-dashboard` | School admin dashboard |
| `admin-dashboard-phone` | Admin dashboard, mobile app |
| `broadsheet` | A class broadsheet with positions |
| `cbt-exam` | Student CBT exam screen |
| `fees-outstanding` | Invoices and outstanding balances |
| `student-profile` | Student profile |
| `score-entry-phone` | Teacher score entry, mobile |
| `parent-app-phone` | Parent app: result and fee balance |
| `student-app-phone` | Student app: materials and assignments |

## Performance budget

`check-budget` enforces 800 KB total on the homepage. The JavaScript budget in the spec is 90 KB gzipped, but React and the Next.js App Router alone ship about 125 KB, so the check currently fails on JS (about 138 KB). Override with `BUDGET_JS_KB` once a budget is agreed.
