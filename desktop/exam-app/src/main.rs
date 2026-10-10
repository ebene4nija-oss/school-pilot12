//! SchoolPilot Exam — the school's own CBT portal in a locked, fullscreen
//! window for the exam hall.
//!
//! Exams are set on the web and sat on the web, exactly as on the mobile app.
//! This program adds only what a browser on a lab PC cannot promise:
//!
//! - **The window stays on the portal.** Fullscreen and borderless, no address
//!   bar, no devtools, no reload or print keys, no navigating to any other
//!   site, and no closing it without the invigilator's PIN.
//! - **Answers outlive the internet.** The portal's exam page writes every
//!   answer to localStorage before sending it and sends whatever is held when
//!   the line comes back (web `student/tests/[attemptId]`). This app gives
//!   that storage a permanent home (a WebView2 profile in `%LOCALAPPDATA%`),
//!   keeps the exam page open until it has sent everything, and on a restart
//!   reopens the attempt that still has answers waiting.
//! - **An invigilator strip** above the portal: school, whether the internet
//!   is up, the time, Home, and a PIN-locked Exit.
//!
//! What it deliberately does not do: block Alt-Tab, Ctrl-Alt-Del or the
//! Windows key. That needs a low-level keyboard hook that antivirus flags and
//! school PCs may not permit; the portal reports a candidate leaving the page
//! for the invigilator walking the room, which is the evidence a malpractice
//! ruling actually needs. No camera, nothing biometric (doc §3).

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod config;
mod pages;

use std::borrow::Cow;
use std::cell::RefCell;
use std::path::PathBuf;
use std::rc::Rc;
use std::time::{Duration, Instant};

use serde_json::{json, Value};
use tao::dpi::{LogicalPosition, LogicalSize};
use tao::event::{Event, StartCause, WindowEvent};
use tao::event_loop::{ControlFlow, EventLoopBuilder, EventLoopProxy};
use tao::window::{Fullscreen, Window, WindowBuilder};
use wry::http::{Request, Response};
use wry::{PageLoadEvent, Rect, WebContext, WebView, WebViewBuilder, WebViewBuilderExtWindows};

use config::{is_own_page, is_within, Config, State};

const VERSION: &str = env!("CARGO_PKG_VERSION");

/// The app's own pages, as WebView2 sees them.
const OWN: &str = "http://spexam.localhost";

/// How long an unlocked invigilator menu stays unlocked.
const UNLOCK_FOR: Duration = Duration::from_secs(120);
/// Wrong PINs before the strip stops listening for a minute.
const MAX_PIN_TRIES: u32 = 5;
/// How often the exam page is asked which attempts still hold answers.
const HELD_EVERY: Duration = Duration::from_secs(20);
/// How long Exit waits for the exam page to say what it still holds.
const HELD_ANSWER_WAIT: Duration = Duration::from_secs(4);

/// Injected into every portal page: removes the browser's ways out of the
/// paper. Copy and paste inside an answer stay — the portal reports a large
/// paste rather than blocking it.
const LOCKDOWN: &str = r#"
(function () {
  "use strict";
  document.addEventListener("contextmenu", function (e) { e.preventDefault(); }, true);
  document.addEventListener("keydown", function (e) {
    var k = (e.key || "").toLowerCase(), ctrl = e.ctrlKey || e.metaKey;
    if (k === "f12" || k === "f5" || k === "f11" ||
        (ctrl && e.shiftKey && (k === "i" || k === "j" || k === "c")) ||
        (ctrl && "rupntwsof".indexOf(k) >= 0 && k.length === 1)) {
      e.preventDefault(); e.stopPropagation();
    }
  }, true);
  window.addEventListener("dragover", function (e) { e.preventDefault(); });
  window.addEventListener("drop", function (e) { e.preventDefault(); });
})();
"#;

