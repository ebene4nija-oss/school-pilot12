# SchoolPilot Operator Console

The screens and API a **platform operator** (role `super_admin`) uses to run SchoolPilot across every school. For what a *school's* own administrator sees, see the School Admin sections of `SchoolPilot-Comprehensive-Documentation.md`.

Spec reference: doc §5 — *"Super Admin manages schools, subscriptions, platform-wide settings, AI service configuration, platform security, cross-school reporting."*

---

## 1. Status against the spec

| Spec area (§5) | State | Where |
|---|---|---|
| Schools | ✅ Built | Schools directory, School detail, Onboarding & migrations; `/platform/schools`, `/platform/school-migrations` |
| Platform-wide settings | ✅ Built | Platform settings; `/platform/settings` |
| AI service configuration | ✅ Built | AI service; per-school switch on School detail and the directory |
| Cross-school reporting | 🟡 Partial | Four headline tiles and today's AI spend. No trends, no exports. |
| Subscriptions | 🟡 Partial | Result PINs (rate card, school rates, offline grants, sales ledger) are built. §13 term plans are not: the band is only a label derived from the roll, and nothing records what a school is on or owes. |
| Platform security | 🟡 Partial | Security (admin 2FA, sessions, sign-out-everywhere) and Audit log are built. The operator IP allowlist is not. |

The full menu, and what is still to build, is in `super-admin-menu.md`.

---

## 2. Signing in

