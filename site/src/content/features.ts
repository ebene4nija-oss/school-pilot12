/**
 * Feature pages (Landing spec §6, "Feature page briefs"). Each page leads
 * with the problem in the school's words, then how SchoolPilot solves it,
 * then proof. Workflows describe only what the code ships today.
 */
import type { Faq } from "./home";

export type Feature = {
  slug: string;
  name: string;
  title: string;
  sub: string;
  seoTitle: string;
  seoDescription: string;
  problems: string[];
  steps: { title: string; text: string }[];
  benefits: { title: string; text: string }[];
  roles: string[];
  callout: { title: string; text: string };
  shot: { src: string; alt: string; phone?: boolean };
  faq: Faq[];
  related: string[];
};

export const features: Feature[] = [
  {
    slug: "results-report-cards",
    name: "Results & Report Cards",
    title: "From first CA to signed report card. Without a spreadsheet.",
    sub: "Set your CA scheme once. Teachers enter scores on their phones, SchoolPilot computes the broadsheet and positions, and report cards go to parents in one step.",
    seoTitle: "School Result Management & Report Card Software",
    seoDescription: "Configurable CA, automatic broadsheets and positions, teacher-approved comments and report cards released to parents. Built for Nigerian schools.",
    problems: [
      "Teachers stay up computing totals, averages and positions by hand.",
      "One wrong cell in the Excel broadsheet and every position below it is wrong.",
      "Report cards are late, and parents keep calling to ask if results are out.",
    ],
    steps: [
      { title: "Set your CA scheme", text: "Number of CAs, weights and grading per section: 2 CAs or 4, 40/60 or 30/70." },
      { title: "Teachers enter scores", text: "On a phone or computer, subject by subject. Homework and quizzes can fill a CA, adjusted by the teacher." },
      { title: "Broadsheet computed", text: "Totals, averages, class positions and subject positions calculated for you." },
      { title: "Corrections approved", text: "A change after submission goes through approval, and the history is kept." },
      { title: "Comments reviewed", text: "AI drafts report comments; nothing reaches a card until a teacher approves or edits it." },
      { title: "Released to parents", text: "Report cards released in one step, on the parent app, the result checker or on paper." },
    ],
    benefits: [
      { title: "Results out sooner", text: "No overnight computing; the broadsheet fills itself as scores come in." },
      { title: "Positions you can trust", text: "Class and subject positions computed the same way every time." },
      { title: "Your school's grading", text: "Each section keeps its own scheme; nursery can use developmental ratings." },
      { title: "Report cards that look the part", text: "Choose a report-card template that carries your school's logo." },
      { title: "Parents served without calls", text: "Released results appear on the parent app and the PIN result checker." },
    ],
    roles: ["Exams officer", "Principal", "Teachers", "Parents"],
    callout: {
      title: "Built for how Nigerian schools grade",
      text: "Class position, subject position, CA and exam weights per section, result PINs for parents who check results online, and printed cards for those who don't.",
    },
    shot: { src: "/screens/broadsheet.webp", alt: "JSS 2 broadsheet with every subject's CA and exam scores, totals and class positions" },
    faq: [
      { id: "scheme", q: "Can each section grade differently?", a: "Yes. Nursery, Primary, JSS and SS each get their own assessment scheme." },
      { id: "positions", q: "Does it compute positions?", a: "Yes: class positions and subject positions, from the scores entered." },
      { id: "ai", q: "Do we have to use AI comments?", a: "No. Teachers can write every comment themselves. If AI drafts one, it waits for a teacher's approval." },
      { id: "print", q: "Can we still print report cards?", a: "Yes. Report cards are generated as PDFs you can print or share." },
    ],
    related: ["cbt", "student-management", "fees-finance"],
  },
  {
    slug: "cbt",
    name: "CBT",
    title: "Exams that keep running through a power cut.",
    sub: "Run computer-based tests online, or on the offline desktop app on your own lab computers when the internet is down. Marking is automatic and scores flow into results.",
    seoTitle: "School CBT Software that Works Offline",
    seoDescription: "Online and offline CBT for Nigerian schools: question banks, shuffled questions, time limits, automatic marking and WAEC, NECO and BECE-style practice.",
    problems: [
      "The light goes off or the network drops halfway through an exam.",
      "Setting, printing and marking objective papers eats weeks of staff time.",
      "Students reach WAEC and NECO with little practice at computer-based exams.",
    ],
    steps: [
      { title: "Build a question bank", text: "Questions grouped by subject and topic, with versions kept when you edit." },
      { title: "Schedule the exam", text: "Set time limits and attempts; questions and options are shuffled per student." },
      { title: "Students sit it", text: "Online, or on the offline desktop app on the school's existing computers." },
      { title: "Marked automatically", text: "Objective answers marked as soon as a paper is submitted; regrade if a key changes." },
      { title: "Scores flow into results", text: "No retyping: CBT scores feed the broadsheet." },
    ],
    benefits: [
      { title: "No internet, no problem", text: "The offline app runs the paper on your lab computers and syncs afterwards." },
      { title: "Nothing to buy", text: "Uses the computers you already have. No special hardware." },
      { title: "Fairer papers", text: "Shuffled questions and options make copying harder." },
      { title: "Exam practice", text: "Set WAEC, NECO and BECE-style practice for SS3 and JSS3." },
    ],
    roles: ["ICT teacher", "Exams officer", "Teachers", "Students"],
    callout: {
      title: "Made for Nigerian lab conditions",
      text: "Power cuts and patchy internet are the norm, not the exception. The offline desktop app was built for exactly that.",
    },
    shot: { src: "/screens/cbt-exam.webp", alt: "Student CBT screen with a timer, numbered questions and shuffled answer options" },
    faq: [
      { id: "offline", q: "Does CBT really work without internet?", a: "Yes. The desktop app runs the exam on your lab computers offline and syncs results when you're back online." },
      { id: "hardware", q: "Do we need new computers?", a: "No. It runs on the computers you already have." },
      { id: "types", q: "What question types are supported?", a: "Objective questions are marked automatically. Talk to us about your exact format." },
      { id: "cbt-cost", q: "Does CBT cost extra?", a: "No. CBT is included. There are no per-exam or per-student CBT fees; your school pays one charge per term." },
    ],
    related: ["results-report-cards", "digital-learning", "student-management"],
  },
  {
    slug: "fees-finance",
    name: "Fees & Finance",
    title: "Know who owes, and how much, before resumption.",
    sub: "Bill every class for the term, accept part-payments, apply scholarships, take payment online and see every balance in one place.",
    seoTitle: "School Fees Management Software for Nigerian Schools",
    seoDescription: "Invoices per class and term, instalments, scholarships, online payment and outstanding balances in Naira. Built for Nigerian private schools.",
    problems: [
      "Nobody knows exactly who has paid until resumption, when it's too late.",
      "Receipts, bank alerts and the fees book never quite agree.",
      "Part-payments and scholarships are tracked on scraps of paper.",
    ],
    steps: [
      { title: "Set fees per class and term", text: "School fees and other items for each class, in Naira." },
      { title: "Invoices go out", text: "Every student billed for the term, visible to their parents." },
      { title: "Instalments and scholarships", text: "Part-payment plans and scholarships applied to the right invoices." },
      { title: "Payments recorded", text: "Recorded at the bursary, or paid online by parents." },
      { title: "Balances at a glance", text: "Outstanding fees by class and student, with receipts for every payment." },
    ],
    benefits: [
      { title: "No more surprises at resumption", text: "See who owes before term starts, not after." },
      { title: "Parents see their balance", text: "Fewer calls to the bursary; parents check the app." },
      { title: "One record of every payment", text: "Receipts, part-payments and scholarships in one place." },
      { title: "Beyond fees", text: "Expenses, budgets and petty cash when you're ready for them." },
    ],
    roles: ["Bursar", "Proprietor", "Parents"],
    callout: {
      title: "Naira, terms and part-payments",
      text: "Fees are billed per term in Naira, and instalments are first-class, because that is how parents actually pay.",
    },
    shot: { src: "/screens/fees-outstanding.webp", alt: "Fees screen listing invoices and outstanding balances by class for the term" },
    faq: [
      { id: "online", q: "Can parents pay online?", a: "Yes, online fee payment is supported. We confirm the payment options with your school during setup." },
      { id: "cash", q: "What about cash and bank transfers?", a: "The bursar records them against the invoice, and the parent's balance updates." },
      { id: "cost", q: "Does fees management cost extra?", a: "No. Fees tracking is part of the free core." },
    ],
    related: ["student-management", "results-report-cards", "digital-learning"],
  },
  {
    slug: "student-management",
    name: "Student Management",
    title: "Know every student, from Nursery 1 to SS3.",
    sub: "One profile per student: guardians, class and arm, attendance, results and history, carried forward every session.",
    seoTitle: "Student Management System for Nigerian Schools",
    seoDescription: "Student records, guardians, class history, promotion and transfers in one place. Import from Excel; built for Nursery to SS3.",
    problems: [
      "Records live in files, Excel and one person's laptop.",
      "Finding a guardian's number means digging through admission forms.",
      "A student's history disappears when they change class or school.",
    ],
    steps: [
      { title: "Admit or import", text: "Add students one by one or import from a spreadsheet." },
      { title: "Place in class and arm", text: "JSS1 Gold, SS2 Science A: however your school names them." },
      { title: "Link guardians", text: "Parents and guardians attached to each child, with their contacts." },
      { title: "History builds itself", text: "Attendance and results add up into a full academic history." },
      { title: "Promote or transfer", text: "Session rollover promotes classes; transfers carry records with the student." },
    ],
    benefits: [
      { title: "One source of truth", text: "Every office looks at the same record." },
      { title: "Years of history", text: "Past results and classes stay with the student." },
      { title: "Clean transfers", text: "Move a student's record to another school properly, with their history." },
      { title: "Data kept to what's needed", text: "We hold only the fields a school needs (NDPA data minimisation)." },
    ],
    roles: ["Admin", "Principal", "Class teachers"],
    callout: {
      title: "Arms, sections and session rollover",
      text: "Classes and arms per section, promotion at the end of the session, and transfer records when a student moves.",
    },
    shot: { src: "/screens/student-profile.webp", alt: "Student profile with guardians, class history and attendance summary" },
    faq: [
      { id: "import", q: "Can we import our existing students?", a: "Yes, from Excel or CSV. We also do the migration for you, free." },
      { id: "photos", q: "Do you need children's photos or fingerprints?", a: "No fingerprints or biometrics, ever. Photos are optional and only if your school uses them, for example on ID cards." },
    ],
    related: ["results-report-cards", "fees-finance", "digital-learning"],
  },
  {
    slug: "digital-learning",
    name: "Digital Learning",
    title: "Learning that carries on after the closing bell.",
    sub: "Lesson materials, assignments with online submission, virtual classes on Zoom or Google Meet, and an AI tutor for revision, all in the apps your students and teachers already use.",
    seoTitle: "Digital Learning for Nigerian Schools: Materials, Assignments, Virtual Classes",
    seoDescription: "Share learning materials, set and mark assignments, run virtual classes on Zoom or Google Meet and give students an AI tutor for revision.",
    problems: [
      "Students go home with nothing to revise from but their exercise books.",
      "Homework is set on the board and half the class never copies it down.",
      "When school is closed, learning stops.",
    ],
    steps: [
      { title: "Share materials", text: "Teachers upload notes, slides and worksheets per subject and class." },
      { title: "Set assignments", text: "Students submit online; teachers mark them, and scores can count towards CA." },
      { title: "Hold virtual classes", text: "Schedule lessons on Zoom or Google Meet; attendance is read from the meeting report." },
      { title: "Revise with the AI tutor", text: "Students ask questions and get explanations, alongside their teachers." },
      { title: "Teachers prepare faster", text: "AI Studio drafts lesson plans and worksheets for teachers to edit." },
    ],
    benefits: [
      { title: "Revision at home", text: "Every subject's materials on the student's phone." },
      { title: "Homework that arrives", text: "Assignments are on the app, not just the board." },
      { title: "School keeps going", text: "Virtual classes when the school can't open." },
      { title: "Less prep time", text: "Lesson plans and worksheets drafted for teachers to adapt." },
    ],
    roles: ["Teachers", "Students", "Parents"],
    callout: {
      title: "AI that supports teachers, not replaces them",
      text: "The AI tutor helps students revise; it doesn't replace the teacher and can make mistakes. AI Studio drafts are a starting point that teachers edit.",
    },
    shot: { src: "/screens/student-app-phone.webp", alt: "Student app showing today's materials, pending assignments and the next virtual class", phone: true },
    faq: [
      { id: "cost", q: "Is digital learning included free?", a: "Yes. Learning materials, assignments, AI Studio and the AI tutor are part of the free core." },
      { id: "video", q: "Do we need our own Zoom or Meet account?", a: "Yes, virtual classes use your school's Zoom or Google Meet; SchoolPilot schedules them and reads attendance." },
      { id: "ai-safe", q: "Is the AI tutor safe for students?", a: "It's for study help only. Your school chooses which sections and subjects can use it, sets a daily message limit, and can lock it during exams." },
    ],
    related: ["cbt", "results-report-cards", "student-management"],
  },
];

export function getFeature(slug: string) {
  return features.find((f) => f.slug === slug);
}
