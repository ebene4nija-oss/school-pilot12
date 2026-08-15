//! SchoolPilot lab relay.
//!
//! Build order step 3 (docs/offline-cbt-client.md §19): auth, provision, bundle
//! storage, SQLite, sync, and no UI beyond a status window. The candidate
//! client, pairing and pinned TLS are step 4.
//!
//! The shape of a school's exam day, in commands:
//!
//! ```text
//!   relay login      --base-url https://kings.schoolpilot.ng   # once
//!   relay provision  --exam 42                                 # the day before
//!   relay serve                                                # exam morning
//!   relay sync                                                 # afterwards
//!   relay purge                                                # §13
//! ```
//!
//! Only `login`, `provision`, the unlock at the start of `serve`, and `sync`
//! need connectivity. Nothing during the paper does, which is the entire point.

use clap::{Parser, Subcommand};
use std::net::{IpAddr, Ipv4Addr, SocketAddr};
use std::path::PathBuf;
use std::sync::{Arc, Mutex, RwLock};

use schoolpilot_relay::api::Api;
use schoolpilot_relay::config::Paths;
use schoolpilot_relay::error::{RelayError, Result};
use schoolpilot_relay::store::Store;
use schoolpilot_relay::{bundle, kiosk, server, sync};

#[derive(Parser)]
#[command(name = "relay", version, about = "SchoolPilot lab relay")]
struct Cli {
    /// Where the relay keeps its bundle, database and media.
    #[arg(long, global = true, env = "RELAY_HOME")]
    home: Option<PathBuf>,

    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Prepare this machine. Run once, at installation, before exam week.
    ///
    /// On the relay laptop this generates the TLS identity and prints the
    /// fingerprint the lab machines must pin (§8.3). On a candidate machine,
    /// `--relay` and `--fingerprint` record what it needs so that `relay
    /// candidate` later takes no arguments at all.
    ///
    /// §5.1's rule is that exam morning involves no configuration. Generating
    /// the certificate at first `serve` would break that: the fingerprint would
    /// not exist until the morning it was needed on forty machines.
    Init {
        /// Configure this machine as a candidate PC pointing at that relay.
        /// Omit on the relay laptop itself.
        #[arg(long)]
        relay: Option<String>,
        /// The relay's fingerprint, from `relay init` on the relay laptop.
        #[arg(long)]
        fingerprint: Option<String>,
        /// Print the fingerprint alone, for an installer script to capture.
        #[arg(long)]
        quiet: bool,
    },

    /// Sign in with staff credentials and remember the school's address.
    Login {
        #[arg(long)]
        base_url: Option<String>,
        #[arg(long)]
        email: String,
        /// Prefer the environment variable over a shell history entry.
        #[arg(long, env = "RELAY_PASSWORD")]
        password: String,
        /// How this relay names itself when issuing a bundle (§14).
        #[arg(long)]
        relay_identity: Option<String>,
    },

    /// Download the encrypted paper and its media. The day before, needs
    /// connectivity once.
    Provision {
        #[arg(long)]
        exam: i64,
        /// Issue a second bundle for an exam that already has one. Deliberate
        /// only: two relays serving one paper is a split brain (§14).
        #[arg(long)]
        override_existing: bool,
    },

    /// Serve the room. Fetches the key, opens the paper into memory, and does
    /// not touch the network again.
    Serve {
        #[arg(long)]
        port: Option<u16>,
        /// Bind beyond loopback, so the lab can reach it. The traffic is TLS
        /// and candidates pin the certificate (§8.3), so this is the normal
        /// setting for a real room; loopback stays the default so a relay
        /// started by accident serves nobody.
        #[arg(long)]
        lan: bool,
    },

    /// Sit the paper: the candidate's fullscreen exam window (§4's second mode).
    ///
    /// Runs on each lab PC and points at the relay over the lab network. This is
    /// the mode a candidate sees; every other subcommand here is the
    /// invigilator's.
    Candidate {
        /// The relay's address on the lab network, e.g. https://10.0.0.4:8443.
        /// Defaults to what `relay init` recorded on this machine.
        #[arg(long)]
        relay: Option<String>,
        /// The certificate fingerprint the relay must present (§8.3). Defaults
        /// to what `relay init` recorded. Required for https either way —
        /// without it there is nothing to pin against.
        #[arg(long)]
        fingerprint: Option<String>,
    },