- Address: `https://admin.schoolpilot.org.ng/login`, school code `admin`.
- The `admin` subdomain is a row in `schools` flagged `is_platform`. It exists only so the operator has a host to sign in through — every login resolves a tenant from the host. It is **not a school**: it is excluded from the directory and from every count, cannot be suspended, and has no AI switch.
- After sign-in an operator lands on the console. `/` (a school's dashboard) redirects back to the console unless a school has been opened (see §4).

---

## 3. Console — `/super-admin`

### Headline tiles

| Tile | Meaning | Source |
|---|---|---|
| Schools | Customer schools, split active / trial / suspended. Excludes the platform row. | `GET /platform/overview` → `schools` |
| Students across all schools | Active students, with total records including leavers. | `students` |
| School fees collected (all schools) | Money schools have collected from parents, against what they billed. **Not SchoolPilot's revenue.** | `school_fee_flow` |
| AI spend this month | Anthropic cost across all schools since the 1st, in Naira. | `ai_spend_this_month` |

### Schools directory

Columns: name and email, subdomain, active students, plan band (derived from the roll per §13 — not a stored plan), fees collected, AI spend this month, status.

| Action | Effect |
|---|---|
| **New school** | Opens the provisioning form (below). The one amber button on the screen. |
| **Open** | Asks for a reason, then opens the school for a limited time (§4). Every school-side page then answers for it. The sidebar shows *Viewing as operator · School name* with **Leave this school**. |
| **Suspend / Reinstate** | Blocks or restores the school's access. Nothing is deleted — roll, results and payment history are kept. Audited. |
| **Trial end** | Shown under a trial school's status, in red once it has passed. Nothing suspends a school when its trial ends; the console only flags it. |
| **Turn AI on / off** | Switches every AI feature for that school. The daily cap limits spend; this stops it outright. Audited. |

### New school

Creates the school **and its first administrator** in one transaction — a school nobody can sign in to is a row, not a tenant.

| Field | Rules |
|---|---|
| School name | Required. |
| School code (subdomain) | Suggested from the name. Lowercase letters, digits and hyphens; cannot start or end with a hyphen; 2–40 characters; unique. Reserved: `admin`, `www`, `api`, `app`, `mail`, `ftp`, `cpanel`, `webmail`, `localhost`. |
| School email | Required. |
| Phone | Optional. |
| Administrator's name and email | Required. The email must not already belong to a user. |
| Status | Trial (default) or Active. A trial ends after `SCHOOL_TRIAL_DAYS` (default 30); the date can be changed on School detail. |

On success the console shows the sign-in address, school code, administrator and a **temporary password shown once** — it cannot be retrieved afterwards. A setup link is also emailed if mail is configured; the panel says whether it was.

### School detail — `/super-admin/schools/{id}`

Reached by clicking a school's name. Everything needed to answer a support call without opening the school:

- Tiles: active students, school fees collected and outstanding (the school's money, not SchoolPilot's), AI spend this month, status.
- Profile, AI switch, account counts by role, and a link to that school's administrators on Security.
- **Internal support notes.** Operator-only: calls, promises, special arrangements. The school never sees them; no school-side route reads `school_support_notes`.
- **Result PIN purchases**: what the school has paid SchoolPilot.
- **Recent activity**: the last ten audit rows for the school, with a link to the full audit log filtered to it.
- **Trial** (trial schools only): change the end date, or **Convert to paying**, which makes the school active and clears the date.
- **Operator access**: the last ten times someone at SchoolPilot opened this school, with the reason given.
- Actions: Open school, Suspend (with a confirmation) or Reinstate (back to trial if a trial date remains, otherwise active).

### Onboarding & migrations — `/super-admin/migrations`

Open migration requests across schools, overdue first, each with a due-in or hours-overdue badge counted from the 24-hour promise. **Start**, **Mark complete** or **Cancel request**. *Notes to the school* are shown to the school, so they are not the place for internal remarks. Internal remarks go in School detail's support notes.

### Result PINs — `/super-admin/result-pins`

- **Issue PINs for an offline payment**: school, quantity, optional unit price (empty means the school's agreed rate, then the rate card), bank transfer or cash, and a required reference. The codes are shown **once**, as a CSV download.
- **Rate card**: add or reprice a band by order size; withdraw or restore it. Past batches keep the price they were bought at.
- **Agreed school rates**: a flat per-PIN price for one school, with a required reason; **Lift** returns the school to the rate card.
- **Batches**: every batch across schools (latest 200), with revenue and PINs-issued totals.

---

## 4. Working inside a school

Opening a school gives an operator that school's records, its pupils' included, so it is on the record.

1. **Open** asks for a reason (at least 10 characters). The web app calls `POST /platform/schools/{id}/access`, which creates a **grant**: operator, school, reason, IP, and an expiry `OPERATOR_ACCESS_MINUTES` from now (default 120). It is audited as `platform.school.opened`. Only after that does the app set the `sp_view_school` cookie.
2. The web proxy turns the cookie into an `X-Platform-School` header. The API honours it **only for a super admin holding a live grant for that school**, one that is neither expired nor closed. For any other role the header is ignored, so no school user can reach another school by setting it.
3. **Leave this school** closes the grant (`platform.school.closed`) and clears the cookie. Opening a different school closes the previous grant first; an operator has one school open at a time.

While inside a school:

- Every school-side page answers for that school, and **writes land in that school**.
- The console links stay in the sidebar, alongside the school's own screens.

**Without a live grant** (none opened, expired, closed, or for a different school), an operator reaches only `/platform/*`, `/user` and `/auth/*`. Every other route returns `403` with `code: "school_access_required"`. Before this, an operator with no school open could call school-side routes unscoped and read every school's records at once: `/students` listed every child on the platform. When a grant expires mid-session, `/user` reports no acting school, so the banner and the school's menu disappear and the dashboard returns to the console.

---

## 5. Platform settings — `/super-admin/settings` and AI service — `/super-admin/ai`

Integration keys and AI configuration for every school. Both screens edit the same registry: **AI service** shows the AI group and today's spend; **Platform settings** shows every other group. **Values saved here override the server's `backend.env`**; anything not set here keeps the server's value, so a deployment that never opens this screen behaves exactly as before.

### How values are handled

- **Secrets are write-only.** The screen can set, replace or remove a key and shows that one is set plus its last four characters (for the Firebase file, the service-account address). No response ever contains a key.
- **Everything is encrypted** with `APP_KEY` before storage, secrets and plain settings alike, so a database dump — which is what a backup is — does not carry keys in the clear.
- **Takes effect immediately.** Web and API requests read the new value on their next request; the queue worker is told to restart so SMS and push jobs pick it up too.
- **Audited.** Each save records *which* keys changed and who changed them, never the values.
- **If `APP_KEY` is ever rotated**, stored values become unreadable. The app does not fail: it falls back to `backend.env`, and the setting shows **Unreadable — re-enter**.

Each setting shows a source badge:

| Badge | Meaning |
|---|---|
| Set here | Saved in the console; overrides the server. |
| From server config | Not set here; `backend.env` provides it. |
| Not set | Neither provides it. |
| Unreadable — re-enter | Saved under a previous `APP_KEY`. |

### AI service

| Setting | Type | Config path | Notes |
|---|---|---|---|
| Anthropic API key | secret | `services.anthropic.api_key` | Without it every AI feature reports itself unavailable. |
| Model for short output | choice | `services.anthropic.comment_model` | Report-card remarks. `claude-haiku-4-5`, `claude-sonnet-5`. |
| Model for documents | choice | `services.anthropic.document_model` | Lesson plans, worksheets, exam drafts. `claude-sonnet-5`, `claude-opus-5-5`, `claude-haiku-4-5`. |
| Daily AI spend cap per school | Naira | `services.anthropic.daily_cap_kobo` | Entered in Naira, stored in kobo. Empty or 0 = no cap. At the cap a school's AI requests are refused until midnight. |
| Alert at this share of the cap | 0.1–1 | `services.anthropic.alert_threshold` | 0.8 warns the school at 80%. |
| US dollar to Naira rate | number | `services.anthropic.usd_to_ngn` | Conversion for recorded spend. Each record keeps its own rate. |

Below the AI settings, **Today's AI spend** lists each school's calls and cost today against the cap, and flags any school at the cap — the usual answer to "the AI stopped working for us".

### SMS

| Setting | Type | Config path |
|---|---|---|
| SMS provider API key (Termii) | secret | `services.sms.api_key` |
| SMS sender ID (≤ 11 characters, registered with the provider) | text | `services.sms.sender_id` |
| Send password links by SMS too | on/off | `services.sms.password_links` |

### WhatsApp

| Setting | Type | Config path |
|---|---|---|
| WhatsApp provider API key | secret | `services.whatsapp.api_key` |
| WhatsApp sender ID | text | `services.whatsapp.sender_id` |

### Push notifications

| Setting | Type | Config path |
|---|---|---|
| Firebase service-account JSON | secret (paste the file) | `services.fcm.credentials` |
| Firebase project ID (optional) | text | `services.fcm.project_id` |

The service-account file is checked on save: it must be JSON containing `client_email` and `private_key`. A malformed file would otherwise save quietly and fail on the first push.

### Deliberately not in the console

| Not here | Why |
|---|---|
| AI stub mode (`services.anthropic.stub`) | Serves canned report-card remarks containing a real child's name, indistinguishable from genuine output. Not something to toggle from a browser. |
| Payment gateway keys (Paystack, Flutterwave) | Per school, already encrypted in `school_payment_gateways`, and owned by the school whose account receives the money. Managed from that school's Fees & Finance screen. |
| `APP_KEY`, database credentials, mail transport | Server-level; changing them from inside the app they run could lock the app out of itself. Stay in `backend.env`. |

Any key not in this registry is refused with `422`.

---

## 4a. Announcements — `/super-admin/announcements`

Notices from SchoolPilot to **school administrators**: maintenance, releases, policy changes. Each has a title, a message, a kind (information or warning), an audience (every school or one school) and an end time. School admins see live ones at the top of their dashboard. Publishing and withdrawing are audited.

They never go to parents, teachers or students. Those are the school's contacts, not SchoolPilot's; messaging them directly would make SchoolPilot the data controller for their details (NDPA, doc §12). A school that wants to pass a notice on uses its own Notifications screen. Not yet shown in the mobile app.

---

## 5a. Security — `/super-admin/security` and `/super-admin/audit-log`

### Security

Every administrator account (school admins and operators) with 2FA state, active session count, last sign-in and lockout. Tiles count accounts with and without 2FA, and say whether the server actually enforces 2FA at sign-in (`AUTH_REQUIRE_ADMIN_2FA`). You can filter by name or email, by 2FA state, and by school when you arrive from School detail.

**Sign out everywhere** deletes the account's tokens: this is the first response to a suspected compromise. It is limited to administrator accounts; teachers' and parents' sessions are the school's to manage. It is refused for the operator's own account, and it is audited.

### Audit log

Every `audit_logs` row across schools, newest first, 50 per page. Filters: kind (operator actions `platform.*` / school activity), school, action prefix, date range.

**Values are shown for platform actions only.** A school-side row, such as a student record edited, carries a child's personal data in its old and new values. The log shows *which fields* changed, not what they changed to (NDPA data minimisation, doc §12). To see the record, open the school.

---

## 6. API reference

All routes are under `/api/v1`, require a Sanctum token for a `super_admin`, and return `403` for any other role.

### Overview and schools

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/platform/overview` | — | `schools`, `students`, `staff`, `school_fee_flow`, `ai_spend_this_month`, `cbt` |
| GET | `/platform/schools` | `?search=`, `?status=active\|trial\|suspended` | `data[]` (id, name, subdomain, email, status, active_students, plan_band, ai_enabled, fees_collected, ai_spend_naira_this_month), `meta` |
| POST | `/platform/schools` | `name`, `subdomain`, `email`, `phone?`, `address?`, `status?` (`trial`\|`active`), `admin_name`, `admin_email` | `201` with `school` (incl. `sign_in_url`) and `administrator` (incl. one-time `temporary_password`, `setup_email_sent`) |
| GET | `/platform/schools/{id}` | — | `school`, `students`, `staff_by_role`, `fee_flow`, `ai_spend_naira_this_month` |
| POST | `/platform/schools/{id}/status` | `status`: `active`\|`trial`\|`suspended` | `school`. `422` for the platform row. |
| POST | `/platform/schools/{id}/ai` | `enabled`: boolean | `school`. `404` for the platform row. |
| POST | `/platform/schools/{id}/trial` | `trial_ends_at` (date, today or later) | `school` with the new date. `422` unless the school is on trial. |
| POST | `/platform/schools/{id}/access` | `reason` (10–500) | `201` with `access` (school, operator, reason, opened_at, expires_at, is_live). Closes any grant the operator already holds. `404` for the platform row. |
| POST | `/platform/access/close` | — | `closed`: how many grants were closed. Nothing open is not an error. |
| GET | `/platform/access-log` | `?school_id=` | `data[]` of grants, newest first, with `ended_at` and `is_live`; `meta` |
| GET | `/platform/schools/{id}/notes` | — | `data[]` (id, body, author, created_at), newest first, up to 200. `404` for the platform row. |
| POST | `/platform/schools/{id}/notes` | `body` (≤ 5000) | `201` with `data`. |

### Settings and AI

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/platform/settings` | — | `groups[]` → `settings[]` (key, label, type, source, is_set, value or hint, server_value, updated_by, updated_at) |
| PUT | `/platform/settings` | `values`: `{key: value}`; `clear`: `[key]` | Same as GET. A secret sent empty is unchanged; any other setting sent empty reverts to the server value. `422` for unknown keys or invalid values, with errors keyed `values.<key>`. |
| GET | `/platform/ai-usage` | — | `date`, `cap_naira`, `schools_with_ai_enabled`, `data[]` (school, calls, spent_naira, cap_used_percentage, at_cap) |

### Migrations

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/platform/school-migrations` | — | Open requests across schools, overdue first, each with its `school` |
| PUT | `/platform/school-migrations/{id}` | `status`: `submitted`\|`in_progress`\|`completed`\|`cancelled`; `operator_notes?` | The request. Completing stamps `completed_at`; notes are shown to the school. |

### Result PINs

| Method | Path | Body |
|---|---|---|
| GET / POST | `/platform/result-pins/price-tiers` | POST: `min_quantity`, `unit_price`, `is_active?` |
| GET / POST | `/platform/result-pins/school-rates` | POST: `school_id`, `unit_price`, `notes` |
| DELETE | `/platform/result-pins/school-rates/{schoolId}` | — |
| GET | `/platform/result-pins/batches` | — |
| POST | `/platform/result-pins/grant` | `school_id`, `quantity`, `unit_price?`, `gateway?` (`bank_transfer`\|`cash`), `notes` |

### Announcements

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/platform/announcements` | — | `data[]`, newest first (up to 100), with school, author, withdrawn_at, is_showing |
| POST | `/platform/announcements` | `title` (≤150), `body` (≤2000), `tone?` (`info`\|`warning`), `school_id?` (none = every school), `shows_until` (future) | `201` with `data` |
| POST | `/platform/announcements/{id}/withdraw` | — | Takes it down now. |
| GET | `/announcements` | — | **school_admin**: up to 5 live announcements for this school or every school. Not for teachers. |

### Security

| Method | Path | Body / query | Returns |
|---|---|---|---|
| GET | `/platform/audit-log` | `?school_id=`, `?scope=platform\|school`, `?action=` (prefix), `?from=`, `?to=` (dates), `?page=` | `data[]` (id, at, action, school, actor, record, changed_fields, old_values/new_values — **null unless the action is `platform.*`**, ip_address), `meta` |
| GET | `/platform/security/admins` | `?school_id=`, `?two_factor=on\|off`, `?search=` | `summary` (admins, with_two_factor, without_two_factor, locked, two_factor_required), `data[]` (id, name, email, role, school, two_factor_enabled, active_sessions, last_login_at, locked_until, status), `meta` |
| POST | `/platform/security/admins/{userId}/revoke-sessions` | — | `sessions_revoked`. `404` unless the user is a school admin or operator; `422` for your own account. |

### Headers

| Header | Set by | Effect |
|---|---|---|
| `X-Platform-School: <id>` | The web proxy, from `sp_view_school`, for super admins only | Scopes the request to that school **while the operator holds a live grant for it** (§4). Ignored for every other role. Without a grant, only `/platform/*`, `/user` and `/auth/*` answer. |
| `X-School-Subdomain` | The web proxy | Identifies the host's school for tenant resolution. For an operator this is the `admin` sign-in subdomain and is **not** their acting school. |

---

## 7. Audit trail

| Action | Recorded |
|---|---|
| `platform.school.provisioned` | Subdomain, status, administrator email |
| `platform.school.status_changed` | Old and new status |
| `platform.school.ai_toggled` | Old and new `ai_enabled` |
| `platform.settings.updated` | Keys changed and keys cleared — never values |
| `platform.school.opened` / `platform.school.closed` | The reason and expiry; when access was ended |
| `platform.school.trial_changed` | Old and new trial end date (status changes also record the trial date) |
| `platform.announcement.published` / `.withdrawn` | Title, audience and end time |
| `platform.user.sessions_revoked` | The administrator whose sessions were revoked, and how many |
| `platform.result_pin_school_rate_set`, grants | As recorded by the result-PIN console |

---

## 8. Next to build

See `super-admin-menu.md` §5. In short: term subscriptions (blocked on §13 pricing decisions), the operator IP allowlist (auth change: risk note first), then compliance, cross-school reports and support.
