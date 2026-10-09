# SchoolPilot SEO: keyword research and page map

Researched 2026-10-09 from live Nigerian search results (school management, result computation, CBT, fees, pricing queries). No paid keyword tool (Ahrefs, Semrush, Google Keyword Planner) was available, so **there are no search-volume numbers here**. Intent and competitor presence are from the results pages. Pull volumes from Google Keyword Planner or Search Console before spending on content.

## What the results pages show

- The page-one field is a mix of local vendors (eSchool.ng, AcadaSuite, Nersapp, SchoolHub, MySkool), an international one localised for Nigeria (OpenEduCat), and listicles ("best school management software Nigeria 2026", Nairametrics, headteacher.ng). Listicles take a lot of the commercial queries.
- Competitors lead with "free". Nersapp advertises a free core and charges ₦500 per student for result generation (confirmed by the owner 2026-10-09; an earlier search snippet said about ₦230, which was wrong). That matches our ₦500 Result Management plan and is above our ₦300 Subscription, so price is not a weakness. The pages still win mainly on **breadth in one product** (results + CBT with an offline lab client + LMS + fees + HR), **Nigerian grading specifics** (CA, broadsheet, positions, WAEC/NECO), **free migration in 24 hours**, and **no per-exam CBT fees**. Check Nersapp's current terms before any comparison copy goes on the site, and don't name them in public copy until verified.
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
- Open Graph image: now set from the console (Super Admin > Website > "Default share preview image", at least 1200×630). Until one is uploaded, shared links have no preview.
- Next steps for ranking: add the site to Google Search Console and Bing Webmaster, submit `/sitemap.xml`, create a Google Business Profile if the office address is public, and get the volumes above before commissioning blog content (suggested first posts: "How to compute CA and exam scores", "Running CBT in a computer lab without internet", "What a school management system costs in Nigeria").

## Search Console: submitting the sitemap

The sitemap is at `https://schoolpilot.org.ng/sitemap.xml` and `robots.txt` points to it. It lists every public page, plus `/blog/`, each post and `/compare/` once they have content, with the real last-modified date for posts and a video entry for posts with a video. Submitting it needs your Google account:

1. Go to search.google.com/search-console and add a **URL-prefix** property for `https://schoolpilot.org.ng/`.
2. Choose the **HTML tag** method. Copy only the `content="…"` value (not the whole tag).
3. In Super Admin > Website, paste it into **Google Search Console verification token** and save, then rebuild and deploy the site.
4. Back in Search Console, click **Verify**.
5. Open **Sitemaps**, enter `sitemap.xml`, and submit.
6. Repeat on Bing Webmaster Tools if you want Bing: it can import the property from Search Console, or use the **Bing Webmaster verification token** field.

Submit again after a rebuild that adds posts only if you want it picked up faster; Google re-reads the sitemap on its own.
