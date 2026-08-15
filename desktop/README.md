# SchoolPilot Desktop — Lab Relay

The relay half of the offline CBT client. Design and rationale live in
[`docs/offline-cbt-client.md`](../docs/offline-cbt-client.md); this file is how
to run and work on the code.

**Status:** step 3 complete; step 4 nearly complete. The relay runs, a candidate
can sit a whole paper — maths included — and `relay candidate` opens it in a
fullscreen kiosk window. Pairing and pinned TLS are the rest of step 4 and do
not exist yet; read [Three things that will bite you](#three-things-that-will-bite-you)
and [Not done yet](#not-done-yet) before showing this to a school.

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

On each candidate machine, one command, and it holds nothing:

```powershell
relay candidate --relay http://10.0.0.4:8443
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
  main.rs      CLI: login, provision, serve, candidate, sync, status, purge
  api.rs       backend client — §9.1 issuance, §9.2 key release, §9.3 batch sync
  assets.rs    KaTeX, compiled in — there is no CDN in a lab (§16)
  bundle.rs    envelope, AES-256-GCM unseal, the paper's shape
  shuffle.rs   the determinism contract with the server (§10)
  store.rs     relay SQLite — attempts, answers, events, sync queue
  paper.rs     one candidate's paper, built from the roster's fixed order
  server.rs    candidate-facing HTTP + the invigilator's status window
  ui.rs        the candidate client — one self-contained page, no CDN
  kiosk.rs     the candidate's fullscreen window (§4's second mode)
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
  not quietly serve a real exam in the clear. **This is now the one thing
  standing between the client and a real exam room, and it needs a design
  decision — see below.**
- **Task-switching is not blocked.** The kiosk window is fullscreen,
  undecorated, always on top, refuses to close, and suppresses every browser
  affordance that leads out of the paper (context menu, devtools, view-source,
  print, new window, reload, drag-and-drop, navigation off the relay's origin).
  It does **not** block Alt-Tab or the Windows key: that needs a low-level
  keyboard hook, which antivirus flags and which needs privileges a school PC
  may not grant. So of §8.2's three claims — "fullscreen, suppressed
  task-switching, and a logged event trail" — the first and third are real and
  the second is partial. Focus loss is reported, not prevented. Say it that way
  to a school.
- Pairing and device tokens (§8.4), booklets and script capture (step 5),
  integrity enforcement (step 8).
- Media checksum verification on download — files are fetched and stored, but
  the manifest's `checksum` is recorded rather than checked.
- On-screen `allow_working_photo` (§6.4) — a candidate cannot yet attach a photo
  of handwritten working.
- LaTeX in the **stimulus of a group whose questions are plain** renders,
  but the JS port of the delimiter parser has no test of its own; the grammar
  is asserted only in PHP and TypeScript. See the note in `ui.rs`.

### The TLS decision that is now blocking (§8.3)

Building the kiosk window turned up something the spec did not anticipate, and
it changes what pinned TLS costs.

**WebView2 validates certificates itself, and wry exposes no hook to override
it.** A self-signed relay certificate therefore produces WebView2's own
full-page certificate error — the click-through warning §8.3 explicitly wanted
to avoid, now inside our own window where it looks even more like a bug. There
is no "pin this fingerprint" call to make; the webview is not ours to instruct.

So pinning cannot be bolted onto the current shape, where the window loads
`https://relay/sit` and the page's own `fetch` talks to the relay. It needs the
client restructured so that:

1. `ui.rs` is served to the window over a **custom protocol** from inside the
   binary, rather than fetched from the relay — the page then never travels the
   network at all, which is strictly better than encrypting it; and
2. every `/relay/v1/...` call is proxied **through Rust**, where `rustls` can
   pin the relay's fingerprint properly, instead of through the webview.

That is a real chunk of work — the client's whole networking path — and it is a
design fork, not a patch. It also happens to be the shape §3 describes best:
"candidate clients are thin". Worth doing deliberately rather than quickly.

Until it exists, `--lan` serves question text in the clear and prints a warning,
and that is the honest state.
