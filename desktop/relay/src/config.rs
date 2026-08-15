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
}

impl Default for Config {
    fn default() -> Self {
        Self {
            base_url: "http://localhost:8000".to_string(),
            token: None,
            staff_name: None,
            relay_identity: None,
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
