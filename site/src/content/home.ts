/**
 * Homepage copy. Every claim here maps to the "claimable now" register in
 * the Landing PRD §2.1. Before adding a line, check it there; anything in
 * §2.3 (hardware, biometrics, inventory, "AI writes report cards", made-up
 * numbers) never goes on the site.
 */

export const hero = {
  eyebrow: "For Nigerian private schools · Nursery to SS3",
  headline: "Run your whole school from one login.",
  sub: "Students, results, CBT, fees, staff and parents, connected. Built for Nigerian private schools, from Nursery to SS3. Free for almost everything.",
  proof: ["Free 24-hour data migration", "No hardware to buy", "CBT works offline"],
};

export const proofStrip = [
  "Nothing to buy",
  "Free 24-hour migration",
  "Offline CBT",
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
 * CBT and digital learning. The whole-school view lives on the Admin role tab and the
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
    fix: "CBT runs on the offline desktop app on your own lab computers, with shuffled questions, time limits and automatic marking.",
    outcome: "CBT that keeps going through a power cut or network outage.",
    href: "/features/cbt/",
    linkLabel: "See how CBT works",
  },
  {
    id: "digital-learning",
    pain: "“Learning stops when the closing bell rings.”",
    fix: "Learning materials, assignments with online submission, virtual classes on Zoom or Google Meet, and an AI tutor for revision.",
    outcome: "Lessons, homework and revision that carry on after school hours.",
    href: "/features/digital-learning/",
    linkLabel: "See digital learning",
  },
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
  { id: "free", title: "Free for almost everything", text: "Core modules cost nothing, CBT included. You pay one charge per term, by school size, when you generate results." },
  { id: "reality", title: "Built for Nigerian reality", text: "CBT that runs with no internet. No hardware to buy. Works on the phones your staff already own." },
  { id: "data", title: "Your data stays yours", text: "Free 24-hour migration in. One-click export out. Children's data treated as the most sensitive data we hold." },
  { id: "connected", title: "Everyone connected", text: "Admins see the whole school, teachers do less admin, and parents stop asking “has the result come out?”" },
];

export type Faq = { id: string; q: string; a: string };

/** The six objections on the homepage; /faq opens with the same six. */
export const objections: Faq[] = [
  { id: "power", q: "Our internet and light are unreliable.", a: "CBT runs on an offline desktop app on the computers you already have, and syncs when the connection returns. Staff use the mobile apps." },
  { id: "staff", q: "My teachers are not computer people.", a: "Everything daily works on a phone. We move your records in for you and train your staff during setup." },
  { id: "mid-session", q: "We're mid-session. Switching now would be chaos.", a: "We migrate your current records free within 24 hours. Start with results and add the rest when you're ready." },
  { id: "cost", q: "What does it really cost?", a: "The core is free, CBT included. You pay one charge per term, by student band, when you generate results." },
  { id: "leaving", q: "What if we want to leave?", a: "Export all of your school's data in one click, any time. No lock-in." },
  { id: "ai", q: "Will the AI write nonsense on report cards?", a: "No AI-drafted comment reaches a report card until a teacher approves or edits it." },
];
