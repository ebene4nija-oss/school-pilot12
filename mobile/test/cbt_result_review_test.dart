import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/api/api_client.dart';
import 'package:schoolpilot/core/providers.dart';
import 'package:schoolpilot/features/learn/my_tests_screen.dart';

/// Answers with one released result.
class _ResultApi implements ApiClient {
  _ResultApi(this.result);

  final Map<String, dynamic> result;

  @override
  Future<T> get<T>(String path, {Map<String, dynamic>? query, dynamic cancelToken}) async =>
      <String, dynamic>{'released': true, 'result': result} as T;

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

Map<String, dynamic> _result({required bool closed}) => {
      'attempt_id': 1,
      'exam_title': 'CA 1',
      'raw_score': 0,
      'max_score': 1,
      'percentage': 0,
      'grade': 'F9',
      'passed': false,
      'topic_breakdown': <dynamic>[],
      'review': {'answers': true, 'correct_answers': closed, 'correct_answers_after_close': !closed},
      'questions': [
        {
          'question_id': 1,
          'question': '2 + 2',
          'is_correct': false,
          'awarded_marks': 0,
          'marks': 1,
          // The app saves a choice as {'option': 'A'}.
          'your_response': {'option': 'A'},
          if (closed) 'correct_answer': 'B',
          if (closed) 'options': {'A': '3', 'B': '4'},
        },
      ],
    };

Future<void> _pump(WidgetTester tester, Map<String, dynamic> result) async {
  await tester.pumpWidget(ProviderScope(
    overrides: [apiClientProvider.overrideWithValue(_ResultApi(result))],
    child: const MaterialApp(home: TestResultScreen(attemptId: 1, title: 'CA 1')),
  ));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('a chosen option shows as the answer, not "(no answer)"', (tester) async {
    await _pump(tester, _result(closed: false));

    expect(find.text('Your answer: A'), findsOneWidget);
    expect(find.textContaining('Correct answer'), findsNothing);
    expect(find.text('The correct answers will show here after the test closes.'), findsOneWidget);
  });

  testWidgets('once the test closes, the correct option shows beside theirs', (tester) async {
    await _pump(tester, _result(closed: true));

    expect(find.text('Your answer: A. 3'), findsOneWidget);
    expect(find.text('Correct answer: B. 4'), findsOneWidget);
  });
}