/// Asks the portal page which attempts hold unsent answers (the exam page
/// keeps them under `cbt-held-<attemptId>`).
fn held_probe(reason: &str) -> String {
    format!(
        r#"(function () {{
  var ids = [];
  try {{
    for (var i = 0; i < localStorage.length; i++) {{
      var k = localStorage.key(i);
      if (k && k.indexOf("cbt-held-") === 0) ids.push(k.slice(9));
    }}
  }} catch (e) {{}}
  window.ipc.postMessage(JSON.stringify({{ kind: "held", ids: ids, reason: "{reason}" }}));
}})();"#
    )
}

/// Asks the portal which installer version is current. Same-origin through
/// the portal's API proxy, so the app needs no HTTP client of its own.
const UPDATE_PROBE: &str = r#"
fetch("/api/backend/public/downloads/offline-cbt/latest", { cache: "no-store" })
  .then(function (r) { return r.ok ? r.json() : null; })
  .then(function (j) { if (j && j.data) window.ipc.postMessage(JSON.stringify({ kind: "latest", data: j.data })); })
  .catch(function () {});
"#;

enum Ev {
    /// A message from the invigilator strip.
    Bar(Value),
    /// A message from the main window, with the page that sent it.
    Main(Value, String),
    /// The main window finished loading a page.
    Loaded(String),
}

#[derive(Clone, Copy, PartialEq)]
enum Pending {
    Exit,
    ChangeSchool,
}

struct App {
    machine_dir: PathBuf,
    user_dir: PathBuf,
    config: Option<Config>,
    state: State,
    /// Where `/connect` sends the window once the portal answers. `None`
    /// means "wherever the state says to resume".
    next_target: Option<String>,
    setup_open: bool,
    unlocked_until: Option<Instant>,
    pin_failures: u32,
    locked_until: Option<Instant>,
    /// An Exit or Change school waiting on the exam page's held-answer report.
    pending: Option<(Pending, Instant)>,
    /// Pin checked, unsent answers shown; the next "anyway" does this.
    confirm_anyway: Option<Pending>,
    update_checked: bool,
}

impl App {
    fn portal(&self) -> Option<String> {
        self.config.as_ref().map(|c| c.portal.clone())
    }

    fn unlocked(&self) -> bool {
        self.config.is_none() || self.unlocked_until.is_some_and(|t| Instant::now() < t)
    }

    /// The page the custom protocol serves for `path`.
    fn serve(&mut self, path: &str) -> Option<String> {
        let school = self.config.as_ref().map(|c| c.label().to_string());

        Some(match (path, &self.config) {
            ("/bar", _) => pages::render(
                &pages::page(pages::BAR),
                &json!({ "school": school.unwrap_or_else(|| "not set up".into()), "portal": self.portal().unwrap_or_default() }),
            ),

            ("/connect", Some(config)) => {
                let target = self.next_target.take().unwrap_or_else(|| self.state.resume_target(&config.portal));
                pages::render(
                    &pages::page(pages::CONNECT),
                    &json!({ "portal": config.portal, "target": target, "held": !self.state.held.is_empty() }),
                )
            }

            ("/setup", _) | ("/connect", None) => {
                self.setup_open = true;
                pages::render(&pages::page(pages::SETUP), &json!({ "school": school }))
            }

            _ => return None,
        })
    }
}

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();

    if args.iter().any(|a| a == "--version") {
        println!("SchoolPilot Exam {VERSION}");
        return;
    }

    // Unattended set-up from the installer: `--configure --school kings --pin 1234`.
    if args.iter().any(|a| a == "--configure") {
        std::process::exit(configure(&args));
    }

    if let Err(message) = run() {
        alert(&format!(
            "SchoolPilot Exam could not start.\n\n{message}\n\nIf this says WebView2, install the Microsoft Edge WebView2 Runtime and try again."
        ));
        std::process::exit(1);
    }
}

fn configure(args: &[String]) -> i32 {
    let value = |name: &str| args.iter().position(|a| a == name).and_then(|i| args.get(i + 1)).cloned();

    let (Some(school), Some(pin)) = (value("--school"), value("--pin")) else {
        eprintln!("usage: SchoolPilotExam --configure --school <code or address> --pin <4-12 digits>");
        return 2;
    };

    match Config::new(&school, &pin, &pin).and_then(|c| c.save(&config::machine_dir()).map(|_| c)) {
        Ok(config) => {
            println!("This computer now opens {}", config.portal);
            0
        }
        Err(message) => {
            eprintln!("{message}");
            1
        }
    }
}

