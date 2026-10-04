# SchoolPilot — Landing Pages & Marketing Website PRD

**Product:** SchoolPilot · **Market:** Private K–12 schools in Nigeria · **Surface:** Public web
**Version:** 2.0 (revises v1.0) · **Date:** 2026-10-04
**Companion docs:** `SchoolPilot-Comprehensive-Documentation.md` (product spec — "the spec"), `LAUNCH.md`, `/TASK/*` (module PRDs)

---

## 0. What changed from v1.0, and why

v1.0 was a solid generic SaaS marketing PRD. v2.0 checks it against three things v1.0 didn't account for: what the product **actually does today** (the codebase), the **commercial model and go-to-market already decided in the spec** (§2, §4, §13, §14), and the **hard rules** in `CLAUDE.md`. The main changes:

| # | v1.0 said | v2.0 says | Why |
|---|---|---|---|
| 1 | Primary CTA: **Get Started** (self-serve account creation) | Primary CTA: **Chat on WhatsApp**; high-intent: **Book a Demo**; "Get Started" = *assisted* onboarding request until self-serve exists | Spec §2.5 and §14: WhatsApp-first sales, no self-serve funnel for Phase 0. Nigerian school owners buy through relationships and quick human responses. Self-serve tenant creation also touches auth/deploy config (a CLAUDE.md hard rule). |
| 2 | Pricing: "Talk to Sales" until finalized | Publish the **pricing model** now (free core; pay per term, by student band, only at result generation and premium CBT). Publish **numbers** only once they're final. | The free core is the strongest message we have, and v1.0 hid it. Stating the model is not fabricating prices. |
| 3 | No mention of switching cost or lock-in | **Free 24-hour migration** and **one-click data export** become headline trust claims | Spec §14 calls these "the single strongest lever against switching friction". Both exist in code (`SchoolMigrationController`, `SchoolDataExportController`). |
| 4 | No mention of hardware | **"Nothing to buy. No scanners, no biometrics."** is a differentiator | Spec §3. Competitors sell fingerprint/RFID kits. We use phone camera and GPS, and avoid the NDPA burden of biometric data. |
| 5 | Didn't address power/internet reality | **Offline CBT** (desktop app on the school's existing computers) is a headline CBT claim | It exists (`CbtOfflineBundleController`, `/desktop`). It answers the first objection every proprietor raises. |
| 6 | Lists Inventory and Facilities as modules | **Removed from the public IA** until they're built | No inventory/facilities module exists in the backend. Marketing an unbuilt module is a fabrication, just like a fake statistic. |
| 7 | Missing real, Nigeria-specific modules | Adds **Result PINs & Result Checker**, **Nursery developmental ratings**, **ID cards**, **Clinic**, **Session rollover**, **Student transfers** | All exist in code and are recognisably Nigerian. |
| 8 | Homepage: 17 stacked sections | **10 sections**, organised around **"one term in SchoolPilot"** rather than a module dump | Mobile-first, data-cost-aware audience. A term narrative (resumption → CA → exams → broadsheet → report card → fees) is how schools already think. |
| 9 | Social proof: wait for real customers | **Prove the product, not the customer count:** interactive demo school, downloadable sample report card, Founding Schools programme | We have no customers to quote, but we do have a demo school (Greenfield Academy seeder) and real report-card templates. |
| 10 | Generic compliance mentions | A dedicated **/trust** page: NDPA, children's data, AI data transfer, 2FA, backups, export | Parents and proprietors are increasingly asked about data. Also a spec §12 obligation. |
| 11 | Leads: abstract "lead system" | Concrete: `/api/v1/public/leads`, built into the **Super Admin console**, with **WhatsApp attribution codes** | WhatsApp conversations are invisible to web analytics unless we tag them. |
| 12 | Analytics: unspecified tooling | **Cookieless analytics**, no third-party ad pixels at launch | NDPA, no consent banner friction, lighter pages. |
| 13 | Performance: "fast" | **Hard budgets** (JS ≤ 90 KB gz, LCP ≤ 2.5 s on Slow 4G) | Mobile data is expensive and connections are uneven. Speed is part of the pitch. |
| 14 | Personas: 5 | Adds **Bursar** and **Exams Officer / ICT teacher** as key influencers. Parents become a **demand channel**. | These two run the money and the result process and usually get the vendor shortlist. Parents pressure schools into adopting portals. |
| 15 | No calendar | **Seasonal campaign calendar** tied to the Nigerian academic year | Buying decisions cluster around session start and end-of-term results. |

Everything in v1.0 not contradicted above still stands, folded into the sections below.

---

## 1. Executive summary

The marketing site is SchoolPilot's front door. It has one job: **turn a Nigerian school owner's curiosity into a WhatsApp conversation or a booked demo**, while giving administrators, teachers and parents enough proof to back that decision.

> **Core promise:** One connected platform for your school's academics, people, money and parents. Free for almost everything. You pay only when it saves you real work, at result time.

Visitor journey: **Recognise the pain → See it handled → Trust it → Talk to a human.**

---

## 2. Ground truth: what we may and may not claim

Every claim on the site must trace to a working feature or a documented policy. This table is the claims register. Marketing copy is reviewed against it before publishing.

### 2.1 Claimable now (built)

