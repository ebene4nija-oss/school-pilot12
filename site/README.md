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

## Things that stay hidden until decided

The site leaves out anything not yet real rather than showing a placeholder:

| Setting | Until set |
| --- | --- |
| `NEXT_PUBLIC_WHATSAPP_NUMBER` | No WhatsApp button; "Book a demo" becomes the primary CTA |
| `NEXT_PUBLIC_DEMO_SCHOOL_URL` | No "try the demo school" links |
| `NEXT_PUBLIC_RESPONSE_MINUTES` | Forms promise a reply "by the next working day" |
| `NEXT_PUBLIC_TRUST_SHOW_AI=1` | /trust omits the AI section (needs cross-border transfer documentation) |
| `NEXT_PUBLIC_TRUST_SHOW_BACKUPS=1` | /trust omits backups (needs the first restore drill) |
| `NEXT_PUBLIC_LEGAL_REVIEWED=1` | Legal pages carry a "draft awaiting legal review" note |
| Contact email, address, privacy email | Not shown |

Prices are never shown on the site; bands say "Price confirmed on WhatsApp" until final prices are approved in writing.

## Content rules

Copy lives in `src/content/`. Every claim must map to something the product does today (Landing PRD §2). Never: hardware or biometrics, inventory or facilities, "AI writes report cards", invented numbers, testimonials or prices. There is no "owner" login; proprietors sign in as the school admin.

Pricing: the core is free, CBT included. Schools pay one charge per term, by student band, when they generate results.

## Screenshots

`ProductShot` shows a real screenshot from `public/screens/` or, if the file is missing, a labelled "Screenshot to capture" frame (never a mock-up). Capture these from the seeded Greenfield Academy demo tenant (`php artisan db:seed --class=DemoSchoolSeeder`), as WebP:

| File | Size | Screen |
| --- | --- | --- |
| `admin-dashboard.webp` | 1280×800 | School admin dashboard |
| `admin-dashboard-phone.webp` | 390×844 | Admin dashboard, mobile app |
| `broadsheet.webp` | 1280×800 | A class broadsheet with positions |
| `cbt-exam.webp` | 1280×800 | Student CBT exam screen |
| `fees-outstanding.webp` | 1280×800 | Invoices and outstanding balances |
| `student-profile.webp` | 1280×800 | Student profile |
| `score-entry-phone.webp` | 390×844 | Teacher score entry, mobile |
| `parent-app-phone.webp` | 390×844 | Parent app: result and balance |
| `student-app-phone.webp` | 390×844 | Student app: materials and assignments |

## Performance budget

`check-budget` enforces 800 KB total on the homepage. The JavaScript budget in the spec is 90 KB gzipped, but React and the Next.js App Router alone ship about 125 KB, so the check currently fails on JS (about 138 KB). Override with `BUDGET_JS_KB` once a budget is agreed.
