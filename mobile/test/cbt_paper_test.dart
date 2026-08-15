import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/cbt/cbt_paper.dart';

/// A paper shaped exactly as `CbtController::paperPayload` returns it.
///
/// These tests exist because the screen was previously written against field
/// names the server has never sent — `id` instead of `question_id`,
/// `question_text` instead of `question`. Every assertion below pins one of
/// those names, so the same drift breaks a test instead of a live exam.
Map<String, dynamic> _payload({
  List<Map<String, dynamic>>? questions,
  int? secondsRemaining,
}) =>
    {
      'attempt': {
        'id': 41,
        'attempt_number': 1,
        'status': 'in_progress',
        'started_at': '2026-08-15T09:00:00+00:00',
        'server_deadline_at': '2026-08-15T10:00:00+00:00',
        'seconds_remaining': secondsRemaining,
      },
      'exam': {
        'id': 7,
        'title': 'Second Term Mathematics',
        'instructions': 'Answer all questions.',
        'duration_minutes': 60,
        'total_marks': 40.0,
        'negative_marking': true,
      },
      'questions': questions ??
          [
            {
              'number': 1,
              'question_id': 900,
              'question_type': 'multiple_choice',
              'content_format': 'latex',
              'topic': 'Algebra',
              'section': 'Section A',
              'marks': 2.0,
              'negative_marks': 0.5,
              'question': r'Solve \( x^2 = 9 \)',
              'media': [
                {
                  'role': 'stem',
                  'asset_id': 5,
                  'url': 'https://cdn.test/stem.png',
                  'alt_text': 'A parabola',
                  'caption': 'Figure 1',
                }
              ],
              'options': [
                {'key': 'A', 'text': '3', 'image': null},
                {
                  'key': 'B',
                  'text': '±3',
                  'image': {'url': 'https://cdn.test/b.png', 'asset_id': 6},
                },
              ],
              'interaction': <String, dynamic>{},
              'saved_response': {'option': 'B'},
            },
          ],
    };

