# Super Admin Menu — What Belongs In It

What the **platform operator** (role `super_admin`) sidebar should contain, where each item comes from, and how far it is built. `platform-console.md` covers the screens and API that already exist in detail. This document covers the whole menu, including items that are not built yet.

Researched 2026-09-26 from:

| Source | What it says |
|---|---|
| Spec §5 (`SchoolPilot-Comprehensive-Documentation.md`) | Super Admin manages *schools, subscriptions, platform-wide settings, AI service configuration, platform security, cross-school reporting*. These six areas are the authoritative scope. |
| Spec §12, §13, §14 | NDPA obligations, per-term student-band pricing charged at result generation and premium CBT, WhatsApp-first sales, free 24-hour migration. |
| Build prompts, Stage 3 item 8 and Stage 12 item 3 | Super Admin needs more protection than 2FA alone (IP allowlist or VPN). Console screens: All Schools Overview, School Account Detail, Platform Settings, Billing Summary. |
| Stitch mockups (`stitch_schoolpilot_management_system/…/all_schools_overview`, `school_account_detail`, `platform_settings`, `subscription_billing_summary`) | Sidebar: **Schools · Accounts · Platform Settings · Billing · Support**. |
| Code: `web/src/components/Sidebar.tsx`, `web/src/app/super-admin/`, `backend/routes/api.php` (`/platform/*`) | What exists today. |

---

## 1. What the menu shows today

```
Operator console        /super-admin            (overview tiles + schools directory + New school)
Platform settings       /super-admin/settings   (AI, SMS, WhatsApp, push keys; today's AI spend)
```

While the operator has a school open, the school admin menu also appears, together with the *Viewing as operator · School name* banner. There is no separate School detail page, even though `GET /platform/schools/{id}` exists.

---

## 2. Recommended menu

Grouped by the six §5 areas, plus the operational items that the spec's go-to-market (§14) and compliance (§12) sections require.

```
Overview
Schools
  ├ All schools
  ├ School detail           (per school, reached from the list)
  └ Onboarding & migrations
Billing
  ├ Billing summary
  ├ Plans & bands
  └ Result PINs
AI service
Security
  ├ Admin 2FA & sessions
  ├ Audit log
  └ Operator access
Compliance (NDPA)
Reports
Support
Platform settings
```

### Status key
✅ built · 🟡 partial · 🔌 API exists, no screen · ❌ not built

| # | Menu item | §5 area | Status | What goes on it | Source |
|---|---|---|---|---|---|
| 1 | **Overview** | Cross-school reporting | 🟡 | Headline tiles: schools (active/trial/suspended), students, school fees collected (labelled clearly as *not* SchoolPilot revenue), AI spend this month. Attention list: schools at their AI cap, overdue migrations, overdue term invoices. | Existing console; mockup |
| 2 | **Schools › All schools** | Schools | ✅ | Directory with search/status filter, plan band, fees, AI spend, status. Actions: New school, Open, Suspend/Reinstate, AI on/off. The mockup also filters by **city** and shows **last active date** and an **"Owes ₦…"** flag. | `/platform/schools`; mockup |
| 3 | **Schools › School detail** | Schools | 🔌 | Profile (name, admin contact, phone, address), students, staff by role, fee flow, AI spend; **subscription and billing history per term**; **internal support notes** (operator-only, never shown to the school); Suspend. | `GET /platform/schools/{id}`; mockup `school_account_detail` |
| 4 | **Schools › Onboarding & migrations** | Schools | 🔌 | Queue of migration requests, overdue first, with status (submitted → in progress → completed/cancelled) and notes that are shown to the school. Honours the §14 promise of free migration within 24 hours. | `/platform/school-migrations` |
| 5 | **Billing › Billing summary** | Subscriptions | ❌ | Revenue **per term** (not MRR, see §3), outstanding and overdue term invoices, upcoming renewals, export. | Mockup `subscription_billing_summary`; Stage 12 |
| 6 | **Billing › Plans & bands** | Subscriptions | ❌ | The §13 student-count bands (Starter → Custom) with per-term prices, the full-session discount, and each school's assigned plan. Today the band is only derived from the roll; nothing is stored. **Pricing decisions are needed before this can be built.** | §13 |
| 7 | **Billing › Result PINs** | Subscriptions | 🔌 | Wholesale rate card (quantity tiers), per-school negotiated rates, PIN batch history, offline grants for schools that paid by bank transfer or cash. This is the "charge at result generation" mechanism from §13. | `/platform/result-pins/*` |
| 8 | **AI service** | AI configuration | ✅ (inside Platform settings) | Anthropic key, models, daily ₦ cap per school, alert threshold, USD→NGN rate, **today's spend per school**, per-school AI switch. Recommend making this its own menu item, because "our AI stopped working" is the most common operator question. | `/platform/settings`, `/platform/ai-usage` |
| 9 | **Security › Admin 2FA & sessions** | Platform security | ❌ | 2FA enrolment status for every school admin and operator (2FA is already mandatory for both roles through `require.2fa`), active sessions, revoke sessions. Per-user endpoints exist (`/users/{id}/security`, `/users/{id}/revoke-sessions`), but no cross-school view does. | §5; §7.15; Stage 3 |
| 10 | **Security › Audit log** | Platform security | ❌ | Searchable log of `platform.*` actions and sensitive school-side changes (students, payments, results), filterable by school, actor and action. The `AuditLog` model is already written to; nothing reads it at platform level. The mockup links a "Security Audit Log". | §7.15; Stage 3; mockup |
| 11 | **Security › Operator access** | Platform security | ❌ | List of operator accounts, and the Stage 3 hardening: **IP allowlist** or a VPN requirement for `super_admin`. Nothing in the code implements an allowlist yet. Treat this as auth config: explain the change and its risk before building it (CLAUDE.md). | Stage 3 item 8 |
| 12 | **Compliance (NDPA)** | Platform security *(inferred)* | 🟡 | Data-export requests across schools (`/admin/exports`), parental-consent coverage per school, deletion and consent-withdrawal requests, and a record of the lawful basis for the Claude API cross-border transfer. Education data puts SchoolPilot in the "major importance" category, so it needs a DPO and annual audit filings. This screen is where that evidence would live. | §12 |
| 13 | **Reports** | Cross-school reporting | ❌ | Trends across terms (schools, students, fees, CBT, AI spend), export to Excel. Keep it aggregate: counts and totals per school, never lists of students. | §5; `platform-console.md` §8 |
| 14 | **Support** | *(operational)* | ❌ | Mockup menu item. Fits the §14 WhatsApp-first model: a view of schools' open issues and the internal notes from School detail. There is no backing model yet. | Mockup; §14 |
| 15 | **Platform settings** | Platform-wide settings | ✅ | SMS (Termii), WhatsApp, Firebase push keys. Secrets are write-only and encrypted, and every change is audited. | `/platform/settings` |

