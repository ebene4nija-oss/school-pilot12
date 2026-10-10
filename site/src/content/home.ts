/**
 * Homepage copy. Every claim here maps to the "claimable now" register in
 * the Landing PRD §2.1. Before adding a line, check it there; anything in
 * §2.3 (hardware, biometrics, inventory, "AI writes report cards", made-up
 * numbers) never goes on the site.
 */

export const hero = {
  eyebrow: "School management system for Nigerian schools · Nursery to SS3",
  headline: "Run your whole school from one login.",
  sub: "One all-in-one system for your school: results, CBT with a computer-lab client, an LMS, fees and school administration, connected for staff, students and parents. Built for Nigerian private schools. From ₦300 per student per term.",
  proof: ["One system, one login", "Free 24-hour data migration", "CBT works offline in your lab"],
};

export const proofStrip = [
  "All-in-one",
  "Day & boarding",
  "Nothing to buy",
  "Free 24-hour migration",
  "CBT lab client",
  "NDPA-first",
  "Web, Android and iOS",
];

export type Problem = {
  id: string;
  pain: string;
  fix: string;
  outcome: string;
  href: string;
  linkLabel: string;
};

/**
 * The four problems the homepage leads with (Landing spec §3): results, fees,
 * CBT and the LMS. The whole-school view lives on the Admin role tab and the
 * school-leaders page.
 */
export const problems: Problem[] = [
  {
    id: "results",
    pain: "“Results take weeks. Teachers compute positions by hand overnight.”",
    fix: "Your CA scheme set once, scores entered on phones, broadsheet and positions computed for you.",
    outcome: "From last exam to signed report card without a spreadsheet.",
    href: "/features/results-report-cards/",
    linkLabel: "See how results work",
  },
  {
    id: "fees",
    pain: "“We don't know who owes fees until resumption.”",
    fix: "Invoices, instalments, scholarships and balances in one place, with online payment.",
    outcome: "Know who owes, and how much, before resumption.",
    href: "/features/fees-finance/",
    linkLabel: "See fees and finance",
  },
  {
    id: "cbt",
    pain: "“Light and internet fail in the middle of exams.”",
    fix: "The SchoolPilot CBT lab client runs the paper over your computer lab's own network, so the exam needs no internet. Answers upload afterwards and are marked automatically.",
    outcome: "CBT that keeps going through a power cut or network outage.",
    href: "/features/cbt/",
    linkLabel: "See how CBT works",
  },
  {
    id: "lms",
    pain: "“Learning stops when the closing bell rings.”",
    fix: "The built-in LMS: learning materials, assignments with online submission, lesson plans, virtual classes on Zoom or Google Meet, and an AI tutor.",
    outcome: "Lessons, homework and revision that carry on after school hours.",
    href: "/features/lms/",
    linkLabel: "See the LMS",
  },
];

/**
 * "Everything in one system": the all-in-one block. Each area links to the
 * page that explains it; together they are the whole school.
 */
export const allInOne = [
  { id: "academics", title: "Academics & results", items: "CA schemes, score entry, broadsheets, positions, report cards, result PINs", href: "/features/results-report-cards/" },
  { id: "cbt", title: "CBT + lab client", items: "Online CBT, plus a computer-lab client that runs exams with no internet", href: "/features/cbt/" },
  { id: "lms", title: "LMS", items: "Learning materials, assignments, lesson plans, virtual classes, AI tutor", href: "/features/lms/" },
  { id: "finance", title: "Fees & finance", items: "Invoices, instalments, scholarships, online payment, expenses", href: "/features/fees-finance/" },
  { id: "admin", title: "School administration", items: "Admissions, attendance, timetable, HR and payroll, communication, boarding, school store, library, transport, ID cards", href: "/features/school-administration/" },
  { id: "people", title: "Apps for everyone", items: "Admin, teachers, parents and students on web, Android and iOS", href: "/solutions/school-owners/" },
];

/** "One term in SchoolPilot": the signature section (Landing PRD §6.2). */
export const termSteps = [
  { id: "resumption", title: "Resumption", text: "Classes, subjects and teachers carried over with one session rollover." },
  { id: "attendance", title: "Attendance", text: "Teachers mark the register on their phones; parents of absent children are alerted." },
  { id: "ca", title: "CA", text: "Your CA structure, your weights. 2 CAs or 4, 40/60 or 30/70." },
  { id: "cbt", title: "Exams & CBT", text: "Run CBT online, or offline on your lab computers when the internet is down." },
  { id: "broadsheet", title: "Broadsheet", text: "Scores flow into the broadsheet; positions computed; corrections tracked and approved." },
  { id: "reports", title: "Report cards", text: "Report cards with teacher-approved comments, released to parents in one step." },
  { id: "fees", title: "Fees", text: "Every invoice, payment and balance in one place. Know who owes before resumption." },
];

