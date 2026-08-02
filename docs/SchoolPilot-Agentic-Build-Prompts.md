# SchoolPilot — Agentic Build Prompts (Claude Code)

*Every stage of taking SchoolPilot from zero to a live, deployed product. Written for Claude Code, sequenced in dependency order — database before APIs, APIs before UI, everything before deployment. Covers Phase 0 (MVP) in full; Phase 1/2 extension pattern is noted at the end.*

## How to Use This

1. Create a repo (monorepo is simplest for a small team: `/backend`, `/web`, `/mobile`).
2. Drop the earlier docs into a `/docs` folder in that repo: `SchoolPilot-Comprehensive-Documentation.md` and reference the UI design mockups under `/stitch_schoolpilot_management_system/`. Claude Code reads these for grounding — don't skip this step.
3. Install Claude Code and run it from the repo root. Run `/init` once, then replace the generated CLAUDE.md with the version below — it's written to reference `/docs` rather than duplicate it, which is the actual current best practice for keeping project memory small and accurate.
4. Run each **Stage** below as a separate Claude Code session, in order. Review the diff and run the tests before moving to the next stage — 18 stages chained unattended is how you end up debugging stage 14 while not knowing which earlier stage actually broke.
5. Commit at the end of each stage.

**Pilot slice vs. full Phase 0.** Sixteen modules is real engineering time for
a small team — don't wait until all of it is built to put this in front of a
real school. Stages 1–4, 6 (minus the AI-comment step below), 8, and a plain
manual-entry version of results are enough to run one pilot school for real.
Stages 5 (timetable auto-generation), 7 (CBT), and 9 (AI Studio + AI Learning
Hub) are what make the free tier a genuine reason to fully switch off a
school's old system — build those out before opening the WhatsApp funnel
broadly, but not necessarily before your very first pilot.

---

## CLAUDE.md *(replace the auto-generated one with this)*

```markdown
# SchoolPilot

## What This Is
A school management platform for private K-12 schools in Nigeria. Multi-tenant
(subdomain per school), four school-side roles (Admin, Teacher, Student,
Parent) plus a Super Admin console. Full product spec:
`/docs/SchoolPilot-Comprehensive-Documentation.md`. UI design files reference:
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
- Explicit parental consent logging (timestamp, IP, guardian ID) required on student registration; include withdrawal handling.
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
```

---

## Stage 1 — Repo & Environment Scaffolding

```
Set up the SchoolPilot monorepo from scratch:

1. Read /docs/SchoolPilot-Comprehensive-Documentation.md in full first, so
   folder and module naming matches the spec's terminology.
2. Create three top-level folders: /backend (Laravel), /web (Next.js),
   /mobile (Flutter). Scaffold each with its framework's standard installer.
3. Add a docker-compose.yml at the repo root with postgres, redis, and
   laravel queue worker services, so `docker compose up -d` gives a working
   local database, cache, and queue listener.
4. Create .env.example files for /backend and /web listing every
   environment variable this project will eventually need: DB connection,
   Redis connection, APP_KEY, PAYSTACK_SECRET_KEY, FLUTTERWAVE_SECRET_KEY,
   ANTHROPIC_API_KEY, AWS/MinIO S3 storage keys (for passports/PDF storage),
   and a wildcard APP_DOMAIN for subdomain routing.
5. Add a root README.md explaining the three-folder structure and how to
   run each part locally.
6. Initialize git, add a .gitignore covering PHP/Node/Flutter/Docker, make
   the first commit.
7. Version the API from the start — every backend route under /api/v1/...
   This is a multi-client product (web + mobile); an unversioned breaking
   change on a backend deploy breaks installed mobile apps with no way to
   roll individual clients back.

Definition of done: `docker compose up -d` starts Postgres and Redis;
`php artisan serve` in /backend and `npm run dev` in /web both boot without
errors; `flutter run` in /mobile boots the default scaffold; first commit
is made.
```

---

## Stage 2 — Database Schema & Multi-Tenancy

