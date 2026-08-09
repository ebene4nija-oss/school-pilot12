# SchoolPilot Mobile — Stitch UI Prompt

Prompts for generating the mobile screens in Google Stitch. The design system is
carried over from the existing web reference in
`stitch_schoolpilot_management_system/` so the mobile app and the web portal
read as one product.

**How to use this file**

1. Paste **§1 System Prompt** first. It sets the design system for the session.
2. Then paste one screen prompt from **§3** per generation. Do not batch them —
   Stitch produces sharper output one screen at a time.
3. Keep **§2 Global Rules** in the session; re-paste the relevant bullets if
   Stitch drifts (it usually drifts on currency format and on tab count).
4. Save each output into `stitch_schoolpilot_mobile/<screen_name>/`, matching
   the existing web folder convention.

---

## §1 System Prompt — paste once at the start

```
You are designing a mobile application UI for SchoolPilot, a school management
platform used by private K-12 schools in Nigeria. One app serves four roles:
School Admin, Teacher, Student, and Parent, with role-based navigation.

PLATFORM
Android-first mobile, 360x800dp reference viewport. Mid-range Android hardware
on LCD screens, often used outdoors in bright sunlight. Design for high contrast
and performance, not for visual flourish.

BRAND PERSONALITY
Authoritative yet supportive. "Modern Corporate" with high-contrast
functionalism. The emotional target is calm control: this app reduces the
cognitive load of managing termly assessments, fee arrears and daily registers.
Reject subtle gradients, soft shadows, glassmorphism, blur and transparency.
Use crisp boundaries, legible type and a robust colour hierarchy.

COLOR TOKENS
primary #002447            on-primary #ffffff
primary-container #1b3a5f  on-primary-container #88a4cf
secondary #835500          on-secondary #ffffff
secondary-container #feae2c on-secondary-container #6b4500
tertiary #002b12           tertiary-container #004320
error #ba1a1a              error-container #ffdad6   on-error-container #93000a
background / surface #fcf9f8
surface-container-lowest #ffffff
surface-container-low #f6f3f2
surface-container #f0eded
surface-container-high #eae7e7
surface-container-highest #e5e2e1
on-surface #1b1c1c         on-surface-variant #43474e
outline #74777f            outline-variant #c3c6cf
Semantic: success/paid/present #2e9e5b, danger/overdue/absent #d64545,
warning/late #f5a623.

TYPOGRAPHY
Headings: Work Sans. Body and all UI labels: Inter. Icons: Material Symbols
Outlined.
display-lg-mobile  Work Sans 24px/32px 700
headline-md        Work Sans 20px/28px 600
body-lg            Inter 16px/26px 400
body-md            Inter 14px/22px 400
label-sm           Inter 12px/16px 600, letter-spacing 0.05em, uppercase
data-mono          Inter 14px/20px 500, tabular figures, for all numbers
No functional text below 14px. Generous line height on lists of student names.

SPACING & SHAPE
8px linear scale. Screen margin 16px. Card radius 8px, section radius 16px,
chips fully rounded. Minimum 48x48dp tap target on every interactive element,
even where the visual is smaller. Data tables may drop to 4px internal padding
so CA1 / CA2 / Exam / Total fit without horizontal scrolling.

ELEVATION
Depth via tonal layering and 1px outlines, not shadows.
Level 0 background #fcf9f8. Level 1 cards #ffffff with a 1px #c3c6cf border.
Level 2 (modals, FAB, bottom sheets) a single soft shadow
0px 4px 12px rgba(0,0,0,0.05).

COMPONENTS
- Primary button: amber #feae2c, dark text #1b1c1c, 48dp tall, full width on
  mobile forms.
- Secondary button: primary blue #002447, white text.
- Ghost button: 1px primary blue border, transparent fill.
- Data card: white, 1px outline, and a 4px left border in a status colour
  (green paid / red owing / amber pending).
- Status chip: light tint background with dark text of the same hue. Used for
  JSS1, SS2, Present, Absent, Paid, Overdue, Pending Approval.
- Input: floating label that stays visible after entry; focused state is a 1px
  primary-blue stroke.
- Bottom navigation: 5 items maximum, icon plus 12px label, active item in
  primary blue with a filled icon.
- App bar: 56dp, title in headline-md, at most two trailing actions.
- Empty state: icon, one line of explanation, one action. Never a bare
  "No data".

CONTENT RULES — these are product rules, not styling preferences
- All money is Nigerian Naira. Always render the ₦ symbol, thousands separators
  and 2 decimals, in tabular figures: ₦50,000.00. Never $ or a bare number.
- Academic language is Nigerian K-12: Term (First/Second/Third), Session
  (2025/2026), Class and Arm (JSS1A, SS2B), CA1, CA2, Exam, Total, Position,
  Broadsheet, WAEC/NECO/JAMB. Never GPA, credits, semesters, faculties or
  "freshman/sophomore".
- Dates as "Mon 9 Aug" or "09/08/2026". Time is West Africa Time.
- Names are Nigerian: Chidinma Okeke, Ibrahim Bello, Tolulope Adeyemi,
  Ngozi Eze, Musa Abdullahi. Schools: "Graceland College", "Bright Future
  Academy". Never Western placeholder names.
- Never show a fingerprint, face-scan, card-reader, RFID or barcode-scanner
  affordance. Attendance is phone camera QR and phone GPS only.

STATES
Every list and detail screen must be shown with its content state. Where the
prompt asks for it, also produce the empty, error and offline variants. Offline
is a slim amber bar under the app bar: "Offline — showing data from 14:32" with
a retry action.

OUTPUT
Mobile screen only. No desktop layout, no marketing copy, no lorem ipsum.
Realistic Nigerian school data in every field.
```

