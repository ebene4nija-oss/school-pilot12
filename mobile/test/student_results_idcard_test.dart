import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/api/api_client.dart';
import 'package:schoolpilot/core/api/api_exception.dart';
import 'package:schoolpilot/core/auth/session.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/core/data/reference_data.dart';
import 'package:schoolpilot/core/providers.dart';
import 'package:schoolpilot/features/idcard/id_card_screen.dart';
import 'package:schoolpilot/features/learn/learning_widgets.dart';
import 'package:schoolpilot/features/results/results_screen.dart';

/// Records the paths asked for; answers with [reply] or throws [error].
class _Api implements ApiClient {
  _Api({this.reply = const <String, dynamic>{}, this.error});

  final Object reply;
  final Object? error;
  final paths = <String>[];

  @override
  Future<T> get<T>(String path, {Map<String, dynamic>? query, dynamic cancelToken}) async {
    paths.add(path);
    if (error != null) throw error!;
    return reply as T;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  test("a student's results are fetched by student id, not login id", () async {
    final api = _Api();
    final container = ProviderContainer(overrides: [
      apiClientProvider.overrideWithValue(api),
      currentRoleProvider.overrideWithValue(UserRole.student),
      currentUserProvider.overrideWithValue(
        const AuthUser(id: 900, name: 'Ada', email: 'a@x', role: UserRole.student),
      ),
      selectedTermProvider.overrideWith((ref) => 3),
      myDayProvider.overrideWith((ref) async => Cached<dynamic>(
            data: {'student': {'id': 42, 'name': 'Ada'}},
            fetchedAt: DateTime.now(),
          )),
    ]);
    addTearDown(container.dispose);

    await container.read(resultSummaryProvider.future);

    expect(api.paths.single, endsWith('/result-checker/42/3/summary'));
  });

  testWidgets('a school without digital cards says so instead of failing', (tester) async {
    final api = _Api(
      error: ApiException(message: 'This school does not issue digital ID cards.', statusCode: 404),
    );
    await tester.pumpWidget(ProviderScope(
      overrides: [apiClientProvider.overrideWithValue(api)],
      child: const MaterialApp(home: IdCardScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('No digital ID card'), findsOneWidget);
    expect(find.textContaining('does not issue digital ID cards'), findsOneWidget);
  });

  testWidgets('a card not issued yet reads "No card yet"', (tester) async {
    final api = _Api(reply: <String, dynamic>{'card': null});
    await tester.pumpWidget(ProviderScope(
      overrides: [apiClientProvider.overrideWithValue(api)],
      child: const MaterialApp(home: IdCardScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('No card yet'), findsOneWidget);
  });

  Widget results(Map<String, dynamic> summary) => ProviderScope(
        overrides: [
          selectedTermProvider.overrideWith((ref) => 3),
          resultSummaryProvider.overrideWith((ref) async => summary),
        ],
        child: const MaterialApp(home: ResultsScreen()),
      );

  testWidgets('a released result (status: released) offers the report card', (tester) async {
    await tester.pumpWidget(results({
      'student_id': 42, 'student_name': 'Ada Obi', 'term_id': 3, 'term_name': 'First Term',
      'status': 'released', 'unlocked': true, 'pin_required': false,
      'preview': {'subjects_count': 9, 'average': 71.4},
    }));
    await tester.pumpAndSettle();

    expect(find.text('Open report card'), findsOneWidget);
    expect(find.text('71.4%'), findsOneWidget);
    expect(find.text('Results not released yet'), findsNothing);
  });

  testWidgets("an unreleased result gives the server's reason", (tester) async {
    await tester.pumpWidget(results({
      'status': 'no_scores',
      'message': 'No scores have been recorded for this student this term. Please contact the school.',
    }));
    await tester.pumpAndSettle();

    expect(find.textContaining('No scores have been recorded'), findsOneWidget);
  });
}