```
Implement the full Phase 0 database schema as Laravel migrations, based on
/docs/SchoolPilot-Comprehensive-Documentation.md §10 (Data Model Overview):

1. Multi-tenancy: a `schools` table, and a school_id foreign key on every
   tenant-owned table plus a global query scope — not separate schemas, for
   simplicity at this stage.
2. Users (role enum: super_admin/school_admin/teacher/student/parent),
   students (including state_of_origin, lga, religion, blood_group,
   allergies as JSON, medical_notes, previous_school), guardians, a
   student_guardian pivot (many-to-many, for split families), parental_consents
   table (guardian_id, student_id, consent_given, ip_address, timestamp, withdrawn_at)
   for NDPA compliance, and staff (including qualifications, employment_history,
   salary_structure, leave_allocations).
3. Academic structure: sessions, terms, classes, arms, subjects, a
   class_subject_teacher pivot.
4. Assessment: ca_schemes (configurable weights per school), score_entries,
   grade_boundaries, report_card_tokens (for QR verification).
5. CBT: question_bank, exam_definitions, exam_sessions, student_attempts.
6. Finance: fee_structures, invoices, payments, expenses.
7. Operations: attendance_records, timetable_versions.
8. A seeder creating one demo school with sample students, staff, classes.
9. Composite indexes for tenant-scoped queries, at minimum on
   (school_id, created_at) and (school_id, student_id, term_id) — every
   query in this system filters by school_id, and unindexed tenant scoping
   degrades badly as student count grows.
10. Soft deletes (`deleted_at`) on students, staff, and score_entries —
    academic records have legal and historical value; a delete should
    never hard-remove a transcript.
11. Application-level encryption (Laravel's encrypted casts, not just
    disk-level encryption) on blood_group, allergies, and medical_notes
    specifically — these need to be unreadable even from a raw database
    dump.

Definition of done: `php artisan migrate:fresh --seed` runs clean; every
tenant-owned table has a school_id column and an enforced scope — add a
test proving one school's query can never return another school's rows.
```

---

## Stage 3 — Auth, Roles & Permissions

```
Implement authentication and role-based access control:

1. Laravel Sanctum for token-based auth (both /web and /mobile will consume
   this API).
2. Login, admin-invited registration only (no public self-signup, per the
   WhatsApp-first onboarding model in the product spec), password reset.
3. TOTP two-factor auth — required for School Admin and Super Admin,
   optional for others.
4. Role-based policies for all five roles. A School Admin only ever sees
   data scoped to their own school_id; Super Admin bypasses tenant scoping.
5. An audit log table + middleware recording who changed what on sensitive
   models (students, payments, results).
6. Subdomain-based tenant resolution middleware: requests to
   {school}.APP_DOMAIN resolve to that school's school_id before any
   controller runs.
7. Global rate limiting via Laravel's throttle middleware, per-IP and
   per-user — not just on the AI endpoints built in Stage 9. A buggy
   client or a scraping attempt against the fee or student endpoints
   should degrade gracefully, not take down the API for every tenant.
8. Harden the Super Admin role beyond 2FA: an IP allowlist for known
   office locations, or a mandatory VPN/private-network requirement. It
   bypasses tenant scoping entirely, so treat it as the highest-value
   target in the system, not just another role with an extra checkbox.

Definition of done: tests confirming — a School Admin cannot fetch another
school's students; 2FA is enforced on admin login; the audit log records a
sample sensitive action; an unknown subdomain is correctly rejected; a
request exceeding the rate limit is rejected with a clear 429.
```

---

## Stage 4 — SIS + Academic Structure APIs

```
Build the Student Information System, Staff HR, and academic structure APIs on the
Stage 2 schema:

1. CRUD for students, guardians, staff — full Nigeria-specific field set.
   Include staff qualifications, salary/payroll profile, and leave allocation endpoints.
   Validate allergies as a structured list, not free text, where possible.
2. NDPA Parental Consent endpoints: record guardian consent (with timestamp & IP)
   upon student registration and allow consent withdrawal/audit.
3. CRUD for sessions, terms, classes, arms, subjects, and assigning a
   teacher to a class-subject pair.
4. A promotion/repeat/transfer endpoint that moves a student between
   class/arm while preserving historical records rather than overwriting
   them.
5. Search/filter on the student list endpoint (by class, arm, fee status)
   to back the admin web screens.
6. A bulk-import endpoint: accept an Excel/CSV upload of students, validate
   rows with plain-English error messages rather than raw exceptions, and
   report exactly which rows succeeded or failed instead of failing the
   whole batch on one bad row. Admins migrating off Excel will use this
   far more than the single-student form.
7. Full school data export endpoint (`POST /api/v1/admin/export-data`): generate
   a downloadable zip/Excel archive containing all student records, academic history,
   and financial logs for data portability trust.

Definition of done: feature tests for create/update/list/filter on
students and staff; tests for parental consent recording & withdrawal; a test
confirming a promoted student's prior-term records are still queryable; a test
confirming a batch import with one deliberately bad row still imports valid rows;
a test verifying data export output.
```