---

## §2 Global Rules — re-paste if Stitch drifts

- 5 bottom-nav items maximum. If a screen needs a sixth destination it goes
  under "More".
- Every number is `data-mono` with tabular figures.
- Every currency value carries `₦`, separators and 2 decimals.
- No shadows except Level 2. No gradient fills. No blur.
- 48dp minimum tap target.
- No hardware-scanner iconography of any kind.
- AI-generated text is never presented as final: it carries a
  "Pending approval" amber chip and an explicit Approve / Edit pair.

---

## §3 Screen Prompts

### 3.1 Onboarding & Auth

**School code**

```
Mobile screen: first-launch school identification for SchoolPilot.
Centred SchoolPilot wordmark on #fcf9f8. Headline "Find your school". A single
floating-label input "School code" with helper text "The short name your school
gave you, e.g. graceland". Amber primary button "Continue" full width. A ghost
text link "I don't know my code" opening a hint that the code is in the invite
SMS or email. Below the fold, small label-sm text: "SchoolPilot — for Nigerian
schools". Show an error variant where the code is not recognised: red helper
text "We couldn't find a school with that code."
```

**Login**

```
Mobile login screen for SchoolPilot. App bar shows the resolved school as a
chip: school crest placeholder + "Graceland College" + a small "change" link.
Headline-md "Sign in". Floating-label inputs for Email and Password with a
show/hide eye icon. A "Forgot password?" ghost link right-aligned. Amber
primary button "Sign in", full width, 48dp. Below it, label-sm helper: "Use the
account your school created for you." Produce three variants: default, an
inline error state "Invalid email or password", and a locked-out state
"Too many attempts. Try again in a minute."
```

**Two-factor**

```
Mobile 2FA screen, shown only to school administrators. Headline "Enter your
6-digit code". A row of six large single-character code boxes, tabular figures,
auto-advancing. Body-md helper: "From your authenticator app." Amber "Verify"
button. Ghost "Back to sign in". Small on-surface-variant note: "Required for
administrator accounts."
```

### 3.2 Admin

**Admin dashboard**