void main() {
  group('CbtPaper.fromJson', () {
    test('reads the attempt envelope the server actually sends', () {
      final paper = CbtPaper.fromJson(_payload());

      expect(paper.attemptId, 41);
      expect(paper.status, 'in_progress');
      expect(paper.examTitle, 'Second Term Mathematics');
      expect(paper.negativeMarking, isTrue);
      // Parsed into West Africa Time for display; compared as an instant,
      // because Dart's DateTime equality also compares the isUtc flag.
      expect(
        paper.deadline!.isAtSameMomentAs(DateTime.parse('2026-08-15T10:00:00Z')),
        isTrue,
      );
    });

    test('reads question_id, not id — an answer keyed to 0 loses the paper',
        () {
      final paper = CbtPaper.fromJson(_payload());

      expect(paper.questions.single.questionId, 900);
      expect(paper.questions.single.questionId, isNot(0));
    });

    test('reads the stem from `question`', () {
      final paper = CbtPaper.fromJson(_payload());

      expect(paper.questions.single.text, r'Solve \( x^2 = 9 \)');
      expect(paper.questions.single.text, isNotEmpty);
    });

    test('reads `saved_response`, so a resumed paper shows prior answers', () {
      final paper = CbtPaper.fromJson(_payload());

      expect(
        CbtResponse.selectedChoice(paper.questions.single.savedResponse),
        'B',
      );
    });

    test('carries marks, section and per-option images', () {
      final question = CbtPaper.fromJson(_payload()).questions.single;

      expect(question.marks, 2.0);
      expect(question.negativeMarks, 0.5);
      expect(question.section, 'Section A');
      expect(question.media.single.url, 'https://cdn.test/stem.png');
      expect(question.media.single.altText, 'A parabola');
      expect(question.options[1].image?.url, 'https://cdn.test/b.png');
      expect(question.options[0].image, isNull);
    });

    test('prefers the server countdown over the handset clock', () {
      final now = DateTime.utc(2026, 8, 15, 9, 30);
      final paper = CbtPaper.fromJson(_payload(secondsRemaining: 600));

      expect(paper.endsAt(now: now), now.add(const Duration(minutes: 10)));
    });

    test('falls back to the deadline when no countdown is sent', () {
      final paper = CbtPaper.fromJson(_payload());

      expect(
        paper.endsAt()!.isAtSameMomentAs(DateTime.parse('2026-08-15T10:00:00Z')),
        isTrue,
      );
    });

    test('survives a paper with no questions', () {
      final paper = CbtPaper.fromJson(_payload(questions: []));

      expect(paper.questions, isEmpty);
      expect(paper.attemptId, 41);
    });
  });

  group('grouped questions', () {
    Map<String, dynamic> grouped(int position, int of) => {
          'number': position,
          'question_id': 100 + position,
          'question_type': 'multiple_choice',
          'question': 'Sub-question $position',
          'options': [
            {'key': 'A', 'text': 'Yes'},
          ],
          'group': {
            'group_id': 12,
            'title': 'Passage 1',
            'stimulus': 'The rains came early that year.',
            'content_format': 'plain',
            'instructions': 'Read the passage and answer the questions.',
            'media': [
              {'url': 'https://cdn.test/passage.png', 'role': 'stimulus'},
            ],
            'position': position,
            'of': of,
          },
        };

    test('parses the shared stimulus and its position in the group', () {
      final paper = CbtPaper.fromJson(
        _payload(questions: [grouped(1, 6), grouped(2, 6)]),
      );

      final first = paper.questions.first.group!;
      expect(first.groupId, 12);
      expect(first.title, 'Passage 1');
      expect(first.stimulus, 'The rains came early that year.');
      expect(first.instructions, 'Read the passage and answer the questions.');
      expect(first.media.single.url, 'https://cdn.test/passage.png');
      expect(first.position, 1);
      expect(first.of, 6);
    });

    test('only the first sub-question opens the passage expanded', () {
      final paper = CbtPaper.fromJson(
        _payload(questions: [grouped(1, 6), grouped(4, 6)]),
      );

      expect(paper.questions[0].group!.isFirstOfGroup, isTrue);
      expect(paper.questions[1].group!.isFirstOfGroup, isFalse);
    });

    test('an ungrouped question has no group', () {
      expect(CbtPaper.fromJson(_payload()).questions.single.group, isNull);
    });
  });

  group('theory interaction', () {
    CbtQuestion theory(Map<String, dynamic> interaction) =>
        CbtQuestion.fromJson({
          'question_id': 500,
          'question_type': 'theory',
          'question': 'Discuss the causes of soil erosion.',
          'interaction': interaction,
        });

    test('an on_paper question offers nothing to type and is not unanswered',
        () {
      final question = theory({'answer_mode': 'on_paper'});

      expect(question.isAnsweredOnPaper, isTrue);
      expect(question.isManuallyGraded, isTrue);
    });

    test('an on_screen question is answered on the handset', () {
      expect(theory({'answer_mode': 'on_screen'}).isAnsweredOnPaper, isFalse);
      // Absent `answer_mode` means on_screen, matching the server default.
      expect(theory({}).isAnsweredOnPaper, isFalse);
    });

    test('carries word guidance and structured parts', () {
      final question = theory({
        'response_format': 'structured',
        'expected_words': 120,
        'max_words': 200,
        'parts': [
          {'key': 'a', 'prompt': 'Define erosion.', 'marks': 2},
          {'key': 'b', 'prompt': 'Give two causes.', 'marks': 4},
        ],
      });

      expect(question.interaction.responseFormat, 'structured');
      expect(question.interaction.expectedWords, 120);
      expect(question.interaction.maxWords, 200);
      expect(question.interaction.parts.map((p) => p.key), ['a', 'b']);
      expect(question.interaction.parts.first.prompt, 'Define erosion.');
      expect(question.interaction.parts[1].marks, 4);
    });

    test('honours allow_image_answer for clients predating the rename', () {
      expect(theory({'allow_image_answer': true}).interaction.allowWorkingPhoto,
          isTrue);
      expect(
        theory({'allow_working_photo': false, 'allow_image_answer': true})
            .interaction
            .allowWorkingPhoto,
        isFalse,
      );
    });
  });

  group('other interaction schemas', () {
    test('ordering, matching, numeric and diagram geometry all parse', () {
      final ordering = CbtInteraction.fromJson({
        'items': ['Egg', 'Larva', 'Pupa'],
      });
      expect(ordering.items, ['Egg', 'Larva', 'Pupa']);

      final matching = CbtInteraction.fromJson({
        'left': ['Lagos', 'Kano'],
        'right': ['South-West', 'North-West'],
      });
      expect(matching.left, ['Lagos', 'Kano']);
      expect(matching.right, ['South-West', 'North-West']);

      final numeric = CbtInteraction.fromJson({
        'unit': 'm/s',
        'decimal_places_hint': 2,
      });
      expect(numeric.unit, 'm/s');
      expect(numeric.decimalPlacesHint, 2);

      final diagram = CbtInteraction.fromJson({
        'zones': [
          {'id': 'z1', 'x': 0.1, 'y': 0.2, 'w': 0.15, 'h': 0.1},
        ],
        'label_pool': ['Nucleus', 'Membrane'],
      });
      expect(diagram.zones.single.id, 'z1');
      expect(diagram.zones.single.x, 0.1);
      expect(diagram.zones.single.h, 0.1);
      expect(diagram.labelPool, ['Nucleus', 'Membrane']);
    });
  });

  /// The envelopes below are read straight off `CbtGradingService`. Sending
  /// the wrong key does not error anywhere — it silently scores zero — so
  /// each one is pinned to the key its own grader looks for.
  group('CbtResponse envelopes match the grader', () {
    test('single choice grades on `option`, not `value`', () {
      expect(CbtResponse.choice('C'), {'option': 'C'});
      expect(CbtResponse.choice('C').containsKey('value'), isFalse);
    });

    test('true/false grades on `value`', () {
      expect(CbtResponse.boolean(true), {'value': true});
      expect(CbtResponse.selectedBoolean({'value': false}), isFalse);
      expect(CbtResponse.selectedBoolean({'value': 'true'}), isTrue);
      expect(CbtResponse.selectedBoolean(null), isNull);
    });

    test('multiple response grades on `options`', () {
      expect(CbtResponse.choices(['A', 'C']), {
        'options': ['A', 'C'],
      });
      expect(
        CbtResponse.selectedChoices({
          'options': ['A', 'C'],
        }),
        {'A', 'C'},
      );
    });

    test('fill-in-the-blank grades on `text`', () {
      expect(CbtResponse.text('photosynthesis'), {'text': 'photosynthesis'});
      expect(CbtResponse.enteredText({'text': 'photosynthesis'}),
          'photosynthesis');
      // recordAnswers wraps a bare string as {'value': …}; still readable.
      expect(CbtResponse.enteredText({'value': 'legacy'}), 'legacy');
    });

    test('numeric grades on `value` with an optional `unit`', () {
      expect(CbtResponse.numeric('9.8', unit: 'm/s²'),
          {'value': '9.8', 'unit': 'm/s²'});
      expect(CbtResponse.numeric('9.8'), {'value': '9.8'});
      expect(CbtResponse.numeric('9.8', unit: ''), {'value': '9.8'});
    });

    test('ordering grades on `order`', () {
      expect(CbtResponse.order(['Egg', 'Larva']), {
        'order': ['Egg', 'Larva'],
      });
      expect(
        CbtResponse.enteredOrder({
          'order': ['Egg', 'Larva'],
        }),
        ['Egg', 'Larva'],
      );
    });

    test('matching grades on `pairs`, keyed by the left-hand item', () {
      expect(CbtResponse.pairs({'Lagos': 'South-West'}), {
        'pairs': {'Lagos': 'South-West'},
      });
      expect(
        CbtResponse.enteredPairs({
          'pairs': {'Lagos': 'South-West'},
        }),
        {'Lagos': 'South-West'},
      );
    });

    test('diagram labelling grades on `labels`, keyed by zone id', () {
      expect(CbtResponse.labels({'z1': 'Nucleus'}), {
        'labels': {'z1': 'Nucleus'},
      });
      expect(
        CbtResponse.enteredLabels({
          'labels': {'z1': 'Nucleus'},
        }),
        {'z1': 'Nucleus'},
      );
    });

    test('hotspot grades on normalised x/y', () {
      expect(CbtResponse.point(0.25, 0.5), {'x': 0.25, 'y': 0.5});
      final point = CbtResponse.enteredPoint({'x': 0.25, 'y': 0.5});
      expect(point?.x, 0.25);
      expect(point?.y, 0.5);
      expect(CbtResponse.enteredPoint({'x': 0.25}), isNull);
    });

    test('structured theory grades on `parts`', () {
      expect(CbtResponse.parts({'a': 'Because of rain.'}), {
        'parts': {'a': 'Because of rain.'},
      });
      expect(
        CbtResponse.enteredParts({
          'parts': {'a': 'Because of rain.'},
        }),
        {'a': 'Because of rain.'},
      );
    });
  });

  group('CbtResponse.isAnswered', () {
    test('an empty or whitespace answer does not count as answered', () {
      expect(CbtResponse.isAnswered(null), isFalse);
      expect(CbtResponse.isAnswered(const {}), isFalse);
      expect(CbtResponse.isAnswered({'text': ''}), isFalse);
      expect(CbtResponse.isAnswered({'text': '   '}), isFalse);
      expect(CbtResponse.isAnswered({'options': <String>[]}), isFalse);
      expect(CbtResponse.isAnswered({'pairs': <String, String>{}}), isFalse);
    });

    test('a real answer counts, including a false boolean and a zero', () {
      expect(CbtResponse.isAnswered({'option': 'A'}), isTrue);
      // "False" is an answer. Treating it as blank would mark an honest
      // response as an omission and skip its negative marking.
      expect(CbtResponse.isAnswered({'value': false}), isTrue);
      expect(CbtResponse.isAnswered({'value': 0}), isTrue);
      expect(
        CbtResponse.isAnswered({
          'order': ['Egg'],
        }),
        isTrue,
      );
      expect(
        CbtResponse.isAnswered({
          'parts': {'a': 'Something'},
        }),
        isTrue,
      );
    });

    test('a partly-filled structured answer counts as answered', () {
      expect(
        CbtResponse.isAnswered({
          'parts': {'a': 'Written', 'b': ''},
        }),
        isTrue,
      );
    });
  });
}
