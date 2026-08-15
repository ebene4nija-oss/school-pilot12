//! Opening a bundle PHP actually sealed.
//!
//! The fixture is produced by `tests/fixtures/make-sealed-bundle.php`, which
//! mirrors `CbtOfflineBundleService::seal()` — gzip, AES-256-GCM, and the
//! plaintext header bound in as additional authenticated data. Sealing in Rust
//! and opening in Rust would prove only that the relay agrees with itself; the
//! interop is the whole risk, so the ciphertext under test comes from PHP.

use schoolpilot_relay::bundle::SealedBundle;

fn response_body() -> String {
    include_str!("fixtures/sealed-bundle.json").to_string()
}

fn key() -> String {
    include_str!("fixtures/sealed-bundle.key").trim().to_string()
}

fn sealed() -> SealedBundle {
    SealedBundle::from_issue_response(&response_body()).expect("fixture should parse")
}

#[test]
fn opens_a_bundle_sealed_by_php() {
    let paper = sealed().open(&key()).expect("PHP-sealed bundle should open");

    assert_eq!(paper.format_version, 1);
    assert_eq!(paper.exam.id, 42);
    assert_eq!(paper.exam.duration_minutes, 45);
    assert_eq!(paper.questions.len(), 3);
    assert_eq!(paper.roster.len(), 2);

    // A quotation mark and a slash in a teacher-authored title, through gzip,
    // GCM and two JSON encoders.
    assert_eq!(paper.exam.title, r#"JSS2 Geography — "Weather & Climate" / Term 2"#);
}

#[test]
fn the_paper_carries_no_marking_scheme() {
    // §9.1 calls this the single most valuable test in the document. It is
    // asserted on the PHP side against a real bundle; asserted here it also
    // catches a relay that starts *inventing* somewhere to put an answer.
    let raw = serde_json::to_string(&sealed().open(&key()).unwrap()).unwrap();

    for forbidden in ["correct_answer", "rubric", "explanation"] {
        assert!(
            !raw.contains(forbidden),
            "`{forbidden}` reached a machine in the exam room"
        );
    }
}

#[test]
fn a_pretty_printed_response_still_opens() {
    // The server computes its AAD from compact JSON however it frames the
    // response, so a proxy or a debug setting that pretty-prints the body must
    // not brick a paper on exam morning. This is the fallback path in
    // `SealedBundle::open`, and it is here because the failure it prevents
    // would happen in a room with forty candidates in it.
    let value: serde_json::Value = serde_json::from_str(&response_body()).unwrap();
    let pretty = serde_json::to_string_pretty(&value).unwrap();

    let paper = SealedBundle::from_issue_response(&pretty)
        .expect("pretty-printed response should still parse")
        .open(&key())
        .expect("pretty-printed response should still open");

    assert_eq!(paper.exam.id, 42);
}

#[test]
fn the_wrong_key_is_refused_rather_than_producing_garbage() {
    let wrong = base64::Engine::encode(
        &base64::engine::general_purpose::STANDARD,
        [0x99u8; 32],
    );

    let error = sealed().open(&wrong).expect_err("a wrong key must not open the paper");

    assert!(
        error.to_string().contains("failed authentication"),
        "the message an invigilator reads should name the two possible causes, got: {error}"
    );
}

#[test]
fn a_tampered_header_is_refused() {
    // The header is bound in as AAD precisely so nobody can retarget a bundle
    // at a different exam while leaving the ciphertext intact.
    let tampered = response_body().replace(r#""exam_id":42"#, r#""exam_id":43"#);

    let error = SealedBundle::from_issue_response(&tampered)
        .expect("tampered response still parses")
        .open(&key())
        .expect_err("a retargeted bundle must not open");

    assert!(matches!(
        error,
        schoolpilot_relay::error::RelayError::Authentication
    ));
}

#[test]
fn a_candidate_is_matched_by_admission_number_and_relay_code() {
    let paper = sealed().open(&key()).unwrap();

    let entry = paper
        .authenticate("SP/2026/0055", "k4m9pqr2")
        .expect("codes are case-insensitive — an invigilator reads them aloud");

    assert_eq!(entry.attempt_id, 9001);
    assert_eq!(entry.question_order, vec![201, 202, 203]);

    // One candidate's code must not open another candidate's paper.
    assert!(paper.authenticate("SP/2026/0056", "K4M9PQR2").is_none());
    assert!(paper.authenticate("SP/2026/0055", "WRONGCODE").is_none());
}

#[test]
fn the_relay_refuses_a_format_it_does_not_understand() {
    // §17: refuse at provision time and say so, rather than failing obscurely
    // at unlock on exam morning.
    let future = response_body().replace(r#""format_version":1"#, r#""format_version":2"#);

    let error = SealedBundle::from_issue_response(&future)
        .expect_err("a newer bundle format must be refused");

    assert!(
        error.to_string().contains("Update the relay"),
        "the message should tell a school what to do, got: {error}"
    );
}