export type Role = {
  id: string;
  label: string;
  promise: string;
  outcomes: string[];
  shot: { src: string; alt: string; phone?: boolean };
  href?: string;
};

export const roles: Role[] = [
  // School-side roles as the product has them: there is no separate "owner"
  // login. A proprietor signs in as the school's admin.
  {
    id: "admin",
    label: "Admin",
    promise: "See and run the whole school from one place.",
    outcomes: [
      "Attendance, fees and results for the whole school on one dashboard.",
      "Student records, timetables and result approvals in one login.",
      "Your data is yours: export all of it in one click, any time.",
    ],
    shot: { src: "/screens/admin-dashboard.webp", alt: "School admin dashboard showing today's attendance, fees collected this term and result progress" },
    href: "/solutions/school-owners/",
  },
  {
    id: "bursar",
    label: "Bursar",
    promise: "Every naira billed, paid and owed, in one place.",
    outcomes: [
      "Invoices per class and term, with instalments and scholarships.",
      "Outstanding balances before resumption, not after.",
      "Salary structures, staff loans and payslips each month.",
    ],
    shot: { src: "/screens/fees-outstanding.webp", alt: "Fees screen listing invoices and outstanding balances by class" },
    href: "/features/fees-finance/",
  },
  {
    id: "teacher",
    label: "Teacher",
    promise: "Less paperwork, more teaching.",
    outcomes: [
      "Mark the register and enter scores from your phone.",
      "Lesson plans and worksheets drafted with AI Studio, for you to edit.",
      "Report comments drafted for you to approve; nothing goes out unreviewed.",
    ],
    shot: { src: "/screens/score-entry-phone.webp", alt: "Teacher entering CA scores for a class on a phone", phone: true },
  },
  {
    id: "parent",
    label: "Parent",
    promise: "Results, fees and attendance on your phone as they happen.",
    outcomes: [
      "See the result the moment the school releases it.",
      "Know the fee balance without calling the bursar.",
      "Hear the same morning if your child is absent.",
    ],
    shot: { src: "/screens/parent-app-phone.webp", alt: "Parent app showing a child's term result and fee balance", phone: true },
  },
  {
    id: "student",
    label: "Student",
    promise: "Subjects, assignments, CBT practice and results in one app.",
    outcomes: [
      "Assignments and learning materials from every subject teacher.",
      "CBT practice, including WAEC, NECO and BECE-style papers your school sets.",
      "An AI tutor for revision, alongside your teachers.",
    ],
    shot: { src: "/screens/student-app-phone.webp", alt: "Student app with today's timetable and pending assignments", phone: true },
  },
];

export const pillars = [
  { id: "results", title: "Results without the all-nighters", text: "CA to broadsheet to positions to a signed report card, configured to how your school grades." },
  { id: "price", title: "Simple per-student pricing", text: "School Management with Result Management at ₦500 per active student per term, or the Subscription at ₦300 after a 30-day free trial. CBT included." },
  { id: "reality", title: "Built for Nigerian reality", text: "CBT that runs with no internet. No hardware to buy. Works on the phones your staff already own." },
  { id: "data", title: "Your data stays yours", text: "Free 24-hour migration in. One-click export out. Children's data treated as the most sensitive data we hold." },
  { id: "connected", title: "Everyone connected", text: "Admins see the whole school, teachers do less admin, and parents stop asking “has the result come out?”" },
];

export type Faq = { id: string; q: string; a: string };

/** The six objections on the homepage; /faq opens with the same six. */
export const objections: Faq[] = [
  { id: "power", q: "Our internet and light are unreliable.", a: "CBT runs in your computer lab with the SchoolPilot lab client, over the lab's own network, with no internet during the exam. Answers upload afterwards. Staff use the mobile apps." },
  { id: "staff", q: "My teachers are not computer people.", a: "Everything daily works on a phone. We move your records in for you and train your staff during setup." },
  { id: "mid-session", q: "We're mid-session. Switching now would be chaos.", a: "We migrate your current records free within 24 hours. Start with results and add the rest when you're ready." },
  { id: "cost", q: "What does it really cost?", a: "School Management with Result Management: ₦500 per active student per term. School Management Subscription: ₦300 after a 30-day free trial. School Management with Boarding School Management: ₦1,000 per term on either plan. CBT is included, with no per-exam fees." },
  { id: "leaving", q: "What if we want to leave?", a: "Export all of your school's data in one click, any time. No lock-in." },
  { id: "ai", q: "Will the AI write nonsense on report cards?", a: "No AI-drafted comment reaches a report card until a teacher approves or edits it." },
];

/**
 * Boarding, its own homepage block. The Landing PRD kept boarding to one line
 * as a secondary audience; it is raised here so boarding schools see it at a
 * glance. Every item maps to a built route in backend/routes/modules/hostel.php
 * (and the sick-bay clinic visit). No inventory claims (PRD §2.3).
 */
