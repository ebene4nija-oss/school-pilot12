import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/messages/messages_screen.dart';

void main() {
  // Shaped like /messages/threads rows: teacher, parent and student.user.
  final thread = <String, dynamic>{
    'id': 1,
    'teacher_id': 10,
    'parent_id': 20,
    'subject': 'Homework',
    'teacher': {'id': 10, 'name': 'Mrs Adeyemi'},
    'parent': {'id': 20, 'name': 'Mr Okafor'},
    'student': {
      'id': 5,
      'user': {'id': 30, 'name': 'Chidi Okafor'},
    },
  };

  test('the teacher sees the parent, the parent sees the teacher', () {
    expect(threadWith(thread, 10), 'Mr Okafor');
    expect(threadWith(thread, 20), 'Mrs Adeyemi');
  });

  test('the child and subject go underneath', () {
    expect(threadAbout(thread), 'About Chidi Okafor · Homework');
  });

  test('falls back to the subject, then "Conversation"', () {
    expect(threadWith({'subject': 'Fees'}, 10), 'Fees');
    expect(threadWith(<String, dynamic>{}, 10), 'Conversation');
    expect(threadAbout(<String, dynamic>{}), '');
  });
}
