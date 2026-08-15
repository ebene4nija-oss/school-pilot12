//! The candidate shell — the window a candidate actually sits the paper in
//! (docs/offline-cbt-client.md §16, and the kiosk half of §8.2).
//!
//! §4: relay and candidate client are one binary in two modes. This is the
//! second mode. It opens a borderless fullscreen window over WebView2 and
//! renders `ui.rs` — one implementation of the paper, shared with the browser
//! path the relay serves at `/sit`.
//!
//! ## Why the page does not come off the network (§8.3)
//!
//! The obvious build points the webview at `https://relay/sit`. It was the
//! first one here, and pinning killed it: **WebView2 validates certificates
//! itself and wry exposes no hook to override that decision**, so a self-signed
//! relay certificate produces WebView2's own full-page warning — exactly the
//! click-through prompt §8.3 set out to avoid, relocated inside our own window
//! where it reads as a broken app.
//!
//! So the webview never speaks to the relay. Instead:
//!
//! 1. The window loads a **custom protocol** served from inside this binary —
//!    `ui.rs` and the vendored KaTeX, no network hop, nothing to intercept.
//! 2. Every `/relay/v1/...` call the page makes is caught by that same protocol
//!    handler and **proxied through Rust**, over a `rustls` client that pins the
//!    relay's certificate fingerprint ([`crate::tls`]).
//!
//! The page's own `fetch` paths are unchanged, because on Windows wry serves a
//! custom protocol as `http://schoolpilot.localhost/…` and a relative fetch
//! lands back on this handler. `ui.rs` does not know it is being proxied.
//!
//! That is a better shape than encrypting the page would have been: the paper's
//! markup never travels the lab network at all, and the only thing that does is
//! the JSON, over a connection that accepts exactly one certificate.
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

use std::borrow::Cow;

use tao::event::{Event, StartCause, WindowEvent};
use tao::event_loop::{ControlFlow, EventLoop};
use tao::window::{Fullscreen, WindowBuilder};
use wry::http::{Request, Response};
use wry::{WebViewBuilder, RequestAsyncResponder};

use crate::error::{RelayError, Result};

/// The custom protocol the window runs on.
///
/// On Windows this surfaces as `http://schoolpilot.localhost/…`, which is what
/// makes the page's existing relative `fetch` calls land back on this handler
/// rather than on a network.
const SCHEME: &str = "schoolpilot";

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
/// `relay_url` is the relay's address on the lab network. `fingerprint` is the
/// certificate the relay must present — read off the invigilator's screen at
/// setup, since §8.4's pairing step does not exist yet. Without it, an `https`
/// relay is refused outright rather than trusted blindly: an exam client that
/// silently accepts any certificate is worse than one that will not start,
/// because the failure is invisible.
pub fn run(relay_url: &str, fingerprint: Option<&str>) -> Result<()> {
    let relay = relay_url.trim_end_matches('/').to_string();
    let secure = relay.starts_with("https://");

    let client = match (secure, fingerprint) {
        (true, Some(pin)) => crate::tls::pinned_client(pin)?,

        (true, None) => {
            return Err(RelayError::Tls(
                "This relay is served over https and no --fingerprint was given, so there is \
                 nothing to check it against. Read the fingerprint off the relay's screen and \
                 pass it with --fingerprint."
                    .into(),
            ))
        }

        // Plain http: the demo and development path. Loud, and never silent.
        (false, _) => {
            tracing::warn!(
                "connecting to {relay} over plain http — question text will cross the network \
                 in the clear. Acceptable for a demo on one machine, never for a real paper."
            );
            reqwest::Client::builder()
                .timeout(std::time::Duration::from_secs(20))
                .build()
                .map_err(|error| RelayError::Tls(error.to_string()))?
        }
    };

    // reqwest is async and the event loop is not, so proxied calls run on the
    // runtime `main` already established. Building a second one here would
    // panic — `block_on` inside a runtime is not allowed — and would be the
    // wrong shape anyway: one process, one scheduler.
    let handle = tokio::runtime::Handle::current();

    // Prove the relay is the pinned one *before* a window opens.
    //
    // Without this the pin still holds, but it fails at the first save — which
    // is a candidate half way through question one being told to raise their
    // hand. A wrong fingerprint is a setup mistake, and setup mistakes belong
    // to the person at the keyboard during setup, not to a child sitting an
    // exam. §8.4 wanted this discovered "the day before, with time to do
    // something about it".
    tokio::task::block_in_place(|| handle.block_on(async {
        match client.get(format!("{relay}/relay/v1/status")).send().await {
            // Any HTTP answer means the handshake succeeded and the
            // certificate matched. A sealed relay answers 503 and that is
            // fine — it is the right relay, simply not unlocked yet.
            Ok(_) => Ok(()),

            Err(error) => Err(RelayError::Tls(format!(
                "could not confirm this is the right relay at {relay}: {error}. Check the \
                 address and the fingerprint against the invigilator's screen before starting \
                 this machine."
            ))),
        }
    }))?;

    let proxy_relay = relay.clone();

    let start = format!("{SCHEME}://localhost/sit");

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
        // Everything the window renders comes from inside this binary, and
        // everything it asks of the relay goes through `client` — see the
        // module note on why the webview never speaks to the network itself.
        .with_asynchronous_custom_protocol(
            SCHEME.to_string(),
            move |_webview_id: wry::WebViewId, request: Request<Vec<u8>>, responder: RequestAsyncResponder| {
                let client = client.clone();
                let relay = proxy_relay.clone();

                handle.spawn(async move {
                    responder.respond(dispatch(&client, &relay, request).await);
                });
            },
        )
        // The paper is the only destination. A link, a redirect or a script
        // that tries to leave it is refused rather than followed — there is no
        // reason for an exam window to be anywhere else, and "the browser
        // wandered off" is not something an invigilator can diagnose mid-paper.
        .with_navigation_handler(|url: String| is_the_paper(&url))
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

/// Serve one request from the window.
///
/// Three kinds, and nothing else exists: the page, its assets, and the relay's
/// API. Anything unrecognised is a 404 rather than a passthrough — a candidate
/// window has no general-purpose fetch.
async fn dispatch(
    client: &reqwest::Client,
    relay: &str,
    request: Request<Vec<u8>>,
) -> Response<Cow<'static, [u8]>> {
    let path = request.uri().path().to_string();

    if path == "/" || path == "/sit" {
        return html(crate::ui::CANDIDATE_APP);
    }

    if let Some((content_type, body)) = crate::assets::lookup(&path) {
        return Response::builder()
            .status(200)
            .header("content-type", content_type)
            .body(Cow::Borrowed(body))
            .expect("static asset response");
    }

    if path.starts_with("/relay/v1/") {
        return proxy(client, relay, request).await;
    }

    Response::builder()
        .status(404)
        .header("content-type", "text/plain; charset=utf-8")
        .body(Cow::Borrowed(&b"not part of the paper"[..]))
        .expect("404 response")
}

