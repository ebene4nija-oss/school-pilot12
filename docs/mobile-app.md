# SchoolPilot Mobile — Build Instructions & App Documentation

**Status:** built. The app lives in `mobile/` — see `mobile/README.md` to run it.
`flutter analyze` is clean and `flutter test` passes.
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
  tokens. Since G8 was closed the app holds **no gateway key at all**, public
  ones included: checkout is opened server-side and the app is handed a hosted
  `authorization_url` to open in a webview (§B14).
- **Role is enforced server-side; the app only hides.** Never treat a hidden
  button as a permission check — 403 is always a possible response and every
  screen handles it.

## A4. Stack

| Concern | Choice | Why |
|---|---|---|
| State | `flutter_riverpod` | Testable without a widget tree; compile-safe overrides for fakes. |
| Routing | `go_router` | Declarative role-based redirect at the router level, one place to gate auth. |
| HTTP | `dio` + interceptors | Interceptor chain is where tenancy, auth, retry and 401 handling live. |
| Models | hand-written, tolerant parsing | See the note below. |
| Local DB | `sqflite` | See the note below. |
| Token storage | `flutter_secure_storage` | Keystore/Keychain, never SharedPreferences. |
| Push | `firebase_messaging` + `flutter_local_notifications` | Matches `DeviceToken.platform` (`android`/`ios`). Backend transport is live — see §B10. |
| QR scan | `mobile_scanner` | Attendance clock-in, phone camera only. |
| GPS | `geolocator` | Staff GPS clock-in against the school geofence. |
| Rich question content | `flutter_widget_from_html` + `flutter_math_fork` | CBT questions carry sanitised HTML, images and LaTeX. |
| Charts | `fl_chart` | Analytics dashboards. |
| Gateway checkout | `webview_flutter` | Paystack/Flutterwave hosted checkout. No card fields in our UI, no PCI surface. |
| Connectivity | `connectivity_plus` | Triggers the outbox drain. |
| Tests | `flutter_test`, `mocktail`, `integration_test` | |

Config by `--dart-define`: `API_BASE_URL`, `ENV`, `SENTRY_DSN`. No `.env` in the
bundle.

**Three decisions taken during the build that differ from this plan as first
written**, recorded here rather than left as a surprise in the code:

1. **`sqflite`, not `drift`.** Drift's typed queries are nicer, but they cost a
   `build_runner` step on every schema change. There are three tables — cache,
   outbox, CBT answers — and the outbox has about six queries. That does not
   earn a codegen pipeline in CI. `AppDatabase` is 90 lines of plain SQL.
2. **Hand-written parsing, not `freezed`/`json_serializable`.** The backend is
   not uniform about response envelopes: some endpoints return a bare array,
   some wrap in `data`, some in a named key, and field names vary between
   `name`, `full_name` and `user.name` for the same thing. Generated strict
   models would throw on the variance. `core/data/cached.dart` has four
   tolerant readers (`listOf`, `mapOf`, `numOf`, `stringOf`) that every screen
   uses instead. Revisit once the API is consistent — the parsing is confined
   to the data layer precisely so it can be swapped.
3. **No `firebase_messaging` yet — but it is now unblocked.** This originally
   read "push cannot work at all until gap **G7** is fixed on the backend".
   G7 is fixed: the backend sends over FCM HTTP v1 and there is a
   `POST /notifications/devices/test` endpoint that pushes to your own handset
   and tells you per device whether it arrived. What remains is client work —
   `google-services.json`, the iOS APNs key, and the notification channel named
   in §B10. See §B10 for the exact payload the server sends.

## A4.1 Theme — from the mobile design system

Source of truth:
`docs/stitch_schoolpilot_mobile_ui_design/stitch_schoolpilot_mobile_ui_design/high_contrast_functionalism/DESIGN.md`.
That is the *mobile* system ("High-Contrast Functionalism") and it is **not
identical** to the web one in `stitch_schoolpilot_management_system/`
("Academic Credibility System"). Where they differ, mobile wins on mobile.
The whole palette goes into one `ColorScheme` in `app/theme.dart`; no feature
declares a hex value.

**Colours that changed from the web system** — the ones that will trip you up if
you copy the web values:

