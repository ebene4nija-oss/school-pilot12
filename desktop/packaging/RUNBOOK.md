# SchoolPilot Offline CBT — installing in a computer lab

For the person setting up the room. You do not need to be a developer, but you
do need to be an administrator on these machines.

**What this does:** lets a school run a CBT exam on the computers it already
owns, with **no internet during the paper**. One laptop (the *relay*) carries
the encrypted paper into the lab and holds every answer. The lab PCs show the
paper and send answers back to that laptop over the school's own network.

**What you need before you start:**

- One laptop to be the relay — the invigilator's, or one lab PC.
- The lab machines on the **same network** as that laptop. Wired or Wi-Fi,
  **and it does not need internet**. A router with no data plan is fine.
- Administrator rights on all of them.
- Ten seconds of internet on the relay laptop on exam morning, which can be a
  phone hotspot.

---

## Before exam week: install

### 1. On the relay laptop

Right-click PowerShell → **Run as administrator**, then:

```powershell
cd <this folder>
.\Install-Relay.ps1 -FingerprintOut .\fingerprint.txt
```

It prints a **fingerprint** — a long string like `e0:fc:48:3c:…`. This is how
every lab PC recognises the real relay. It does not change. `-FingerprintOut`
saves it onto this stick so the next step can read it automatically, which is
what you want when you are about to walk to forty machines.

Then sign in to your school (needs internet, once):

```powershell
relay login --base-url https://<your-school>.schoolpilot.ng --email you@school.ng
```

### 2. On every lab PC

**Every machine that will be used, not a sample.** A PC you skip is a PC that
cannot sit the paper.

With the same stick, as administrator:

```powershell
.\Install-Candidate.ps1 -RelayUrl https://<relay-ip>:8443 -FingerprintFile .\fingerprint.txt
```

To find `<relay-ip>`, run `ipconfig` on the relay laptop and take the IPv4
address on the lab's network.

This puts a **Sit Exam** shortcut on the desktop.

---

## The day before an exam

### 3. Download the paper (relay laptop, needs internet)

```powershell
relay provision --exam <exam id>
```

### 4. Pair the room

On the relay laptop:

```powershell
relay pair
```

It shows a short code like `T7RN-DVPN` and waits. **Leave it running.** Then on
each lab PC:

```powershell
.\Install-Candidate.ps1 -RelayUrl https://<relay-ip>:8443 `
                        -FingerprintFile .\fingerprint.txt `
                        -PairingCode T7RN-DVPN
```

When the room is done, press Ctrl-C on the relay. It lists what it paired —
check the count against the number of machines in the room.

Pairing is also how you find out the network is wrong **with a day to fix it**
rather than on exam morning. If a machine cannot reach the relay here, see
*Troubleshooting*.

---

## Exam morning

On the relay laptop, with ten seconds of internet (phone hotspot is fine):

```powershell
relay serve --lan
```

It fetches the key, opens the paper into memory, and **does not touch the
internet again**. You can put the phone away.

On each lab PC: the candidate opens **Sit Exam**. They enter the admission
number and paper code from their slip. That is the whole of it.

The relay's screen shows how many candidates are seated, how many have
submitted, and how many answers it is holding. Watch that, not the PCs.

**If a machine dies mid-paper:** move the candidate to a spare machine and have
them sign in again. Their answers are on the relay, not on the dead PC. They
resume where they left off, and the clock keeps the deadline it was given.

---

## After the exam

Take the relay laptop somewhere with internet — any time, there is no rush:

```powershell
relay sync
```

It reports what was uploaded. Then, once results are confirmed:

```powershell
relay purge
```

**Do not skip the purge.** The laptop is holding real children's exam answers,
and it should not carry them around for a term.

---

## Troubleshooting

**"This machine has not been paired"** — that PC was missed on pairing day. Run
`relay pair` on the relay and re-run `Install-Candidate.ps1` on that PC with the
code. It takes a minute.

**A PC reaches the internet but not the relay** — this is almost always
**client isolation** on a guest Wi-Fi network, which blocks machines on the same
network from talking to each other. That is exactly the traffic an exam needs.
Use a different network, or ask whoever manages the router to turn client
isolation off for the lab.

**"could not confirm this is the right relay"** — the fingerprint on that PC does
not match the relay that answered. Check the relay's IP address is right and
that the fingerprint matches the relay's screen. Do **not** work around this by
skipping the fingerprint; it is what stops a machine trusting the wrong relay.

**"The exam window could not be opened"** mentioning WebView2 — that machine is
missing the WebView2 runtime and the installer had no copy to install. See
`webview2\PUT-WEBVIEW2-INSTALLER-HERE.txt`.

**SmartScreen: "Windows protected your PC"** — this software is not yet
code-signed, so Windows warns on first run. Click **More info** → **Run anyway**.
If your school's policy does not allow that, tell us and we will arrange a
signed build.

**The relay laptop dies mid-exam** — its state is a single file. Copy
`%LOCALAPPDATA%\SchoolPilot\relay\` to another laptop with the relay installed
and run `relay serve --lan` there. Practise this once before you rely on it.

---

## What this software will and will not do

It **will**: run the paper with no internet, keep every answer on the relay
rather than on the lab PCs, survive a machine dying, hold the deadline centrally
so a wrong clock on a lab PC cannot extend or shorten a paper, and record when a
candidate leaves the exam window.

It **will not**: stop a determined candidate from switching to another program
with Alt-Tab, or from photographing the screen. The exam window is fullscreen
and reports when it loses focus, and that report is evidence an invigilator can
review afterwards — **it is not a substitute for someone walking the room.**
Please do not describe it to parents as though it were.

Results are **not** shown at the end of an offline paper. They appear once the
school releases them, exactly as for a paper compiled online. That is the
deliberate trade for the paper never needing the internet: no answer key is ever
carried into the exam room.
