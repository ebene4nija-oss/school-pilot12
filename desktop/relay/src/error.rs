//! Failures the relay can hit, phrased for the person standing in the lab.
//!
//! An invigilator with forty candidates waiting cannot act on "decrypt failed".
//! Every message here is written to be read aloud to somebody who did not build
//! this and cannot read a stack trace.

use thiserror::Error;

pub type Result<T> = std::result::Result<T, RelayError>;

#[derive(Debug, Error)]
pub enum RelayError {
    #[error("Bundle failed authentication. It is the wrong key, or the file has been altered.")]
    Authentication,

    #[error(
        "This bundle was built for format version {found}, and this relay understands version \
         {supported}. Update the relay before exam day — it will not open this paper."
    )]
    UnsupportedFormat { found: i64, supported: i64 },

    #[error("The exam has not opened yet, so the key has not been released.")]
    NotOpenYet,

    #[error("Not signed in. Run `relay login` while you still have internet.")]
    NotAuthenticated,

    #[error("No bundle has been provisioned. Run `relay provision --exam <id>` the day before.")]
    NoBundle,

    #[error("The paper is still sealed. The relay needs its key before it can serve candidates.")]
    Locked,

    #[error("No candidate on this bundle's roster matches that admission number and code.")]
    UnknownCandidate,

    #[error("Attempt {0} is not on this bundle's roster.")]
    AttemptNotOnRoster(i64),

    #[error(
        "The exam window could not be opened on this machine: {0}. If this says WebView2 is \
         missing, install the WebView2 runtime from the relay's USB stick and try again."
    )]
    Kiosk(String),

    #[error("Transport security failed: {0}")]
    Tls(String),

    #[error("{0}")]
    NotConfigured(String),

    #[error(
        "This bundle belongs to school {incoming}, and this relay is serving school {held}. \
         Refusing it — a relay carries one school's papers at a time. If this laptop is being \
         moved to another school, run `relay purge` first."
    )]
    TenantMismatch { held: i64, incoming: i64 },

    #[error(
        "This relay is signed in to {current} and is still holding that school's exam data. \
         Signing in to {incoming} now would leave one school's candidate answers on a relay \
         belonging to another. Run `relay sync` to upload what is outstanding, then \
         `relay purge`, then sign in again."
    )]
    TenantSwitch { current: String, incoming: String },

    #[error("{0}")]
    Protocol(String),

    #[error("The server said: {message} (HTTP {status})")]
    Server { status: u16, message: String },

    #[error("Could not reach the server. Check the connection and try again. ({0})")]
    Transport(String),

    #[error("Local database error: {0}")]
    Storage(#[from] rusqlite::Error),

    #[error("Could not read or write relay files: {0}")]
    Io(#[from] std::io::Error),

    #[error("Malformed response from the server: {0}")]
    Json(#[from] serde_json::Error),

    #[error("Malformed base64 in the bundle: {0}")]
    Base64(#[from] base64::DecodeError),
}

impl From<reqwest::Error> for RelayError {
    fn from(error: reqwest::Error) -> Self {
        RelayError::Transport(error.to_string())
    }
}
