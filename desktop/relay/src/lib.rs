//! SchoolPilot lab relay — library half.
//!
//! The binary in `main.rs` is a thin command-line skin over this. Everything
//! testable lives here, most importantly [`shuffle`], which carries the
//! determinism contract with the server (docs/offline-cbt-client.md §10) and is
//! asserted against the shared parity vectors in `tests/parity.rs`.

pub mod api;
pub mod assets;
pub mod bundle;
pub mod config;
pub mod error;
pub mod kiosk;
pub mod pairing;
pub mod paper;
pub mod server;
pub mod shuffle;
pub mod store;
pub mod sync;
pub mod tls;
pub mod ui;
