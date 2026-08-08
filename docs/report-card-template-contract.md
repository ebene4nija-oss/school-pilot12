# Report Card Template Contract

**Engine:** `schoolpilot-report-card/v1`

Every SchoolPilot school can code its own report card and import it. This
document is the contract that design must be written against: what the markup
may contain, what data it can reach, and what it is obliged to include.

A school that imports nothing gets the shipped default design, which is also
the worked example at the end of this document.

---

## 1. Design principles

These are not style preferences. They are the reasons the engine is shaped the
way it is, and knowing them explains most of the restrictions below.

**A report card is a legal-ish document, not a web page.** It is printed,
photocopied, signed, and produced years later in an admissions office. It must
render identically today and in 2031, on a machine with no internet. That is
why there is no JavaScript, no web fonts, no remote images, and no layout that
depends on a live network.

**Presentation is yours; the record is not.** A school owns its crest, its
colours, its trait grid, the order of its columns. It does not own whether the
verification QR appears, whether an unapproved AI remark can print, or what a
score means. Those are enforced by the engine and are not designable.

**Templates are data, never code.** A school-supplied file is rendered on our
servers, on infrastructure shared with every other tenant. Blade or Twig would
make an uploaded report card design a remote code execution vector. So the
dialect below has no expression evaluation at all — no function calls, no
arithmetic, no property access on objects. Anything it cannot express is
either supplied ready-made in the data model or is deliberately out of scope.

**Fail at import, not at print.** Schools generate report cards in one burst,
at the end of term, usually late, usually under pressure. Every check that can
run at import time does: parse errors, missing required content, unsafe
markup, and a full dry-run render against sample data. An imported template
has already been rendered once before anyone relies on it.

**Nothing goes live by accident.** An import lands as a `draft`. It becomes
the school's design only when an admin explicitly activates it, and exactly
one design is active at a time — so a term's cards cannot be printed half in
one layout and half in another.

---

## 2. The bundle

A bundle is a single JSON document. Upload it as the request body or as a
`bundle` file to `POST /api/v1/report-cards/templates`.

```json
{
  "engine": "schoolpilot-report-card/v1",
  "name": "Graceland Terminal Report",
  "slug": "graceland-terminal",
  "version": 1,
  "description": "Portrait A4, house colours, affective traits grid.",
  "page": {
    "size": "A4",
    "orientation": "portrait",
    "margins_mm": { "top": 12, "right": 10, "bottom": 12, "left": 10 }
  },
  "regions": ["header", "bio", "scores_table", "traits", "comments", "footer"],
  "styles": ".report { font-size: 11px; } ...",
  "body": "<section class=\"report\"> ... </section>"
}
```

| Field | Required | Notes |
|---|---|---|
| `engine` | yes | Must match exactly. A mismatched engine is refused, not coerced. |
| `name` | yes | Shown to admins. |
| `slug` | no | Derived from `name` if omitted. |
| `version` | no | Integer. Omit and the next version for that slug is assigned. Re-importing an existing `slug` + `version` is refused — bump the version to publish a revision. |
| `page.size` | no | `A4` (default), `A5`, `LETTER`, `LEGAL`. |
| `page.orientation` | no | `portrait` (default) or `landscape`. |
| `page.margins_mm` | no | Clamped to 0–50mm per edge. |
| `regions` | no | Declarative, for the admin UI. Does not affect rendering. |
| `styles` | no | CSS. Max 200 KB. |
| `body` | yes | Template markup. Max 500 KB. |

---

## 3. Template syntax

### Interpolation

```
{{ student.name }}
```

Always HTML-escaped. There is no unescaped variant — `{{{ ... }}}` is refused
at import.

### Filters

Chain with `|`. Arguments follow a colon and may be quoted.

| Filter | Example | Output |
|---|---|---|
| `naira` | `{{ fee \| naira }}` | `₦125,000.00` |
| `number:n` | `{{ score \| number:1 }}` | `84.0` |
| `percent:n` | `{{ rate \| percent }}` | `93.5%` |
| `upper` / `lower` / `title` | `{{ school.name \| upper }}` | `GRACELAND COLLEGE` |
| `ordinal` | `{{ position \| ordinal }}` | `3rd` |
| `initials` | `{{ student.name \| initials }}` | `AN` |
| `date:"format"` | `{{ term.ends_on \| date:"j F Y" }}` | `11 December 2026` |
| `default:"…"` | `{{ nickname \| default:"—" }}` | fallback when null/empty |
| `trim` | `{{ note \| trim }}` | |
| `grade_colour` | `<td class="{{ this.grade \| grade_colour }}">` | `grade-excellent`, `grade-very-good`, `grade-credit`, `grade-pass`, `grade-fail` — a class name, so *your* CSS decides what an A1 looks like |

