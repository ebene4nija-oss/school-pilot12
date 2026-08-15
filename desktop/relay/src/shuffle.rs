//! The determinism contract (docs/offline-cbt-client.md §10).
//!
//! This is the second implementation of an ordering the server already owns,
//! and the document is blunt about what that costs: two implementations of the
//! same ordering must never diverge. `tests/parity.rs` asserts this module
//! against `backend/tests/fixtures/cbt-shuffle-parity.json`, the same vectors
//! `CbtShuffleParityTest` asserts on the PHP side.
//!
//! Why a hand-rolled LCG rather than a real RNG: `CbtExamService::seededShuffle`
//! documents that `mt_srand()` was rejected because PHP changed its Mt19937
//! implementation once already, which would silently re-order a resumed
//! candidate's paper after a server upgrade. MINSTD is specified arithmetic —
//! it cannot drift underneath either side.
//!
//! **Do not "improve" anything here.** A better shuffle is a wrong shuffle.

/// MINSTD: `state = state * 16807 mod 2147483647`.
const MULTIPLIER: i64 = 16807;
const MODULUS: i64 = 2147483647;

/// Seeded Fisher–Yates, descending, matching `CbtExamService::seededShuffle`.
///
/// The seed normalisation mirrors PHP exactly, including the `<= 0` branch:
/// a seed that reduces to zero would otherwise stick the LCG at zero forever
/// and hand every candidate the same "shuffled" paper.
pub fn seeded_shuffle<T: Clone>(items: &[T], seed: i64) -> Vec<T> {
    let mut values = items.to_vec();
    let mut state = seed % MODULUS;

    if state <= 0 {
        state += MODULUS - 1;
    }

    let n = values.len();
    if n < 2 {
        return values;
    }

    for i in (1..n).rev() {
        state = (state * MULTIPLIER) % MODULUS;
        let j = (state % (i as i64 + 1)) as usize;
        values.swap(i, j);
    }

    values
}

/// One question of the paper as the ordering rules see it: an id, and the group
/// it belongs to if any.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct AttachedQuestion {
    pub id: i64,
    pub group_id: Option<i64>,
    pub group_sequence: Option<i64>,
}

/// The exam settings that affect ordering, and nothing else.
#[derive(Debug, Clone, Copy, Default)]
pub struct OrderingSettings {
    pub shuffle_questions: bool,
    pub shuffle_within_group: bool,
    pub questions_per_attempt: Option<i64>,
}

/// An indivisible unit of the paper: one group, or one ungrouped question.
#[derive(Debug, Clone, PartialEq, Eq)]
struct Unit {
    group_id: Option<i64>,
    ids: Vec<i64>,
}

/// Reproduces `CbtExamService::composePaper`.
///
/// The relay does not normally call this: §10 says to prefer the server's
/// stored `question_order`, which travels pre-issued in the bundle roster, and
/// `paper::for_attempt` does exactly that. This exists so the parity test can
/// prove the rules are reproducible from the specification — which is the only
/// evidence that the two sides will still agree next year.
pub fn compose_paper(
    questions: &[AttachedQuestion],
    settings: OrderingSettings,
    seed: i64,
) -> Vec<i64> {
    let units = paper_units(questions);

    if units.is_empty() {
        return Vec::new();
    }

    let mut units = draw_units(units, settings.questions_per_attempt, seed);

    if settings.shuffle_questions {
        units = seeded_shuffle(&units, seed);
    }

    if settings.shuffle_within_group {
        units = units
            .into_iter()
            .map(|unit| match unit.group_id {
                // Seeded per group so shuffling group 3 does not depend on how
                // many groups came before it.
                Some(group_id) if unit.ids.len() > 1 => Unit {
                    group_id: Some(group_id),
                    ids: seeded_shuffle(&unit.ids, seed + group_id),
                },
                _ => unit,
            })
            .collect();
    }

    units.into_iter().flat_map(|unit| unit.ids).collect()
}

