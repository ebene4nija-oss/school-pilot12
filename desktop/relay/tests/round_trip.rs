//! The scripted fake candidate (docs/offline-cbt-client.md §19, step 3).
//!
//! "Prove the round trip with a scripted fake candidate." This drives the real
//! HTTP handlers against a real SQLite store and a paper PHP actually sealed,
//! and finishes by inspecting the batch payload the relay would upload — which
//! is the far end of the round trip and the part most likely to be wrong.
//!
//! What it does not do is talk to a live backend. That belongs in the
//! integration pass of §18 and needs a server; everything up to the request
//! body is provable here, on any machine, in milliseconds.

use axum::body::Body;
use axum::http::{Request, StatusCode};
use http_body_util::BodyExt;
use std::sync::{Arc, Mutex, RwLock};
use tower::ServiceExt;

use schoolpilot_relay::bundle::SealedBundle;
use schoolpilot_relay::server::{router, AppState};
use schoolpilot_relay::store::Store;

const ADMISSION: &str = "SP/2026/0055";
const CODE: &str = "K4M9PQR2";

fn state() -> Arc<AppState> {
    let sealed = SealedBundle::from_issue_response(include_str!("fixtures/sealed-bundle.json"))
        .expect("fixture parses");

    let paper = sealed
        .open(include_str!("fixtures/sealed-bundle.key").trim())
        .expect("fixture opens");

    let store = Store::open_in_memory().expect("store opens");
    store.save_bundle(&sealed).expect("bundle saves");
    store
        .save_roster(&sealed.envelope.bundle_id, &paper.roster)
        .expect("roster saves");

    Arc::new(AppState {
        store: Mutex::new(store),
        bundle_id: sealed.envelope.bundle_id.clone(),
        exam_title: paper.exam.title.clone(),
        paper: RwLock::new(Some(paper)),
    })
}

async fn post(state: &Arc<AppState>, path: &str, body: serde_json::Value) -> (StatusCode, serde_json::Value) {
    let response = router(Arc::clone(state))
        .oneshot(
            Request::builder()
                .method("POST")
                .uri(path)
                .header("content-type", "application/json")
                .body(Body::from(body.to_string()))
                .unwrap(),
        )
        .await
        .unwrap();

    let status = response.status();
    let bytes = response.into_body().collect().await.unwrap().to_bytes();
    let value = serde_json::from_slice(&bytes).unwrap_or(serde_json::Value::Null);

    (status, value)
}

async fn get(state: &Arc<AppState>, path: &str) -> (StatusCode, serde_json::Value) {
    let response = router(Arc::clone(state))
        .oneshot(Request::builder().uri(path).body(Body::empty()).unwrap())
        .await
        .unwrap();

    let status = response.status();
    let bytes = response.into_body().collect().await.unwrap().to_bytes();
    let value = serde_json::from_slice(&bytes).unwrap_or(serde_json::Value::Null);

    (status, value)
}

fn credentials() -> serde_json::Value {
    serde_json::json!({ "admission_number": ADMISSION, "relay_code": CODE })
}