Date formats are PHP `date()` format characters.

### Iteration

```html
{{#each scores}}
  <tr><td>{{@number}}</td><td>{{ this.subject }}</td></tr>
{{else}}
  <tr><td colspan="2">No scores recorded.</td></tr>
{{/each}}
```

Inside a loop: `{{ this.field }}` for the current item, `{{@index}}`
(0-based), `{{@number}}` (1-based), `{{@first}}`, `{{@last}}`, `{{@length}}`,
and `{{ ../field }}` to reach the enclosing scope.

The `{{else}}` branch renders when the collection is empty. Use it — a term
with no scores entered yet is common in the first week.

### Conditionals

```html
{{#if comments.principal}}<p>{{ comments.principal }}</p>{{/if}}
{{#unless attendance.total}}<p>Attendance not recorded this term.</p>{{/unless}}
```

`{{#if}}` and `{{#unless}}` both support `{{else}}`. Truthiness: a non-empty
array, a non-empty string that is not `"0"`, a non-zero number.

### Comments

```
{{! Not rendered. }}
```

### Limits

Blocks nest 12 deep. Loops run 5,000 iterations in total per render. Output
caps at 4 MB. Exceeding any of these raises an error rather than an OOM.

---

## 4. Data model

This is everything a template can see. There is no way to query for anything
else.

```
school.name, .address, .phone, .email, .logo_url, .subdomain

student.name, .admission_number, .class, .arm, .gender, .photo_url,
        .position, .class_size

term.name, .starts_on, .ends_on, .session, .next_term_begins

summary.subjects_count, .total_score, .average, .overall_grade

attendance.present, .absent, .total, .percentage

scores[]  .subject, .subject_code, .first_ca, .second_ca, .ca_total, .exam,
          .total, .grade, .position, .class_average, .class_highest,
          .class_lowest, .teacher_comment

comments.class_teacher, .principal

traits[]  .name, .rating

grading_scale[]  .grade, .range, .remark

verification.token, .verify_url, .qr_code_url

generated_at
```

Two things worth calling out:

`scores[].teacher_comment` is **null unless the comment has been approved**.
An AI-generated remark sitting in `pending_approval` is not merely hidden from
the page — it never enters the data model, and the whole report card is
refused until it is reviewed. There is no template that can print one.

`verification.qr_code_url` is a `data:image/png` URI encoded **on this server**
— no third party ever sees a verification token, and a school with no internet
can still print a verifiable card. It is an empty string only if encoding
failed outright, so guard it with `{{#if}}` and always print
`verification.verify_url` as text alongside it.

Fetch the live data model for a deployment, with sample values, from
`GET /api/v1/report-cards/template-contract`.

---

## 5. Required content

A template is refused at import unless it references:

1. `student.name`
2. `scores` (the subject table)
3. `verification.` (the QR or the verification URL)

The third is the one schools ask about. Result forgery is endemic in the
Nigerian market and the QR-verifiable report card is a headline feature of the
product. It is not a design element a school can remove.

---

## 6. Allowed markup

**Tags:** `div span p br hr section header footer article main aside h1–h6
strong b em i u s small sub sup mark ul ol li dl dt dd table thead tbody tfoot
tr th td caption colgroup col img figure figcaption blockquote pre code abbr
time`

**Attributes:** `class id style title dir lang` on anything, plus `data-*`.
Per tag: `img[src alt width height]`, `td/th[colspan rowspan align valign
scope]`, `col[span width]`, `table[border cellpadding cellspacing]`,
`time[datetime]`, `ol[start type]`.

**Refused outright at import:** `<script>`, `<iframe>`, `<object>`,
`<embed>`, any `on*` handler, `javascript:` URLs, `<?php`, `@php`, `{{{ }}}`.