| Token | Mobile | Web | Note |
|---|---|---|---|
| `primary` | `#000e22` | `#002447` | Mobile went a shade darker for sunlight contrast. |
| `primary-container` | `#002447` | `#1b3a5f` | The old primary is now the container. |
| `on-primary-container` | `#718cb5` | `#88a4cf` | |
| `secondary-container` | `#ffc16a` | `#feae2c` | See the amber note below. |
| `tertiary` | `#001105` | `#002b12` | |
| `surface-container` | `#f0edec` | `#f0eded` | |

Unchanged and load-bearing: `surface`/`background` `#fcf9f8`, cards
`#ffffff`, `outline-variant` `#c3c6cf`, `error` `#ba1a1a`.
Semantic status colours are not in the token block but are used throughout the
screens: present/paid `#2e7d32`, absent/overdue `#c62828`, late/pending
`#f5a623`.

**Two internal conflicts in that DESIGN.md — resolve them this way:**

1. **Action amber.** The token block says `secondary-container: #ffc16a`; the
   prose and every generated screen use `#feae2c`. Use **`#feae2c`** for the
   primary action button, with `#1b1c1b` text. Ship one amber, not two.
2. **Corner radii.** The `rounded` token scale is tight (`lg: 0.5rem`,
   `xl: 0.75rem`) but the prose specifies cards 8px, major sections 16px, and
   fully-rounded buttons and chips. Follow the **prose** — that is what the
   screens show. In Flutter: `cardRadius = 8`, `sectionRadius = 16`,
   `StadiumBorder()` on buttons and chips.

**Typography** is renamed from the web system — `headline-lg/md/sm`,
`body-lg/md`, `label-lg/sm`. There is no `data-mono` token any more, but the
screens still set numbers apart. Define a `numeric` text style: Inter, 14/20,
w500, with `FontFeature.tabularFigures()`, and use it for every score, tally,
countdown and Naira amount. Columns of figures that do not align are the single
most common way a results screen looks broken.

**Elevation is zero.** The mobile system rejects shadows outright — depth is a
1px `#c3c6cf` outline on white over a `#fcf9f8` ground, and tonal stacking for
nested rows. Set `elevation: 0` on the card, app-bar, dialog, bottom-sheet and
FAB themes globally. The one shadow allowed by the *web* system does not carry
over.

**Avatars.** The generated screens use photographic student headshots from
remote URLs. In the app, default to **initials on a tonal circle**. A photo
renders only when the school has actually uploaded one, and never on a list
that a data-saver user is scrolling. This is data cost and §B6 both.

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

| # | Stage | Contents | State |
|---|---|---|---|
| 0 | Skeleton | `flutter create`, layout, theme per §A4.1, router, dio client + interceptors, SQLite schema. | done |
| 1 | Auth & tenancy | School-code entry, login, 2FA step, secure token, role-based redirect, session expiry. | done |
| 2 | Shell & dashboards | Bottom nav per role, four dashboards from `/analytics/*`, profile, sign-out, offline-queue screen. | done |
| 3 | Attendance | Teacher register (bulk, idempotent, offline-queued), staff GPS clock-in. | done |
| 4 | Timetable & academics | `/timetable/view` with cache, roster, admin academics hub, result release, PIN inventory. | done |
| 5 | Homework | Teacher set/grade, student submit, parent visibility. | **blocked on G5** — no student-facing homework list exists |
| 6 | Results | Student/parent results, release gate, PIN redeem, purchase. | done |
| 7 | CBT | Available exams, sitting a paper (timer, image/LaTeX rendering, local answer buffer, question navigator), submit. | done |
| 8 | Finance | Statement, defaulters, payment history, hosted-checkout payment. | done |
| 9 | Communication & AI | Threads, messaging, notification inbox, student tutor chat. | done except the client half of push (backend transport is live — §B10) and the per-user inbox (**G6**) |
| 10 | Hardening | Offline soak on a real handset, low-end profiling, accessibility pass, crash reporting, release signing, store listing. | not started |

Stage 10 is the remaining work, plus whatever the gap fixes unblock. QR
attendance scanning is scaffolded (`mobile_scanner` is a dependency and the
permission is declared) but the scanner screen itself is not built — the roll
call covers the same job without it, so it was not the best use of the time.

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

**Rate limits the UI must respect:** login is 5/min per email+IP,
forgot-password is 3/min per address and 10/min per IP, PIN redeem is 10/min,
everything else shares 60/min. On `429` the app shows a plain "too many
attempts, try again shortly" and backs off — it does not retry automatically.

