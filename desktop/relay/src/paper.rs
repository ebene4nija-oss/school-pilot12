//! Turning the opened bundle into one candidate's paper (§5.3, §6.2).
//!
//! The relay renders the order it is given. It does **not** re-derive grouping
//! or the subset draw: `question_order` was fixed server-side and travels in
//! the roster, which §10 calls the strongest version of the determinism rule —
//! the client never computes a grouped order at all.
//!
//! What the relay does still compute is option order, because that is seeded
//! per question rather than stored. `shuffle::shuffle_options` matches
//! `CbtExamService::presentOptions`, and the parity vectors police it.

use serde::Serialize;

use crate::bundle::{ExamSettings, Paper, QuestionGroup, RosterEntry};
use crate::shuffle;

#[derive(Debug, Clone, Serialize)]
pub struct CandidatePaper {
    pub attempt_id: i64,
    pub candidate_name: Option<String>,
    pub admission_number: Option<String>,
    pub exam: CandidateExam,
    pub server_deadline_at: Option<String>,
    pub questions: Vec<CandidateQuestion>,
}

#[derive(Debug, Clone, Serialize)]
pub struct CandidateExam {
    pub title: String,
    pub instructions: Option<String>,
    pub content_format: String,
    pub duration_minutes: i64,
    pub total_marks: f64,
    pub negative_marking: bool,
    /// Always false offline (§5.4). Sent so the candidate UI never has to guess
    /// whether to promise a score at the end.
    pub show_results_immediately: bool,
    pub question_count: usize,
}

#[derive(Debug, Clone, Serialize)]
pub struct CandidateQuestion {
    pub position: usize,
    pub question_id: i64,
    pub question_type: String,
    pub content_format: String,
    pub question: String,
    pub section: Option<String>,
    pub marks: f64,
    pub options: Vec<CandidateOption>,
    pub media: Vec<i64>,
    pub interaction: serde_json::Value,
    pub answer_mode: Option<String>,
    /// The shared stimulus, sent with every sub-question of a group.
    ///
    /// Repeating it costs a few kilobytes over the LAN and removes any chance
    /// of a candidate landing on question 3 of a passage with nothing to read.
    /// §6.2 requires the stimulus to stay visible while any of its
    /// sub-questions is on screen; sending it per question is the simplest
    /// thing that cannot fail to satisfy that.
    pub group: Option<CandidateGroup>,
}

#[derive(Debug, Clone, Serialize)]
pub struct CandidateGroup {
    pub group_id: i64,
    pub title: Option<String>,
    pub stimulus: Option<String>,
    pub content_format: String,
    pub instructions: Option<String>,
    pub media: Vec<i64>,
    /// "Question 3 of 6 in this passage" — §6.2 requires the candidate to know
    /// where they are inside a group.
    pub position_in_group: usize,
    pub questions_in_group: usize,
}

