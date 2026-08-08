# CBT Authoring: Images, Maths, and Question Types

How to author questions that carry pictures and equations, and what the engine
does with them. Companion to §7.8 and §7.9 of the product documentation.

---

## 1. Images

### Uploading

```
POST /api/v1/cbt/media          multipart: file, alt_text, caption?
GET  /api/v1/cbt/media
DELETE /api/v1/cbt/media/{id}
```

`alt_text` is **required**. This is a deliberate friction point: a labelled
biology diagram with no description is a question a candidate using a screen
reader cannot answer, and alt text is never retrofitted across a bank of 4,000
questions. The only moment it can realistically be collected is upload.

What happens to an upload:

| Step | Why |
|---|---|
| Type sniffed from the bytes, not the filename | A `.png` extension on a PHP file is the oldest upload trick there is |
| JPEG, PNG, WebP, GIF accepted; **SVG refused** | SVG is a script container, not a picture |
| Max 5 MB, max 5000px on either edge | Exam halls run on slow connections and old lab machines |
| EXIF/XMP metadata stripped | A phone photo of a whiteboard carries GPS coordinates and a device serial — personal data about a staff member, collected by accident, kept forever. NDPA data minimisation (doc §12) |
| SHA-256 of the stored bytes; identical images deduplicated per school | The same diagram across twenty questions is one file and one download for the offline client |
| Thumbnail generated where an image extension is available | Skipped, not fatal, on hosts without GD |

Metadata stripping walks the container format directly rather than re-encoding
through GD: GD is not guaranteed present on a school's shared hosting, and a
decode/re-encode round-trip visibly degrades the fine lines in a scanned
geometry diagram.

Deleting an image that a question still references is refused, with the list of
question IDs — a labelling question whose diagram vanished is unanswerable.

### Attaching images to a question

`media` is a list of references. Each has an `asset_id`, a `role`, and an
optional `position`.

| Role | Renders as |
|---|---|
| `stem` | Image(s) under the question text |
| `option:A`, `option:B`, … | Image attached to that specific option |
| `diagram` | The working surface for `diagram_label` and `hotspot` questions |
| `explanation` | Shown only after the attempt is submitted |

```json
{
  "subject_id": 4,
  "question": "Identify the labelled structure.",
  "question_type": "multiple_choice",
  "options": { "A": "Xylem", "B": "Phloem", "C": "Cambium", "D": "Cortex" },
  "correct_answer": "A",
  "media": [{ "asset_id": 12, "role": "stem", "position": 0 }]
}
```

An asset belonging to another school is refused. `explanation`-role images are
withheld from the candidate's paper and released with the result.

### Offline delivery

`GET /api/v1/cbt/exams/{id}/offline-package` returns the paper plus a media
manifest: every asset's URL, SHA-256, and byte size, with a total. The offline
lab client pre-downloads against it and can verify what it cached. The total is
there so an admin can see "this paper is 14 MB" *before* pushing it to twenty
machines over a phone hotspot.

The offline package deliberately does **not** include the marking scheme. The
client collects responses; the server grades them on sync.

---

## 2. LaTeX maths

Write maths inline in the question, options, or explanation using standard
delimiters:

| Delimiter | Mode |
|---|---|
| `$ … $` | inline |
| `\( … \)` | inline |
| `$$ … $$` | display |
| `\[ … \]` | display |

```json
{
  "question": "Solve for $x$: $$x^2 - 5x + 6 = 0$$",
  "options": { "A": "$x = 2, 3$", "B": "$x = 1, 6$" }
}
```

An escaped `\$` is a literal dollar sign, not a delimiter — so a word problem
about prices in US dollars works without fighting the parser. (Money in this
product is Naira; `₦` needs no escaping at all.)

### content_format

The server sets `content_format` to `latex` when it finds maths in **any** of
the stem, options, or explanation, and to `plain` otherwise. Clients use it to
skip KaTeX parsing on questions that are pure prose.

Detecting from the options matters more than it sounds: in a maths paper the
stem is often plain English ("Simplify the following") and every equation lives
in the options.

### What is rejected

Validation runs at authoring time, on every field a client will hand to a maths
renderer. Rejected:

- **Unbalanced delimiters.** An unclosed `$` turns the rest of a paper into
  maths. Catching it in the editor beats catching it at 9am on exam day with
  200 candidates seated.
- **Unmatched or over-nested braces** (limit: 20 deep).
- **Macros that inject HTML or reach outside the expression:**
  `\htmlClass`, `\htmlId`, `\htmlStyle`, `\htmlData`, `\href`, `\url`,
  `\includegraphics`, `\def`, `\gdef`, `\edef`, `\xdef`, `\newcommand`,
  `\renewcommand`, `\let`, `\csname`, `\expandafter`, `\noexpand`, `\input`,
  `\include`, `\write`, `\openin`, `\openout`, `\read`, `\immediate`,
  `\catcode`, `\special`, `\loop`, `\repeat`, `\usepackage`, `\documentclass`.
- Expressions over 1,000 characters, or more than 40 per field.

