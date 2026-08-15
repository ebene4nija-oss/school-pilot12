//! The parity test (docs/offline-cbt-client.md §10).
//!
//! These are the same vectors `backend/tests/Feature/CbtShuffleParityTest.php`
//! asserts, read from the same committed file. If the two sides ever disagree,
//! a resumed or re-synced candidate silently sits a different paper from the
//! one they were served — which is the failure this test exists to make
//! impossible to ship.
//!
//! **Do not regenerate the fixture to make a failing test pass.** It has
//! already caught one real defect on the PHP side (a `shuffle_within_group`
//! setting silently dropped because the column was not on the model), which is
//! the entire argument for it. A failure here means one of the two
//! implementations drifted; find out which.

use schoolpilot_relay::shuffle::{
    compose_paper, seeded_shuffle, shuffle_options, AttachedQuestion, OrderingSettings,
};
use serde::Deserialize;
use std::path::PathBuf;

#[derive(Debug, Deserialize)]
struct Fixture {
    attached_questions: Vec<FixtureQuestion>,
    seeded_shuffle: Vec<ShuffleVector>,
    option_shuffle: Vec<OptionVector>,
    grouped_papers: Vec<GroupedVector>,
}

#[derive(Debug, Deserialize)]
struct FixtureQuestion {
    id: i64,
    group_id: Option<i64>,
    group_sequence: Option<i64>,
}

#[derive(Debug, Deserialize)]
struct ShuffleVector {
    seed: i64,
    length: usize,
    input: Vec<i64>,
    output: Vec<i64>,
}

#[derive(Debug, Deserialize)]
struct OptionVector {
    attempt_seed: i64,
    question_id: i64,
    effective_seed: i64,
    input: Vec<String>,
    output: Vec<String>,
}

#[derive(Debug, Deserialize)]
struct GroupedVector {
    seed: i64,
    exam: GroupedExam,
    question_order: Vec<i64>,
}

#[derive(Debug, Deserialize)]
struct GroupedExam {
    shuffle_questions: bool,
    shuffle_within_group: bool,
    questions_per_attempt: Option<i64>,
}

/// The fixture is committed on the backend side and shared, not copied. Two
/// copies of a parity vector is two things that can drift, which defeats the
/// purpose of having one.
fn fixture() -> Fixture {
    let path = PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("../../backend/tests/fixtures/cbt-shuffle-parity.json");

    let raw = std::fs::read_to_string(&path).unwrap_or_else(|error| {
        panic!(
            "could not read the shared parity vectors at {}: {error}\n\
             This file is the contract with the server. Do not stub it out.",
            path.display()
        )
    });

    serde_json::from_str(&raw).expect("parity fixture is not valid JSON")
}

#[test]
fn seeded_shuffle_matches_the_committed_vectors() {
    let fixture = fixture();
    assert!(!fixture.seeded_shuffle.is_empty(), "fixture carried no shuffle vectors");

    for vector in &fixture.seeded_shuffle {
        assert_eq!(
            vector.input.len(),
            vector.length,
            "vector for seed {} is internally inconsistent",
            vector.seed
        );

        assert_eq!(
            seeded_shuffle(&vector.input, vector.seed),
            vector.output,
            "seeded_shuffle drifted from the server for seed {} at length {}",
            vector.seed,
            vector.length
        );
    }
}

#[test]
fn option_shuffle_is_seeded_per_question() {
    let fixture = fixture();
    assert!(!fixture.option_shuffle.is_empty(), "fixture carried no option vectors");

    for vector in &fixture.option_shuffle {
        // The effective seed is attempt.seed + question_id, so shuffling
        // question 7's options does not depend on how many came before it.
        assert_eq!(
            vector.attempt_seed + vector.question_id,
            vector.effective_seed,
            "the fixture's own arithmetic disagrees with itself"
        );

        assert_eq!(
            shuffle_options(&vector.input, vector.attempt_seed, vector.question_id),
            vector.output,
            "option order drifted for question {} at attempt seed {}",
            vector.question_id,
            vector.attempt_seed
        );
    }
}

