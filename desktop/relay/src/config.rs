//! Where the relay keeps its things, and what it is allowed to keep.
//!
//! §13 governs this file more than anything else: the relay holds question
//! text, children's answers and — later — photographs of their handwriting.
//! The content key is conspicuously absent from everything here. It lives in
//! memory for the length of one exam and is never written to disk, a log, or a
//! crash dump.

use serde::{Deserialize, Serialize};
use std::path::{Path, PathBuf};

use crate::error::{RelayError, Result};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Config {
    /// e.g. `https://kings.schoolpilot.ng` — the school's subdomain, because
    /// the platform is multi-tenant and the relay belongs to one school.
    pub base_url: String,
    /// Sanctum token from `POST /api/v1/auth/login`. Staff credentials, the
    /// same ones the web portal uses.
    pub token: Option<String>,
    pub staff_name: Option<String>,
    /// How this relay identifies itself when issuing a bundle, so a second
    /// issuance for the same exam can be refused by name (§14).
    pub relay_identity: Option<String>,
    /// The tenant this relay is bound to, learned from the first bundle it is
    /// issued.
    ///
    /// The platform is multi-tenant and `base_url` is a school's subdomain, so
    /// a relay that changed schools while still holding a paper would be
    /// carrying one school's children's answers to another school's API. The
    /// backend would reject the attempts as off-roster (§9.3), but the data
    /// would already have left, and under NDPA that is a disclosure whether or
    /// not the far end accepted it. So the binding is recorded and enforced
    /// rather than assumed from the URL.
    #[serde(default)]
    pub school_id: Option<i64>,
    /// Port the candidate-facing server listens on.
    #[serde(default = "default_port")]
    pub port: u16,
}

fn default_port() -> u16 {
    8443
}

impl Config {
    pub fn api_root(&self) -> String {
        format!("{}/api/v1", self.base_url.trim_end_matches('/'))
    }

    pub fn token(&self) -> Result<&str> {
        self.token.as_deref().ok_or(RelayError::NotAuthenticated)
    }

    /// Refuse a bundle belonging to a school this relay is not serving.
    ///
    /// Defence in depth: the backend issues bundles scoped to the caller's
    /// tenant, so reaching here means something has already gone wrong — a
    /// staff account with access to two schools, a bundle file copied between
    /// laptops, a relay re-pointed at a sister school mid-term. This is the
    /// last place to catch it before another school's question paper is on
    /// this disk.
    pub fn guard_tenant(&self, incoming: i64) -> Result<()> {
        match self.school_id {
            Some(held) if held != incoming => {
                Err(RelayError::TenantMismatch { held, incoming })
            }
            _ => Ok(()),
        }
    }
}

/// May this relay be pointed at a different school right now?
///
/// Changing school is legitimate — a laptop is re-deployed, a pilot ends — but
/// not while the relay still holds a paper, a roster or answers belonging to
/// the school it is leaving. Purging is the deliberate act that makes it safe,
/// and §13 wants that purge to happen anyway.
pub fn may_switch_tenant(current: &str, incoming: &str, holds_data: bool) -> bool {
    !holds_data || same_tenant(current, incoming)
}

/// Two base URLs addressing the same school.
///
/// Compared without scheme, trailing slash or case, because `relay login` is
/// typed by a person and `https://Kings.schoolpilot.ng/` is the same school as
/// `https://kings.schoolpilot.ng`. Anything beyond that — a different
/// subdomain — is a different tenant and is treated as one.
fn same_tenant(a: &str, b: &str) -> bool {
    fn key(url: &str) -> String {
        url.trim()
            .trim_end_matches('/')
            .trim_start_matches("https://")
            .trim_start_matches("http://")
            .to_lowercase()
    }

    key(a) == key(b)
}

impl Default for Config {
    fn default() -> Self {
        Self {
            base_url: "http://localhost:8000".to_string(),
            token: None,
            staff_name: None,
            relay_identity: None,
            school_id: None,
            port: default_port(),
        }
    }
}

/// Resolved paths for one relay installation.
#[derive(Debug, Clone)]
pub struct Paths {
    pub root: PathBuf,
}

impl Paths {
    /// `%LOCALAPPDATA%\SchoolPilot\relay` on Windows, which is where a lab PC's
    /// non-roaming per-user data belongs. Overridable so tests and a school
    /// running the relay from a USB stick both work.
    pub fn resolve(override_root: Option<PathBuf>) -> Result<Self> {
        let root = match override_root {
            Some(path) => path,
            None => dirs::data_local_dir()
                .ok_or_else(|| {
                    RelayError::Protocol("could not find a local data directory".into())
                })?
                .join("SchoolPilot")
                .join("relay"),
        };

        std::fs::create_dir_all(&root)?;
        std::fs::create_dir_all(root.join("media"))?;

        Ok(Self { root })
    }

