# Offline CBT Client — Design & Build Specification

**Status:** §19 steps 1–3 are built and tested, and step 4 is done but for
pairing — a candidate can sit a whole paper, maths and all, in a fullscreen
kiosk window, over TLS whose certificate the client pins. Pairing (§8.4) and the
softAP spike (§3.2.1) are what remain of it; see §19.
**Target:** a desktop application that runs a published CBT exam on a school's
existing computer-lab PCs with no internet connection during the paper.
**Owner:** unassigned. **Last updated:** 2026-08-15.

Built so far — §9.1 bundle issuance, §9.2 key release, §9.3 batch sync, §9.4
question groups, §6.3/§6.4 theory answer modes, §6.6 grouping rules, and the
§10 parity vectors, the lab relay, and a candidate client that can sit a paper —
`desktop/relay`, see its [README](../desktop/README.md). `relay demo` runs a
sample paper end to end with no backend, which is the quickest way to see what
exists. Steps 5–11 (paper scripts, Teacher's Desk, AI suggestions, kiosk,
packaging, pilot) are still design only. See §19 for each step's state.

**A school with no cabling can still run this.** The requirement is that the
relay and the lab machines share a local network — wired or wireless, with or
without internet. §3.2 covers both the case where the school has one and the
case where the relay has to provide it.

Related: [CBT authoring guide](cbt-authoring-guide.md) ·
[Comprehensive documentation §7.8](SchoolPilot-Comprehensive-Documentation.md) ·
`backend/app/Services/CbtExamService.php` ·
`backend/app/Services/CbtGradingService.php` ·
`backend/app/Services/CbtOfflineBundleService.php` ·
`backend/tests/fixtures/cbt-shuffle-parity.json`

---

## 1. What this is, and what it is not

Exam day in a Nigerian private school rarely comes with reliable internet. A
paper that dies halfway through because the line dropped is worse than no CBT at
all — it is a room of forty candidates, a lost sitting, and a conversation with
parents. This client exists so that the *only* moments requiring connectivity
are before and after the exam, never during it.

**It is:** software installed on computers the school already owns.

**It is not:** a reason to buy anything. Per the hard rule in the root
`CLAUDE.md`, no purchased hardware — no exam-hall server appliance, no dongles,
no locked-down thin clients. If a design step seems to need one, the step is
wrong.

### 1.1 Scope

In scope:

- Running a published exam (`cbt_exams.allow_offline = true`) with zero
  internet, in a lab that has a network **and in one that has none at all**
  (§3.2).
- All auto-graded question types the web CBT runner already supports, including
  images and LaTeX maths.
- **Grouped questions** — a shared passage, diagram or data set with several
  sub-questions hanging off it (§6.2).
- **Theory and essay questions**, answered either on screen or on paper (§6.3,
  §6.4).
- **Paper scripts** captured after the exam and routed to the marking teacher
  (§6.5).
- **Optional AI-assisted marking** of theory answers, always subject to teacher
  approval (§7.3).
- Resuming a candidate whose machine died mid-paper.
- Queueing answers, scripts and integrity events, and syncing them after.

Out of scope for v1:

- Authoring. Papers and marking schemes are built in the web portal, never here.
- Auto-grading in the exam room. All grading — objective and theory — happens
  server-side after sync (§5.4).
- Any question type the web runner does not already support.

### 1.2 A note on where the work lands

Sections 6 and 7 describe capabilities that are **platform-wide, not
offline-only**: grouped questions, theory answer modes, paper-script capture and
the Teacher's Desk all belong in the web portal and the mobile app too. They are
specified here because the offline client cannot ship without them, and because
designing them offline-first surfaces constraints (no answer keys in the room,
deferred grading) that the online path should inherit rather than contradict.

Whoever picks this up should expect roughly half the backend work in §9 to
benefit the online CBT runner immediately, before any desktop code exists.

---

## 2. The constraint that shapes everything

The backend deliberately has **no endpoint that returns question text ahead of
an attempt.** Papers are assembled only inside `startAttempt` →
`buildCandidatePaper`, after the exam has opened and the attempt row exists. The
existing `offlinePackage` endpoint is media-only for candidates, and for staff
returns exam metadata, a question count and a media manifest — *not the
questions themselves*. `CbtController::candidateOfflinePackage` says so
explicitly: "There is no second, laxer way into a paper."

That is a good rule and this client must not become the exception to it. But it
collides head-on with the requirement: you cannot simultaneously have

1. no network during the exam, and
2. no question text on any lab machine before the exam starts.

Something has to give. **What gives is (2), under encryption** — question text
may sit on disk beforehand, but only as ciphertext whose key does not arrive
until the exam opens. Everything in §8 follows from that single decision.

---

## 3. Architecture: one relay, not fifty clients

```
                    ┌──────────────────────────┐
   internet         │   SchoolPilot backend    │
   (before & after  │   /api/v1/...            │
    the exam only)  └────────────┬─────────────┘
                                 │  ① provision   ⑤ sync
                                 │
                    ┌────────────┴─────────────┐
                    │      LAB RELAY           │   invigilator's laptop or
                    │  encrypted bundle        │   one designated lab PC
                    │  SQLite: attempts,       │
                    │  answers, scripts, events│
                    └────────────┬─────────────┘
                                 │  lab network: wired, Wi-Fi,
                                 │  or relay hotspot (no internet)
              ┌──────────┬───────┴───────┬──────────┐
              │          │               │          │
          ┌───┴───┐  ┌───┴───┐       ┌───┴───┐  ┌───┴───┐
          │ PC 01 │  │ PC 02 │  ...  │ PC 39 │  │ PC 40 │   candidate clients
          └───────┘  └───────┘       └───────┘  └───────┘
```

**The paper lives on one machine, not forty.** Candidate clients are thin: they
render questions served by the relay over the LAN and post answers back to it.
The relay is the only component that holds the bundle, the only one that ever
talks to the internet, and the only one that survives a candidate machine dying.

### 3.1 Why not a standalone per-PC client

The obvious design — install the app on every lab PC, each downloading its own
bundle — was rejected:

| Problem | Relay model | Per-PC model |
|---|---|---|
| Paper on disk before exam | 1 machine to secure | 40 machines to secure |
| Candidate PC dies mid-paper | Move seats, resume from relay | Attempt lost with the disk |
| Key release | Once, on one machine | 40×, or one shared key that leaks |
| Post-exam sync | One queue, one upload | 40 machines must each get online |
| Bandwidth to provision | 1 download | 40 downloads, or manual USB copying |

The per-PC model's failure mode is also the worst one: a candidate whose machine
dies has no recoverable state, because the only copy of their answers was on the
disk that failed.

This rejection was revisited when the requirement to support networkless schools
came up, and **it held**. A per-PC design means importing the paper to every
machine and collecting answers from every machine, by hand, for every exam — an
hour of an exam officer's time per sitting, with no recovery when one machine is
missed. A deployment model that only works when nobody makes a mistake is not a
deployment model. The relay stays the only architecture.

### 3.2 Two transports, one architecture

The relay and the candidate machines have to be on the same local network. That
is the whole requirement — **not a cable, and not the internet.** Any network
that carries traffic between them will do, and the tiers are about who supplies
it, not what it is made of.

| Tier | Where the network comes from | Typical room size |
|---|---|---|
| **A** | One the school already has — a switch, a router, or **any Wi-Fi access point in range of the lab** | Whatever the lab seats |
| **B** | The relay makes one itself, when the school has none | Constrained — §3.2.1 |

