//! What this machine remembers, and the rules for it.
//!
//! Two files, kept apart on purpose:
//!
//! - **`config.json`** in `%ProgramData%\SchoolPilot\Exam` — which school this
//!   PC sits papers for, and the invigilator PIN (salted hash, never the PIN).
//!   Machine-wide, so every Windows account on a lab PC opens the same school,
//!   and written once at setup.
//! - **`state.json`** next to the WebView2 profile in `%LOCALAPPDATA%` — the
//!   last page and which attempts still hold unsent answers. Per user, because
//!   the held answers themselves live in that user's WebView2 localStorage.
//!
//! No student data is stored here — only attempt ids, which mean nothing
//! without the school's server (NDPA data-minimisation, doc §12).

use std::fs;
use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};

/// Where a bare school code points: `kings` → `https://kings.schoolpilot.org.ng`.
pub const TENANT_DOMAIN: &str = "schoolpilot.org.ng";

pub const MIN_PIN_LEN: usize = 4;
pub const MAX_PIN_LEN: usize = 12;

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Config {
    /// What the invigilator typed, kept for display ("kings").
    pub school: String,
    /// The portal origin every page must stay on, e.g. `https://kings.schoolpilot.org.ng`.
    pub portal: String,
    pub pin_salt: String,
    pub pin_hash: String,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize, PartialEq)]
pub struct State {
    /// The last portal page that finished loading.
    #[serde(default)]
    pub last_url: Option<String>,
    /// Attempt ids whose answers are held in localStorage and not yet sent.
    #[serde(default)]
    pub held: Vec<String>,
}

impl Config {
    /// Build a config from what the invigilator typed at setup.
    pub fn new(school: &str, pin: &str, confirm: &str) -> Result<Self, String> {
        let portal = portal_from(school)?;
        check_pin(pin)?;

        if pin != confirm {
            return Err("The two PINs do not match.".into());
        }

        let salt = random_hex(16);
        Ok(Config {
            school: school.trim().to_lowercase(),
            portal,
            pin_hash: hash_pin(&salt, pin),
            pin_salt: salt,
        })
    }

    pub fn pin_matches(&self, pin: &str) -> bool {
        // Not constant-time; the attacker is a candidate at a keyboard with a
        // lockout after five tries, not a timing oracle.
        hash_pin(&self.pin_salt, pin.trim()) == self.pin_hash
    }

    /// The school's name for the top bar.
    pub fn label(&self) -> &str {
        let host = self.portal.split_once("://").map_or(self.portal.as_str(), |(_, host)| host);
        host.strip_suffix(&format!(".{TENANT_DOMAIN}")).unwrap_or(host)
    }

    /// Is this URL inside the school's portal (or one of this app's own pages)?
    pub fn allows(&self, url: &str) -> bool {
        is_within(url, &self.portal) || is_own_page(url)
    }

    pub fn load(dir: &Path) -> Option<Self> {
        read_json(&dir.join("config.json"))
    }

    pub fn save(&self, dir: &Path) -> Result<(), String> {
        write_json(&dir.join("config.json"), self)
    }
}

impl State {
    pub fn load(dir: &Path) -> Self {
        read_json(&dir.join("state.json")).unwrap_or_default()
    }

    pub fn save(&self, dir: &Path) {
        // Losing this file loses convenience (which page to reopen), never an
        // answer — those live in WebView2's own storage.
        let _ = write_json(&dir.join("state.json"), self);
    }

    /// Where the window should go once the portal answers.
    ///
    /// An attempt with unsent answers wins: its page is the only thing that
    /// sends them. Then an exam page that was open when the app went down.
    /// Anything else starts at the portal's front door — a lab PC should not
    /// reopen the last person's report card.
    pub fn resume_target(&self, portal: &str) -> String {
        if let Some(id) = self.held.iter().find(|id| is_attempt_id(id)) {
            return format!("{portal}/student/tests/{id}");
        }

        if let Some(last) = &self.last_url {
            if is_within(last, portal) {
                let path = &last[portal.len()..];
                if path.starts_with("/student/tests/") {
                    return last.clone();
                }
            }
        }

        format!("{portal}/")
    }
}

