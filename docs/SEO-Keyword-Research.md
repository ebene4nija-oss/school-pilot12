# SchoolPilot SEO: keyword research and page map

Researched 2026-10-09 from live Nigerian search results (school management, result computation, CBT, fees, pricing queries). No paid keyword tool (Ahrefs, Semrush, Google Keyword Planner) was available, so **there are no search-volume numbers here**. Intent and competitor presence are from the results pages. Pull volumes from Google Keyword Planner or Search Console before spending on content.

## What the results pages show

- The page-one field is a mix of local vendors (eSchool.ng, AcadaSuite, Nersapp, SchoolHub, MySkool), an international one localised for Nigeria (OpenEduCat), and listicles ("best school management software Nigeria 2026", Nairametrics, headteacher.ng). Listicles take a lot of the commercial queries.
- Competitors lead with "free". Nersapp advertises a free core and charges roughly ₦230 per active student for result generation. Our ₦300/₦500 per student per term is not the cheapest headline, so the pages win on **breadth in one product** (results + CBT with an offline lab client + LMS + fees + HR), **Nigerian grading specifics** (CA, broadsheet, positions, WAEC/NECO), **free migration in 24 hours**, and **no per-exam CBT fees**, not on price alone.
- CBT intent is rising: WAEC/NECO CBT compliance for 2026 is in the news, and schools are asking how to run CBT on their own labs. SchoolPilot's offline lab client matches that.
- "Pricing" queries ("school portal price Nigeria", "school management software cost") have thin answers from competitors, because most hide prices. A page that states Naira prices clearly is a real differentiator and can earn a rich result.

## Keyword clusters and the page that owns each

One primary page per cluster, so pages do not compete with each other.

| Cluster | Target keywords (intent) | Owning page |
| --- | --- | --- |
| Head term | school management system Nigeria; school management software Nigeria; school portal software Nigeria (commercial) | `/` |
| Pricing | school management software price Nigeria; school portal cost per student; school management system pricing Naira; school software per term (commercial, high intent) | `/pricing/` |
| Results | result management software for schools Nigeria; school result computation software; broadsheet software; report card software Nigeria; CA and exam result system; WAEC-style grading scale software (commercial) | `/features/results-report-cards/` |
| CBT | CBT software for schools Nigeria; offline CBT software for schools; school CBT exam software; WAEC NECO CBT practice for schools; CBT computer lab software (commercial/informational) | `/features/cbt/` |
| Fees | school fees management software Nigeria; school fees payment app; school fee instalment software; collect school fees online Nigeria (commercial) | `/features/fees-finance/` |
| Students | student management system Nigeria; student information system for Nigerian schools (commercial) | `/features/student-management/` |
| LMS | school LMS Nigeria; e-learning platform for secondary schools; virtual classroom for schools Nigeria (commercial) | `/features/lms/` |
| Administration | school administration software Nigeria; school HR and payroll software; school admission portal; school timetable software (commercial) | `/features/school-administration/` |
| Boarding | boarding school management software; hostel management software for schools (commercial) | `/features/school-administration/` (hostel section) |
| Switching | switch school portal; migrate school data; change school management software (transactional) | `/migrate/` |
| Proprietors | school owner dashboard; monitor school from phone; proprietor app for private schools (commercial) | `/solutions/school-owners/` |
| Trust | NDPA school data; student data protection Nigeria (informational) | `/trust/` |

Long-tail phrases to mirror in FAQ answers and headings (these are question-shaped, so they suit FAQ schema): "How much does a school management system cost in Nigeria?", "What is the best school management software in Nigeria?", "Can a school run CBT without internet?", "How do I compute CA and exam scores for a broadsheet?", "How can parents check results with a PIN?".

## What changed in the site for this

- Title tags: each under about 60 characters including the "| SchoolPilot" suffix, primary keyword first.
- Meta descriptions: 140–160 characters, with the price where the page is about cost, and a reason to click.
- `/pricing/`: keyworded title and H1, `Product` + `Offer` structured data (real prices only), `FAQPage`, `BreadcrumbList`.
- Site-wide: `SoftwareApplication` now carries an `AggregateOffer` (₦300–₦500, NGN) and `WebSite`; per-page Open Graph.
- Feature pages: `BreadcrumbList` and `FAQPage` structured data.
- Removed an unsupported FAQ claim that paying for a session costs less than three terms.

## Not done, and why

- No `aggregateRating` or review schema: there are no real, consented reviews (Landing PRD §14.4).
- No competitor-comparison or "best software" pages yet. They are the biggest remaining opportunity, since listicles own those queries, but they need claims about competitors that must be verified first.
- No Open Graph image: there is no 1200×630 asset in `public/brand/`. Add one and set `openGraph.images` in `src/app/layout.tsx`.
- Next steps for ranking: add the site to Google Search Console and Bing Webmaster, submit `/sitemap.xml`, create a Google Business Profile if the office address is public, and get the volumes above before commissioning blog content (suggested first posts: "How to compute CA and exam scores", "Running CBT in a computer lab without internet", "What a school management system costs in Nigeria").