**Wi-Fi is Tier A, not Tier B.** If the lab machines can see the school's
wireless network — the office router, the admin block's AP, a MiFi someone
brings in — they join it, the relay joins it, and the room is served. A
consumer router handles thirty to sixty clients without complaint, so there is
no seat limit worth designing around in this case. Tier B exists only for a
school with *no* network anywhere, wired or wireless.

Two things to verify on any Wi-Fi network before the exam, both cheap and both
fatal if missed:

- **Client isolation must be off.** Guest SSIDs commonly block client-to-client
  traffic, which is exactly the traffic this design is made of. A candidate
  machine that can browse the internet but cannot reach the relay has hit this,
  and it looks like a broken app rather than a router setting.
- **The network needs no internet at all.** It is carrying a LAN conversation.
  A router with an expired data plan, or one never connected to a line, is a
  perfectly good Tier A network — worth saying to schools explicitly, because
  they will assume the opposite and rule themselves out.

**Both tiers are the same software.** Nothing above the socket changes: same
bundle, same key release, same relay-held SQLite, same batch sync, same resume
story. Tier B is a network-configuration step at provision time, not a second
codebase — which is exactly why it is the fallback, and why a per-PC mode is
not (§3.1).

The tier is decided per school **at onboarding, from a checklist, never on exam
morning**. Detecting it takes five minutes: can machine 2 reach machine 1 over
the wire; if not, do the machines see any Wi-Fi network; if so, can two of them
reach each other on it.

#### 3.2.1 Tier B's ceiling

When the relay has to be the access point, Windows' built-in Mobile Hotspot caps
at **eight connected clients**. That is a real limit, but it is the limit of one
fallback path, not of Wi-Fi and not of Tier B as a whole. Answers, in order:

1. **Find any network first.** A router in the office, moved to the lab for the
   morning, or simply an AP already in range. Costs nothing, makes the school
   Tier A, and removes the cap entirely. This should be exhausted before Tier B
   is even discussed — most schools that look like Tier B are Tier A schools
   nobody asked the right question.
2. **Raise the cap.** Driving the Wi-Fi adapter as a softAP directly, rather
   than through the Mobile Hotspot shell, may support many more clients — the
   answer is driver-dependent and cannot be assumed from a desk. **Spike this in
   step 4 before Tier B is promised to any school** (§19). Until measured, plan
   Tier B for eight.
3. **Sit the paper in waves of eight.** Last resort, and worse than it looks —
   wave 1 walks out knowing the paper. `shuffle_questions` and
   `questions_per_attempt` blunt that; they do not fix it. Only offer waves where
   the question bank is deep enough for genuinely disjoint subsets, and say so
   plainly to the school rather than letting them find out.

#### 3.2.2 The school that fits neither tier

A lab with no wired network, no Wi-Fi reachable from the machines, and machines
whose adapters cannot even join one **cannot run this client**, and the
specification says so rather than inventing a mode to cover it. That school
keeps doing what it does today — paper, or online CBT if there is a line — until
it has a network.

This should be a rare finding, and if it is coming back often, the checklist is
being run badly. The usual causes are a guest SSID with client isolation on, or
nobody having asked whether the machines have Wi-Fi adapters at all.

This is a deliberate reversal. A per-PC standalone mode was designed to close
this gap and then cut, because it required importing the paper to forty machines
and collecting answers from forty machines, by hand, for every exam. That is
around an hour of an exam officer's time per sitting with no recovery when a
machine is missed, and it is not something a school does twice. Shipping it
would have turned an honest "not yet" into a promise that fails in week three.

The hard rule against purchased hardware still holds and is not in tension with
this. It bans *this product* requiring dedicated equipment — exam appliances,
dongles, scanners. A lab network is ordinary school IT that every computer room
needs for every other purpose, and telling a school its lab needs a switch is
different in kind from telling it to buy a device that exists only to run our
exams.

---

## 4. Components

| Component | Runs on | Responsibility |
|---|---|---|
| **Relay** | Invigilator's laptop or one lab PC | Holds bundle, issues papers, stores answers and scripts, syncs |
| **Candidate client** | Each lab PC | Renders paper, captures answers, reports events |
| **Backend additions** | Existing Laravel API | Bundle issuance, key release, batch sync, groups, scripts, marking (§9) |
| **Teacher's Desk** | Web portal | Marking queue for theory answers and scripts (§7) |

Relay and candidate client ship as **one binary in two modes** (`--relay` /
default). One codebase, one installer, one update path; a school that only needs
a five-machine room can even run relay and candidate on the same PC.

There is no third mode. Tier B changes how the relay is reached, not what runs
on a candidate machine, and keeping it that way is what stops the client
sprawling into per-deployment variants (§3.2).

---

## 5. Lifecycle

### 5.1 Provision — the day before, needs connectivity once

The invigilator signs in to the relay with their normal SchoolPilot staff
credentials (Sanctum token, same as the web portal) and selects a published
exam with `allow_offline = true`.

The relay downloads:

1. **The encrypted bundle** — questions, groups, options, LaTeX source, exam
   metadata, and the roster of eligible candidates with pre-issued attempt IDs
   and seeds (§9.1). Encrypted; the relay cannot read it yet.
2. **Media assets** — via the existing `GET /api/v1/cbt/exams/{examId}/offline-package`
   staff response, which already returns a manifest of `asset_id`, `url`,
   `checksum`, `byte_size`, `mime_type`, `width`, `height`, `alt_text`,
   `caption`, plus `asset_count` and `total_bytes`. The relay fetches each
   asset, verifies `checksum`, and re-fetches mismatches. `total_bytes` exists
   precisely so an admin sees "this paper is 14 MB" before starting.
3. **Answer booklets**, if the paper has any on-paper theory (§6.5) — a printable
   PDF per candidate, generated server-side, ready for the school to print.

Media is *not* encrypted — it is already reachable to any authenticated
candidate before `opens_at` by design, so encrypting it protects nothing and
costs cache complexity.

Candidate clients pair to the relay in this phase too (§8.4), so exam morning
involves no configuration.

Getting everyone onto the network happens here and only here: on Tier A the
machines join the school's Wi-Fi or are already on the wire; on Tier B the relay
starts its hotspot and they join that. Either way it is done the day before,
once, and no later section knows which it was.

### 5.2 Unlock — exam morning, ~10 seconds of connectivity

The relay requests the bundle's content key (§9.2). Ten seconds on the school's
Wi-Fi or the invigilator's phone hotspot is enough.

**Offline fallback**, for a hall with genuinely no signal: the key is delivered
to the invigilator's phone out of band (WhatsApp or SMS) as a QR image. The
relay reads it via the laptop webcam, or the invigilator saves the image and
picks it from disk. No hardware, and no 40-character string to retype.

A time-lock alone is not acceptable as the gate — local clocks can be changed.
Key release is the gate.

Tier B changes nothing here. The relay needs ten seconds of *internet*, which is
the invigilator's phone hotspot or the school's line — unrelated to whether the
lab itself has a network.

### 5.3 Sit — zero internet

Candidates authenticate to the relay. The relay:

- Assigns each candidate their pre-issued attempt (`attempt_id`, `seed`,
  `question_order`, `server_deadline_at`).
- Serves questions in the order fixed by the seed (§10), with grouped questions
  kept contiguous and their stimulus pinned on screen (§6.2).
- Persists every answer to local SQLite immediately, with a monotonically
  increasing `client_sequence` per attempt.
- Records integrity events (§12) locally.
- Enforces the deadline it cached — never a locally computed one.

