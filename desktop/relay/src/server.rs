//! The relay as seen from a candidate machine, and from the invigilator's
//! status window (§5.3).
//!
//! **Transport security is not finished here.** §8.3 requires relay↔candidate
//! traffic to run over TLS with a self-signed certificate the candidate client
//! pins at pairing time, because this connection carries live question text.
//! Step 3 is the skeleton — plain HTTP on the LAN, proven with a scripted fake
//! candidate — and the server refuses to bind to anything but loopback unless
//! told otherwise, so an unfinished relay cannot quietly end up serving a real
//! room. Pairing and pinning arrive with the candidate client in step 4.

use axum::extract::{Query, State};
use axum::http::StatusCode;
use axum::response::{Html, IntoResponse};
use axum::routing::{get, post};
use axum::{Json, Router};
use serde::{Deserialize, Serialize};
use std::net::SocketAddr;
use std::sync::{Arc, Mutex, RwLock};

use crate::bundle::Paper;
use crate::error::Result;
use crate::paper::{self, CandidatePaper};
use crate::store::Store;

pub struct AppState {
    pub store: Mutex<Store>,
    /// The decrypted paper. Memory only, for the length of the exam (§8.1).
    pub paper: RwLock<Option<Paper>>,
    pub bundle_id: String,
    pub exam_title: String,
}

impl AppState {
    /// Zero the paper from memory. §8.1: on exam close the relay drops the key
    /// and the decrypted paper.
    pub fn close(&self) {
        if let Ok(mut paper) = self.paper.write() {
            *paper = None;
        }
    }
}

pub fn router(state: Arc<AppState>) -> Router {
    Router::new()
        .route("/", get(status_page))
        .route("/sit", get(candidate_app))
        // Vendored, never fetched (§16). Relative to `/sit`, so the stylesheet's
        // own `url(fonts/…)` rules resolve here without rewriting the CSS.
        .route("/assets/katex/katex.min.css", get(crate::assets::katex_css))
        .route("/assets/katex/katex.min.js", get(crate::assets::katex_js))
        .route("/assets/katex/fonts/:name", get(crate::assets::katex_font))
        .route("/relay/v1/status", get(status_json))
        .route("/relay/v1/session", post(session))
        .route("/relay/v1/paper", get(candidate_paper))
        .route("/relay/v1/answers", post(record_answer))
        .route("/relay/v1/events", post(record_event))
        .route("/relay/v1/submit", post(submit))
        .with_state(state)
}

/// Serve the room over TLS (§8.3).
///
/// The certificate is the relay's own and candidates pin it by fingerprint;
/// see [`crate::tls`] for why there is no CA and what that does and does not
/// buy. This is the path a real exam runs on.
pub async fn serve_tls(
    state: Arc<AppState>,
    addr: SocketAddr,
    config: axum_server::tls_rustls::RustlsConfig,
) -> Result<()> {
    let handle = axum_server::Handle::new();
    let shutdown = handle.clone();

    tokio::spawn(async move {
        let _ = tokio::signal::ctrl_c().await;
        tracing::info!("shutting down; the paper is being dropped from memory");
        // Long enough for an in-flight answer to finish landing. A candidate's
        // last save is worth three seconds of patience.
        shutdown.graceful_shutdown(Some(std::time::Duration::from_secs(3)));
    });

    tracing::info!("relay listening on https://{addr}");

    axum_server::bind_rustls(addr, config)
        .handle(handle)
        .serve(router(state).into_make_service())
        .await
        .map_err(crate::error::RelayError::Io)?;

    Ok(())
}

/// Serve without TLS. The demo path, and loopback development.
pub async fn serve(state: Arc<AppState>, addr: SocketAddr) -> Result<()> {
    let listener = tokio::net::TcpListener::bind(addr)
        .await
        .map_err(crate::error::RelayError::Io)?;

    tracing::info!("relay listening on http://{addr}");

    axum::serve(listener, router(state))
        .with_graceful_shutdown(async {
            let _ = tokio::signal::ctrl_c().await;
            tracing::info!("shutting down; the paper is being dropped from memory");
        })
        .await
        .map_err(crate::error::RelayError::Io)?;

    Ok(())
}

