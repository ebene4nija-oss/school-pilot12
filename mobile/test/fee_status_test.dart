import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/reference_data.dart';
import 'package:schoolpilot/features/finance/fee_status_screen.dart';

Map<String, dynamic> _totals(int students, num billed, num paid, Map<String, int> counts) => {
      'students': students,
      'billed': billed,
      'paid': paid,
      'outstanding': billed - paid,
      'collection_rate': billed == 0 ? null : (paid / billed * 100).roundToDouble(),
      'counts': {'paid': 0, 'part_paid': 0, 'owing': 0, 'not_invoiced': 0, ...counts},
    };

void main() {
  testWidgets('who has paid narrows by arm and status from one fetch', (tester) async {
    // Tall enough that the whole list is built.
    tester.view.physicalSize = const Size(800, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    var fetches = 0;
    final report = {
      'totals': _totals(3, 300000, 140000, {'paid': 1, 'part_paid': 1, 'owing': 1}),
      'classes': [
        {
          'class_id': 8,
          'class': 'JSS 2',
          ..._totals(3, 300000, 140000, {'paid': 1, 'part_paid': 1, 'owing': 1}),
          'arms': [
            {'arm_id': 1, 'arm': 'A', ..._totals(2, 200000, 140000, {'paid': 1, 'part_paid': 1})},
            {'arm_id': 2, 'arm': 'B', ..._totals(1, 100000, 0, {'owing': 1})},
          ],
        },
      ],
      'students': [
        {'student_id': 1, 'name': 'Ada Obi', 'class_id': 8, 'class': 'JSS 2', 'arm_id': 1, 'arm': 'A', 'balance': 0, 'status': 'paid'},
        {'student_id': 2, 'name': 'Chidi Eze', 'class_id': 8, 'class': 'JSS 2', 'arm_id': 1, 'arm': 'A', 'balance': 60000, 'status': 'part_paid'},
        {'student_id': 3, 'name': 'Bisi Ade', 'class_id': 8, 'class': 'JSS 2', 'arm_id': 2, 'arm': 'B', 'balance': 100000, 'status': 'owing'},
      ],
    };

    await tester.pumpWidget(ProviderScope(
      overrides: [
        termsProvider.overrideWith((ref) async => const [TermRef(id: 5, name: 'First Term', isCurrent: true)]),
        feeStatusProvider.overrideWith((ref, args) async {
          fetches++;
          return report;
        }),
      ],
      child: const MaterialApp(home: FeeStatusScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Who has paid'), findsOneWidget);
    expect(find.text('1 of 3 cleared · 47.0% collected'), findsOneWidget);
    expect(find.text('Bisi Ade'), findsOneWidget);

    // Owing only.
    await tester.tap(find.text('Owing (1)'));
    await tester.pumpAndSettle();
    expect(find.text('Bisi Ade'), findsOneWidget);
    expect(find.text('Ada Obi'), findsNothing);

    // A class, then one arm: no new fetch.
    await tester.tap(find.text('Owing (1)'));
    await tester.tap(find.text('Every class'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('JSS 2 · 1/3 paid').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('A · 1/2'));
    await tester.pumpAndSettle();

    expect(find.text('Ada Obi'), findsOneWidget);
    expect(find.text('Bisi Ade'), findsNothing);
    expect(fetches, 1);
  });
}
