//! The candidate client, served by the relay (docs/offline-cbt-client.md §19,
//! step 4 — paper rendering, answer capture, resume).
//!
//! **Why this is a page and not yet a Tauri window.** §16 chooses Tauri for the
//! candidate client, and that still stands: kiosk fullscreen, suppressed task
//! switching and a reliable `client_crashed` signal need a real window, and a
//! browser tab cannot honestly claim any of them. What a browser *can* do is
//! render the paper, capture answers, honour the deadline and report focus
//! events — which is the whole of the paper-sitting experience and every part
//! of it that a school will want to look at before committing to a rollout.
//!
//! So this ships first, served by the relay over the lab network to any machine
//! with a browser. The Tauri shell wraps this same markup later and adds the
//! things only a window can do. Nothing here is throwaway; the kiosk guarantees
//! are simply not claimed until they are real.
//!
//! Everything is inline and self-contained. §16 is emphatic that the web app
//! vendors KaTeX rather than using a CDN because "an exam hall may have no
//! internet, and a paper whose equations render as raw TeX is not a paper
//! anyone can sit" — the same reasoning forbids a stylesheet or a font from a
//! CDN here. There is no network in the room.

/// Maths is rendered by KaTeX, vendored into the binary by [`crate::assets`].
///
/// The segment parser below is a port of `web/src/components/RichContent.tsx`,
/// which is in turn a mirror of `MathContentService::extractExpressions` on the
/// server — same four delimiters, same longest-opener-first order, same
/// escaped-dollar rule so `\$20` stays a price. Three implementations of one
/// grammar is two too many, but the alternative is a candidate sitting a paper
/// that splits differently from the one their classmate sat online, so the
/// rule is the same as §10's: they must never drift. If you change one, change
/// all three.
pub const CANDIDATE_APP: &str = r##"<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SchoolPilot — Sit Your Paper</title>
<link rel="stylesheet" href="/assets/katex/katex.min.css">
<script src="/assets/katex/katex.min.js"></script>
<style>
  :root {
    --ink: #14181f; --muted: #5b6472; --line: #d8dee8; --bg: #f6f7f9;
    --card: #ffffff; --accent: #1f4e9c; --warn: #9a3412; --ok: #1a7f37;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--ink);
    font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
  }
  .wrap { max-width: 52rem; margin: 0 auto; padding: 1rem; }
  .card {
    background: var(--card); border: 1px solid var(--line);
    border-radius: .6rem; padding: 1.25rem; margin-bottom: 1rem;
  }
  h1 { font-size: 1.3rem; margin: 0 0 .25rem; }
  h2 { font-size: 1.05rem; margin: 0 0 .75rem; }
  label { display: block; font-weight: 600; margin: .75rem 0 .25rem; }
  input[type=text], textarea {
    width: 100%; padding: .6rem .7rem; font: inherit;
    border: 1px solid var(--line); border-radius: .4rem; background: #fff;
  }
  textarea { min-height: 9rem; resize: vertical; }
  button {
    font: inherit; font-weight: 600; padding: .6rem 1.1rem; cursor: pointer;
    border: 1px solid var(--accent); border-radius: .4rem;
    background: var(--accent); color: #fff;
  }
  button.ghost { background: #fff; color: var(--accent); }
  button:disabled { opacity: .45; cursor: not-allowed; }
  .row { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
  .spread { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
  .muted { color: var(--muted); }
  .small { font-size: .85rem; }
  .err { color: var(--warn); font-weight: 600; }
  .hidden { display: none !important; }

  /* The countdown must be glanceable from across a room (§15). */
  .clock {
    font-variant-numeric: tabular-nums; font-size: 1.6rem; font-weight: 700;
    letter-spacing: .02em;
  }
  .clock.low { color: var(--warn); }

  .stimulus {
    background: #fbfcfe; border-left: 3px solid var(--accent);
    padding: .8rem 1rem; margin-bottom: 1rem; max-height: 20rem; overflow-y: auto;
    white-space: pre-wrap;
  }
  .stem { white-space: pre-wrap; margin: 0 0 1rem; }
  .opt {
    display: flex; gap: .6rem; align-items: flex-start; padding: .55rem .7rem;
    border: 1px solid var(--line); border-radius: .4rem; margin-bottom: .5rem;
    cursor: pointer; background: #fff;
  }
  .opt:hover { border-color: var(--accent); }
  .opt input { margin-top: .35rem; }
  .opt .key { font-weight: 700; min-width: 1.3rem; }

  .nav { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .5rem; }
  .nav button {
    width: 2.4rem; padding: .3rem 0; font-size: .85rem;
    background: #fff; color: var(--ink); border-color: var(--line);
  }
  .nav button.done { background: #e7f0ff; border-color: var(--accent); color: var(--accent); }
  .nav button.flag { border-color: var(--warn); color: var(--warn); }
  .nav button.here { outline: 2px solid var(--accent); outline-offset: 1px; }

  .save { font-size: .85rem; font-weight: 600; }
  .save.ok { color: var(--ok); }
  .save.busy { color: var(--muted); }
  .save.bad { color: var(--warn); }
  .banner { background: #fff4ed; border: 1px solid #f0c6a8; padding: .7rem 1rem;
            border-radius: .4rem; margin-bottom: 1rem; }

  /* A displayed equation may be wider than a lab monitor. Let it scroll on its
     own rather than pushing the answer area off screen (§6.2's reasoning,
     applied to maths). */
  .math-display { display: block; margin: .6rem 0; overflow-x: auto; overflow-y: hidden; }
  .math-inline { display: inline-block; }
  .math-raw { font-family: ui-monospace, Consolas, monospace; color: var(--warn); }
</style>

<div class="wrap">

  <!-- ---------------------------------------------------------- sign in -->
  <div id="signin" class="card">
    <h1>Sit your paper</h1>
    <p class="muted small">Enter the details on the slip your invigilator gave you.</p>
    <label for="admission">Admission number</label>
    <input id="admission" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false">
    <label for="code">Paper code</label>
    <input id="code" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false">
    <p id="signin-error" class="err small hidden"></p>
    <p style="margin-top:1rem"><button id="start">Begin</button></p>
  </div>

  <!-- ------------------------------------------------------------ paper -->
  <div id="paper" class="hidden">
    <div class="card spread">
      <div>
        <h1 id="exam-title"></h1>
        <div class="muted small"><span id="who"></span> &middot; <span id="progress"></span></div>
      </div>
      <div style="text-align:right">
        <div id="clock" class="clock">--:--</div>
        <div id="save" class="save muted">Ready</div>
      </div>
    </div>

    <div id="resumed" class="banner hidden">
      <strong>Welcome back.</strong> Your earlier answers were saved by the relay and are still here.
    </div>
    <div id="latex-warning" class="banner hidden">
      This paper contains mathematical notation and the part of this client that
      draws it did not load. Do not continue — raise your hand and tell your
      invigilator now.
    </div>

    <div class="card">
      <div id="group" class="hidden">
        <h2 id="group-title"></h2>
        <div id="group-instructions" class="muted small" style="margin-bottom:.5rem"></div>
        <div id="stimulus" class="stimulus"></div>
        <div class="muted small" id="group-position" style="margin-bottom:1rem"></div>
      </div>

      <div class="spread" style="margin-bottom:.5rem">
        <h2 id="q-heading" style="margin:0"></h2>
        <span class="muted small" id="q-marks"></span>
      </div>

      <p id="stem" class="stem"></p>
      <div id="answer"></div>

      <p class="row" style="margin-top:1rem">
        <label class="row small" style="font-weight:normal;margin:0;cursor:pointer">
          <input type="checkbox" id="flag"> Flag for review
        </label>
      </p>

      <div class="row" style="margin-top:1rem">
        <button class="ghost" id="prev">Previous</button>
        <button class="ghost" id="next">Next</button>
        <span style="flex:1"></span>
        <button id="submit">Submit paper</button>
      </div>

      <div class="nav" id="nav"></div>
    </div>
  </div>

  <!-- ------------------------------------------------------------- done -->
  <div id="done" class="card hidden">
    <h1>Paper submitted</h1>
    <p id="done-message"></p>
    <p class="muted small">You may now leave the room quietly, or wait to be told to.</p>
  </div>

</div>

<script>
(function () {
  "use strict";

  var creds = null;      // { admission_number, relay_code }
  var paper = null;      // the served paper
  var answers = {};      // question_id -> response
  var flags = {};        // question_id -> bool
  var at = 0;            // index of the question on screen
  var deadline = null;
  var submitted = false;
  var saveTimer = null;
  var pending = {};      // question_id -> true while a save is in flight

  var $ = function (id) { return document.getElementById(id); };

  // ---------------------------------------------------------------- maths
  // A port of web/src/components/RichContent.tsx. Longest opener first, so
  // `$$` wins over `$` and `\[` over `\(`.

  var DELIMITERS = [
    ["$$", "$$", true],
    ["\\[", "\\]", true],
    ["\\(", "\\)", false],
    ["$", "$", false]
  ];

  function parseSegments(content) {
    var segments = [];
    var buffer = "";
    var i = 0;

    function flush() {
      if (buffer) { segments.push({ kind: "text", value: buffer }); buffer = ""; }
    }

    while (i < content.length) {
      // `\$20` is a price, not an opening delimiter.
      if (content[i] === "\\" && content[i + 1] === "$") {
        buffer += "$";
        i += 2;
        continue;
      }

      var match = null;
      for (var d = 0; d < DELIMITERS.length; d++) {
        if (content.startsWith(DELIMITERS[d][0], i)) { match = DELIMITERS[d]; break; }
      }

      if (!match) { buffer += content[i]; i += 1; continue; }

      var from = i + match[0].length;
      var closeAt = content.indexOf(match[1], from);

      if (closeAt === -1) {
        // The server rejects unclosed delimiters at authoring time, so reaching
        // here means legacy content. Show it literally rather than swallowing
        // the rest of the question.
        buffer += content.slice(i);
        break;
      }

      flush();
      segments.push({ kind: "math", value: content.slice(from, closeAt), display: match[2] });
      i = closeAt + match[1].length;
    }

    flush();
    return segments;
  }

  // Put authored content into an element: prose as text nodes, maths as KaTeX.
  //
  // Prose is never assigned as HTML. A stem is authored by a teacher and could
  // contain markup, and nothing in this product needs a teacher to be able to
  // inject HTML into a paper.
  function rich(el, content, format) {
    el.innerHTML = "";

    if (content === null || content === undefined) { return; }

    // Most questions in a bank are prose, and a paper is sixty of them on a lab
    // machine that is not fast. Skip the parser unless the author said maths.
    if (format !== "latex" || !window.katex) {
      el.appendChild(document.createTextNode(content));
      return;
    }

    parseSegments(content).forEach(function (segment) {
      if (segment.kind === "text") {
        el.appendChild(document.createTextNode(segment.value));
        return;
      }

      var span = document.createElement("span");

      try {
        // Same options as the web runner. `trust: false` blocks the
        // HTML-injecting commands even though the server already refuses them;
        // `throwOnError: false` means a bad expression shows as source rather
        // than blanking the question mid-exam.
        span.innerHTML = window.katex.renderToString(segment.value, {
          displayMode: segment.display,
          throwOnError: false,
          trust: false,
          strict: "ignore",
          output: "htmlAndMathml"
        });
        span.className = segment.display ? "math-display" : "math-inline";
      } catch (error) {
        // Belt and braces: renderToString should not throw with the options
        // above, but a candidate must never lose a question to an exception.
        span.className = "math-raw";
        span.textContent = segment.value;
      }

      el.appendChild(span);
    });
  }

  function show(id) {
    ["signin", "paper", "done"].forEach(function (name) {
      $(name).classList.toggle("hidden", name !== id);
    });
  }

  function post(path, body) {
    return fetch(path, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) { throw new Error(data.error || "Request failed"); }
        return data;
      });
    });
  }

  function withCreds(extra) {
    var body = { admission_number: creds.admission_number, relay_code: creds.relay_code };
    for (var key in extra) { if (extra.hasOwnProperty(key)) { body[key] = extra[key]; } }
    return body;
  }

  function saveState(kind, text) {
    var el = $("save");
    el.className = "save " + kind;
    el.textContent = text;
  }

  // ------------------------------------------------------------ sign in

  $("start").addEventListener("click", function () {
    var admission = $("admission").value.trim();
    var code = $("code").value.trim();

    if (!admission || !code) {
      return signinError("Enter both your admission number and the paper code.");
    }

    creds = { admission_number: admission, relay_code: code };

    post("/relay/v1/session", withCreds({}))
      .then(function (session) {
        $("resumed").classList.toggle("hidden", !session.resumed);
        return loadPaper(session);
      })
      .catch(function (error) { signinError(error.message); });
  });

  $("code").addEventListener("keydown", function (event) {
    if (event.key === "Enter") { $("start").click(); }
  });

  function signinError(message) {
    var el = $("signin-error");
    el.textContent = message;
    el.classList.remove("hidden");
  }

  function loadPaper(session) {
    var query = "?admission_number=" + encodeURIComponent(creds.admission_number)
              + "&relay_code=" + encodeURIComponent(creds.relay_code);

    return fetch("/relay/v1/paper" + query)
      .then(function (response) { return response.json(); })
      .then(function (data) {
        paper = data;
        deadline = data.server_deadline_at ? new Date(data.server_deadline_at) : null;

        $("exam-title").textContent = data.exam.title;
        $("who").textContent = data.candidate_name || creds.admission_number;

        // §16: a paper whose equations render as raw TeX is not a paper anyone
        // can sit. KaTeX is vendored into the relay binary and served from it,
        // so the only way it is missing is a build or a route that broke — in
        // which case say so up front rather than letting a candidate discover
        // it at question 14.
        var latex = data.exam.content_format === "latex"
          || data.questions.some(function (q) {
               return q.content_format === "latex"
                 || (q.group && q.group.content_format === "latex");
             });
        $("latex-warning").classList.toggle("hidden", !(latex && !window.katex));

        show("paper");
        render();
        tick();
        setInterval(tick, 1000);
      });
  }

  // ------------------------------------------------------------- render

  function render() {
    var q = paper.questions[at];
    if (!q) { return; }

    $("q-heading").textContent = "Question " + q.position + " of " + paper.questions.length;
    $("q-marks").textContent = q.marks + (q.marks === 1 ? " mark" : " marks");
    $("progress").textContent = Object.keys(answers).length + " of "
      + paper.questions.length + " answered";
    rich($("stem"), q.question, q.content_format);

    // §6.2: the stimulus stays visible while any of its sub-questions is on
    // screen, and the candidate must know which sub-question they are on.
    if (q.group) {
      $("group").classList.remove("hidden");
      rich($("group-title"), q.group.title || "Read the following", q.group.content_format);
      rich($("group-instructions"), q.group.instructions || "", q.group.content_format);
      rich($("stimulus"), q.group.stimulus || "", q.group.content_format);
      $("group-position").textContent = "Question " + q.group.position_in_group
        + " of " + q.group.questions_in_group + " on this passage";
    } else {
      $("group").classList.add("hidden");
    }

    $("flag").checked = !!flags[q.question_id];
    renderAnswer(q);
    renderNav();

    $("prev").disabled = at === 0;
    $("next").disabled = at === paper.questions.length - 1;
  }

  function renderAnswer(q) {
    var host = $("answer");
    host.innerHTML = "";

    if (q.options && q.options.length) {
      q.options.forEach(function (option) {
        var label = document.createElement("label");
        label.className = "opt";

        var input = document.createElement("input");
        input.type = "radio";
        input.name = "q" + q.question_id;
        input.value = option.key;
        input.checked = answers[q.question_id] === option.key;
        input.addEventListener("change", function () {
          answers[q.question_id] = option.key;
          save(q.question_id, option.key);
          renderNav();
          $("progress").textContent = Object.keys(answers).length + " of "
            + paper.questions.length + " answered";
        });

        var key = document.createElement("span");
        key.className = "key";
        key.textContent = option.key + ".";

        // Options carry no format of their own; an option to a maths question
        // is maths (`3x^2` as a distractor is the normal case, not the odd one).
        var text = document.createElement("span");
        rich(text, option.text, q.content_format);

        label.appendChild(input);
        label.appendChild(key);
        label.appendChild(text);
        host.appendChild(label);
      });
      return;
    }

    // The manually-graded family (§6.3). `on_paper` questions show the
    // instruction and store no response — the marks arrive later from the
    // booklet, and typing into a box that goes nowhere would be a lie.
    if (q.answer_mode === "on_paper") {
      var note = document.createElement("p");
      note.className = "banner";
      note.textContent = "Answer this question in your printed booklet, not on screen.";
      host.appendChild(note);
      return;
    }

    var area = document.createElement("textarea");
    area.value = answers[q.question_id] || "";
    var max = q.interaction && q.interaction.max_words;

    var counter = document.createElement("div");
    counter.className = "muted small";

    function words(value) {
      var trimmed = value.trim();
      return trimmed ? trimmed.split(/\s+/).length : 0;
    }

    function updateCounter() {
      var count = words(area.value);
      counter.textContent = max ? count + " / " + max + " words" : count + " words";
      counter.style.color = max && count > max ? "var(--warn)" : "";
    }

    area.addEventListener("input", function () {
      // Hard cap enforced client-side (§6.3), by refusing extra words rather
      // than truncating mid-sentence behind the candidate's back.
      if (max && words(area.value) > max) {
        area.value = area.value.trim().split(/\s+/).slice(0, max).join(" ");
      }
      answers[q.question_id] = area.value;
      updateCounter();
      queueSave(q.question_id, area.value);
    });

    updateCounter();
    host.appendChild(area);
    host.appendChild(counter);
  }

  function renderNav() {
    var nav = $("nav");
    nav.innerHTML = "";

    paper.questions.forEach(function (q, index) {
      var button = document.createElement("button");
      button.textContent = q.position;
      if (answers[q.question_id] !== undefined && answers[q.question_id] !== "") {
        button.className = "done";
      }
      if (flags[q.question_id]) { button.className += " flag"; }
      if (index === at) { button.className += " here"; }
      button.addEventListener("click", function () { at = index; render(); });
      nav.appendChild(button);
    });
  }

  $("prev").addEventListener("click", function () { if (at > 0) { at--; render(); } });
  $("next").addEventListener("click", function () {
    if (at < paper.questions.length - 1) { at++; render(); }
  });

  $("flag").addEventListener("change", function () {
    var q = paper.questions[at];
    flags[q.question_id] = $("flag").checked;
    save(q.question_id, answers[q.question_id]);
    renderNav();
  });

  // -------------------------------------------------------------- saving

  function queueSave(questionId, value) {
    // Autosave on a pause, not on every keystroke: a 500-word essay would
    // otherwise be five hundred writes and five hundred sequence numbers.
    clearTimeout(saveTimer);
    saveState("busy", "Saving…");
    saveTimer = setTimeout(function () { save(questionId, value); }, 900);
  }

  function save(questionId, value) {
    if (value === undefined) { return; }

    pending[questionId] = true;
    saveState("busy", "Saving…");

    post("/relay/v1/answers", withCreds({
      question_id: questionId,
      response: value,
      flagged_for_review: !!flags[questionId]
    })).then(function () {
      delete pending[questionId];
      saveState("ok", "Saved");
    }).catch(function (error) {
      // The relay holds the record; a failure here means the answer is only in
      // this browser. The candidate must be told, loudly, and told what to do.
      saveState("bad", "NOT saved — raise your hand");
      console.error(error);
    });
  }

  // ------------------------------------------------------------- the clock

  function tick() {
    if (!deadline || submitted) { return; }

    var left = Math.floor((deadline - new Date()) / 1000);

    if (left <= 0) {
      $("clock").textContent = "00:00";
      return doSubmit(true);
    }

    var hours = Math.floor(left / 3600);
    var minutes = Math.floor((left % 3600) / 60);
    var seconds = left % 60;
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };

    $("clock").textContent = (hours ? hours + ":" : "") + pad(minutes) + ":" + pad(seconds);
    $("clock").classList.toggle("low", left <= 300);
  }

  // ------------------------------------------------------------- submit

  $("submit").addEventListener("click", function () { doSubmit(false); });

  function doSubmit(automatic) {
    if (submitted) { return; }

    var unanswered = paper.questions.filter(function (q) {
      return q.answer_mode !== "on_paper"
        && (answers[q.question_id] === undefined || answers[q.question_id] === "");
    }).length;

    if (!automatic && unanswered > 0) {
      var ok = window.confirm(
        unanswered + " question" + (unanswered === 1 ? " is" : "s are") +
        " still unanswered. Submit anyway?"
      );
      if (!ok) { return; }
    }

    submitted = true;
    clearTimeout(saveTimer);

    post("/relay/v1/submit", withCreds({}))
      .then(function (result) {
        $("done-message").textContent = result.message;
        show("done");
      })
      .catch(function (error) {
        submitted = false;
        window.alert("Could not submit: " + error.message + "\nRaise your hand.");
      });
  }

  // ------------------------------------------------------------- events
  // §12: evidence for a human reviewing a malpractice question later, never
  // grounds for anything automatic.

  function report(type, metadata) {
    if (!creds || submitted) { return; }
    post("/relay/v1/events", withCreds({ event_type: type, metadata: metadata || null }))
      .catch(function () { /* an event must never interrupt a paper */ });
  }

  window.addEventListener("blur", function () { report("focus_lost"); });
  window.addEventListener("focus", function () { report("focus_regained"); });

  document.addEventListener("paste", function (event) {
    // Record the size, not the content: a candidate may legitimately copy
    // their own earlier text, and this is evidence, not an accusation.
    var text = (event.clipboardData || window.clipboardData);
    var length = text ? (text.getData("text") || "").length : 0;
    if (length > 40) { report("paste", { characters: length }); }
  });

  window.addEventListener("beforeunload", function (event) {
    if (creds && !submitted) {
      event.preventDefault();
      event.returnValue = "";
    }
  });
})();
</script>
"##;