**Sign-out.** `POST /api/v1/auth/logout` to revoke this device's token, delete
the FCM token via `DELETE /api/v1/notifications/devices`, then wipe secure
storage and the local cache. The logout call is best-effort: a handset with no
signal must still be able to sign out locally. The 401 path skips it, because
the token it would present is the one the server just rejected.

**Forgot password.** `POST /api/v1/auth/forgot-password` with an `email`. It
always returns 200 with the same message, so the app must not branch on the
answer or claim the address was found — that response is what stops the screen
being used to discover who holds an account. The link it sends opens the web
portal at `FRONTEND_URL/reset-password`; the app handles no deep links, so the
user sets the password there and comes back to sign in.

## B3. Roles and navigation

Five tabs maximum, because a fifth is already the practical limit on a 5-inch
screen.

**Admin** — Home · Students · Fees · Academics · More
Home is `/analytics/principal`. Students covers the roster, records and user
management. Fees covers invoices, defaulters and payments. Academics covers
results, release, timetable and CBT oversight. More holds staff, calendar,
notifications, result PINs and settings.

> The generated designs use **Home · Fees · Students · Staff · More**, which
> puts Staff in the nav and leaves results, timetable and CBT with no
> destination at all — for a principal, results are not a "More" item. Adopting
> Academics in that slot and moving Staff under More is the one deliberate
> departure from the design set. It is a one-line router change if you disagree.

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

**Transport: FCM HTTP v1.** The backend authenticates with a Firebase service
account and posts to `/v1/projects/{id}/messages:send`. It previously used the
legacy `/fcm/send` server-key endpoint, which Google decommissioned in June
2024 — that was gap **G7**, and it is closed. Server side lives in
`backend/app/Services/FcmService.php`, covered by
`backend/tests/Feature/PushDeliveryTest.php`.

Register the FCM token on login and on token refresh via
`POST /notifications/devices` with `platform: android|ios`. Unregister on
sign-out so a shared handset stops receiving another family's alerts.

### Verifying your end without an admin

```
POST /api/v1/notifications/devices/test
{ "title": "optional", "body": "optional" }
```

Pushes to the caller's **own** registered devices, synchronously, and returns a
verdict per device. Any role may call it; throttled to 10/minute. Read the
status codes before reaching for a debugger:

| Code | Meaning |
|---|---|
| `200` | At least one device accepted it. `devices[].status` is `sent`. |
| `422` | You have not registered a token yet. |
| `502` | Every device failed. `devices[].reason` says why; a `status` of `invalid_token` means the token was deleted server-side and the app should re-register. |
| `503` | The **deployment** has no `FCM_CREDENTIALS`. Not your bug — an operator has to set it. |

### The payload you will receive

```json
{
  "notification": { "title": "…", "body": "…" },
  "data":         { "…": "string" },
  "android": { "priority": "high",
               "notification": { "channel_id": "schoolpilot_default", "sound": "default" } },
  "apns":    { "headers": { "apns-priority": "10" },
               "payload": { "aps": { "sound": "default", "content-available": 1 } } }
}
```

Three things the client must match:

1. **The Android channel id is `schoolpilot_default`.** Create exactly that
   channel via `flutter_local_notifications` at startup. Android 8+ silently
   discards a notification naming a channel that does not exist — no error, no
   log, nothing appears. It is configurable server-side as
   `FCM_ANDROID_CHANNEL_ID`, but do not change it unless both sides change.
2. **Every `data` value is a string.** FCM v1 rejects anything else, so the
   server coerces on the way out: `42` arrives as `"42"`, `true` as `"true"`,
   and any nested structure as a JSON string you must decode. Parse
   defensively; do not assume an int.
3. **`content-available: 1` on iOS** means the app is woken on receipt, so the
   background handler should refresh the inbox rather than waiting for a tap.

Deep-link payloads route to the relevant screen (invoice, result, homework,
message) off the `data` map.

### Token lifecycle

