import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/finance/bursary_screens.dart';

void main() {
  testWidgets("a student's invoices show what is owed, and only owing ones take a payment", (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          bursarStatementProvider.overrideWith((ref, id) async => {
                'balance': 45000,
                'total_paid': 55000,
                'invoices': [
                  {
                    'invoice_id': 1, 'invoice_number': 'INV-1', 'term': 'First Term',
                    'total_amount': 100000, 'amount_paid': 55000, 'balance': 45000,
                    'payments': [
                      {'receipt_number': 'REC-000007', 'amount': 55000, 'gateway': 'bank_transfer', 'paid_at': '2026-09-20T10:00:00Z'},
                    ],
                  },
                  {'invoice_id': 2, 'invoice_number': 'INV-0', 'term': 'Third Term', 'total_amount': 90000, 'amount_paid': 90000, 'balance': 0, 'payments': []},
                ],
              }),
        ],
        child: const MaterialApp(home: BursarStudentScreen(studentId: 5, name: 'Ada Obi')),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Ada Obi'), findsOneWidget);
    expect(find.textContaining('REC-000007'), findsOneWidget);
    expect(find.text('Record payment'), findsOneWidget, reason: 'the settled invoice offers no payment');
  });

  testWidgets('the payment sheet refuses more than the balance before sending anything', (tester) async {
    await tester.pumpWidget(const ProviderScope(
      child: MaterialApp(
        home: Scaffold(body: RecordPaymentSheet(invoice: {'invoice_id': 1, 'invoice_number': 'INV-1', 'balance': 45000})),
      ),
    ));

    // Starts at the full balance.
    expect(find.text('45000.00'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, '50000');
    await tester.tap(find.widgetWithText(FilledButton, 'Record payment'));
    await tester.pump();

    expect(find.textContaining('more than the'), findsOneWidget);
  });

  testWidgets('transfers waiting for the bursar can be approved or rejected', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          pendingTransfersProvider.overrideWith((ref) async => [
                {'payment_id': 3, 'student': 'Tunde Bello', 'amount': 20000, 'reference': 'TRF-88', 'created_at': '2026-10-08T09:00:00Z'},
              ]),
        ],
        child: const MaterialApp(home: TransfersToConfirmScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Tunde Bello'), findsOneWidget);
    expect(find.text('Approve'), findsOneWidget);
    expect(find.text('Reject'), findsOneWidget);
  });
}
