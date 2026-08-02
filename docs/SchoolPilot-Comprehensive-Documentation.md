# SchoolPilot — Product & Technical Documentation (v1)

*Working title only — swap in your real product name throughout. This document is self-contained: it folds in everything from the earlier IntelS teardown and Unified Blueprint, filtered through one hard constraint — no biometrics, no special or dedicated hardware. Every module below runs on a standard smartphone camera/GPS, a computer, and a browser. Nothing else to buy, install, wire in, or maintain.*

---

## 1. Introduction & Vision

### 1.1 What this is
A cloud-based school management platform for private K-12 schools in Nigeria — one login covering student records, results, fees, computer-based testing, and AI-assisted teaching and learning. It's built to run entirely on devices schools, staff, and parents already own.

### 1.2 Purpose
Digitize teaching, learning, administration, communication, and examinations while staying usable in low-bandwidth, low-hardware-budget environments — the norm for most private schools in this market, not the exception.

### 1.3 Objectives
- Cut the admin load of result processing, fee tracking, and timetabling from days to minutes.
- Give every teacher an AI assistant for lesson prep and grading feedback.
- Give every student a 24/7 tutor tied to what their own teacher actually assigned.
- Give every parent real-time visibility into attendance, fees, and academic performance.
- Do all of this without asking a school to buy a single piece of equipment.

### 1.4 Curriculum Alignment
- **Phase 0**: WAEC, NECO, JAMB/UTME CBT standards
- **Phase 1**: + NABTEB (National Business and Technical Examinations Board)
- **Phase 1**: + Cambridge, Montessori (optional tagging, for international-curriculum private schools)

---

## 2. Design Principles

Every feature decision gets checked against these:

1. **Software-only.** If it can't run on a standard smartphone camera, a phone's built-in GPS, or a browser, it doesn't ship. No purchased scanners, readers, sensors, or wearables — ever. Full detail in §3.
2. **K-12, Nigeria, day schools first.** University-level structures (faculties, semesters) and boarding-specific modules (hostel, health) are real needs but secondary — they wait for Phase 2, not because they're bad ideas, but because serving every segment at once serves none of them well.
3. **Phased, not flat — and piloted before broadened.** Every module below is tagged Phase 0/1/2. Phase 0 is what has to exist before opening sign-ups broadly — but even inside Phase 0, get one real school onto a narrower slice first (SIS, academic structure, attendance, fees, basic results) before building out CBT, timetable auto-generation, and the AI modules. Real usage from one school sharpens those better than building them speculatively.
4. **Monetize at the moment of value**, not by seat count. Schools pay when they generate results or run premium CBT exams — the moment the manual alternative is most painfully obvious — not a flat fee regardless of use.
5. **WhatsApp-first sales and support.** No self-serve signup funnel to build for Phase 0; a human responds within minutes on WhatsApp instead.
6. **NDPA-first compliance.** Nigeria's Data Protection Act, 2023 is the real target — not a generic GDPR badge — with extra care because the primary data subjects are children.
7. **Free-forever core.** Most modules carry no charge, ever. The paid features are the deliberate minority.

---

## 3. Explicitly Out of Scope: Hardware & Biometrics

This is a hard boundary for the project, not a "maybe later." If it needs a receiver, a reader, a sensor, or a headset, it isn't part of this product.

**Excluded entirely:**
- Facial recognition, for attendance or anything else
- Fingerprint / biometric scanners
- RFID readers and RFID-enabled ID cards
- Dedicated barcode-scanner guns
- Dedicated vehicle GPS-tracker units
- IoT classroom sensors, smart boards, digital bell systems, smart lockers, voice assistants, environmental/energy monitoring
- VR/AR headsets and VR/AR classrooms
- Any "smart building" hardware line

**Why:** most of the target market — private K-12 schools running on tuition income, often across campuses with inconsistent power and internet — has no budget or appetite for hardware procurement, installation, or maintenance. Every excluded item above also turns "sign up on WhatsApp, live in 30 minutes" into a multi-week hardware rollout, which breaks the low-friction onboarding this kind of product depends on to spread. If a genuine government or institutional customer ever wants a hardware-backed "smart school" offering, that's a different business with different unit economics — not a phase of this roadmap.

