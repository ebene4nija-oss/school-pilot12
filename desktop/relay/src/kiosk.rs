//! The candidate shell — the window a candidate actually sits the paper in
//! (docs/offline-cbt-client.md §16, and the kiosk half of §8.2).
//!
//! §4: relay and candidate client are one binary in two modes. This is the
//! second mode. It opens a borderless fullscreen window over WebView2 and
//! points it at the relay's `/sit` page, which means the paper-rendering code
//! has exactly one implementation — `ui.rs` — rather than one for the browser
//! and one for the window.
//!
//! **What this honestly is.** §8.2 sets the claim precisely: "kiosk fullscreen,
//! suppressed task-switching, and a logged event trail an invigilator can
//! review" — with a human still walking the room. A desktop app on a school's
//! own Windows PCs cannot truly lock the machine down, and the specification is
//! explicit that claiming otherwise "will eventually cost an argument with a
//! parent over a malpractice ruling". So:
//!
//! - Fullscreen, undecorated, always on top: **done here.**
//! - Browser affordances that let a candidate wander — context menu, devtools,
//!   view-source, print, new window, reload, in-page navigation away from the
//!   relay: **suppressed here.**
//! - Alt-Tab, Ctrl-Alt-Del, the Windows key: **not blocked, deliberately.**
//!   Eating those needs a low-level keyboard hook, which antivirus flags, which
//!   needs privileges a school PC may not grant, and which buys deterrence the
//!   invigilator already provides by walking the room. Focus loss is *reported*
//!   instead (§12), which is what an invigilator reviewing a malpractice
//!   question can actually use.
//!
//! That last line is the difference between evidence and prevention, and §8.2
//! already chose evidence.

use tao::event::{Event, StartCause, WindowEvent};
use tao::event_loop::{ControlFlow, EventLoop};
use tao::window::{Fullscreen, WindowBuilder};
use wry::WebViewBuilder;

use crate::error::{RelayError, Result};