fn run() -> Result<(), String> {
    let machine_dir = config::machine_dir();
    let user_dir = config::user_dir();

    let config = Config::load(&machine_dir).or_else(|| Config::load(&user_dir));
    let state = State::load(&user_dir);

    let app = Rc::new(RefCell::new(App {
        machine_dir,
        user_dir: user_dir.clone(),
        config,
        state,
        next_target: None,
        setup_open: false,
        unlocked_until: None,
        pin_failures: 0,
        locked_until: None,
        pending: None,
        confirm_anyway: None,
        update_checked: false,
    }));

    let event_loop = EventLoopBuilder::<Ev>::with_user_event().build();
    let proxy = event_loop.create_proxy();

    let window = WindowBuilder::new()
        .with_title("SchoolPilot Exam")
        .with_decorations(false)
        .with_always_on_top(true)
        .with_resizable(false)
        // Borderless rather than exclusive: an old lab GPU switching display
        // mode can fail, flicker, or come back on the wrong monitor.
        .with_fullscreen(Some(Fullscreen::Borderless(None)))
        .build(&event_loop)
        .map_err(|e| e.to_string())?;

    // One permanent profile for both views: this is where the exam page's
    // held answers live, so it must survive restarts and must not sit in
    // Program Files, which a candidate account cannot write.
    let mut context = WebContext::new(Some(user_dir.join("WebView2")));

    let bar = WebViewBuilder::with_web_context(&mut context)
        .with_bounds(bar_bounds(&window))
        .with_url(format!("{OWN}/bar"))
        .with_custom_protocol("spexam".into(), protocol(&app))
        .with_ipc_handler(forward(&proxy, |msg, _| Ev::Bar(msg)))
        .with_navigation_handler(|url| is_own_page(&url))
        .with_new_window_req_handler(|_| false)
        .with_browser_accelerator_keys(false)
        .with_devtools(cfg!(debug_assertions))
        .build_as_child(&window)
        .map_err(|e| format!("WebView2: {e}"))?;

    let nav_app = Rc::clone(&app);
    let load_proxy = proxy.clone();

    let main = WebViewBuilder::with_web_context(&mut context)
        .with_bounds(main_bounds(&window))
        .with_url(format!("{OWN}/connect"))
        .with_initialization_script(LOCKDOWN)
        .with_custom_protocol("spexam".into(), protocol(&app))
        .with_ipc_handler(forward(&proxy, Ev::Main))
        // Only the school's portal and this app's pages. A link, redirect or
        // script that tries to leave is refused, not followed.
        .with_navigation_handler(move |url| match nav_app.try_borrow() {
            Ok(app) => is_own_page(&url) || app.config.as_ref().is_some_and(|c| c.allows(&url)),
            Err(_) => is_own_page(&url),
        })
        .with_new_window_req_handler(|_| false)
        .with_download_started_handler(|_, _| false)
        .with_on_page_load_handler(move |event, url| {
            if matches!(event, PageLoadEvent::Finished) {
                let _ = load_proxy.send_event(Ev::Loaded(url));
            }
        })
        .with_browser_accelerator_keys(false)
        .with_hotkeys_zoom(false)
        .with_devtools(cfg!(debug_assertions))
        .build_as_child(&window)
        .map_err(|e| format!("WebView2: {e}"))?;

    let mut last_held_probe = Instant::now();

    event_loop.run(move |event, _, control_flow| {
        // Keep `context` alive as long as the views that use it.
        let _ = &context;
        *control_flow = ControlFlow::WaitUntil(Instant::now() + Duration::from_secs(2));

        match event {
            Event::NewEvents(StartCause::Init) => {
                let _ = main.focus();
            }

            Event::WindowEvent { event: WindowEvent::Resized(_) | WindowEvent::ScaleFactorChanged { .. }, .. } => {
                let _ = bar.set_bounds(bar_bounds(&window));
                let _ = main.set_bounds(main_bounds(&window));
            }

            // Alt-F4 and friends. Closing would abandon a paper silently; the
            // way out is the invigilator's Exit, which checks first.
            Event::WindowEvent { event: WindowEvent::CloseRequested, .. } => {
                if app.borrow().config.is_none() {
                    *control_flow = ControlFlow::Exit;
                }
            }

            Event::UserEvent(Ev::Bar(msg)) => on_bar(&app, &msg, &bar, &main, control_flow),

            Event::UserEvent(Ev::Main(msg, source)) => on_main(&app, &msg, &source, &bar, &main, control_flow),

            Event::UserEvent(Ev::Loaded(url)) => {
                let (on_portal, check_update) = {
                    let mut a = app.borrow_mut();
                    let on_portal = a.portal().is_some_and(|p| is_within(&url, &p));
                    if on_portal {
                        a.state.last_url = Some(url.clone());
                        a.state.save(&a.user_dir);
                    }
                    let check = on_portal && !a.update_checked;
                    if check {
                        a.update_checked = true;
                    }
                    (on_portal, check)
                };

                if on_portal {
                    let _ = main.evaluate_script(&held_probe("load"));
                    last_held_probe = Instant::now();
                }
                if check_update {
                    let _ = main.evaluate_script(UPDATE_PROBE);
                }
            }

            Event::NewEvents(StartCause::ResumeTimeReached { .. }) => {
                let (on_portal, stale) = {
                    let a = app.borrow();
                    let on_portal = a.portal().is_some_and(|p| main.url().is_ok_and(|u| is_within(&u, &p)));
                    let stale = a.pending.is_some_and(|(_, since)| since.elapsed() > HELD_ANSWER_WAIT);
                    (on_portal, stale)
                };

                if on_portal && last_held_probe.elapsed() > HELD_EVERY {
                    let _ = main.evaluate_script(&held_probe("tick"));
                    last_held_probe = Instant::now();
                }

                // The page never answered (it is mid-navigation, or offline on
                // an error page): decide on what it last reported.
                if stale {
                    let pending = app.borrow_mut().pending.take();
                    if let Some((action, _)) = pending {
                        let held = app.borrow().state.held.clone();
                        decide(&app, action, &held, &bar, &main, control_flow);
                    }
                }
            }

            _ => {}
        }
    });
}

