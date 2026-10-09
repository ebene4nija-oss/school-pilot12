import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/homework/hand_in_screen.dart';
import 'package:schoolpilot/features/homework/teacher_homework_screen.dart';

Widget _app(Widget home, [List<Override> overrides = const []]) =>
    ProviderScope(overrides: overrides, child: MaterialApp(home: home));

void main() {
  testWidgets('homework that takes photos or files offers the camera and a picker', (tester) async {
    await tester.pumpWidget(_app(const HandInScreen(work: {
      'id': 5,
      'title': 'Simple interest',
      'subject': 'Mathematics',
      'description': 'Exercise 4b, questions 1 to 10.',
      'due_date': '2026-10-15',
      'submission_type': 'text_or_file',
      'submitted': false,
    })));
    await tester.pumpAndSettle();

    expect(find.text('Take photo'), findsOneWidget);
    expect(find.text('Choose file'), findsOneWidget);
    expect(find.text('Hand in'), findsOneWidget);

    // Nothing typed or attached: says what is missing rather than sending.
    await tester.tap(find.text('Hand in'));
    await tester.pumpAndSettle();
    expect(find.text('Type an answer or attach a photo or file.'), findsOneWidget);
  });

  testWidgets('typed-only homework has no attachment buttons', (tester) async {
    await tester.pumpWidget(_app(const HandInScreen(work: {
      'id': 6,
      'title': 'Essay',
      'description': 'My holiday.',
      'submission_type': 'text',
    })));
    await tester.pumpAndSettle();

    expect(find.text('Take photo'), findsNothing);
    expect(find.text('Your answer'), findsOneWidget);
  });

  testWidgets('marked homework is read-only and shows the mark', (tester) async {
    await tester.pumpWidget(_app(const HandInScreen(work: {
      'id': 7,
      'title': 'Fractions',
      'description': 'Page 12.',
      'submission_type': 'text_or_file',
      'submitted': true,
      'status': 'graded',
      'marks_awarded': 8,
      'marks_available': 10,
      'teacher_feedback': 'Neat work.',
    })));
    await tester.pumpAndSettle();

    expect(find.text('Marked: 8/10'), findsOneWidget);
    expect(find.text('Teacher: Neat work.'), findsOneWidget);
    expect(find.text('Hand in again'), findsNothing);
    expect(find.text('Take photo'), findsNothing);
  });

  testWidgets('a teacher sees who handed in, who has not, and can mark', (tester) async {
    tester.view.physicalSize = const Size(1080, 3000);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(_app(const SubmissionsScreen(homeworkId: 5, title: 'Simple interest', maxScore: 10), [
      submissionsProvider(5).overrideWith((ref) async => {
            'summary': {'class_size': 3, 'submitted': 2, 'late': 1, 'marked': 0},
            'submissions': [
              {'student_id': 1, 'student_name': 'Ada', 'submitted': true, 'submission_id': 11, 'submitted_at': '2026-10-14T18:00:00+01:00', 'body': 'Answers attached', 'files': [{'id': 1, 'name': 'page1.jpg'}]},
              {'student_id': 2, 'student_name': 'Bayo', 'submitted': true, 'submission_id': 12, 'is_late': true, 'submitted_at': '2026-10-16T08:00:00+01:00', 'files': []},
              {'student_id': 3, 'student_name': 'Chika', 'submitted': false, 'files': []},
            ],
          }),
    ]));
    await tester.pumpAndSettle();

    expect(find.text('2/3'), findsOneWidget);
    expect(find.text('Handed in (2)'), findsOneWidget);
    expect(find.text('Not handed in (1)'), findsOneWidget);
    expect(find.text('Chika'), findsOneWidget);
    expect(find.text('📎 page1.jpg'), findsOneWidget);
    expect(find.text('Save marks'), findsOneWidget);
  });
}