The server deletes a token the moment FCM says `UNREGISTERED`,
`SENDER_ID_MISMATCH`, or that `message.token` itself is malformed. So a
re-install, a "clear data", or a restored backup means the app is silently
unregistered and **must** re-register on next launch — `onTokenRefresh` alone
is not enough, because the token can be unchanged while the server row is gone.
Register on every cold start; the endpoint upserts, so it is cheap and safe.

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
| **G1** | ~~No `POST /auth/logout`.~~ **Done.** `POST /api/v1/auth/logout` deletes the token that made the request, leaving the user's other sessions alone. `AuthController::logout`. | — | — |
| **G2** | ~~No self-service password reset.~~ **Done.** `POST /api/v1/auth/forgot-password` and `POST /api/v1/auth/reset-password`, backed by `PasswordResetService` and Laravel's `password_reset_tokens` broker. | — | — |
| **G3** | `PUT /users/{id}/profile` is admin-only. | Students and parents cannot update their own phone number or photo. | Add a `PUT /me/profile` scoped to the caller. |
| **G4** | `GET /cbt/exams/{examId}/offline-package` is staff-only. | A student device cannot pre-download a paper. Fine if offline CBT is desktop-only (§A1) — a blocker the moment mobile offline sitting is wanted. | Decide explicitly; if wanted, add a candidate-scoped variant behind `CbtAttemptPolicy`. |
| **G5** | No student- or parent-facing homework list. Only `POST /academics/homework` (teacher), the teacher's submissions index, and the student's own single-submission endpoint. | A student cannot discover what homework exists — only open one they already have the id for. | Add `GET /academics/homework` scoped to the caller's class/child. |
| **G6** | `GET /notifications/history` is `role:super_admin,school_admin`. | No per-user notification inbox. A parent can receive a push but cannot see what they were sent. | Add `GET /me/notifications`, paginated, own rows only. |
| ~~**G7**~~ | ~~`NotificationService::push()` posts to `https://fcm.googleapis.com/fcm/send` with a `server_key`. That is the **legacy FCM API, decommissioned by Google**.~~ | ~~Push does not work at all, regardless of client code.~~ | **Fixed.** Migrated to FCM HTTP v1 in `FcmService`. Operators set `FCM_CREDENTIALS`; clients verify with `POST /notifications/devices/test`. Contract in §B10. |
| ~~**G8**~~ | ~~No endpoint exposes a school's gateway **public** key, and `School` stores none.~~ | ~~The app has no way to learn which key to open checkout with.~~ | **Fixed.** Each school now holds its own merchant account in `school_payment_gateways` (keys encrypted at rest, write-only across the API), set through `PUT /finance/gateways/{gateway}`. Checkout is opened **server-side**: `POST /finance/payments/initialize` and `POST /result-checker/purchase` return a hosted `authorization_url`, so no key of any kind reaches the client. Contract in §B14. |
| ~~**G9**~~ | ~~`POST /finance/payments` requires a client-supplied unique `reference`.~~ | ~~A retry with a fresh reference creates a duplicate pending row.~~ | **Fixed.** The server mints every reference. `reference` is now optional and honoured only from an admin recording a manual payment, where it carries a real bank slip number. |
| **G10** | No paginated envelope on several list endpoints. | Roster and history screens can pull the whole table on a metered connection. | Confirm per endpoint during Stage 2; add cursor pagination where missing. |
| ~~**G11**~~ | ~~Nothing lists a guardian's own children.~~ | ~~The parent role cannot function at all.~~ | **Fixed.** `GET /api/v1/parent/children` — the path the app already calls. Scoped by the `student_guardian` pivot rather than by school membership, and it returns name, class, arm and admission number only; the four encrypted health columns never appear (NDPA §12). |
| ~~**G12**~~ | ~~No `GET /classes` and no `GET /terms`.~~ | ~~Every staff screen needs a class and a term before it can ask the server anything.~~ | **Fixed.** `GET /api/v1/classes` (teaching order, arms, active roll) and `GET /api/v1/terms`. See the note on `is_current` below. |

**Handling.** None of these were worked around in the client. Where a gap blocks
a control, the app shows what to do instead of offering a button that cannot
work, and the code says which gap it is waiting on. What is left is **G3, G4,
G5, G6 and G10** — each blocks a single control or screen rather than a whole
role. G1, G2, G7, G8, G9, G11 and G12 are done.

**On G12 and `terms.is_current`.** The column exists and nothing in the product
ever writes it — no route, no job, no admin screen sets it — so on a real
school's data it is `false` on every row, and a picker that trusted it would
open with no default at all. `GET /terms` therefore *computes* `is_current`:
the term today falls inside; failing that whatever an admin has pinned, since a
hand-set flag is still a deliberate statement; failing that the term that most
recently started. The last case is the Nigerian long vacation, roughly July to
September, when today is inside no term and the school is finishing results for
the term that just ended rather than preparing one that has not begun.
`current_term_id` is lifted out of the array so no two clients can disagree
about what happens when nothing matches.

