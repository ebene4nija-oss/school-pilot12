import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/students/academic_history_screen.dart';

void main() {
  Widget app(Map<String, dynamic> data) => ProviderScope(
        overrides: [academicHistoryProvider('/x').overrideWith((ref) async => data)],
        child: const MaterialApp(home: AcademicHistoryScreen(path: '/x')),
      );

  Map<String, dynamic> term(String name, String session, double avg, int pos) => {
        'term': name,
        'session': session,
        'class': 'JSS 2',
        'verified': true,
        'figures': {
          'average': avg,
          'position': pos,
          'class_size': 30,
          'subjects': [
            {'subject': 'Mathematics', 'total': avg + 4, 'grade': 'B2'},
            {'subject': 'English', 'total': avg - 4, 'grade': 'C4'},
          ],
          'form_teacher_comment': 'Steady improvement.',
        },
      };

  testWidgets('progress over time, then each term with its subjects', (tester) async {
    tester.view.physicalSize = const Size(1080, 3600);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(app({
      'student': {'name': 'Ada Obi', 'class': 'JSS 2', 'admission_number': 'GC/001'},
      'terms': [term('First Term', '2025/2026', 58.0, 12), term('Second Term', '2025/2026', 66.5, 6)],
      'sessions': [
        {'session': '2025/2026', 'terms': 2, 'session_average': 62.25},
      ],
      'timeline': [
        {'kind': 'enrolment', 'session': '2025/2026', 'class': 'JSS 2 A', 'outcome': 'promoted'},
      ],
      'previous_schools': [],
      'partial_history': false,
    }));
    await tester.pumpAndSettle();

    expect(find.text('Ada Obi'), findsOneWidget);
    expect(find.byType(LineChart), findsOneWidget);
    expect(find.textContaining('▲ 8.5'), findsOneWidget);
    expect(find.text('62.3%'), findsOneWidget);
    expect(find.textContaining('6th of 30'), findsOneWidget);

    // A term opens to its subjects.
    await tester.tap(find.textContaining('Second Term'));
    await tester.pumpAndSettle();
    expect(find.text('Mathematics'), findsOneWidget);
    expect(find.text('B2'), findsOneWidget);
    expect(find.textContaining('Steady improvement.'), findsOneWidget);
  });

  testWidgets('no released terms yet says so', (tester) async {
    await tester.pumpWidget(app({
      'student': {'name': 'New Student'},
      'terms': [],
      'sessions': [],
      'timeline': [],
      'previous_schools': [],
    }));
    await tester.pumpAndSettle();

    expect(find.text('No term records yet'), findsOneWidget);
  });
}