fn on_bar(app: &Rc<RefCell<App>>, msg: &Value, bar: &WebView, main: &WebView, control_flow: &mut ControlFlow) {
    let kind = msg["kind"].as_str().unwrap_or_default();

    match kind {
        "home" => {
            let portal = app.borrow().portal();
            if let Some(portal) = portal {
                app.borrow_mut().next_target = Some(format!("{portal}/"));
                let _ = main.load_url(&format!("{OWN}/connect"));
            }
        }

        "pin" => {
            let pin = msg["pin"].as_str().unwrap_or_default();
            let reply = {
                let mut a = app.borrow_mut();
                let now = Instant::now();

                if a.locked_until.is_some_and(|t| now < t) {
                    "bar.pinRefused('Too many wrong PINs. Wait a minute and try again.')".to_string()
                } else if a.config.as_ref().map_or(true, |c| c.pin_matches(pin)) {
                    a.pin_failures = 0;
                    a.unlocked_until = Some(now + UNLOCK_FOR);
                    "bar.unlocked()".to_string()
                } else {
                    a.pin_failures += 1;
                    if a.pin_failures >= MAX_PIN_TRIES {
                        a.pin_failures = 0;
                        a.locked_until = Some(now + Duration::from_secs(60));
                    }
                    "bar.pinRefused('Wrong PIN.')".to_string()
                }
            };
            let _ = bar.evaluate_script(&reply);
        }

        "exit" | "change_school" => {
            let action = if kind == "exit" { Pending::Exit } else { Pending::ChangeSchool };
            if !app.borrow().unlocked() {
                let _ = bar.evaluate_script("bar.reset()");
                return;
            }

            // Ask the exam page itself — it knows about answers given since
            // the last report. If the window is not on the portal right now,
            // the last report is all there is.
            let on_portal = app.borrow().portal().is_some_and(|p| main.url().is_ok_and(|u| is_within(&u, &p)));
            if on_portal {
                app.borrow_mut().pending = Some((action, Instant::now()));
                let _ = main.evaluate_script(&held_probe("exit"));
            } else {
                let held = app.borrow().state.held.clone();
                decide(app, action, &held, bar, main, control_flow);
            }
        }

        "exit_anyway" => {
            let action = {
                let mut a = app.borrow_mut();
                if !a.unlocked() {
                    None
                } else {
                    a.confirm_anyway.take()
                }
            };
            match action {
                Some(action) => proceed(app, action, bar, main, control_flow),
                None => {
                    let _ = bar.evaluate_script("bar.reset()");
                }
            }
        }

        "cancel" => {
            let mut a = app.borrow_mut();
            a.unlocked_until = None;
            a.confirm_anyway = None;
            a.pending = None;
        }

        _ => {}
    }
}

