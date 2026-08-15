//! Transport security between the relay and the candidate machines (§8.3).
//!
//! The connection this protects carries live question text across a lab
//! network, and §8.3 is blunt about why the network's own security is not the
//! answer: the school's Wi-Fi "carries staff laptops, phones and whatever else
//! is in the building", and a relay-broadcast hotspot "has a passphrase typed
//! in front of forty candidates". Neither is a private link. Pinning is what
//! makes the network's own security irrelevant to us.
//!
//! **There is no certificate authority here, and that is deliberate.** The
//! relay signs its own certificate once, at first `serve`, and the candidate
//! client is told the exact fingerprint to expect. A CA would mean either
//! buying into public PKI for a machine with no public name, or running our own
//! — both large, and neither better than "this is the one certificate, and
//! nothing else will do".
//!
//! ## What the candidate verifier checks, and what it ignores
//!
//! [`PinnedServerCertVerifier`] accepts exactly one certificate: the one whose
//! SHA-256 matches the fingerprint it was given. It deliberately does **not**
//! check the hostname, the expiry, or a chain to any root, because none of
//! those mean anything for a self-signed certificate on a machine addressed by
//! whatever IP the lab's DHCP handed out this morning. That sounds alarming
//! written down, so state it precisely: this is *stricter* than ordinary web
//! PKI, not weaker. Ordinary verification accepts any of hundreds of CAs for a
//! matching name; this accepts one specific key and nothing else, whatever name
//! it presents.
//!
//! The residual risk is the fingerprint's own delivery. §8.4's pairing step was
//! where a candidate machine was going to learn it; without pairing it is
//! passed to `relay candidate --fingerprint`, read off the invigilator's screen
//! at setup. That is a supervised, one-time, out-of-band channel, which is the
//! same property pairing was providing.

use std::sync::Arc;

use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::pki_types::{CertificateDer, ServerName, UnixTime};
use rustls::{DigitallySignedStruct, Error as TlsError, SignatureScheme};
use sha2::{Digest, Sha256};

use crate::error::{RelayError, Result};

/// Make sure a crypto provider is installed before anything builds a config.
///
/// rustls 0.23 has no implicit default. Called from every entry point that can
/// reach TLS rather than from one of them, because the failure mode otherwise
/// is a panic deep inside a handshake on exam morning.
pub fn install_crypto_provider() {
    // Already-installed is not an error; two callers racing is the normal case.
    let _ = rustls::crypto::ring::default_provider().install_default();
}

/// The relay's own certificate and the fingerprint candidates must pin.
#[derive(Debug, Clone)]
pub struct RelayIdentity {
    pub cert_pem: String,
    pub key_pem: String,
    /// Lowercase hex, colon-separated, of the SHA-256 over the certificate's
    /// DER. The form a person reads off a screen and types into a command.
    pub fingerprint: String,
    /// True if this call generated the certificate rather than loading one.
    ///
    /// Matters because of *when*: the identity is supposed to be created at
    /// installation (`relay init`), so the fingerprint exists in time to
    /// configure the lab machines. A `serve` that finds itself generating one
    /// has been run on a relay nobody installed properly, and the person about
    /// to open a paper needs telling — not because it is unsafe, but because
    /// forty machines are about to need a fingerprint they have never seen.
    pub created: bool,
}

