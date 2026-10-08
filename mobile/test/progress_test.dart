import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/features/progress/progress_screen.dart';

Future<void> _pump(WidgetTester tester, Map<String, dynamic> progress, Map<String, dynamic> mastery) async {
  await tester.pumpWidget(MaterialApp(
    home: Scaffold(body: ListView(children: ProgressView.sections(progress, mastery))),
  ));
}

void main() {
  testWidgets('released results, change since last term and homework done', (tester) async {
    await _pump(tester, {
      'empty': false,
      'period': {'label': 'First Term 2026/2027'},
      'overview': {'average': 68.4, 'change_points': 3.2, 'subjects_passed': 8, 'subjects_counted': 9, 'position': null},
      'subjects': [
        {'subject': 'Mathematics', 'total': 72, 'grade': 'B', 'change': 5},
        {'subject': 'Basic Science', 'total': 45, 'grade': 'D', 'change': -4},
      ],
      'completion': {'assigned': 12, 'completed': 10, 'missed': 2},
      'attendance': {'totals': {'rate': 94}},
    }, const {});

    expect(find.text('68.4%'), findsOneWidget);
    expect(find.text('+3.2 since last term'), findsOneWidget);
    expect(find.text('8/9'), findsOneWidget);
    expect(find.text('10/12'), findsOneWidget);
    expect(find.text('2 missed'), findsOneWidget);
    expect(find.text('72 (B)'), findsOneWidget);
    expect(find.text('▼ 4'), findsOneWidget);
    // The school hides positions: none shown.
    expect(find.textContaining('Position'), findsNothing);
  });

  testWidgets('before any release, topics still show and nothing pretends to be a result', (tester) async {
    await _pump(tester, {'empty': true, 'overview': null}, {
      'needs_work': [
        {'subject': 'Mathematics', 'topic': 'Fractions', 'mastery_percentage': 35},
      ],
      'mastered': [],
      'developing': [],
    });

    expect(find.text('No released results yet'), findsOneWidget);
    expect(find.text('Needs work'), findsOneWidget);
    expect(find.text('Fractions · Mathematics'), findsOneWidget);
  });

  testWidgets('the leaderboard marks the student themself', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          leaderboardProvider.overrideWith((ref) async => Cached<dynamic>(
                data: {
                  'data': [
                    {'rank': 1, 'name': 'Ada O.', 'class': 'JSS 2', 'points': 340, 'is_me': false},
                    {'rank': 2, 'name': 'Tunde B.', 'class': 'JSS 2', 'points': 310, 'is_me': true},
                  ],
                },
                fetchedAt: DateTime.now(),
              )),
        ],
        child: const MaterialApp(home: LeaderboardScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Tunde B. (you)'), findsOneWidget);
    expect(find.text('340 pts'), findsOneWidget);
  });
}