// ----------------------------------------------------------------------
// Candidate identity
// ----------------------------------------------------------------------

/// A candidate proves who they are with their admission number and the relay
/// code on their slip. Both come from inside the ciphertext, so neither is
/// verifiable until the paper is unlocked — which is the intended shape: there
/// is nothing to attack before the exam opens.
#[derive(Debug, Deserialize)]
pub struct Credentials {
    pub admission_number: String,
    pub relay_code: String,
}

#[derive(Debug, Serialize)]
struct ApiError {
    error: String,
}

/// A refusal, kept small on purpose so it can travel in a `Result` without
/// making every success path carry an axum `Response`-sized hole.
struct Refusal(StatusCode, String);

impl IntoResponse for Refusal {
    fn into_response(self) -> axum::response::Response {
        (self.0, Json(ApiError { error: self.1 })).into_response()
    }
}

fn fail(status: StatusCode, message: impl Into<String>) -> axum::response::Response {
    Refusal(status, message.into()).into_response()
}

/// Resolve credentials to a roster entry, or the refusal to send back.
fn authenticate(
    state: &AppState,
    credentials: &Credentials,
) -> std::result::Result<crate::bundle::RosterEntry, Refusal> {
    let guard = state.paper.read().map_err(|_| {
        Refusal(StatusCode::INTERNAL_SERVER_ERROR, "relay state is poisoned".into())
    })?;

    let Some(paper) = guard.as_ref() else {
        return Err(Refusal(
            StatusCode::SERVICE_UNAVAILABLE,
            "The paper is still sealed. The invigilator has not unlocked it yet.".into(),
        ));
    };

    paper
        .authenticate(&credentials.admission_number, &credentials.relay_code)
        .cloned()
        .ok_or_else(|| {
            Refusal(
                StatusCode::UNAUTHORIZED,
                "That admission number and code do not match anyone on this paper. Check the slip and call the invigilator.".into(),
            )
        })
}

// ----------------------------------------------------------------------
// Candidate endpoints
// ----------------------------------------------------------------------

#[derive(Debug, Serialize)]
struct SessionResponse {
    attempt_id: i64,
    candidate_name: Option<String>,
    exam_title: String,
    server_deadline_at: Option<String>,
    question_count: usize,
    /// True when this candidate has sat before — a machine died and they moved
    /// seats. The client should say so plainly rather than silently continuing:
    /// a candidate who thinks they lost their work needs to be told they did
    /// not (§5.3).
    resumed: bool,
}

async fn session(
    State(state): State<Arc<AppState>>,
    Json(credentials): Json<Credentials>,
) -> axum::response::Response {
    let entry = match authenticate(&state, &credentials) {
        Ok(entry) => entry,
        Err(refusal) => return refusal.into_response(),
    };

    // The deadline is the one the bundle carries, never one computed here (§15).
    if paper::deadline_passed(&entry, chrono::Utc::now()) == Some(true) {
        return fail(StatusCode::GONE, "This paper's time has passed.");
    }

    let (resumed, question_count) = {
        let store = state.store.lock().expect("store lock");
        let resumed = match store.seat(entry.attempt_id) {
            Ok(resumed) => resumed,
            Err(error) => return fail(StatusCode::INTERNAL_SERVER_ERROR, error.to_string()),
        };

        if resumed {
            // §12: a resumed attempt is evidence for whoever reviews the room
            // later. Recorded, never acted on automatically.
            let _ = store.record_event(entry.attempt_id, "resumed", None);
        }

        (resumed, entry.question_order.len())
    };

    Json(SessionResponse {
        attempt_id: entry.attempt_id,
        candidate_name: entry.candidate_name.clone(),
        exam_title: state.exam_title.clone(),
        server_deadline_at: entry.server_deadline_at.clone(),
        question_count,
        resumed,
    })
    .into_response()
}

async fn candidate_paper(
    State(state): State<Arc<AppState>>,
    Query(credentials): Query<Credentials>,
) -> axum::response::Response {
    let entry = match authenticate(&state, &credentials) {
        Ok(entry) => entry,
        Err(refusal) => return refusal.into_response(),
    };

    let guard = state.paper.read().expect("paper lock");
    let Some(paper) = guard.as_ref() else {
        return fail(StatusCode::SERVICE_UNAVAILABLE, "The paper is sealed.");
    };

    let built: CandidatePaper = paper::for_attempt(paper, &entry);

    Json(built).into_response()
}