---

## Stage 5 — Attendance + Timetable Engine

```
Build attendance and the constraint-based timetable engine:

1. Attendance: an endpoint generating a short-lived QR token for a class
   session, plus a manual mark-attendance endpoint. Confirm scan direction
   against the Stitch design before building. No RFID, no biometric — QR
   and manual only, per CLAUDE.md's hard rules.
2. GPS staff clock-in: accept lat/lng from the staff member's phone at
   check-in; store it on the attendance record. No geofencing enforcement
   in Phase 0 — log it, don't gate on it yet.
3. Timetable engine as a constraint-satisfaction problem: no teacher
   double-booked, no class double-booked, respect configurable break
   periods. Research and pick a suitable CSP/scheduling approach rather
   than a naive greedy algorithm — explain the choice before implementing.
4. Endpoints to trigger generation, regenerate, and publish a timetable
   version.
5. Don't confine offline handling to the CBT client in Stage 7 — teachers
   will mark attendance and students will check timetables without
   connectivity too. Add a local SQLite + sync-queue pattern to the
   Flutter app for attendance and timetable reads, with an explicit
   conflict rule per entity: attendance writes are server-wins on
   conflict; timetable reads are read-only, so there's nothing to
   reconcile.

Definition of done: a test seeding a deliberately conflicting constraint
set confirms the engine either resolves it cleanly or reports the specific
conflict — it must never silently double-book.
```

---

## Stage 6 — Assessment & Results

```
Build assessment and result processing — the flagship paid feature.
Prioritize correctness over speed here:

1. CA scheme CRUD: weighted components (e.g. CA1 20% / CA2 20% / Exam 60%)
   that must sum to 100%, with optional per-subject overrides.
2. Score entry endpoint (student × subject × CA component), validated
   against the scheme.
3. A queued result-compilation job: computes final scores, class
   rank/position, grade bands.
4. AI comment generation: call the Claude API (use claude-haiku-4-5-20251001
   for cost efficiency at this volume — confirm current model names and
   pricing at docs.claude.com before hardcoding, since these do change
   over time) with the student's score data, producing a short,
   encouraging, subject-specific remark. Store each comment in a
   `pending_approval` state with an audit trail linking it to the exact
   score data used to generate it — never let a generated comment print
   on a report card before a teacher has read and approved or edited it.
   Build the approve/edit endpoint as part of this stage, not a later one:
   an AI-written remark reaching a parent unreviewed is a real
   reputational and potentially legal risk, not a nice-to-have safeguard.
5. PDF report card generation (queued): branded template, subject score
   table, the approved AI comment, a QR code encoding a signed
   verification token. Refuse to generate a report card for any student
   whose comment is still in `pending_approval`. Build a public (no-auth)
   verification endpoint that looks up a token.
6. Broadsheet endpoint: whole-class, all-subjects, exportable to CSV/Excel.

Definition of done: an end-to-end test seeding a full class's scores,
triggering compilation, and confirming correct ranking math; a test that a
valid QR token verifies and a tampered one is rejected; a test confirming
a report card cannot be generated while its AI comment is still
`pending_approval`.
```

---

## Stage 7 — CBT Engine

