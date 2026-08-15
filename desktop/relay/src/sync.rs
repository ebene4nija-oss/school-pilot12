//! Uploading the room after the exam (§5.5, §11).
//!
//! Nothing here is clever, and that is deliberate: conflict resolution lives
//! server-side in `CbtExamService::supersedes()`, and the relay's only job is
//! to feed it correctly. Re-sending an already-synced answer is expected and
//! safe — the server counts it as `ignored`, not applied twice.

use crate::api::{Api, SyncResponse};
use crate::error::Result;
use crate::store::Store;

/// What one sync pass did, in the words an exam officer needs.
#[derive(Debug, Clone, Default)]
pub struct SyncReport {
    pub attempts: i64,
    pub saved: i64,
    pub ignored: i64,
    pub rejected: i64,
    pub finalised: i64,
    pub events: i64,
    /// Attempts the server would not take, with its reason. Surfaced rather
    /// than swallowed: §11 says a rejection indicates a client bug, and one
    /// that nobody sees is a child's answers quietly not arriving.
    pub problems: Vec<(i64, String)>,
    pub nothing_to_do: bool,
}

impl SyncReport {
    /// The sentence §11 asks for, verbatim in shape: "synced 400 answers,
    /// ignored 12, rejected 0."
    pub fn headline(&self) -> String {
        if self.nothing_to_do {
            return "Nothing to sync — every attempt on this bundle is already uploaded.".into();
        }

        format!(
            "Synced {} answers across {} attempts, ignored {}, rejected {}. {} finalised, {} integrity events.",
            self.saved, self.attempts, self.ignored, self.rejected, self.finalised, self.events
        )
    }
}

/// Walk the queue and upload it.
///
/// Chunked so that one enormous room does not become one enormous request that
/// times out and takes everything with it. §11 wants essays in their own chunk
/// for the same reason; with only objective answers in step 3 the chunk size is
/// the whole of that mechanism, and the split by payload shape comes with the
/// candidate client.
pub async fn run(api: &Api, store: &Store, bundle_id: &str, chunk: usize) -> Result<SyncReport> {
    let pending = store.pending_uploads(bundle_id)?;

    if pending.is_empty() {
        return Ok(SyncReport { nothing_to_do: true, ..Default::default() });
    }

    let mut report = SyncReport::default();

    for batch in pending.chunks(chunk.max(1)) {
        let response: SyncResponse = api.batch_sync(bundle_id, batch).await?;

        report.attempts += response.summary.attempts;
        report.saved += response.summary.saved;
        report.ignored += response.summary.ignored;
        report.rejected += response.summary.rejected;
        report.finalised += response.summary.finalised;
        report.events += response.summary.events;

        for result in response.attempts {
            if result.rejected.is_empty() {
                // Only a clean attempt is marked synced. Anything rejected stays
                // in the queue so a retry after the bug is fixed still has it.
                store.mark_synced(result.attempt_id)?;
                continue;
            }

            for reason in result.rejected {
                report.problems.push((result.attempt_id, reason));
            }
        }
    }

    Ok(report)
}