A candidate whose PC dies moves to a spare machine, re-authenticates, and
resumes: the relay holds their answers, so nothing is lost. This is the single
biggest practical advantage of the architecture and should be tested first.

None of this differs by tier: a hotspot is a network like any other, and the
resume path does not know which one it is on.

### 5.4 Grade — server-side, after sync

**Offline exams do not show results immediately.** `show_results_immediately`
is forced to `false` when `allow_offline` is set.

This is a deliberate trade. Instant grading would require answer keys and
marking schemes inside the bundle, on a machine in the exam room — the
highest-value secret in the whole system, sitting where the exam is being sat.
Deferring results means **the bundle contains no correct answers and no marking
rubrics at all**, which removes an entire class of attack. Results appear when
the school releases them, exactly as for a paper compiled online.

Theory answers then flow to the Teacher's Desk (§7), where they wait for a human
regardless of whether AI assistance is enabled.

Note this in any school-facing material: offline mode trades instant results for
exam-day resilience.

### 5.5 Sync — after the exam, whenever connectivity returns

The relay walks its queue and uploads each attempt's answers, script pages and
events. The existing conflict rules apply unchanged (§11). Partial syncs are safe
to retry. Once an attempt is confirmed finalised server-side, the relay may
delete its local copy — and must, per §13.

The relay may have to be carried somewhere with a signal to do it, which is fine
— it is one laptop, and §5.5 is the only phase with no time pressure on it.

---

## 6. Question model: groups, theory and paper

### 6.1 What already exists

More is built than a reader might assume. `QuestionBankItem::TYPES` already
carries eleven types:

```
multiple_choice   multiple_response   true_false   fill_blank   numeric
matching          ordering            image_choice diagram_label hotspot
theory            ← "manually graded; may carry a photo of working"
```

`AUTO_GRADED_TYPES` lists the ten that `CbtGradingService::correctFraction()`
scores automatically; `theory` is deliberately absent and falls through to
`requires_manual_grading => true`. Teacher marking already has an endpoint —
`POST /api/v1/cbt/attempts/{attemptId}/grade` — which writes `awarded_marks`,
`feedback` and `graded_by` per answer, re-totals the attempt from the answer rows
rather than applying a delta ("so a corrected mark cannot double-count"), and
clears `requires_manual_grading` once nothing is left ungraded. Exam results
already expose an `awaiting_marking` count.

So theory is not new. **What is new** is grouping, the on-paper answer route, a
cross-exam marking queue, and AI assistance.

### 6.2 Grouped questions

A comprehension passage with six sub-questions, a data table with four
calculations, a diagram with three labelling tasks — one shared stimulus, many
questions. The bank has no concept of this today; every item stands alone.

**New table `cbt_question_groups`:**

| Column | Notes |
|---|---|
| `id`, `school_id` | Tenant-scoped like everything else |
| `subject_id` | For filtering in the bank |
| `title` | "Passage 2 — The Harmattan", shown to the candidate |
| `stimulus` | The passage/table/scenario text |
| `content_format` | Same enum as questions, so LaTeX works in a stimulus |
| `media` | Shared images, referenced as asset ids |
| `instructions` | "Read the passage below and answer questions 12–17" |
| `metadata` | Reserved |

**On `question_bank_items`:** add nullable `group_id` and `group_sequence`.
A question with a null `group_id` behaves exactly as today — this must be a
purely additive migration, since every existing question has no group.

**Rendering rules:**

- The stimulus stays visible while any of its sub-questions is on screen. On a
  narrow lab monitor, that means a split pane or a pinned collapsible panel, not
  "scroll up to re-read the passage".
- Long passages get their own scroll region so the answer area never leaves view.
- The candidate must be able to see which sub-question of the group they are on
  ("Question 3 of 6 in this passage").
- Media attached to the group loads once, not per sub-question.

**Marks** stay on the individual sub-questions. A group has no mark of its own;
`total_marks` sums sub-questions exactly as now.

### 6.3 Theory and essay questions

Rather than adding an `essay` type alongside `theory`, treat `theory` as the
manually-graded family and put the shape in the existing `answer_schema` JSON
column — which is already how every other type carries its per-question grading
configuration:

```jsonc
{
  "response_format": "essay",        // short_answer | structured | essay
  "answer_mode": "on_screen",        // on_screen | on_paper  (§6.4)
  "expected_words": 250,             // soft guide shown to candidate
  "max_words": 500,                  // hard cap, enforced client-side
  "allow_working_photo": true,       // candidate may attach a photo of workings
  "rubric": [                        // used by the Teacher's Desk and, if
    {"criterion": "Thesis is stated clearly", "marks": 2},
    {"criterion": "Three supporting points", "marks": 6},
    {"criterion": "Grammar and expression",   "marks": 2}
  ]
}
```

Reusing `answer_schema` avoids a migration on `question_bank_items` for this
part and keeps all per-type configuration in one place.

- **`short_answer`** — a sentence or two. Plain textarea.
- **`structured`** — (a), (b), (c) parts under one stem. Renders as labelled
  sub-fields; stored as a keyed object in `response`.
- **`essay`** — long-form. Word counter, autosave on every pause, no rich-text
  formatting (formatting is not being marked and invites paste-in trickery).

**The rubric is staff-only and never enters the bundle.** It is marking-scheme
data, exactly like `correct_answer`, which `showAttempt` already withholds from
candidates ("The marking scheme itself is staff-only even after release").

### 6.4 On-screen or on-paper

Theory can be answered two ways, set per question via `answer_mode`, and
defaulted per exam.

**`on_screen`** — the candidate types into the client. The response is stored in
`cbt_attempt_answers.response` as JSON like any other type, and syncs through the
normal path. A candidate may optionally attach a photo of handwritten working
(`allow_working_photo`), which for a maths proof is often the real answer.

**`on_paper`** — the candidate writes in a printed booklet. The client shows the
question and the instruction to answer in the booklet, records that the
candidate reached it, and stores no response. Marks arrive later, from the
teacher, via §6.5.

Why keep the paper route at all:

- Nigerian theory papers are often long-form handwriting; typing them on an old
  lab keyboard is slower and disadvantages candidates who do not type.
- It is the honest answer for maths and sciences, where diagrams, working and
  notation are still faster on paper than in any editor we would ship.
- It cuts offline complexity sharply: a school can run objectives in the client
  and theory on paper, which is a genuinely good option for a low-spec lab —
  and a shorter on-screen section is exactly what makes a Tier B room capped at
  eight machines survivable in waves (§3.2.1).

An exam may mix modes freely — objectives on screen, Section B on paper.

### 6.5 Paper scripts: booklets, matching, capture

The problem with paper is joining the script back to the attempt without
hand-keying names. Solved with a QR code, using infrastructure that already
exists (`QrCodeService::dataUriFor()` and the report-card QR precedent).

**Booklet generation.** At provision time the backend generates a printable PDF
per candidate:

- Header: school name, exam title, candidate name and class, attempt number.
- **A QR code encoding the `attempt_id` and a page number**, repeated in the same
  corner of every page.
- Ruled answer space per question, with the question number pre-printed.

Printing is done by the school on the office printer they already own. This is
the same "printable PDF, not an access credential" pattern §3 of the
comprehensive documentation applies to ID cards.

**Capture, after the exam.** The teacher photographs the scripts with their own
phone through the SchoolPilot mobile app, or scans them on the school's existing
office copier and uploads the file from the web portal. The QR on each page
matches the image to the attempt and the page automatically. No dedicated
scanner, no barcode gun — the phone camera the app already uses for attendance.