export const boarding = {
  title: "Boarding school? The hostel runs in SchoolPilot too.",
  intro: "Houses, beds, roll calls, exeats and welfare on the same records as results and fees. Day schools never see any of it.",
  items: [
    { id: "beds", icon: "admin", title: "Hostels, rooms and beds", text: "Set up your houses, allocate and move boarders, and see every free bed at a glance." },
    { id: "rollcall", icon: "clock", title: "Roll calls on a phone", text: "House staff mark night and morning roll calls on their phone. Missing boarders show up straight away." },
    { id: "exeat", icon: "transfer", title: "Exeats with parent consent", text: "Parents request or approve leave in the app. Every departure and return is logged." },
    { id: "welfare", icon: "shield", title: "Welfare, incidents and sick bay", text: "Welfare notes, incident records and sick-bay visits, seen only by the staff who should." },
    { id: "roster", icon: "calendar", title: "Duty roster and room checks", text: "Publish the house staff rota, inspect rooms, and track repairs boarders report." },
    { id: "fees", icon: "fees", title: "Boarding fees billed for you", text: "Hostel charges go straight onto each boarder's fee account, alongside tuition." },
  ],
} as const;

/**
 * The school store: uniforms, books and consumables handed to students,
 * confirmed by parents. Maps to backend/routes/modules/inventory.php. Optional
 * per school; no price claim (it carries no separate charge in pricing.ts).
 */
export const store = {
  title: "Uniforms, books and supplies, without the missing-item arguments.",
  intro: "Keep stock of what you hand to students, give a new student everything in one go at registration, and let parents confirm in the app that it all arrived.",
  items: [
    { id: "stock", icon: "sheet", title: "Stock you can trust", text: "Every size and edition has its own count. Deliveries, corrections and hand-overs are all recorded, and low stock is flagged." },
    { id: "packs", icon: "gift", title: "New-student packs", text: "Set up a pack once, like “JSS 1 new student pack”, and hand it over at registration in one step." },
    { id: "fees", icon: "fees", title: "Straight onto the fees", text: "You choose which items are charged. Charged items go onto that term’s invoice; free ones go out at no charge." },
    { id: "confirm", icon: "check", title: "Parents confirm receipt", text: "Parents tick what arrived in their app. Anything missing comes back to you with a note, so you can sort it out." },
  ],
} as const;

/**
 * "Before and after": the same moments of a term, today and in SchoolPilot.
 * Each "after" restates something already claimed on a feature page; no
 * numbers, because we have none we can stand behind yet.
 */
export const beforeAfter = [
  {
    id: "results",
    moment: "Results week",
    before: "Teachers add up CA and exam scores by hand, and someone stays up computing positions in Excel.",
    after: "Teachers enter scores on their phones. Totals, averages and positions are computed as the scores come in.",
  },
  {
    id: "corrections",
    moment: "A score is wrong",
    before: "One wrong cell moves every position below it, and nobody can say who changed what.",
    after: "A change after submission goes through approval, and the history is kept.",
  },
  {
    id: "fees",
    moment: "Fees",
    before: "The bursar finds out who owes at resumption, from receipts, bank alerts and the fees book.",
    after: "Every invoice, part-payment and scholarship in one place. Balances by class before term starts.",
  },
  {
    id: "parents",
    moment: "Parents",
    before: "Parents call to ask whether results are out, what they owe, and why their child was marked absent.",
    after: "Results, fee balance and absences show on the parent's phone as they happen.",
  },
  {
    id: "cbt",
    moment: "CBT day",
    before: "The light goes off or the network drops, and the exam stops for the whole lab.",
    after: "The lab client runs the paper over the lab's own network. Nothing needs the internet until it's time to upload.",
  },
  {
    id: "homework",
    moment: "Homework",
    before: "Homework is copied from the board, and half the class never writes it down.",
    after: "Assignments and notes are on the student app, and work is submitted online.",
  },
];

/** Risk reversal: why trying SchoolPilot costs a school nothing. Each line is in pricing.ts, /migrate/ or /trust/. */
export const noRisk = [
  { id: "trial", icon: "gift", title: "30 days free", text: "The School Management Subscription starts with a 30-day free trial." },
  { id: "migration", icon: "transfer", title: "We move you in", text: "Your records migrated free, within 24 hours. No retyping." },
  { id: "start-small", icon: "check", title: "Start with one thing", text: "Begin with results, or fees, and switch the rest on when you're ready." },
  { id: "hardware", icon: "apps", title: "Nothing to buy", text: "It runs on the phones and computers your school already has." },
  { id: "fees", icon: "fees", title: "No surprise fees", text: "Per active student, per term. No per-exam, per-teacher or per-parent charges." },
  { id: "export", icon: "unlock", title: "Leave any time", text: "Export all of your school's data in one click. No lock-in." },
] as const;
