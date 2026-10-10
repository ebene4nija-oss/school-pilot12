import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/attendance/my_attendance_screen.dart';

void main() {
  testWidgets('the rate, absences and every marked day', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        myAttendanceProvider(null).overrideWith((ref) async => {
              'term': {'id': 3, 'name': 'First Term'},
              'summary': {'marked_days': 4, 'present': 3, 'late': 1, 'absent': 1, 'excused': 0, 'rate': 75},
              'days': [
                {'date': '2026-09-17', 'status': 'present'},
                {'date': '2026-09-16', 'status': 'absent'},
                {'date': '2026-09-15', 'status': 'late'},
              ],
              'terms': [
                {'id': 3, 'name': 'First Term'},
              ],
            }),
      ],
      child: const MaterialApp(home: MyAttendanceScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('75%'), findsOneWidget);
    expect(find.text('4 days marked'), findsOneWidget);
    expect(find.text('Absent'), findsWidgets);
    expect(find.text('Late'), findsOneWidget);
    expect(find.text('Thu 17 Sep'), findsOneWidget);
  });
}