---

## 3. Where the mockups conflict with the spec or the rules

The mockups are UI references. Where they conflict with the spec, the spec wins.

| Mockup shows | Problem | Do instead |
|---|---|---|
| Billing summary headlined **"Total MRR"**, monthly/quarterly toggle, `₦150k/mo` renewals | §13: billing is **per term**, charged at result generation and premium CBT, and is *not* a flat monthly SaaS fee | Per-term revenue, term renewals, PIN sales |
| Tiers **Basic / Pro / Enterprise** | §13 bands are Starter, Growth, Standard, Premium, Enterprise, Custom, set by student count | Use the §13 bands |
| AI models "Edu-Fast" / "Edu-Reasoning", "monthly budget" | Real config is named Claude models and a **daily** ₦ cap per school | Keep the built AI screen |
| **Mandatory MFA "Strict (All Roles)"** toggle and idle-timeout setting | 2FA is already enforced in middleware for school admins and operators. A browser toggle that could turn it *off* weakens auth, and CLAUDE.md requires explaining auth changes first | Show enrolment status (item 9) rather than a toggle |
| **System announcement** to "All platform users / Parents only" | Messaging another school's parents directly makes SchoolPilot act as the data controller for that contact (NDPA). Schools already have their own broadcast feature | If built, limit it to **school admins** (maintenance notices, release notes) |
| **Accounts** menu item | It is unclear whether this means school accounts (School detail says "Back to Accounts") or user accounts | Treat as *Schools*. Cross-school user management is covered by Security |

---

## 4. Deliberately not in the menu

| Not here | Why |
|---|---|
| Payment gateway keys (Paystack/Flutterwave) | Each school owns its own keys, stored encrypted in `school_payment_gateways` and managed from the school's Fees & Finance screen |
| `APP_KEY`, database, mail transport, AI stub mode | Server-level settings; changing them from the app could lock the app out of itself (`platform-console.md` §5) |
| Any school's day-to-day screens | The operator reaches them through **Open** on a school, not through the operator menu |
| Mobile | The operator console is web-only (`mobile-app.md`) |
| Browsing student records across schools | NDPA data minimisation: platform views stay aggregate. Opening a single school is the audited way in |

---

## 5. Suggested build order

1. **School detail + Onboarding & migrations + Result PINs screens.** The APIs are built and tested; only the screens are missing.
2. **Security: audit log, then admin 2FA & sessions.** Mostly read-only, and it closes the "platform security ❌" gap.
3. **AI service as its own menu item.** A small refactor of the existing settings page.
4. **Plans & bands → Billing summary.** Blocked on pricing decisions (§13 uses placeholder ₦X).
5. **Operator access (IP allowlist).** Auth change: needs a written risk note first.
6. **Compliance, Reports, Support.**

## 6. Open questions for the product owner

- §13 prices: what is ₦X, and what is the full-session discount?
- Should a school's bill be a term licence (as the mockup shows) or purely usage-based on PINs and premium CBT (as §13 implies), or both?
- Will there be more than one operator account? If so, do operators need sub-roles, for example support-only versus billing?
- Should the Support menu item be built in-app, or should support stay entirely in WhatsApp?
