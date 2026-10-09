import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/auth/session.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/core/providers.dart';
import 'package:schoolpilot/features/finance/fees_overview.dart';
import 'package:schoolpilot/features/finance/fees_screen.dart';

Map<String, dynamic> _row(int invoice, int student, String name, String cls, num balance, int days, String band, {String? phone}) => {
      'invoice_id': invoice,
      'student_id': student,
      'student_name': name,
      'class': cls,
      'total_amount': 100000,
      'balance': balance,
      'days_overdue': days,
      'ageing_bucket': band,
      'contacts': [
        {'name': 'Parent of $name', 'phone': phone, 'relationship': 'guardian'},
      ],
    };

Widget _screen() => ProviderScope(
      overrides: [
        currentRoleProvider.overrideWith((ref) => UserRole.bursar),
        collectedSinceProvider.overrideWith((ref, from) async => 250000),
        defaultersProvider.overrideWith((ref) async => Cached<dynamic>(
              data: {
                'summary': {
                  'defaulting_students': 3,
                  'total_outstanding': 180000,
                  'by_ageing': {
                    '1-30_days': {'invoices': 1, 'amount': 30000},
                    'over_90_days': {'invoices': 2, 'amount': 150000},
                  },
                },
                'defaulters': [
                  _row(1, 11, 'Ada Obi', 'JSS 1', 30000, 12, '1-30_days', phone: '08030000001'),
                  _row(2, 12, 'Bayo Ade', 'JSS 2', 100000, 120, 'over_90_days'),
                  _row(3, 13, 'Chika Eze', 'JSS 2', 50000, 95, 'over_90_days', phone: '08030000003'),
                ],
              },
              fetchedAt: DateTime.now(),
            )),
      ],
      child: const MaterialApp(home: FeesOverviewScreen()),
    );

void main() {
  testWidgets('shows money in, money owed, how old it is and who owes it', (tester) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(_screen());
    await tester.pumpAndSettle();

    expect(find.text('Today'), findsOneWidget);
    expect(find.text('This week'), findsOneWidget);
    expect(find.textContaining('3 students owe'), findsOneWidget);
    expect(find.text('Over 90 days · 2'), findsOneWidget);
    expect(find.text('Who owes (3)'), findsOneWidget);
    expect(find.text('Remind all 3'), findsOneWidget);

    // Largest balance first by default.
    expect(
      tester.getTopLeft(find.text('Bayo Ade')).dy,
      lessThan(tester.getTopLeft(find.text('Chika Eze')).dy),
    );

    // A family with no number on file cannot be called.
    final noPhone = find.byTooltip('No phone number on file');
    expect(noPhone, findsOneWidget);

    // Filtering by class narrows the list and the reminder.
    await tester.tap(find.text('JSS 1').first);
    await tester.pumpAndSettle();
    expect(find.text('Who owes (1)'), findsOneWidget);
    expect(find.text('Remind all 1'), findsOneWidget);
  });

  testWidgets('selecting families reminds just them, free channel on by default', (tester) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(_screen());
    await tester.pumpAndSettle();

    await tester.tap(find.byType(Checkbox).first);
    await tester.pumpAndSettle();
    expect(find.text('Remind 1 selected'), findsOneWidget);

    await tester.tap(find.text('Remind 1 selected'));
    await tester.pumpAndSettle();

    expect(find.text('Remind parents'), findsOneWidget);
    final inApp = tester.widget<CheckboxListTile>(find.widgetWithText(CheckboxListTile, 'In-app notification'));
    final sms = tester.widget<CheckboxListTile>(find.widgetWithText(CheckboxListTile, 'SMS'));
    expect(inApp.value, isTrue);
    expect(sms.value, isFalse);
    expect(find.text('Send to 1 family'), findsOneWidget);
  });
}