    /// Upload everything held locally. After the exam, whenever connectivity
    /// returns.
    Sync {
        #[arg(long, default_value_t = 25)]
        chunk: usize,
    },

    /// Run the whole thing against a built-in sample paper, with no backend.
    ///
    /// Exists for two audiences: an engineer who wants to see the relay work
    /// before wiring up a school, and a school that wants to know whether these
    /// lab machines can run a paper at all — which is worth answering with a
    /// demo rather than with a real exam.
    Demo {
        #[arg(long)]
        port: Option<u16>,
        #[arg(long)]
        lan: bool,
        /// Minutes on the clock, so the countdown does something visible.
        #[arg(long, default_value_t = 45)]
        minutes: i64,
        /// Serve the demo over TLS and print the fingerprint, so the candidate
        /// shell can be exercised on the same pinned path a real exam uses
        /// (§8.3). Plain http otherwise, which is friendlier to a browser.
        #[arg(long)]
        tls: bool,
    },

    /// What this relay is holding right now.
    Status,

    /// Delete a finalised exam's local data (§13).
    Purge {
        /// Purge even where attempts have not been confirmed synced. Loses
        /// answers; requires saying so out loud.
        #[arg(long)]
        force: bool,
    },
}

#[tokio::main]
async fn main() {
    tracing_subscriber::fmt()
        .with_env_filter(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| "schoolpilot_relay=info".into()),
        )
        .with_target(false)
        .init();

    if let Err(error) = run().await {
        // One line, in words an invigilator can act on. The detail is in the
        // log; the person in the room gets the sentence.
        eprintln!("\n  {error}\n");
        std::process::exit(1);
    }
}