**On G2 and credential delivery.** Fixing self-service reset also removed the
last three places a working credential came back over the API — `POST /students`
returned `temp_password`, `POST /users/{id}/reset-password` returned
`temporary_password`, and both bulk importers created accounts with an
8-hex-character password nobody could receive. Account creation, admin reset and
invite now all send the same single-use expiring link through
`PasswordResetService`. The link is recorded in `notification_logs` as a
description rather than verbatim: a school admin can read
`GET /notifications/history`, and a stored link would be an account takeover.

Delivery is email (`MAIL_MAILER` — a deployment left on the default `log`
swallows every link) plus optional SMS behind `SMS_PASSWORD_LINKS`, off by
default because a reset link costs two billed segments. Links point at
`FRONTEND_URL`, where `{subdomain}` is substituted per school.

## B13. Design set — coverage and known defects

The generated screens live in
`docs/stitch_schoolpilot_mobile_ui_design/stitch_schoolpilot_mobile_ui_design/`,
one folder per screen with `code.html` and `screen.png`. 35 screens plus the
design system. They are a **visual specification, not a source to port** — the
HTML is Tailwind-on-CDN mockup markup with remote image URLs.

**Coverage.** Every screen in the Stitch prompt was generated. Enough to build
Stages 0–9 without further design work, with the exceptions below.

**Defects to fix before the screen is built.** Each is a real problem in the
output, not a stylistic preference:

| Screen | Defect | Resolution |
|---|---|---|
| `gradebook_score_entry` | The table overflows the 360dp viewport: `Exam` is clipped mid-word and **`Total` is off-screen entirely**. The whole point of the compact 4px density was to fit CA1/CA2/CA3/Exam/Total without horizontal scroll. Also renders only 3 rows over a half-empty screen. | Regenerate. In Flutter, pin the name column, make the four score columns flex, and keep Total visible at all costs — it is the column teachers check. |
| `class_register_offline_state`, `teacher_attendance_register` | The P/A/L/E segmented control is `h-[36px]`, below the 48dp minimum, and renders as an unusable hairline strip with ~2px glyphs. | Build at 48dp with a full-width control beneath the student name. The rest of the screen — offline banner, tallies, sticky submit with "31 of 34 marked" — is right and should be kept. |
| `cbt_exam_question_view` | LaTeX is printed as **raw source** (`\(\frac{a}{\sin \alpha}\)`). Stitch has no math renderer. | Cosmetic in the mockup only — `flutter_math_fork` renders it properly. Do not copy the escaping. |
| `cbt_exam_question_view` | The geometry diagram is a screenshot **of a phone screenshot** — an Android status bar, clock and nav buttons are baked into the image asset. | Replace the asset. Real diagrams come from the CBT media library via `CbtMediaService`. |
| `notifications` | Shows **`$1,250`**. A dollar amount, in a Naira product. | Hard rule violation. Regenerate; verify with the §4 checklist. |
| `notifications`, `result_checker_pin_gate` | "Term 2" instead of "Second Term". | Nigerian term naming, no exceptions. |
| `gradebook_score_entry` | Session reads `2023/2024`; other screens use `2025/2026`. | One session across the set. |
| 6 screens | Product name drifts to **SchoolPath** and **SchoolNexus** (`ai_tutor_chat`, `student_home_dashboard`, `student_results`, `parent_dashboard`, `attendance_behaviour`, `result_checker_pin_gate`). | Cosmetic — the app bar carries the school name, not the product name. Ignore, do not propagate. |
| `class_register_offline_state`, `gradebook_score_entry` | Teacher bottom nav drifts to `Students` / `Staff`. Three other teacher screens have it right. | Use the canonical set in §B3. |
| `school_admin_dashboard` | Duplicate of `principal_dashboard` for a different school, with no bottom nav. | Redundant. Build from `principal_dashboard`. |
| — | **Missing:** the student-side "results not yet released" locked variant, and the CBT question-navigator grid sheet. Both were requested; neither came back. | Both are built in code without a mock — `ResultsScreen._NotReleased` and the navigator sheet in `cbt_attempt_screen.dart`. A 40-question paper is unusable without the navigator. |