/// Forward one API call to the relay over the pinned connection.
async fn proxy(
    client: &reqwest::Client,
    relay: &str,
    request: Request<Vec<u8>>,
) -> Response<Cow<'static, [u8]>> {
    let target = match request.uri().query() {
        Some(query) => format!("{relay}{}?{query}", request.uri().path()),
        None => format!("{relay}{}", request.uri().path()),
    };

    let method = reqwest::Method::from_bytes(request.method().as_str().as_bytes())
        .unwrap_or(reqwest::Method::GET);

    let mut outgoing = client.request(method, &target);

    // Only content-type travels. The window has no cookies, no auth header and
    // no session of its own — the candidate's credentials are in the body,
    // where `ui.rs` puts them — so forwarding headers wholesale would copy
    // WebView2's own noise onto the relay for no benefit.
    if let Some(content_type) = request.headers().get("content-type") {
        outgoing = outgoing.header("content-type", content_type);
    }

    let body = request.into_body();
    if !body.is_empty() {
        outgoing = outgoing.body(body);
    }

    match outgoing.send().await {
        Ok(response) => {
            let status = response.status().as_u16();
            let content_type = response
                .headers()
                .get("content-type")
                .and_then(|value| value.to_str().ok())
                .unwrap_or("application/json")
                .to_string();

            let bytes = response.bytes().await.unwrap_or_default().to_vec();

            Response::builder()
                .status(status)
                .header("content-type", content_type)
                .body(Cow::Owned(bytes))
                .expect("proxied response")
        }

        // A failure here is the pin refusing an impostor, or the relay being
        // unreachable. `ui.rs` shows the `error` field to the candidate — it is
        // the "NOT saved — raise your hand" path — so the sentence has to be
        // one an invigilator can act on, not a transport dump.
        Err(error) => {
            tracing::error!("relay call failed: {error}");

            let message = if error.to_string().contains("certificate") {
                "This machine is not talking to the right relay. Stop and tell your invigilator."
            } else {
                "Lost contact with the relay. Your answer is not saved — raise your hand."
            };

            let body = serde_json::json!({ "error": message }).to_string();

            Response::builder()
                .status(502)
                .header("content-type", "application/json")
                .body(Cow::Owned(body.into_bytes()))
                .expect("proxy failure response")
        }
    }
}