**Failure handling matters more here than anywhere else in this document.** A
script that fails to match is a child's exam paper that has gone missing as far
as the system is concerned:

- Unmatched pages land in an **exceptions tray** on the Teacher's Desk, never
  discarded, with a manual "assign to candidate" action.
- A QR that reads but names an attempt not in this exam is rejected loudly.
- The Desk shows *expected pages vs received pages* per candidate, so a missing
  page 3 is visible before marking starts, not after results are released.
- Capture is idempotent — re-photographing a page replaces it rather than
  appending a duplicate.

**Offline capture.** Scripts may also be photographed against the relay while
still offline, queued in relay SQLite, and uploaded during §5.5. This matters
because the teacher may want to capture scripts in the lab immediately, while
the room is still supervised and no script has left the building.

### 6.6 Interaction with shuffling and selection

Grouping breaks two assumptions in the existing engine, and both need explicit
rules:

1. **Shuffle.** `question_order` is a flat list of question ids. With groups, a
   flat shuffle would scatter a passage's sub-questions across the paper and make
   it unanswerable. Rule: **shuffle at group level, then optionally within each
   group, then flatten.** Ungrouped questions shuffle among the groups as
   single-item units. `shuffle_within_group` defaults to `false`, because
   sub-questions frequently build on each other.

2. **Random subset.** `questions_per_attempt` picks N questions. If groups exist,
   N must select **whole groups**, never individual sub-questions — otherwise a
   candidate gets question (c) of a comprehension without (a) and (b), and the
   marks do not add up to `total_marks`. Where a random subset cannot be composed
   to the requested size out of whole groups, the engine should overshoot to the
   nearest group boundary and say so at authoring time rather than silently
   producing short papers.

Both rules must be implemented **once, server-side**, with the resulting flat
`question_order` shipped in the bundle. The client renders the order it is
given; it does not re-derive grouping. See §10.

---

## 7. Marking: the Teacher's Desk

Everything manually marked converges on one screen in the web portal. Today
there is a per-exam `awaiting_marking` count and a `gradeAttempt` endpoint, but
no queue a teacher can simply work through.

### 7.1 The queue

A teacher opens the Desk and sees what is waiting for *them* — scoped to the
classes and subjects they are assigned, not the whole school:

- Grouped by exam, then by question, **not by candidate.** Marking every
  candidate's answer to question 4 in a row is faster and far more consistent
  than marking candidate 1's whole paper, then candidate 2's. This is the single
  most important UX decision on the Desk.
- Counts of outstanding answers, scripts received vs expected, and exceptions.
- A clear "release blocked" indicator: an exam cannot be released while any
  answer is unmarked, and the Desk should say which ones.

### 7.2 Marking workflow

For each answer, the teacher sees the question, the rubric from `answer_schema`,
and either the typed response or the script pages for that candidate. They enter
marks — per rubric criterion where a rubric exists, summing to the awarded total
— plus optional feedback.

This maps onto the existing endpoint, which already validates
`marks.*.awarded_marks` and `marks.*.feedback`, writes `graded_by`, and re-totals
from the answer rows. Extend it to accept a per-criterion breakdown stored in
answer metadata; keep `awarded_marks` as the single source of the number.

Anonymous marking (hiding candidate names) should be an option. It is cheap to
build and it is the kind of thing that makes a marking dispute go away.

### 7.3 Optional AI-assisted grading

Off by default. When enabled, AI **suggests** a mark and a justification against
the rubric; it never awards one.

- Gated by a new `theory_grading` entry in the existing per-school
  `ai_feature_flags`, alongside `report_comments`, `lesson_plans`,
  `exam_generation`, `homework_ideas`, `translation`, `performance_summaries`
  and `tutor`. A school that wants none of this simply never turns it on.
- The suggestion is generated from the question, the rubric and the candidate's
  response — and, for `on_paper` answers, only where the script has legible
  transcribed text. **Do not attempt to mark handwriting from a photo in v1.**
  Handwriting recognition on a photographed Nigerian exam script, in mixed
  handwriting, under lab lighting, is a different project with a much worse
  failure mode: a confidently wrong mark on a child's paper.
- Suggestions are per rubric criterion, with a one-line reason each, so a teacher
  can see *why* and disagree with one line rather than the whole mark.
- The model is told the rubric is authoritative and that it must not invent
  criteria.

### 7.4 The approval gate — non-negotiable

The root `CLAUDE.md` rule is that an AI-generated report-card comment never
reaches a report card unreviewed; it sits in `pending_approval` until a teacher
approves or edits it. **The same rule applies here, and more strictly, because
this is a mark rather than a sentence of prose.**

- AI output is written to a **separate field** (`ai_suggested_marks`,
  `ai_suggestion_rationale`, `ai_suggestion_status`), never to `awarded_marks`.
- `graded_by` always holds a human user id. There is no AI value for that column.
  Anyone auditing later must be able to see a person's name against every mark.
- An attempt with unapproved suggestions still counts as `requires_manual_grading`
  and still blocks release.
- The Desk offers "accept", "accept with edit", and "reject" per answer, and
  bulk-accept is deliberately **not** offered for a whole exam in one click. If a
  teacher wants to accept a page of suggestions they must at least page through
  them.
- Every acceptance records that the mark originated as an AI suggestion, so a
  later dispute can be answered honestly.

An AI-suggested mark that a teacher never looked at must be impossible to
release, not merely discouraged.

### 7.5 NDPA considerations

AI-assisted marking sends a child's exam answer to a US-hosted API. That is a
cross-border transfer of a minor's personal data, and §12 of the comprehensive
documentation is explicit that this needs a documented lawful basis under NDPA
§§41 and 43 — not an assumption that the provider is reputable.

- The `theory_grading` flag must be off until a school opts in, and the opt-in
  should state plainly what leaves the country.
- Send the answer text, the question and the rubric. Do not send the candidate's
  name, admission number, class or any identifier — the mark comes back and is
  joined locally by answer id.
- Script photographs must not be sent at all in v1, which follows anyway from
  not doing handwriting recognition.
- Log every AI marking call in the existing AI usage records so a school can see
  what was processed on its behalf.

---

## 8. Security model

### 8.1 Bundle encryption

- Content key: 256-bit, random per exam, generated server-side at bundle build.
- Cipher: **AES-256-GCM**, authenticated. A tampered bundle must fail to open,
  not open with garbage.
- The bundle is written to relay disk at provision time; the key is withheld
  until unlock and held **in memory only** — never written to disk, never
  logged, never included in a crash dump.
- On exam close, the relay zeroes the key and the decrypted paper in memory.

### 8.2 Threat model, stated honestly

| Threat | Mitigation | Residual |
|---|---|---|
| Bundle copied off relay overnight | Encrypted at rest, key not yet issued | None material |
| Candidate reads paper from their own PC | Paper never stored there; served per-question over LAN | LAN sniffing — mitigated by TLS on the LAN link |
| Someone with admin rights on the relay dumps memory during the exam | Nothing prevents this | **Accepted.** Logged, not prevented |
| Answer keys or marking rubrics extracted | Not present in the bundle at all (§5.4) | None |
| Candidate alt-tabs to a browser | Kiosk mode, focus-loss events logged | **Deterrence and evidence, not prevention** |
| Candidate photographs the screen | Nothing prevents this | **Accepted.** Invigilation is human |
| Clock manipulation on candidate PC | Deadline enforced by relay, not candidate | None material |
| Paper script substituted after the exam | QR ties page to attempt; page counts reconciled | Physical chain of custody stays the school's job |
| Candidate puts their own phone on the lab network | The Wi-Fi passphrase is not the access control — the relay authenticates candidates and pins TLS (§8.3) | A phone on the network is not a phone in the paper |