fn on_main(app: &Rc<RefCell<App>>, msg: &Value, source: &str, bar: &WebView, main: &WebView, control_flow: &mut ControlFlow) {
    match msg["kind"].as_str().unwrap_or_default() {
        "held" => {
            // Only the school's own pages can speak for its localStorage.
            let from_portal = app.borrow().portal().is_some_and(|p| is_within(source, &p));
            if !from_portal {
                return;
            }

            let ids: Vec<String> = msg["ids"]
                .as_array()
                .map(|a| a.iter().filter_map(|v| v.as_str()).filter(|s| s.len() <= 20 && s.chars().all(|c| c.is_ascii_digit())).map(String::from).collect())
                .unwrap_or_default();

            let pending = {
                let mut a = app.borrow_mut();
                if a.state.held != ids {
                    a.state.held = ids.clone();
                    a.state.save(&a.user_dir);
                }
                if msg["reason"] == "exit" { a.pending.take() } else { None }
            };

            if let Some((action, _)) = pending {
                decide(app, action, &ids, bar, main, control_flow);
            }
        }

        "latest" => {
            let data = &msg["data"];
            let newest = data["version"].as_str().unwrap_or_default();
            let minimum = data["min_supported_version"].as_str().unwrap_or_default();

            let notice = if !minimum.is_empty() && config::version_older(VERSION, minimum) {
                format!("This app ({VERSION}) is out of date — install version {newest} before the next exam")
            } else if !newest.is_empty() && config::version_older(VERSION, newest) {
                format!("Update available: version {newest}")
            } else {
                return;
            };
            let _ = bar.evaluate_script(&format!("bar.notice({})", json!(notice)));
        }

        "setup" => {
            // Only this app's own setup page, and only while it is meant to be open.
            if !is_own_page(source) || !app.borrow().setup_open {
                return;
            }

            let field = |name: &str| msg[name].as_str().unwrap_or_default().to_string();
            let result = Config::new(&field("school"), &field("pin"), &field("confirm")).and_then(|config| {
                let a = app.borrow();
                // Machine-wide when the installer prepared the folder; this
                // user only when it did not (a developer's run).
                config.save(&a.machine_dir).or_else(|_| config.save(&a.user_dir)).map(|_| config)
            });

            match result {
                Ok(config) => {
                    {
                        let mut a = app.borrow_mut();
                        a.config = Some(config);
                        a.setup_open = false;
                        a.update_checked = false;
                        a.unlocked_until = None;
                        a.state = State::default();
                        a.state.save(&a.user_dir);
                    }
                    let _ = bar.load_url(&format!("{OWN}/bar"));
                    let _ = main.load_url(&format!("{OWN}/connect"));
                }
                Err(message) => {
                    let _ = main.evaluate_script(&format!("setupError({})", json!(message)));
                }
            }
        }

        _ => {}
    }
}

