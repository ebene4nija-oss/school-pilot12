import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/auth/session.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/features/dashboard/quick_actions.dart';
import 'package:schoolpilot/features/learn/learn_tab.dart';
import 'package:schoolpilot/features/learn/my_tests_screen.dart';
import 'package:schoolpilot/features/teaching/lesson_notes_screen.dart';

Cached<dynamic> _c(Object data) => Cached<dynamic>(data: data, fetchedAt: DateTime.now());

void main() {
  group('home shortcuts', () {
    test('every school role gets some, and an unknown role none', () {
      for (final role in UserRole.values) {
        final actions = quickActionsFor(role);
        if (role == UserRole.other) {
          expect(actions, isEmpty);
        } else {
          expect(actions.length, greaterThanOrEqualTo(3), reason: role.name);
          expect(actions.map((a) => a.path).toSet().length, actions.length, reason: '${role.name} has a duplicate');
        }
      }
    });

    test('students reach their tests and teachers their CBT and lesson notes', () {
      expect(quickActionsFor(UserRole.student).map((a) => a.path), contains('/my-tests'));
      expect(quickActionsFor(UserRole.teacher).map((a) => a.path), containsAll(['/cbt-exams', '/lesson-notes']));
    });

    test('every shortcut goes to a route the router declares', () {
      final router = File('lib/app/router.dart').readAsStringSync();
      for (final role in UserRole.values) {
        for (final a in quickActionsFor(role)) {
          expect(router, contains("path: '${a.path}'"), reason: '${role.name}: ${a.label}');
        }
      }
    });
  });

  testWidgets('My tests sorts papers into open, coming up and done', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        availableExamsProvider.overrideWith((ref) async => _c({
              'data': [
                {'exam_id': 1, 'title': 'Maths now', 'subject': 'Mathematics', 'duration_minutes': 30, 'can_start': true},
              ],
            })),
        myTestsProvider.overrideWith((ref) async => _c({
              'data': [
                {'exam_id': 1, 'title': 'Maths now', 'state': 'open', 'attempts': <Map<String, dynamic>>[]},
                {'exam_id': 2, 'title': 'English soon', 'subject': 'English', 'state': 'upcoming', 'opens_at': '2026-10-20T09:00:00+01:00', 'duration_minutes': 40, 'attempts': <Map<String, dynamic>>[]},
                {
                  'exam_id': 3,
                  'title': 'Science done',
                  'subject': 'Science',
                  'state': 'closed',
                  'attempts': [
                    {'attempt_id': 30, 'status': 'graded', 'submitted_at': '2026-10-01T10:00:00+01:00', 'percentage': 72.0},
                  ],
                },
                {
                  'exam_id': 4,
                  'title': 'History held back',
                  'state': 'closed',
                  'attempts': [
                    {'attempt_id': 40, 'status': 'submitted', 'submitted_at': '2026-10-02T10:00:00+01:00', 'percentage': null},
                  ],
                },
              ],
            })),
      ],
      child: const MaterialApp(home: MyTestsScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Maths now'), findsOneWidget);
    expect(find.text('Start'), findsOneWidget);
    expect(find.text('English soon'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('72%'), 200);
    expect(find.text('Science done'), findsOneWidget);
    expect(find.text('72%'), findsOneWidget);
    expect(find.text('Awaiting result'), findsOneWidget);
  });

  testWidgets('lesson notes show whether each reached students', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        myLessonNotesProvider.overrideWith((ref) async => [
              {'id': 1, 'title': 'Photosynthesis', 'status': 'published', 'subject': 'Biology', 'class': 'SS 1'},
              {'id': 2, 'title': 'Cell division', 'status': 'pending_approval', 'subject': 'Biology', 'class': 'SS 1'},
              {'id': 3, 'title': 'Osmosis', 'status': 'rejected', 'review_note': 'Wrong class'},
            ]),
      ],
      child: const MaterialApp(home: LessonNotesScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Shared'), findsOneWidget);
    expect(find.text('Awaiting approval'), findsOneWidget);
    expect(find.text('Sent back'), findsOneWidget);
    expect(find.text('Reviewer: Wrong class'), findsOneWidget);
    expect(find.text('Add lesson note'), findsOneWidget);
  });
}