/// Turn what was typed into a portal origin.
///
/// A school code (`kings`) becomes its SchoolPilot subdomain. A full address
/// (`https://exams.kingscollege.ng/login`) is cut back to its origin. Plain
/// http is refused except on this machine, which only a developer uses.
pub fn portal_from(input: &str) -> Result<String, String> {
    let input = input.trim().to_lowercase();

    if input.is_empty() {
        return Err("Enter your school's code or web address.".into());
    }

    let is_code = input.len() <= 63
        && input.chars().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '-')
        && !input.starts_with('-')
        && !input.ends_with('-');

    if is_code {
        return Ok(format!("https://{input}.{TENANT_DOMAIN}"));
    }

    let (scheme, rest) = match input.split_once("://") {
        Some((scheme, rest)) => (scheme.to_string(), rest),
        None => ("https".to_string(), input.as_str()),
    };

    let host = rest.split(['/', '?', '#']).next().unwrap_or_default();

    let valid_host = !host.is_empty()
        && !host.contains('@')
        && host.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-' | ':' | '[' | ']'));

    if !valid_host {
        return Err("That doesn't look like a school code or web address.".into());
    }

    let hostname = host.rsplit_once(':').map_or(host, |(name, _)| name);
    let local = matches!(hostname, "localhost" | "127.0.0.1" | "[::1]");

    let labels_ok = hostname.split('.').all(|l| !l.is_empty() && !l.starts_with('-') && !l.ends_with('-'));
    if !local && (!hostname.contains('.') || !labels_ok) {
        return Err("That doesn't look like a school code or web address.".into());
    }

    match scheme.as_str() {
        "https" => Ok(format!("https://{host}")),
        "http" if local => Ok(format!("http://{host}")),
        "http" => Err("Use the school's https:// address.".into()),
        _ => Err("That doesn't look like a school code or web address.".into()),
    }
}

pub fn check_pin(pin: &str) -> Result<(), String> {
    let ok = (MIN_PIN_LEN..=MAX_PIN_LEN).contains(&pin.len()) && pin.chars().all(|c| c.is_ascii_digit());

    if ok {
        Ok(())
    } else {
        Err(format!("The PIN must be {MIN_PIN_LEN} to {MAX_PIN_LEN} digits."))
    }
}

/// `url` is `origin` itself or a page under it.
///
/// The boundary check is the point: a bare `starts_with` would accept
/// `https://kings.schoolpilot.org.ng.evil.example`, a host anyone can register.
pub fn is_within(url: &str, origin: &str) -> bool {
    match url.strip_prefix(origin) {
        Some(rest) => matches!(rest.chars().next(), None | Some('/') | Some('?') | Some('#')),
        None => false,
    }
}

/// This app's own pages, served from inside the binary.
///
/// wry hands them over as `spexam://localhost/…` on some platforms and
/// rewrites them to `http://spexam.localhost/…` on Windows; both must pass.
pub fn is_own_page(url: &str) -> bool {
    ["spexam://localhost", "http://spexam.localhost", "https://spexam.localhost"]
        .iter()
        .any(|origin| is_within(url, origin))
}

fn is_attempt_id(id: &str) -> bool {
    !id.is_empty() && id.len() <= 20 && id.chars().all(|c| c.is_ascii_digit())
}

/// `a.b.c` compared numerically; anything unparsable counts as 0.0.0.
pub fn version_older(installed: &str, other: &str) -> bool {
    fn parts(v: &str) -> [u64; 3] {
        let mut out = [0; 3];
        for (slot, piece) in out.iter_mut().zip(v.trim().trim_start_matches('v').split('.')) {
            *slot = piece.parse().unwrap_or(0);
        }
        out
    }

    parts(installed) < parts(other)
}

fn hash_pin(salt: &str, pin: &str) -> String {
    let digest = Sha256::digest(format!("schoolpilot-exam:{salt}:{pin}").as_bytes());
    hex(&digest)
}

fn random_hex(bytes: usize) -> String {
    let mut buf = vec![0u8; bytes];
    getrandom::getrandom(&mut buf).expect("the OS random source is unavailable");
    hex(&buf)
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

fn read_json<T: for<'de> Deserialize<'de>>(path: &Path) -> Option<T> {
    serde_json::from_str(&fs::read_to_string(path).ok()?).ok()
}

fn write_json<T: Serialize>(path: &Path, value: &T) -> Result<(), String> {
    let fail = |e: std::io::Error| format!("Could not save to {}: {e}", path.display());

    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent).map_err(fail)?;
    }

    // Write then rename, so a power cut mid-write leaves the old file whole.
    let tmp = path.with_extension("tmp");
    fs::write(&tmp, serde_json::to_vec_pretty(value).expect("config serialises")).map_err(fail)?;
    fs::rename(&tmp, path).map_err(fail)
}

/// `%ProgramData%\SchoolPilot\Exam` — shared by every account on the PC.
pub fn machine_dir() -> PathBuf {
    std::env::var_os("ProgramData")
        .map(PathBuf::from)
        .unwrap_or_else(|| dirs::data_local_dir().unwrap_or_else(std::env::temp_dir))
        .join("SchoolPilot")
        .join("Exam")
}

