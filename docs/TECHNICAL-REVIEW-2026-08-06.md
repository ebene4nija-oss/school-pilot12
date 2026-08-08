# SchoolPilot — Technical Review

**Date:** 2026-08-06
**Reviewed:** `backend/` (deep), `web/`, `mobile/`, spec alignment against `docs/SchoolPilot-Comprehensive-Documentation.md`
**Branch at time of review:** `master` @ `9a16257`

---

## STATUS — updated 2026-08-06, after the security pass

The §7 "Now" block is **done and test-backed**. Suite: **48 passing** (28 pre-existing
+ 20 new in `backend/tests/Feature/SecurityHardeningTest.php`), no regressions.

| Item | Status |
|---|---|
| §2.1 Parent/student IDOR | Fixed — `StudentPolicy` + `authorize()` on feed, analytics, portfolio |
| §2.2 Forgeable QR token | Fixed — `QrAttendanceTokenService`, HMAC-signed, server-side claims, single-use |
| §2.3 GPS verifies nothing | Fixed — `GeofenceService` haversine check, fails closed, logs distance |
| §2.4 Webhook secret fallback | Fixed — `config()` with no fallback, `hash_equals`, own throttle bucket |
| §2.5 2FA bypass | Fixed — fails closed on missing secret; backdoor removed |
| §2.6 Login not tenant-bound | Fixed — host-resolved tenant enforced; super-admin exempt |
| §2.7 Tenant scope holes | **Partly done** — see the note in §2.7 below. Not finished. |
| §2.8 Login brute force | Fixed — 5/min per email+IP limiter |
| §2.9 Invite returns password | Fixed — removed from response |
| §3 Dead Claude model IDs | Fixed — plus explicit stub mode, timeouts, retries |

**Two bugs found while fixing, not in the original review:**

1. `TotpService::verifyCode()` was declared `: boolean` — not a valid PHP type, so it
   would have fatalled the first time 2FA was actually exercised. It never was, because
   no test enabled 2FA. Now `: bool`.
2. The same method had a hardcoded backdoor: `env('APP_ENV') === 'testing' && $code === '123456'`
   returned true for any secret. Removed — nothing depended on it.

**One finding that invalidates an assumption in the original review:** see §8.1.

## 1. Where the project actually stands

| Layer | State |
|---|---|
| Backend | Substantial — 19 controllers, 38 models, 19 migrations, 28 tests passing (107 assertions) |
| Web admin | 20 files, 13 page shells. **No auth at all** — no login page, no token storage, no `Authorization` header |
| Mobile | **0 files.** Directory is empty |
| Queues/Jobs | Redis is in the stack; `app/Jobs`, `app/Events`, `app/Policies` don't exist. Everything runs inline |

The backend breadth is real and `php artisan test` genuinely passes 28/28. But the tests cover happy paths — they don't probe auth boundaries, and that's where the problems are. **The current suite would pass with every security bug in section 2 still present.** That's the more useful finding than the pass rate.

---

## 2. Blockers before any school touches this

Ranked by what happens if you go live tomorrow.

### 2.1 Any parent can read any student's record — IDOR

- `backend/app/Http/Controllers/Api/V1/ParentPortalController.php` → `getStudentFeed`
- `backend/app/Http/Controllers/Api/V1/AnalyticsController.php` → `getParentDashboard`
- Also `/students/{studentId}/portfolio` in `UserManagementController.php`

Both scope by `school_id` only and never check the guardian link:

```php
$student = Student::where('school_id', $schoolId)->with(['user'])->findOrFail($studentId);
```

A parent changes the ID in the URL and gets another child's grades, attendance, behaviour reports, and pickup authorizations. Against doc §12 this is an NDPA incident, not just a bug.

