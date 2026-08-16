//! The backend's side of the conversation (§9.1–§9.3).
//!
//! Every call here happens either before the exam or after it. Nothing in this
//! module runs while candidates are sitting — that is the entire premise of the
//! product, and if a future change makes the exam-time path depend on it, the
//! change is wrong.
//!
//! All routes are under `/api/v1/`. An unversioned call would break installed
//! relays exactly as it would break installed mobile apps.

use serde::{Deserialize, Serialize};
use serde_json::json;
use std::time::Duration;

use crate::bundle::SealedBundle;
use crate::config::Config;
use crate::error::{RelayError, Result};

pub struct Api {
    client: reqwest::Client,
    root: String,
    token: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct MediaAsset {
    pub asset_id: i64,
    pub url: String,
    pub checksum: Option<String>,
    pub byte_size: Option<i64>,
    pub mime_type: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct AnswerUpload {
    pub question_id: i64,
    pub response: serde_json::Value,
    pub client_sequence: i64,
    pub client_timestamp: String,
    pub time_spent_seconds: Option<i64>,
    pub flagged_for_review: Option<bool>,
}

#[derive(Debug, Clone, Serialize)]
pub struct EventUpload {
    pub event_type: String,
    pub occurred_at: String,
    pub metadata: Option<serde_json::Value>,
}

#[derive(Debug, Clone, Serialize)]
pub struct AttemptUpload {
    pub attempt_id: i64,
    pub answers: Vec<AnswerUpload>,
    pub events: Vec<EventUpload>,
    pub submit: bool,
}

#[derive(Debug, Clone, Deserialize)]
pub struct SyncSummary {
    pub attempts: i64,
    pub saved: i64,
    pub ignored: i64,
    pub rejected: i64,
    pub finalised: i64,
    pub events: i64,
}

#[derive(Debug, Clone, Deserialize)]
pub struct AttemptResult {
    pub attempt_id: i64,
    pub saved: i64,
    pub ignored: i64,
    pub events: i64,
    #[serde(default)]
    pub rejected: Vec<String>,
    pub attempt_status: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct SyncResponse {
    pub bundle_id: String,
    pub summary: SyncSummary,
    pub attempts: Vec<AttemptResult>,
}

impl Api {
    pub fn new(config: &Config) -> Result<Self> {
        let client = reqwest::Client::builder()
            // Provisioning a large paper over a phone hotspot is slow but not
            // interactive; a short timeout here just means retrying a 14 MB
            // download from the start.
            .timeout(Duration::from_secs(180))
            .user_agent(concat!("SchoolPilot-Relay/", env!("CARGO_PKG_VERSION")))
            .build()?;

        Ok(Self {
            client,
            root: config.api_root(),
            token: config.token.clone(),
        })
    }

    fn authed(&self, request: reqwest::RequestBuilder) -> Result<reqwest::RequestBuilder> {
        let token = self.token.as_deref().ok_or(RelayError::NotAuthenticated)?;
        Ok(request.bearer_auth(token))
    }

    /// Turn a non-2xx into something an invigilator can read. Laravel returns
    /// `{"error": "..."}` from these controllers and `{"message": "..."}` from
    /// validation, so try both before falling back to the raw body.
    async fn read(response: reqwest::Response) -> Result<String> {
        let status = response.status();
        let body = response.text().await.unwrap_or_default();

        if status.is_success() {
            return Ok(body);
        }

        let message = serde_json::from_str::<serde_json::Value>(&body)
            .ok()
            .and_then(|value| {
                value
                    .get("error")
                    .or_else(|| value.get("message"))
                    .and_then(|m| m.as_str())
                    .map(str::to_string)
            })
            .unwrap_or_else(|| body.chars().take(300).collect());

        Err(RelayError::Server { status: status.as_u16(), message })
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    /// Sign in with the invigilator's normal staff credentials.
    pub async fn login(&self, email: &str, password: &str) -> Result<(String, Option<String>)> {
        let response = self
            .client
            .post(format!("{}/auth/login", self.root))
            .json(&json!({
                "email": email,
                "password": password,
                "device_name": "SchoolPilot Lab Relay",
            }))
            .send()
            .await?;

        let body = Self::read(response).await?;
        let value: serde_json::Value = serde_json::from_str(&body)?;

        let token = value
            .get("token")
            .or_else(|| value.get("access_token"))
            .or_else(|| value.pointer("/data/token"))
            .and_then(|t| t.as_str())
            .ok_or_else(|| {
                RelayError::Protocol("sign-in succeeded but returned no token".into())
            })?
            .to_string();

        let name = value
            .pointer("/user/name")
            .or_else(|| value.pointer("/data/user/name"))
            .and_then(|n| n.as_str())
            .map(str::to_string);

        Ok((token, name))
    }

    // ------------------------------------------------------------------
    // §9.1 Bundle issuance
    // ------------------------------------------------------------------

    pub async fn issue_bundle(
        &self,
        exam_id: i64,
        relay_identity: Option<&str>,
        override_existing: bool,
    ) -> Result<SealedBundle> {
        let request = self
            .client
            .post(format!("{}/cbt/exams/{}/offline-bundle", self.root, exam_id))
            .json(&json!({
                "relay_identity": relay_identity,
                "override_existing": override_existing,
            }));

        let response = self.authed(request)?.send().await?;
        let body = Self::read(response).await?;

        SealedBundle::from_issue_response(&body)
    }

    /// Media assets for an exam, from the existing staff offline-package
    /// manifest. Not encrypted — already reachable to any authenticated
    /// candidate before `opens_at` by design, so sealing it protects nothing.
    pub fn media_assets(manifest: &serde_json::Value) -> Vec<MediaAsset> {
        manifest
            .get("assets")
            .and_then(|a| a.as_array())
            .map(|assets| {
                assets
                    .iter()
                    .filter_map(|asset| serde_json::from_value(asset.clone()).ok())
                    .collect()
            })
            .unwrap_or_default()
    }

    pub async fn download(&self, url: &str) -> Result<Vec<u8>> {
        let request = self.client.get(url);
        let response = self.authed(request)?.send().await?;

        if !response.status().is_success() {
            let status = response.status().as_u16();
            return Err(RelayError::Server {
                status,
                message: format!("could not download {url}"),
            });
        }

        Ok(response.bytes().await?.to_vec())
    }

    // ------------------------------------------------------------------
    // §9.2 Key release
    // ------------------------------------------------------------------

    /// Ten seconds of connectivity on exam morning.
    ///
    /// Refused before `opens_at` by the server, where the clock is ours. The
    /// relay must not soften that into a local time check — that is the whole
    /// reason the gate lives on the server.
    pub async fn release_key(&self, bundle_id: &str) -> Result<String> {
        let request = self
            .client
            .post(format!("{}/cbt/offline-bundle/{}/key", self.root, bundle_id));

        let response = self.authed(request)?.send().await?;
        let status = response.status();
        let body = response.text().await.unwrap_or_default();

        if status == reqwest::StatusCode::FORBIDDEN {
            let message = serde_json::from_str::<serde_json::Value>(&body)
                .ok()
                .and_then(|v| v.get("error").and_then(|e| e.as_str()).map(str::to_string))
                .unwrap_or_else(|| "the key was refused".into());

            return Err(RelayError::Server { status: 403, message });
        }

        if !status.is_success() {
            return Err(RelayError::Server {
                status: status.as_u16(),
                message: body.chars().take(300).collect(),
            });
        }

        let value: serde_json::Value = serde_json::from_str(&body)?;

        value
            .get("key")
            .and_then(|k| k.as_str())
            .map(str::to_string)
            .ok_or_else(|| RelayError::Protocol("key release returned no key".into()))
    }

    // ------------------------------------------------------------------
    // §9.3 Batch sync
    // ------------------------------------------------------------------

    /// Upload a room's worth of answers, events and submissions.
    ///
    /// The server authorises every attempt against the bundle's roster and
    /// runs each through the same `recordAnswers` the online client uses, so
    /// conflict resolution stays in exactly one place (§11). Partial failures
    /// come back per attempt rather than as one 422.
    pub async fn batch_sync(
        &self,
        bundle_id: &str,
        attempts: &[AttemptUpload],
    ) -> Result<SyncResponse> {
        let request = self
            .client
            .post(format!("{}/cbt/offline-sync/batch", self.root))
            .json(&json!({ "bundle_id": bundle_id, "attempts": attempts }));

        let response = self.authed(request)?.send().await?;
        let body = Self::read(response).await?;

        Ok(serde_json::from_str(&body)?)
    }
}

/// Pair a candidate machine with a relay (§8.4).
///
/// Deliberately not a method on [`Api`]: this call goes to the *relay* on the
/// lab network, not to the backend, and it carries no staff token. A candidate
/// machine has no business holding one, and putting this beside the backend
/// calls would eventually let it.
pub async fn pair_device(
    relay_url: &str,
    fingerprint: Option<&str>,
    pairing_code: &str,
    device_name: &str,
) -> Result<String> {
    let relay = relay_url.trim_end_matches('/');

    // The same pin the exam itself will use. Pairing over an unverified
    // connection would hand a device token to whatever answered, so an https
    // relay needs its fingerprint here exactly as it does later.
    let client = match (relay.starts_with("https://"), fingerprint) {
        (true, Some(pin)) => crate::tls::pinned_client(pin)?,
        (true, None) => {
            return Err(RelayError::Tls(
                "pairing with an https relay needs --fingerprint, or this machine would be \
                 trusting whatever answered"
                    .into(),
            ))
        }
        (false, _) => reqwest::Client::builder()
            .timeout(Duration::from_secs(20))
            .build()
            .map_err(|error| RelayError::Tls(error.to_string()))?,
    };

    let response = client
        .post(format!("{relay}/relay/v1/pair"))
        .json(&json!({ "pairing_code": pairing_code, "device_name": device_name }))
        .send()
        .await?;

    let status = response.status();
    let body = response.text().await.unwrap_or_default();

    if !status.is_success() {
        let message = serde_json::from_str::<serde_json::Value>(&body)
            .ok()
            .and_then(|value| value.get("error")?.as_str().map(str::to_string))
            .unwrap_or(body);

        return Err(RelayError::Server { status: status.as_u16(), message });
    }

    let parsed: serde_json::Value = serde_json::from_str(&body)?;

    parsed
        .get("device_token")
        .and_then(|value| value.as_str())
        .map(str::to_string)
        .ok_or_else(|| RelayError::Protocol("the relay returned no device token".into()))
}
