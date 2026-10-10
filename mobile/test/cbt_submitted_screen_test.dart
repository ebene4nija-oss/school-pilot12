import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/cbt/cbt_submitted_screen.dart';

void main() {
  testWidgets('results held back: reassures and says what happens next', (tester) async {
    await tester.pumpWidget(const MaterialApp(
      home: CbtSubmittedScreen(
        answered: 38,
        total: 40,
        result: {
          'exam_title': 'JSS 2 Mathematics CA 1',
          'submitted_at': '2026-10-10T09:15:00+01:00',
          'message': 'Submitted. Results will be released by your school.',
        },
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Paper submitted'), findsOneWidget);
    expect(find.text('JSS 2 Mathematics CA 1'), findsOneWidget);
    expect(find.text('38 of 40'), findsOneWidget);
    expect(find.textContaining('Your school will release your results'), findsOneWidget);
    expect(find.text('YOUR SCORE'), findsNothing);
    expect(find.text('Done'), findsOneWidget);
  });

  testWidgets('results shown at once: the score and grade', (tester) async {
    await tester.pumpWidget(const MaterialApp(
      home: CbtSubmittedScreen(
        answered: 20,
        total: 20,
        result: {'percentage': 85.0, 'grade': 'A1', 'passed': true, 'time_spent_seconds': 1230},
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('85%'), findsOneWidget);
    expect(find.text('A1'), findsOneWidget);
    expect(find.text('20 min 30 s'), findsOneWidget);
  });

  testWidgets('Done leaves the screen', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: Builder(
        builder: (context) => TextButton(
          onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(
            builder: (_) => const CbtSubmittedScreen(answered: 1, total: 1),
          )),
          child: const Text('open'),
        ),
      ),
    ));
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Done'));
    await tester.pumpAndSettle();

    expect(find.text('Paper submitted'), findsNothing);
  });
}