#[tokio::test]
async fn a_candidate_sits_a_paper_end_to_end() {
    let state = state();

    // --- seat -------------------------------------------------------
    let (status, session) = post(&state, "/relay/v1/session", credentials()).await;
    assert_eq!(status, StatusCode::OK, "session: {session}");
    assert_eq!(session["attempt_id"], 9001);
    assert_eq!(session["resumed"], false);
    assert_eq!(session["question_count"], 3);

    // --- read the paper ---------------------------------------------
    let (status, paper) = get(
        &state,
        &format!("/relay/v1/paper?admission_number={}&relay_code={CODE}", urlencode(ADMISSION)),
    )
    .await;
    assert_eq!(status, StatusCode::OK, "paper: {paper}");

    let questions = paper["questions"].as_array().unwrap();
    assert_eq!(questions.len(), 3);
    assert_eq!(
        questions.iter().map(|q| q["question_id"].as_i64().unwrap()).collect::<Vec<_>>(),
        vec![201, 202, 203],
        "the relay serves the roster's order and derives nothing"
    );

    // The comprehension's stimulus travels with its sub-questions, and the
    // candidate can see where they are inside it (§6.2).
    assert_eq!(questions[1]["group"]["group_id"], 101);
    assert_eq!(questions[1]["group"]["position_in_group"], 1);
    assert_eq!(questions[1]["group"]["questions_in_group"], 2);
    assert!(questions[1]["group"]["stimulus"].as_str().unwrap().contains("Harmattan"));

    // Nothing a candidate could grade themselves with.
    let raw = paper.to_string();
    for forbidden in ["correct_answer", "rubric", "explanation"] {
        assert!(!raw.contains(forbidden), "`{forbidden}` was served to a candidate");
    }

    // --- answer -----------------------------------------------------
    let mut request = credentials();
    request["question_id"] = serde_json::json!(201);
    request["response"] = serde_json::json!("A");
    let (status, saved) = post(&state, "/relay/v1/answers", request.clone()).await;
    assert_eq!(status, StatusCode::OK, "answer: {saved}");
    assert_eq!(saved["client_sequence"], 1);

    // Changing an answer supersedes rather than duplicating (§11).
    request["response"] = serde_json::json!("B");
    let (_, changed) = post(&state, "/relay/v1/answers", request).await;
    assert_eq!(changed["client_sequence"], 2);

    // --- an event ---------------------------------------------------
    let mut event = credentials();
    event["event_type"] = serde_json::json!("focus_lost");
    let (status, _) = post(&state, "/relay/v1/events", event).await;
    assert_eq!(status, StatusCode::OK);

    // --- submit -----------------------------------------------------
    let (status, submitted) = post(&state, "/relay/v1/submit", credentials()).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(submitted["submitted"], true);
    assert!(
        !submitted["message"].as_str().unwrap().to_lowercase().contains("score"),
        "an offline paper promises no score in the room (§5.4)"
    );

    // --- what would be uploaded -------------------------------------
    let store = state.store.lock().unwrap();
    let pending = store.pending_uploads(&state.bundle_id).unwrap();

    assert_eq!(pending.len(), 1, "only the candidate who sat should be uploaded");
    let upload = &pending[0];

    assert_eq!(upload.attempt_id, 9001);
    assert!(upload.submit, "a submitted paper must be closed out server-side");
    assert_eq!(upload.answers.len(), 1, "one row per question, not one per keystroke");
    assert_eq!(upload.answers[0].response, serde_json::json!("B"));
    assert_eq!(upload.answers[0].client_sequence, 2);
    assert_eq!(upload.events.len(), 2, "focus_lost, plus the submit marker");
}

#[tokio::test]
async fn a_candidate_who_moves_seats_resumes_rather_than_restarting() {
    // The single biggest practical advantage of the relay model (§5.3), and
    // §19 says to test it first.
    let state = state();

    let (_, first) = post(&state, "/relay/v1/session", credentials()).await;
    assert_eq!(first["resumed"], false);

    let mut answer = credentials();
    answer["question_id"] = serde_json::json!(201);
    answer["response"] = serde_json::json!("C");
    post(&state, "/relay/v1/answers", answer).await;

    // The machine dies. The candidate walks to a spare seat and signs in again.
    let (status, second) = post(&state, "/relay/v1/session", credentials()).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(second["resumed"], true, "the candidate must be told their work survived");

    let store = state.store.lock().unwrap();
    let pending = store.pending_uploads(&state.bundle_id).unwrap();

    assert_eq!(pending[0].answers.len(), 1);
    assert_eq!(pending[0].answers[0].response, serde_json::json!("C"), "the answer survived");
}

