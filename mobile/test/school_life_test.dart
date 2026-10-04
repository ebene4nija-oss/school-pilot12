import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/core/sync/sync_service.dart';
import 'package:schoolpilot/features/child/child_school_life.dart';
import 'package:schoolpilot/features/hostel/hostel_screen.dart';
import 'package:schoolpilot/features/idcard/id_card_screen.dart';
import 'package:schoolpilot/features/timetable/timetable_screen.dart';

void main() {
  group('refused register rows', () {
    test('a fully accepted register is not flagged', () {
      expect(SyncService.refusedRows({'marked_count': 30, 'rejected': []}), isNull);
      expect(SyncService.refusedRows(null), isNull);
    });

    test('a past-day correction the server refused is kept with its reason', () {
      final line = SyncService.refusedRows({
        'marked_count': 28,
        'rejected': [
          {'student_id': 4, 'reason': 'Only an administrator can change attendance for a past day.'},
          {'student_id': 9, 'reason': 'Only an administrator can change attendance for a past day.'},
        ],
      });

      expect(line, '2 rows were not saved: Only an administrator can change attendance for a past day.');
    });
  });

  group('timetable shapes', () {
    test("a student's week grouped by day becomes one list with days", () {
      final periods = periodsOf({
        'days': {
          'Monday': [
            {'slot_name': 'P1', 'subject_name': 'Basic Science', 'venue': 'Science Lab', 'teacher': 'Mr Chidi'},
          ],
          'Tuesday': [
            {'slot_name': 'P1', 'subject_name': 'English'},
          ],
        },
      });

      expect(periods.map((p) => p['day']), ['Monday', 'Tuesday']);
      expect(periods.first['venue'], 'Science Lab');
    });

    test("a staff timetable's flat entries pass straight through", () {
      final periods = periodsOf({
        'entries': [
          {'id': 3, 'day_of_week': 'Monday', 'venue_name': 'JSS 2 Room', 'teacher_name': 'Mrs Bello'},
        ],
      });

      expect(periods.single['venue_name'], 'JSS 2 Room');
      expect(subjectOf(periods.single), 'Period');
    });

    test('teacher may be a name or an object, subject a name or an object', () {
      expect(subjectOf({'subject': {'name': 'Maths'}}), 'Maths');
      expect(subjectOf({'subject_name': 'Agric'}), 'Agric');
      expect(isBreak({'is_break': 1}), isTrue);
      expect(isBreak({'subject_name': 'Short break'}), isTrue);
    });
  });

  group('school life cards', () {
    Future<void> pump(WidgetTester tester, Widget child) =>
        tester.pumpWidget(MaterialApp(home: Scaffold(body: SingleChildScrollView(child: child))));

    testWidgets('a day student is told there is no boarding place', (tester) async {
      await pump(tester, const HostelSummary(summary: {'boarding': null}));
      expect(find.text('Not a boarder'), findsOneWidget);
    });

    testWidgets("a boarder sees their bed, whether they are in, and their leave", (tester) async {
      await pump(
        tester,
        const HostelSummary(summary: {
          'boarding': {'status': 'active', 'category': 'full'},
          'hostel': 'Unity House',
          'room': 'A2',
          'bed': '3',
          'presence': 'on_leave',
          'leave': [
            {'leave_type': 'weekend', 'depart_at': '2026-10-09T15:00:00Z', 'return_at': '2026-10-11T17:00:00Z', 'status': 'departed'},
          ],
          'charges': [],
        }),
      );
      expect(find.text('Unity House · Room A2 · Bed 3'), findsOneWidget);
      expect(find.text('Away on leave'), findsOneWidget);
      expect(find.text('Away'), findsOneWidget);
    });

    testWidgets('a card that is no longer valid says why and shows no code', (tester) async {
      await pump(
        tester,
        const DigitalIdCard(card: {
          'serial': 'HT-S-0001', 'status': 'lost', 'valid': false, 'headline': 'Reported lost',
          'holder_name': 'Ada Obi', 'role': 'Student', 'school': 'Hilltop', 'photo': null, 'qr': null,
        }),
      );
      expect(find.text('Reported lost'), findsOneWidget);
      expect(find.text('Security staff scan this to check the card.'), findsNothing);
    });
  });

  testWidgets("a parent sees each piece of homework's state, marks and the teacher's note", (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          childLearningProvider.overrideWith((ref, id) async => Cached<dynamic>(
                fetchedAt: DateTime.now(),
                data: {
                  'children': [
                    {
                      'student_id': id,
                      'upcoming_classes': [],
                      'materials': null,
                      'virtual_attendance': {'counts': {'present': 3, 'absent': 1}, 'recent': []},
                      'assignments': {
                        'pending': [
                          {'id': 1, 'title': 'Fractions worksheet', 'subject': 'Maths', 'due_date': '2026-10-09', 'state': 'returned', 'return_note': 'Show your working'},
                        ],
                        'submitted': [],
                        'graded': [
                          {'id': 2, 'title': 'Essay', 'subject': 'English', 'due_date': '2026-10-01', 'state': 'graded', 'marks_awarded': 8, 'marks_available': 10},
                        ],
                      },
                    },
                  ],
                },
              )),
        ],
        child: const MaterialApp(home: Scaffold(body: ChildLearningView(childId: 5))),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Returned to redo'), findsOneWidget);
    expect(find.text('Teacher: Show your working'), findsOneWidget);
    expect(find.text('8 / 10'), findsOneWidget);
    expect(find.text('present: 3'), findsOneWidget);
  });
}