async fn run() -> Result<()> {
    let cli = Cli::parse();
    let paths = Paths::resolve(cli.home)?;
    let mut config = paths.load()?;

    match cli.command {
        Command::Init { relay, fingerprint, quiet } => {
            match relay {
                // A candidate machine. It gets an address and a fingerprint and
                // nothing else — no token, no school, no exam data.
                Some(relay_url) => {
                    if relay_url.starts_with("https://") && fingerprint.is_none() {
                        return Err(RelayError::Tls(
                            "an https relay needs --fingerprint, or this machine will have \
                             nothing to check the relay against on exam morning"
                                .into(),
                        ));
                    }

                    paths.save_candidate(&schoolpilot_relay::config::CandidateConfig {
                        relay_url: relay_url.clone(),
                        fingerprint: fingerprint.clone(),
                    })?;

                    if !quiet {
                        println!("\n  This machine is set up to sit exams from {relay_url}.");

                        match &fingerprint {
                            Some(pin) => println!("  It will accept only certificate {pin}."),
                            None => println!(
                                "  WARNING: no fingerprint, so this machine cannot verify the \
                                 relay. Plain http only — never a real paper."
                            ),
                        }

                        println!("\n  On exam morning, run:\n\n    relay candidate\n");
                    }
                }

                // The relay laptop. This is the step that has to happen before
                // the lab machines can be configured at all.
                None => {
                    let identity =
                        schoolpilot_relay::tls::RelayIdentity::load_or_create(&paths.home())?;

                    if quiet {
                        // For an installer script: one line, nothing else, so
                        // it can be captured and fed to the lab machines.
                        println!("{}", identity.fingerprint);
                    } else {
                        println!(
                            "\n  Relay {} at {}.",
                            if identity.created { "prepared" } else { "already prepared" },
                            paths.home().display()
                        );
                        println!("\n  Certificate fingerprint:\n");
                        println!("    {}\n", identity.fingerprint);
                        println!("  To check by eye:\n\n    {}\n", identity.readable_fingerprint());
                        println!("  On each candidate machine, once:\n");
                        println!("    relay init --relay https://<this machine>:{} \\", config.port);
                        println!("      --fingerprint {}\n", identity.fingerprint);
                        println!(
                            "  Keep this fingerprint. It does not change, and every lab machine \n  \
                             is checking against it.\n"
                        );
                    }
                }
            }
        }

        Command::Login { base_url, email, password, relay_identity } => {
            if let Some(url) = base_url {
                // §13 and multi-tenancy together: a relay still holding one
                // school's paper must not be re-pointed at another school's
                // subdomain. `sync` then `purge` is the deliberate path.
                let holds_data = Store::open(&paths.database())
                    .ok()
                    .and_then(|store| store.active_bundle().ok().flatten())
                    .is_some();

                if !schoolpilot_relay::config::may_switch_tenant(
                    &config.base_url,
                    &url,
                    holds_data,
                ) {
                    return Err(RelayError::TenantSwitch {
                        current: config.base_url.clone(),
                        incoming: url,
                    });
                }

                // A clean relay changing schools starts over: the old tenant
                // binding must not outlive the school it belonged to.
                if config.base_url != url {
                    config.school_id = None;
                }

                config.base_url = url;
            }
            if relay_identity.is_some() {
                config.relay_identity = relay_identity;
            }

            let api = Api::new(&config)?;
            let (token, name) = api.login(&email, &password).await?;

            config.token = Some(token);
            config.staff_name = name.clone();
            paths.save(&config)?;

            println!(
                "Signed in to {} as {}.",
                config.base_url,
                name.unwrap_or_else(|| email.clone())
            );
        }

        Command::Provision { exam, override_existing } => {
            config.token()?;
            let api = Api::new(&config)?;
            let store = Store::open(&paths.database())?;

            println!("Requesting the bundle for exam {exam}...");

            let sealed = api
                .issue_bundle(exam, config.relay_identity.as_deref(), override_existing)
                .await?;

            // Checked before the ciphertext touches the disk, not after.
            config.guard_tenant(sealed.envelope.header.school_id)?;

            store.save_bundle(&sealed)?;

            // First bundle binds the relay to its school. Recorded rather than
            // inferred from `base_url`, so a subdomain that changes (a rename,
            // a custom domain) does not silently look like a different tenant.
            if config.school_id.is_none() {
                config.school_id = Some(sealed.envelope.header.school_id);
                paths.save(&config)?;
            }

            let header = &sealed.envelope.header;
            println!(
                "Bundle {} issued: {} questions for {} candidates.",
                header.bundle_id, header.question_count, header.attempt_count
            );

            // The roster lives inside the ciphertext, so it cannot be stored
            // until the paper is opened on exam morning. What can be stored now
            // is the sealed bundle and the media — which is the whole design:
            // the relay carries the paper overnight without being able to read
            // it (§8.1).
            let assets = Api::media_assets(&sealed.media_manifest);
            let total: i64 = assets.iter().filter_map(|a| a.byte_size).sum();

            if assets.is_empty() {
                println!("No media to fetch.");
            } else {
                println!(
                    "Fetching {} media files ({:.1} MB)...",
                    assets.len(),
                    total as f64 / 1_048_576.0
                );

                let mut failed = 0;

                for asset in &assets {
                    match api.download(&asset.url).await {
                        Ok(bytes) => {
                            std::fs::write(paths.media_file(asset.asset_id), &bytes)?;
                            store.record_media(&header.bundle_id, asset, true)?;
                        }
                        Err(error) => {
                            failed += 1;
                            tracing::warn!("asset {} failed: {error}", asset.asset_id);
                        }
                    }
                }

                if failed > 0 {
                    println!(
                        "{failed} media files could not be fetched. Run provision again while \
                         you still have internet — a paper with missing diagrams is not sittable."
                    );
                } else {
                    println!("Media complete and stored.");
                }
            }

            println!(
                "\nReady. On exam morning run `relay serve` — it needs about ten seconds of \
                 internet to fetch the key, then nothing."
            );
        }

        Command::Serve { port, lan } => {
            config.token()?;
            let api = Api::new(&config)?;
            let store = Store::open(&paths.database())?;

            let stored = store.active_bundle()?.ok_or(RelayError::NoBundle)?;

            println!("Fetching the key for bundle {}...", stored.bundle_id);

            // §9.2: refused before `opens_at`, by the server, where the clock is
            // ours. The relay does not second-guess that with a local check.
            let key = api.release_key(&stored.bundle_id).await?;

            let sealed = bundle::SealedBundle {
                envelope: stored.envelope.clone(),
                aad: stored.aad.clone(),
                media_manifest: stored.media_manifest.clone(),
            };

            let opened = sealed.open(&key)?;
            drop(key); // the string is gone; nothing wrote it anywhere

            store.save_roster(&stored.bundle_id, &opened.roster)?;

            println!(
                "Paper open: \"{}\", {} questions, {} candidates.",
                opened.exam.title,
                opened.questions.len(),
                opened.roster.len()
            );

            let addr = SocketAddr::new(
                if lan {
                    IpAddr::V4(Ipv4Addr::UNSPECIFIED)
                } else {
                    IpAddr::V4(Ipv4Addr::LOCALHOST)
                },
                port.unwrap_or(config.port),
            );

            let identity = schoolpilot_relay::tls::RelayIdentity::load_or_create(&paths.home())?;

            if identity.created {
                // §5.1: exam morning involves no configuration. Reaching here
                // means installation never ran, so the lab machines are pinned
                // to nothing and are about to need this fingerprint typed into
                // them one at a time — which is worth saying now rather than
                // letting it be discovered machine by machine.
                println!(
                    "\n  NOTE: this relay had no certificate, so one was just generated.\n  \
                     `relay init` should have run at installation. Every candidate machine \n  \
                     now needs the fingerprint below before it can sit this paper.\n"
                );
            }

            let state = Arc::new(server::AppState {
                store: Mutex::new(store),
                exam_title: opened.exam.title.clone(),
                bundle_id: stored.bundle_id.clone(),
                paper: RwLock::new(Some(opened)),
            });

            // The one thing the invigilator has to carry to the candidate
            // machines. Printed large and last, because it is what somebody is
            // about to read off this screen (§8.3).
            println!("\n  Status window: https://{addr}/");
            println!("\n  On each candidate machine:\n");
            println!("    relay candidate --relay https://<this machine>:{} \\", addr.port());
            println!("      --fingerprint {}\n", identity.fingerprint);
            println!("  Certificate fingerprint, to check by eye:\n");
            println!("    {}\n", identity.readable_fingerprint());
            println!("  Press Ctrl-C to close the paper.");

            let tls = schoolpilot_relay::tls::server_config(&identity).await?;

            server::serve_tls(Arc::clone(&state), addr, tls).await?;

            // §8.1: zero the paper on close.
            state.close();
            println!("Paper closed and dropped from memory. Run `relay sync` when you have internet.");
        }

        Command::Sync { chunk } => {
            config.token()?;
            let api = Api::new(&config)?;
            let store = Store::open(&paths.database())?;
            let stored = store.active_bundle()?.ok_or(RelayError::NoBundle)?;

            let report = sync::run(&api, &store, &stored.bundle_id, chunk).await?;

            println!("{}", report.headline());

            for (attempt_id, reason) in &report.problems {
                println!("  attempt {attempt_id}: {reason}");
            }

            if !report.problems.is_empty() {
                println!(
                    "\nThose attempts are still queued locally. Nothing has been lost — \
                     run sync again once the cause is fixed."
                );
            }
        }

        Command::Demo { port, lan, minutes, tls } => {
            // The same fixture the tests use: a paper PHP actually sealed, with
            // a comprehension group, a theory question and two candidates.
            const SEALED: &str = include_str!("../tests/fixtures/sealed-bundle.json");
            const KEY: &str = include_str!("../tests/fixtures/sealed-bundle.key");

            let sealed = bundle::SealedBundle::from_issue_response(SEALED)?;
            let mut opened = sealed.open(KEY.trim())?;

            // The fixture's deadline is a fixed date, which would make the
            // countdown either absurd or already expired. Move it so the clock
            // demonstrates the thing it exists to demonstrate.
            let deadline = (chrono::Local::now() + chrono::Duration::minutes(minutes))
                .to_rfc3339();

            for entry in opened.roster.iter_mut() {
                entry.server_deadline_at = Some(deadline.clone());
            }

            // In memory: a demo must not leave a school's lab machine holding a
            // SQLite file of invented children (§13 applies to fixtures too, if
            // only as a habit worth keeping).
            let store = Store::open_in_memory()?;
            store.save_bundle(&sealed)?;
            store.save_roster(&sealed.envelope.bundle_id, &opened.roster)?;

            let addr = SocketAddr::new(
                if lan { IpAddr::V4(Ipv4Addr::UNSPECIFIED) } else { IpAddr::V4(Ipv4Addr::LOCALHOST) },
                port.unwrap_or(config.port),
            );

            let scheme = if tls { "https" } else { "http" };

            println!("\n  Demo paper: \"{}\"", opened.exam.title);
            println!("  {} questions, {} minutes on the clock.\n", opened.questions.len(), minutes);
            println!("  Invigilator status window : {scheme}://{addr}/");
            println!("  Candidates sit the paper  : {scheme}://{addr}/sit\n");
            println!("  Sign in as one of:");

            for entry in &opened.roster {
                println!(
                    "    {:<14} code {}   ({})",
                    entry.admission_number.as_deref().unwrap_or("-"),
                    entry.relay_code.as_deref().unwrap_or("-"),
                    entry.candidate_name.as_deref().unwrap_or("-"),
                );
            }

            println!("\n  Nothing here talks to a backend. Ctrl-C to stop.\n");

            let state = Arc::new(server::AppState {
                store: Mutex::new(store),
                exam_title: opened.exam.title.clone(),
                bundle_id: sealed.envelope.bundle_id.clone(),
                paper: RwLock::new(Some(opened)),
            });

            if tls {
                let identity =
                    schoolpilot_relay::tls::RelayIdentity::load_or_create(&paths.home())?;

                println!("  Sit it in the kiosk window with:\n");
                println!("    relay candidate --relay https://{addr} \\");
                println!("      --fingerprint {}\n", identity.fingerprint);

                let config = schoolpilot_relay::tls::server_config(&identity).await?;
                server::serve_tls(Arc::clone(&state), addr, config).await?;
            } else {
                println!("  Sit it in the kiosk window with:\n");
                println!("    relay candidate --relay http://{addr}\n");

                server::serve(Arc::clone(&state), addr).await?;
            }

            state.close();
        }

        Command::Candidate { relay, fingerprint } => {
            // No store, no bundle, no token: a candidate machine holds none of
            // those and must not be able to. §3 is explicit that the paper lives
            // on one machine, not forty — this mode renders what the relay
            // serves and keeps nothing.
            let installed = paths.load_candidate()?;

            // Arguments win over what was installed, so a machine moved to a
            // spare relay mid-exam (§14) can be pointed at it without an
            // installer, but the ordinary case types nothing.
            let relay = relay
                .or_else(|| installed.as_ref().map(|c| c.relay_url.clone()))
                .ok_or_else(|| {
                    RelayError::NotConfigured(
                        "This machine has not been set up for exams. Run `relay init --relay \
                         <address> --fingerprint <value>` once, or pass --relay now."
                            .into(),
                    )
                })?;

            let fingerprint =
                fingerprint.or_else(|| installed.and_then(|c| c.fingerprint));

            kiosk::run(&relay, fingerprint.as_deref())?;
        }

        Command::Status => {
            let store = Store::open(&paths.database())?;

            match store.active_bundle()? {
                None => println!("No bundle provisioned."),
                Some(stored) => {
                    let counts = store.counts(&stored.bundle_id)?;

                    println!("Bundle    {}", stored.bundle_id);
                    println!("Exam      {}", stored.exam_id);
                    println!("Roster    {}", counts.candidates);
                    println!("Seated    {}", counts.seated);
                    println!("Submitted {}", counts.submitted);
                    println!("Answers   {} ({} awaiting sync)", counts.answers, counts.unsynced_answers);
                    println!("Events    {} ({} awaiting sync)", counts.events, counts.unsynced_events);
                }
            }
        }

        Command::Purge { force } => {
            let store = Store::open(&paths.database())?;
            let stored = store.active_bundle()?.ok_or(RelayError::NoBundle)?;
            let counts = store.counts(&stored.bundle_id)?;

            if counts.unsynced_answers > 0 && !force {
                println!(
                    "{} answers have not been confirmed by the server yet. Run `relay sync` \
                     first, or `relay purge --force` to delete them anyway.",
                    counts.unsynced_answers
                );
                return Ok(());
            }

            store.purge_bundle(&stored.bundle_id)?;

            // Media too: §13 wants the exam gone, not mostly gone.
            if paths.media_dir().exists() {
                std::fs::remove_dir_all(paths.media_dir())?;
                std::fs::create_dir_all(paths.media_dir())?;
            }

            println!("Exam data purged from this relay.");
        }
    }

    Ok(())
}
