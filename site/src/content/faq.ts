/**
 * The /faq page (Landing spec §6, "/faq: the full question set"). It opens
 * with the six homepage objections, then the rest by topic. Answers are
 * limited to what is true today.
 */
import { objections, type Faq } from "./home";

export const faqGroups: { title: string; items: Faq[] }[] = [
  { title: "What owners ask first", items: objections },
  {
    title: "The product",
    items: [
      { id: "what", q: "What is SchoolPilot?", a: "An all-in-one school management system: results, CBT with a computer-lab client, an LMS, fees and school administration, on the web and on Android and iOS." },
      { id: "all-in-one", q: "Do we still need separate CBT or LMS software?", a: "No. CBT, the computer-lab client and the LMS are part of SchoolPilot and use the same students, classes and subjects as your results." },
      { id: "who", q: "Who is it for?", a: "Private Nigerian schools, Nursery to SS3, from about 50 to 3,000 students." },
      { id: "sections", q: "Can each section run differently?", a: "Yes. Nursery, Primary, JSS and SS each get their own assessment scheme; nursery can use developmental ratings instead of scores." },
      { id: "hardware", q: "Do we need to buy any machines?", a: "No. Nothing to buy, ever. Attendance and transport use phones; CBT uses the computers you already have." },
    ],
  },
  {
    title: "Academics",
    items: [
      { id: "ca", q: "Can we set our own CA structure?", a: "Yes: the number of CAs, their weights and your grading, per section." },
      { id: "cbt", q: "Does it do CBT?", a: "Yes, online and offline on your own lab computers." },
      { id: "reports", q: "Report cards and broadsheets?", a: "Yes: broadsheets with class and subject positions, report-card templates, and comments a teacher approves." },
      { id: "learning", q: "Is there an LMS?", a: "Yes, built in: learning materials, assignments with online submission, lesson plans, virtual classes on Zoom or Google Meet, and an AI tutor for revision." },
    ],
  },
  {
    title: "Parents, teachers and students",
    items: [
      { id: "no-smartphone", q: "What about parents without smartphones?", a: "They can check results with a PIN on your school's result checker, receive printed report cards, and get SMS alerts where your school has SMS switched on." },
      { id: "teachers-phone", q: "Can teachers work from their phones?", a: "Yes: registers, scores and assignments in the mobile app." },
      { id: "students", q: "What do students get?", a: "Their timetable, assignments, learning materials, CBT practice, results and the AI tutor." },
    ],
  },
  {
    title: "Getting started",
    items: [
      { id: "setup", q: "How long does setup take?", a: "We migrate your records within 24 hours. Then a setup wizard guides your admin through the rest." },
      { id: "import", q: "Can we bring our existing data?", a: "Yes: from Excel or CSV, from paper using our templates, or from another portal. Free." },
      { id: "training", q: "Will you train our staff?", a: "Yes, as part of onboarding." },
    ],
  },
  {
    title: "Cost",
    items: [
      { id: "paid", q: "What exactly do we pay for?", a: "A per-student charge each term: School Management with Result Management at ₦500, or the School Management Subscription at ₦300 after a 30-day free trial. School Management with Boarding School Management is ₦1,000 per term on either plan. CBT is included, with no per-exam fees." },
      { id: "stop", q: "What happens if we stop paying?", a: "Your data stays accessible and you can export all of it." },
      { id: "session", q: "Is billing per term or per session?", a: "Per term, for the students enrolled that term. There is no separate session fee." },
      { id: "how-much", q: "How much does a school management system cost in Nigeria?", a: "SchoolPilot is ₦300 per active student per term on the School Management Subscription, or ₦500 with Result Management. See the pricing page for worked examples." },
    ],
  },
  {
    title: "Security and data",
    items: [
      { id: "protect", q: "How is our data protected?", a: "Roles and custom roles, two-factor sign-in for admins, audit logs, login history and encryption of sensitive fields. See Security & trust." },
      { id: "biometrics", q: "Do you use fingerprints or face scans?", a: "No. Never." },
      { id: "export", q: "Can we take our data and leave?", a: "Yes, a one-click export of everything, any time." },
    ],
  },
];
