# SchoolPilot Mobile — Build Instructions & App Documentation

**Status:** plan, not yet built. `mobile/` is empty.
**Target:** one Flutter codebase, role-based navigation, Android-first.
**Backend:** the existing Laravel API at `/api/v1/...` (see `backend/routes/api.php`).

This file has two parts:

- **Part A — Build Instructions.** The working agreement I follow while building
  the app. Rules, stack decisions, layout, definition of done, stage order.
- **Part B — App Documentation.** What the app actually is: roles, screens, the
  endpoint behind each screen, offline behaviour, and the backend gaps that
  block specific screens.

---

# Part A — Build Instructions

## A1. Scope

**In scope (v1):** the four school-side roles — Admin, Teacher, Student, Parent
— in one installable app, with role-based navigation decided at login.

**Out of scope (v1):**

- **Super Admin.** The platform console stays on web. A SchoolPilot operator
  administering 40 schools is doing desk work on a laptop, not a handset.
  `role:super_admin` routes are not wired into the app.
- **The offline exam client.** Doc §7.8 specifies that as a downloadable
  *desktop* client for the school's existing lab PCs. The Flutter app sits
  papers online, with local resilience (§B7) — it is not the lab client.
- Bulk data entry that belongs on web: broadsheet grid entry, CSV import,
  fee-structure building, report-card template import.

## A2. Hard rules (inherited from `CLAUDE.md`, non-negotiable)

1. **No biometrics, no purchased hardware.** Attendance is phone camera (QR)
   and phone GPS. If a screen seems to need a reader or scanner, redesign it.
2. **K-12 only.** Terms, classes, arms, CA/exam, JSS/SS, WAEC. Never semesters,
   faculties, credits, or GPA.
3. **NDPA data minimisation.** Do not cache student health data on the device
   at all (§B6). Do not add a personal-data field to a form without checking
   doc §12.
4. **Money is Naira.** `₦` symbol, two decimals, thousands separators, one
   shared formatter. Never a bare number, never `$`.
5. **No touching payment, auth, or deployment config** without explaining the
   change and its risk first.
6. **Every call goes to `/api/v1/...`.** The version prefix is a constant in one
   place. An installed app is the reason the prefix exists.
7. **An AI comment is never shown as final while `pending_approval`.** On the
   teacher's review screen it is labelled as a draft awaiting approval; a parent
   or student screen must not render it at all until approved.

## A3. Mobile-specific rules

- **Offline is a design constraint, not a fallback.** Any screen a teacher or
  student opens during the school day must render from cache and say when the
  data was last refreshed. Writes queue (§B7).
- **Mid-range Android is the target device.** Budget: cold start under 3s on a
  4-year-old handset, APK under 40 MB, no screen holding more than ~200 rows in
  memory without pagination.
- **Data costs money.** No polling loops, no auto-downloading images on a
  metered connection, `ETag`/`If-None-Match` where the backend supports it,
  pull-to-refresh over background refresh.
- **Every failure state is a real state.** Loading / empty / error / offline are
  four distinct widgets, and every list and detail screen has all four. An
  endless spinner is a bug.
- **No secrets in the binary.** No API keys, no gateway secret keys, no admin
  tokens. Public gateway keys are supplied at runtime or by `--dart-define`.
- **Role is enforced server-side; the app only hides.** Never treat a hidden
  button as a permission check — 403 is always a possible response and every
  screen handles it.

## A4. Stack

| Concern | Choice | Why |
|---|---|---|
| State | `flutter_riverpod` | Testable without a widget tree; compile-safe overrides for fakes. |
| Routing | `go_router` | Declarative role-based redirect at the router level, one place to gate auth. |
| HTTP | `dio` + interceptors | Interceptor chain is where tenancy, auth, retry and 401 handling live. |
| Models | `freezed` + `json_serializable` | Backend returns plain JSON; hand-written parsing rots. |
| Local DB | `drift` (SQLite) | Typed queries, migrations, and it is what the outbox needs. |
| Token storage | `flutter_secure_storage` | Keystore/Keychain, never SharedPreferences. |
| Push | `firebase_messaging` + `flutter_local_notifications` | Matches `DeviceToken.platform` (`android`/`ios`). See gap **G7**. |
| QR scan | `mobile_scanner` | Attendance clock-in, phone camera only. |
| GPS | `geolocator` | Staff GPS clock-in against the school geofence. |
| Rich question content | `flutter_widget_from_html` + `flutter_math_fork` | CBT questions carry sanitised HTML, images and LaTeX. |
| Charts | `fl_chart` | Analytics dashboards. |
| Gateway checkout | `webview_flutter` | Paystack/Flutterwave hosted checkout. No card fields in our UI, no PCI surface. |
| Connectivity | `connectivity_plus` | Triggers the outbox drain. |
| Tests | `flutter_test`, `mocktail`, `integration_test` | |