/// Build the paper for one roster entry.
///
/// Questions the bundle does not carry are skipped rather than faked. That
/// should be impossible — the bundle ships exactly the questions somebody was
/// drawn — but a silently short paper is better than a panic in a room with
/// forty candidates in it, and the count mismatch is visible on the status
/// window.
pub fn for_attempt(paper: &Paper, entry: &RosterEntry) -> CandidatePaper {
    let group_sizes = group_sizes(paper, &entry.question_order);
    let mut seen_in_group: Vec<(i64, usize)> = Vec::new();

    let questions = entry
        .question_order
        .iter()
        .filter_map(|question_id| paper.question(*question_id))
        .enumerate()
        .map(|(index, question)| {
            let options = present_options(question, &paper.exam, entry.seed);

            let group = question.group_id.and_then(|group_id| {
                let position = match seen_in_group.iter_mut().find(|(id, _)| *id == group_id) {
                    Some((_, count)) => {
                        *count += 1;
                        *count
                    }
                    None => {
                        seen_in_group.push((group_id, 1));
                        1
                    }
                };

                paper.group(group_id).map(|group: &QuestionGroup| CandidateGroup {
                    group_id,
                    title: group.title.clone(),
                    stimulus: group.stimulus.clone(),
                    content_format: group.content_format.clone(),
                    instructions: group.instructions.clone(),
                    media: group.media.iter().map(|m| m.asset_id).collect(),
                    position_in_group: position,
                    questions_in_group: group_sizes
                        .iter()
                        .find(|(id, _)| *id == group_id)
                        .map(|(_, size)| *size)
                        .unwrap_or(1),
                })
            });

            CandidateQuestion {
                position: index + 1,
                question_id: question.question_id,
                question_type: question.question_type.clone(),
                content_format: question.content_format.clone(),
                question: question.question.clone(),
                section: question.section.clone(),
                marks: question.marks,
                options,
                media: question.media.iter().map(|m| m.asset_id).collect(),
                interaction: question.interaction.clone(),
                answer_mode: question.answer_mode.clone(),
                group,
            }
        })
        .collect::<Vec<_>>();

    CandidatePaper {
        attempt_id: entry.attempt_id,
        candidate_name: entry.candidate_name.clone(),
        admission_number: entry.admission_number.clone(),
        exam: CandidateExam {
            title: paper.exam.title.clone(),
            instructions: paper.exam.instructions.clone(),
            content_format: paper.exam.content_format.clone(),
            duration_minutes: paper.exam.duration_minutes,
            total_marks: paper.exam.total_marks,
            negative_marking: paper.exam.negative_marking,
            show_results_immediately: false,
            question_count: questions.len(),
        },
        server_deadline_at: entry.server_deadline_at.clone(),
        questions,
    }
}

#[derive(Debug, Clone, Serialize)]
pub struct CandidateOption {
    pub key: String,
    pub text: String,
    pub image_asset_id: Option<i64>,
}

fn present_options(
    question: &crate::bundle::Question,
    exam: &ExamSettings,
    attempt_seed: i64,
) -> Vec<CandidateOption> {
    let options: Vec<CandidateOption> = question
        .options
        .iter()
        .map(|option| CandidateOption {
            key: option.key.clone(),
            text: option.text.clone(),
            image_asset_id: option.image_asset_id,
        })
        .collect();

    if exam.shuffle_options && shuffle::options_are_shuffleable(&question.question_type) {
        return shuffle::shuffle_options(&options, attempt_seed, question.question_id);
    }

    options
}

/// How many of each group's questions this candidate was actually drawn.
///
/// Taken from the served order rather than from the group's full size: with
/// `questions_per_attempt` the draw takes whole groups (§6.6), so these agree —
/// but counting what was served is the version that stays true if that ever
/// changes, and "Question 3 of 6" must never count questions the candidate
/// cannot see.
fn group_sizes(paper: &Paper, order: &[i64]) -> Vec<(i64, usize)> {
    let mut sizes: Vec<(i64, usize)> = Vec::new();

    for question_id in order {
        let Some(group_id) = paper.question(*question_id).and_then(|q| q.group_id) else {
            continue;
        };

        match sizes.iter_mut().find(|(id, _)| *id == group_id) {
            Some((_, count)) => *count += 1,
            None => sizes.push((group_id, 1)),
        }
    }

    sizes
}