**Fix:** Laravel Policies doing object-level checks (the `app/Policies` directory doesn't exist yet). Role middleware on routes is not authorization — it answers "is this a parent?" not "is this *that child's* parent?"

### 2.2 The QR attendance token is forgeable

`backend/app/Http/Controllers/Api/V1/AttendanceController.php:38`

```php
$token = base64_encode(json_encode($tokenPayload));   // no signature
```

Anyone can mint one with any `school_id` and a far-future `expires_at`. Worse, every verification in `markStudentAttendance` is guarded by `isset(...)`, so **omitting a field skips the check entirely**:

```php
if (isset($decoded['school_id']) && $decoded['school_id'] != $schoolId) { ... }
```

**Fix:** either `hash_hmac` the payload with `APP_KEY` and verify with `hash_equals`, or (better) store the nonce in Redis with a 15-min TTL and treat the QR as an opaque lookup key — that also makes it single-use. Drop the `isset()` guards; a missing field must fail.

### 2.3 GPS clock-in verifies nothing

`AttendanceController.php` → `staffGpsClockIn` accepts any lat/long and writes `status: 'present'`. Doc §3 makes phone GPS the replacement for the biometric reader, so this is the feature carrying that weight — and it currently records a self-declaration.

**Fix:** haversine check against the school's stored coordinates with a configurable radius; persist the computed distance so admins can review outliers rather than just trusting a boolean.

### 2.4 Payment webhook secrets fall back to a value committed to git

`backend/app/Http/Controllers/Api/V1/FinanceController.php:119` and `:136`

```php
$secret = env('PAYSTACK_SECRET_KEY', 'sk_test_mock_secret');
$secretHash = env('FLUTTERWAVE_SECRET_HASH', 'mock_flw_secret_hash');
```

`env()` called outside config files returns the **default** once `php artisan config:cache` has run — a standard deploy step. Anyone reading this repo can then forge `charge.success` and mark fees paid.

**Fix:**
- Move to `config('services.paystack.secret')` and **remove the fallback** so a missing key fails loudly.
- Use `hash_equals()` instead of `!==` (timing).
- Add an idempotency/event log keyed on the gateway event ID — replay protection currently relies only on the `status !== 'successful'` check.
- Exclude `/webhooks/{gateway}` from `throttle:60,1` — gateway retries will get 429'd during peak fee season.

### 2.5 2FA can be bypassed

`backend/app/Http/Controllers/Api/V1/AuthController.php:55`

```php
$secret = $profile->two_factor_secret ?? 'JBSWY3DPEHPK3PXP'; // Fallback secret
```

That is the well-known RFC 6238 test secret. Any admin with `two_factor_enabled = true` but a null secret is open to anyone who generates codes from that public string. **Fix:** fail closed — no secret means 2FA cannot be satisfied.

### 2.6 Login isn't tenant-bound

`AuthController::login` validates `subdomain` as `nullable`, and `backend/app/Http/Middleware/TenantResolutionMiddleware.php` resolves the school into a request attribute that **nothing ever reads**. A user from School A authenticates fine at School B's subdomain.

Their data stays scoped by their own profile, so it isn't a data leak — but the tenant boundary is currently decorative, which shouldn't stand in a multi-tenant product.

**Fix:** when the middleware resolves a tenant, require that the authenticating user belongs to it. Bind the resolved tenant into the container so downstream code reads one authoritative value.

### 2.7 Tenant scoping has holes outside the request cycle

`backend/app/Models/BelongsToTenant.php:14`

```php
if (Auth::check() && Auth::user()->userProfile && Auth::user()->userProfile->role !== 'super_admin') {
    $builder->where('school_id', Auth::user()->userProfile->school_id);
}
```

Three problems:
1. `super_admin` gets **no scope on any query** — every listing spans all tenants.
2. Anything without `Auth` also gets no scope: the unauthenticated webhook, any future queued job, artisan commands like `GenerateInsightsCommand`.
3. `Auth::user()->userProfile` fires a lookup on **every model boot** — an N+1 baked into the ORM layer.

**Fix:** resolve tenant once in middleware into a container singleton; the global scope reads that. Handle super-admin as an explicit opt-in (`->allTenants()`), not an absence of scoping.

> **STATUS: deliberately not finished.** The login path is now tenant-bound (§2.6) and
> cross-tenant reads are blocked at the object level by `StudentPolicy` (§2.1), which
> covers the exploitable paths. The `BelongsToTenant` refactor itself is **still
> outstanding** — it touches the global scope on all 38 models, and the webhook
> legitimately needs an unscoped `Payment::where('reference', ...)` lookup, so doing it
> safely means auditing every unauthenticated code path first. That is its own piece of
> work, not a line item in a security pass; attempting it here risked breaking the 28
> passing tests for no security gain beyond what the policy already provides. The N+1
> (`Auth::user()->userProfile` on every model boot) is also still there.

### 2.8 Brute force is wide open

`backend/routes/api.php:13` — a single `throttle:60,1` covers everything including `/auth/login`. 60 password attempts per minute per IP.

**Fix:** `throttle:5,1` on login keyed on email+IP; keep the general bucket for the rest.

### 2.9 Minor

- `AuthController::inviteUser` returns the temp password in the JSON response, with no expiry and no forced reset on first login.
- Controllers return raw Eloquent models — the encrypted medical fields on `backend/app/Models/Student.php:37` (`blood_group`, `allergies`, `medical_notes`, `emergency_contacts`) decrypt into any response that touches a Student. API Resources would fix this and the payload-size issue in §4 at the same time.

---

## 3. The AI features are currently broken in production

Both model IDs in `backend/app/Services/ClaudeService.php` are dead:

| In code | Status | Replace with |
|---|---|---|
| `claude-3-sonnet-20240229` | Retired Jul 2025 — **404s** | `claude-sonnet-5` ($3/$15 per MTok; intro $2/$10 through 2026-08-31) |
| `claude-3-haiku-20240307` | Retired 2026-04-19 — **404s as of today** | `claude-haiku-4-5` ($1/$5 per MTok) |

Haiku 4.5 for report-card comments (high volume, short output); Sonnet 5 for lesson plans and worksheets.

Three things in that file matter more than the IDs:

**`sk-ant-mock-key` fallback is dangerous, not safe.** With a misconfigured production env, `generateReportCardComment` silently returns a canned sentence with the real student's name and score interpolated:

```php
return "{$scoreData['student_name']} has demonstrated commendable effort in {$scoreData['subject']} this term, scoring {$scoreData['total_score']}%...";
```

It looks like a genuine remark. A teacher approves it. That reaches a report card. Given the hard rule that AI comments never go out unreviewed, this mock path shouldn't exist outside tests — throw instead.

**No HTTP timeout.** On Nigerian connectivity a hung Anthropic call ties up a PHP-FPM worker indefinitely. Add `->timeout(30)->retry(2, 500)`.

**Cache keys aren't tenant-scoped.** `md5(json_encode($scoreData))` is global, so two schools with a same-named student at the same score share a comment.

---

## 4. Real-life usage: what breaks on contact with an actual school

This is where most of the remaining value is — §2 is bounded work, these are design-level.

### 4.1 Attendance is one HTTP request per student

A JSS2 teacher with 42 students on a 3G phone makes 42 sequential calls, each needing the QR token, each able to fail halfway. This is the single most-used endpoint in the product and it's shaped for a demo.

**Need:** `POST /api/v1/attendance/bulk` taking an array, idempotent (server-side idempotency key), returning per-row results — plus client-side local queuing.

### 4.2 Nothing is queued

No `app/Jobs` at all, despite Redis being in the stack per CLAUDE.md. Running **inline in the request** right now: Claude API calls, report-card PDF generation, bulk CSV import, SMS and WhatsApp sends.

Nigerian private schools run on shared hosting with 30–60s PHP timeouts. On the day the whole school's report cards are generated, this falls over. All of it belongs in queued jobs with a status endpoint the UI polls.

### 4.3 Pagination is mostly absent

4 of 38 list queries paginate. A 1,200-student school's broadsheet or user list will hit memory limits. `getStudentFeed` pulls **all** behaviour reports ever, unbounded — and it's the parent app's home screen, so it'll be the most-hit endpoint you have.

### 4.4 N+1 queries throughout

Zero eager loading in `AccountingController`, `AttendanceController`, `SubjectManagementController`, `TimetableController`, `Phase1And2Controller`, `CbtController`.

**Quick win:** add `Model::preventLazyLoading()` in local/testing — the existing tests will then point at every site.

### 4.5 Data cost is a feature requirement here

Parents pay per MB. Broadsheet and feed payloads should be field-limited via API Resources (there are none). Enable gzip; add ETags on read-heavy endpoints like timetable view.

### 4.6 Term-end concurrency

Result compilation, broadsheet generation, and fee reconciliation all spike in the same week. No locking, no queue, no batch endpoints. Load-test with realistic numbers — 800 students × 12 subjects × 3 CAs — before a pilot.

### 4.7 No offline story despite the spec demanding one

Doc §225 is explicit that the Flutter app needs local-first attendance and timetable reads, not just the CBT client. Only `/cbt/offline-sync` exists today. This must be designed into the mobile app from the first commit — it does not retrofit.

### 4.8 Validation and authorization are thin

1 FormRequest class (`StoreScoreEntryRequest`) across ~19 controllers; validation is inline `Validator::make` everywhere. No Policies. 7 `DB::transaction` calls total across the whole app — worth auditing which multi-write operations (payment + invoice update, promotion + history, payroll) are currently non-atomic.

### 4.9 Credit where due

Bank-transfer reconciliation and cash payment recording are both implemented. That shows the finance module was designed for how Nigerian schools actually pay rather than assuming card-on-file. Encrypted medical fields on `Student` are right too.

---

## 5. The web app needs to be re-approached

No login page, no token storage, no `Authorization` header anywhere in `web/src`. Every page calls `auth:sanctum`-protected endpoints, so in a browser they all 401 — the pages render skeletons and swallow the error in `console.error`.

Also `web/src/lib/api.ts:7` sets a `Host` header:

```ts
'Host': 'greenfield.schoolpilot.test',
```

Browsers forbid `fetch` from setting `Host` — it's silently dropped, so tenant resolution never happens client-side. Pass the subdomain as a header you control (`X-School-Subdomain`) or derive it from `window.location`.

**Before adding any more pages:** login → token (httpOnly cookie or memory, not localStorage) → auth interceptor → role-gated routing.

⚠️ `web/AGENTS.md` states this Next.js version has breaking changes and to read `node_modules/next/dist/docs/` before writing code. No web code was written during this review — whoever picks it up should start there.

---

## 6. Mobile app — how to approach it

Greenfield, so sequencing matters more than code.

1. **Fix the API contract first.** Bulk attendance, pagination, API Resources, and object-level authorization all change response shapes. Mobile apps are installed software — you cannot iterate the contract after release without breaking users (doc §226).
2. **Local-first from commit one.** Drift/SQLite as the source of truth for the UI; a sync queue with `pending`/`synced`/`conflict` states; server-side idempotency keys so a retried attendance mark doesn't double-write.
3. **Build three daily-use flows only, deeply:** teacher marks attendance · teacher enters CA scores · parent views feed and pays fees. Those decide whether a school renews. The other 20 modules are web-admin work.
4. **Two device integrations:** camera QR and GPS — both meaningless until §2.2 and §2.3 are fixed server-side.
5. **Defer:** AI tutor chat, gamification, bus tracking. Demo well, used rarely.

**Open question to confirm before scoping:** the spec's offline CBT client (§7.16, §131) is a *desktop* app for school lab computers, not part of the Flutter codebase. Verify that's still the plan.

---

## 7. Suggested order

**Now — security, ~2–3 days.** Write a failing test for each *first*, then fix:
- [ ] Object-level Policies for parent/student/portfolio access (§2.1)
- [ ] Sign or server-store the QR token; remove `isset()` guards (§2.2)
- [ ] Geofence GPS clock-in (§2.3)
- [ ] Webhook secrets → `config()`, no fallback, `hash_equals`, idempotency log, exempt from throttle (§2.4)
- [ ] Fail-closed 2FA (§2.5)
- [ ] Tenant-bind login; consume the resolved tenant (§2.6, §2.7)
- [ ] Login-specific rate limit (§2.8)

**Next — survive a real school.**
- [ ] Bulk attendance endpoint
- [ ] Queue AI / PDF / SMS / bulk-import jobs
- [ ] Pagination + API Resources everywhere
- [ ] Eager loading (`preventLazyLoading` in dev)
- [ ] Current Claude model IDs + timeouts; delete the mock-key path (§3)

**Then.**
- [ ] Web auth + tenant header
- [ ] Mobile, on a frozen API contract

---

## 8. Verification notes

### 8.1 The existing suite never exercised tenant resolution

Ten test files pass a host like this:

```php
$this->withServerVariables(['HTTP_HOST' => 'testschool.localhost'])->postJson('/api/v1/students', ...)
```

**That server variable is silently discarded.** Laravel's `prepareUrlForRequest()`
prepends `config('app.url')` to a relative URI, and Symfony's `Request::create()` then
overwrites `HTTP_HOST` from that URL. Proven by probe: a request to `/api/v1/health`
with `HTTP_HOST: nosuchschool.localhost` returns **200**, when `TenantResolutionMiddleware`
should 404 an unknown subdomain. With an absolute URL (`http://nosuchschool.localhost/api/v1/health`)
it correctly returns 404.

Consequences:

- `TenantResolutionMiddleware` has **never** run its resolution branch under test. Its
  404-on-unknown-subdomain path and the whole host→tenant mechanism were untested.
- Tests named for tenant scoping (e.g. `promote is school scoped`) are really exercising
  the `BelongsToTenant` global scope via the authenticated user's profile, not
  host-based tenancy. They are still valid tests — just not tests of what the name implies.

`SecurityHardeningTest` uses absolute URLs throughout and does exercise it.
**Follow-up:** convert the other ten test files to absolute URLs. Expect some to fail
once tenant resolution actually engages — that would be a real finding, not a test bug.

### 8.2 Original review verification

- `php artisan test` → 28 passed, 107 assertions, 33.8s. Genuinely green.
- Model retirement dates and replacement IDs/pricing confirmed against the bundled `claude-api` skill reference (cached 2026-06-24), not from memory.
- `mobile/` confirmed empty (`find mobile -type f | wc -l` → 0).
- Web auth absence confirmed by grep for `token|localStorage|Authorization|login` across `web/src` — only match is unrelated UI copy.