**What replaces each assumption:**

| You might assume this needs hardware | What this product uses instead |
|---|---|
| Attendance tracking | QR code scanned by the app's own camera view, or manual mark by a teacher/gatekeeper |
| Staff clock-in verification | The staff member's own phone GPS, read through the app they already use to log in |
| Bus tracking | The driver's phone, running the same app, broadcasting location while a route is active |
| Library check-in/out | Phone camera scanning a printed barcode on the book, or plain manual entry |
| ID cards | A generated, printable PDF design — printed at any print shop or office printer the school already uses; not an access-control credential |
| "Smart" classroom features | Live video/whiteboard through the browser (Zoom/Meet integration), not installed smartboards or sensors |
| Offline exam days | A downloadable desktop app running on the school's existing lab/office computers — no new equipment, just software that works without internet |

---

## 4. Target Market & Positioning

**Primary segment:** private K-12 day schools in Nigeria, roughly 50–3,000 students, currently running on paper, Excel, or a rival portal.

**Positioning statement:** *"Everything a school's front office, classroom, and finance office need in one login — free for almost all of it, and you only pay the moment you'd otherwise be paying a person to do it manually."*

**What this borrows from the market, and what it deliberately skips:**
- Borrows the proven wedge from the live competitor researched during discovery: freemium core, WhatsApp-first sales, pay-at-result-generation, free 24-hour migration from whatever the school uses today.
- Borrows a richer feature vocabulary from a broader LMS specification also reviewed during discovery: deeper student-record fields, wider curriculum coverage, gamification, boarding-school modules — sequenced by phase rather than built all at once.
- Skips the "serve every segment including hardware and university-level academics" scope that neither source actually proved was necessary to win.

---

## 5. User Roles & Permissions

| Role | Manages |
|---|---|
| Super Admin *(platform operator — your team)* | Schools, subscriptions, platform-wide settings, AI service configuration, platform security, cross-school reporting |
| School Admin | Teachers, students, parents, subjects, classes, timetables, fees, attendance, results, CBT |
| Teacher | Notes and lesson content, attendance, grading, assignments, AI content generation |
| Student | Class access, assignments, CBT, results, AI tutor, discussion |
| Parent | Attendance, fees, results, notifications, teacher chat |

---

## 6. Role Dashboards

- **Admin:** student population, revenue, attendance rate, CBT stats, pending approvals, AI insight alerts (grade drops, revenue forecast, attrition risk)
- **Teacher:** today's classes, attendance, assignments due, upcoming exams, class performance snapshot, messages
- **Student:** timetable, homework, attendance, subjects, performance graph, badges, upcoming tests
- **Parent:** child's attendance, fees due, academic progress, notifications, school events

---

## 7. Phase 0 Modules (MVP — what has to exist to launch)

### 7.1 Multi-Tenancy & School Setup
Subdomain-per-school (`school.yourplatform.com`), isolated tenant data, guided 30-minute setup (subjects, fees, classes) designed to need no formal training.

### 7.2 Student Information System
Biodata, passport photo, birth certificate reference, parent/guardian info (supports multiple guardians), emergency contact, state of origin, LGA, religion, blood group, allergies, medical notes, previous school. All optional-but-supported fields — cheap to store, expected on any real Nigerian school registration form.

### 7.3 Staff / HR Management
Profiles, qualifications, salary/payroll, leave allocation, employment history, class/subject assignment.

### 7.4 Academic Structure
Sessions, terms, classes, arms/streams, promotion and repeat/transfer workflows. K-12 only (nursery through SS3) — no faculties/departments/semesters in Phase 0.