/// Whether the deadline the bundle carries has passed.
///
/// §15: the deadline is `server_deadline_at` from the bundle and nothing else.
/// The relay never computes one from local time plus `duration_minutes`, and
/// must not "helpfully" fall back to doing so when the field is missing — a
/// missing deadline is a bundle problem, and running an untimed paper is worse
/// than refusing to start one.
pub fn deadline_passed(entry: &RosterEntry, now: chrono::DateTime<chrono::Utc>) -> Option<bool> {
    let deadline = entry.server_deadline_at.as_deref()?;
    let parsed = chrono::DateTime::parse_from_rfc3339(deadline).ok()?;

    Some(now >= parsed.with_timezone(&chrono::Utc))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bundle::{Question, QuestionOption};

    fn question(id: i64, group_id: Option<i64>) -> Question {
        Question {
            question_id: id,
            question_type: "multiple_choice".into(),
            content_format: "plain".into(),
            question: format!("Question {id}"),
            topic: None,
            section: None,
            marks: 1.0,
            negative_marks: 0.0,
            group_id,
            group_sequence: None,
            options: vec![
                QuestionOption { key: "A".into(), text: "a".into(), image_asset_id: None },
                QuestionOption { key: "B".into(), text: "b".into(), image_asset_id: None },
                QuestionOption { key: "C".into(), text: "c".into(), image_asset_id: None },
                QuestionOption { key: "D".into(), text: "d".into(), image_asset_id: None },
            ],
            media: vec![],
            interaction: serde_json::Value::Null,
            answer_mode: None,
        }
    }

    fn paper_with(questions: Vec<Question>, groups: Vec<QuestionGroup>, shuffle_options: bool) -> Paper {
        Paper {
            format_version: 1,
            exam: ExamSettings {
                id: 1,
                title: "Mock".into(),
                instructions: None,
                content_format: "plain".into(),
                duration_minutes: 60,
                opens_at: None,
                closes_at: None,
                shuffle_questions: false,
                shuffle_options,
                shuffle_within_group: false,
                questions_per_attempt: None,
                max_attempts: 1,
                negative_marking: false,
                pass_mark: 40.0,
                total_marks: 100.0,
                integrity_settings: None,
                show_results_immediately: false,
            },
            groups,
            questions,
            roster: vec![],
        }
    }

    fn entry(order: Vec<i64>) -> RosterEntry {
        RosterEntry {
            attempt_id: 900,
            student_id: 1,
            candidate_name: Some("Ada".into()),
            admission_number: Some("SP/001".into()),
            attempt_number: 1,
            seed: 7,
            question_order: order,
            server_deadline_at: None,
            relay_code: Some("ABCD2345".into()),
        }
    }

    #[test]
    fn the_served_order_is_the_roster_order_untouched() {
        let paper = paper_with(vec![question(3, None), question(1, None), question(2, None)], vec![], false);
        let built = for_attempt(&paper, &entry(vec![2, 3, 1]));

        assert_eq!(
            built.questions.iter().map(|q| q.question_id).collect::<Vec<_>>(),
            vec![2, 3, 1],
            "the relay renders the order it is given and derives nothing"
        );
    }

    #[test]
    fn a_candidate_knows_where_they_are_inside_a_passage() {
        let paper = paper_with(
            vec![question(1, Some(50)), question(2, Some(50)), question(3, Some(50))],
            vec![QuestionGroup {
                group_id: 50,
                title: Some("Passage 2".into()),
                stimulus: Some("The Harmattan...".into()),
                content_format: "plain".into(),
                instructions: None,
                media: vec![],
            }],
            false,
        );

        let built = for_attempt(&paper, &entry(vec![1, 2, 3]));
        let second = built.questions[1].group.as_ref().unwrap();

        assert_eq!(second.position_in_group, 2);
        assert_eq!(second.questions_in_group, 3);
        assert!(second.stimulus.is_some(), "the stimulus travels with every sub-question");
    }

    #[test]
    fn ordering_questions_keep_their_option_order() {
        let mut ordering = question(1, None);
        ordering.question_type = "ordering".into();

        let paper = paper_with(vec![ordering], vec![], true);
        let built = for_attempt(&paper, &entry(vec![1]));

        assert_eq!(
            built.questions[0].options.iter().map(|o| o.key.as_str()).collect::<Vec<_>>(),
            vec!["A", "B", "C", "D"],
            "for an ordering question the order is the answer"
        );
    }

    #[test]
    fn a_missing_question_shortens_the_paper_rather_than_panicking() {
        let paper = paper_with(vec![question(1, None)], vec![], false);
        let built = for_attempt(&paper, &entry(vec![1, 99]));

        assert_eq!(built.questions.len(), 1);
        assert_eq!(built.exam.question_count, 1);
    }
}
