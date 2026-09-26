# Apex Report

A navy-and-gold report card, one A4 page per term, for first and second term.

- `apex-report.json` is the bundle you upload. Upload it for a school from **Report card requests** (`/super-admin/report-card-requests`, then **Upload the template**). The school then previews it and approves it.
- `body.html` and `styles.css` are the source. Edit these, then rebuild the bundle: copy them into the `body` and `styles` fields of `apex-report.json`.
- `apex-sample.pdf` shows 17 subjects on one page. It uses made-up sample data.

**What the card shows:**
- School and term header, and the pupil's details. Attendance, position and "class champion" (first in class) appear as badges.
- Subjects grouped into core and other. Each row has a mark for each CA and the exam, total (shaded by score), class average, highest, position and grade (coloured per grade).
- A term-average row.
- Standards (ratings).
- The school's own grading key, in its own colours.
- Form teacher's and principal's comments over signature lines, and a stamp.
- The verification QR code and footer.

**Filled from the school's own setup:**
- A column for each part of the school's marking scheme (two, three or more CAs and the exam).
- Affective traits and psychomotor skills with the school's rating key, from **Ratings & comments**. Hidden for a pupil with no ratings yet.
- Form teacher's and head of school's comments, with the school's title for its head (e.g. Head Teacher). A comment the school switched off is left off the card. An empty one prints as a line to write on.

**Fits one page** for up to about 20 subjects. Long per-subject teacher remarks take extra space.

**Colours per grade** cover letter scales (A, B+, B, C, D, E, F) and WAEC (A1–F9). For any other grade, add a `.gk-<grade>` rule to `styles.css`. `B+` becomes `.gk-Bplus`.
