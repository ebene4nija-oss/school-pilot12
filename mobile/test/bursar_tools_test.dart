import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/finance/bursary_tools.dart';

void _tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 3000);
  tester.view.devicePixelRatio = 2.0;
  addTearDown(tester.view.reset);
}

void main() {
  testWidgets('the ledger totals what came in and offers a receipt per paid row', (tester) async {
    _tall(tester);
    await tester.pumpWidget(ProviderScope(
      overrides: [
        ledgerProvider.overrideWith((ref, args) async => {
              'total_received': 75000,
              'data': [
                {'payment_id': 1, 'receipt_number': 'REC-000001', 'amount': 50000, 'gateway': 'bank_transfer', 'status': 'successful', 'paid_at': '2026-10-08T10:00:00+01:00', 'student': 'Ada Obi', 'class': 'JSS 1'},
                {'payment_id': 2, 'receipt_number': 'REC-000002', 'amount': 25000, 'gateway': 'paystack', 'status': 'pending', 'created_at': '2026-10-09T09:00:00+01:00', 'student': 'Bayo Ade', 'class': 'JSS 2'},
              ],
            }),
      ],
      child: const MaterialApp(home: PaymentsLedgerScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Received'), findsOneWidget);
    expect(find.text('Ada Obi'), findsOneWidget);
    final shares = tester.widgetList<IconButton>(find.ancestor(of: find.byIcon(Icons.ios_share), matching: find.byType(IconButton))).toList();
    expect(shares.length, 2);
    expect(shares[0].onPressed, isNotNull); // paid
    expect(shares[1].onPressed, isNull); // still pending: no receipt yet
  });

  testWidgets('expenses and petty cash show totals and the box balance', (tester) async {
    _tall(tester);
    await tester.pumpWidget(ProviderScope(
      overrides: [
        expensesProvider.overrideWith((ref) async => {
              'total': 120000,
              'categories': ['Utilities'],
              'data': [
                {'id': 1, 'title': 'Diesel', 'category': 'Utilities', 'amount': 120000, 'payment_method': 'cash', 'expense_date': '2026-10-07'},
              ],
            }),
        pettyCashProvider.overrideWith((ref) async => {
              'balance': 8500,
              'data': [
                {'id': 1, 'type': 'disbursement', 'amount': 1500, 'purpose': 'Chalk', 'entry_date': '2026-10-08', 'custodian': 'Bursar'},
              ],
            }),
      ],
      child: const MaterialApp(home: ExpensesScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Total spent'), findsOneWidget);
    expect(find.text('Diesel'), findsOneWidget);

    await tester.tap(find.text('Petty cash'));
    await tester.pumpAndSettle();
    expect(find.text('In the box'), findsOneWidget);
    expect(find.text('Chalk'), findsOneWidget);
  });

  testWidgets('a scholarship is chosen by name and size before it can be applied', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        scholarshipsProvider.overrideWith((ref) async => [
              {'id': 1, 'name': 'Staff child', 'type': 'percentage', 'value': 50},
              {'id': 2, 'name': 'Sibling', 'type': 'fixed', 'value': 10000},
            ]),
      ],
      child: const MaterialApp(home: Scaffold(body: ApplyScholarshipSheet(invoice: {'invoice_id': 9, 'invoice_number': 'INV-9', 'total_amount': 150000}))),
    ));
    await tester.pumpAndSettle();

    final apply = tester.widget<FilledButton>(find.ancestor(of: find.text('Apply to this bill'), matching: find.byWidgetPredicate((w) => w is FilledButton)));
    expect(apply.onPressed, isNull);

    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pumpAndSettle();
    expect(find.text('Staff child · 50% off'), findsWidgets);
    await tester.tap(find.text('Staff child · 50% off').last);
    await tester.pumpAndSettle();

    final ready = tester.widget<FilledButton>(find.ancestor(of: find.text('Apply to this bill'), matching: find.byWidgetPredicate((w) => w is FilledButton)));
    expect(ready.onPressed, isNotNull);
  });
}