```
Mobile dashboard for a school administrator (the Principal view). App bar:
"Graceland College", a bell icon with a red dot, and an avatar.
Row of two stat cards, then a second row of two: Enrolment 842 students;
Attendance today 91.4%; Fees collected this term ₦18,420,000.00 with a thin
progress bar reading "62% of ₦29,700,000.00"; Outstanding ₦11,280,000.00 in
red. Numbers in display-lg-mobile, labels in label-sm uppercase.
Below: a section "Needs attention" as a list of data cards with 4px left
borders — "37 fee defaulters" (red), "Second Term results awaiting release"
(amber), "4 leave requests pending" (amber). Then "Term at a glance": a small
bar chart of attendance across the last 5 school days.
Bottom nav 5 items: Home, People, Finance, Academics, More.
```

**Student roster**

```
Mobile student list for an administrator. App bar "Students" with search and
filter icons. A horizontal scrolling row of filter chips: All, JSS1, JSS2,
JSS3, SS1, SS2, SS3. Below, a sticky count row: "842 students · JSS2A shown".
List of data cards: circular initial avatar, student name in body-lg, second
line "JSS2A · Adm. No. GC/2023/0184" in body-md on-surface-variant, and a
right-aligned status chip (green "Paid", red "Owing ₦45,000.00"). 4px left
border matching the chip. Amber FAB bottom-right with a person-add icon.
Also show the empty variant: "No students in JSS2A yet" with an "Add student"
action.
```

**Fee defaulters**

```
Mobile defaulter dashboard for a bursar. App bar "Defaulters". A summary band
in primary-container #1b3a5f with white text: "₦11,280,000.00 outstanding
across 37 students". Segmented control: All / 1 term behind / 2+ terms behind.
List rows: student name, class, amount owed in red data-mono, and days overdue
as a label-sm. Each row has a trailing overflow menu. A bottom action bar with
two buttons: ghost "Export" and amber "Send reminders", and beneath the amber
button a small warning line: "SMS is charged per message."
```

**Result release**

```
Mobile screen for releasing term results, administrator only. App bar
"Release results". A term selector chip row: First / Second / Third, Session
2025/2026. A list of classes with, per row: class name (JSS2A), a progress
indicator "Scores complete 100%", a comments indicator "3 AI comments pending
approval" in amber, and a toggle labelled Released / Not released. Rows with
outstanding comment approvals show the toggle disabled with helper text "Approve
all comments before releasing." A bottom sheet confirmation: "Release Second
Term results for JSS2A? Parents will be able to check results with a PIN."
Confirm button amber, cancel ghost.
```

**Result-checker PIN inventory**

```
Mobile PIN inventory screen for a school administrator. Top card: "PINs in
stock 1,240" in display-lg-mobile, with a thin amber bar and helper "Reorder
below 200". Two secondary stats side by side: "Sold this term 486" and
"Revenue ₦1,458,000.00". Section "Recent batches" listing: batch reference,
quantity, unit price ₦1,200.00, purchase date, and a status chip (Active /
Exhausted). Two buttons pinned to the bottom: amber "Buy more PINs" and ghost
"Sell over the counter".
```

### 3.3 Teacher

**Teacher dashboard**

```
Mobile dashboard for a teacher. App bar: "Good morning, Mrs Adeyemi" in
headline-md with an avatar. First card, full width, primary-container blue:
"Next: Mathematics · JSS2A · 09:40 – 10:20 · Room 4" with a white ghost button
"Take register". Below, a row of three compact stat tiles: "Register 2 of 5
taken", "Scores due 18", "Homework to grade 24".
Section "Today's timetable" as a vertical timeline: time on the left in
data-mono, subject and class on the right, the current period highlighted with
a 4px amber left border.
Section "Awaiting you": rows for "3 AI comments pending approval" (amber chip),
"1 leave request" and "2 unread parent messages".
Bottom nav: Home, Register, Gradebook, Classes, More.
```

**Class register (the flagship offline screen)**

