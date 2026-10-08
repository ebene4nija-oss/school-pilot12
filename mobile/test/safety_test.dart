import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/child/safety_screen.dart';

Future<void> _pump(WidgetTester tester, {List<Map<String, dynamic>> pickups = const [], Map<String, dynamic>? consent}) {
  return tester.pumpWidget(
    ProviderScope(
      child: MaterialApp(
        home: Scaffold(body: RefreshableSafety(childId: 5, pickups: pickups, consent: consent)),
      ),
    ),
  );
}

void main() {
  testWidgets('only people still allowed to collect can be stopped', (tester) async {
    await _pump(tester, pickups: [
      {'id': 1, 'student_id': 5, 'authorized_person_name': 'Auntie Ngozi', 'authorized_person_phone': '08031234567', 'relationship': 'Aunt', 'is_active': true},
      {'id': 2, 'student_id': 5, 'authorized_person_name': 'Mr Musa', 'authorized_person_phone': '08099999999', 'relationship': 'Driver', 'is_active': false},
    ]);

    expect(find.text('Auntie Ngozi'), findsOneWidget);
    expect(find.text('Stop'), findsOneWidget);
    expect(find.text('Stopped'), findsOneWidget);
  });

  testWidgets('consent is never pre-ticked, and AI consent needs records consent first', (tester) async {
    await _pump(tester);

    final boxes = tester.widgetList<CheckboxListTile>(find.byType(CheckboxListTile)).toList();
    expect(boxes.map((b) => b.value), [false, false]);
    expect(boxes[1].onChanged, isNull, reason: 'AI consent cannot be given without records consent');
    expect(find.textContaining('Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini)'), findsOneWidget);
    expect(find.text('Withdraw'), findsNothing);

    await tester.tap(find.byType(CheckboxListTile).first);
    await tester.pump();
    expect(tester.widgetList<CheckboxListTile>(find.byType(CheckboxListTile)).last.onChanged, isNotNull);
  });

  testWidgets('consent on record is shown and can be withdrawn', (tester) async {
    await _pump(tester, consent: {'id': 7, 'student_id': 5, 'consent_given': true, 'ai_cross_border_consent_given': false});

    final boxes = tester.widgetList<CheckboxListTile>(find.byType(CheckboxListTile)).toList();
    expect(boxes.map((b) => b.value), [true, false]);
    await tester.scrollUntilVisible(find.text('Withdraw'), 100);
    expect(find.text('Withdraw'), findsOneWidget);
  });
}
