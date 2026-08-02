# SchoolPilot

## What This Is
A school management platform for private K-12 schools in Nigeria. Multi-tenant
(subdomain per school), four school-side roles (Admin, Teacher, Student,
Parent) plus a Super Admin console. Full product spec:
`/docs/SchoolPilot-Comprehensive-Documentation.md`. UI reference:
`/stitch_schoolpilot_management_system/`.

## Stack
- Backend: Laravel (PHP), Postgres, Redis (cache + queues)
- Web admin: Next.js + Tailwind
- Mobile: Flutter (single codebase, role-based navigation)
- Payments: Paystack, Flutterwave
- AI: Claude API for comment generation, lesson-plan/worksheet generation,
  and the student tutor chat

## Commands
- Backend: `composer install`, `php artisan migrate`, `php artisan serve`, `php artisan test`
- Web: `npm install`, `npm run dev`, `npm run build`, `npm run lint`
- Mobile: `flutter pub get`, `flutter run`, `flutter test`

## Hard Rules
- No biometrics, no dedicated/purchased hardware, ever. If a feature seems to
  need a reader, scanner, or sensor, redesign it around a phone camera or
  phone GPS instead — see doc §3.
- K-12 academic structure only. Don't introduce university-level concepts
  (faculties, semesters) unless explicitly asked.
- NDPA data-minimization on anything touching student data — don't add new
  personal-data fields without checking doc §12 first.
- Money is Naira (₦). Academic terms are Nigerian (CA, broadsheet, JSS/SS,
  WAEC) — not GPA/semester language.
- Don't touch payment, auth, or deployment config without first explaining
  the change and its risk.
- All backend routes are versioned under /api/v1/... — this is a multi-client
  (web + mobile) product; an unversioned breaking change on the backend
  breaks installed mobile apps.
- An AI-generated report-card comment never reaches a report card
  unreviewed. It sits in `pending_approval` until a teacher approves or
  edits it.

## Workflow
- Propose a brief plan before multi-file changes.
- Write tests alongside new backend endpoints; run the suite before calling
  a task done.
- Commit at the end of each completed stage with a clear message.
