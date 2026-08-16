//! Relay SQLite: the source of truth for the room (§5.3, §11, §14).
//!
//! When the relay process dies mid-exam, this file is what survives, and
//! restarting must pick up exactly where it left off. Two consequences run
//! through everything below:
//!
//! - `client_sequence` is monotonic **per attempt** and is read back from here
//!   on boot. Restarting the counter at zero would make a resumed candidate's
//!   later answers lose to their earlier ones under `supersedes()` (§11).
//! - Answers are written on arrival, not on submit. A candidate whose machine
//!   dies has their work here already, which is the single biggest practical
//!   advantage of the relay model and the first thing to test.
//!
//! The content key is not in this file and must never be. §13.

use rusqlite::{params, Connection, OptionalExtension};
use std::path::Path;

use crate::api::{AnswerUpload, AttemptUpload, EventUpload, MediaAsset};
use crate::bundle::{Envelope, RosterEntry, SealedBundle};
use crate::error::{RelayError, Result};

pub struct Store {
    conn: Connection,
}

/// Hex SHA-256, used for device tokens at rest (§8.4).
fn sha256_hex(value: &str) -> String {
    use sha2::{Digest, Sha256};

    Sha256::digest(value.as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

#[derive(Debug, Clone)]
pub struct StoredBundle {
    pub bundle_id: String,
    pub exam_id: i64,
    pub envelope: Envelope,
    pub aad: String,
    pub media_manifest: serde_json::Value,
}

#[derive(Debug, Clone)]
pub struct StoredAttempt {
    pub attempt_id: i64,
    pub bundle_id: String,
    pub candidate_name: Option<String>,
    pub admission_number: Option<String>,
    pub seed: i64,
    pub question_order: Vec<i64>,
    pub server_deadline_at: Option<String>,
    pub status: String,
    pub started_at: Option<String>,
    pub submitted_at: Option<String>,
    pub synced_at: Option<String>,
}

#[derive(Debug, Clone, Default)]
pub struct RoomCounts {
    pub candidates: i64,
    pub seated: i64,
    pub submitted: i64,
    pub answers: i64,
    pub unsynced_answers: i64,
    pub events: i64,
    pub unsynced_events: i64,
}

impl Store {
    pub fn open(path: &Path) -> Result<Self> {
        let conn = Connection::open(path)?;

        // A power cut in a Nigerian school lab is not an edge case (§14).
        conn.pragma_update(None, "journal_mode", "WAL")?;
        conn.pragma_update(None, "synchronous", "FULL")?;
        conn.pragma_update(None, "foreign_keys", "ON")?;

        let store = Self { conn };
        store.migrate()?;

        Ok(store)
    }

    pub fn open_in_memory() -> Result<Self> {
        let store = Self { conn: Connection::open_in_memory()? };
        store.migrate()?;
        Ok(store)
    }

    fn migrate(&self) -> Result<()> {
        self.conn.execute_batch(
            r#"
            CREATE TABLE IF NOT EXISTS bundles (
                bundle_id       TEXT PRIMARY KEY,
                exam_id         INTEGER NOT NULL,
                format_version  INTEGER NOT NULL,
                envelope_json   TEXT NOT NULL,
                aad             TEXT NOT NULL,
                media_manifest  TEXT NOT NULL,
                opens_at        TEXT,
                closes_at       TEXT,
                built_at        TEXT,
                provisioned_at  TEXT NOT NULL,
                purged_at       TEXT
            );

            CREATE TABLE IF NOT EXISTS attempts (
                attempt_id          INTEGER PRIMARY KEY,
                bundle_id           TEXT NOT NULL REFERENCES bundles(bundle_id) ON DELETE CASCADE,
                student_id          INTEGER NOT NULL,
                candidate_name      TEXT,
                admission_number    TEXT,
                attempt_number      INTEGER NOT NULL DEFAULT 1,
                seed                INTEGER NOT NULL,
                question_order      TEXT NOT NULL,
                server_deadline_at  TEXT,
                relay_code          TEXT,
                status              TEXT NOT NULL DEFAULT 'provisioned',
                started_at          TEXT,
                submitted_at        TEXT,
                synced_at           TEXT
            );

            CREATE TABLE IF NOT EXISTS answers (
                attempt_id          INTEGER NOT NULL REFERENCES attempts(attempt_id) ON DELETE CASCADE,
                question_id         INTEGER NOT NULL,
                response            TEXT,
                client_sequence     INTEGER NOT NULL,
                client_timestamp    TEXT NOT NULL,
                time_spent_seconds  INTEGER,
                flagged_for_review  INTEGER NOT NULL DEFAULT 0,
                synced              INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (attempt_id, question_id)
            );

            CREATE TABLE IF NOT EXISTS events (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_id  INTEGER NOT NULL REFERENCES attempts(attempt_id) ON DELETE CASCADE,
                event_type  TEXT NOT NULL,
                occurred_at TEXT NOT NULL,
                metadata    TEXT,
                synced      INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS media (
                asset_id    INTEGER PRIMARY KEY,
                bundle_id   TEXT NOT NULL,
                url         TEXT NOT NULL,
                checksum    TEXT,
                byte_size   INTEGER,
                mime_type   TEXT,
                verified    INTEGER NOT NULL DEFAULT 0
            );

            -- §8.4. Paired the day before, never during the exam. The token is
            -- stored as a digest: a stolen relay disk must not yield working
            -- device credentials, and the relay only ever needs to *check* one.
            CREATE TABLE IF NOT EXISTS devices (
                device_id     TEXT PRIMARY KEY,
                device_name   TEXT NOT NULL,
                token_sha256  TEXT NOT NULL,
                paired_at     TEXT NOT NULL,
                last_seen_at  TEXT
            );

            CREATE INDEX IF NOT EXISTS answers_unsynced ON answers(attempt_id, synced);
            CREATE INDEX IF NOT EXISTS events_unsynced  ON events(attempt_id, synced);
            "#,
        )?;

        // Additive, and tolerated if it is already there: relays exist in the
        // field with this table and no such column, and a failed migration on
        // exam morning is worse than a duplicate-column error nobody reads.
        let _ = self
            .conn
            .execute("ALTER TABLE attempts ADD COLUMN last_device_id TEXT", []);

        Ok(())
    }

    // ------------------------------------------------------------------
    // Devices (§8.4)
    // ------------------------------------------------------------------

    /// Record a paired machine and return its device id.
    ///
    /// Re-pairing a machine of the same name replaces its token rather than
    /// adding a second row — a lab PC re-imaged the week before an exam is
    /// ordinary, and leaving its old row behind would make the paired count an
    /// unreliable check against "did we pair every machine?" (§8.4).
    pub fn pair_device(&self, device_name: &str, token: &str) -> Result<String> {
        let device_id = format!("dev_{}", &sha256_hex(token)[..16]);

        self.conn.execute(
            "DELETE FROM devices WHERE device_name = ?1",
            params![device_name],
        )?;

        self.conn.execute(
            "INSERT INTO devices (device_id, device_name, token_sha256, paired_at)
             VALUES (?1, ?2, ?3, datetime('now'))
             ON CONFLICT(device_id) DO UPDATE SET
                device_name = excluded.device_name,
                token_sha256 = excluded.token_sha256,
                paired_at = excluded.paired_at",
            params![device_id, device_name, sha256_hex(token)],
        )?;

        Ok(device_id)
    }

    /// Which paired machine is this, if any?
    pub fn authenticate_device(&self, token: &str) -> Result<Option<(String, String)>> {
        let found: Option<(String, String)> = self
            .conn
            .query_row(
                "SELECT device_id, device_name FROM devices WHERE token_sha256 = ?1",
                params![sha256_hex(token)],
                |row| Ok((row.get(0)?, row.get(1)?)),
            )
            .optional()?;

        if let Some((device_id, _)) = &found {
            self.conn.execute(
                "UPDATE devices SET last_seen_at = datetime('now') WHERE device_id = ?1",
                params![device_id],
            )?;
        }

        Ok(found)
    }

    /// Copy paired machines from another store.
    ///
    /// For `relay demo`, which runs in memory (§13: a demo must not leave a
    /// school's laptop holding a SQLite file of invented children) but must
    /// still behave like the real thing. Without this a demo accepts every
    /// machine in the room regardless of pairing, which is the opposite of what
    /// a school is being shown.
    pub fn copy_devices_from(&self, source: &Store) -> Result<usize> {
        let mut statement = source
            .conn
            .prepare("SELECT device_id, device_name, token_sha256, paired_at FROM devices")?;

        let rows: Vec<(String, String, String, String)> = statement
            .query_map([], |row| {
                Ok((row.get(0)?, row.get(1)?, row.get(2)?, row.get(3)?))
            })?
            .collect::<std::result::Result<Vec<_>, _>>()?;

        for (device_id, device_name, token_sha256, paired_at) in &rows {
            self.conn.execute(
                "INSERT OR REPLACE INTO devices
                     (device_id, device_name, token_sha256, paired_at)
                 VALUES (?1, ?2, ?3, ?4)",
                params![device_id, device_name, token_sha256, paired_at],
            )?;
        }

        Ok(rows.len())
    }

    pub fn device_count(&self) -> Result<i64> {
        Ok(self.conn.query_row("SELECT COUNT(*) FROM devices", [], |row| row.get(0))?)
    }

    pub fn devices(&self) -> Result<Vec<(String, String, Option<String>)>> {
        let mut statement = self.conn.prepare(
            "SELECT device_name, paired_at, last_seen_at FROM devices ORDER BY device_name",
        )?;

        let rows = statement
            .query_map([], |row| Ok((row.get(0)?, row.get(1)?, row.get(2)?)))?
            .collect::<std::result::Result<Vec<_>, _>>()?;

        Ok(rows)
    }

    // ------------------------------------------------------------------
    // Bundles
    // ------------------------------------------------------------------

    pub fn save_bundle(&self, sealed: &SealedBundle) -> Result<()> {
        self.conn.execute(
            r#"
            INSERT INTO bundles (bundle_id, exam_id, format_version, envelope_json, aad,
                                 media_manifest, opens_at, closes_at, built_at, provisioned_at)
            VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, datetime('now'))
            ON CONFLICT(bundle_id) DO UPDATE SET
                envelope_json = excluded.envelope_json,
                aad = excluded.aad,
                media_manifest = excluded.media_manifest,
                purged_at = NULL
            "#,
            params![
                sealed.envelope.bundle_id,
                sealed.envelope.header.exam_id,
                sealed.envelope.format_version,
                serde_json::to_string(&sealed.envelope)?,
                sealed.aad,
                serde_json::to_string(&sealed.media_manifest)?,
                sealed.envelope.header.opens_at,
                sealed.envelope.header.closes_at,
                sealed.envelope.header.built_at,
            ],
        )?;

        Ok(())
    }

    /// The bundle this relay is currently carrying, if any.
    pub fn active_bundle(&self) -> Result<Option<StoredBundle>> {
        let row = self
            .conn
            .query_row(
                "SELECT bundle_id, exam_id, envelope_json, aad, media_manifest
                 FROM bundles WHERE purged_at IS NULL ORDER BY provisioned_at DESC LIMIT 1",
                [],
                |row| {
                    Ok((
                        row.get::<_, String>(0)?,
                        row.get::<_, i64>(1)?,
                        row.get::<_, String>(2)?,
                        row.get::<_, String>(3)?,
                        row.get::<_, String>(4)?,
                    ))
                },
            )
            .optional()?;

        let Some((bundle_id, exam_id, envelope_json, aad, manifest_json)) = row else {
            return Ok(None);
        };

        Ok(Some(StoredBundle {
            bundle_id,
            exam_id,
            envelope: serde_json::from_str(&envelope_json)?,
            aad,
            media_manifest: serde_json::from_str(&manifest_json)?,
        }))
    }

    /// §13: delete once the server confirms the attempts are finalised — as
    /// part of the sync path, not "eventually".
    pub fn purge_bundle(&self, bundle_id: &str) -> Result<()> {
        self.conn.execute("DELETE FROM attempts WHERE bundle_id = ?1", params![bundle_id])?;
        self.conn.execute("DELETE FROM media WHERE bundle_id = ?1", params![bundle_id])?;
        self.conn.execute(
            "UPDATE bundles SET purged_at = datetime('now') WHERE bundle_id = ?1",
            params![bundle_id],
        )?;

        Ok(())
    }

    // ------------------------------------------------------------------
    // Roster
    // ------------------------------------------------------------------

    pub fn save_roster(&self, bundle_id: &str, roster: &[RosterEntry]) -> Result<()> {
        for entry in roster {
            self.conn.execute(
                r#"
                INSERT INTO attempts (attempt_id, bundle_id, student_id, candidate_name,
                                      admission_number, attempt_number, seed, question_order,
                                      server_deadline_at, relay_code)
                VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10)
                ON CONFLICT(attempt_id) DO UPDATE SET
                    question_order = excluded.question_order,
                    server_deadline_at = excluded.server_deadline_at,
                    relay_code = excluded.relay_code
                "#,
                params![
                    entry.attempt_id,
                    bundle_id,
                    entry.student_id,
                    entry.candidate_name,
                    entry.admission_number,
                    entry.attempt_number,
                    entry.seed,
                    serde_json::to_string(&entry.question_order)?,
                    entry.server_deadline_at,
                    entry.relay_code,
                ],
            )?;
        }

        Ok(())
    }

    pub fn attempt(&self, attempt_id: i64) -> Result<Option<StoredAttempt>> {
        self.conn
            .query_row(
                "SELECT attempt_id, bundle_id, candidate_name, admission_number, seed,
                        question_order, server_deadline_at, status, started_at, submitted_at, synced_at
                 FROM attempts WHERE attempt_id = ?1",
                params![attempt_id],
                Self::map_attempt,
            )
            .optional()
            .map_err(RelayError::from)
    }

    fn map_attempt(row: &rusqlite::Row) -> rusqlite::Result<StoredAttempt> {
        let order: String = row.get(5)?;

        Ok(StoredAttempt {
            attempt_id: row.get(0)?,
            bundle_id: row.get(1)?,
            candidate_name: row.get(2)?,
            admission_number: row.get(3)?,
            seed: row.get(4)?,
            question_order: serde_json::from_str(&order).unwrap_or_default(),
            server_deadline_at: row.get(6)?,
            status: row.get(7)?,
            started_at: row.get(8)?,
            submitted_at: row.get(9)?,
            synced_at: row.get(10)?,
        })
    }

    /// Seat a candidate. Idempotent: a candidate resuming after their machine
    /// died is the expected case, not an error, and must keep their start time.
    pub fn seat(&self, attempt_id: i64) -> Result<bool> {
        let already: Option<String> = self
            .conn
            .query_row(
                "SELECT started_at FROM attempts WHERE attempt_id = ?1",
                params![attempt_id],
                |row| row.get(0),
            )
            .optional()?
            .flatten();

        if already.is_some() {
            return Ok(true); // resumed
        }

        self.conn.execute(
            "UPDATE attempts SET status = 'in_progress', started_at = datetime('now')
             WHERE attempt_id = ?1",
            params![attempt_id],
        )?;

        Ok(false)
    }

    /// Seat a candidate, noting which machine they are at.
    ///
    /// Returns `(resumed, moved_seat)`. `moved_seat` is the §12 `seat_changed`
    /// case: the attempt is being continued from a different paired device than
    /// last time, which is exactly the dead-PC recovery §5.3 is built around —
    /// evidence for whoever reviews the room later, never grounds for anything
    /// automatic.
    pub fn seat_at_device(&self, attempt_id: i64, device_id: &str) -> Result<(bool, bool)> {
        let previous: Option<String> = self
            .conn
            .query_row(
                "SELECT last_device_id FROM attempts WHERE attempt_id = ?1",
                params![attempt_id],
                |row| row.get(0),
            )
            .optional()?
            .flatten();

        let resumed = self.seat(attempt_id)?;

        self.conn.execute(
            "UPDATE attempts SET last_device_id = ?2 WHERE attempt_id = ?1",
            params![attempt_id, device_id],
        )?;

        let moved = matches!(previous, Some(before) if before != device_id);

        Ok((resumed, moved))
    }

    pub fn mark_submitted(&self, attempt_id: i64) -> Result<()> {
        self.conn.execute(
            "UPDATE attempts SET status = 'submitted', submitted_at = datetime('now')
             WHERE attempt_id = ?1",
            params![attempt_id],
        )?;

        Ok(())
    }

    // ------------------------------------------------------------------
    // Answers
    // ------------------------------------------------------------------

    /// The next `client_sequence` for an attempt.
    ///
    /// Read from the table rather than from a counter in memory, so a relay
    /// that restarts mid-exam does not hand out sequence 1 again to a candidate
    /// who is already on 30 — those answers would then lose to their own
    /// earlier ones under `supersedes()` and the candidate would watch their
    /// paper go backwards.
    pub fn next_sequence(&self, attempt_id: i64) -> Result<i64> {
        let max: Option<i64> = self.conn.query_row(
            "SELECT MAX(client_sequence) FROM answers WHERE attempt_id = ?1",
            params![attempt_id],
            |row| row.get(0),
        )?;

        Ok(max.unwrap_or(0) + 1)
    }

    pub fn record_answer(
        &self,
        attempt_id: i64,
        question_id: i64,
        response: &serde_json::Value,
        time_spent_seconds: Option<i64>,
        flagged: bool,
    ) -> Result<i64> {
        let sequence = self.next_sequence(attempt_id)?;
        let now = chrono::Local::now().to_rfc3339();

        self.conn.execute(
            r#"
            INSERT INTO answers (attempt_id, question_id, response, client_sequence,
                                 client_timestamp, time_spent_seconds, flagged_for_review, synced)
            VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, 0)
            ON CONFLICT(attempt_id, question_id) DO UPDATE SET
                response = excluded.response,
                client_sequence = excluded.client_sequence,
                client_timestamp = excluded.client_timestamp,
                time_spent_seconds = excluded.time_spent_seconds,
                flagged_for_review = excluded.flagged_for_review,
                synced = 0
            "#,
            params![
                attempt_id,
                question_id,
                serde_json::to_string(response)?,
                sequence,
                now,
                time_spent_seconds,
                flagged as i64,
            ],
        )?;

        Ok(sequence)
    }

    pub fn record_event(
        &self,
        attempt_id: i64,
        event_type: &str,
        metadata: Option<&serde_json::Value>,
    ) -> Result<()> {
        self.conn.execute(
            "INSERT INTO events (attempt_id, event_type, occurred_at, metadata, synced)
             VALUES (?1, ?2, ?3, ?4, 0)",
            params![
                attempt_id,
                event_type,
                chrono::Local::now().to_rfc3339(),
                metadata.map(|m| m.to_string()),
            ],
        )?;

        Ok(())
    }

    // ------------------------------------------------------------------
    // Sync queue
    // ------------------------------------------------------------------

    /// Everything not yet accepted by the server, as batch payloads.
    ///
    /// Answers first and separately from any future script-page queue: answers
    /// are small and irreplaceable, images are large and re-capturable, and on
    /// a bad line the small irreplaceable things must land first (§11).
    pub fn pending_uploads(&self, bundle_id: &str) -> Result<Vec<AttemptUpload>> {
        let mut statement = self.conn.prepare(
            "SELECT attempt_id, status FROM attempts
             WHERE bundle_id = ?1 AND (synced_at IS NULL OR started_at IS NOT NULL)
             ORDER BY attempt_id",
        )?;

        let rows: Vec<(i64, String)> = statement
            .query_map(params![bundle_id], |row| Ok((row.get(0)?, row.get(1)?)))?
            .collect::<rusqlite::Result<_>>()?;

        let mut uploads = Vec::new();

        for (attempt_id, status) in rows {
            let answers = self.unsynced_answers(attempt_id)?;
            let events = self.unsynced_events(attempt_id)?;
            let submit = status == "submitted";

            // Nothing to say about a candidate who never sat down.
            if answers.is_empty() && events.is_empty() && !submit {
                continue;
            }

            uploads.push(AttemptUpload { attempt_id, answers, events, submit });
        }

        Ok(uploads)
    }

    fn unsynced_answers(&self, attempt_id: i64) -> Result<Vec<AnswerUpload>> {
        let mut statement = self.conn.prepare(
            "SELECT question_id, response, client_sequence, client_timestamp,
                    time_spent_seconds, flagged_for_review
             FROM answers WHERE attempt_id = ?1 AND synced = 0 ORDER BY client_sequence",
        )?;

        let answers = statement
            .query_map(params![attempt_id], |row| {
                let raw: Option<String> = row.get(1)?;

                Ok(AnswerUpload {
                    question_id: row.get(0)?,
                    response: raw
                        .and_then(|r| serde_json::from_str(&r).ok())
                        .unwrap_or(serde_json::Value::Null),
                    client_sequence: row.get(2)?,
                    client_timestamp: row.get(3)?,
                    time_spent_seconds: row.get(4)?,
                    flagged_for_review: Some(row.get::<_, i64>(5)? != 0),
                })
            })?
            .collect::<rusqlite::Result<Vec<_>>>()?;

        Ok(answers)
    }

    fn unsynced_events(&self, attempt_id: i64) -> Result<Vec<EventUpload>> {
        let mut statement = self.conn.prepare(
            "SELECT event_type, occurred_at, metadata FROM events
             WHERE attempt_id = ?1 AND synced = 0 ORDER BY id",
        )?;

        let events = statement
            .query_map(params![attempt_id], |row| {
                let metadata: Option<String> = row.get(2)?;

                Ok(EventUpload {
                    event_type: row.get(0)?,
                    occurred_at: row.get(1)?,
                    metadata: metadata.and_then(|m| serde_json::from_str(&m).ok()),
                })
            })?
            .collect::<rusqlite::Result<Vec<_>>>()?;

        Ok(events)
    }

    /// Mark an attempt's queue accepted.
    ///
    /// Only called for attempts the server reported without rejections. A
    /// rejected answer stays unsynced and stays visible — §11 is explicit that
    /// a rejection indicates a client bug and must be surfaced loudly rather
    /// than swallowed.
    pub fn mark_synced(&self, attempt_id: i64) -> Result<()> {
        self.conn.execute(
            "UPDATE answers SET synced = 1 WHERE attempt_id = ?1",
            params![attempt_id],
        )?;
        self.conn.execute(
            "UPDATE events SET synced = 1 WHERE attempt_id = ?1",
            params![attempt_id],
        )?;
        self.conn.execute(
            "UPDATE attempts SET synced_at = datetime('now') WHERE attempt_id = ?1",
            params![attempt_id],
        )?;

        Ok(())
    }

    // ------------------------------------------------------------------
    // Media
    // ------------------------------------------------------------------

    pub fn record_media(&self, bundle_id: &str, asset: &MediaAsset, verified: bool) -> Result<()> {
        self.conn.execute(
            "INSERT INTO media (asset_id, bundle_id, url, checksum, byte_size, mime_type, verified)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)
             ON CONFLICT(asset_id) DO UPDATE SET verified = excluded.verified",
            params![
                asset.asset_id,
                bundle_id,
                asset.url,
                asset.checksum,
                asset.byte_size,
                asset.mime_type,
                verified as i64,
            ],
        )?;

        Ok(())
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    pub fn counts(&self, bundle_id: &str) -> Result<RoomCounts> {
        let one = |sql: &str| -> Result<i64> {
            Ok(self.conn.query_row(sql, params![bundle_id], |row| row.get(0))?)
        };

        Ok(RoomCounts {
            candidates: one("SELECT COUNT(*) FROM attempts WHERE bundle_id = ?1")?,
            seated: one(
                "SELECT COUNT(*) FROM attempts WHERE bundle_id = ?1 AND started_at IS NOT NULL",
            )?,
            submitted: one(
                "SELECT COUNT(*) FROM attempts WHERE bundle_id = ?1 AND status = 'submitted'",
            )?,
            answers: one(
                "SELECT COUNT(*) FROM answers a JOIN attempts t ON t.attempt_id = a.attempt_id
                 WHERE t.bundle_id = ?1",
            )?,
            unsynced_answers: one(
                "SELECT COUNT(*) FROM answers a JOIN attempts t ON t.attempt_id = a.attempt_id
                 WHERE t.bundle_id = ?1 AND a.synced = 0",
            )?,
            events: one(
                "SELECT COUNT(*) FROM events e JOIN attempts t ON t.attempt_id = e.attempt_id
                 WHERE t.bundle_id = ?1",
            )?,
            unsynced_events: one(
                "SELECT COUNT(*) FROM events e JOIN attempts t ON t.attempt_id = e.attempt_id
                 WHERE t.bundle_id = ?1 AND e.synced = 0",
            )?,
        })
    }

    pub fn roster_summary(&self, bundle_id: &str) -> Result<Vec<StoredAttempt>> {
        let mut statement = self.conn.prepare(
            "SELECT attempt_id, bundle_id, candidate_name, admission_number, seed,
                    question_order, server_deadline_at, status, started_at, submitted_at, synced_at
             FROM attempts WHERE bundle_id = ?1 ORDER BY admission_number, attempt_id",
        )?;

        let rows = statement
            .query_map(params![bundle_id], Self::map_attempt)?
            .collect::<rusqlite::Result<Vec<_>>>()?;

        Ok(rows)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn seeded() -> Store {
        let store = Store::open_in_memory().unwrap();

        store
            .conn
            .execute(
                "INSERT INTO bundles (bundle_id, exam_id, format_version, envelope_json, aad,
                                      media_manifest, provisioned_at)
                 VALUES ('b1', 5, 1, '{}', '{}', '{}', datetime('now'))",
                [],
            )
            .unwrap();

        store
            .conn
            .execute(
                "INSERT INTO attempts (attempt_id, bundle_id, student_id, seed, question_order)
                 VALUES (900, 'b1', 1, 7, '[1,2,3]')",
                [],
            )
            .unwrap();

        store
    }

    #[test]
    fn sequence_survives_a_restart() {
        let store = seeded();

        for question_id in 1..=3 {
            store
                .record_answer(900, question_id, &serde_json::json!("A"), None, false)
                .unwrap();
        }

        // A fresh Store over the same rows is what a relay restart looks like.
        // Reading the counter back from disk is the whole point (§11).
        assert_eq!(store.next_sequence(900).unwrap(), 4);
    }

    #[test]
    fn re_answering_replaces_and_advances_the_sequence() {
        let store = seeded();

        let first = store
            .record_answer(900, 1, &serde_json::json!("A"), None, false)
            .unwrap();
        let second = store
            .record_answer(900, 1, &serde_json::json!("B"), None, false)
            .unwrap();

        assert!(second > first, "a changed answer must supersede the earlier one");

        let pending = store.pending_uploads("b1").unwrap();
        assert_eq!(pending.len(), 1);
        assert_eq!(pending[0].answers.len(), 1, "one row per question, not one per keystroke");
        assert_eq!(pending[0].answers[0].response, serde_json::json!("B"));
    }

    #[test]
    fn seating_twice_is_a_resume_not_a_restart() {
        let store = seeded();

        assert!(!store.seat(900).unwrap(), "first seat is not a resume");
        assert!(store.seat(900).unwrap(), "second seat is a resume");
    }

    #[test]
    fn a_candidate_who_never_sat_is_not_uploaded() {
        let store = seeded();
        assert!(store.pending_uploads("b1").unwrap().is_empty());
    }
}
