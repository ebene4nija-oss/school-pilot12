# Designing a school's report card

**For:** whoever at SchoolPilot turns a school's design request into a template.
**Engine rules:** [report-card-template-contract.md](report-card-template-contract.md). This page covers the workflow and how to read a school's brief; the contract covers what the markup may contain.

---

## 1. The workflow

1. **The school sends a request** from *Report card & grading → Design request*:
   - Files: their current report card (a blank one, or with the pupil's details blacked out), their crest, and optionally signatures and a stamp.
   - A structured brief: which sections to show, how marks are split, the trait lists, paper size, colours, motto and notes.
   - Their grading scale, per class level where their sections grade differently.
2. **It appears in your queue** at `/super-admin/report-card-requests`. Click **I'm working on this** so no one else builds the same card.
3. **Build the template** to the contract. Start from the shipped default design; `GET /api/v1/report-cards/template-contract` returns its body and styles, plus the sample data it renders against.
4. **Check the grading scales** on the request. Fix obvious slips (a gap between 49 and 50, a duplicated grade) and **Save scales**. They take effect only when the school approves.
5. **Upload the bundle** with an optional note to the school. It is validated and test-rendered on upload. If it fails, the reason is shown and nothing changes. If it passes, it becomes a draft for that school and the request moves to **With the school**.
6. **The school previews and decides.** They see the card rendered with sample data (never a real pupil).
   - **Approve:** the design goes live, the grading scales are applied, and the current term can be re-graded.
   - **Send back:** you see their comments, and the request returns to your queue. Upload a new version; it replaces the old draft.
7. **Once approved or cancelled, the school's files are deleted.** Download anything you need to keep while you work, and don't keep copies of sample cards afterwards. A used report card is a child's record.

You never make a design live yourself. The school approves what it has seen.

---

## 2. Reading the brief

| Brief item | Where it goes in the template |
|---|---|
| Position in class | `{{ student.position }}` of `{{ student.class_size }}` |
| Position per subject, class average, highest/lowest | columns from `scores[]`: `position`, `class_average`, `class_highest`, `class_lowest` |
| Attendance | `attendance.present`, `attendance.absent`, `attendance.total`, `attendance.percentage` |
| Grading key | loop over `grading_scale[]` (`grade`, `range`, `remark`). It is the school's own scale for the pupil's class level. |
| Passport photo | `student.photo_url`, inside an `{{#if}}` so a pupil without a photo leaves no broken box |
| Crest | Embed the crest the school sent as a `data:image/png;base64,…` URI in the template. A remote `school.logo_url` shows in the preview but not in the PDF, because the PDF renderer does not fetch remote files. |
| Verification QR | required by the engine; always present |
| Signatures / stamp | Embed the images the school sent as `data:image` URIs. Crop them tight on white first. Keep all embedded images together under about 300 KB: the template body is capped at 500 KB, and embedding adds about a third to each image. With no image, leave a ruled signature line. |

### Marks split

The brief lists the school's components (for example `1st CA 20 + 2nd CA 20 + Exam 60`). Score entry today stores **two CAs and an exam** (`scores[].first_ca`, `second_ca`, `exam`, `ca_total`, `total`). The school's maximum marks are set in its CA scheme.

- **Two CAs + exam:** label the columns with the school's own names and maximum marks.
- **More than two CAs** (for example 3 × 10 + 70): the data can't show them separately yet. Show a single **CA total** column (`ca_total`), labelled with the combined maximum, and say so in your note to the school. Recording each CA separately is a planned change to score entry.

---

## 3. Sections the school can ask for but the product cannot fill yet

The template engine has slots for these, but **nothing in SchoolPilot lets a teacher enter them yet**. On real report cards they render empty:

- affective traits (`traits[]`) and psychomotor skills
- class teacher's remark (`comments.class_teacher`) and principal's remark (`comments.principal`)
- next term begins (`term.next_term_begins`) and next term fees

Per-subject teacher remarks (`scores[].teacher_comment`) do work. They include approved AI remarks, and never unapproved ones.

Design these sections into the card if the school asked for them, so the layout is final. Wrap each in `{{#if}}` so an empty section collapses rather than printing blank boxes, and tell the school in your note that those parts will fill in once their data-entry screens ship.

---

## 4. Practical checks before upload

- Preview at the school's paper size and orientation (`page` in the bundle). Most Nigerian schools print A4 portrait.
- A class of 15 subjects must still fit on one page. Test by adding rows to `sample_data` in the bundle.
- Use no web fonts. Embed every image as a `data:image` URI: remote images are dropped from the PDF, and the card must print on a machine with no internet.
- Prefer black text on white with the school colours as accents. Cards are photocopied.
- Keep the grading key readable. Some schools have 9 grades, and it needs to fit.
- Name the bundle after the school (`"name": "Graceland Terminal Report"`). Leave `version` out and the next number is assigned.