```
Mobile attendance roll-call screen for a teacher — the most important screen in
the app. It must be usable one-handed, quickly, with no internet.
App bar "JSS2A · Register" with the date "Mon 9 Aug" beneath in body-md.
Directly under the app bar, a slim amber offline banner: "Offline — 3 changes
will sync when you're back online" with a small cloud-off icon.
A summary strip: Present 28 (green), Absent 3 (red), Late 2 (amber),
Excused 1, all as tallies in data-mono.
The list: one row per student, 56dp tall, circular initial avatar, name in
body-lg, and a 4-way segmented status control on the right with compact
labels P / A / L / E. The selected segment fills with its semantic colour and
white text. Rows are separated by 1px outline-variant lines, not cards.
A "Mark all present" ghost button sits above the list.
Bottom bar, always visible: amber "Submit register" full width with a secondary
line "31 of 34 marked".
Produce three variants: online default, the offline state described above, and
a post-submit state with a green confirmation snackbar "Register saved".
```

**Gradebook / score entry**

```
Mobile score-entry screen for a teacher. App bar "Mathematics · JSS2A" with a
term chip "Second Term". A dense compact table with a sticky first column of
student names and numeric columns CA1 (10), CA2 (10), CA3 (20), Exam (60),
Total (100). Numeric cells are tappable inline inputs in data-mono, right
aligned, 4px internal padding so all five columns fit without horizontal
scrolling. The Total column is read-only, shaded surface-container, and turns
red when below the pass mark. A row of the class average sits pinned at the
bottom of the table.
Above the table, a thin progress bar: "24 of 34 entered".
Bottom bar: ghost "Save draft" and amber "Submit scores".
```

**AI comment review — pending approval**

```
Mobile screen where a teacher reviews AI-generated report-card comments before
they can reach a report card. This gate is a hard product rule and must be
visually obvious.
App bar "Comments · JSS2A" with a counter chip "3 pending".
Each item is a card: student name and class, then the generated comment in
body-lg inside a surface-container-low block with a 4px amber left border, and
an amber "Pending approval" chip at the top right with a small sparkle icon.
Under the comment, a label-sm line: "Draft generated by AI — not yet on the
report card."
Two actions per card: ghost "Edit" and amber "Approve". An expanded edit state
shows the comment in a multi-line text field with a character counter.
Also show an approved card variant: green "Approved" chip, comment in plain
body text, a single ghost "Undo" action.
```

**Homework grading queue**

```
Mobile homework grading list for a teacher. App bar "Homework" with a filter
icon. Tabs: To grade (24) / Graded / Not submitted. List rows: student name,
submission time "Submitted Sun 8 Aug, 21:14", an attachment chip if present,
and a trailing score input box in data-mono showing "— / 20". Rows submitted
after the deadline carry a red "Late" chip. A bottom bar with ghost "Bulk
grade" and amber "Save grades".
```

### 3.4 Student

**Student dashboard**

```
Mobile home screen for a secondary-school student. App bar: "Hello, Chidinma"
with an avatar and a small streak chip "12-day streak" with a flame icon.
Hero card in primary-container: "Next class · Mathematics · 09:40 · Room 4".
A row of two cards: "Average this term 74.5%" with a small upward trend arrow
in green, and "Attendance 96%".
Section "Due soon": homework cards with subject, title, and a due chip that is
amber for tomorrow and red for today.
Section "Exams": one CBT card — "Second Term Mid-Term Test · Mathematics ·
40 questions · 45 minutes · Opens Tue 10 Aug 10:00", with an amber "Start"
button that is disabled until the window opens.
A compact "Ask your tutor" entry card with a chat icon.
Bottom nav: Home, Timetable, Learn, Results, More.
```

**CBT exam — question view**

```
Mobile computer-based test screen, mid-exam. This is a focus environment: no
bottom navigation at all.
Top bar: a slim linear progress bar, "Question 12 of 40" on the left, and a
countdown "18:42" on the right in data-mono that turns amber under 5 minutes
and red under 1 minute.
Question body in body-lg, including a rendered diagram image inside a bordered
container and one inline mathematical formula, to show that both images and
LaTeX render correctly.
Four answer options as full-width selectable cards, 56dp tall, radio-style, the
selected one filled with primary-container blue and white text.
A ghost "Flag for review" chip under the options.
Bottom bar: ghost "Previous", a centre "Grid" button that opens a question
navigator, and amber "Next".
Show the navigator sheet as a second frame: a 5-column grid of numbered
squares, answered in green, flagged in amber, unanswered outlined.
Include a small saved indicator: "Answers saved on this device" with a check
icon, and an offline variant reading "Offline — your answers are safe and will
submit when you reconnect."
```