Config by `--dart-define`: `API_BASE_URL`, `ENV`, `SENTRY_DSN`. No `.env` in the
bundle.

## A5. Project layout

Feature-first, because the roles slice the app vertically:

```
mobile/
  lib/
    main.dart
    app/            router, theme, localisation, bootstrap
    core/
      api/          dio client, interceptors, ApiException, endpoints.dart
      db/           drift database, outbox, sync worker
      auth/         session store, token, role
      ui/           shared widgets: states, money, chips, tables
    features/
      auth/         login, school code, 2FA
      dashboard/    one per role
      attendance/
      timetable/
      assessment/   scores, broadsheet read-only, report cards
      cbt/
      homework/
      finance/
      results/      result checker + PINs
      messaging/
      ai/           tutor chat, AI studio
      profile/
    l10n/
  test/
  integration_test/
```

Each feature: `data/` (dto + remote + local), `domain/` (model + repository
interface), `presentation/` (screens + controllers).

## A6. Conventions

- **One API client.** Every request passes through `core/api`. No `dio` instance
  is constructed inside a feature.
- **Interceptor order:** tenancy header → auth header → retry → error mapping →
  logging. `401` clears the session and redirects once, never in a loop.
- **Errors surface as `ApiException`** with a `status`, a user-safe message, and
  the backend's `errors` map for field-level display on 422.
- **Dates are ISO 8601 UTC on the wire, West Africa Time in the UI.** Nigeria
  is UTC+1 with no DST — one formatter, no per-screen conversion.
- **Naira through `Money.format()` only.**
- Comment the *why*, at the density of the existing backend. Do not narrate
  what the code plainly says.

## A7. Definition of done for a stage

A stage is done when all of the following are true:

- The screens render from real API responses against a seeded local backend.
- Loading, empty, error and offline states each exist and were seen.
- Widget tests cover the controller logic; the repository is tested against a
  mocked client, including the 401/403/422 paths.
- `flutter analyze` is clean and `flutter test` passes.
- Anything blocked by a backend gap is listed in the commit message, not
  silently stubbed.
- One commit, clear message, at the end of the stage.

## A8. Stage order

Each stage is independently useful, and each ends in a commit.

| # | Stage | Contents |
|---|---|---|
| 0 | Skeleton | `flutter create`, layout, theme from the Stitch tokens, router, dio client + interceptors, drift schema, CI job. |
| 1 | Auth & tenancy | School-code entry, login, 2FA step, secure token, role-based redirect, session expiry. Gap **G1** filed. |
| 2 | Shell & dashboards | Bottom nav per role, four dashboards from `/analytics/*`, profile screen, sign-out. |
| 3 | Attendance | Teacher register (bulk, idempotent, offline-queued), QR clock-in, staff GPS clock-in, history. First real use of the outbox. |
| 4 | Timetable & academics | `/timetable/view` with cache, subjects, class lists, student/parent read paths. |
| 5 | Homework | Teacher set/grade, student submit, parent visibility. Gap **G5**. |
| 6 | Results | Student/parent results, report-card view, result checker + PIN redeem, purchase via webview. Gaps **G8**, **G9**. |
| 7 | CBT | Available exams, sitting a paper (timer, image/LaTeX rendering, local answer buffer), submit, result. |
| 8 | Finance | Invoices, statement, payment history, receipts, gateway checkout, admin defaulter view. |
| 9 | Communication & AI | Threads, messaging, notification inbox, student tutor chat, teacher AI studio with the `pending_approval` review flow. Gaps **G6**, **G7**. |
| 10 | Hardening | Offline soak, low-end device profiling, accessibility pass, crash reporting, release signing, store listings. |