The third, fifth and sixth rows matter for how this is sold. A desktop app on a
school's Windows PCs cannot truly lock down a machine, and claiming otherwise
will eventually cost an argument with a parent over a malpractice ruling. The
honest claim is **kiosk fullscreen, suppressed task-switching, and a logged
event trail an invigilator can review** — with a human still walking the room.

### 8.3 Transport on the LAN

Relay↔candidate traffic carries live question text and must not be plaintext
HTTP. The relay generates a self-signed certificate at install; candidate
clients pin it during pairing (§8.4). Certificate pinning at pairing time avoids
both a CA and trust-on-every-connect prompts.

**Built, and step 4 changed how.** WebView2 validates certificates itself and
exposes no hook for a host application to override that decision, so a
self-signed relay certificate produces the webview's own full-page certificate
warning — the click-through prompt this section set out to avoid, relocated
inside our own window. Pinning cannot live in a client whose webview loads the
paper over the network.

So it does not. The candidate window loads the page over a custom protocol
served from inside the binary, and every `/relay/v1` call is proxied through
Rust, where `rustls` pins the relay's fingerprint. The paper's markup never
crosses the lab network at all; only JSON does. That is a stronger outcome than
this section originally asked for, and it arrived by way of a constraint rather
than by design — worth recording as such.

The verifier accepts exactly one certificate and ignores hostname, expiry and
chain, all of which are meaningless for a self-signed certificate on whatever
address DHCP handed out this morning. Stated plainly because it reads
alarmingly: this is *stricter* than ordinary web PKI, which accepts any of
hundreds of CAs for a matching name.

The fingerprint reaches candidate machines by being read off the invigilator's
screen at setup — supervised, one-time and out of band, which is the property
§8.4's pairing was providing. A machine given the wrong fingerprint, or none,
refuses to open a window at all rather than failing at the first save with a
candidate already in the seat.

**This matters more on Wi-Fi, not less.** The school's wireless network carries
staff laptops, phones and whatever else is in the building; a relay-broadcast
hotspot has a passphrase typed in front of forty candidates. Neither is a
private link, and a WPA2 passphrase is not access control. Do not let "it's the
school's own network" or "it's our own hotspot" become a reason to weaken the
pinned-TLS requirement — pinning is what makes the network's own security
irrelevant to us.

### 8.4 Pairing

At provision time each candidate PC pairs to the relay once: the relay displays
a short pairing code, the candidate client enters it, and the two exchange a
long-lived device token plus the pinned certificate fingerprint. Pairing is
staff-supervised and happens the day before, never during the exam.

Pairing is the same on both tiers, once the machines are on the network. It is
also the moment a room discovers a problem with that network — a client cap
reached (§3.2.1), or client isolation silently blocking peer traffic (§3.2) —
the day before, with time to do something about it, rather than on exam morning.
**Pair every machine that will be used, not a sample**, for exactly this reason.

---

## 9. Backend work required

Everything below is new. All routes are versioned under `/api/v1/` per the hard
rule — this client is another API consumer and an unversioned change would break
installed copies exactly as it would break the mobile app.

Items marked **[platform]** benefit the web and mobile CBT runners too and can
ship before any desktop code exists.

### 9.1 Bundle issuance

```
POST /api/v1/cbt/exams/{examId}/offline-bundle
     role: school_admin | teacher (must already pass the exam `view` policy)
```

Builds and returns an encrypted bundle for a named roster. Response body is the
ciphertext plus a `bundle_id`, `key_id`, `built_at`, and a plaintext header
carrying only non-sensitive metadata (`exam_id`, `question_count`,
`server_deadline_at` policy, `attempt_count`).

Plaintext inside the bundle:

- Exam settings the client must honour: `duration_minutes`, `opens_at`,
  `closes_at`, `shuffle_questions`, `shuffle_options`, `questions_per_attempt`,
  `max_attempts`, `negative_marking`, `pass_mark`, `extra_time_minutes`,
  `integrity_settings`, `content_format`, `total_marks`.
- Questions: text, options, LaTeX source, `media_asset_ids`, `question_type`,
  and for theory the *candidate-facing* parts of `answer_schema` only —
  `response_format`, `answer_mode`, `expected_words`, `max_words`,
  `allow_working_photo`. **Never `rubric`, never `correct_answer`.**
- Groups: `title`, `stimulus`, `instructions`, `content_format`, media ids.
- Roster: one entry per eligible candidate with a pre-issued `attempt_id`,
  `seed`, `question_order`, `server_deadline_at`, and a credential the candidate
  can authenticate to the relay with.

Pre-issuing attempts is the part that needs the most care server-side: it
creates `cbt_attempts` rows in a `provisioned` state that must not count against
`max_attempts` until actually started, and must be reclaimable if the exam is
cancelled. Add `provisioned` to the attempt status enum rather than overloading
`in_progress`.

A test must assert the serialised bundle contains no `correct_answer` and no
`rubric` key anywhere. That is the single most valuable test in this document.

### 9.2 Key release

```
POST /api/v1/cbt/offline-bundle/{bundleId}/key
     role: school_admin | teacher
```

Returns the content key. Refuses before `opens_at`. Logs who unlocked, when, and
from where. Rate-limited hard, and every call is audited — an unlock is a
security-relevant event and belongs in `audit_logs`.

### 9.3 Batch sync — and a blocker in the current route

The existing sync endpoint is single-attempt and student-scoped:

```php
// routes/api.php:471
Route::post('/cbt/offline-sync', [CbtController::class, 'syncOfflineAnswers'])
    ->middleware('role:student');
```

**A relay cannot use this.** It uploads on behalf of up to several hundred
candidates while authenticated as staff, and `role:student` rejects it outright.
This must be resolved before any client work begins. Add:

> **Resolved.** `POST /api/v1/cbt/offline-sync/batch` exists, staff-scoped, in
> `CbtOfflineBundleController::batchSync`. The student-scoped route is
> unchanged.

```
POST /api/v1/cbt/offline-sync/batch
     role: school_admin | teacher
     body: { bundle_id, attempts: [ { attempt_id, answers: [...], events: [...], submit } ] }
```

It should reuse `CbtExamService::recordAnswers(..., fromOfflineClient: true)`
per attempt so the conflict rules stay in exactly one place, authorise each
attempt against the bundle's roster (a relay must not be able to post answers
for a student who was never on it), and return per-attempt results so a partial
failure is legible rather than all-or-nothing.

Keep the existing student-scoped endpoint. The mobile app may still want it.

### 9.4 Question groups **[platform]**

```
GET|POST      /api/v1/cbt/question-groups
GET|PUT|DELETE /api/v1/cbt/question-groups/{id}
```

Plus `group_id` and `group_sequence` on question create/update, and group
hydration in `listQuestions`, `showExam` and `buildCandidatePaper`. The shuffle
and subset rules in §6.6 live in `CbtExamService`.

Deleting a group must not orphan its questions — either block deletion while
questions reference it, or null the reference and return them to standalone.
Decide explicitly; do not leave it to a cascade default.

### 9.5 Paper scripts **[platform]**

```
POST /api/v1/cbt/exams/{examId}/booklets      → generates printable PDFs
POST /api/v1/cbt/attempts/{attemptId}/script  → upload one or more page images
GET  /api/v1/cbt/exams/{examId}/scripts       → received vs expected, exceptions
POST /api/v1/cbt/scripts/{id}/assign          → resolve an unmatched page
```

Notes:

- The existing `uploadMedia` caps files at 5 MB, which is tight for a phone
  photo of an A4 page. Script upload needs its own limit and server-side
  downscaling, not a raised global cap.
- Store page images against the attempt with a page index; re-upload of the same
  page replaces rather than appends.
- Strip EXIF on upload. `ImageMetadataStripper` already exists — reuse it. A
  phone photo carries GPS coordinates, and these are children's exam scripts.

### 9.6 Teacher's Desk **[platform]**

```
GET  /api/v1/cbt/marking/queue          → grouped by exam → question
POST /api/v1/cbt/marking/suggest        → request AI suggestions (flagged feature)
POST /api/v1/cbt/marking/{answerId}/decision → accept | accept-with-edit | reject
```

Marks themselves continue to go through the existing
`POST /api/v1/cbt/attempts/{attemptId}/grade`. Do not build a second write path
for marks; that endpoint's re-total-from-rows behaviour is exactly right and
should stay the only way a score changes.

New columns on `cbt_attempt_answers`: `ai_suggested_marks` (decimal, nullable),
`ai_suggestion_rationale` (json, nullable), `ai_suggestion_status` (enum:
`none`, `pending_approval`, `accepted`, `edited`, `rejected`, default `none`).

### 9.7 Event upload

`CbtAttemptEvent` rows (`attempt_id`, `event_type`, `occurred_at`, `metadata`)
ride along in the batch payload rather than getting their own endpoint. They are
worthless without the attempt they belong to.

### 9.8 Tests

Per the workflow rule, each new endpoint ships with tests. At minimum:

- Bundle contains no `correct_answer` and no `rubric`.
- Key release refused before `opens_at`.
- Batch sync rejects an `attempt_id` outside the bundle roster.
- Replayed batch is idempotent.
- Grouped shuffle keeps sub-questions contiguous, always.
- `questions_per_attempt` with groups never splits a group.
- An answer with `ai_suggestion_status = pending_approval` blocks release.
- `graded_by` is never null on a released attempt.
- Script re-upload replaces rather than duplicates a page.

---

## 10. The determinism contract

`CbtExamService::seededShuffle()` is a Fisher–Yates driven by a seeded MINSTD
LCG (`state = state * 16807 mod 2147483647`), chosen specifically so paper order
is **stable forever** across PHP versions — the docblock explains that
`mt_srand()` was rejected because PHP changed its Mt19937 implementation and
would silently re-order a resumed candidate's paper after a server upgrade.

The offline client depends on that same stability. Two rules:

1. **The client reimplements `seededShuffle` exactly** — same LCG constants,
   same descending Fisher–Yates loop, same modulus behaviour.
2. **A parity test runs in CI on both sides.** A fixed vector of seeds and list
   lengths, with expected output orders committed to the repo, asserted by both
   the PHP suite and the client's suite. If the two ever disagree, a resumed or
   re-synced candidate silently gets a different paper than they sat.

   The vectors are committed at `backend/tests/fixtures/cbt-shuffle-parity.json`
   and asserted by `tests/Feature/CbtShuffleParityTest.php`. They cover the flat
   shuffle, per-question option shuffling, and grouped papers with the subset
   draw. They were generated by a standalone reference implementation written
   from this section rather than from `CbtExamService`, so agreement means the
   rules are reproducible from the specification — which is exactly what the
   client author will be doing. **Do not regenerate the fixture to make a
   failing test pass.** It has already caught one real defect (a
   `shuffle_within_group` setting silently dropped because the column was not on
   the model), which is the entire argument for it.

Better still, prefer the server's stored `question_order` where it exists —
pre-issued in the bundle roster — and treat the local shuffle purely as the
fallback for orders generated on the relay. Less duplicated logic is less drift.

**Grouping raises the stakes.** The group-then-flatten order in §6.6 is more
intricate than a flat shuffle and therefore easier to drift. Extend the parity
vectors to cover grouped papers explicitly: groups of varying size, mixed
grouped and ungrouped items, `shuffle_within_group` both on and off. The
strongest version of this rule is that the client never computes a grouped order
at all — the server always supplies it.

This is the maintenance tax of the whole project and it should be stated plainly
to whoever picks it up: two implementations of the same ordering must never
diverge.

---

## 11. Sync and conflict resolution

Already solved server-side; the client must simply feed it correctly.
`CbtExamService::supersedes()` orders answers by:

1. `client_sequence` first — monotonic per device, so it survives a clock change.
2. Then `client_timestamp`.
3. An answer carrying no ordering information never overwrites one that has some.

Client obligations:

- `client_sequence` is **monotonic per attempt**, incremented on every save,
  persisted alongside the answer. It must survive a relay restart — read the max
  from SQLite on boot, do not restart at zero.
- `client_timestamp` is ISO-8601 with offset.
- Re-sending an already-synced answer is expected and safe; it will be counted
  as `ignored`, not applied twice.
- Answers for a question not in that attempt's `question_order` are rejected
  server-side and indicate a client bug — surface them loudly, do not swallow.

**Theory answers** sync through the same path; a long essay is just a larger
`response` payload. Two additions:

- Essays should sync in their own batch chunk. A 500-word answer per candidate
  across 400 candidates is a materially larger upload than objective responses,
  and a chunk that times out should not drag the objectives with it.
- Script page images sync **after** all answers, as a separate queue. Answers are
  small and irreplaceable; images are large and re-capturable. If connectivity is
  poor, the small irreplaceable things must land first.

`recordAnswers` already returns `saved`, `ignored` and `rejected` counts. The
relay's sync screen should show all three, plus script pages uploaded and
outstanding, because "synced 400 answers, ignored 12, rejected 0, 318 of 400
script pages uploaded" is the sentence an anxious exam officer needs.

---

## 12. Integrity events

Written locally during the exam, uploaded with the batch. Reuse
`CbtAttemptEvent.event_type` values already in use (`resumed`, `offline_sync`,
`auto_submit`, `manual_submit`) and add:

| Event | When |
|---|---|
| `focus_lost` / `focus_regained` | Candidate leaves or returns to the exam window |
| `fullscreen_exited` | Kiosk mode broken |
| `client_crashed` | Client restarted with an attempt still open |
| `seat_changed` | Attempt resumed from a different paired device |
| `relay_restarted` | Relay process restarted mid-exam |
| `clock_skew_detected` | Candidate device clock diverges materially from relay |
| `paper_section_reached` | Candidate reached an `on_paper` question |
| `paste_detected` | Large paste into a theory answer field |

`paste_detected` is evidence, not an accusation — a candidate may legitimately
copy their own earlier text. Record the size, not the content.

Events are evidence for a human reviewing a malpractice question, not automatic
grounds for anything. Do not build auto-disqualification.

---

## 13. Data retention on the relay

The relay holds question text, candidate answers and script photographs — all
sensitive under NDPA, and the data subjects are children.

- Decrypted paper: memory only, zeroed at exam close.
- Local SQLite: deleted per attempt once the server confirms the attempt is
  finalised. Not "eventually" — as part of the sync confirmation path.
- **Script images: deleted from the relay the moment upload is confirmed.** These
  are photographs of children's handwriting on a laptop that leaves the school.
  They are the most sensitive thing the relay ever holds and the least excusable
  to retain.
- If a bundle is never unlocked (exam cancelled), the relay deletes it on
  expiry of `closes_at + 7 days`.
- The relay must expose a visible "purge exam data" action for the invigilator,
  and log it.

