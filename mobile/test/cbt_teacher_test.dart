import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/cbt/teacher/cbt_exam_detail_screen.dart';
import 'package:schoolpilot/features/cbt/teacher/cbt_exams_screen.dart';
import 'package:schoolpilot/features/cbt/teacher/cbt_results_screen.dart';
import 'package:schoolpilot/features/cbt/teacher/cbt_teacher_data.dart';
import 'package:schoolpilot/core/data/reference_data.dart';
import 'package:schoolpilot/features/teaching/ai_studio_screen.dart';

Widget _app(Widget home, List<Override> overrides) => ProviderScope(
      overrides: overrides,
      child: MaterialApp(home: home),
    );

void main() {
  test('only theory is marked by hand', () {
    expect(isHandMarked('theory'), isTrue);
    expect(isHandMarked('multiple_choice'), isFalse);
    expect(isHandMarked(null), isFalse);
  });

  testWidgets('the exam list shows each paper with its status', (tester) async {
    await tester.pumpWidget(_app(const CbtExamsScreen(), [
      teacherExamsProvider.overrideWith((ref) async => [
            {
              'id': 1,
              'title': 'JSS 2 Maths test',
              'status': 'draft',
              'subject': {'id': 3, 'name': 'Mathematics'},
              'duration_minutes': 40,
              'exam_questions_count': 0,
              'attempts_count': 0,
            },
            {
              'id': 2,
              'title': 'JSS 2 English test',
              'status': 'published',
              'subject': {'id': 4, 'name': 'English'},
              'duration_minutes': 30,
              'exam_questions_count': 20,
              'attempts_count': 12,
            },
          ]),
    ]));
    await tester.pumpAndSettle();

    expect(find.text('JSS 2 Maths test'), findsOneWidget);
    expect(find.text('Draft'), findsOneWidget);
    expect(find.text('Published'), findsOneWidget);
    expect(find.textContaining('12 sat'), findsOneWidget);
    expect(find.text('New exam'), findsOneWidget);
  });

  testWidgets('a draft with no questions cannot be published yet', (tester) async {
    await tester.pumpWidget(_app(const CbtExamDetailScreen(examId: 1), [
      examDetailProvider(1).overrideWith((ref) async => {
            'exam': {
              'id': 1,
              'title': 'JSS 2 Maths test',
              'status': 'draft',
              'subject_id': 3,
              'subject': {'name': 'Mathematics'},
              'duration_minutes': 40,
              'exam_questions': <Map<String, dynamic>>[],
            },
            'total_marks': 0,
            'attempt_count': 0,
          }),
    ]));
    await tester.pumpAndSettle();

    final publish = tester.widget<FilledButton>(
      find.ancestor(
        of: find.text('Add questions to publish'),
        matching: find.byWidgetPredicate((w) => w is FilledButton),
      ),
    );
    expect(publish.onPressed, isNull);
    expect(find.text('Add from bank'), findsOneWidget);
  });

  testWidgets('a published paper offers close and results, not editing', (tester) async {
    await tester.pumpWidget(_app(const CbtExamDetailScreen(examId: 2), [
      examDetailProvider(2).overrideWith((ref) async => {
            'exam': {
              'id': 2,
              'title': 'JSS 2 English test',
              'status': 'published',
              'subject_id': 4,
              'duration_minutes': 30,
              'exam_questions': [
                {
                  'question_id': 9,
                  'marks': 5,
                  'question': {'question': 'Write a letter.', 'question_type': 'theory'},
                },
              ],
            },
            'total_marks': 5,
            'attempt_count': 3,
          }),
    ]));
    await tester.pumpAndSettle();

    expect(find.text('Close exam'), findsOneWidget);
    expect(find.text('Results and marking (3 sat)'), findsOneWidget);
    expect(find.text('Add from bank'), findsNothing);
    expect(find.byTooltip('Remove from exam'), findsNothing);
    expect(find.textContaining('Theory — you mark it'), findsOneWidget);
  });

  testWidgets('results put scripts awaiting marks first', (tester) async {
    await tester.pumpWidget(_app(const CbtResultsScreen(examId: 2), [
      examResultsProvider(2).overrideWith((ref) async => {
            'summary': {
              'attempts': 2,
              'awaiting_marking': 1,
              'average_percentage': 55.5,
              'pass_rate': 50,
            },
            'candidates': [
              {'attempt_id': 1, 'student_name': 'Ada', 'percentage': 80, 'grade': 'A1', 'requires_manual_grading': false},
              {'attempt_id': 2, 'student_name': 'Zainab', 'percentage': 31, 'grade': null, 'requires_manual_grading': true},
            ],
          }),
    ]));
    await tester.pumpAndSettle();

    expect(find.text('Theory answers to mark'), findsOneWidget);
    expect(find.text('Grade A1'), findsOneWidget);
    // Zainab is waiting for marks, so she is listed before Ada.
    expect(
      tester.getTopLeft(find.text('Zainab')).dy,
      lessThan(tester.getTopLeft(find.text('Ada')).dy),
    );
  });
  testWidgets('AI questions are all ticked, filed to a class, and the typed subject is matched', (tester) async {
    await tester.pumpWidget(_app(
      const Scaffold(
        body: SingleChildScrollView(
          child: SaveToBankCard(
            questions: [
              {'question': 'What is 5% of 200?', 'options': {'A': '5', 'B': '10'}, 'correct_answer': 'B'},
              {'question': 'What is 10% of 50?', 'options': {'A': '5', 'B': '10'}, 'correct_answer': 'A'},
            ],
            subjectName: 'mathematics',
            topic: 'Percentages',
            difficulty: 'easy',
          ),
        ),
      ),
      [
        classesProvider.overrideWith((ref) async => const [ClassRef(id: 7, name: 'JSS 2')]),
        classSubjectsProvider.overrideWith((ref, classId) async => [
              {'id': 3, 'name': 'Mathematics'},
              {'id': 4, 'name': 'English'},
            ]),
      ],
    ));
    await tester.pumpAndSettle();

    FilledButton save() => tester.widget<FilledButton>(
          find.ancestor(
            of: find.text('Save 2 to question bank'),
            matching: find.byWidgetPredicate((w) => w is FilledButton),
          ),
        );

    // The bank is filed by class: nothing saves until one is chosen.
    expect(save().onPressed, isNull);

    await tester.tap(find.text('Class they are for'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('JSS 2').last);
    await tester.pumpAndSettle();

    expect(find.text('Mathematics'), findsOneWidget);
    expect(save().onPressed, isNotNull);
  });
}
