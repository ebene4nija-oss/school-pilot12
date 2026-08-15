# SchoolPilot Desktop — Lab Relay

The relay half of the offline CBT client. Design and rationale live in
[`docs/offline-cbt-client.md`](../docs/offline-cbt-client.md); this file is how
to run and work on the code.

**Status:** step 3 complete; step 4 complete but for pairing. The relay runs and
serves over pinned TLS, a candidate can sit a whole paper — maths included — and
`relay candidate` opens it in a fullscreen kiosk window that refuses to start
against a relay it cannot verify. Pairing (§8.4) is the remaining piece; read
[Not done yet](#not-done-yet) before showing this to a school.

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

On each candidate machine, one command, and it holds nothing. The fingerprint is
printed by `relay serve` — it is what makes this the *right* relay (§8.3):

```powershell
relay candidate --relay https://10.0.0.4:8443 --fingerprint e0:fc:48:3c:…
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
| `tests/pinning.rs` | Real TLS handshakes against a real server: the pinned relay is reached, an impostor holding its own valid self-signed certificate is refused, hostname is not what is checked, and a truncated fingerprint never matches. A pin never exercised against an impostor is a comment, not a control. |
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
  tls.rs       the relay's certificate and the candidate's pin (§8.3)
  config.rs    paths and the staff token
```

## Three things that will bite you

**The relay's certificate is not the content key.** `relay-key.pem` lives on
disk on purpose — it is the relay's identity across every exam it will serve,
and a fingerprint that changed each morning would be unusable. The *content*
key, below, is the one that must never touch a disk. Do not let the two blur.

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

## How pinned TLS works here (§8.3)

`relay serve` now speaks HTTPS and prints the fingerprint candidates must pin:

```
    relay candidate --relay https://10.0.0.4:8443 \
      --fingerprint e0:fc:48:3c:…
```

The certificate is generated once, at first `serve`, and kept — a fingerprint
that changed every morning would have to be re-read to forty machines every
exam, which is the per-machine ritual §3.1 rejected the standalone design over.

**The webview never touches the network.** Building the kiosk turned up the
constraint that shapes this: WebView2 validates certificates itself and wry
exposes no hook to override it, so pointing the window at `https://relay/sit`
would produce WebView2's own full-page certificate warning — the click-through
prompt §8.3 set out to avoid, relocated inside our own window. So instead the
window loads a custom protocol served from inside the binary, and `kiosk.rs`
proxies every `/relay/v1/...` call through Rust, where `rustls` pins properly.
The paper's markup never crosses the lab network at all; only JSON does, over a
connection that accepts exactly one certificate.

**What the pin checks, and what it ignores.** `PinnedServerCertVerifier`
accepts one certificate — the one whose SHA-256 matches — and deliberately does
not check hostname, expiry or chain, because none of those mean anything for a
self-signed certificate on a DHCP address. That is *stricter* than ordinary web
PKI, not weaker: ordinary verification accepts any of hundreds of CAs for a
matching name; this accepts one key and nothing else.

The residual risk is fingerprint delivery. §8.4's pairing was going to carry it;
without pairing it is read off the invigilator's screen at setup — supervised,
one-time, out of band, which is the property pairing was providing.

A wrong or missing fingerprint **refuses to open a window at all**, rather than
failing at the first save with a candidate already sitting there. `tests/pinning.rs`
runs real handshakes: the right relay is reached, an impostor holding its own
valid self-signed certificate is refused, and a truncated pin never matches.