| Area | Evidence in codebase | Marketing name |
|---|---|---|
| Student records, guardians, class history, transfers | `StudentController`, `Transfer*`, `AcademicHistoryController` | Student Management |
| Sections (Nursery/Primary/JSS/SS), configurable CA schemes | `SectionsController`, `CaScheme`, `SchemeVersionController` | School Sections & Assessment |
| Score entry, broadsheet, positions, corrections, approvals | `BroadsheetController`, `Broadsheet*`, `ReportEntryController` | Results & Broadsheets |
| Report cards (templates, design requests, generation, release) | `GeneratedReportCardController`, `ReportCardTemplate*` | Report Cards |
| Result PINs and online result checker | `ResultPin*`, `ResultCheckerController` | Result Checker |
| AI report-card comments (teacher-approved) | `PlatformAiRemarksController`, `StudentRemark` | AI Comment Assistant |
| CBT: question bank, groups, versions, oversight, regrade, offline bundles | `Cbt*` | CBT (online & offline) |
| Attendance (QR / manual), absence alerts | `AttendanceController`, `AbsenceAlertController` | Attendance |
| Timetable + exam timetable | `TimetableController`, `ExamTimetableController` | Timetable |
| Homework / assignments with submissions | `Homework*` | Assignments |
| Learning materials, lesson plans, AI Studio | `LearningMaterialController`, `LessonPlanController`, `AiStudioController` | Teaching Tools |
| AI tutor | `TutorConversation`, `TutorSetting` | AI Tutor |
| Live classes (Zoom/Meet-style providers) | `LiveClassController`, `VideoProviderConnection` | Virtual Classes |
| Fees, invoices, installments, scholarships, online payment | `FeeCollectionController`, `Invoice`, `PaymentInstallment`, `Scholarship`, `PaymentGatewayController` | Fees & Finance |
| Accounting, expenses, budgets, petty cash, vendors | `AccountingController`, `Expense`, `Budget`, `PettyCash`, `Vendor` | Finance |
| Staff records, leave, loans, salary structures, payroll, payslips | `StaffHrController`, `HrPayrollController`, `Payslip` | HR & Payroll |
| Admissions with public application form | `AdmissionController` (incl. `/public/admissions/*`) | Admissions |
| Hostel (rooms, beds, roll-call, leave, welfare, incidents) | `Hostel*` | Hostel / Boarding |
| Library | `LibraryController` | Library |
| Transport / bus tracking via driver's phone | `web/src/app/bus-tracking` | Transport |
| Clinic visits | `ClinicVisit` | Sick Bay |
| ID cards (designed, printable PDF) | `IdCard*` | ID Cards |
| Messaging, notifications, SMS gateway, push | `Message*`, `NotificationController`, `SchoolSmsGateway` | Communication |
| Nursery developmental ratings | `DevelopmentalController`, `DevelopmentalRating` | Early Years Reporting |
| Gamification | `GamificationController` | Student Motivation |
| Parent portal and student portal | `ParentPortalController`, `StudentPortalController` | Parent / Student apps |
| Analytics, progress insights, interventions | `AnalyticsController`, `ProgressController`, `Intervention` | Insights |
| Data export, migration, session rollover | `SchoolDataExportController`, `SchoolMigrationController`, `SessionRolloverController` | Your Data, Your School |
| 2FA, audit logs, login history, custom roles | `security` page, `AuditLog`, `LoginHistory`, `CustomRole` | Security |

### 2.2 Claimable only after verification

| Claim | Condition before it goes live |
|---|---|
| "Pay fees with Paystack / Flutterwave" | Live keys and signed webhooks verified per `LAUNCH.md` §1. Until then, say "online fee payment". |
| "SMS and WhatsApp alerts to parents" | One real test message per channel delivered in production (`LAUNCH.md` §1). |
| "Nightly encrypted backups, 1-hour RPO" | First monthly restore drill completed and logged. |
| Any count of schools/students/teachers | Pulled live from the platform. See §8. |

### 2.3 Never claim

- Inventory or facilities management. **Not built.** Add when built.
- Biometrics, facial recognition, RFID, fingerprint clock-in, or any hardware. **Ever.** (Spec §3.)
- "Your data never leaves Nigeria." AI features call a US-hosted API (spec §12). Say exactly what is true; see §13.
- "AI writes your report cards." AI *drafts* comments that a teacher must approve (CLAUDE.md hard rule).
- University vocabulary: semesters, GPA, faculties, courses-as-credits.
- Fabricated testimonials, logos, statistics, prices, or "trusted by 500+ schools".

---

## 3. Positioning

### 3.1 Positioning statement (from spec §4, sharpened for the web)

> **For private school owners in Nigeria** who are tired of spreadsheets, late results and chasing fees, **SchoolPilot** is the school management platform that **runs your whole school from one login, with nothing to buy and nothing to install, and is free for almost everything**. Unlike portals that charge per seat, sell you fingerprint machines or lock in your data, **you pay only at result time, we move your data in free within 24 hours, and you can take it all out in one click.**

### 3.2 Five pillars (use these, in this order of priority)

1. **Results without the all-nighters.** CA to broadsheet to positions to a signed report card, configured to how *your* school grades.
2. **Free for almost everything.** Core modules cost nothing. You pay per term, by school size, only when you generate results or run premium CBT.
3. **Built for Nigerian reality.** CBT that runs with no internet. No hardware to buy. Parents without smartphones get SMS. Works on the phones your staff already own.
4. **Your data stays yours.** Free 24-hour migration in. One-click export out. NDPA-first, with children's data treated as the most sensitive data we hold.
5. **Everyone connected.** Owners see everything. Teachers spend less time on admin. Parents stop asking "has the result come out?"

### 3.3 Message hierarchy

| Level | Copy |
|---|---|
| Headline (control) | **Run your whole school from one login.** |
| Sub | Students, results, CBT, fees, staff and parents, connected. Built for Nigerian private schools, from Nursery to SS3. Free for almost everything. |
| Proof line | Free 24-hour data migration · No hardware to buy · Works offline for CBT |

v1.0's headline ("Run Your School Smarter. From One Powerful Platform.") is kept as an **A/B variant** (§17). "Smarter" and "powerful" are claims every competitor makes. "One login" is concrete and checkable.