```
Build the computer-based testing engine:

1. Question bank CRUD: subject, topic, difficulty, question type — start
   multiple-choice only for Phase 0, keep the schema extensible for Phase
   1's richer types.
2. Exam builder: assemble questions (manually or by topic/difficulty
   filter), set duration, randomization on/off, format label (WAEC/NECO/
   JAMB/custom).
3. Exam-session endpoints: start attempt, autosave each answer as it's
   submitted (don't wait for final submit), final submit, instant
   auto-grading of objective questions.
4. Design a sync contract for the offline exam client (build it now only
   if asked — otherwise just the contract): it must accept a batch of
   answers with client-side timestamps after reconnecting, handling
   out-of-order or delayed submission without an older answer overwriting
   a newer one.

Definition of done: a test that a started/answered/submitted attempt
produces the correct auto-graded score; a test that a delayed/out-of-order
sync batch doesn't corrupt already-submitted answers.
```

---

## Stage 8 — Fees & Finance

```
Build fees and payments. Flag any change here for explicit review before
merging — this is real money:

1. Fee structure CRUD (per class, per term, optional add-ons).
2. Invoice generation per student per term from their class's fee
   structure.
3. Manual payment recording (cash, bank transfer) plus Paystack and
   Flutterwave integration — check Packagist/current docs for a
   well-maintained Laravel package for each, or integrate directly against
   their REST APIs if nothing current and well-maintained exists. Handle
   payment-confirmation webhooks, not just the client-side redirect —
   verify webhook signatures, don't trust an unsigned callback.
4. A defaulter-list endpoint (outstanding balance, sorted descending).
5. Expense tracking CRUD (categorized entries, not linked to payments).

Definition of done: a test simulating a full webhook round-trip (invoice
created → payment initiated → webhook received → invoice marked paid); a
test that a malformed/unsigned webhook is rejected.
```

---

## Stage 9 — AI Studio + AI Learning Hub

```
Build the two AI-facing modules using the Claude API:

1. AI Studio (teacher-facing): endpoints for lesson-plan, worksheet, and
   quiz generation. Structured input (subject, topic, class level,
   duration) → structured output your frontend can render, not a wall of
   text — request a defined JSON shape in the API call. Save generations
   so teachers can revisit/reuse them.
2. AI Learning Hub (student-facing tutor): a conversational endpoint
   maintaining per-student conversation history, scoped to what that
   student's teacher has actually assigned (pull relevant assignment/
   curriculum context into the prompt), and designed to teach rather than
   just hand over exam answers outright. Use claude-sonnet-5 here — tutoring
   quality matters more than raw cost efficiency. Confirm current model
   names at docs.claude.com.
3. Rate-limit both endpoints per student/teacher to control API cost.
4. Cache AI Studio responses keyed by (subject, topic, class_level,
   language) with a 24–72 hour TTL, since multiple teachers at the same
   school will generate on identical topics. Vary or refresh the cached
   output after a few hits rather than serving the exact same worksheet
   indefinitely — two teachers comparing notes shouldn't notice they got
   an identical generation.
5. Store every system prompt used by AI Studio and the Learning Hub in
   versioned files or a prompts table with a version column, not as
   hardcoded strings in controllers — you'll need to know exactly which
   prompt version produced a given output when tuning tutoring behavior
   or comment tone later.
6. A daily AI-spend cap per school and a global cap, with an alert
   (email/Slack) at 80% of budget — end-of-term comment generation for a
   large school, or an unexpectedly heavy tutor session, can spike cost
   fast.
7. An explicit output-validation layer on the tutor endpoint beyond the
   base model's own behavior: specifically detect and block responses
   that hand over a direct CBT/exam answer instead of an explanation.
   That's a domain-specific rule for this product that a general-purpose
   model has no way to know on its own.

Definition of done: a test confirming a lesson-plan request returns the
expected structured fields; a test confirming tutor conversation history
persists and is correctly scoped per student; a test confirming a cached
AI Studio response is reused within the TTL window; a test confirming the
tutor declines to output a bare exam answer for a flagged question type; a
note documenting where the rate limits and spend caps live.
```

---

## Stage 10 — Notifications + Analytics

