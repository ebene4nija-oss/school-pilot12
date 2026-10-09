import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/more/approvals_screen.dart';

void main() {
  test('every kind the server sends has a way to decide it', () {
    for (final type in approvalSections.keys) {
      expect(approvalActions[type], isNotEmpty, reason: type);
      // Turning something down always asks why.
      expect(approvalActions[type]!.where((a) => !a.primary).any((a) => a.needsReason), isTrue, reason: type);
    }
  });

  testWidgets('pending items are grouped by kind with their own decisions', (tester) async {
    tester.view.physicalSize = const Size(1080, 3000);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(ProviderScope(
      overrides: [
        approvalsProvider.overrideWith((ref) async => {
              'total': 3,
              'items': [
                {'type': 'leave', 'id': 1, 'title': 'Mrs Okafor — sick leave', 'detail': '12 Oct – 13 Oct · 2 day(s)', 'note': 'Malaria'},
                {'type': 'admission', 'id': 2, 'title': 'Tobi Ade', 'detail': 'for JSS 1 · ADM-1'},
                {'type': 'lesson_note', 'id': 3, 'title': 'Fractions notes', 'detail': 'Mathematics · JSS 2'},
              ],
            }),
      ],
      child: const MaterialApp(home: ApprovalsScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Staff leave (1)'), findsOneWidget);
    expect(find.text('Admission applications (1)'), findsOneWidget);
    expect(find.text('Lesson notes (1)'), findsOneWidget);
    expect(find.text('“Malaria”'), findsOneWidget);
    expect(find.text('Offer'), findsOneWidget);
    expect(find.text('Waitlist'), findsOneWidget);

    // Declining asks for a reason before anything is sent.
    await tester.tap(find.text('Decline').first);
    await tester.pumpAndSettle();
    expect(find.text('Reason — they will see this'), findsOneWidget);
  });
}