    pub fn config_file(&self) -> PathBuf {
        self.root.join("relay.json")
    }

    /// Where the relay keeps everything, including its TLS identity (§8.3).
    pub fn home(&self) -> PathBuf {
        self.root.clone()
    }

    pub fn database(&self) -> PathBuf {
        self.root.join("relay.sqlite")
    }

    pub fn media_dir(&self) -> PathBuf {
        self.root.join("media")
    }

    pub fn media_file(&self, asset_id: i64) -> PathBuf {
        self.media_dir().join(format!("{asset_id}.bin"))
    }

    pub fn load(&self) -> Result<Config> {
        let path = self.config_file();

        if !path.exists() {
            return Ok(Config::default());
        }

        Ok(serde_json::from_str(&std::fs::read_to_string(path)?)?)
    }

    pub fn save(&self, config: &Config) -> Result<()> {
        write_private(&self.config_file(), &serde_json::to_vec_pretty(config)?)
    }
}

/// Write a file that holds a staff token.
///
/// On Windows the parent directory under `%LOCALAPPDATA%` is already
/// per-user; this exists so the permission question has one obvious home if a
/// school ever runs the relay on a shared account, and so the Unix path (a
/// developer's machine) does not sit at 0644.
fn write_private(path: &Path, bytes: &[u8]) -> Result<()> {
    std::fs::write(path, bytes)?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        std::fs::set_permissions(path, std::fs::Permissions::from_mode(0o600))?;
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn bound_to(school_id: Option<i64>) -> Config {
        Config { school_id, ..Config::default() }
    }

    #[test]
    fn a_relay_accepts_its_own_schools_bundles() {
        assert!(bound_to(Some(42)).guard_tenant(42).is_ok());
    }

    #[test]
    fn an_unbound_relay_takes_the_first_bundle_it_is_given() {
        // Before the first provision there is nothing to contradict, and the
        // caller records the binding from that bundle.
        assert!(bound_to(None).guard_tenant(42).is_ok());
    }

    #[test]
    fn another_schools_paper_is_refused() {
        // The one that matters: a question paper belonging to a school this
        // relay does not serve must not reach this disk.
        let refusal = bound_to(Some(42)).guard_tenant(7).unwrap_err();

        assert!(matches!(
            refusal,
            RelayError::TenantMismatch { held: 42, incoming: 7 }
        ));
        assert!(refusal.to_string().contains("relay purge"));
    }

    #[test]
    fn a_relay_holding_a_paper_may_not_change_school() {
        assert!(!may_switch_tenant(
            "https://kings.schoolpilot.ng",
            "https://queens.schoolpilot.ng",
            true
        ));
    }

    #[test]
    fn a_purged_relay_may_be_redeployed() {
        // Redeployment is legitimate; doing it while holding children's
        // answers is not. Purging is what makes the difference.
        assert!(may_switch_tenant(
            "https://kings.schoolpilot.ng",
            "https://queens.schoolpilot.ng",
            false
        ));
    }

    #[test]
    fn signing_in_again_to_the_same_school_is_not_a_switch() {
        // Re-authenticating mid-term is routine — a token expires, a password
        // changes — and must not be mistaken for a redeployment just because
        // the operator typed the URL slightly differently.
        for spelling in [
            "https://kings.schoolpilot.ng",
            "https://kings.schoolpilot.ng/",
            "https://Kings.SchoolPilot.ng",
            "http://kings.schoolpilot.ng",
        ] {
            assert!(
                may_switch_tenant("https://kings.schoolpilot.ng", spelling, true),
                "`{spelling}` was treated as a different school"
            );
        }
    }

    #[test]
    fn a_sister_school_on_the_same_domain_is_still_a_different_tenant() {
        // Subdomain is the tenant. Two schools in one group share everything
        // but the part that identifies them.
        assert!(!may_switch_tenant(
            "https://kings.schoolpilot.ng",
            "https://kings-annex.schoolpilot.ng",
            true
        ));
    }

    #[test]
    fn the_tenant_binding_survives_a_round_trip_through_the_config_file() {
        let config = bound_to(Some(42));
        let reloaded: Config =
            serde_json::from_str(&serde_json::to_string(&config).unwrap()).unwrap();

        assert_eq!(reloaded.school_id, Some(42));
    }

    #[test]
    fn a_config_written_before_this_field_existed_still_loads() {
        // Relays already in the field have no `school_id` in their JSON. They
        // must keep working and bind on their next provision, not fail to
        // start.
        let legacy = r#"{"base_url":"https://kings.schoolpilot.ng","token":"t","port":8443}"#;
        let config: Config = serde_json::from_str(legacy).unwrap();

        assert_eq!(config.school_id, None);
        assert!(config.guard_tenant(42).is_ok());
    }
}