```
Build notifications, messaging, and the admin insights engine:

1. Push notification delivery (Firebase Cloud Messaging is the natural fit
   for the Flutter app) for attendance, fee, and result events.
2. SMS integration for high-volume notifications (paid feature per the
   product spec) — pick a Nigeria-capable SMS gateway and implement it
   behind a simple interface so the provider can be swapped later.
3. In-app Teacher-Parent messaging endpoints (threads, message sending, read receipts)
   so parents and teachers can communicate directly per the Stitch design specs.
4. Rule-based (not ML) insight generation: a scheduled job flagging
   students whose average dropped past a threshold term-over-term,
   computing a simple revenue forecast from payment patterns, flagging
   schools with rising fee-default rates. Surface as a feed the admin
   dashboard queries.

Definition of done: a test that a seeded grade drop correctly triggers an
insight record; teacher-parent message threads store and deliver correctly;
push and SMS sending sit behind an interface with a fake/test implementation so
tests don't hit real providers.
```

---

## Stage 11 — Web Admin Portal: Setup & Records

```
Build the Next.js web admin screens from Stitch Batches 1 and 2 (see
/docs/SchoolPilot-Stitch-Design-Prompts.md), wired to the Stage 4/5 APIs:

1. Admin Dashboard, School Setup Wizard, Student Records List, Student
   Profile Detail, Add/Edit Student Form.
2. Staff Directory, Staff Profile, Timetable Builder, Timetable Result
   View, Attendance Overview.
3. Match the color/typography system from the Stitch Master Design System
   Prompt for visual consistency with the generated mockups.
4. Handle loading and empty states for every list/table.

Definition of done: every screen renders real data from the backend
against a local dev database, not mock data; form validation errors
surface clearly; a Playwright test walks through creating a student end
to end via the UI.
```

---

## Stage 12 — Web Admin Portal: Results, CBT, Fees & Super Admin

```
Build the remaining web admin screens (Stitch Batches 3, 4, 5), wired to
Stage 6/7/8/10 APIs:

1. CA Scheme Setup, Result Compilation Dashboard, Report Card Preview
   (working print/export), Broadsheet View, CBT Exam Setup.
2. Fee Structure Builder, Invoice & Payment Tracking, Defaulter Dashboard,
   Compose Notification, Analytics & Insights.
3. Super Admin console: All Schools Overview, School Account Detail,
   Platform Settings, Billing Summary — gate this entire section behind
   the super_admin role.

Definition of done: a full result-compilation flow works end to end
through the UI for a seeded class; the report card PDF downloads and its
QR code verifies against the Stage 6 public verification endpoint.
```

---

## Stage 13 — Mobile App: Teacher Flows

```
Build the Flutter screens for the Teacher role (Stitch Batches 6 and 7).
Set up the app's role-based navigation shell first if it doesn't exist yet
— a bottom tab nav that changes per logged-in role — since Teacher,
Student, and Parent all extend this same shell:

1. Teacher Dashboard, Class Attendance (QR scan via device camera, plus
   manual roster), Gradebook/Score Entry, Assignment Creation, Assignment
   Grading Queue.
2. AI Studio Home, Lesson Plan Generator, Worksheet/Quiz Generator, wired
   to Stage 9's endpoints.

Definition of done: a teacher can log in, mark attendance via QR scan on
a real device/emulator camera, enter grades, and generate a lesson plan,
all against the live local backend.
```

---

## Stage 14 — Mobile App: Student Flows

```
Build the Flutter screens for the Student role (Stitch Batches 8 and 9):

1. Student Dashboard, Timetable View, Assignment List & Detail, CBT
   Exam-Taking View (working countdown timer, a submit confirmation that
   can't trigger accidentally), Results View.
2. AI Tutor Chat, Study Progress, Subject Library.

Definition of done: a student can take a full CBT exam end to end (start,
answer, submit, see the auto-graded result) and hold a multi-turn
conversation with the AI tutor that correctly remembers earlier messages
in the same session.
```

---

## Stage 15 — Mobile App: Parent Flows

```
Build the Flutter screens for the Parent role (Stitch Batch 10):

1. Parent Dashboard (working child-selector for parents with multiple
   children), Attendance History, Fees/Pay (wire the real Paystack/
   Flutterwave flow from Stage 8 — this is a real-money screen, test it
   thoroughly against sandbox/test API keys before anything else), Results
   View, Messages with Teacher.

Definition of done: a parent with two linked children can switch between
them and see correct, non-mixed data for each; a test payment completes
successfully against Paystack/Flutterwave's sandbox environment.
```