#[derive(Debug, Deserialize)]
struct AnswerRequest {
    #[serde(flatten)]
    credentials: Credentials,
    question_id: i64,
    response: serde_json::Value,
    time_spent_seconds: Option<i64>,
    #[serde(default)]
    flagged_for_review: bool,
}

async fn record_answer(
    State(state): State<Arc<AppState>>,
    Json(request): Json<AnswerRequest>,
) -> axum::response::Response {
    let entry = match authenticate(&state, &request.credentials) {
        Ok(entry) => entry,
        Err(refusal) => return refusal.into_response(),
    };

    // An answer to a question outside this attempt's order is a client bug, and
    // §11 says to surface it loudly rather than swallow it. The server would
    // reject it at sync anyway; catching it here names the machine that did it.
    if !entry.question_order.contains(&request.question_id) {
        return fail(
            StatusCode::UNPROCESSABLE_ENTITY,
            format!(
                "Question {} is not on attempt {}'s paper.",
                request.question_id, entry.attempt_id
            ),
        );
    }

    let store = state.store.lock().expect("store lock");

    match store.record_answer(
        entry.attempt_id,
        request.question_id,
        &request.response,
        request.time_spent_seconds,
        request.flagged_for_review,
    ) {
        Ok(sequence) => Json(serde_json::json!({ "saved": true, "client_sequence": sequence }))
            .into_response(),
        Err(error) => fail(StatusCode::INTERNAL_SERVER_ERROR, error.to_string()),
    }
}

#[derive(Debug, Deserialize)]
struct EventRequest {
    #[serde(flatten)]
    credentials: Credentials,
    event_type: String,
    metadata: Option<serde_json::Value>,
}

/// Event types the backend will accept from a relay
/// (`CbtAttemptEvent::RELAY_REPORTABLE_TYPES`).
///
/// Checked here so a bad event type is refused at the seat rather than
/// poisoning a 400-candidate batch at sync time, hours later, when the room has
/// gone home.
const RELAY_REPORTABLE: &[&str] = &[
    "focus_lost",
    "focus_regained",
    "fullscreen_exit",
    "paste",
    "copy",
    "navigation_blocked",
    "network_lost",
    "network_restored",
    "client_crashed",
    "seat_changed",
    "relay_restarted",
    "clock_skew_detected",
    "paper_section_reached",
    "resumed",
];

async fn record_event(
    State(state): State<Arc<AppState>>,
    Json(request): Json<EventRequest>,
) -> axum::response::Response {
    let entry = match authenticate(&state, &request.credentials) {
        Ok(entry) => entry,
        Err(refusal) => return refusal.into_response(),
    };

    if !RELAY_REPORTABLE.contains(&request.event_type.as_str()) {
        return fail(
            StatusCode::UNPROCESSABLE_ENTITY,
            format!("`{}` is not an event a relay may report.", request.event_type),
        );
    }

    let store = state.store.lock().expect("store lock");

    match store.record_event(entry.attempt_id, &request.event_type, request.metadata.as_ref()) {
        Ok(()) => Json(serde_json::json!({ "recorded": true })).into_response(),
        Err(error) => fail(StatusCode::INTERNAL_SERVER_ERROR, error.to_string()),
    }
}

async fn submit(
    State(state): State<Arc<AppState>>,
    Json(credentials): Json<Credentials>,
) -> axum::response::Response {
    let entry = match authenticate(&state, &credentials) {
        Ok(entry) => entry,
        Err(refusal) => return refusal.into_response(),
    };

    let store = state.store.lock().expect("store lock");

    if let Err(error) = store.mark_submitted(entry.attempt_id) {
        return fail(StatusCode::INTERNAL_SERVER_ERROR, error.to_string());
    }

    let _ = store.record_event(entry.attempt_id, "paper_section_reached", None);

    // §5.4: no score in the room. Said here so a candidate client cannot invent
    // one, and so the message a candidate sees is the true one.
    Json(serde_json::json!({
        "submitted": true,
        "message": "Your paper has been received by the relay. Results are released by the school after the exam.",
    }))
    .into_response()
}

