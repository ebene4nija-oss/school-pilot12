# SchoolPilot Operator Console

The screens and API a **platform operator** (role `super_admin`) uses to run SchoolPilot across every school. For what a *school's* own administrator sees, see the School Admin sections of `SchoolPilot-Comprehensive-Documentation.md`.

Spec reference: doc §5 — *"Super Admin manages schools, subscriptions, platform-wide settings, AI service configuration, platform security, cross-school reporting."*

---

## 1. Status against the spec

| Spec area (§5) | State | Where |
|---|---|---|
| Schools | ✅ Built | Console → Schools; `/platform/schools` |
| Platform-wide settings | ✅ Built | Platform settings; `/platform/settings` |
| AI service configuration | ✅ Built | Platform settings → AI service; per-school switch in Schools |
| Cross-school reporting | 🟡 Partial | Four headline tiles and today's AI spend. No trends, no exports. |
| Subscriptions | ❌ Not built | §13 plan bands appear only as a label derived from the roll. Nothing records what a school is on or owes. |
| Platform security | ❌ Not built | No platform view of 2FA enrolment, sessions or the audit log. |

Built with an API but **no screen yet**:

| Feature | API | Why it matters |
|---|---|---|
| Migration request queue | `/platform/school-migrations` | Schools request assisted migration with a 24-hour promise; the queue that fulfils it is only reachable through the API. |
| Result-PIN rate card, school rates and grants | `/platform/result-pins/*` | Wholesale pricing and offline PIN grants for schools that paid by transfer. |

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
| **Open** | Scopes the operator's session to that school. Every school-side page then answers for it. The sidebar shows *Viewing as operator · School name* with **Leave this school**. |
| **Suspend / Reinstate** | Blocks or restores the school's access. Nothing is deleted — roll, results and payment history are kept. Audited. |
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
| Status | Trial (default) or Active. |

On success the console shows the sign-in address, school code, administrator and a **temporary password shown once** — it cannot be retrieved afterwards. A setup link is also emailed if mail is configured; the panel says whether it was.

---

## 4. Working inside a school

**Open** on a school sets an `sp_view_school` cookie. The web proxy turns that into an `X-Platform-School` header, which the API honours **for super admins only** — for any other role it is ignored, so no school user can reach another school by setting a header.

While inside a school:

- Every school-side page answers for that school, and **writes land in that school**.
- The console link stays in the sidebar, alongside the school's own screens.
- **Leave this school** clears the cookie and returns to the console.

An operator who has *not* opened a school has no acting school. School-scoped endpoints refuse with `409 Choose a school…` rather than guessing — in particular they never fall back to the `admin` sign-in subdomain.

---

## 5. Platform settings — `/super-admin/settings`

Integration keys and AI configuration for every school. **Values saved here override the server's `backend.env`**; anything not set here keeps the server's value, so a deployment that never opens this screen behaves exactly as before.

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

### Settings and AI

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/platform/settings` | — | `groups[]` → `settings[]` (key, label, type, source, is_set, value or hint, server_value, updated_by, updated_at) |
| PUT | `/platform/settings` | `values`: `{key: value}`; `clear`: `[key]` | Same as GET. A secret sent empty is unchanged; any other setting sent empty reverts to the server value. `422` for unknown keys or invalid values, with errors keyed `values.<key>`. |
| GET | `/platform/ai-usage` | — | `date`, `cap_naira`, `schools_with_ai_enabled`, `data[]` (school, calls, spent_naira, cap_used_percentage, at_cap) |

### Migrations *(no screen yet)*

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/platform/school-migrations` | — | Open requests across schools, overdue first, each with its `school` |
| PUT | `/platform/school-migrations/{id}` | `status`: `submitted`\|`in_progress`\|`completed`\|`cancelled`; `operator_notes?` | The request. Completing stamps `completed_at`; notes are shown to the school. |

### Result PINs *(no screen yet)*

| Method | Path | Body |
|---|---|---|
| GET / POST | `/platform/result-pins/price-tiers` | POST: `min_quantity`, `unit_price`, `is_active?` |
| GET / POST | `/platform/result-pins/school-rates` | POST: `school_id`, `unit_price`, `notes` |
| DELETE | `/platform/result-pins/school-rates/{schoolId}` | — |
| GET | `/platform/result-pins/batches` | — |
| POST | `/platform/result-pins/grant` | `school_id`, `quantity`, `unit_price?`, `gateway?` (`bank_transfer`\|`cash`), `notes` |

### Headers

| Header | Set by | Effect |
|---|---|---|
| `X-Platform-School: <id>` | The web proxy, from `sp_view_school`, for super admins only | Scopes the request to that school. Ignored for every other role; ignored if the id does not exist. |
| `X-School-Subdomain` | The web proxy | Identifies the host's school for tenant resolution. For an operator this is the `admin` sign-in subdomain and is **not** their acting school. |

---

## 7. Audit trail

| Action | Recorded |
|---|---|
| `platform.school.provisioned` | Subdomain, status, administrator email |
| `platform.school.status_changed` | Old and new status |
| `platform.school.ai_toggled` | Old and new `ai_enabled` |
| `platform.settings.updated` | Keys changed and keys cleared — never values |
| `platform.result_pin_school_rate_set`, grants | As recorded by the result-PIN console |

---

## 8. Next to build

In order of what they unblock:

1. **Screens for the migration queue and result-PIN console** — the API exists and is tested.
2. **Platform security** — 2FA enrolment across operators and school admins, active sessions, and a searchable audit log.
3. **Subscriptions** — record each school's plan and term billing per §13, charged at result generation and premium CBT. Needs pricing decisions first.
4. **Cross-school reporting** — trends over terms, and exports.