impl RelayIdentity {
    /// Load the relay's certificate, generating one the first time.
    ///
    /// Generated once and kept, not regenerated per run: a fingerprint that
    /// changed on every `serve` would have to be re-read to forty machines
    /// every exam morning, which is exactly the per-machine ritual §3.1
    /// rejected the standalone design over.
    pub fn load_or_create(dir: &std::path::Path) -> Result<Self> {
        let cert_path = dir.join("relay-cert.pem");
        let key_path = dir.join("relay-key.pem");

        if cert_path.exists() && key_path.exists() {
            let cert_pem = std::fs::read_to_string(&cert_path)?;
            let key_pem = std::fs::read_to_string(&key_path)?;
            let fingerprint = fingerprint_of_pem(&cert_pem)?;

            return Ok(Self { cert_pem, key_pem, fingerprint, created: false });
        }

        std::fs::create_dir_all(dir)?;

        // The names are a courtesy, not the check — a pinning client ignores
        // them (see the module note). They are here so that a browser opened
        // against the relay for the status window has something coherent to
        // complain about, and so a future CA-backed deployment has the shape.
        let mut names = vec!["localhost".to_string(), "127.0.0.1".to_string()];
        names.extend(local_addresses());

        let key_pair =
            rcgen::KeyPair::generate().map_err(|e| RelayError::Tls(e.to_string()))?;

        let mut params = rcgen::CertificateParams::new(names)
            .map_err(|e| RelayError::Tls(e.to_string()))?;
        params.distinguished_name = {
            let mut dn = rcgen::DistinguishedName::new();
            dn.push(rcgen::DnType::CommonName, "SchoolPilot Lab Relay");
            dn
        };

        let cert = params
            .self_signed(&key_pair)
            .map_err(|e| RelayError::Tls(e.to_string()))?;

        let cert_pem = cert.pem();
        let key_pem = key_pair.serialize_pem();
        let fingerprint = fingerprint_hex(cert.der());

        std::fs::write(&cert_path, &cert_pem)?;
        std::fs::write(&key_path, &key_pem)?;

        // The private key is the relay's identity for every exam it will ever
        // serve. Not as sensitive as the content key (§8.1), which is why it
        // may live on disk at all, but not world-readable either.
        restrict(&key_path)?;

        Ok(Self { cert_pem, key_pem, fingerprint, created: true })
    }

    /// The fingerprint in the form an invigilator reads aloud: grouped, short
    /// enough to compare by eye against what a candidate machine displays.
    pub fn readable_fingerprint(&self) -> String {
        self.fingerprint
            .split(':')
            .collect::<Vec<_>>()
            .chunks(4)
            .map(|chunk| chunk.join(""))
            .collect::<Vec<_>>()
            .join(" ")
            .to_uppercase()
    }
}

/// SHA-256 over a DER certificate, as lowercase colon-separated hex.
pub fn fingerprint_hex(der: &[u8]) -> String {
    let digest = Sha256::digest(der);

    digest
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect::<Vec<_>>()
        .join(":")
}

/// The fingerprint of the first certificate in a PEM bundle.
pub fn fingerprint_of_pem(pem: &str) -> Result<String> {
    let der = pem_to_der(pem).ok_or_else(|| {
        RelayError::Tls("the relay's certificate file is not a PEM certificate".into())
    })?;

    Ok(fingerprint_hex(&der))
}

/// Decode the first `CERTIFICATE` block of a PEM document.
///
/// Hand-rolled rather than pulling a PEM crate for six lines: the input is a
/// file this program wrote itself, one block, no headers.
fn pem_to_der(pem: &str) -> Option<Vec<u8>> {
    use base64::Engine;

    let start = pem.find("-----BEGIN CERTIFICATE-----")?;
    let rest = &pem[start + "-----BEGIN CERTIFICATE-----".len()..];
    let end = rest.find("-----END CERTIFICATE-----")?;

    let body: String = rest[..end].chars().filter(|c| !c.is_whitespace()).collect();

    base64::engine::general_purpose::STANDARD.decode(body).ok()
}

/// Compare two fingerprints as a person might have typed them.
///
/// Colons, spaces and case are presentation. A candidate machine refusing to
/// start because the invigilator typed the fingerprint in capitals would be a
/// self-inflicted exam-morning outage.
pub fn fingerprints_match(expected: &str, actual: &str) -> bool {
    fn normalise(value: &str) -> String {
        value
            .chars()
            .filter(|c| c.is_ascii_alphanumeric())
            .flat_map(|c| c.to_lowercase())
            .collect()
    }

    let (expected, actual) = (normalise(expected), normalise(actual));

    // Guard against a truncated or empty pin silently matching by prefix.
    !expected.is_empty() && expected == actual
}