/// `%LOCALAPPDATA%\SchoolPilot\Exam` — this user's WebView2 profile and state.
pub fn user_dir() -> PathBuf {
    dirs::data_local_dir()
        .unwrap_or_else(std::env::temp_dir)
        .join("SchoolPilot")
        .join("Exam")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_school_code_becomes_its_subdomain() {
        assert_eq!(portal_from("kings").unwrap(), "https://kings.schoolpilot.org.ng");
        assert_eq!(portal_from("  Kings-College ").unwrap(), "https://kings-college.schoolpilot.org.ng");
    }

    #[test]
    fn a_full_address_is_cut_back_to_its_origin() {
        assert_eq!(portal_from("https://kings.schoolpilot.org.ng/login").unwrap(), "https://kings.schoolpilot.org.ng");
        assert_eq!(portal_from("exams.kingscollege.ng").unwrap(), "https://exams.kingscollege.ng");
        assert_eq!(portal_from("http://localhost:3000/student").unwrap(), "http://localhost:3000");
    }

    #[test]
    fn plain_http_and_nonsense_are_refused() {
        for bad in ["", "http://kings.schoolpilot.org.ng", "ftp://x.ng", "user@evil.example", "-kings", "a b"] {
            assert!(portal_from(bad).is_err(), "`{bad}` was accepted");
        }
    }

    #[test]
    fn the_portal_is_allowed_and_lookalikes_are_not() {
        let config = Config::new("kings", "1234", "1234").unwrap();

        for ok in [
            "https://kings.schoolpilot.org.ng",
            "https://kings.schoolpilot.org.ng/student/tests/42",
            "https://kings.schoolpilot.org.ng?x=1",
            "http://spexam.localhost/connect",
            "spexam://localhost/setup",
        ] {
            assert!(config.allows(ok), "`{ok}` was refused");
        }

        for bad in [
            "https://kings.schoolpilot.org.ng.evil.example/",
            "https://kings.schoolpilot.org.ngx/",
            "https://other.schoolpilot.org.ng/",
            "http://kings.schoolpilot.org.ng/",
            "https://google.com/",
            "http://spexam.localhost.evil.example/",
            "file:///C:/Windows/System32/cmd.exe",
        ] {
            assert!(!config.allows(bad), "`{bad}` was allowed");
        }
    }

    #[test]
    fn the_pin_is_checked_and_never_stored() {
        let config = Config::new("kings", "2580", "2580").unwrap();

        assert!(config.pin_matches("2580"));
        assert!(!config.pin_matches("2581"));
        assert!(!config.pin_hash.contains("2580"));

        assert!(Config::new("kings", "12", "12").is_err());
        assert!(Config::new("kings", "12ab", "12ab").is_err());
        assert!(Config::new("kings", "1234", "4321").is_err());
    }

    #[test]
    fn the_strip_names_the_school_not_the_url() {
        assert_eq!(Config::new("kings", "1234", "1234").unwrap().label(), "kings");
        assert_eq!(Config::new("https://exams.kings.ng/login", "1234", "1234").unwrap().label(), "exams.kings.ng");
    }

    #[test]
    fn two_machines_with_the_same_pin_store_different_hashes() {
        let a = Config::new("kings", "1234", "1234").unwrap();
        let b = Config::new("kings", "1234", "1234").unwrap();
        assert_ne!(a.pin_hash, b.pin_hash);
    }

    #[test]
    fn an_attempt_with_unsent_answers_is_reopened_first() {
        let portal = "https://kings.schoolpilot.org.ng";

        let state = State { last_url: Some(format!("{portal}/student/results")), held: vec!["77".into()] };
        assert_eq!(state.resume_target(portal), format!("{portal}/student/tests/77"));

        let state = State { last_url: Some(format!("{portal}/student/tests/9")), held: vec![] };
        assert_eq!(state.resume_target(portal), format!("{portal}/student/tests/9"));

        // Not an exam page: back to the front door, not the last user's page.
        let state = State { last_url: Some(format!("{portal}/student/report-cards")), held: vec![] };
        assert_eq!(state.resume_target(portal), format!("{portal}/"));

        // Another school's page or a junk id never becomes the target.
        let state = State { last_url: Some("https://other.schoolpilot.org.ng/student/tests/1".into()), held: vec!["1/../x".into()] };
        assert_eq!(state.resume_target(portal), format!("{portal}/"));
    }

    #[test]
    fn config_and_state_survive_a_restart() {
        let dir = tempfile::tempdir().unwrap();
        let config = Config::new("kings", "1234", "1234").unwrap();
        config.save(dir.path()).unwrap();
        assert_eq!(Config::load(dir.path()), Some(config));

        let state = State { last_url: Some("https://kings.schoolpilot.org.ng/".into()), held: vec!["5".into()] };
        state.save(dir.path());
        assert_eq!(State::load(dir.path()), state);
    }

    #[test]
    fn versions_compare_numerically() {
        assert!(version_older("1.0.0", "1.0.1"));
        assert!(version_older("1.9.0", "1.10.0"));
        assert!(!version_older("1.10.0", "1.9.0"));
        assert!(!version_older("1.0.0", "1.0.0"));
        assert!(version_older("garbage", "0.0.1"));
    }
}