Retention is one of the quieter arguments for the relay model: there is one
machine to purge, and it belongs to a member of staff. A per-PC design would
have spread the same obligation across forty machines that students themselves
use on ordinary school days — retained data sitting on the data subjects' own
hardware, which is worse than the laptop-in-a-bag case this section opens with.

A laptop carrying last term's mock papers, 400 children's answers and their
scanned scripts for months is a data-protection incident waiting to happen.
Retention is a feature, not cleanup.

---

## 14. Failure modes

| Failure | Behaviour |
|---|---|
| Candidate PC dies | Move seat, re-auth to relay, resume; answers intact |
| Relay dies mid-exam | Restart; SQLite is the source of truth; log `relay_restarted`. **Single point of failure — see below** |
| Power cut, whole lab | On restore, relay and clients resume; deadline honoured from `server_deadline_at` |
| LAN or Wi-Fi drops | Candidate client shows a clear banner, buffers locally, reconnects and flushes |
| Hotspot drops (Tier B) | Same as a network drop; relay restarts the hotspot, clients rejoin and flush |
| Tier B hits its client cap | The ninth machine is refused with a plain message, not a hang. Caught at pairing the day before, never on exam morning |
| Router has client isolation on | Candidate reaches the internet but not the relay. The client must name this specifically rather than reporting a generic connection error — it is a router setting, and an unrecognisable symptom otherwise (§3.2) |
| No connectivity after exam | Relay holds queue indefinitely; sync when available; nothing expires locally |
| Sync interrupted halfway | Retry; `supersedes()` makes it idempotent |
| Exam closed server-side while relay offline | Sync still accepted and closed out — `syncOfflineAnswers` already saves late batches, then finalises |
| Two relays provisioned for one exam | Must be prevented: bundle issuance records the relay identity; a second issuance requires explicit staff override |
| Script page won't match by QR | Exceptions tray, manual assignment; never discarded (§6.5) |
| Script page never captured | Desk shows received vs expected; release blocked while marks are outstanding |
| Booklet printed for the wrong candidate | QR mismatch surfaces at capture, not at marking |
| AI marking unavailable or times out | Answer stays in the queue for manual marking; never blocks, never guesses |

**Relay as single point of failure** is the honest weakness of this design. The
mitigation is that its state is a single SQLite file: an invigilator can copy it
to another laptop and restart. Document that recovery procedure and rehearse it
during the pilot. Do not build automatic relay failover for v1 — it is
substantial complexity for a rare event with a workable manual answer.

---

## 15. Time handling

- `server_deadline_at` is authoritative and comes from the bundle. The client
  never computes a deadline from local time plus `duration_minutes`.
- The relay records its clock offset from the server at unlock and applies it.
- Candidate clients display time remaining as reported by the relay; they do not
  trust their own clocks.
- Clock skew beyond a threshold logs `clock_skew_detected` and warns the
  invigilator — a lab of PCs with wrong BIOS dates is common and worth surfacing
  before the paper starts rather than after.
- Where a paper mixes on-screen and on-paper sections, the deadline covers the
  whole sitting. The client must make remaining time visible to a candidate who
  is currently writing in a booklet and not looking at the screen — a large,
  glanceable countdown, not a number in a corner.

---

## 16. Technology

**Recommendation: Tauri (Rust core + web UI).**

| | Tauri | Electron |
|---|---|---|
| Installer size | ~5–15 MB | ~120 MB+ |
| Memory per candidate PC | Low | High |
| Old lab hardware | Comfortable | Struggles |
| Rendering | System WebView2 | Bundled Chromium |

Installer size and RAM footprint decide it. These machines are often old, and
the installer may be distributed over a phone hotspot or a USB stick.