### 7.5 Attendance — hardware-free
QR code scan (via the app's own camera, no reader device) or manual entry for students; phone-GPS check-in for staff. No RFID, no biometric, no facial recognition.

### 7.6 Timetable Engine
Constraint-based auto-scheduling — generates a conflict-free master timetable in seconds, respects teacher-break constraints, can re-optimize if a teacher's availability changes. This is a genuine constraint-satisfaction problem (solvable with an existing CSP/scheduling library) and worth real engineering investment; it's one of the strongest low-cost differentiators available.

### 7.7 Assessment & Result Processing
Configurable continuous-assessment schemes (schools each weight CA vs. exams differently — this must be flexible, not fixed), automatic grade computation and class ranking, AI-generated personalized teacher remarks held in a **pending-approval state until a teacher reviews and approves or edits them** — an AI-written comment should never reach a printed report card unreviewed, since a hallucinated or oddly-worded remark about a specific child is a real reputational and legal risk — branded PDF report cards with a QR code for instant authenticity verification, whole-class broadsheet views, Excel import and export (bulk score entry, not just single-student forms).

### 7.8 CBT / Testing Engine
Online computer-based testing aligned to WAEC/NECO/JAMB formats: question randomization, timed delivery, instant objective grading, topic-wise performance analytics. Includes a downloadable offline exam client for exam days without reliable internet — runs on the school's existing lab computers, locks the exam session, queues results to sync once reconnected. No new hardware; this is a software client only.

### 7.9 Question Bank & Curriculum Coverage
Unlimited bank tagged by subject, topic, and difficulty; WAEC/NECO/JAMB alignment from day one; import/export support.

### 7.10 AI Studio — Teacher Tools
AI-generated lesson plans (objectives, activities, assessment strategy, curriculum-aligned), worksheet and quiz generation with answer keys, save-and-reuse templates, batch generation of a week's materials at once. Built on an LLM API with curriculum context injected into the prompt — the most replicable AI feature in this spec, and a strong, cheap demand driver.

### 7.11 AI Learning Hub — Student Tutor
24/7 conversational tutor in English, tied to what the student's own teacher actually assigned (not a generic public chatbot), personalized learning paths that adjust with progress, topic mastery tracking, achievement badges.

### 7.12 Fees & Finance
Flexible fee-structure builder, automated invoicing, multi-channel payment logging (cash, bank transfer, online gateway), expense tracking, defaulter/debtor dashboard, receipt and invoice printing. Payment gateways: Paystack and Flutterwave (covers most private schools).

### 7.13 Communication & Notifications
Push notifications, SMS (paid add-on for high-volume use), WhatsApp-based support funnel.

### 7.14 Analytics & Insights
Rule-based (not machine-learning) alerts to start: grade-drop flags, revenue forecasting from fee-payment patterns, simple attrition-risk signals. Start simple; there's no evidence this market needs anything more sophisticated at launch.

### 7.15 Security & Compliance Basics
Encryption at rest and in transit, strict tenant data isolation, two-factor authentication, audit logs, daily automated backups. NDPA-aligned from day one (see §12).

### 7.16 Mobile & Web Apps
One cross-platform mobile app for every role (admin/teacher/parent/student), role-based navigation and permissions — not four separate apps. Web admin portal for staff-heavy data entry (bulk result entry, finance reports); mobile for day-to-day consumption.

---

## 8. Phase 1 Modules (Retention & Polish)

- **Assessment enhancements:** rubric-based grading, peer review on assignments
- **CBT question-type expansion:** drag-and-drop, diagram labeling, coding questions, math equations, negative marking
- **Curriculum expansion:** NABTEB tagging, Cambridge/Montessori tagging
- **Multilingual AI tutor:** Yoruba, Hausa, Igbo, French translation support — validate translation quality with real classroom feedback before marketing it heavily
- **Fees enhancements:** scholarships, discounts, installment plans
- **In-app chat and discussion forums**
- **Gamification:** points, streaks, leaderboards, certificates (beyond the basic badges shipped in Phase 0)
- **Course/content uploads:** PDF, video, audio attached to lessons and assignments
- **HR enhancements:** performance appraisal, certificate tracking

---

## 9. Phase 2 Modules (Segment Expansion — boarding schools & beyond)

### 9.1 Transportation — hardware-free
GPS bus tracking using the *driver's own phone* running the app, broadcasting location only while a route is active — no vehicle unit to purchase or install. High parent-facing visibility ("where's the bus") even at day schools; consider building this before the rest of Phase 2.

### 9.2 Library — hardware-free
Digital catalog, borrowing and due-date tracking, reservations. Barcode lookups happen via the app's phone-camera scanner (software), not a dedicated scanner gun.

### 9.3 Hostel / Boarding
Room and bed allocation, visitor logs, maintenance tickets, hostel-specific fees.

### 9.4 Health / Clinic
Medical records, clinic-visit logging, vaccination tracking, incident reports (emergency contacts already live in the core SIS).

### 9.5 Live / Virtual Classrooms
Integrate Zoom/Google Meet for schedule and attendance sync rather than building a custom video/whiteboard stack — cheaper, faster, and proven, versus uncertain daily demand for an in-house build.

### 9.6 Full International-Curriculum Support
Beyond simple tagging: a complete Cambridge/Montessori academic-structure option for schools running a dual curriculum.

---

## 10. Data Model Overview

| Domain | Key entities |
|---|---|
| Tenancy & identity | schools, users, roles, staff, students, guardians (many-to-many for split families) |
| Academic structure | sessions, terms, classes, arms, subjects, class-subject-teacher assignments |
| SIS | student core record + `state_of_origin`, `lga`, `religion`, `blood_group`, `allergies[]`, `medical_notes`, `previous_school` |
| Assessment | CA scheme definitions (school-configurable weights), score entries, grade-boundary tables, broadsheets, report-card templates, QR verification tokens |
| Testing / CBT | question bank (topic/difficulty tags), exam definitions, exam sessions, student attempts, offline-sync queue |
| Finance | fee structures, invoices, payments (multi-channel), expenses, defaulter tracking |
| AI content | lesson-plan generations, worksheet generations, generated comments (with an audit trail linking each comment to the score data it was generated from), tutor conversation history, per-student learning-path state |
| Operations | attendance records, timetable constraint sets + generated timetable versions, notification/SMS logs |
| Phase 2 additions | `books`/`copies`/`borrow_records` (library), `rooms`/`beds`/`bed_assignments` (hostel), `buses`/`routes`/`gps_pings` (transport), `clinic_visits`/`vaccination_records` (health) |
| Analytics | pre-aggregated or nightly-ETL tables feeding grade-drop detection, revenue forecasting, attrition-risk scoring — simple statistical rules to start, not deep learning |

**Indexing & integrity:** composite indexes on every tenant-scoped table, at minimum `(school_id, created_at)` and `(school_id, student_id, term_id)` — every query in this system filters by school_id, and unindexed tenant scoping degrades badly as student count grows. Soft deletes (`deleted_at`) on students, staff, and score entries, since academic records have legal and historical value a hard delete shouldn't erase. Application-level encryption (not just at-rest disk encryption) on `blood_group`, `allergies`, and `medical_notes` specifically.

---

## 11. Technical Architecture & Stack

**Multi-tenancy:** subdomain-per-school routing to a tenant-resolution layer; row-level or schema-level tenant scoping in a shared database cluster.

**Recommended stack:**
- **Backend:** Laravel (PHP) as the primary backend — fast for CRUD-heavy admin systems, mature multi-tenancy packages, easy to hire for locally. Node.js reserved for what it's actually best at: real-time pieces (chat, live notifications, WebSocket-driven features), not the whole backend.
- **Frontend (web):** React / Next.js for the admin portal.
- **Mobile:** Flutter, one codebase, role-based navigation — matches the "one app for everyone" principle in §7.16.
- **Database:** pick one relational database, not two. Postgres is the stronger default given how relationally complex this gets (finance, academic structure, multi-tenancy).
- **Cache & queues:** Redis — report generation, PDF rendering, and bulk result compilation should run as background jobs, not block a web request.
- **Object storage:** one provider (S3 or equivalent) based on where you're already hosting.
- **Offline sync:** a deliberate local-first strategy, and not just for the offline CBT client. The desktop exam client needs local SQLite with a queued sync-on-reconnect job — but the Flutter app itself needs the same pattern for attendance marking and timetable reads, since teachers and students will hit connectivity gaps in ordinary daily use, not just on exam day. "The app happens to work offline" isn't a strategy in either case.
- **API versioning:** every backend route under `/api/v1/...` from the first deploy. This is a multi-client product (web + mobile); an unversioned breaking change on the backend has no way to avoid breaking installed mobile apps.
- **Scaling later, not day one:** composite indexing (see §10) is cheap and belongs in the first migrations. A read replica or a separate reporting/analytics database for end-of-term result-compilation load is good practice — but provision it when real usage shows the primary database straining, not preemptively for a scale you don't have yet.

---

## 12. Security, Privacy & Compliance

*(General information, not legal advice — get a proper legal review once you're handling live student data.)*

- **NDPA (Nigeria Data Protection Act, 2023)** is the real regulatory target, enforced by the Nigeria Data Protection Commission — not a generic GDPR badge, which is largely irrelevant unless you're processing EU residents' data.
- Because the primary data subjects are **children**, NDPA's protections around children's and sensitive personal data apply with extra weight. Build parental consent into SIS registration specifically: a guardian checks a consent statement with a timestamp and IP logged, and there's a way to withdraw consent later that doesn't break the academic record-keeping the school is separately obligated to retain.
- **Cross-border data transfer applies to the Claude API calls in §7.10/§7.11, and needs a documented answer, not an assumption.** Under NDPA §§41 and 43 (and the 2025 General Application and Implementation Directive), transferring personal data outside Nigeria is prohibited by default unless the receiving country has an adequacy determination, the transfer is covered by NDPC-approved safeguards (contractual clauses, binding corporate rules), or the data subject has given explicit, specific, revocable consent. Every AI Studio and AI Learning Hub call sends student data to a US-hosted API, which triggers this. Confirm Anthropic's current data-processing terms cover it, and document the transfer per NDPA's requirements — "the AI provider is reputable" isn't the same thing as a documented lawful transfer basis.
- **"Education" is one of NDPA's named sectors for "data controller/processor of major importance" status**, and the general threshold for that status is processing over 200 data subjects in six months — a single mid-sized school clears that almost immediately. Major-importance status brings a mandatory Data Protection Officer and annual compliance-audit filings. Plan for this as a near-term cost, not a someday one.
- Document a data retention and deletion policy explicitly, before a school or parent asks: how long a graduated student's record is kept, and how a parent's deletion request is handled when academic records (results, attendance) must legally be preserved even if other fields (contact details, medical notes) can be removed.
- **A direct benefit of the no-biometrics decision in §3**: biometric data is treated as a stricter category of sensitive personal data under most data-protection frameworks, NDPA included. By not collecting it at all, this product sidesteps an entire tier of consent and security obligations that a biometric-attendance competitor has to solve for — one less compliance burden, not just a hardware-cost saving.
- Baseline controls: encryption at rest and in transit, **plus application-level encryption on individual sensitive fields** (`blood_group`, `allergies`, `medical_notes`) so they're unreadable even from a raw database dump, not just protected by disk-level encryption. Strict tenant isolation, 2FA, audit logs, daily automated backups with a genuinely tested restore process, global API rate limiting (not only on the AI endpoints), defined data-export path (so a school can always leave with its own data — a trust signal worth stating explicitly in your own marketing, as it is for the products this spec drew on).

---

## 13. Monetization & Subscription Model

**Model, not exact numbers:** price by student-count band, billed per term (with a discount for paying per full session/year), and charge specifically at the moment of **result generation** and **premium CBT use** — not as a flat monthly SaaS fee. This aligns your revenue with the school's own fee-collection cycle and ties payment to the single moment the manual alternative is most painfully obvious.

**Illustrative band structure** *(placeholder numbers — model these against your actual infrastructure, AI-API, and support costs; don't copy a competitor's price list directly)*:

| Band | Student range | Illustrative price/term |
|---|---|---|
| Starter | 1–50 | ₦X |
| Growth | 51–200 | ₦X × ~2.5 |
| Standard | 201–500 | ₦X × ~5 |
| Premium | 501–1,000 | ₦X × ~8 |
| Enterprise | 1,001–2,000 | ₦X × ~13 |
| Custom | 2,001+ | Negotiated |

**What stays free regardless of student count:** SIS, HR, timetable engine, fees tracking, attendance, AI Studio, AI Learning Hub, front-desk/communication tools — everything except result-generation and premium CBT grading.

---

## 14. Go-To-Market Strategy

- **WhatsApp-first funnel:** every call-to-action routes to a pre-filled WhatsApp message to a human, with a fast response commitment. No self-serve checkout to build for launch.
- **Free, fast migration:** offer to import a school's existing records (from Excel or a rival portal) within 24 hours at no cost — this is the single strongest lever against switching friction.
- **ROI-framed marketing:** state a "saves ₦X/year" figure next to every free feature — reframes the feature list as a financial pitch, not just a spec sheet.
- **Social proof:** a live counter of onboarded schools and student counts, shown on the homepage, builds trust fast in a relationship-driven market.
- **Data portability as a trust signal:** advertise one-click export of all records to Excel — directly answers the "what if we want to leave" objection before it's asked.

---

## 15. Roadmap Summary

| Phase | Focus | Contains |
|---|---|---|
| **Phase 0 — MVP** | Prove the core wedge | Multi-tenancy, SIS, academic structure, results, CBT, fees, AI Studio, AI Learning Hub, timetable, hardware-free attendance, security basics, one mobile app |
| **Phase 1 — Retention** | Polish and engagement | Rubrics/peer review, richer CBT question types, curriculum expansion, multilingual tutor, fee flexibility, chat/forums, gamification, content uploads |
| **Phase 2 — Segment expansion** | Boarding schools & beyond | Transport, library, hostel, health, live classes, full international-curriculum support — all hardware-free |
| *(Excluded entirely)* | *Not part of this roadmap* | Biometrics, RFID, IoT/smart-building hardware, VR/AR — see §3 |

---

## 16. Appendix: Full Feature Checklist

- [ ] Multi-tenant school setup, subdomain routing
- [ ] Super Admin console + 4 school-side roles
- [ ] Full Nigeria-specific SIS field set
- [ ] K-12 academic structure (session/term/class/arm)
- [ ] Configurable CA schemes, auto-computed results, QR-verified PDF report cards
- [ ] AI-generated report-card comments, held for teacher approval before printing
- [ ] Bulk student/result import (Excel/CSV) alongside single-record forms
- [ ] Online CBT (WAEC/NECO/JAMB-aligned) + offline exam client
- [ ] AI Studio: lesson plans, worksheets, quizzes
- [ ] AI Learning Hub: English tutor tied to teacher content
- [ ] Timetable auto-generation (constraint-based)
- [ ] QR/manual student attendance, phone-GPS staff attendance
- [ ] Fee structure builder, invoicing, Paystack/Flutterwave
- [ ] Push notifications + SMS
- [ ] Versioned API (`/api/v1`) from first deploy
- [ ] NDPA-aligned security: encryption (incl. field-level on sensitive data), 2FA, audit logs, tested backups, documented cross-border AI-transfer basis
- [ ] One cross-platform mobile app, role-based
- [ ] WhatsApp-first sales/support funnel
- [ ] Rubrics, peer review, richer CBT question types (Phase 1)
- [ ] NABTEB/Cambridge/Montessori curriculum tagging (Phase 1)
- [ ] Multilingual AI tutor (Phase 1)
- [ ] Scholarships/installments, in-app chat, gamification (Phase 1)
- [ ] Transport, library, hostel, health modules — all hardware-free (Phase 2)
- [ ] Zoom/Meet-integrated live classes (Phase 2)
- [ ] Full international-curriculum support (Phase 2)

---

*This document supersedes the need to cross-reference the earlier competitor teardowns for day-to-day use — it's meant to stand alone as the working spec. Happy to turn any Phase 0 module into a detailed technical PRD, draft the actual database migrations, or write the AI prompt specs for the comment generator and tutor next.*