/// Exit or change school now, or say what is still unsent and wait for "anyway".
fn decide(app: &Rc<RefCell<App>>, action: Pending, held: &[String], bar: &WebView, main: &WebView, control_flow: &mut ControlFlow) {
    if held.is_empty() {
        proceed(app, action, bar, main, control_flow);
        return;
    }

    app.borrow_mut().confirm_anyway = Some(action);

    let papers = if held.len() == 1 { "1 paper on this computer has".to_string() } else { format!("{} papers on this computer have", held.len()) };
    let text = format!("{papers} answers not yet sent. Keep the app open until the internet is back.");
    let _ = bar.evaluate_script(&format!("bar.unsent({})", json!(text)));
}

fn proceed(app: &Rc<RefCell<App>>, action: Pending, bar: &WebView, main: &WebView, control_flow: &mut ControlFlow) {
    match action {
        Pending::Exit => *control_flow = ControlFlow::Exit,
        Pending::ChangeSchool => {
            {
                let mut a = app.borrow_mut();
                a.unlocked_until = None;
                a.confirm_anyway = None;
            }
            let _ = bar.evaluate_script("bar.reset()");
            let _ = main.load_url(&format!("{OWN}/setup"));
        }
    }
}

/// The `spexam` protocol handler: this app's three pages, nothing else.
fn protocol(app: &Rc<RefCell<App>>) -> impl Fn(wry::WebViewId, Request<Vec<u8>>) -> Response<Cow<'static, [u8]>> + 'static {
    let app = Rc::clone(app);

    move |_, request| {
        let page = app.try_borrow_mut().ok().and_then(|mut a| a.serve(request.uri().path()));

        match page {
            Some(html) => Response::builder()
                .status(200)
                .header("content-type", "text/html; charset=utf-8")
                .header("cache-control", "no-store")
                .body(Cow::Owned(html.into_bytes())),
            None => Response::builder().status(404).body(Cow::Borrowed(&b"not found"[..])),
        }
        .expect("static response")
    }
}

/// Turn an IPC message into an event for the loop. Anything that is not a
/// JSON object is dropped — a portal page can post anything it likes.
fn forward(proxy: &EventLoopProxy<Ev>, make: impl Fn(Value, String) -> Ev + 'static) -> impl Fn(Request<String>) + 'static {
    let proxy = proxy.clone();

    move |request| {
        let source = request.uri().to_string();
        if let Ok(msg @ Value::Object(_)) = serde_json::from_str::<Value>(request.body()) {
            let _ = proxy.send_event(make(msg, source));
        }
    }
}

fn logical_size(window: &Window) -> LogicalSize<f64> {
    window.inner_size().to_logical(window.scale_factor())
}

fn bar_bounds(window: &Window) -> Rect {
    let size = logical_size(window);
    Rect {
        position: LogicalPosition::new(0.0, 0.0).into(),
        size: LogicalSize::new(size.width, pages::BAR_HEIGHT).into(),
    }
}

fn main_bounds(window: &Window) -> Rect {
    let size = logical_size(window);
    Rect {
        position: LogicalPosition::new(0.0, pages::BAR_HEIGHT).into(),
        size: LogicalSize::new(size.width, (size.height - pages::BAR_HEIGHT).max(0.0)).into(),
    }
}

/// A message box, for the one failure there is no window to show in.
fn alert(text: &str) {
    #[cfg(windows)]
    {
        #[link(name = "user32")]
        extern "system" {
            fn MessageBoxW(hwnd: *mut std::ffi::c_void, text: *const u16, caption: *const u16, kind: u32) -> i32;
        }
        let wide = |s: &str| s.encode_utf16().chain(Some(0)).collect::<Vec<u16>>();
        // SAFETY: both strings are NUL-terminated UTF-16 that outlive the call.
        unsafe { MessageBoxW(std::ptr::null_mut(), wide(text).as_ptr(), wide("SchoolPilot Exam").as_ptr(), 0x10) };
    }
    #[cfg(not(windows))]
    eprintln!("{text}");
}