**What came out well and should be followed closely:** `ai_comment_review` —
the amber left-bordered draft block, the "Pending Approval" sparkle chip and the
Edit/Approve pair make the approval gate unmistakable, which is exactly what the
hard rule needs. Also `parent_dashboard` (the child switcher and the red-bordered
fee card), `student_results`, and the offline banner treatment on the register.

**Audit result on the content rules:** no GPA, semester, credit or faculty
language anywhere in the set; no biometric, card-reader or barcode-scanner
iconography anywhere; names and schools are Nigerian throughout. The only
currency violation is the one row in `notifications`.

## B14. Online payment — the client contract

Closes G8 and G9. Every school holds its own merchant account, and the client
holds nothing.

**What the school does, once.** A school admin — not a SchoolPilot operator, the
route is `role:school_admin` with no super_admin fallback — pastes the keys from
its own Paystack or Flutterwave dashboard into
`PUT /api/v1/finance/gateways/{gateway}`, then copies the `webhook_url` that
comes back into that same dashboard. Keys are stored encrypted in
`school_payment_gateways` and are write-only across the API: `GET
/finance/gateways` returns `••••` plus the last four characters, never the key.
Flutterwave additionally requires the secret hash from its dashboard, because
without it not one callback from that account can be verified.

**What the app does, per payment.** Implemented in
`mobile/lib/features/finance/gateway_checkout.dart`, used by the fees screen and
the result-checker PIN gate.

1. `POST /api/v1/finance/payments/initialize` with `invoice_id` — and, in the
   normal case, nothing else. **Send no `gateway`:** a parent has no idea which
   merchant account their school holds, and `GET /finance/gateways` is
   school_admin only, so the server picks the one the school connected. **Send
   no `amount`** unless the payer is deliberately part-paying; the default is
   the balance the server computed, which is what stops a tampered client
   paying ₦1 against a ₦45,000 bill. For a result,
   `POST /api/v1/result-checker/purchase` takes `student_id` and `term_id` and
   behaves identically.
2. Open the returned `authorization_url` in a webview. That is the entire
   client-side gateway integration: no key to hold, no SDK, no card field of
   ours, no PCI surface.
3. Close the webview on `/api/v1/payments/return` — **that URL specifically,
   never "a host that isn't the gateway's"**. A card that asks for 3-D Secure
   routes the payer through their own bank's domain mid-payment, so host
   sniffing abandons checkout exactly when the parent is verifying it. The
   return page is served by the backend, is unauthenticated, and deliberately
   claims nothing about the outcome.
4. Refresh the statement — on abandon as well as on completion, because a payer
   can finish a transfer and then background the app rather than wait for the
   redirect. Say *confirming*, never *paid*: settlement is a signed webhook the
   app never sees, and **nothing the app does can mark a payment successful.**

**Failure states worth rendering.** `409` — the school takes fees another way,
so this is not an error the parent can fix; show the server's message and point
at the office or bank transfer rather than an error dialog. `403` — not this
caller's child. `502` — the school's gateway refused; the message is safe to
show, it comes from the gateway itself.

**Why the server picks the reference.** A client that mints its own retries a
dropped connection with a fresh one and creates a second pending row against the
same money. References are now `SPFP_…` (fees), `SPRP_…` (a result PIN),
`SPMP_…` (a manual payment an admin recorded) and `SPRB_…` (a school buying PIN
stock from SchoolPilot — the one flow that still settles on the platform
account). `POST /finance/payments` still accepts a `reference`, but only from an
admin recording a manual payment, where it is a real bank slip number.

## B15. Related documents

- Product spec: `docs/SchoolPilot-Comprehensive-Documentation.md` (§3 hardware,
  §7.16 mobile, §11 architecture, §12 NDPA)
- **Mobile design set (35 screens + tokens):**
  `docs/stitch_schoolpilot_mobile_ui_design/stitch_schoolpilot_mobile_ui_design/`
  — system in `high_contrast_functionalism/DESIGN.md`
- Web UI reference, for cross-client consistency:
  `stitch_schoolpilot_management_system/`
- Stitch prompts and regeneration prompts: `docs/mobile-stitch-prompt.md`
- CBT content rules: `docs/cbt-authoring-guide.md`
- Report-card rendering: `docs/report-card-template-contract.md`