/// Injected before the page runs.
///
/// Everything here removes a way *out* of the paper. Nothing here tries to
/// remove a way out of the machine — see the module note.
///
/// Text selection, copy and paste inside the answer box are deliberately left
/// alone: a candidate legitimately copies their own earlier text while writing
/// an essay, and `ui.rs` already reports a large paste as evidence rather than
/// blocking it (§12).
const LOCKDOWN: &str = r#"
(function () {
  "use strict";

  // The right-click menu offers reload, view-source, print and inspect.
  document.addEventListener("contextmenu", function (e) { e.preventDefault(); }, true);

  document.addEventListener("keydown", function (e) {
    var k = (e.key || "").toLowerCase();
    var ctrl = e.ctrlKey || e.metaKey;

    var blocked =
      k === "f12" ||                                   // devtools
      k === "f5" ||                                    // reload
      (ctrl && e.shiftKey && (k === "i" || k === "j" || k === "c")) ||
      (ctrl && (k === "r" || k === "u" || k === "p" ||  // reload, source, print
                k === "n" || k === "t" || k === "w" ||  // new window/tab, close
                k === "s" || k === "o" || k === "f"));  // save, open, find

    if (blocked) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  // A drag-and-dropped file would navigate the webview away from the paper.
  window.addEventListener("dragover", function (e) { e.preventDefault(); }, false);
  window.addEventListener("drop", function (e) { e.preventDefault(); }, false);
})();
"#;

/// Run the candidate shell against a relay.
///
/// `relay_url` is the relay's address on the lab network. Everything the window
/// is allowed to reach lives under it; see the navigation guard below.
pub fn run(relay_url: &str) -> Result<()> {
    let origin = origin_of(relay_url);
    let start = format!("{}/sit", relay_url.trim_end_matches('/'));

    let event_loop = EventLoop::new();

    let window = WindowBuilder::new()
        .with_title("SchoolPilot — Examination")
        .with_decorations(false)
        .with_always_on_top(true)
        .with_resizable(false)
        // Borderless rather than exclusive: exclusive fullscreen on an old lab
        // GPU is a mode switch that can fail, flicker, or come back on the
        // wrong monitor. Borderless is the same thing to a candidate and does
        // not touch the display mode.
        .with_fullscreen(Some(Fullscreen::Borderless(None)))
        .build(&event_loop)
        .map_err(|error| RelayError::Kiosk(error.to_string()))?;

    let _webview = WebViewBuilder::new()
        .with_url(&start)
        .with_initialization_script(LOCKDOWN)
        // The paper is the only destination. A link, a redirect or a script
        // that tries to leave the relay's origin is refused rather than
        // followed — on a school network there is no reason for the exam window
        // to be anywhere else, and "the browser wandered off" is not something
        // an invigilator can diagnose mid-paper.
        .with_navigation_handler(move |url: String| is_within(&origin, &url))
        .with_new_window_req_handler(|_url: String| false)
        .build(&window)
        .map_err(|error| RelayError::Kiosk(error.to_string()))?;

    event_loop.run(move |event, _, control_flow| {
        *control_flow = ControlFlow::Wait;

        match event {
            Event::NewEvents(StartCause::Init) => {
                tracing::info!("candidate shell up, showing {start}");
            }

            // The candidate cannot close the window out from under their own
            // paper. Submission is a button in the page, which is the only
            // path that tells the relay anything; closing the window would
            // just abandon an attempt silently.
            //
            // This is not a trap: the invigilator ends a sitting from the
            // relay, and a machine can always be powered off — at which point
            // the relay still holds every answer (§5.3, §14).
            Event::WindowEvent { event: WindowEvent::CloseRequested, .. } => {
                tracing::warn!("close requested and refused; the paper is still open");
            }

            Event::WindowEvent { event: WindowEvent::Destroyed, .. } => {
                *control_flow = ControlFlow::Exit;
            }

            _ => {}
        }
    });
}

/// Is this URL inside the relay's origin?
///
/// A bare `starts_with` is not enough and the difference is a real hole:
/// `http://relay:8443` is a prefix of `http://relay:8443.evil.example`, so a
/// naive check would happily navigate to somebody else's host. The character
/// after the origin has to be a path, query or fragment boundary — or nothing
/// at all.
fn is_within(origin: &str, url: &str) -> bool {
    let Some(rest) = url.strip_prefix(origin) else {
        return false;
    };

    matches!(rest.chars().next(), None | Some('/') | Some('?') | Some('#'))
}

/// The scheme-and-authority prefix everything in the window must stay under.
fn origin_of(url: &str) -> String {
    let trimmed = url.trim_end_matches('/');

    match trimmed.find("://") {
        Some(at) => {
            let rest = &trimmed[at + 3..];
            let host_len = rest.find('/').unwrap_or(rest.len());
            format!("{}://{}", &trimmed[..at], &rest[..host_len])
        }
        // No scheme is a mistake worth failing closed on rather than guessing.
        None => trimmed.to_string(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_origin_is_scheme_and_authority_only() {
        assert_eq!(origin_of("https://10.0.0.4:8443/sit"), "https://10.0.0.4:8443");
        assert_eq!(origin_of("http://relay.local:8443/"), "http://relay.local:8443");
        assert_eq!(origin_of("https://10.0.0.4:8443"), "https://10.0.0.4:8443");
    }

    #[test]
    fn the_paper_and_its_assets_are_reachable() {
        let origin = origin_of("http://relay:8443");

        for allowed in [
            "http://relay:8443",
            "http://relay:8443/",
            "http://relay:8443/sit",
            "http://relay:8443/relay/v1/paper?admission_number=X",
            "http://relay:8443/assets/katex/fonts/KaTeX_Main-Regular.woff2",
        ] {
            assert!(is_within(&origin, allowed), "`{allowed}` was refused");
        }
    }

    #[test]
    fn a_lookalike_host_is_not_the_relay() {
        // The case a bare `starts_with` gets wrong, and the reason `is_within`
        // exists: every one of these has the relay's origin as a text prefix.
        let origin = origin_of("http://relay:8443");

        for refused in [
            "http://relay:8443.evil.example/sit",
            "http://relay:84439/sit",
            "http://relay:8443@evil.example/sit",
            "http://relay:8443x/sit",
            "http://relay.evil.example/sit",
            "http://relay:9999/sit",
            "https://relay:8443/sit",
            "file:///C:/Windows/System32/cmd.exe",
        ] {
            assert!(!is_within(&origin, refused), "`{refused}` was allowed through");
        }
    }
}
