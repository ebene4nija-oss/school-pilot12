import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/reference_data.dart';
import 'package:schoolpilot/features/attendance/school_today_screen.dart';
import 'package:schoolpilot/features/students/enrol_student_screen.dart';
import 'package:schoolpilot/features/students/student_profile_sheet.dart';

void _tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 3200);
  tester.view.devicePixelRatio = 2.0;
  addTearDown(tester.view.reset);
}

void main() {
  testWidgets('today shows registers still out, who is absent and staff not in', (tester) async {
    _tall(tester);
    await tester.pumpWidget(ProviderScope(
      overrides: [
        schoolTodayProvider.overrideWith((ref) async => {
              'date': '2026-10-09',
              'summary': {'students': 3, 'present': 1, 'late': 0, 'absent': 1, 'classes': 2, 'classes_marked': 1},
              'classes': [
                {'class': 'JSS 1', 'students': 2, 'present': 1, 'late': 0, 'absent': 1, 'marked': true},
                {'class': 'JSS 2', 'students': 1, 'present': 0, 'late': 0, 'absent': 0, 'marked': false},
              ],
              'absent': [
                {'student_id': 2, 'name': 'Bayo Ade', 'class': 'JSS 1', 'guardian_phone': '08031234567'},
                {'student_id': 4, 'name': 'No Number', 'class': 'JSS 1', 'guardian_phone': null},
              ],
              'staff': {
                'total': 2,
                'clocked_in': 1,
                'list': [
                  {'name': 'Mrs Early', 'clocked_in_at': '2026-10-09T07:40:00+01:00'},
                  {'name': 'Mr Late', 'clocked_in_at': null},
                ],
              },
            }),
      ],
      child: const MaterialApp(home: SchoolTodayScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('1/2'), findsWidgets);
    expect(find.text('Registers not taken'), findsOneWidget);
    expect(find.text('Bayo Ade'), findsOneWidget);
    expect(find.byTooltip('Call parent'), findsOneWidget);
    expect(find.byTooltip('No parent number on file'), findsOneWidget);
    expect(find.text('Staff not clocked in (1)'), findsOneWidget);
    expect(find.text('Mr Late'), findsOneWidget);
  });

  testWidgets('a student profile shows the parent with a call button', (tester) async {
    _tall(tester);
    await tester.pumpWidget(ProviderScope(
      overrides: [
        studentQuickViewProvider(7).overrideWith((ref) async => {
              'id': 7,
              'name': 'Ada Obi',
              'class': 'JSS 1',
              'admission_number': 'ADM7',
              'guardian_name': 'Mrs Obi',
              'guardian_phone': '08030000007',
              'attendance_rate': 96.5,
              'latest_average': 71.2,
              'fee_status': 'Owing',
              'missing_fields': ['date_of_birth'],
            }),
      ],
      child: const MaterialApp(home: Scaffold(body: StudentProfileSheet(student: {'id': 7, 'name': 'Ada Obi'}))),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Mrs Obi'), findsOneWidget);
    expect(find.byTooltip('Call parent'), findsOneWidget);
    expect(find.text('97%'), findsOneWidget);
    expect(find.text('71.2%'), findsOneWidget);
    expect(find.text('Owing'), findsOneWidget);
    expect(find.textContaining('Record incomplete: date of birth'), findsOneWidget);
  });

  testWidgets('enrolling needs the child basics before anything is sent', (tester) async {
    _tall(tester);
    await tester.pumpWidget(ProviderScope(
      overrides: [classesProvider.overrideWith((ref) async => const [ClassRef(id: 1, name: 'JSS 1')])],
      child: const MaterialApp(home: EnrolStudentScreen()),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Enrol'));
    await tester.pumpAndSettle();
    expect(find.text("The child's name, gender and class are needed."), findsOneWidget);
  });
}