**AI tutor chat**

```
Mobile chat screen for a student's AI tutor. App bar "Tutor · Mathematics" with
a subject switcher chip. Message list: student messages right-aligned in
primary-container blue with white text; tutor messages left-aligned on white
cards with a 1px outline, containing body-lg text, a worked example in a
surface-container-low block, and one rendered formula. A small label-sm footer
under the first tutor message: "Your tutor explains — it won't just give you the
answer."
Suggested-prompt chips above the composer: "Explain simultaneous equations",
"Give me a practice question", "Why is my answer wrong?".
Composer: rounded input, an attach-photo icon, and an amber send button.
```

**Student results**

```
Mobile results screen for a student. App bar "Results" with a term selector
chip "Second Term · 2025/2026". A summary card: Total 742, Average 74.2%,
Position "6th of 34", Class average 68.1%, all in data-mono with label-sm
captions. Below, one card per subject: subject name, a small horizontal bar of
the score, CA and Exam split in a compact row (CA 32/40 · Exam 46/60 ·
Total 78), and a grade chip (A1, B2, C4) tinted by band.
Also produce the gated variant: the same screen blurred behind a centred lock
card reading "Second Term results are not yet released by your school."
```

### 3.5 Parent

**Parent dashboard with child switcher**

```
Mobile home screen for a parent. App bar contains a child switcher: a pill with
the child's avatar, "Chidinma · JSS2A", and a chevron; tapping it opens a sheet
listing two children. A bell icon on the right.
First card, red-bordered: "School fees · ₦45,000.00 outstanding · due Fri 13
Aug" with an amber "Pay now" button.
Second card: "Today · Present · arrived 07:42" with a green left border.
Section "Recent updates" as a feed: a behaviour note "Commended for helping in
the lab — Mrs Adeyemi, Fri 6 Aug" with a green chip; a homework item
"Mathematics assignment due tomorrow"; a school notice "Mid-term break begins
20 Aug".
Section "Results": a card "Second Term results are available" with an amber
"Check result" button.
Bottom nav: Home, Child, Fees, Results, More.
```

**Result checker — PIN gate**

```
Mobile result-checker screen for a parent whose child's results are released
but locked. App bar "Second Term Result · Chidinma Okeke".
Centre card with a lock icon: headline-md "Unlock this result", body-lg "Results
for Second Term 2025/2026 have been released. Use a result-checker PIN to open
them."
Two clearly separated paths:
1. "I have a PIN" — a floating-label input for the PIN with a paste action and a
   ghost "Redeem" button.
2. "Buy a PIN" — a card showing "₦1,200.00" in data-mono with an amber
   "Pay with Paystack" button and a label-sm note "One PIN opens this result for
   the whole term."
A small footer link: "Bought a PIN at the school office? Enter it above."
Also produce the error variant for a wrong PIN: red helper text "This PIN is not
valid or has already been used."
```

**Fees and payment**

```
Mobile fee screen for a parent. App bar "School fees" with the child chip.
Top card: "Outstanding ₦45,000.00" in display-lg-mobile red, with "of
₦145,000.00 for Second Term" beneath and a progress bar.
Section "Breakdown": rows for Tuition ₦110,000.00, Books ₦18,000.00, Uniform
₦12,000.00, PTA levy ₦5,000.00, each in data-mono.
Section "Payments made": rows with date, amount in green, channel chip (Bank
transfer / Paystack / Cash), and a download-receipt icon.
An instalment-plan card if one exists: "Plan: 3 instalments · next ₦45,000.00
due Fri 13 Aug".
Bottom bar: amber "Pay ₦45,000.00" full width.
```

**Child attendance & behaviour**

```
Mobile screen showing a child's attendance and behaviour. App bar "Chidinma ·
JSS2A". Segmented control: Attendance / Behaviour.
Attendance view: a summary strip Present 58 / Absent 3 / Late 2 in semantic
colours, then a month calendar grid where each school day is a small filled dot
in its status colour, with a legend below, then a list of the exceptions only
("Absent · Tue 20 Jul · No reason given").
Behaviour view: a chronological list of cards, each with a 4px left border,
green for commendations and amber for concerns, showing the note, the teacher's
name, and the date.
```

