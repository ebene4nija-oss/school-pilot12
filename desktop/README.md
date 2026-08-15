# SchoolPilot Desktop — Lab Relay

The relay half of the offline CBT client. Design and rationale live in
[`docs/offline-cbt-client.md`](../docs/offline-cbt-client.md); this file is how
to run and work on the code.

**Status:** build-order step 3 of 11 — auth, provision, bundle storage, SQLite,
sync, status window, proven with a scripted fake candidate. The candidate
client, pairing and pinned TLS are step 4 and do not exist yet.

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
- Pairing and device tokens (§8.4), the candidate client (step 4), booklets and
  script capture (step 5), kiosk and integrity enforcement (step 8).
- Media checksum verification on download — files are fetched and stored, but
  the manifest's `checksum` is recorded rather than checked.