**Silently stripped at render:** any other tag or attribute, HTML comments,
and unsafe `img src` values. The import response's `validation_report` lists
everything that was stripped, so a designer can see what happened rather than
guess why a border vanished.

### Images

`img src` may be:

- a `data:image/png|jpeg|gif|webp;base64,…` URI — **the recommended way to
  embed a school crest**, since it needs no network at print time
- a relative or root-relative path (`/storage/crests/graceland.png`)
- an `https:` or `http:` URL — but note that the PDF renderer runs with remote
  fetching **disabled**, so a remote image will render in the HTML preview and
  be blank in the PDF. Do not rely on one.

Image rendering in the PDF requires PHP's **`ext-gd`**, which `composer.json`
now declares. Without it dompdf refuses any document containing an image — the
crest, the passport photo, and the verification QR all fail together — so a
deployment missing it fails at `composer install` rather than at print time.

`file:` URLs and protocol-relative `//host/…` URLs are removed. Both would let
a template make the server read something it should not.

An `<img>` with no `alt` gets an empty one rather than being dropped.

### CSS

Standard CSS, with these removed: `@import`, `expression()`, `behavior:`,
`-moz-binding`, `javascript:`/`vbscript:` URLs, and any `url()` pointing at a
remote scheme (`url()` to a `data:image` URI or a relative path is kept).

Page setup comes from `page` in the bundle; the engine emits the `@page` rule
itself. `body` is preset to a font stack the PDF renderer has available —
override it in your own CSS if you have embedded a font as a data URI.

---

## 7. Workflow

```
GET    /api/v1/report-cards/template-contract        # live data model + default design
POST   /api/v1/report-cards/templates                # import (lands as draft)
GET    /api/v1/report-cards/templates                # list, with validation reports
GET    /api/v1/report-cards/templates/{id}/preview   # renders with SAMPLE data
GET    /api/v1/report-cards/templates/{id}/preview?student_id=&term_id=
POST   /api/v1/report-cards/templates/{id}/activate  # archives the previous active one
DELETE /api/v1/report-cards/templates/{id}           # refused while active

GET    /api/v1/report-cards/{studentId}/{termId}           # HTML, active design
GET    /api/v1/report-cards/{studentId}/{termId}?format=pdf
```

Preview defaults to fictional sample data. Checking a border radius should not
put a real child's academic record on screen — pass `student_id` and `term_id`
only when you specifically need to see real data.

---

## 8. Worked example

The shipped default design is a complete, valid template. Fetch it from
`GET /api/v1/report-cards/template-contract` (`default_template.body` and
`default_template.styles`), or read it in
`backend/app/Services/ReportCard/ReportCardTemplateService.php`. Copying it
and editing is the fastest way to start.

A minimal valid template:

```html
<section class="report">
  <h1>{{ school.name | upper }}</h1>
  <p>{{ student.name }} — {{ student.class }} {{ student.arm }}</p>
  <p>{{ term.name }}, {{ term.session }}</p>

  <table>
    <thead>
      <tr><th>#</th><th>Subject</th><th>CA</th><th>Exam</th><th>Total</th><th>Grade</th></tr>
    </thead>
    <tbody>
      {{#each scores}}
      <tr>
        <td>{{@number}}</td>
        <td>{{ this.subject }}</td>
        <td>{{ this.ca_total | number:1 }}</td>
        <td>{{ this.exam | number:1 }}</td>
        <td>{{ this.total | number:1 }}</td>
        <td class="{{ this.grade | grade_colour }}">{{ this.grade }}</td>
      </tr>
      {{else}}
      <tr><td colspan="6">No scores recorded for this term.</td></tr>
      {{/each}}
    </tbody>
  </table>

  <p>Average: {{ summary.average | number:1 }} ({{ summary.overall_grade }}) ·
     Position {{ student.position | ordinal }} of {{ student.class_size }}</p>

  {{#if comments.class_teacher}}<p><strong>Class Teacher:</strong> {{ comments.class_teacher }}</p>{{/if}}

  <footer>
    {{#if verification.qr_code_url}}
    <img src="{{ verification.qr_code_url }}" alt="Result verification QR code">
    {{/if}}
    <p>Verify at {{ verification.verify_url }}</p>
  </footer>
</section>
```