---

## Stage 16 — Testing Suite

```
Harden test coverage across the whole system before deployment:

1. Backend: run `php artisan test --coverage` and fill gaps in any Stage
   2–10 module below reasonable coverage — prioritize payment, results,
   and CBT-grading logic above everything else.
2. Web: Playwright E2E for the critical paths — admin creates a student,
   compiles results, generates a report card; admin sets up a fee
   structure and views the defaulter list.
3. Mobile: widget tests for the CBT exam-taking flow and the attendance
   QR-scan flow at minimum, given how failure-sensitive both are.
4. Write TESTING.md documenting how to run each suite and what's
   intentionally not covered yet.

Definition of done: all three suites run with a single documented command
each and pass cleanly on a fresh clone of the repo.
```

---

## Stage 17 — CI/CD & Hosting Setup

```
Set up deployment infrastructure. Start with the cheapest path that's
still production-safe — this doesn't need enterprise-scale infrastructure
for a first launch:

1. Backend: deploy to Laravel Cloud (git-push deploys, managed Postgres
   and Redis, no server management) — or Laravel Forge on a small
   DigitalOcean droplet if minimizing early recurring cost matters more
   than convenience. Either way: separate staging and production
   environments, secrets never committed to git.
2. Web admin: deploy to Vercel, connected to the same repo, auto-deploying
   /web on push to main.
3. Configure wildcard DNS (*.yourdomain.com) at the backend so Stage 3's
   subdomain tenant resolution works in production, plus a root-domain
   landing page.
4. CI pipeline (GitHub Actions or equivalent): run the Stage 16 suites on
   every pull request; only deploy to production from main after tests
   pass.
5. Basic error tracking (e.g. Sentry) and uptime monitoring on backend and
   web admin.
6. Confirm production Paystack/Flutterwave keys are live-mode, not
   test-mode, and webhook URLs point at the production domain.

Definition of done: a push to main triggers a full CI run and, on success,
deploys backend and web automatically; a real subdomain request in
production resolves to the correct tenant; a live-mode test transaction
completes successfully.
```

---

## Stage 18 — Go-Live Checklist

```
Before announcing this to real schools, confirm each of these against the
live production environment, not staging:

1. Full smoke test in production: log in as each of the five roles, mark
   attendance, enter a score, compile a result, generate and verify a
   report card, run a full CBT exam attempt, process a real small test
   payment, send a push notification and an SMS.
2. Confirm daily automated database backups are actually running, and
   schedule a recurring monthly restore test to a separate database with
   a data-integrity check — a backup you've only restored from once
   during launch week is a backup you've mostly not tested.
3. Write down your actual current RTO (how fast you can recover) and RPO
   (how much data you could afford to lose), honestly, based on the
   infrastructure you actually have today — not the ideal numbers.
   Revisit and tighten both as the system matures; a published RTO you
   can't currently hit is worse than an honest, modest one.
4. Confirm 2FA works for a real School Admin account, not just in tests.
5. Confirm the promised data-export feature produces a usable Excel file
   with real data.
6. Load-test result compilation with a realistic worst case (a school with
   several hundred students all compiling near end-of-term) so the first
   real results day isn't the first time it's been tried at that scale.
7. Have the WhatsApp Business number, a lead-tracking sheet, and an
   after-hours auto-reply message ready before the first school is
   onboarded.

Definition of done: every item above is checked off against production
specifically, with who checked it and when noted somewhere durable — a
LAUNCH.md or issue tracker, not memory.
```

---

## Extending to Phase 1 / Phase 2

Once Phase 0 is live and at least one real school is using it, the same
pattern extends forward: one stage per module group, referencing the
matching Stitch batch and the relevant section of
`SchoolPilot-Comprehensive-Documentation.md` (§8 for Phase 1, §9 for Phase
2). Don't write these out in detail before Phase 0 has real users — what
Phase 1 actually needs to prioritize will be clearer once real schools are
using the core product than it is right now.

---

*This is the whole path from empty repo to a school actually using it. If you want, I can also draft the LAUNCH.md and TESTING.md templates referenced above, or write the actual Paystack/Flutterwave webhook-verification code for Stage 8 directly, rather than leaving it to the agent.*
