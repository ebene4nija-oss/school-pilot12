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
    title: "CBT for your computer lab, with or without internet.",
    sub: "Set papers once in SchoolPilot and run them online, or in your computer lab with the SchoolPilot CBT lab client: one school laptop carries the paper in and serves it to every lab PC over your local network. No internet needed during the exam.",
    seoTitle: "School CBT Software with an Offline Computer-Lab Client",
    seoDescription: "Online CBT plus a computer-lab client that runs exams with no internet: shuffled questions, theory and comprehension questions, invigilator view, automatic marking.",
    problems: [
      "The light goes off or the network drops halfway through an exam.",
      "Setting, printing and marking objective papers eats weeks of staff time.",
      "Students reach WAEC and NECO with little practice at computer-based exams.",
    ],
    steps: [
      { title: "Build the paper", text: "Question bank by subject and topic: objective, theory, comprehension passages and maths, with versions kept when you edit." },
      { title: "Prepare the lab the day before", text: "The lab client downloads the encrypted paper to one school laptop, and the lab PCs are paired to it." },
      { title: "Sit the exam offline", text: "Each lab PC opens the paper fullscreen over the lab's network. Questions and options are shuffled per candidate, with a countdown and autosave." },
      { title: "Invigilate from one screen", text: "See who has started and submitted. A candidate whose PC dies resumes where they stopped." },
      { title: "Upload, then marked", text: "Answers upload once you're back online. Objective answers are marked automatically and scores flow into the broadsheet." },
    ],
    benefits: [
      { title: "No internet during the exam", text: "Only preparing and uploading need a connection, never the exam itself." },
      { title: "Uses the lab you have", text: "Software on your existing laptop and lab PCs. Nothing to buy." },
      { title: "Works without cabling", text: "Wired or Wi-Fi; if the lab has no network at all, the laptop can provide one." },
      { title: "Exam practice", text: "Set WAEC, NECO and BECE-style practice for SS3 and JSS3, online or in the lab." },
    ],
    roles: ["ICT teacher", "Exams officer", "Teachers", "Students"],
    callout: {
      title: "Made for Nigerian lab conditions",
      text: "Power cuts and patchy internet are the norm, not the exception. The lab client keeps every answer on the school laptop until it can upload, so a dropped line never ends a paper.",
    },
    shot: { src: "/screens/cbt-exam.webp", alt: "Student CBT screen with a timer, numbered questions and shuffled answer options" },
    faq: [
      { id: "offline", q: "Does CBT really work without internet?", a: "Yes. With the lab client, the exam runs entirely over your lab's local network. Internet is only needed the day before, to download the paper, and afterwards, to upload answers." },
      { id: "hardware", q: "Do we need new computers or a server?", a: "No. One school laptop runs the lab client and your existing lab PCs sit the exam." },
      { id: "network", q: "Our lab has no network cabling. Can we still use it?", a: "Yes. Wi-Fi works, and where there is no network at all the laptop can create one for the lab." },
      { id: "types", q: "What question types are supported?", a: "Objective questions, theory answers, comprehension passages with several questions, and maths. Objective answers are marked automatically; theory answers go to the teacher to mark." },
      { id: "cbt-cost", q: "Does CBT cost extra?", a: "No. CBT and the lab client are included. There are no per-exam or per-student CBT fees; it is part of your school's per-term plan." },
    ],
    related: ["results-report-cards", "lms", "school-administration"],
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
    related: ["student-management", "results-report-cards", "lms"],
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
    related: ["results-report-cards", "fees-finance", "lms"],
  },
  {
    slug: "lms",
    name: "LMS (Digital Learning)",
    title: "A learning management system built into your school's portal.",
    sub: "SchoolPilot's LMS puts lesson materials, assignments, lesson plans and virtual classes in the same system as your results and records. Students learn from the app, parents can follow along, and the admin sees it all.",
    seoTitle: "School LMS for Nigerian Schools: Materials, Assignments, Virtual Classes",
    seoDescription: "A learning management system inside SchoolPilot: learning materials, assignments with online submission, lesson plans, virtual classes on Zoom or Google Meet and an AI tutor.",
    problems: [
      "Students go home with nothing to revise from but their exercise books.",
      "Homework is set on the board and half the class never copies it down.",
      "When school is closed, learning stops.",
    ],
    steps: [
      { title: "Publish learning materials", text: "Teachers upload notes, slides and worksheets per subject and class; they can be reviewed before students see them, and teachers see who opened them." },
      { title: "Set assignments", text: "Students submit online and get work back with feedback. Scores can count towards CA." },
      { title: "Plan lessons", text: "Teachers write lesson plans, with AI Studio drafting a starting point, and submit them for review." },
      { title: "Hold virtual classes", text: "Schedule lessons on Zoom or Google Meet; attendance is read from the meeting report." },
      { title: "Revise with the AI tutor", text: "Students ask questions and get explanations, within limits your school sets." },
    ],
    benefits: [
      { title: "One system, not another app", text: "The LMS uses the same students, classes and subjects as your results." },
      { title: "Revision at home", text: "Every subject's materials on the student's phone." },
      { title: "Homework that arrives", text: "Assignments are on the app, not just the board." },
      { title: "School keeps going", text: "Virtual classes when the school can't open." },
    ],
    roles: ["Teachers", "Students", "Parents", "Admin"],
    callout: {
      title: "AI that supports teachers, not replaces them",
      text: "The AI tutor helps students revise; it doesn't replace the teacher and can make mistakes. AI Studio drafts are a starting point that teachers edit.",
    },
    shot: { src: "/screens/student-app-phone.webp", alt: "Student app showing today's materials, pending assignments and the next virtual class", phone: true },
    faq: [
      { id: "cost", q: "Is the LMS included free?", a: "Yes. Learning materials, assignments, lesson plans, AI Studio and the AI tutor are part of the free core." },
      { id: "separate", q: "Is it a separate LMS we have to set up?", a: "No. It's part of SchoolPilot, using the classes, subjects and students you already have." },
      { id: "video", q: "Do we need our own Zoom or Meet account?", a: "Yes, virtual classes use your school's Zoom or Google Meet; SchoolPilot schedules them and reads attendance." },
      { id: "ai-safe", q: "Is the AI tutor safe for students?", a: "It's for study help only. Your school chooses which sections and subjects can use it, sets a daily message limit, and can lock it during exams." },
    ],
    related: ["cbt", "results-report-cards", "school-administration"],
  },
  {
    slug: "school-administration",
    name: "School Administration",
    title: "Run the whole school office from one system.",
    sub: "Admissions, attendance, timetables, staff and payroll, communication, boarding, library, transport and ID cards, all on the same records as your results, CBT and LMS.",
    seoTitle: "School Administration Software for Nigerian Schools",
    seoDescription: "Admissions, attendance, timetables, HR and payroll, parent communication, hostel, library, transport and ID cards in one school management system.",
    problems: [
      "Every office keeps its own register, and none of them agree.",
      "Admission forms, staff files and payroll sheets live in different cupboards.",
      "Parents hear about absences and announcements late, or not at all.",
    ],
    steps: [
      { title: "Admit online", text: "Parents apply with documents; review, entrance assessment, offer and enrolment create the student record." },
      { title: "Take attendance", text: "Teachers mark the register on a phone; parents of absent children are alerted." },
      { title: "Plan the timetable", text: "Class and exam timetables, with venue clashes flagged." },
      { title: "Manage staff and payroll", text: "Staff records, leave, loans and monthly payslips." },
      { title: "Keep everyone informed", text: "Messages and notifications to parents and staff from the same system." },
    ],
    benefits: [
      { title: "One set of records", text: "Admissions, attendance, staff and fees all point at the same student and staff records." },
      { title: "Boarding and more", text: "Hostel and sick bay, library, bus tracking from the driver's phone, and ID cards, when you need them." },
      { title: "Roles and permissions", text: "Each staff member sees what their role needs; custom roles for your school's structure." },
      { title: "Year to year", text: "Session rollover carries classes and students into the new session." },
    ],
    roles: ["Admin", "Bursar", "Teachers", "Parents"],
    callout: {
      title: "No hardware, ever",
      text: "No fingerprint or card readers. Attendance runs on teachers' phones and bus tracking on the driver's phone.",
    },
    shot: { src: "/screens/admin-dashboard.webp", alt: "School admin dashboard showing today's attendance, fees collected this term and result progress" },
    faq: [
      { id: "modules", q: "Do we have to use every module?", a: "No. Switch on what you need; everything shares the same records when you add more." },
      { id: "boarding", q: "We're a day school. Is the boarding side in the way?", a: "No. Hostel and sick bay only appear if your school uses them." },
      { id: "staff", q: "Is payroll included?", a: "Yes. Staff records, salary structures, leave, loans and payslips are part of the free core." },
    ],
    related: ["student-management", "fees-finance", "lms"],
  },
];

export function getFeature(slug: string) {
  return features.find((f) => f.slug === slug);
}