---

# Part B — App Documentation

## B1. What the app is

One Flutter application. A user signs in with their school's code and their
email; the backend returns a role; the router picks that role's navigation
graph. There is no role switcher — a person who is both a teacher and a parent
has two accounts, which is how the backend already models it (`UserProfile.role`
is singular).

## B2. Tenancy and authentication

**School code.** The backend resolves the tenant from the host subdomain, and
falls back to the `X-School-Subdomain` header
(`TenantResolutionMiddleware`). A mobile app has no subdomain, so:

1. First launch asks for the **school code** (the school's subdomain, e.g.
   `graceland`). Stored locally, editable from the login screen.
2. Every request sends `X-School-Subdomain: <code>`.
3. `POST /api/v1/auth/login` also sends `subdomain` in the body — the controller
   checks the user actually belongs to that tenant and audits a mismatch as
   `user.login_wrong_tenant`.

**Login.** `POST /api/v1/auth/login` → `{ token, user: { id, name, email, role,
school_id } }`. Token to `flutter_secure_storage`, sent as
`Authorization: Bearer <token>`.

**2FA.** School admins with `two_factor_enabled` get `422` with
`requires_2fa: true`. The app then shows a six-digit code step and re-posts
login with `two_factor_code`. Teachers, students and parents never see this
step.

**Rate limits the UI must respect:** login is 5/min per email+IP, PIN redeem is
10/min, everything else shares 60/min. On `429` the app shows a plain
"too many attempts, try again shortly" and backs off — it does not retry
automatically.

**Sign-out.** Delete the FCM token via `DELETE /api/v1/notifications/devices`,
wipe secure storage and the local cache. The Sanctum token is *not* revoked
server-side — see gap **G1**.

## B3. Roles and navigation

Five tabs maximum, because a fifth is already the practical limit on a 5-inch
screen.

**Admin** — Home · People · Finance · Academics · More
Home is `/analytics/principal`. People covers students, staff and users.
Finance covers invoices, defaulters, payments. Academics covers results,
timetable and CBT oversight. More holds calendar, notifications, result PINs and
settings.

**Teacher** — Home · Register · Gradebook · Classes · More
Home is `/analytics/teacher`. Register is the day's roll call, and it is the
screen designed to work with no signal at all. Gradebook is score entry and the
AI-comment review queue. Classes covers homework, students and the timetable.
More holds messages, leave requests and AI Studio.

**Student** — Home · Timetable · Learn · Results · More
Home is `/analytics/student`. Learn holds homework, CBT and the AI tutor.
Results is read-only and gated by release status. More holds fees, the
portfolio and gamification.

**Parent** — Home · Child · Fees · Results · More
Home is the feed for the selected child, with a child switcher in the app bar
when a guardian has several. Child covers attendance, behaviour and the
timetable. Results is PIN-gated. More holds messages, pickup authorisation and
consent.

## B4. Screen inventory and the endpoint behind each

### Shared

| Screen | Endpoint |
|---|---|
| School code + login | `POST /auth/login` |
| Session bootstrap | `GET /user` |
| Register push device | `POST /notifications/devices` |
| Message threads | `GET /messages/threads`, `GET /messages/threads/{id}`, `POST /messages/send` |
| Timetable | `GET /timetable/view` |
| Subjects | `GET /subjects`, `GET /classes/{classId}/subjects` |

### Admin

| Screen | Endpoint |
|---|---|
| Dashboard | `GET /analytics/principal` |
| Student roster / detail | `GET /students`, `GET /students/{id}` |
| Add student | `POST /students` |
| Class history | `GET /students/{student}/class-history` |
| Users | `GET /users`, `GET /users/{id}`, `POST /users/{id}/status`, `POST /users/{id}/reset-password`, `POST /users/{id}/unlock` |
| Invite | `POST /auth/invite` |
| Defaulters | `GET /finance/defaulters` |
| Record payment | `POST /finance/payments` |
| Invoice / receipt PDF | `GET /finance/invoices/{id}/pdf`, `GET /finance/payments/{id}/receipt` |
| Income report | `GET /accounting/income-report` |
| Leave decisions | `GET /hr/leave`, `POST /hr/leave/{id}/decision`, `GET /hr/leave-calendar` |
| Result release | `GET /results/releases`, `POST /results/release` (school_admin only, deliberately no super_admin) |
| PIN inventory & sales | `GET /result-pins/inventory`, `GET /result-pins/batches`, `POST /result-pins/counter-sale`, `GET /result-pins/sales-report` |
| Report-card run | `POST /report-cards/generate` → poll `GET /jobs/{batchId}` |
| Broadcast | `POST /notifications/broadcast` (channel picker must state that SMS is billed per message) |

