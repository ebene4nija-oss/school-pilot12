import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/features/learn/learn_tab.dart';
import 'package:schoolpilot/features/learn/learning_widgets.dart';

Cached<dynamic> _c(Object data) => Cached<dynamic>(data: data, fetchedAt: DateTime.now());

void main() {
  testWidgets('exams from /cbt/available show resume, attempts and used-up states', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        homeworkProvider.overrideWith((ref) async => _c(const <String, dynamic>{})),
        myLearningProvider.overrideWith((ref) async => _c(const <String, dynamic>{})),
        availableExamsProvider.overrideWith((ref) async => _c({
              'data': [
                // The payload names the exam `exam_id`, not `id`.
                {'exam_id': 7, 'title': 'Maths test', 'subject': 'Mathematics', 'duration_minutes': 40, 'attempts_used': 0, 'attempts_allowed': 1, 'can_start': true, 'resumable_attempt_id': 55},
                {'exam_id': 8, 'title': 'English test', 'subject': 'English', 'duration_minutes': 30, 'attempts_used': 1, 'attempts_allowed': 2, 'can_start': true},
                {'exam_id': 9, 'title': 'Science test', 'subject': 'Science', 'duration_minutes': 30, 'attempts_used': 1, 'attempts_allowed': 1, 'can_start': false},
              ],
            })),
      ],
      child: const MaterialApp(home: LearnTab()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Resume'), findsOneWidget);
    expect(find.text('Attempt 2 of 2'), findsOneWidget);
    expect(find.text('No attempts left'), findsOneWidget);

    final usedUp = tester.widget<FilledButton>(find.ancestor(
      of: find.text('No attempts left'),
      matching: find.byWidgetPredicate((w) => w is FilledButton),
    ));
    expect(usedUp.onPressed, isNull);
  });
}
