import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/api/api_client.dart';
import 'package:schoolpilot/core/auth/session.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/core/db/cache_store.dart';
import 'package:schoolpilot/core/providers.dart';
import 'package:schoolpilot/features/dashboard/dashboard_providers.dart';
import 'package:schoolpilot/features/finance/fees_screen.dart';
import 'package:schoolpilot/features/gradebook/report_entries_screen.dart';
import 'package:schoolpilot/features/learn/learning_widgets.dart';
import 'package:schoolpilot/features/messages/new_message_screen.dart';

/// Records the paths asked for, and answers with an empty statement.
class _RecordingApi implements ApiClient {
  final paths = <String>[];

  @override
  Future<T> get<T>(String path, {Map<String, dynamic>? query, dynamic cancelToken}) async {
    paths.add(path);
    return <String, dynamic>{'invoices': <dynamic>[], 'payments': <dynamic>[]} as T;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _MemoryCache implements CacheStore {
  @override
  Future<void> write(String key, Object payload) async {}

  @override
  Future<CachedPayload<dynamic>?> read(String key) async => null;

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  testWidgets('a pupil is rated on the school\'s own traits and scale', (tester) async {
    tester.view.physicalSize = const Size(1080, 3000);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const ProviderScope(
      child: MaterialApp(
        home: PupilEntryScreen(
          args: (classId: 1, termId: 1),
          settings: {
            'affective_traits': ['Punctuality', 'Neatness'],
            'psychomotor_skills': ['Handwriting'],
            'rating_scale': [
              {'value': '5', 'label': 'Excellent'},
              {'value': '3', 'label': 'Good'},
            ],
            'form_teacher_comment': true,
            'principal_comment': true,
            'principal_title': 'Head teacher',
          },
          student: {'student_id': 7, 'name': 'Ada Obi', 'ratings': {'Punctuality': '5'}},
          canWriteForm: true,
          canWritePrincipal: false,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Punctuality'), findsOneWidget);
    expect(find.text('Handwriting'), findsOneWidget);
    expect(find.text('Excellent'), findsNWidgets(3));
    final chosen = tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Excellent').first);
    expect(chosen.selected, isTrue);
    expect(find.text("Form teacher's comment"), findsOneWidget);
    // A form teacher does not write the head's comment.
    expect(find.text("Head teacher's comment"), findsNothing);
  });

  testWidgets('a parent starts a message by choosing the child it is about', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        myChildrenProvider.overrideWith((ref) async => [
              {'id': 3, 'name': 'Ada Obi', 'class': 'JSS 1'},
              {'id': 4, 'name': 'Bayo Obi', 'class': 'Primary 4'},
            ]),
      ],
      child: const MaterialApp(home: ParentNewMessageScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Which child is this about?'), findsOneWidget);
    expect(find.text('Ada Obi'), findsOneWidget);
    expect(find.text('Primary 4'), findsOneWidget);
  });

  test("a student's statement is fetched by student id, not login id", () async {
    final api = _RecordingApi();
    final container = ProviderContainer(overrides: [
      apiClientProvider.overrideWithValue(api),
      currentRoleProvider.overrideWithValue(UserRole.student),
      currentUserProvider.overrideWithValue(const AuthUser(id: 900, name: 'Ada', email: 'a@x', role: UserRole.student)),
      myDayProvider.overrideWith((ref) async => Cached<dynamic>(
            data: {'student': {'id': 42, 'name': 'Ada'}},
            fetchedAt: DateTime.now(),
          )),
      cacheStoreProvider.overrideWithValue(_MemoryCache()),
    ]);
    addTearDown(container.dispose);

    await container.read(statementProvider.future).catchError((_) => Cached<dynamic>(data: null, fetchedAt: DateTime.now()));

    expect(api.paths.single, contains('/finance/students/42/statement'));
  });
}