### Teacher

| Screen | Endpoint |
|---|---|
| Dashboard | `GET /analytics/teacher` |
| Class register | `GET /attendance/register`, `POST /attendance/bulk` |
| QR clock-in token | `POST /attendance/qr-token` |
| Mark one student | `POST /attendance/mark` |
| Staff GPS clock-in | `POST /attendance/staff-gps` |
| Score entry | `POST /assessment/score` |
| Broadsheet (read-only on mobile) | `GET /assessment/broadsheet` |
| AI comment draft | `POST /assessment/score/{id}/ai-comment` |
| **Approve / edit comment** | `POST /assessment/score/{id}/review-comment` |
| Homework | `POST /academics/homework`, `GET /academics/homework/{id}/submissions`, `POST /academics/submissions/{id}/grade`, `POST /academics/homework/{id}/bulk-grade` |
| Behaviour report | `POST /students/behavior-report` |
| Comment bank | `GET /teacher/comment-bank` |
| Leave request | `POST /hr/leave`, `GET /hr/leave`, `POST /hr/leave/{id}/cancel` |
| CBT authoring (light) | `GET /cbt/exams`, `POST /cbt/exams`, `POST /cbt/exams/{id}/publish`, `GET /cbt/exams/{id}/results` |
| AI Studio | `POST /ai/lesson-plan`, `POST /ai/exam-generation`, `POST /ai/homework-ideas`, `POST /ai/translate` |

### Student

| Screen | Endpoint |
|---|---|
| Dashboard | `GET /analytics/student` |
| My subjects | `GET /students/{id}/subjects`, `POST /students/{id}/subjects` |
| Homework submit | `POST /academics/homework/{id}/submit`, `GET /academics/homework/{id}/my-submission` |
| CBT list | `GET /cbt/available` |
| Sit a paper | `POST /cbt/exams/{id}/start`, `GET /cbt/attempts/{id}`, `POST /cbt/attempts/{id}/answers`, `POST /cbt/attempts/{id}/submit`, `POST /cbt/attempts/{id}/events` |
| Offline answer flush | `POST /cbt/offline-sync` |
| CBT result | `GET /cbt/attempts/{id}/result` |
| Results | `GET /result-checker/{studentId}/{termId}/summary`, `GET /result-checker/{studentId}/{termId}` |
| Fees | `GET /finance/students/{id}/statement` |
| AI tutor | `POST /ai/tutor-chat`, `GET /ai/tutor/conversations`, `GET /ai/tutor/conversations/{id}`, `GET /ai/tutor/mastery` |
| Portfolio | `GET /students/{id}/portfolio` |
| Gamification | `GET /gamification/profile/{studentId?}`, `GET /gamification/leaderboard`, `POST /gamification/activity` |

### Parent

| Screen | Endpoint |
|---|---|
| Child feed | `GET /parent/feed/{studentId}` |
| Behaviour history | `GET /students/{studentId}/behavior-reports` |
| Child record | `GET /students/{id}` |
| Health record | `GET /students/{id}/medical` — **never cached** (§B6) |
| Fees | `GET /finance/students/{id}/statement`, `GET /finance/invoices/{id}/installment-plan` |
| Pay | `POST /finance/payments` + gateway webview |
| Results | `GET /result-checker/{studentId}/{termId}/summary` then `/…/{termId}` |
| Buy a PIN | `POST /result-checker/purchase` |
| Redeem a counter PIN | `POST /result-checker/redeem` |
| My PINs | `GET /result-checker/my-pins` |
| Pickup authorisation | `POST /parent/pickup-authorization` |
| Consent | `POST /compliance/parental-consent`, `POST /compliance/parental-consent/{id}/withdraw` |

## B5. Attendance — hardware-free, offline-first

Three paths, all phone-only:

1. **Roll call (primary).** Teacher opens the register, taps present/absent/
   late/excused down the list, submits once. `POST /attendance/bulk` takes up to
   200 records and **requires an `idempotency_key`** (8–100 chars), which the
   backend caches per school. The app generates a UUID when the register is
   opened, persists it with the queued record, and reuses that same key on every
   retry. This is what makes a dropped connection safe.
2. **QR clock-in.** Teacher displays a short-lived token from
   `POST /attendance/qr-token`; students scan with the phone camera. No reader.
3. **Staff GPS clock-in.** `POST /attendance/staff-gps` with lat/long, checked
   against the school geofence server-side. The app must explain the location
   permission in-context before requesting it, and must degrade to "ask your
   admin to mark you present" if permission is refused — never block the user.

## B6. Data the app must not store

Under NDPA data minimisation (doc §12):

- **Student health records** (`GET /students/{id}/medical`) are fetched fresh,
  held in memory only, and cleared when the screen is popped. Every read is
  audited server-side; a cached copy would be an unaudited one.
- No student photos or personal data in app logs or crash reports.
- The local cache is wiped on sign-out and on a 401 that ends the session.
- The device cache is not encrypted-at-rest by default, so nothing sensitive
  beyond timetable, own scores and roster names goes into drift.

## B7. Offline strategy

Two mechanisms, deliberately separate.

**Read cache.** Drift tables mirror the endpoints a user opens during the day:
timetable, class register, student roster, homework list, own results. Each row
carries `fetched_at`; every screen shows "Updated 14:32" and a manual refresh.
Stale data is shown, never hidden.

**Write outbox.** Every mutation made while offline becomes a row:

```
outbox(id, method, path, body_json, idempotency_key,
       created_at, attempts, last_error, status)
```

Drained on reconnect (`connectivity_plus`) and on app resume, oldest first,
with exponential backoff. Rules:

- The idempotency key is generated **once at enqueue** and never regenerated.
- A `4xx` other than `408`/`429` is terminal: the row is marked failed and
  surfaced to the user with the server's message. Silent discards are not
  allowed.
- The user always has a visible "N pending" indicator and can inspect the queue.

**CBT is a special case.** Answers are written to local SQLite with an
incrementing `client_sequence` and a `client_timestamp` as the candidate taps,
flushed to `POST /cbt/attempts/{id}/answers` opportunistically, and reconciled
via `POST /cbt/offline-sync` (which accepts `client_sequence`/`client_timestamp`
and ignores anything for an already-finalised attempt). A candidate who loses
signal mid-paper keeps answering. Exam timing stays server-authoritative — the
local timer is a display, and `ExpireOverdueCbtAttempts` is the truth.

## B8. CBT on mobile

The paper renderer must handle what the authoring side can produce (see
`docs/cbt-authoring-guide.md`): sanitised HTML, referenced image assets from the
media library, and LaTeX. Images are pre-fetched at attempt start so a mid-paper
signal drop does not blank a diagram. Tab-away and focus-loss are logged via
`POST /cbt/attempts/{id}/events` — a proctoring signal, not a lockdown; the app
does not attempt kiosk mode.

## B9. Results and PINs

The result path is gated twice, and the UI must respect both:

1. **Release.** Until the school admin releases the term for that class, the
   summary endpoint says so. The screen shows "results not yet released" — it
   never offers a purchase.
2. **PIN.** Once released, `GET /result-checker/{studentId}/{termId}/summary`
   reports whether access is already unlocked. If not, the parent either
   redeems a counter-bought PIN or buys online.

`POST /result-checker/purchase` mints a reference and returns it `pending`; the
gateway runs **client-side** and the backend only trusts the signed webhook.
So the app opens a webview at the gateway, then polls the summary endpoint for
unlock rather than trusting the webview's own success callback. A purchase for
an already-unlocked result is refused with `409` — that guard exists precisely
because a parent will tap twice.

Staff never pay: `/report-cards/{studentId}/{termId}` is unmetered.

## B10. Notifications

Register the FCM token on login and on token refresh via
`POST /notifications/devices` with `platform: android|ios`. Unregister on
sign-out so a shared handset stops receiving another family's alerts. Deep-link
payloads route to the relevant screen (invoice, result, homework, message).
Blocked by gap **G7**.