// ----------------------------------------------------------------------
// Status
// ----------------------------------------------------------------------

#[derive(Debug, Serialize)]
struct StatusResponse {
    bundle_id: String,
    exam_title: String,
    unlocked: bool,
    candidates: i64,
    seated: i64,
    submitted: i64,
    answers: i64,
    unsynced_answers: i64,
    events: i64,
    unsynced_events: i64,
}

fn snapshot(state: &AppState) -> StatusResponse {
    let unlocked = state.paper.read().map(|p| p.is_some()).unwrap_or(false);
    let counts = state
        .store
        .lock()
        .expect("store lock")
        .counts(&state.bundle_id)
        .unwrap_or_default();

    StatusResponse {
        bundle_id: state.bundle_id.clone(),
        exam_title: state.exam_title.clone(),
        unlocked,
        candidates: counts.candidates,
        seated: counts.seated,
        submitted: counts.submitted,
        answers: counts.answers,
        unsynced_answers: counts.unsynced_answers,
        events: counts.events,
        unsynced_events: counts.unsynced_events,
    }
}

async fn status_json(State(state): State<Arc<AppState>>) -> Json<StatusResponse> {
    Json(snapshot(&state))
}

/// The candidate client. Static, self-contained, no request to any other host —
/// there is no network in the room to make one on.
async fn candidate_app() -> Html<&'static str> {
    Html(crate::ui::CANDIDATE_APP)
}

/// The invigilator's one screen. §19 asks for no more than a status window at
/// this stage, and there is a reason to keep it that way: everything on it is a
/// number an anxious exam officer needs, and nothing on it is a control that
/// could be pressed by mistake during a paper.
async fn status_page(State(state): State<Arc<AppState>>) -> Html<String> {
    let status = snapshot(&state);
    let lock = if status.unlocked { "Unlocked" } else { "Sealed" };

    Html(format!(
        r#"<!doctype html>
<meta charset="utf-8">
<meta http-equiv="refresh" content="5">
<title>SchoolPilot Relay — {title}</title>
<style>
  body {{ font: 16px/1.5 system-ui, sans-serif; margin: 2rem auto; max-width: 40rem; }}
  h1 {{ font-size: 1.25rem; margin-bottom: 0; }}
  p.sub {{ color: #666; margin-top: .25rem; }}
  dl {{ display: grid; grid-template-columns: auto 1fr; gap: .4rem 1.5rem; }}
  dt {{ color: #444; }}
  dd {{ margin: 0; font-variant-numeric: tabular-nums; font-weight: 600; }}
  .state {{ display: inline-block; padding: .1rem .5rem; border-radius: .3rem;
            background: {badge}; color: #fff; font-size: .8rem; }}
</style>
<h1>{title}</h1>
<p class="sub">Bundle {bundle} &middot; <span class="state">{lock}</span></p>
<p class="sub">Candidates sit the paper at <code>/sit</code> on this machine's address.</p>
<dl>
  <dt>Candidates on roster</dt><dd>{candidates}</dd>
  <dt>Seated</dt><dd>{seated}</dd>
  <dt>Submitted</dt><dd>{submitted}</dd>
  <dt>Answers held</dt><dd>{answers}</dd>
  <dt>Awaiting sync</dt><dd>{unsynced}</dd>
  <dt>Integrity events</dt><dd>{events}</dd>
</dl>
"#,
        title = html_escape(&status.exam_title),
        bundle = html_escape(&status.bundle_id),
        lock = lock,
        badge = if status.unlocked { "#1a7f37" } else { "#6e7781" },
        candidates = status.candidates,
        seated = status.seated,
        submitted = status.submitted,
        answers = status.answers,
        unsynced = status.unsynced_answers,
        events = status.events,
    ))
}

/// An exam title is authored by a teacher and rendered into this page. It is
/// not attacker-controlled in any interesting sense, but it is user-controlled,
/// and interpolating it raw would be a bug waiting for the first apostrophe.
fn html_escape(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
}