**Headline variants for testing:**
- *"From first CA to signed report card. Without a single spreadsheet."* (results-led)
- *"The school portal that's free until result time."* (price-led)
- *"Know what's happening in your school, without calling anyone."* (owner-visibility-led)

### 3.4 Language rules

- Nigerian school vocabulary: *session, term, resumption, CA, exam, broadsheet, position, report card, result, proprietor/proprietress, bursar, school fees, PTA, JSS1–SS3, Nursery 1–2, Primary 1–6, BECE, WAEC, NECO*.
- Money always in ₦ with Nigerian grouping (₦150,000).
- Outcome before feature: *"Know who's absent by 8:15 and tell their parents automatically,"* not *"Attendance Management."*
- Plain, warm, confident. No hype words ("revolutionary", "cutting-edge", "AI-powered everything").

---

## 4. Audience

### 4.1 Buyers, influencers, users

| Persona | Role in the sale | Core pain | What wins them | Primary CTA |
|---|---|---|---|---|
| **Proprietor / Director** | **Decision-maker and payer** | No visibility; leakage in fees; results delayed; dependence on one "computer person" | Owner dashboard, fee visibility, cost (free core), data ownership | **Chat on WhatsApp / Book a demo** |
| **Principal / Head Teacher / School Admin** | Champion; runs the evaluation | Spreadsheets, result approvals, staff coordination | Approval workflows, broadsheet, timetable, one place for everything | Book a demo |
| **Bursar / Accountant** *(new)* | Influencer with veto on finance | Reconciling fees, debtors list, receipts, payroll | Invoices, installments, payment records, debtors, payslips | See finance tour |
| **Exams Officer / ICT Teacher** *(new)* | Technical gatekeeper; often makes the shortlist | Result computation, CBT setup, lab computers, power cuts | Configurable CA, offline CBT, broadsheet export | See how CBT works |
| **Teacher** | User; adoption risk | Score entry, attendance, lesson notes | Phone-friendly score entry, AI lesson-plan help, less paperwork | Explore teacher tools |
| **Parent** | User and **demand channel** | Late results, no visibility, fee confusion | Results on phone, fee balance, absence alerts | See the parent experience / *"Recommend SchoolPilot to your school"* |
| **Student** | User | Assignments, results, revision | CBT practice, AI tutor, results | Explore student experience |

**Implication:** the homepage speaks to the **proprietor** first. Role pages carry the rest.

### 4.2 Segment priority

1. **Day schools, 50–3,000 students**, currently on paper, Excel or a rival portal (spec §4).
2. Boarding schools: secondary audience. Hostel/sick bay/transport pages exist but sit lower in the IA.
3. Multi-campus groups: handled through demo/sales, not a dedicated page in Phase 1.

### 4.3 Objections the site must answer (each needs a page section or FAQ entry)

| Objection | Answer (only true claims) |
|---|---|
| "Our internet and light are unreliable." | Offline CBT desktop app on your existing computers; mobile apps for staff. |
| "My teachers are not computer people." | Phone-first, guided setup, we migrate your data and train your staff. |
| "We're mid-session. Switching now is chaos." | Free 24-hour migration of current records; start with one module (e.g. results) and grow. |
| "What does it really cost?" | Free core; per-term charge by student band only at result generation/premium CBT. |
| "What if we leave?" | One-click full export, any time. |
| "Many parents don't have smartphones." | SMS alerts; result checker with PINs; printable report cards. |
| "Is the AI going to write nonsense on report cards?" | Nothing AI-drafted reaches a report card until a teacher approves or edits it. |
| "Is children's data safe?" | NDPA-first design, 2FA, audit logs, encryption, no biometrics collected at all. See /trust. |
| "Do we need to buy machines?" | No. Nothing to buy, ever. |

---

## 5. Information architecture

Changes from v1.0: Inventory and Facilities removed. Result Checker, Early Years, ID Cards and Sick Bay added. **/trust** and **/migrate** added. **/schools** (find-your-school login) added. Resources trimmed for Phase 1.

```text
/                                   Home
/features                           All features (grouped hub)
  /features/student-management
  /features/academics               (sessions, terms, sections, CA schemes)
  /features/results-report-cards    (broadsheet, positions, comments, release)
  /features/result-checker          (PINs, parent result access)        NEW
  /features/cbt                     (online + offline)
  /features/attendance
  /features/timetable
  /features/assignments
  /features/teaching-tools          (materials, lesson plans, AI Studio)
  /features/virtual-classes
  /features/ai-tutor
  /features/fees-finance            (merges v1.0 "Finance")
  /features/hr-payroll
  /features/admissions
  /features/communication
  /features/boarding                (hostel, sick bay)            grouped
  /features/library
  /features/transport
  /features/id-cards                                              NEW
/solutions
  /solutions/school-owners          ← highest-priority commercial page
  /solutions/administrators
  /solutions/bursars                                              NEW
  /solutions/teachers
  /solutions/parents
  /solutions/students
/school-sections
  /school-sections/nursery          (developmental ratings, early years)
  /school-sections/primary
  /school-sections/jss              (BECE readiness)
  /school-sections/ss               (WAEC/NECO readiness, CBT practice)
/pricing                            Model now; numbers when final
/demo                               Book a demo
/start                              Assisted onboarding request ("Get Started")
/migrate                            Free 24-hour migration                NEW
/trust                              Security, privacy, NDPA, AI data      NEW
/schools                            Find your school → tenant login       NEW
/faq
/about
/contact
/privacy · /terms · /data-policy
/resources (Phase 3: blog, guides, templates, help center)
/campaigns/* (Phase 3)
```

### 5.1 Login handoff (missing from v1.0)

SchoolPilot is multi-tenant by subdomain (`<school>.schoolpilot.org.ng`). A visitor clicking **Login** on the marketing site doesn't know their subdomain. **/schools** solves this:

- A single input: *"Your school's SchoolPilot address"* (e.g. `greenfield`), with suffix `.schoolpilot.org.ng` shown inline.
- Exact-match lookup only. **No search or listing of schools** (that would let anyone enumerate our customers; their customer list is also private information).
- Remember the last school in `localStorage` for a one-tap return.
- Parent-facing link *"Checking a result? Use your school's result checker"* goes to the same lookup, then to `/result-checker` on that tenant.

---

## 6. Homepage

Ten sections. Each must earn its place on a phone.

```text
1  NAV            Logo · Features · Solutions · Pricing · Login | [Chat on WhatsApp] [Book a demo]
2  HERO           Headline · sub · CTAs · proof line · real product UI (demo school)
3  PROOF STRIP    Nothing to buy · Free 24-hr migration · Offline CBT · NDPA-first · Web + Android/iOS
4  PROBLEM        "Running a school shouldn't mean running on spreadsheets."
5  ONE TERM       Interactive timeline: Resumption → Attendance → CA → Exams/CBT → Broadsheet → Report cards → Fees
6  ROLES          Tabs: Owner · Admin · Bursar · Teacher · Parent · Student (screenshot + 3 outcomes each)
7  WHY US         The five pillars (§3.2)
8  PRICING MODEL  "Free for almost everything" + what's paid + link to /pricing
9  FAQ            6 highest-value objections (§4.3), collapsible
10 FINAL CTA      WhatsApp + demo + "Try the demo school"
   FOOTER
```

v1.0's separate Admin, Academic, Teacher, Student, Parent, Operations and AI sections become **section 6 (role tabs)** and **section 5 (one term)**. Operations (hostel, library, transport) appears as one line in section 7: *"Boarding, library and transport too, when you need them."*

### 6.1 Hero

- **Eyebrow:** *For Nigerian private schools · Nursery to SS3*
- **Headline:** *Run your whole school from one login.*
- **Sub:** as §3.3.
- **CTAs:** **Chat on WhatsApp** (primary: amber button with the WhatsApp icon, kept on-brand rather than WhatsApp green) · **Book a demo** (secondary) · text link *"or try the demo school →"*
- **Visual:** a real screenshot of the principal dashboard from the Greenfield Academy demo tenant (§9), served as responsive AVIF/WebP with explicit dimensions. **No carousel.**
- **Mobile:** headline, sub, two stacked full-width buttons, then image. Under 600 px, the image is a cropped phone-frame of the mobile app, not a shrunk desktop screenshot.

### 6.2 "One term in SchoolPilot" (signature section)

A horizontal (desktop) / vertical (mobile) timeline of seven steps. Each step has one outcome sentence and a small UI crop. Example:

| Step | Outcome copy |
|---|---|
| Resumption | Classes, subjects and teachers carried over with one session rollover. |
| Attendance | Teachers mark the register on their phones; parents of absent children are alerted. |
| CA | Your CA structure, your weights. 2 CAs or 4, 40/60 or 30/70. |
| Exams & CBT | Run CBT online, or offline on your lab computers when the internet is down. |
| Broadsheet | Scores flow into the broadsheet; positions computed; corrections tracked and approved. |
| Report cards | Professional report cards with teacher-approved comments, released to parents in one step. |
| Fees | Every invoice, payment and balance in one place. Know who owes before resumption. |

This is the section that makes visitors feel *"they understand how a Nigerian school works."*

---

## 7. Page templates

### 7.1 Feature page (v1.0 §10, refined)