## B11. Testing

- **Unit:** repositories against a mocked `dio`, including 401/403/422/429.
- **Widget:** each screen's four states; the register screen's offline path.
- **Integration (`integration_test`):** login → role redirect; offline roll call
  → reconnect → single server write (proving the idempotency key is reused);
  CBT attempt with a simulated signal drop.
- **Golden tests** for the report-card and result views, where layout is the
  product.

Run `flutter analyze` and `flutter test` before calling any stage done.

## B12. Backend gaps that block mobile work

Verified against `backend/routes/api.php` and the controllers on
`feat/cbt-images-latex-report-card-templates`. Each needs a backend change; none
should be worked around by hacking the client.

| # | Gap | Impact | Suggested fix |
|---|---|---|---|
| **G1** | No `POST /auth/logout`. Only `login` and `invite` exist. | Signing out leaves a valid Sanctum token alive forever. On a shared or stolen handset that is a live session. | Add `POST /api/v1/auth/logout` deleting the current access token. |
| **G2** | No self-service password reset. Only admin-initiated `POST /users/{id}/reset-password`. | A parent who forgets their password must phone the school. This will be the single largest support cost at scale. | Add forgot/reset-password with an emailed or SMS token. |
| **G3** | `PUT /users/{id}/profile` is admin-only. | Students and parents cannot update their own phone number or photo. | Add a `PUT /me/profile` scoped to the caller. |
| **G4** | `GET /cbt/exams/{examId}/offline-package` is staff-only. | A student device cannot pre-download a paper. Fine if offline CBT is desktop-only (§A1) — a blocker the moment mobile offline sitting is wanted. | Decide explicitly; if wanted, add a candidate-scoped variant behind `CbtAttemptPolicy`. |
| **G5** | No student- or parent-facing homework list. Only `POST /academics/homework` (teacher), the teacher's submissions index, and the student's own single-submission endpoint. | A student cannot discover what homework exists — only open one they already have the id for. | Add `GET /academics/homework` scoped to the caller's class/child. |
| **G6** | `GET /notifications/history` is `role:super_admin,school_admin`. | No per-user notification inbox. A parent can receive a push but cannot see what they were sent. | Add `GET /me/notifications`, paginated, own rows only. |
| **G7** | `NotificationService::push()` posts to `https://fcm.googleapis.com/fcm/send` with a `server_key`. That is the **legacy FCM API, decommissioned by Google**. | Push does not work at all, regardless of client code. Stage 9 cannot be completed. | Migrate to FCM HTTP v1 (OAuth service account, `/v1/projects/{id}/messages:send`). Backend change; flag before touching. |
| **G8** | No endpoint exposes a school's gateway **public** key, and `School` stores none. `ResultCheckerController::purchase` explicitly expects checkout to be driven client-side "with the school's own public key". | The app has no way to learn which key to open checkout with, in a product where each school holds its own merchant account. | Either add the public key to the school settings payload, or add a server-side `initialize` returning a hosted `authorization_url`. The second is safer. |
| **G9** | `POST /finance/payments` requires a client-supplied unique `reference`. | A client minting its own payment reference is fragile — a retry with a fresh reference creates a duplicate pending row. | Have the server mint the reference, as `result-checker/purchase` already does with `generateReference('SPRP')`. |
| **G10** | No paginated envelope on several list endpoints. | Roster and history screens can pull the whole table on a metered connection. | Confirm per endpoint during Stage 2; add cursor pagination where missing. |

**Handling:** G1, G2 and G7 are filed before Stage 1 starts, since Stage 1
(auth) and Stage 9 (push) sit directly on top of them. G8/G9 are decided before
Stage 8 — I will not ship a payment flow that invents its own reference without
the change being agreed first, per the payment-config rule in `CLAUDE.md`.

## B13. Related documents

- Product spec: `docs/SchoolPilot-Comprehensive-Documentation.md` (§3 hardware,
  §7.16 mobile, §11 architecture, §12 NDPA)
- UI reference: `stitch_schoolpilot_management_system/` and its `DESIGN.md`
- Stitch prompt for the mobile screens: `docs/mobile-stitch-prompt.md`
- CBT content rules: `docs/cbt-authoring-guide.md`
- Report-card rendering: `docs/report-card-template-contract.md`