KaTeX is safe by default but grows unsafe once `trust` is enabled, and some
clients render with MathJax instead. None of these macros belong in a question a
teacher typed, so they are refused rather than escaped.

### Rendering

The server validates and stores; it does not render. Clients render with KaTeX
(web: `web/src/components/RichContent.tsx`) or an equivalent.

For search indexing and for prompts sent to the Claude API,
`MathContentService::stripMath()` replaces expressions with a `[maths]`
placeholder — raw TeX confuses both and wastes tokens.

---

## 3. Question types

| Type | Response shape | Marking |
|---|---|---|
| `multiple_choice` | `{"option": "B"}` | Exact match on `correct_answer` |
| `image_choice` | `{"option": "B"}` | As above; options carry `option:X` images |
| `true_false` | `{"value": true}` | |
| `multiple_response` | `{"options": ["A","C"]}` | All-or-nothing by default; `answer_schema.partial_credit` gives `(hits − misses) / available`, floored at 0, so ticking every box earns nothing |
| `fill_blank` | `{"text": "photosynthesis"}` | Matched against `answer_schema.accepted[]`, a *list* of spellings — a candidate should not lose a mark to a hyphen. `case_sensitive`, `ignore_punctuation` |
| `numeric` | `{"value": 9.8, "unit": "m/s²"}` | `answer_schema.value` ± `tolerance` (`tolerance_type`: `absolute` or `percent`). `require_unit` to enforce units |
| `matching` | `{"pairs": {"1":"b"}}` | `answer_schema.pairs`; partial credit **on** by default |
| `ordering` | `{"order": ["c","a","b"]}` | `answer_schema.order`; partial credit counts items in correct position |
| `diagram_label` | `{"labels": {"z1":"stamen"}}` | `answer_schema.zones[]` — `{id, label \| accepted[], x, y, w, h}`. Partial credit per zone, on by default |
| `hotspot` | `{"x": 0.42, "y": 0.31}` | `answer_schema.zones[]` — `rect` or `circle`, in **normalised 0–1 coordinates** so a lab monitor and a phone produce comparable answers |
| `theory` | `{"text": "…", "media_asset_ids": [12]}` | Held for a teacher. `allow_image_answer` lets a candidate photograph their working — phone camera, no hardware |

Marking conventions that hold across all types:

- A **blank** answer scores 0 and never attracts a negative mark. JAMB-style
  negative marking punishes a wrong guess, not an honest omission.
- A partially correct answer carries its fraction of the marks but is never
  reported as `is_correct`.
- A paper's total is floored at 0 — negative marking cannot produce a negative
  report-card score.

The candidate-facing payload includes an `interaction` object with the geometry
a client needs to draw drop zones, the label pool, and the matching columns —
but never the correct labels, pairs, or accepted spellings.

---

## 4. What the candidate never receives

While an attempt is open, the served paper has `correct_answer`, `explanation`,
`explanation`-role media, and the answer half of `answer_schema` removed.
Anything else is a marking scheme published to DevTools.

After submission, release is governed by the exam's `show_results_immediately`
flag. Even then, the correct answer itself is staff-only — the first candidate
out of the hall should not be handing the marking scheme to the queue outside.

---

## 5. Exam delivery

**The server owns the clock.** `server_deadline_at` is set once, at start, from
server time. Nothing a client sends extends it. A candidate who closes the
laptop for ten minutes loses ten minutes, exactly as they would in a hall. Time
elapsed on a save or resume auto-submits the paper.

**Shuffling is deterministic, not random.** Each attempt gets a seed; question
and option order derive from it. A candidate whose power cuts resumes to the
same paper, and an invigilator reviewing a dispute can reproduce exactly what
that candidate saw. It also means we do not snapshot 60 questions per attempt.

**Offline and online share one code path.** `recordAnswers` handles both, so an
offline batch cannot take a shortcut the live path forbids. Out-of-order
batches resolve by `client_sequence` (monotonic per device, survives a clock
change), falling back to `client_timestamp`. An answer with no ordering
information never overwrites one that has some, and a finalised attempt accepts
nothing — a late batch must not rewrite a marked script.

**Invigilation is hardware-free.** The client reports focus loss, paste,
fullscreen exit, and network drops; the server records and counts them. Nothing
auto-voids an attempt. A candidate whose phone rang is not a cheat, and a false
accusation costs a school far more than a missed one — a teacher reads the
flags and decides.

**Published papers are frozen.** Questions cannot be attached, detached, or
edited once an exam is published, and neither can a question's diagram. A
labelling question with a different image is a new question.

---

## 6. Analytics

`GET /api/v1/cbt/exams/{id}/results` returns the cohort view: score
distribution, pass rate, per-candidate rows with integrity flags, and item
analysis.

**Topic breakdown** is on every result: which topics a candidate or class lost
marks in. "The class lost this paper on simultaneous equations, not on algebra
generally" is something a teacher can act on before the next lesson.

**Item analysis** reports the classical facility index (p) — the proportion who
got each question right. A very low p on material that was actually taught
usually means the question is broken, not the class. `question_bank` also
carries running `times_answered` / `times_correct` counters so difficulty is
known across every sitting, not just one.
