import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/auth/session.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/core/providers.dart';
import 'package:schoolpilot/features/finance/fees_screen.dart';

/// The statement as the API sends it (`total_invoiced`, `total_paid`,
/// `balance`). A paid-up family once saw "Fully paid ₦0.00" with ₦205,000
/// in the breakdown below, because the screen read a field the API never sent.
void main() {
  testWidgets('a paid-up statement shows what was paid, not ₦0', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        currentRoleProvider.overrideWith((ref) => UserRole.parent),
        statementProvider.overrideWith((ref) async => Cached<dynamic>(
              data: {
                'total_invoiced': 205000.0,
                'total_paid': 205000.0,
                'balance': 0.0,
                'currency': 'NGN',
                'invoices': [
                  {'invoice_id': 1, 'term': 'First Term', 'total_amount': 205000.0, 'amount_paid': 205000.0, 'balance': 0.0, 'status': 'paid', 'payments': <dynamic>[]},
                ],
              },
              fetchedAt: DateTime.now(),
              stale: false,
            )),
      ],
      child: const MaterialApp(home: FeesScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('FULLY PAID'), findsOneWidget);
    expect(find.text('₦0.00'), findsNothing);
    expect(find.textContaining('paid of'), findsOneWidget);
  });
}
