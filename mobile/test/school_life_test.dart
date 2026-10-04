import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/sync/sync_service.dart';
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
}
