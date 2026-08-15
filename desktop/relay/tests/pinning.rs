//! Does the pin actually pin? (docs/offline-cbt-client.md §8.3)
//!
//! Everything else about transport security is arrangement — files on disk,
//! fingerprints printed on a screen, a flag on a command line. This is the
//! test that says the arrangement works: a real TLS handshake, against a real
//! server holding the relay's real certificate, refused when the certificate
//! is anyone else's.
//!
//! It is the transport equivalent of `unseal.rs` asserting that no marking
//! scheme reached the room. A pin that is never exercised against an impostor
//! is a comment, not a control.

use std::net::{Ipv4Addr, SocketAddr, TcpListener};

use axum::routing::get;
use axum::Router;
use schoolpilot_relay::tls::{pinned_client, RelayIdentity};

/// Start a TLS server holding `identity`, and return the address it is on.
///
/// Binds port 0 and reads back what the OS gave us, so the suite can run
/// anywhere and in parallel without a hard-coded port.
async fn serve_with(identity: &RelayIdentity) -> SocketAddr {
    let listener = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).expect("bind an ephemeral port");
    let addr = listener.local_addr().expect("read back the port");

    let config = schoolpilot_relay::tls::server_config(identity)
        .await
        .expect("the relay's own certificate loads into a server config");

    let app = Router::new().route("/relay/v1/status", get(|| async { "the paper is here" }));

    tokio::spawn(async move {
        let _ = axum_server::from_tcp_rustls(listener, config)
            .serve(app.into_make_service())
            .await;
    });

    // Let the listener come up before the first handshake.
    tokio::time::sleep(std::time::Duration::from_millis(150)).await;

    addr
}

fn relay(dir: &std::path::Path) -> RelayIdentity {
    RelayIdentity::load_or_create(dir).expect("a relay can always make its own certificate")
}

#[tokio::test]
async fn a_candidate_reaches_the_relay_it_pinned() {
    let home = tempfile::tempdir().unwrap();
    let identity = relay(home.path());
    let addr = serve_with(&identity).await;

    let client = pinned_client(&identity.fingerprint).expect("build a pinned client");

    let response = client
        .get(format!("https://{addr}/relay/v1/status"))
        .send()
        .await
        .expect("the pinned relay is reachable");

    assert!(response.status().is_success());
    assert_eq!(response.text().await.unwrap(), "the paper is here");
}

#[tokio::test]
async fn a_machine_pretending_to_be_the_relay_is_refused() {
    // The attack §8.3 exists for: something else on the lab's Wi-Fi answering
    // on the relay's address. It holds a perfectly valid, perfectly self-signed
    // certificate — it is simply not *the* certificate.
    let real = tempfile::tempdir().unwrap();
    let impostor = tempfile::tempdir().unwrap();

    let expected = relay(real.path());
    let presented = relay(impostor.path());

    assert_ne!(expected.fingerprint, presented.fingerprint);

    let addr = serve_with(&presented).await;
    let client = pinned_client(&expected.fingerprint).expect("build a pinned client");

    let outcome = client.get(format!("https://{addr}/relay/v1/status")).send().await;

    assert!(
        outcome.is_err(),
        "a client pinned to one relay completed a request to another"
    );
}

#[tokio::test]
async fn the_hostname_is_not_what_is_being_checked() {
    // Worth pinning down deliberately, because it looks like a bug otherwise.
    // The relay is reached at whatever address DHCP handed it this morning, so
    // hostname verification would fail constantly and mean nothing. The
    // fingerprint is the whole check — the certificate here carries no name
    // matching `127.0.0.1` by any CA's reckoning, and it is accepted because
    // it is the right key.
    let home = tempfile::tempdir().unwrap();
    let identity = relay(home.path());
    let addr = serve_with(&identity).await;

    let client = pinned_client(&identity.fingerprint).unwrap();

    assert!(client
        .get(format!("https://{addr}/relay/v1/status"))
        .send()
        .await
        .is_ok());
}

#[tokio::test]
async fn a_fingerprint_typed_by_a_person_still_works() {
    // The invigilator reads this off a screen and types it into forty
    // machines. Spacing and case must not decide whether an exam starts.
    let home = tempfile::tempdir().unwrap();
    let identity = relay(home.path());
    let addr = serve_with(&identity).await;

    for typed in [
        identity.fingerprint.clone(),
        identity.readable_fingerprint(),
        identity.fingerprint.replace(':', ""),
        identity.fingerprint.to_uppercase(),
    ] {
        let client = pinned_client(&typed).expect("build a pinned client");

        assert!(
            client
                .get(format!("https://{addr}/relay/v1/status"))
                .send()
                .await
                .is_ok(),
            "`{typed}` was not accepted as the relay's fingerprint"
        );
    }
}

#[tokio::test]
async fn a_half_typed_fingerprint_does_not_open_the_door() {
    // The failure that would quietly undo the whole control: a truncated pin
    // matching by prefix, so a mistyped command trusts anything.
    let home = tempfile::tempdir().unwrap();
    let identity = relay(home.path());
    let addr = serve_with(&identity).await;

    let half = &identity.fingerprint[..identity.fingerprint.len() / 2];
    let client = pinned_client(half).expect("build a pinned client");

    assert!(
        client
            .get(format!("https://{addr}/relay/v1/status"))
            .send()
            .await
            .is_err(),
        "a truncated fingerprint was accepted"
    );
}
