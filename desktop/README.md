# SchoolPilot Desktop — Lab Relay

The relay half of the offline CBT client. Design and rationale live in
[`docs/offline-cbt-client.md`](../docs/offline-cbt-client.md); this file is how
to run and work on the code.

**Status:** step 3 complete; step 4 part-built. The relay runs, and a candidate
can sit a whole paper in a browser served by it. Pairing, pinned TLS and the
Tauri kiosk shell are the rest of step 4 and do not exist yet.

## Try it in one command

No backend, no school, no setup — a sample paper PHP actually sealed, served by
the real relay:

```powershell
cd desktop/relay
cargo run -- demo
```

It prints two candidate sign-ins. Open **http://127.0.0.1:8443/sit** to sit the
paper and **http://127.0.0.1:8443/** for the invigilator's status window. Add
`--lan` to reach it from another machine on the network, and `--minutes 3` to
watch the clock run out and auto-submit.

What that exercises: unsealing a real bundle, per-candidate question order,
seeded option shuffling, a comprehension group with its stimulus pinned, a
theory question with a word cap, autosave, resume, the countdown, focus events,
and submission. What it does not exercise: anything that talks to the backend.

## What this is

One laptop carries an encrypted exam into a computer lab with no internet,
serves it to candidate machines over the lab's network, holds every answer, and
uploads the lot afterwards. The paper lives on that one machine, not on forty.

Only four moments touch the network, and none of them is during the exam:

```
relay login     --base-url https://kings.schoolpilot.ng --email …   # once
relay provision --exam 42                                           # day before
relay serve                                                         # ~10s on exam morning
relay sync                                                          # afterwards
relay purge                                                         # §13 retention
```

## Building

Needs the Rust MSVC toolchain and Visual Studio Build Tools (`rusqlite` is
compiled from C).

```powershell
winget install Rustlang.Rustup
winget install Microsoft.VisualStudio.2022.BuildTools `
  --override "--quiet --wait --add Microsoft.VisualStudio.Workload.VCTools --includeRecommended"

cd desktop/relay
cargo build
cargo test
```

## Tests

| File | What it holds down |
|---|---|
| `tests/parity.rs` | **The one that must never fail.** Question and option order, asserted against `backend/tests/fixtures/cbt-shuffle-parity.json` — the same vectors `CbtShuffleParityTest` asserts in PHP. If the two sides disagree, a resumed candidate silently sits a different paper from the one they were served. Do not regenerate the fixture to make it pass. |
| `tests/unseal.rs` | Opens a bundle **PHP actually sealed** (gzip + AES-256-GCM + header-as-AAD), rejects a wrong key, a retargeted header and a future format version, and asserts no marking scheme reached the room. |
| `tests/round_trip.rs` | The scripted fake candidate: seat → read paper → answer → change answer → event → submit → inspect the batch payload. Includes the resume-after-a-dead-PC case, which §19 says to test first. |
| unit tests | In each module — sequence monotonicity across restart, group position, HTML escaping, raw-JSON slicing. |

Regenerate the sealed fixture only when the bundle format changes:

```powershell
cd desktop/relay/tests/fixtures
php make-sealed-bundle.php
```

## Layout

```
src/
  main.rs      CLI: login, provision, serve, sync, status, purge
  api.rs       backend client — §9.1 issuance, §9.2 key release, §9.3 batch sync
  bundle.rs    envelope, AES-256-GCM unseal, the paper's shape
  shuffle.rs   the determinism contract with the server (§10)
  store.rs     relay SQLite — attempts, answers, events, sync queue
  paper.rs     one candidate's paper, built from the roster's fixed order
  server.rs    candidate-facing HTTP + the invigilator's status window
  ui.rs        the candidate client — one self-contained page, no CDN
  sync.rs      the upload walk (§5.5, §11)
  config.rs    paths and the staff token
```

## Three things that will bite you

**The content key is never written down.** Not to the config file, not to
SQLite, not to a log. It is fetched at `serve`, held in memory, and dropped when
the paper closes (§8.1). If you find yourself persisting it to make a restart
easier, restart is already handled — `serve` fetches it again.

**Nothing may reach for the network during the exam.** `api.rs` is called by
`login`, `provision`, the unlock at the start of `serve`, and `sync`. If a code
path between those ever needs it, the design has gone wrong, not the network.

**The relay renders the order it is given.** `question_order` is fixed
server-side and travels in the bundle roster. `shuffle.rs` exists to prove the
rules still agree, not to compute a paper — see the note at the top of
`paper.rs`.

## Not done yet

- **Pinned TLS between relay and candidate (§8.3).** `serve` binds to loopback
  unless given `--lan`, which prints a warning, because a half-built relay must
  not quietly serve a real exam in the clear.
- **Kiosk mode.** The candidate client is a page, so it can report focus loss
  and pastes but cannot prevent task-switching. §8.2 is already honest that the
  real claim is "fullscreen, suppressed task-switching, and a logged event
  trail" — a browser delivers the third of those three, and the Tauri shell owes
  the first two. Do not describe the current state to a school as invigilation.
- **LaTeX.** `content_format: latex` is carried through the bundle but not
  rendered; the client shows a banner telling the candidate to raise it with the
  invigilator rather than presenting raw TeX as if it were the question.
  Vendoring KaTeX is the fix (§16).
- Pairing and device tokens (§8.4), booklets and script capture (step 5),
  integrity enforcement (step 8).
- Media checksum verification on download — files are fetched and stored, but
  the manifest's `checksum` is recorded rather than checked.
- On-screen `allow_working_photo` (§6.4) — a candidate cannot yet attach a photo
  of handwritten working.
