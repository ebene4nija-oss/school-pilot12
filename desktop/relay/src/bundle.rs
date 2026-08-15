//! The encrypted paper: envelope, unsealing, and what is inside it
//! (docs/offline-cbt-client.md §8.1, §9.1).
//!
//! The ciphertext sits on the relay's disk from provision until the exam is
//! purged. The key does not: it arrives on exam morning through a separate
//! audited call and is held in memory only, never written here.

use aes_gcm::aead::{Aead, KeyInit, Payload};
use aes_gcm::{Aes256Gcm, Nonce};
use base64::Engine as _;
use flate2::read::GzDecoder;
use serde::{Deserialize, Serialize};
use std::io::Read;

use crate::error::{RelayError, Result};

/// The wire format this build understands.
///
/// §17: a client that meets a newer bundle must refuse it at provision time and
/// say so plainly, rather than failing obscurely at unlock on exam morning.
pub const SUPPORTED_FORMAT_VERSION: i64 = 1;

// ----------------------------------------------------------------------
// Envelope
// ----------------------------------------------------------------------

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Header {
    pub bundle_id: String,
    pub exam_id: i64,
    pub school_id: i64,
    pub format_version: i64,
    pub question_count: i64,
    pub attempt_count: i64,
    pub opens_at: Option<String>,
    pub closes_at: Option<String>,
    pub built_at: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Cipher {
    pub algorithm: String,
    pub compression: String,
    pub iv: String,
    pub tag: String,
    pub ciphertext: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Envelope {
    pub bundle_id: String,
    pub key_id: String,
    pub format_version: i64,
    pub built_at: String,
    pub header: Header,
    pub cipher: Cipher,
}

/// An envelope plus the exact header bytes the server signed over.
///
/// **Why the raw bytes are kept.** `CbtOfflineBundleService::aad()` is
/// `json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`, and
/// GCM authenticates that byte string, not the header's meaning. Re-serialising
/// a parsed header here would work right up until some school's exam title
/// contains a character the two serialisers escape differently — and then a
/// bundle that provisioned fine would fail to open on exam morning, in a room
/// with forty candidates in it and no way to diagnose it.
///
/// So the relay never re-serialises. It slices the `"header":{...}` substring
/// straight out of the response body and stores those bytes verbatim.
#[derive(Debug, Clone)]
pub struct SealedBundle {
    pub envelope: Envelope,
    /// Byte-exact `header` JSON as the server sent it. This is the GCM AAD.
    pub aad: String,
    pub media_manifest: serde_json::Value,
}

impl SealedBundle {
    /// Parse a `POST /cbt/exams/{id}/offline-bundle` response body.
    pub fn from_issue_response(body: &str) -> Result<Self> {
        let root: serde_json::Value = serde_json::from_str(body)?;

        let bundle = root
            .get("bundle")
            .ok_or_else(|| RelayError::Protocol("response carried no `bundle`".into()))?;

        let envelope: Envelope = serde_json::from_value(bundle.clone())?;

        if envelope.format_version != SUPPORTED_FORMAT_VERSION {
            return Err(RelayError::UnsupportedFormat {
                found: envelope.format_version,
                supported: SUPPORTED_FORMAT_VERSION,
            });
        }

        if envelope.cipher.algorithm != "aes-256-gcm" {
            return Err(RelayError::Protocol(format!(
                "bundle is sealed with {}, which this relay cannot open",
                envelope.cipher.algorithm
            )));
        }

        let aad = extract_raw_object(body, "header").ok_or_else(|| {
            RelayError::Protocol("could not locate the raw `header` object in the response".into())
        })?;

        Ok(Self {
            envelope,
            aad,
            media_manifest: root.get("media_manifest").cloned().unwrap_or(serde_json::Value::Null),
        })
    }

    /// Decrypt and inflate. Consumes nothing — a relay that restarts mid-exam
    /// re-opens the same stored ciphertext with the key it fetches again.
    ///
    /// Two candidate AADs are tried, and the reason is worth stating because it
    /// looks like belt-and-braces and is not:
    ///
    /// 1. **The raw slice**, which is right whenever the response is compact —
    ///    the normal case, since that is what Laravel puts on the wire. It
    ///    survives any escaping difference between the two JSON encoders,
    ///    because it never re-encodes anything.
    /// 2. **A compact re-serialisation** of the parsed header, which is right
    ///    if the response was pretty-printed somewhere between the two — a
    ///    proxy, a debug setting — since the server computed its AAD from
    ///    compact JSON regardless of how it framed the response.
    ///
    /// Each covers the other's failure mode. Neither on its own covers both,
    /// and the cost of getting this wrong is a bundle that provisioned cleanly
    /// and then will not open on exam morning.
    pub fn open(&self, base64_key: &str) -> Result<Paper> {
        match self.open_with(base64_key, self.aad.as_bytes()) {
            Ok(paper) => Ok(paper),
            Err(RelayError::Authentication) => {
                let canonical = serde_json::to_string(&self.envelope.header)?;
                self.open_with(base64_key, canonical.as_bytes())
            }
            Err(other) => Err(other),
        }
    }

    fn open_with(&self, base64_key: &str, aad: &[u8]) -> Result<Paper> {
        let b64 = base64::engine::general_purpose::STANDARD;

        let key = b64
            .decode(base64_key.trim())
            .map_err(|_| RelayError::Protocol("content key is not valid base64".into()))?;

        if key.len() != 32 {
            return Err(RelayError::Protocol(format!(
                "content key is {} bytes, expected 32",
                key.len()
            )));
        }

        let iv = b64.decode(&self.envelope.cipher.iv)?;
        let tag = b64.decode(&self.envelope.cipher.tag)?;
        let ciphertext = b64.decode(&self.envelope.cipher.ciphertext)?;

        if iv.len() != 12 {
            return Err(RelayError::Protocol(format!(
                "initialisation vector is {} bytes, expected 12",
                iv.len()
            )));
        }

        // PHP hands back ciphertext and tag separately; the RustCrypto AEAD
        // expects them concatenated.
        let mut sealed = ciphertext;
        sealed.extend_from_slice(&tag);

        let cipher = Aes256Gcm::new_from_slice(&key)
            .map_err(|_| RelayError::Protocol("could not build the cipher from this key".into()))?;

        let plaintext = cipher
            .decrypt(Nonce::from_slice(&iv), Payload { msg: &sealed, aad })
            // GCM cannot tell us which of the two it was, and saying so honestly
            // is more use to an invigilator than guessing.
            .map_err(|_| RelayError::Authentication)?;

        let json = match self.envelope.cipher.compression.as_str() {
            "gzip" => {
                let mut out = String::new();
                GzDecoder::new(&plaintext[..]).read_to_string(&mut out)?;
                out
            }
            "none" => String::from_utf8(plaintext)
                .map_err(|_| RelayError::Protocol("bundle payload is not valid UTF-8".into()))?,
            other => {
                return Err(RelayError::Protocol(format!(
                    "bundle is compressed with {other}, which this relay cannot read"
                )))
            }
        };

        Ok(serde_json::from_str(&json)?)
    }
}

// ----------------------------------------------------------------------
// Payload
// ----------------------------------------------------------------------

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Paper {
    pub format_version: i64,
    pub exam: ExamSettings,
    #[serde(default)]
    pub groups: Vec<QuestionGroup>,
    pub questions: Vec<Question>,
    pub roster: Vec<RosterEntry>,
}

impl Paper {
    pub fn question(&self, question_id: i64) -> Option<&Question> {
        self.questions.iter().find(|q| q.question_id == question_id)
    }

    pub fn group(&self, group_id: i64) -> Option<&QuestionGroup> {
        self.groups.iter().find(|g| g.group_id == group_id)
    }

    /// A candidate claims their paper with an admission number and the relay
    /// code printed on their slip. The code exists only inside the ciphertext —
    /// the server keeps no copy, because the server is never asked to verify it.
    pub fn authenticate(&self, admission_number: &str, relay_code: &str) -> Option<&RosterEntry> {
        let code = relay_code.trim().to_ascii_uppercase();
        let admission = admission_number.trim();

        self.roster.iter().find(|entry| {
            entry.relay_code.as_deref().map(str::to_ascii_uppercase) == Some(code.clone())
                && entry.admission_number.as_deref() == Some(admission)
        })
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ExamSettings {
    pub id: i64,
    pub title: String,
    pub instructions: Option<String>,
    #[serde(default = "plain")]
    pub content_format: String,
    pub duration_minutes: i64,
    pub opens_at: Option<String>,
    pub closes_at: Option<String>,
    #[serde(default)]
    pub shuffle_questions: bool,
    #[serde(default)]
    pub shuffle_options: bool,
    #[serde(default)]
    pub shuffle_within_group: bool,
    pub questions_per_attempt: Option<i64>,
    #[serde(default)]
    pub max_attempts: i64,
    #[serde(default)]
    pub negative_marking: bool,
    #[serde(default)]
    pub pass_mark: f64,
    #[serde(default)]
    pub total_marks: f64,
    #[serde(default)]
    pub integrity_settings: Option<serde_json::Value>,
    /// Always false for an offline paper (§5.4). Carried explicitly so the
    /// client does not have to infer it.
    #[serde(default)]
    pub show_results_immediately: bool,
}

fn plain() -> String {
    "plain".to_string()
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct QuestionGroup {
    pub group_id: i64,
    pub title: Option<String>,
    pub stimulus: Option<String>,
    #[serde(default = "plain")]
    pub content_format: String,
    pub instructions: Option<String>,
    #[serde(default)]
    pub media: Vec<MediaReference>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Question {
    pub question_id: i64,
    pub question_type: String,
    #[serde(default = "plain")]
    pub content_format: String,
    pub question: String,
    pub topic: Option<String>,
    pub section: Option<String>,
    #[serde(default)]
    pub marks: f64,
    #[serde(default)]
    pub negative_marks: f64,
    pub group_id: Option<i64>,
    pub group_sequence: Option<i64>,
    #[serde(default)]
    pub options: Vec<QuestionOption>,
    #[serde(default)]
    pub media: Vec<MediaReference>,
    #[serde(default)]
    pub interaction: serde_json::Value,
    #[serde(default)]
    pub answer_mode: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct QuestionOption {
    pub key: String,
    pub text: String,
    pub image_asset_id: Option<i64>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct MediaReference {
    pub asset_id: i64,
    #[serde(default)]
    pub role: String,
    #[serde(default)]
    pub position: i64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RosterEntry {
    pub attempt_id: i64,
    pub student_id: i64,
    pub candidate_name: Option<String>,
    pub admission_number: Option<String>,
    pub attempt_number: i64,
    pub seed: i64,
    pub question_order: Vec<i64>,
    pub server_deadline_at: Option<String>,
    pub relay_code: Option<String>,
}

// ----------------------------------------------------------------------
// Raw JSON slicing
// ----------------------------------------------------------------------

/// Slice out the raw text of a top-level-ish JSON object value by key.
///
/// Deliberately not a parser: the whole point is to return the server's own
/// bytes untouched, escapes and all. It scans for `"key"` used as a key (that
/// is, followed by a colon and then `{`) and brace-matches from there while
/// respecting string literals and their escapes.
fn extract_raw_object(body: &str, key: &str) -> Option<String> {
    let needle = format!("\"{key}\"");
    let bytes = body.as_bytes();
    let mut from = 0usize;

    while let Some(found) = body[from..].find(&needle) {
        let key_start = from + found;
        let mut cursor = key_start + needle.len();

        // Skip whitespace, then require a colon, then whitespace, then `{`.
        while cursor < bytes.len() && (bytes[cursor] as char).is_ascii_whitespace() {
            cursor += 1;
        }
        if cursor >= bytes.len() || bytes[cursor] != b':' {
            from = key_start + needle.len();
            continue;
        }
        cursor += 1;
        while cursor < bytes.len() && (bytes[cursor] as char).is_ascii_whitespace() {
            cursor += 1;
        }
        if cursor >= bytes.len() || bytes[cursor] != b'{' {
            from = key_start + needle.len();
            continue;
        }

        let start = cursor;
        let mut depth = 0i32;
        let mut in_string = false;
        let mut escaped = false;

        while cursor < bytes.len() {
            let byte = bytes[cursor];

            if in_string {
                if escaped {
                    escaped = false;
                } else if byte == b'\\' {
                    escaped = true;
                } else if byte == b'"' {
                    in_string = false;
                }
            } else {
                match byte {
                    b'"' => in_string = true,
                    b'{' => depth += 1,
                    b'}' => {
                        depth -= 1;
                        if depth == 0 {
                            return Some(body[start..=cursor].to_string());
                        }
                    }
                    _ => {}
                }
            }

            cursor += 1;
        }

        from = key_start + needle.len();
    }

    None
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn slices_the_header_verbatim() {
        let body = r#"{"message":"ok","bundle":{"header":{"bundle_id":"abc","exam_id":7},"cipher":{}}}"#;
        assert_eq!(
            extract_raw_object(body, "header").as_deref(),
            Some(r#"{"bundle_id":"abc","exam_id":7}"#)
        );
    }

    #[test]
    fn is_not_fooled_by_the_key_appearing_inside_a_string() {
        // An exam titled `"header":{` is absurd, and is exactly the kind of
        // absurdity that turns up in a real school's data.
        let body = r#"{"title":"\"header\":{ not this one","header":{"exam_id":9}}"#;
        assert_eq!(
            extract_raw_object(body, "header").as_deref(),
            Some(r#"{"exam_id":9}"#)
        );
    }

    #[test]
    fn handles_nested_braces_and_escaped_quotes() {
        let body = r#"{"header":{"title":"a \"quoted\" {brace}","nested":{"deep":true}},"cipher":{}}"#;
        assert_eq!(
            extract_raw_object(body, "header").as_deref(),
            Some(r#"{"title":"a \"quoted\" {brace}","nested":{"deep":true}}"#)
        );
    }

    #[test]
    fn returns_nothing_when_the_key_is_absent() {
        assert_eq!(extract_raw_object(r#"{"cipher":{}}"#, "header"), None);
    }
}
