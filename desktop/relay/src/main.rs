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
use schoolpilot_relay::{bundle, server, sync};

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
        /// Bind beyond loopback. Refused by default while transport security is
        /// unfinished (§8.3) — a half-built relay must not quietly serve a real
        /// exam over a LAN in the clear.
        #[arg(long)]
        lan: bool,
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
        Command::Login { base_url, email, password, relay_identity } => {
            if let Some(url) = base_url {
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

            store.save_bundle(&sealed)?;

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

            if lan {
                println!(
                    "\n  WARNING: serving on the LAN in plain HTTP. §8.3 requires pinned TLS \
                     before a real paper runs on this. Use for testing only.\n"
                );
            }

            let state = Arc::new(server::AppState {
                store: Mutex::new(store),
                exam_title: opened.exam.title.clone(),
                bundle_id: stored.bundle_id.clone(),
                paper: RwLock::new(Some(opened)),
            });

            println!("Status window: http://{addr}/\nPress Ctrl-C to close the paper.");

            server::serve(Arc::clone(&state), addr).await?;

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

        Command::Demo { port, lan, minutes } => {
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

            println!("\n  Demo paper: \"{}\"", opened.exam.title);
            println!("  {} questions, {} minutes on the clock.\n", opened.questions.len(), minutes);
            println!("  Invigilator status window : http://{addr}/");
            println!("  Candidates sit the paper  : http://{addr}/sit\n");
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

            server::serve(Arc::clone(&state), addr).await?;
            state.close();
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