#[test]
fn grouped_paper_composition_matches_the_committed_vectors() {
    let fixture = fixture();
    assert!(!fixture.grouped_papers.is_empty(), "fixture carried no grouped papers");

    let questions: Vec<AttachedQuestion> = fixture
        .attached_questions
        .iter()
        .map(|q| AttachedQuestion {
            id: q.id,
            group_id: q.group_id,
            group_sequence: q.group_sequence,
        })
        .collect();

    for vector in &fixture.grouped_papers {
        let settings = OrderingSettings {
            shuffle_questions: vector.exam.shuffle_questions,
            shuffle_within_group: vector.exam.shuffle_within_group,
            questions_per_attempt: vector.exam.questions_per_attempt,
        };

        assert_eq!(
            compose_paper(&questions, settings, vector.seed),
            vector.question_order,
            "grouped composition drifted for seed {} (shuffle={}, within_group={}, per_attempt={:?})",
            vector.seed,
            vector.exam.shuffle_questions,
            vector.exam.shuffle_within_group,
            vector.exam.questions_per_attempt
        );
    }
}

/// Grouping is the rule most likely to drift, so assert the property directly
/// rather than only through recorded vectors: whatever the seed, a passage's
/// sub-questions must land together. A candidate who gets question (c) of a
/// comprehension without (a) and (b) has been handed an unanswerable paper.
#[test]
fn a_passage_is_never_split_whatever_the_seed() {
    let fixture = fixture();

    let questions: Vec<AttachedQuestion> = fixture
        .attached_questions
        .iter()
        .map(|q| AttachedQuestion {
            id: q.id,
            group_id: q.group_id,
            group_sequence: q.group_sequence,
        })
        .collect();

    let group_of = |id: i64| questions.iter().find(|q| q.id == id).and_then(|q| q.group_id);

    for seed in 1..500 {
        let settings = OrderingSettings {
            shuffle_questions: true,
            shuffle_within_group: true,
            questions_per_attempt: None,
        };

        let order = compose_paper(&questions, settings, seed);

        let mut seen_groups: Vec<i64> = Vec::new();
        let mut current: Option<i64> = None;

        for question_id in &order {
            let group = group_of(*question_id);

            if group == current {
                continue;
            }

            if let Some(group_id) = group {
                assert!(
                    !seen_groups.contains(&group_id),
                    "seed {seed} scattered group {group_id} across the paper: {order:?}"
                );
                seen_groups.push(group_id);
            }

            current = group;
        }
    }
}

/// §6.6: a random subset selects whole groups and overshoots to the nearest
/// group boundary rather than splitting one.
#[test]
fn a_subset_draw_never_takes_half_a_passage() {
    let fixture = fixture();

    let questions: Vec<AttachedQuestion> = fixture
        .attached_questions
        .iter()
        .map(|q| AttachedQuestion {
            id: q.id,
            group_id: q.group_id,
            group_sequence: q.group_sequence,
        })
        .collect();

    for target in 1..=questions.len() as i64 {
        for seed in 1..50 {
            let settings = OrderingSettings {
                shuffle_questions: true,
                shuffle_within_group: false,
                questions_per_attempt: Some(target),
            };

            let order = compose_paper(&questions, settings, seed);

            for question in &questions {
                let Some(group_id) = question.group_id else { continue };

                let drawn_here = order.contains(&question.id);
                let siblings: Vec<i64> = questions
                    .iter()
                    .filter(|q| q.group_id == Some(group_id))
                    .map(|q| q.id)
                    .collect();

                let drawn_siblings = siblings.iter().filter(|id| order.contains(id)).count();

                assert!(
                    !drawn_here || drawn_siblings == siblings.len(),
                    "target {target} seed {seed} drew {} of group {group_id}'s {} questions",
                    drawn_siblings,
                    siblings.len()
                );
            }
        }
    }
}
