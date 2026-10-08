import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/features/staff/leave_screen.dart';
import 'package:schoolpilot/features/teaching/ai_studio_screen.dart';

void main() {
  group('AI Studio drafts as text', () {
    test('a lesson plan reads top to bottom, missing parts left out', () {
      final lines = DraftView.textOf(StudioTool.lessonPlan, {
        'subject': 'Mathematics',
        'topic': 'Simple interest',
        'class_level': 'JSS 2',
        'duration_minutes': 40,
        'objectives': ['Students will be able to calculate simple interest.'],
        'activities': [
          {'title': 'Worked example', 'minutes': 15, 'description': 'Interest on ₦10,000 at 5% for 2 years.'},
        ],
        'homework': 'Exercise 4b.',
      });

      expect(lines.first, 'Mathematics: Simple interest (JSS 2), 40 minutes');
      expect(lines, contains('• Students will be able to calculate simple interest.'));
      expect(lines, contains('• Worked example (15 min): Interest on ₦10,000 at 5% for 2 years.'));
      expect(lines, contains('Homework:'));
      expect(lines, isNot(contains('Assessment:')));
    });

    test('questions show options and the answer', () {
      final lines = DraftView.textOf(StudioTool.questions, {
        'questions': [
          {'question': 'What is 5% of ₦200?', 'options': {'A': '₦5', 'B': '₦10', 'C': '₦20', 'D': '₦50'}, 'correct_answer': 'B', 'explanation': '200 × 0.05'},
        ],
      });

      expect(lines, ['Q1. What is 5% of ₦200?', '   A. ₦5', '   B. ₦10', '   C. ₦20', '   D. ₦50', '   Answer: B — 200 × 0.05']);
    });
  });

  testWidgets('a placeholder draft says it is not real', (tester) async {
    await tester.pumpWidget(const MaterialApp(
      home: Scaffold(body: DraftView(tool: StudioTool.homework, draft: {'stub': true, 'ideas': []})),
    ));
    expect(find.textContaining('not a real draft'), findsOneWidget);
  });

  testWidgets('leave requests show their decision, and only pending ones can be withdrawn', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          myLeaveProvider.overrideWith((ref) async => Cached<dynamic>(
                data: {
                  'data': [
                    {'id': 1, 'leave_type': 'sick', 'status': 'pending', 'start_date': '2026-10-12', 'end_date': '2026-10-13'},
                    {'id': 2, 'leave_type': 'annual', 'status': 'rejected', 'start_date': '2026-11-02', 'end_date': '2026-11-06', 'decision_note': 'Exam week'},
                  ],
                },
                fetchedAt: DateTime.now(),
              )),
        ],
        child: const MaterialApp(home: LeaveScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Sick leave'), findsOneWidget);
    expect(find.text('Note: Exam week'), findsOneWidget);
    expect(find.text('Withdraw request'), findsOneWidget);
  });
}