fn html(body: &'static str) -> Response<Cow<'static, [u8]>> {
    Response::builder()
        .status(200)
        .header("content-type", "text/html; charset=utf-8")
        .body(Cow::Borrowed(body.as_bytes()))
        .expect("page response")
}

/// Is this URL the paper, rather than somewhere the window has wandered?
///
/// Both spellings have to pass: wry hands the page `schoolpilot://localhost/…`
/// on some platforms and rewrites it to `http://schoolpilot.localhost/…` on
/// Windows, and a guard that knew only one of those would either block the
/// paper or let everything through, depending which one it knew.
///
/// The boundary check is the point. A bare `starts_with` would accept
/// `http://schoolpilot.localhost.evil.example`, which is a real host somebody
/// could register.
fn is_the_paper(url: &str) -> bool {
    const ORIGINS: [&str; 3] = [
        "schoolpilot://localhost",
        "http://schoolpilot.localhost",
        "https://schoolpilot.localhost",
    ];

    ORIGINS.iter().any(|origin| match url.strip_prefix(origin) {
        Some(rest) => matches!(rest.chars().next(), None | Some('/') | Some('?') | Some('#')),
        None => false,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_paper_and_its_assets_are_reachable() {
        for allowed in [
            "schoolpilot://localhost/sit",
            "http://schoolpilot.localhost/sit",
            "http://schoolpilot.localhost/",
            "http://schoolpilot.localhost",
            "http://schoolpilot.localhost/relay/v1/paper?admission_number=X",
            "http://schoolpilot.localhost/assets/katex/fonts/KaTeX_Main-Regular.woff2",
        ] {
            assert!(is_the_paper(allowed), "`{allowed}` was refused");
        }
    }

    #[test]
    fn a_lookalike_host_is_not_the_paper() {
        // The case a bare `starts_with` gets wrong: every one of these has an
        // allowed origin as a text prefix, and `…localhost.evil.example` is a
        // host somebody could actually register.
        for refused in [
            "http://schoolpilot.localhost.evil.example/sit",
            "http://schoolpilot.localhostx/sit",
            "http://schoolpilot.localhost@evil.example/sit",
            "https://evil.example/sit",
            "file:///C:/Windows/System32/cmd.exe",
            "about:blank",
        ] {
            assert!(!is_the_paper(refused), "`{refused}` was allowed through");
        }
    }

    #[tokio::test]
    async fn the_window_serves_the_paper_and_its_maths_without_a_network() {
        // The whole point of the custom protocol: with the relay unreachable,
        // the page and every byte of KaTeX still render. Only the API needs the
        // relay, and that is the one thing pinned.
        let client = reqwest::Client::new();
        let unreachable = "https://127.0.0.1:1";

        for (path, expected) in [
            ("/sit", "text/html"),
            ("/assets/katex/katex.min.css", "text/css"),
            ("/assets/katex/fonts/KaTeX_Main-Regular.woff2", "font/woff2"),
        ] {
            let request = Request::builder().uri(path).body(Vec::new()).unwrap();
            let response = dispatch(&client, unreachable, request).await;

            assert_eq!(response.status(), 200, "{path} did not serve");
            assert!(!response.body().is_empty(), "{path} served nothing");
            assert!(response.headers()["content-type"]
                .to_str()
                .unwrap()
                .starts_with(expected));
        }
    }

    #[tokio::test]
    async fn a_path_that_is_not_part_of_the_paper_is_refused() {
        let client = reqwest::Client::new();

        for path in ["/etc/passwd", "/relay/v2/anything", "/../ui.rs", "/status"] {
            let request = Request::builder().uri(path).body(Vec::new()).unwrap();
            let response = dispatch(&client, "https://127.0.0.1:1", request).await;

            assert_eq!(response.status(), 404, "`{path}` was served");
        }
    }

    #[tokio::test]
    async fn an_unreachable_relay_tells_the_candidate_to_raise_their_hand() {
        // §11: a save that did not land must be loud. The candidate sees this
        // string, so it is asserted rather than assumed.
        let client = reqwest::Client::builder()
            .timeout(std::time::Duration::from_millis(300))
            .build()
            .unwrap();

        let request = Request::builder()
            .method("POST")
            .uri("/relay/v1/answers")
            .body(b"{}".to_vec())
            .unwrap();

        let response = dispatch(&client, "https://127.0.0.1:1", request).await;

        assert_eq!(response.status(), 502);

        let body = String::from_utf8(response.body().to_vec()).unwrap();
        assert!(body.contains("raise your hand"), "unhelpful failure: {body}");
    }
}