### 3.6 Shared

**Timetable**

```
Mobile weekly timetable. App bar "Timetable" with a class chip "JSS2A".
A horizontally scrolling day selector (Mon–Fri) with the current day filled in
primary blue. Below, a vertical list for the selected day: time in data-mono on
the left in a fixed 64dp column, and a card on the right with subject, teacher
and room. Break and assembly rows are shown as slim surface-container strips
without a card. The current period has a 4px amber left border and a small
"Now" chip.
Include a "Last updated 07:15" line under the app bar with a refresh icon, and
an offline variant of that line.
```

**Messages**

```
Mobile teacher-parent messaging. Two frames.
Frame 1, thread list: app bar "Messages" with a search icon; rows with a
circular avatar, the other party's name, a role label-sm ("Parent · Chidinma
JSS2A"), the last message preview truncated to one line, a timestamp, and an
unread count in an amber circular badge.
Frame 2, a conversation: messages as bubbles — outgoing right-aligned in
primary-container blue with white text, incoming left-aligned on white with a
1px outline. Day separators as centred label-sm chips. A composer with an
attach icon and an amber send button.
```

**Notification inbox**

```
Mobile notification inbox. App bar "Notifications" with a "Mark all read" ghost
action. Grouped by Today / This week / Earlier. Each row: a leading circular
icon tinted by category (money red, results green, homework amber, school
notice blue), a title in body-lg, one line of detail in body-md
on-surface-variant, a timestamp, and an unread dot. Tapping a row is implied to
deep-link. Include the empty variant: an inbox icon with "You're all caught up."
```

**Profile & settings**

```
Mobile profile screen. Header: large circular avatar, name in headline-md, a
role chip ("Teacher"), and the school name in body-md. Grouped list sections
with leading icons:
Account — Edit profile, Change password, Two-factor authentication (with an
"On"/"Off" trailing chip).
Preferences — Notifications, Language, Data saver (with a toggle and helper
"Don't load images on mobile data").
Offline — "3 items waiting to sync" with a trailing sync icon, tappable to a
queue detail.
About — Help, Privacy, App version 1.0.0.
A ghost "Sign out" button in red text at the bottom, full width.
```

**Global states pack**

```
Produce a set of four mobile state frames for SchoolPilot, sharing one app bar
("Students") so they read as a set:
1. Loading — three skeleton list cards in surface-container with no spinner.
2. Empty — a centred outline icon, headline-md "No students yet", body-md "Add
   your first student to get started", and an amber "Add student" button.
3. Error — a centred outline alert icon, headline-md "Couldn't load students",
   body-md "Check your connection and try again", and a ghost "Retry" button.
4. Offline — the normal list rendered in full, with a slim amber banner beneath
   the app bar: "Offline — showing data from 14:32", a cloud-off icon and a
   "Retry" text action.
```

---

## §4 Generation checklist

Before accepting a Stitch output, check:

- [ ] Bottom nav has 5 items or fewer, and matches the role's set in
      `docs/mobile-app.md` §B3.
- [ ] Every currency value reads `₦NN,NNN.00`.
- [ ] Academic language is Nigerian K-12 — no GPA, semesters or credits.
- [ ] Names and school names are Nigerian.
- [ ] No fingerprint, face-scan, card-reader or barcode-scanner iconography.
- [ ] Tap targets look at least 48dp.
- [ ] No shadows other than on modals/FAB; no gradients; no blur.
- [ ] Any AI-generated text carries the "Pending approval" treatment.
- [ ] The screen has a stated behaviour for empty and offline.

## §5 Related

- Build plan and API mapping: `docs/mobile-app.md`
- Existing web screens and full token set:
  `stitch_schoolpilot_management_system/` (`academic_credibility_system/DESIGN.md`)
- Product spec: `docs/SchoolPilot-Comprehensive-Documentation.md`