// ----------------------------------------------------------------------
// Server side
// ----------------------------------------------------------------------

/// The relay's TLS configuration, for `axum-server`.
pub async fn server_config(
    identity: &RelayIdentity,
) -> Result<axum_server::tls_rustls::RustlsConfig> {
    install_crypto_provider();

    axum_server::tls_rustls::RustlsConfig::from_pem(
        identity.cert_pem.clone().into_bytes(),
        identity.key_pem.clone().into_bytes(),
    )
    .await
    .map_err(|error| RelayError::Tls(error.to_string()))
}

// ----------------------------------------------------------------------
// Client side — the pin
// ----------------------------------------------------------------------

/// Accepts exactly one server certificate: the one matching `fingerprint`.
#[derive(Debug)]
pub struct PinnedServerCertVerifier {
    fingerprint: String,
    provider: Arc<rustls::crypto::CryptoProvider>,
}

impl PinnedServerCertVerifier {
    pub fn new(fingerprint: impl Into<String>) -> Self {
        Self {
            fingerprint: fingerprint.into(),
            provider: Arc::new(rustls::crypto::ring::default_provider()),
        }
    }
}

impl ServerCertVerifier for PinnedServerCertVerifier {
    fn verify_server_cert(
        &self,
        end_entity: &CertificateDer<'_>,
        _intermediates: &[CertificateDer<'_>],
        _server_name: &ServerName<'_>,
        _ocsp_response: &[u8],
        _now: UnixTime,
    ) -> std::result::Result<ServerCertVerified, TlsError> {
        let presented = fingerprint_hex(end_entity.as_ref());

        if fingerprints_match(&self.fingerprint, &presented) {
            return Ok(ServerCertVerified::assertion());
        }

        // Named precisely, because the person who sees this is standing in a
        // lab and needs to know it is not a network fault.
        Err(TlsError::General(format!(
            "the machine answering as the relay presented certificate {presented}, not the \
             expected {}. Do not continue — tell the invigilator.",
            self.fingerprint
        )))
    }

    fn verify_tls12_signature(
        &self,
        message: &[u8],
        cert: &CertificateDer<'_>,
        dss: &DigitallySignedStruct,
    ) -> std::result::Result<HandshakeSignatureValid, TlsError> {
        rustls::crypto::verify_tls12_signature(
            message,
            cert,
            dss,
            &self.provider.signature_verification_algorithms,
        )
    }

    fn verify_tls13_signature(
        &self,
        message: &[u8],
        cert: &CertificateDer<'_>,
        dss: &DigitallySignedStruct,
    ) -> std::result::Result<HandshakeSignatureValid, TlsError> {
        rustls::crypto::verify_tls13_signature(
            message,
            cert,
            dss,
            &self.provider.signature_verification_algorithms,
        )
    }

    fn supported_verify_schemes(&self) -> Vec<SignatureScheme> {
        self.provider.signature_verification_algorithms.supported_schemes()
    }
}

/// An HTTP client that will talk to the pinned relay and to nothing else.
pub fn pinned_client(fingerprint: &str) -> Result<reqwest::Client> {
    install_crypto_provider();

    let config = rustls::ClientConfig::builder()
        .dangerous()
        .with_custom_certificate_verifier(Arc::new(PinnedServerCertVerifier::new(fingerprint)))
        .with_no_client_auth();

    reqwest::Client::builder()
        .use_preconfigured_tls(config)
        // The exam is on a LAN. A request that has not answered in this long is
        // a problem to surface, not to keep waiting on behind a spinner.
        .timeout(std::time::Duration::from_secs(20))
        .build()
        .map_err(|error| RelayError::Tls(error.to_string()))
}

// ----------------------------------------------------------------------
// Odds and ends
// ----------------------------------------------------------------------

/// This machine's LAN addresses, for the certificate's names.
///
/// The UDP trick: connecting a datagram socket sends nothing, it just asks the
/// routing table which local address would be used to reach that destination.
/// It works with no internet, which is the only condition that matters here.
fn local_addresses() -> Vec<String> {
    let mut found = Vec::new();

    for probe in ["10.255.255.255:1", "192.168.255.255:1", "172.31.255.255:1"] {
        if let Ok(socket) = std::net::UdpSocket::bind("0.0.0.0:0") {
            if socket.connect(probe).is_ok() {
                if let Ok(addr) = socket.local_addr() {
                    let ip = addr.ip().to_string();
                    if ip != "0.0.0.0" && !found.contains(&ip) {
                        found.push(ip);
                    }
                }
            }
        }
    }

    found
}

#[cfg(not(windows))]
fn restrict(path: &std::path::Path) -> Result<()> {
    use std::os::unix::fs::PermissionsExt;

    std::fs::set_permissions(path, std::fs::Permissions::from_mode(0o600))?;
    Ok(())
}

#[cfg(windows)]
fn restrict(_path: &std::path::Path) -> Result<()> {
    // Windows inherits the parent directory's ACL, and the relay's home is
    // under the operator's profile. Nothing useful to tighten without dragging
    // in the ACL APIs for a file that is not the sensitive one — the content
    // key is, and it is never written at all (§8.1).
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn an_identity_survives_a_restart_with_the_same_fingerprint() {
        // The property forty machines depend on: pin it once, not every
        // morning. A relay that regenerated its certificate on each `serve`
        // would silently invalidate every candidate machine's pin overnight.
        let dir = tempfile::tempdir().unwrap();

        let first = RelayIdentity::load_or_create(dir.path()).unwrap();
        let second = RelayIdentity::load_or_create(dir.path()).unwrap();

        assert_eq!(first.fingerprint, second.fingerprint);
        assert_eq!(first.cert_pem, second.cert_pem);
        assert!(first.fingerprint.contains(':'));
        assert_eq!(first.fingerprint.split(':').count(), 32, "sha-256 is 32 bytes");
    }

    #[test]
    fn the_fingerprint_is_the_digest_of_the_certificate_on_disk() {
        let dir = tempfile::tempdir().unwrap();
        let identity = RelayIdentity::load_or_create(dir.path()).unwrap();

        let der = pem_to_der(&identity.cert_pem).expect("its own PEM parses");

        assert_eq!(identity.fingerprint, fingerprint_hex(&der));
    }

    #[test]
    fn two_relays_do_not_share_a_fingerprint() {
        let (a, b) = (tempfile::tempdir().unwrap(), tempfile::tempdir().unwrap());

        assert_ne!(
            RelayIdentity::load_or_create(a.path()).unwrap().fingerprint,
            RelayIdentity::load_or_create(b.path()).unwrap().fingerprint,
        );
    }

    #[test]
    fn a_fingerprint_is_compared_the_way_a_person_types_it() {
        let canonical = "ab:cd:ef:01";

        assert!(fingerprints_match(canonical, "ab:cd:ef:01"));
        assert!(fingerprints_match(canonical, "ABCDEF01"));
        assert!(fingerprints_match(canonical, "AB CD EF 01"));
        assert!(fingerprints_match(canonical, "abcd ef01"));
    }

    #[test]
    fn a_truncated_or_empty_pin_never_matches() {
        // The dangerous near-miss: a prefix comparison would make a
        // half-typed fingerprint, or an empty one, accept any relay at all.
        assert!(!fingerprints_match("ab:cd:ef:01", "ab:cd"));
        assert!(!fingerprints_match("ab:cd", "ab:cd:ef:01"));
        assert!(!fingerprints_match("", ""));
        assert!(!fingerprints_match("", "ab:cd:ef:01"));
        assert!(!fingerprints_match("::::", "ab:cd:ef:01"));
    }

    #[test]
    fn the_readable_form_regroups_without_changing_the_value() {
        let dir = tempfile::tempdir().unwrap();
        let identity = RelayIdentity::load_or_create(dir.path()).unwrap();

        assert!(fingerprints_match(
            &identity.fingerprint,
            &identity.readable_fingerprint()
        ));
    }
}