The candidate UI should reuse the web CBT runner's components and its **locally
bundled KaTeX** — the web app already vendors KaTeX rather than using a CDN for
exactly this reason ("an exam hall may have no internet, and a paper whose
equations render as raw TeX is not a paper anyone can sit"). Do not undo that.

**WebView2 dependency:** present on Windows 11 and current Windows 10, but not
on older unpatched machines. The installer must bundle the evergreen bootstrapper
and work without internet. Test on the oldest machine the pilot school has.

**Tier B adds no new technology**, only Windows networking APIs the OS already
exposes. Whether those APIs will serve a whole room is §3.2.1's open question,
and it is a question about drivers rather than about the framework choice.

---

## 17. Packaging and distribution

- **Windows first**, `.exe` installer. Linux and macOS are not priorities for
  Nigerian school labs.
- **Code signing:** unsigned binaries trigger SmartScreen, which reads to a
  school as "this is a virus". Budget for an OV certificate, or accept and
  document a clear "More info → Run anyway" walkthrough with screenshots.
- **Offline installation:** the installer must work from a USB stick with no
  internet. Assume that is the common case.
- **Silent install matters more than it looks.** The candidate client still has
  to reach forty machines once, at first setup, so the installer needs an
  unattended switch. This is a one-time cost per lab rather than a per-exam one
  — which is precisely the line §3.1 draws between an acceptable deployment and
  an unacceptable one.
- **Updates:** version-check on provision (an online moment anyway). The client
  must refuse to run a bundle built for a newer bundle-format version and say so
  clearly, rather than failing obscurely at unlock.

---

## 18. Testing

| Layer | Coverage |
|---|---|
| Parity | Shuffle vectors identical between PHP and client, including grouped papers (§10) — non-negotiable |
| Unit | Sequence monotonicity across restart; deadline enforcement; checksum verification; word-cap enforcement |
| Integration | Full provision → unlock → sit → sync against a real backend, with a mixed paper: objectives, a comprehension group, an on-screen essay and an on-paper theory section. **Run it on both tiers** |
| Failure injection | Kill candidate mid-essay; kill relay mid-exam; pull the LAN; drop the hotspot; skew clocks; corrupt a media file; corrupt the bundle; upload an unmatchable script page |
| Scale | 40 concurrent candidates against one relay laptop, with essay-length payloads, over Wi-Fi rather than a cable — the wireless path is the one most schools will use. Measure before promising a room size. Separately and earlier, **measure the relay hotspot's real client cap** (§3.2.1) |
| Security | Bundle unreadable before key release; **no answer keys and no rubrics in bundle**; batch sync rejects off-roster attempts |
| Marking | AI suggestion cannot reach `awarded_marks` without a decision; release blocked while `pending_approval` exists; `graded_by` always a human |

The scale test decides what the sales conversation may promise. Run it early on
representative hardware, not on a developer's machine — and run it with essays,
since a 400-candidate essay sync is a very different upload from 400 sets of
objective answers.

---

## 19. Build order

1. ~~**Backend, exam-side** — §9.1, §9.2, §9.3 with tests.~~ **Done.**
   `CbtOfflineBundleService`, `CbtOfflineBundleController`,
   `tests/Feature/CbtOfflineBundleTest.php`. The `role:student` blocker is
   resolved. Pre-issued attempts use a new `provisioned` status that
   `startAttempt` adopts rather than duplicating — see the note below, since
   that was the risk this step existed to retire.
2. ~~**Backend, question model [platform]** — §9.4 groups, `answer_schema`
   extensions for theory.~~ **Done.** `CbtQuestionGroup`,
   `CbtQuestionGroupController`, grouping rules in `CbtExamService::composePaper`,
   parity vectors in `tests/fixtures/cbt-shuffle-parity.json`. Live on the web
   runner now; the offline client will consume the same order.
3. ~~**Relay skeleton** — auth, provision, bundle storage, SQLite, sync. No UI
   beyond a status window. Prove the round trip with a scripted fake candidate.~~
   **Done.** `desktop/relay`, a Rust binary with `login` / `provision` / `serve`
   / `sync` / `status` / `purge`. The scripted fake candidate is
   `tests/round_trip.rs`; the parity vectors are asserted in Rust against the
   same committed fixture the PHP suite uses. See the note below — the AAD
   turned out to be the sharp edge, not the crypto.
4. **Candidate client** — pairing, paper rendering including groups and theory
   editors, answer capture, resume. **Tier B lands here too**, as a provisioning
   step rather than a separate build — and with it the softAP spike of §3.2.1,
   which should be done *early in the step*, not at the end. It is cheap, and a
   bad answer changes what can be sold before the UI work is sunk.

   **Mostly done.** Paper rendering, answer capture, autosave, the countdown,
   resume, groups, theory word caps and focus/paste events all work, served by
   the relay at `/sit` as one self-contained page (`desktop/relay/src/ui.rs`).
   Maths renders: KaTeX is vendored into the binary (`assets.rs`) and the
   delimiter grammar is a port of the web runner's, per §16. `relay candidate`
   opens the paper in a fullscreen kiosk window (`kiosk.rs`) built on wry/tao —
   the webview layer Tauri itself is built on, chosen over the full Tauri
   scaffolding because the client needs one window and no IPC, and §16's real
   requirements (WebView2, small installer, low RAM) are identical either way.
   The window measures ~31 MB resident. `relay demo` runs the lot against a
   built-in sample paper with no backend.

   **Pinned TLS is done** (§8.3): `serve` speaks HTTPS with a certificate the
   relay generates once and keeps, prints the fingerprint for the invigilator to
   carry to the lab machines, and `relay candidate --fingerprint …` refuses to
   open a window against anything else. `tests/pinning.rs` proves it against a
   real impostor rather than asserting it in prose.

   One thing is **not** claimed. **Task-switching is suppressed only inside the
   window** — every browser affordance leading out of the paper is blocked, but
   Alt-Tab and the Windows key are not, because eating them needs a low-level
   keyboard hook that antivirus flags and that a school PC may refuse the
   privileges for. Of §8.2's three claims, the first and third are real and the
   middle one is partial; focus loss is reported, not prevented.

   Still outstanding for the step: pairing (§8.4), and the softAP client-cap
   spike of §3.2.1, which needs a room and a handful of machines rather than
   code.
5. **Paper scripts [platform]** — §9.5 booklets, capture, matching, exceptions.
6. **Teacher's Desk [platform]** — §9.6 queue and marking, manual only.
7. **AI suggestions** — §7.3 and §7.4, behind the flag, last. It is the only part
   of this document that is optional, and it should be built once marking works
   without it.
8. **Integrity, kiosk, events.**
9. **Failure injection and the 40-candidate scale test**, on both tiers.
10. **Packaging, installer, offline install, update path.**
11. **Pilot** with one school on a real mock exam, with an online fallback ready
    and an engineer physically present.

Steps 1–3 are where the risk is. If the pre-issued-attempt model in §9.1 does
not survive contact with `startAttempt`'s locking and `max_attempts` accounting,
that is better discovered in week one than after the UI is built.

**It survived, with one change.** `startAttempt` now *adopts* a `provisioned`
attempt instead of opening a new one. Without that, a candidate whose bundle was
built yesterday and who then sat online today got a second attempt row — burning
their single allowance and splitting their answers across two papers. The
`used` count already excluded `provisioned`, so the accounting was fine; it was
the resume path that was not. Covered by
`test_pre_issued_attempts_do_not_burn_an_allowance`.

**Step 3 surfaced one thing worth writing down: the AAD is the fragile part,
not the cipher.** §8.1 binds the plaintext header in as additional
authenticated data, and GCM authenticates the header's *bytes*, not its
meaning. `CbtOfflineBundleService::aad()` is `json_encode($header, …)`, so the
relay must reproduce that byte string exactly — and re-serialising a parsed
header in a second language is precisely the kind of thing that works on every
test school and then fails on the one whose exam title contains a character the
two encoders escape differently. That failure would land at unlock, on exam
morning, in a room with forty candidates in it.

The relay therefore slices the raw `"header":{…}` substring straight out of the
response body and never re-encodes it, falling back to a compact
re-serialisation only if that fails — which covers the opposite hazard, a proxy
or debug setting that pretty-prints the response after the server computed its
AAD. Each covers the other's failure mode. `tests/unseal.rs` opens a bundle PHP
actually sealed and exercises both paths; sealing in Rust and opening in Rust
would have proved only that the relay agrees with itself.

Two other things surfaced that the design did not anticipate, both now fixed:

- **On-paper theory had nowhere to put a mark.** `submitAttempt` only wrote an
  answer row for questions the grader could score, so a question answered in a
  booklet had no row at all — `gradeAttempt` would have updated nothing, the
  teacher's mark would have vanished silently, and the attempt would have
  reported itself fully graded. Every served question now gets a row; a null
  `graded_by` is what "still owed a human" means. This was already a latent bug
  for blank on-screen theory answers; §6.4 turns it from occasional into
  certain.
- **A bundle needs a scheduled start.** §15 forbids the client computing a
  deadline from local time, so the bundle must carry a real instant — which
  means `opens_at` is now required before an offline paper can be published or
  bundled.

Steps 2, 5 and 6 deliver value to the existing online product whether or not the
desktop client ever ships. If the project is ever paused, pause it after step 6.

---

## 20. Open questions

1. **What network can the lab machines reach, and how many seats?** One WhatsApp
   message: are the PCs on a switch; if not, do they see any Wi-Fi network —
   the office router counts, and it does not need internet; how many seats. Most
   schools that sound like Tier B turn out to be Tier A once asked properly
   (§3.2). A school that genuinely reaches nothing cannot run this client
   (§3.2.2), and that is worth knowing early rather than on exam morning.
2. **How many clients can the relay's own hotspot hold?** §3.2.1. Not a question
   for a school — a question for one afternoon with a laptop and a handful of
   PCs. It only bounds the fallback path, but it decides what can be promised to
   a school with no network of its own. Do it early.
3. **How many candidates per room, realistically?** Sets the scale target.
4. **Who is the relay operator** — the exam officer, or an IT person? Determines
   how much the relay UI must explain itself.
5. **Is deferred results (§5.4) acceptable** to schools running mocks, or does
   anyone genuinely need instant offline scores? If the latter, the answer-key
   threat model has to be reopened — do not do this quietly.
6. **Do pilot schools want theory on screen or on paper?** If the honest answer
   is overwhelmingly paper, §6.4's paper route becomes the priority and the
   on-screen essay editor can wait.
7. **Who marks — the subject teacher or an exam officer?** Decides whether the
   Desk's default scope is "my classes" or "the whole exam".
8. **Would any school actually enable AI marking?** Worth asking before building
   §7.3. It is the most speculative item here and the easiest to defer.
9. **Code-signing budget?** Decides §17.
10. **Windows versions in the field** at the pilot school. Decides the WebView2
    fallback story.

---

## 21. Explicitly out of scope, permanently

Consistent with root `CLAUDE.md` and §3 of the comprehensive documentation:

- Any purchased hardware: exam-hall appliances, dongles, thin clients, dedicated
  servers, proctoring cameras, document scanners.
- Biometric identification of candidates.
- Remote/AI proctoring, gaze tracking, webcam monitoring.
- Automatic disqualification on an integrity event.
- **AI awarding a mark that no teacher approved**, in any mode, behind any flag,
  for any question type. There is no configuration that permits it.

Candidate identity at the seat is established by the invigilator, the same way
it is on paper.
