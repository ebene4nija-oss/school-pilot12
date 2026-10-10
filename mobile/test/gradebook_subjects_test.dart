import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/gradebook/gradebook_tab.dart';

void main() {
  // Shaped like /teachers/{id}/subjects: one row per subject and class.
  final rows = <Map<String, dynamic>>[
    {'subject_id': 1, 'subject_name': 'Mathematics', 'class_id': 10, 'class_name': 'JSS 1'},
    {'subject_id': 1, 'subject_name': 'Mathematics', 'class_id': 11, 'class_name': 'JSS 2'},
    {'subject_id': 2, 'subject_name': 'Basic Science', 'class_id': 11, 'class_name': 'JSS 2'},
    {'subject_id': 3, 'subject_name': 'Civic Education', 'class_id': null, 'class_name': null},
  ];

  test('the subject name comes from subject_name', () {
    expect(subjectNameOf(rows.first), 'Mathematics');
    expect(subjectNameOf({'name': 'English'}), 'English');
    expect(subjectNameOf({'subject': {'name': 'Yoruba'}}), 'Yoruba');
  });

  test('a class shows only its subjects, each once', () {
    final jss2 = subjectsForClass(rows, 11).map(subjectNameOf).toList();
    expect(jss2, ['Mathematics', 'Basic Science', 'Civic Education']);

    final jss1 = subjectsForClass(rows, 10).map(subjectNameOf).toList();
    expect(jss1, ['Mathematics', 'Civic Education']);
  });

  test('with no class chosen every row is listed', () {
    expect(subjectsForClass(rows, null), hasLength(4));
  });
}