1. **Outcome headline** (not the module name) + one-line sub
2. **The problem today** (2–3 bullets in the school's own words)
3. **How it works:** 3–5 step workflow with real screenshots
4. **Benefits:** 3–6, each an outcome
5. **Who uses it:** role chips linking to solution pages
6. **Nigerian detail callout:** e.g. "Supports class position, subject position and 'tie' handling"
7. **Proof element:** demo link, sample PDF, or short screen recording (≤ 30 s, muted, poster image, loads on tap)
8. **FAQ** (3–5)
9. **Related features** (3)
10. **CTA band:** WhatsApp + demo

### 7.2 Solution (role) page

Hero aimed at the role → "a day/week/term in your role" → 4–6 outcome blocks with screenshots → objections for that role → CTA. **The school-owners page also carries the pricing model and the trust summary.**

### 7.3 School-section page

Must acknowledge that sections run differently (v1.0 §18). Section-specific content:

| Section | Lead with |
|---|---|
| Nursery | Developmental ratings instead of scores; early-years report cards; parent updates |
| Primary | Simple CA structures, class teacher workflows, parent communication |
| JSS | Subject teachers, broadsheets, BECE preparation via CBT practice |
| SS | Arms and subject combinations, WAEC/NECO-style CBT practice, result PINs |

---

## 8. Trust and social proof (honest by design)

### 8.1 Before we have customers

Prove **the product**, not the customer count:

1. **Interactive demo school** (§9): the single strongest proof asset.
2. **Downloadable sample report card** (PDF) from a real template with demo data (Apex template).
3. **Sample broadsheet** (PDF/XLSX) from the demo school.
4. **60-second screen recordings** of score entry → broadsheet → report card, and CBT offline.
5. **Trust commitments** (all true): free migration, one-click export, nothing to buy, NDPA-first, AI with teacher approval.
6. **Founder/team presence** on /about: real names, real photos, a real Nigerian phone/WhatsApp number and address. In this market, a visible team builds trust.

### 8.2 Founding Schools programme

Offer the first cohort of schools (size to be set by the business) a benefit, e.g. a locked rate or extended free result-generation terms, **in exchange for written consent** to use their name, logo and a quote after one full term. This creates genuine case studies by the first major buying season. Consent is stored and revocable. A school that withdraws is removed within 7 days.

### 8.3 Live stats

Spec §14 suggests a live counter. Allowed **only** if:
- the numbers are read live from the platform (`GET /api/v1/public/stats`, cached 1 h), never typed in;
- each number is hidden until it passes a threshold that isn't embarrassing (e.g. schools ≥ 10, students ≥ 1,000), with thresholds set server-side;
- only aggregate counts are returned. No school names.

### 8.4 Testimonials, logos and case studies

Real, consented and attributable (name, role, school) only. Case-study structure per v1.0 §30. **No anonymous testimonials.** "A proprietor in Lagos" is indistinguishable from a fake one.

---

## 9. Demo school (new: the most important proof asset)

The repo already seeds **Greenfield Academy** with realistic, fictional data. Expose it publicly:

- `demo.schoolpilot.org.ng` with **role switcher**: Owner · Admin · Teacher · Parent · Student. One click, no signup.
- **Read-mostly:** writes are allowed (so it feels real) but the tenant is **reset nightly** from the seeder.
- **Disabled in demo:** outbound SMS/WhatsApp/email, payments, AI calls (serve canned responses, or cap tightly under a dedicated demo budget), data export, password changes, 2FA.
- A persistent banner: *"You're exploring a demo school with fictional data. [Chat with us] [Book a demo]"*.
- All names, photos and records fictional. Avatars are initials or illustrations. **Never real children's photos.**
- Track `demo_role_selected` and time-in-demo; a demo visit is a high-intent signal for lead scoring.

> Engineering note: the demo is a normal tenant with a `is_demo` flag that gates the disabled features in middleware. It must never share a database row with a real tenant.

---

## 10. Pricing page

### 10.1 Now (model published, numbers pending)

```text
FREE, FOREVER                               PAID, PER TERM
Student records · Staff & HR                Result generation (report cards)
Attendance · Timetable · Fees tracking      Premium CBT grading
Communication · AI Studio · AI Tutor
Admissions · Parent & student apps          Priced by student band, billed per term.
                                            Pay for a full session and save.
```

- Explain *why*: "You pay at the moment SchoolPilot saves you the most work, not for seats you don't use."
- Show the **band structure** (Starter … Custom) with **"Price confirmed on WhatsApp"** in place of a number, until prices are final.
- **Result PINs:** explain that schools can sell result-checker PINs to parents (`ResultPinSale`). Only describe what the code supports; don't invent revenue figures.
- FAQ: What counts as a student? Can we pay per session? What happens if we don't pay? (State the real policy, e.g. data remains accessible and exportable.)

### 10.2 When prices are final

Add numbers to bands, a ₦ calculator ("How many students do you have?" → band → per-term and per-session price), and an "included in every plan" checklist. **Never display placeholder figures** (v1.0 rule stands).

### 10.3 ROI calculator (Phase 2, lead magnet)

Inputs: number of classes, subjects per class, hours your staff spend computing results per term, average staff hourly cost. Output: hours and ₦ saved per term. **Show the formula and assumptions on the page.** It must not be a black box producing flattering numbers (spec §14's "saves ₦X" done honestly).

---

## 11. Conversion paths

### 11.1 CTA hierarchy

| Priority | CTA | Destination | Where |
|---|---|---|---|
| Primary | **Chat on WhatsApp** | `wa.me/<number>?text=<prefilled + ref code>` | Nav, hero, every CTA band, mobile sticky bar |
| High intent | **Book a demo** | `/demo` | Nav, hero, solution pages, pricing |
| Low commitment | **Try the demo school** | `demo.schoolpilot.org.ng` | Hero link, feature pages |
| Onboarding | **Get started** | `/start` | Pricing, /migrate, final CTA |
| Existing users | **Login** | `/schools` | Nav |

**Mobile sticky bar:** two buttons (WhatsApp · Demo), appears after the hero scrolls out and hides while the keyboard is open or the footer is visible.

### 11.2 WhatsApp with attribution (new)

WhatsApp conversations are invisible to web analytics. Fix:

1. On CTA click, generate a short ref code (e.g. `SP-7KQ2`) client-side and `POST /api/v1/public/leads/touch` with `{ref, utm_*, landing_page, referrer}` (no personal data).
2. Prefill: *"Hello SchoolPilot, I'd like to learn more for my school. (Ref: SP-7KQ2)"*
3. When sales opens a lead in the console from a WhatsApp chat, they paste the ref, which joins the conversation to its source, campaign and pages.

### 11.3 Book a demo form (`/demo`)

v1.0 had 10 fields. Long forms lose leads on mobile, and NDPA asks for data minimisation. v2.0:

| Field | Required | Notes |
|---|---|---|
| Full name | ✔ | |
| Phone (WhatsApp) | ✔ | Accept `080…`, `+234…`, `234…`; normalise to E.164 |
| School name | ✔ | |
| Your role | ✔ | Proprietor/Director · Principal/Head · Admin · Bursar · ICT/Exams · Teacher · Other |
| Number of students | ✔ | **Bands**, not a free number (maps to pricing bands) |
| Sections | – | Multi-select chips: Nursery · Primary · JSS · SS |
| Email | – | Optional; many owners prefer WhatsApp |
| Preferred contact | – | WhatsApp (default) · Call · Email |
| What do you use today? | – | Paper · Excel · Another portal · Mixed |
| Message | – | |
| Consent | ✔ | *"SchoolPilot may contact me about this request."* Links to /privacy. Not pre-ticked. |

Submission flow: client validation → server validation → spam checks (§14) → create lead → **WhatsApp + email confirmation to the visitor** → notify sales (console + WhatsApp/Slack/email to on-call) → success state with **next step and expected response time** → optional *"Pick a time"* calendar link.

**Response SLA:** first human response **within 15 minutes, 8am–6pm WAT, Mon–Sat**, otherwise by 9am next working day. The success screen states this honestly.

### 11.4 Get started (`/start`): assisted, not self-serve (Phase 1)

v1.0's flow (create account → verify → setup → … → start using) assumes self-serve tenant provisioning. That doesn't exist yet, and building it means changing auth and provisioning (a CLAUDE.md hard rule: explain the risk first). Phase 1 instead:

```text
/start form (demo fields + preferred subdomain + "what to migrate")
→ Lead created with intent=onboard
→ Team provisions tenant + runs free migration (≤ 24 h)
→ Admin receives login on WhatsApp + email; 2FA enrolment on first login
→ Setup wizard (exists: web/src/app/setup-wizard) takes over in-app
```

Self-serve provisioning moves to **Phase 3**, after the assisted flow has shown where schools get stuck.

### 11.5 Migration page (`/migrate`)

Dedicated page for the strongest switching lever: what we migrate (students, guardians, classes, staff, past results via `HistoryImportBatch`, fee balances), from where (Excel/CSV, paper via template, other portals), how long (≤ 24 h), what it costs (nothing), downloadable import templates, and the reverse promise: one-click export.

---

## 12. Lead management

Build into the **Super Admin console** (`web/src/app/super-admin`, `docs/platform-console.md`). Don't buy a CRM for Phase 1.

### 12.1 States

```text
New → Contacted → Qualified → Demo scheduled → Demo done → Onboarding → Live (converted)
                     ↘ Nurture (not now)        ↘ Lost (reason required)
```

v1.0's "Trial" state is dropped: the free core makes "trial" meaningless. A school is either onboarding or live.

### 12.2 Lead record

`source`, `ref_code`, `utm_source/medium/campaign/content/term`, `landing_page`, `first_seen_at`, `pages_viewed_count`, `demo_school_visited`, `role`, `student_band`, `sections[]`, `current_method`, `contact_preference`, `status`, `owner` (sales rep), `next_action_at`, `notes`, `lost_reason`, `consent_at`, `consent_ip`.

### 12.3 Lead scoring (simple, explainable)

+3 proprietor/director · +2 principal/bursar · +2 band ≥ 201 students · +2 visited demo school · +2 viewed pricing · +1 per feature page (cap 3) · +3 came via /migrate. **Hot ≥ 8** pings sales immediately.

### 12.4 Retention

Unconverted leads are deleted (or anonymised to aggregate stats) **12 months after last contact**. This is stated in the privacy notice. Converted leads become customer records under the school contract.

---

## 13. Trust page (`/trust`)

Written for proprietors **and** for parents a school might forward it to. Plain language, every statement true:

- **NDPA 2023.** How we act as data processor for schools, and how parental consent is captured and withdrawn (spec §12).
- **Children's data.** What we collect and why; no biometrics, ever; sensitive health fields encrypted at field level.
- **AI and your data.** Which features use AI (comments, lesson plans, tutor), that these send limited data to an AI provider outside Nigeria, the lawful basis we rely on, and that **AI-drafted comments never reach a report card without teacher approval**. *Don't publish this section until the cross-border transfer documentation in `LAUNCH.md` is confirmed.*
- **Access control.** Roles, custom roles, 2FA for admins, audit logs, login history.
- **Reliability.** Backups and restore testing (only after the first restore drill, §2.2).
- **Leaving.** One-click export of all your school's data.
- **Contact.** Data protection contact email (DPO once appointed; spec §12 notes education triggers "major importance" status).

---

## 14. Engineering requirements

### 14.1 Architecture

- **Separate app:** `site/` (new Next.js app in the monorepo) on the apex domain `schoolpilot.org.ng`. **Do not** add marketing routes to `web/`: the root of `web/` is the authenticated dashboard (`'use client'`), and v1.0 §42 rightly forbids loading the app bundle on the public site.
- **Static by default:** every page SSG; ISR only for `/api/v1/public/stats`-backed blocks. Deployable to any CDN; no runtime coupling to the backend except form posts and stats.
- **Shared brand tokens** from `web/public/brand` and `mobile/assets` (logo, mark), not copied by hand.
- **Content:** MDX files in `site/content/` for Phase 1–2 (reviewed via PRs, zero cost, versioned). Move to a headless CMS (v1.0 §35) **only** when non-developers are publishing weekly. Before that, a CMS adds cost without benefit.

### 14.2 Backend endpoints (versioned, per CLAUDE.md)

| Method | Route | Purpose | Protection |
|---|---|---|---|
| POST | `/api/v1/public/leads` | Demo / start / contact submissions | Throttle 5/min/IP, 20/day/IP; honeypot; Turnstile token; server-side validation |
| POST | `/api/v1/public/leads/touch` | Anonymous attribution touch for WhatsApp ref codes | Throttle; no PII accepted |
| GET | `/api/v1/public/stats` | Thresholded aggregate counts | Cached 1 h; aggregates only |
| GET | `/api/v1/public/schools/resolve?slug=` | Exact-match subdomain check for `/schools` | Throttle; returns `{exists, login_url}` only |

Each ships with feature tests (validation, throttling, honeypot rejection, phone normalisation, threshold hiding, no enumeration). The admissions public endpoints (`/public/admissions/*`) are the existing pattern to follow.

### 14.3 Performance budgets (hard, CI-enforced)

| Metric | Budget |
|---|---|
| LCP (Slow 4G, mid-range Android) | ≤ 2.5 s |
| INP | ≤ 200 ms |
| CLS | ≤ 0.05 |
| JS shipped on home (gzip) | ≤ 90 KB |
| Total home weight, first load | ≤ 800 KB |
| Fonts | ≤ 2 families, subset, `font-display: swap`, self-hosted |
| Images | AVIF/WebP, explicit `width/height`, lazy below the fold |
| Video | Never autoplay on mobile; poster + tap-to-load |
| Third-party scripts | 0 at launch, except analytics (≤ 2 KB) and Turnstile on form pages only |

Lighthouse CI runs on every PR to `site/`; a budget regression fails the build.

### 14.4 SEO

v1.0 §32–34 stands, plus:
- **Structured data:** `Organization`, `SoftwareApplication` (no `aggregateRating` until real reviews exist), `FAQPage`, `BreadcrumbList`.
- **Open Graph images** generated per page at build time from a branded template.
- **`hreflang="en-NG"`**, `lang="en-NG"` on `<html>`.
- **Search intent we will capture that v1.0 missed:**
  - *"how to compute class position", "broadsheet template excel", "CA and exam ratio", "WAEC grading system", "report card comment for students"*. Answer these with genuinely useful guides plus free downloads (Phase 3).
  - *"<school name> result checker" / "check school result online"*. Parents search this. Route them via `/schools` to their school's result checker. It's goodwill traffic and brand exposure, not leads; keep it out of the conversion funnel metrics.
- Tenant subdomains are `noindex` except their public admissions form, which schools may choose to index.

### 14.5 Accessibility (WCAG 2.1 AA, per v1.0 §41)

Additional concrete rules: minimum body text 16 px (many proprietors read on phones in bright light); amber `#FEAE2C` is **never used for text on white** (fails contrast) but only as a fill behind navy text; focus rings use a 2 px navy + amber double outline; all screenshots have alt text describing the *outcome shown*, not "screenshot"; `prefers-reduced-motion` disables timeline animation.

### 14.6 Security (v1.0 §43, made concrete)

Server-side validation mirrors client rules. Honeypot plus Cloudflare Turnstile (privacy-friendly, works without heavy JS). Throttling per §14.2. Strict CSP on `site/` (no inline scripts except hashed JSON-LD). No API keys in the client. CORS on `/public/*` limited to the apex origin. Lead PII is encrypted at rest and visible only to platform roles with lead permission.

---

## 15. Analytics

- **Tool:** cookieless, privacy-friendly (self-hosted Plausible or Umami). **No cookie banner needed**, no ad pixels at launch. If paid ads start later, add pixels behind a real consent prompt.
- **Events:**

| Event | Properties |
|---|---|
| `cta_click` | `cta` (whatsapp/demo/start/demo_school/login), `location` (nav/hero/band/sticky/footer), `page` |
| `whatsapp_open` | `ref_code`, `page` |
| `form_start` / `form_submit` / `form_error` | `form`, `field` (for errors), `error_type` |
| `demo_role_selected` | `role` |
| `pricing_band_viewed` | `band` |
| `faq_open` | `question_id` |
| `scroll_depth` | 25/50/75/100 on home and solution pages |
| `video_play` | `video_id` |
| `sample_download` | `asset` (report_card/broadsheet/template) |

- **North-star KPI:** **qualified schools onboarded per month** (v1.0's "qualified school conversion rate" is kept as the ratio).
- **Funnel:** Visitor → Engaged (≥ 2 pages or demo school) → CTA click → Lead → Qualified → Demo done → Onboarding → Live.
- **Leading indicators:** WhatsApp first-response time, demo-school visit → lead rate, form completion rate on mobile.

---

## 16. Visual design (v1.0 §38 stands)

Palette: Deep Navy `#000E22`, Dark Blue `#002447`, Amber `#FEAE2C`, Light Amber `#FFC16A`, White. Additions:

- **Product-first:** every section's visual is real UI from the demo tenant. Screenshots are **regenerated automatically** each release (Playwright script against the seeded demo tenant), so marketing never shows a stale or mocked UI.
- **People imagery:** if used, licensed photography of Nigerian classrooms and staff. **No identifiable children** in Phase 1, even with consent; it's simpler and safer under NDPA.
- **Phone frames** for parent/teacher/student content, since those roles live on mobile.
- **Data-cost-aware:** illustrations as inline SVG, not raster.

---

## 17. A/B testing

Only once traffic supports it (≈ 1,000+ weekly visitors to the tested page). Before that, tests won't reach significance. Priority:

1. Hero headline (control vs. the three variants in §3.3)
2. Primary CTA: "Chat on WhatsApp" vs. "Book a demo" as the filled button
3. Demo form: required-5 vs. required-3 (name, phone, school)
4. Pricing-model block on home: present vs. absent

Measure against **qualified leads**, never clicks alone.

---

## 18. Campaign calendar (Nigerian academic year)

| Window | Moment | Campaign page | Hook |
|---|---|---|---|
| **Jun–Sep** | Session planning; biggest switching window | `/campaigns/new-session` | "Start the new session on SchoolPilot. We migrate everything free before resumption." |
| **Oct–Nov** | First-term exams approaching | `/campaigns/result-management` | "Make this the last term you compute results by hand." |
| **Nov–Dec** | First-term results crunch | `/campaigns/report-cards` | Sample report card download + migration offer |
| **Jan–Mar** | WAEC/NECO/BECE preparation | `/campaigns/cbt` | Offline CBT practice for SS3/JSS3 |
| **Mar–Apr** | Second-term results | `/campaigns/result-management` (reuse) | |
| **Jul** | Third-term results + promotion | `/campaigns/new-session` (start early) | Session rollover |

Campaign pages follow v1.0 §48 (one objective, minimal nav, attribution). Note: today is **October 2026**. The first realistic campaign is **result-management for first-term 2026/2027**. The big push is **new-session, June–September 2027**.

---

## 19. Error, empty and edge states

- **404:** *"We couldn't find that page."* Shows a search of site pages plus **Back to SchoolPilot** plus WhatsApp.
- **Form errors:** inline, next to the field, in words (*"Enter a Nigerian phone number, e.g. 0803 123 4567"*). An error summary at the top is announced to screen readers. **Input is never cleared on error.**
- **Submission failure / offline:** keep the data in the form, show *"We couldn't send this. Check your connection and try again, or message us on WhatsApp"* with the prefilled WhatsApp link containing their details (this rescues leads when the network drops).
- **Success:** *"Thanks, [first name]. We've received your request. We'll message you on WhatsApp within 15 minutes during working hours (8am–6pm, Mon–Sat)."* plus *"While you wait: try the demo school."*
- **`/schools` not found:** *"We couldn't find that school address. Check the spelling, or ask your school for its SchoolPilot link."* Never suggest similar names (that leaks tenant existence).

---

## 20. Footer

**Product:** Features · Pricing · Demo school · Migration · Security & trust
**Solutions:** Owners · Administrators · Bursars · Teachers · Parents · Students
**Sections:** Nursery · Primary · JSS · SS
**Company:** About · Contact · FAQ
**Legal:** Privacy · Terms · Data policy
**Contact block:** WhatsApp number · email · office address (real, verified)
**Social:** verified official accounts only (v1.0 rule).

---

## 21. Phasing (revised)

### Phase 1a: Launch (target: 3 weeks; aligned with the first-term results season)

Home · Pricing (model) · `/demo` · `/start` · `/migrate` · `/trust` · `/schools` · About · Contact · FAQ · Privacy · Terms · 404
Feature pages: **Results & Report Cards**, **CBT**, **Fees & Finance**, **Student Management**
Solution page: **School Owners**
Backend: leads, touch, resolve endpoints + console lead list
Analytics + performance budgets in CI

*Why these four features:* they're the money moments (results, fees) and the pain moments (results, CBT), and results is where we charge.

### Phase 1b (+3 weeks)

Demo school public with role switcher · Academics · Attendance · HR & Payroll · Admissions · Result Checker · Solutions: Administrators, Bursars · Sample PDFs

### Phase 2

Teachers · Parents · Students · School-section pages · Teaching Tools · AI Tutor · Virtual Classes · Communication · Boarding · Library · Transport · ID Cards · ROI calculator · Founding Schools case studies · live stats (once thresholds met)

### Phase 3

Resources (guides, templates, blog) · campaign pages · self-serve provisioning · headless CMS (if justified) · A/B testing · comparison pages (only factual, about publicly verifiable competitor features)

---

## 22. Acceptance criteria

v1.0 §51 criteria stand. These are added or made measurable:

- [ ] Every claim on every page maps to a row in §2.1 or a verified row in §2.2. Reviewed before each release.
- [ ] Nothing in §2.3 appears anywhere on the site.
- [ ] A first-time visitor on a phone can state what SchoolPilot is and who it's for after the hero. Tested with 5 real school staff (≥ 4/5 pass).
- [ ] WhatsApp CTA is reachable within one tap from any scroll position on mobile.
- [ ] Demo form: required fields ≤ 5 + consent; Nigerian phone formats accepted; data preserved on failure.
- [ ] Leads appear in the Super Admin console within 10 s, with UTM, landing page and ref code.
- [ ] WhatsApp ref code joins a WhatsApp conversation to its web session in the console.
- [ ] `/schools` resolves exact slugs only and cannot be used to list tenants.
- [ ] Public endpoints are versioned under `/api/v1/public/*`, throttled, and covered by feature tests that pass in `php artisan test`.
- [ ] Performance budgets (§14.3) enforced in CI and met on Phase 1a pages.
- [ ] Zero cookies set by `site/` without consent; no third-party ad pixels.
- [ ] All screenshots come from the demo tenant; no real names, photos or records.
- [ ] `/trust` AI section published only after cross-border transfer documentation is confirmed.
- [ ] Pricing shows no number until final prices are approved in writing.
- [ ] Lighthouse accessibility ≥ 95 and a manual keyboard + screen-reader pass on Home, Demo and Pricing.

---

## 23. Risks

| Risk | Mitigation |
|---|---|
| Site over-promises versus product → churn and reputational damage in a word-of-mouth market | Claims register (§2), release review |
| WhatsApp response is slow → leads go cold | 15-min SLA, on-call rota, hot-lead pings, auto-acknowledgement message |
| Demo school abused (spam, offensive content) or leaks real data | Nightly reset, outbound disabled, separate tenant, content filter on free-text fields |
| Free-core message attracts schools that never pay | Lead scoring by band, pay-at-results model, qualification in sales |
| AI cross-border statement is wrong → NDPA exposure | /trust AI section gated on legal confirmation |
| Marketing app drifts from product (stale screenshots) | Automated screenshot generation per release |
| Competitor comparison pages invite legal disputes | Phase 3 only; factual, sourced, dated |

---

## 24. Open decisions (owner sign-off needed)

1. **Official WhatsApp Business number** and staffing for the 15-minute SLA.
2. **Final prices per band.** Until then, the pricing page shows the model only.
3. **Founding Schools offer:** cohort size and benefit.
4. **Whether the demo school's AI features use live AI** (with a demo budget cap) or canned responses.
5. **Brand domain for marketing:** apex `schoolpilot.org.ng` assumed (matches `LAUNCH.md` wildcard DNS).
6. **Data Protection contact** to publish on /trust and /privacy.
7. **Legal review** of Privacy, Terms, Data Policy and the AI-transfer statement before launch.

---

## 25. Final principle

The site must feel like the front door of a product that **already understands a Nigerian school**: its terms, its broadsheets, its fee season, its unreliable power and its WhatsApp-first relationships. It shouldn't feel like a generic SaaS template with "Nigeria" pasted in.

**Trust + Simplicity + Product depth + Nigerian relevance + Conversion**, in that order. Trust comes first because, in this market, one fabricated claim found out costs more than every lead it ever won.