#[tokio::test]
async fn a_sealed_paper_serves_nobody() {
    let state = state();
    state.close(); // exam closed, paper dropped from memory (§8.1)

    let (status, body) = post(&state, "/relay/v1/session", credentials()).await;

    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE);
    assert!(body["error"].as_str().unwrap().contains("sealed"));
}

#[tokio::test]
async fn a_wrong_code_gets_a_sentence_an_invigilator_can_act_on() {
    let state = state();

    let (status, body) = post(
        &state,
        "/relay/v1/session",
        serde_json::json!({ "admission_number": ADMISSION, "relay_code": "NOPE" }),
    )
    .await;

    assert_eq!(status, StatusCode::UNAUTHORIZED);
    assert!(
        body["error"].as_str().unwrap().contains("call the invigilator"),
        "the candidate needs to know what to do next, got: {body}"
    );
}

#[tokio::test]
async fn an_answer_to_a_question_not_on_the_paper_is_refused_loudly() {
    // §11: this indicates a client bug. The server would reject it at sync
    // anyway; refusing here names the machine that did it, while it is still
    // in the room.
    let state = state();
    post(&state, "/relay/v1/session", credentials()).await;

    let mut request = credentials();
    request["question_id"] = serde_json::json!(9999);
    request["response"] = serde_json::json!("A");

    let (status, body) = post(&state, "/relay/v1/answers", request).await;

    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert!(body["error"].as_str().unwrap().contains("not on attempt"));
}

#[tokio::test]
async fn an_event_type_the_backend_would_reject_is_caught_at_the_seat() {
    let state = state();
    post(&state, "/relay/v1/session", credentials()).await;

    let mut event = credentials();
    event["event_type"] = serde_json::json!("candidate_looked_shifty");

    let (status, body) = post(&state, "/relay/v1/events", event).await;

    assert_eq!(
        status,
        StatusCode::UNPROCESSABLE_ENTITY,
        "a bad event type must not poison a 400-candidate batch hours later"
    );
    assert!(body["error"].as_str().unwrap().contains("not an event a relay may report"));
}

#[tokio::test]
async fn the_status_window_reports_the_room() {
    let state = state();
    post(&state, "/relay/v1/session", credentials()).await;

    let (status, body) = get(&state, "/relay/v1/status").await;

    assert_eq!(status, StatusCode::OK);
    assert_eq!(body["unlocked"], true);
    assert_eq!(body["candidates"], 2);
    assert_eq!(body["seated"], 1);
    assert_eq!(body["submitted"], 0);
}

#[tokio::test]
async fn the_candidate_client_asks_nothing_of_the_outside_world() {
    // §16: the web app vendors KaTeX rather than using a CDN because an exam
    // hall may have no internet. The same reasoning forbids a stylesheet, a
    // font or a script from anywhere but this binary — there is no network in
    // the room to fetch them over, and a paper that renders as unstyled text
    // because a CDN was unreachable is not a paper anyone can sit.
    let response = router(state())
        .oneshot(Request::builder().uri("/sit").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);

    let bytes = response.into_body().collect().await.unwrap().to_bytes();
    let html = String::from_utf8(bytes.to_vec()).unwrap();

    for offender in ["src=\"http", "href=\"http", "//cdn.", "googleapis", "unpkg", "jsdelivr"] {
        assert!(
            !html.contains(offender),
            "the candidate client reaches for `{offender}`, which will not resolve in a lab"
        );
    }

    // And it must actually be the client, not an empty page that trivially
    // passes the check above.
    assert!(html.contains("/relay/v1/session"));
    assert!(html.contains("/relay/v1/submit"));
}

fn urlencode(value: &str) -> String {
    value
        .chars()
        .map(|c| match c {
            'A'..='Z' | 'a'..='z' | '0'..='9' | '-' | '_' | '.' | '~' => c.to_string(),
            other => format!("%{:02X}", other as u32),
        })
        .collect()
}
