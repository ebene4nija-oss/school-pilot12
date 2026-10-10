//! The few pages this app draws itself, served from inside the binary on the
//! `spexam` custom protocol (`http://spexam.localhost/…` on Windows).
//!
//! Everything else on screen is the school's own portal. These exist for the
//! moments the portal cannot speak for itself: before a school is chosen,
//! while there is no internet to load it, and the invigilator's strip above it.
//!
//! Each page takes its settings as one JSON object substituted for
//! `__CONFIG__`, escaped so nothing typed at setup can close the script tag.

use serde_json::Value;

/// Height of the invigilator strip, in logical pixels.
pub const BAR_HEIGHT: f64 = 48.0;

/// Fill a page's `__CONFIG__` with `config`, safe inside a `<script>`.
pub fn render(page: &str, config: &Value) -> String {
    let json = config.to_string().replace('<', "\\u003c").replace('>', "\\u003e").replace('&', "\\u0026");
    page.replace("__CONFIG__", &json)
}

const STYLE: &str = r#"
  :root { --brand:#002447; --brand-soft:#1b3a5f; --accent:#feae2c; --ink:#1b1c1c; --muted:#5c5f66;
          --surface:#ffffff; --sunken:#f4f1f0; --ok:#1f9d55; --bad:#c2410c; }
  * { box-sizing: border-box; }
  html, body { margin: 0; height: 100%; font-family: "Segoe UI", system-ui, sans-serif; color: var(--ink); }
  button { font: inherit; cursor: pointer; border-radius: 8px; border: 1px solid transparent; padding: 6px 14px; }
  input { font: inherit; border: 1px solid #c9ccd3; border-radius: 8px; padding: 8px 10px; }
  input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
"#;

/// The strip above the portal: school, connection, clock, Home, Invigilator.
pub const BAR: &str = r#"<!doctype html>
<html><head><meta charset="utf-8"><style>
__STYLE__
  body { background: var(--brand); color: #fff; overflow: hidden; user-select: none; }
  .row { display: flex; align-items: center; gap: 14px; height: 48px; padding: 0 14px; white-space: nowrap; }
  .name { font-weight: 600; letter-spacing: .2px; }
  .name small { font-weight: 400; opacity: .7; margin-left: 6px; }
  .net { display: flex; align-items: center; gap: 7px; font-size: 14px; overflow: hidden; text-overflow: ellipsis; }
  .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--ok); flex: none; }
  .net.off .dot { background: var(--accent); }
  .spacer { flex: 1; }
  .clock { font-variant-numeric: tabular-nums; opacity: .9; }
  .ghost { background: transparent; color: #fff; border-color: rgba(255,255,255,.35); }
  .ghost:hover { background: rgba(255,255,255,.1); }
  .primary { background: var(--accent); color: #2b1d00; font-weight: 600; }
  .danger { background: #fff; color: var(--bad); font-weight: 600; }
  .notice { font-size: 13px; background: rgba(254,174,44,.18); border: 1px solid rgba(254,174,44,.5);
            padding: 3px 10px; border-radius: 999px; overflow: hidden; text-overflow: ellipsis; }
  .msg { font-size: 14px; overflow: hidden; text-overflow: ellipsis; }
  .msg.err { color: #ffd59a; }
  input { width: 120px; padding: 5px 8px; letter-spacing: 3px; }
  [hidden] { display: none !important; }
</style></head>
<body>
  <div class="row">
    <span class="name">SchoolPilot Exam<small id="school"></small></span>
    <span class="net" id="net"><span class="dot"></span><span id="netText">Online</span></span>
    <span class="notice" id="notice" hidden></span>
    <span class="spacer"></span>

    <span id="idle" class="row" style="padding:0">
      <span class="clock" id="clock"></span>
      <button class="ghost" id="home">Home</button>
      <button class="ghost" id="invig">Invigilator</button>
    </span>

    <form id="pin" class="row" style="padding:0" hidden>
      <span class="msg" id="pinMsg">Invigilator PIN</span>
      <input id="pinInput" type="password" inputmode="numeric" autocomplete="off" maxlength="12">
      <button class="primary" type="submit">Unlock</button>
      <button class="ghost" type="button" data-cancel>Cancel</button>
    </form>

    <span id="menu" class="row" style="padding:0" hidden>
      <span class="msg" id="menuMsg"></span>
      <button class="danger" id="exit">Exit app</button>
      <button class="ghost" id="anyway" hidden>Continue anyway</button>
      <button class="ghost" id="school-change">Change school</button>
      <button class="ghost" data-cancel>Cancel</button>
    </span>
  </div>
<script>
(function () {
  "use strict";
  var CFG = __CONFIG__;
  var $ = function (id) { return document.getElementById(id); };
  var send = function (msg) { window.ipc.postMessage(JSON.stringify(msg)); };

  $("school").textContent = "· " + CFG.school;
  // Nothing to be online to, and nowhere to go home to, before setup.
  if (!CFG.portal) { $("net").hidden = true; $("home").hidden = true; }

  function show(which) {
    ["idle", "pin", "menu"].forEach(function (id) { $(id).hidden = id !== which; });
    if (which === "pin") { $("pinInput").value = ""; $("pinInput").focus(); }
  }

  // Called from Rust.
  window.bar = {
    pinRefused: function (text) {
      show("pin");
      $("pinMsg").textContent = text; $("pinMsg").className = "msg err";
    },
    unlocked: function () {
      show("menu");
      $("menuMsg").textContent = ""; $("anyway").hidden = true; $("exit").hidden = false;
    },
    unsent: function (text) {
      show("menu");
      $("menuMsg").textContent = text; $("menuMsg").className = "msg err";
      $("exit").hidden = true; $("anyway").hidden = false;
    },
    reset: function () { show("idle"); },
    notice: function (text) { $("notice").textContent = text; $("notice").hidden = !text; }
  };

  $("home").onclick = function () { send({ kind: "home" }); };
  $("invig").onclick = function () {
    $("pinMsg").textContent = "Invigilator PIN"; $("pinMsg").className = "msg";
    show("pin");
  };
  $("pin").onsubmit = function (e) { e.preventDefault(); send({ kind: "pin", pin: $("pinInput").value }); };
  $("exit").onclick = function () { send({ kind: "exit" }); };
  $("anyway").onclick = function () { send({ kind: "exit_anyway" }); };
  $("school-change").onclick = function () { send({ kind: "change_school" }); };
  Array.prototype.forEach.call(document.querySelectorAll("[data-cancel]"), function (b) {
    b.onclick = function () { send({ kind: "cancel" }); show("idle"); };
  });

  function tick() {
    $("clock").textContent = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  }
  tick(); setInterval(tick, 5000);

  // navigator.onLine only knows about the cable. A no-cors request to the
  // portal knows about the internet: it resolves if anything answered.
  function setNet(up) {
    $("net").className = up ? "net" : "net off";
    $("netText").textContent = up ? "Online"
      : "No internet — answers are kept on this computer and will send when it is back";
  }
  function probe() {
    if (!CFG.portal) return;
    if (!navigator.onLine) { setNet(false); return; }
    var ctl = new AbortController(); var t = setTimeout(function () { ctl.abort(); }, 8000);
    fetch(CFG.portal + "/favicon.ico?probe=" + Date.now(), { mode: "no-cors", cache: "no-store", signal: ctl.signal })
      .then(function () { setNet(true); }, function () { setNet(false); })
      .finally(function () { clearTimeout(t); });
  }
  window.addEventListener("online", probe);
  window.addEventListener("offline", probe);
  probe(); setInterval(probe, 15000);

  document.addEventListener("contextmenu", function (e) { e.preventDefault(); });
})();
</script>
</body></html>
"#;

/// Shown until the portal answers: at start-up, and after Home.
pub const CONNECT: &str = r#"<!doctype html>
<html><head><meta charset="utf-8"><style>
__STYLE__
  body { background: var(--sunken); display: grid; place-items: center; }
  .card { background: var(--surface); max-width: 520px; margin: 24px; padding: 36px 32px; border-radius: 16px;
          box-shadow: 0 10px 30px rgba(0,36,71,.08); text-align: center; }
  h1 { color: var(--brand); font-size: 22px; margin: 18px 0 8px; }
  p { color: var(--muted); line-height: 1.55; margin: 8px 0; }
  .spin { width: 38px; height: 38px; margin: 0 auto; border-radius: 50%; border: 4px solid #dfe3ea;
          border-top-color: var(--brand); animation: s 1s linear infinite; }
  .off .spin { border-top-color: var(--accent); }
  .safe { background: #fff7e8; color: #6b4500; border-radius: 10px; padding: 12px 14px; margin-top: 16px; }
  @keyframes s { to { transform: rotate(360deg); } }
  [hidden] { display: none; }
</style></head>
<body>
  <div class="card" id="card">
    <div class="spin"></div>
    <h1 id="title">Opening your school's portal…</h1>
    <p id="sub"></p>
    <p class="safe" id="safe" hidden>Any answers already given on this computer are saved here and will be sent
      as soon as the internet is back. Keep this app open.</p>
  </div>
<script>
(function () {
  "use strict";
  var CFG = __CONFIG__;
  var $ = function (id) { return document.getElementById(id); };
  $("sub").textContent = CFG.portal.replace(/^https?:\/\//, "");

  var tries = 0;
  function attempt() {
    tries++;
    var ctl = new AbortController(); var t = setTimeout(function () { ctl.abort(); }, 10000);
    fetch(CFG.portal + "/favicon.ico?probe=" + Date.now(), { mode: "no-cors", cache: "no-store", signal: ctl.signal })
      .then(function () { location.replace(CFG.target); })
      .catch(function () {
        $("card").className = "card off";
        $("title").textContent = "Waiting for the internet";
        $("sub").textContent = "Trying again every few seconds (" + tries + ")…";
        $("safe").hidden = !CFG.held;
        setTimeout(attempt, 5000);
      })
      .finally(function () { clearTimeout(t); });
  }
  attempt();
  document.addEventListener("contextmenu", function (e) { e.preventDefault(); });
})();
</script>
</body></html>
"#;

/// First run, or "Change school": which portal, and the invigilator PIN.
pub const SETUP: &str = r#"<!doctype html>
<html><head><meta charset="utf-8"><style>
__STYLE__
  body { background: var(--sunken); display: grid; place-items: center; }
  form { background: var(--surface); width: 100%; max-width: 480px; margin: 24px; padding: 32px; border-radius: 16px;
         box-shadow: 0 10px 30px rgba(0,36,71,.08); }
  h1 { color: var(--brand); font-size: 22px; margin: 0 0 6px; }
  p { color: var(--muted); line-height: 1.5; margin: 0 0 20px; font-size: 14px; }
  label { display: block; font-weight: 600; font-size: 14px; margin: 16px 0 6px; }
  label small { font-weight: 400; color: var(--muted); }
  input { width: 100%; }
  .two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .err { background: #fdecea; color: #8a1c0b; border-radius: 8px; padding: 10px 12px; margin-top: 16px; font-size: 14px; }
  button { margin-top: 22px; width: 100%; padding: 11px; background: var(--brand); color: #fff; font-weight: 600; }
  [hidden] { display: none; }
</style></head>
<body>
  <form id="f" autocomplete="off">
    <h1>Set up this computer</h1>
    <p>Done once by an invigilator or the school's IT person. Candidates will only ever see your school's portal.</p>

    <label for="school">School code or web address <small>— e.g. <b>kings</b> for kings.schoolpilot.org.ng</small></label>
    <input id="school" required autofocus>

    <div class="two">
      <div><label for="pin">Invigilator PIN</label><input id="pin" type="password" inputmode="numeric" maxlength="12" required></div>
      <div><label for="confirm">Repeat PIN</label><input id="confirm" type="password" inputmode="numeric" maxlength="12" required></div>
    </div>
    <p style="margin:8px 0 0">4–12 digits. Needed to close the app or change the school, so keep it from candidates.</p>

    <div class="err" id="err" hidden></div>
    <button type="submit">Save and open the portal</button>
  </form>
<script>
(function () {
  "use strict";
  var CFG = __CONFIG__;
  var $ = function (id) { return document.getElementById(id); };
  if (CFG.school) $("school").value = CFG.school;

  window.setupError = function (text) { $("err").textContent = text; $("err").hidden = false; };

  $("f").onsubmit = function (e) {
    e.preventDefault();
    $("err").hidden = true;
    window.ipc.postMessage(JSON.stringify({
      kind: "setup", school: $("school").value, pin: $("pin").value, confirm: $("confirm").value
    }));
  };
})();
</script>
</body></html>
"#;

/// A page with the shared stylesheet in place.
pub fn page(source: &str) -> String {
    source.replace("__STYLE__", STYLE)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn typed_text_cannot_close_the_script_tag() {
        let html = render(&page(SETUP), &json!({ "school": "</script><script>alert(1)</script>" }));
        assert!(!html.contains("</script><script>alert(1)"));
        assert!(html.contains("\\u003c/script\\u003e"));
    }

    #[test]
    fn every_page_is_filled() {
        for source in [BAR, CONNECT, SETUP] {
            let html = render(&page(source), &json!({ "school": "kings", "portal": "https://x", "target": "https://x/", "held": false }));
            assert!(!html.contains("__CONFIG__") && !html.contains("__STYLE__"));
        }
    }
}