/// Group questions into units, a group taking the position of its earliest
/// member so the author's `order_index` still decides where a passage sits.
fn paper_units(questions: &[AttachedQuestion]) -> Vec<Unit> {
    let mut units: Vec<Unit> = Vec::new();
    let mut group_index: Vec<(i64, usize)> = Vec::new();

    for question in questions {
        let Some(group_id) = question.group_id else {
            units.push(Unit { group_id: None, ids: vec![question.id] });
            continue;
        };

        match group_index.iter().find(|(id, _)| *id == group_id) {
            Some((_, index)) => units[*index].ids.push(question.id),
            None => {
                group_index.push((group_id, units.len()));
                units.push(Unit { group_id: Some(group_id), ids: vec![question.id] });
            }
        }
    }

    // Within a group the authored sequence wins; questions with no sequence
    // trail behind in id order rather than landing arbitrarily.
    let sequence_of = |id: i64| {
        questions
            .iter()
            .find(|q| q.id == id)
            .and_then(|q| q.group_sequence)
    };

    for unit in units.iter_mut() {
        if unit.group_id.is_none() || unit.ids.len() < 2 {
            continue;
        }

        unit.ids.sort_by(|a, b| match (sequence_of(*a), sequence_of(*b)) {
            (None, None) => a.cmp(b),
            (None, Some(_)) => std::cmp::Ordering::Greater,
            (Some(_), None) => std::cmp::Ordering::Less,
            (Some(sa), Some(sb)) => sa.cmp(&sb).then(a.cmp(b)),
        });
    }

    units
}

/// Draw `questions_per_attempt` by whole unit, overshooting to the nearest
/// group boundary rather than splitting a passage (§6.6).
fn draw_units(units: Vec<Unit>, target: Option<i64>, seed: i64) -> Vec<Unit> {
    let target = target.unwrap_or(0);
    let total: i64 = units.iter().map(|u| u.ids.len() as i64).sum();

    if target <= 0 || target >= total {
        return units;
    }

    let indices: Vec<usize> = (0..units.len()).collect();
    let order = seeded_shuffle(&indices, seed);

    let mut chosen: Vec<usize> = Vec::new();
    let mut count: i64 = 0;

    for index in order {
        if count >= target {
            break;
        }
        chosen.push(index);
        count += units[index].ids.len() as i64;
    }

    // Restore authored order among the chosen units; the shuffle step owns
    // presentation order, this step only owns selection.
    chosen.sort_unstable();

    chosen.into_iter().map(|index| units[index].clone()).collect()
}

/// Option ordering, matching `CbtExamService::presentOptions`.
///
/// The effective seed is `attempt.seed + question_id` so shuffling question 7's
/// options does not depend on how many questions came before it.
pub fn shuffle_options<T: Clone>(options: &[T], attempt_seed: i64, question_id: i64) -> Vec<T> {
    seeded_shuffle(options, attempt_seed + question_id)
}

/// `ordering` and `matching` questions must not have their options shuffled —
/// for those the order *is* the answer.
pub fn options_are_shuffleable(question_type: &str) -> bool {
    !matches!(question_type, "ordering" | "matching")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_seed_that_reduces_to_zero_still_shuffles() {
        // MODULUS as a seed normalises to 0, which the `<= 0` branch rescues.
        // Without it the LCG sticks at zero and every candidate gets one order.
        let items: Vec<i64> = (0..10).collect();
        assert_ne!(seeded_shuffle(&items, MODULUS), items);
    }

    #[test]
    fn shuffling_is_a_permutation_not_a_rewrite() {
        let items: Vec<i64> = (0..40).collect();
        let mut shuffled = seeded_shuffle(&items, 12345);
        shuffled.sort_unstable();
        assert_eq!(shuffled, items);
    }

    #[test]
    fn short_lists_are_returned_untouched() {
        assert_eq!(seeded_shuffle::<i64>(&[], 7), Vec::<i64>::new());
        assert_eq!(seeded_shuffle(&[42i64], 7), vec![42]);
    }
}
