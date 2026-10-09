import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/api/api_client.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/core/providers.dart';
import 'package:schoolpilot/features/store/parent_store_screen.dart';

/// Records what the parent sends back.
class _RecordingApi implements ApiClient {
  String? path;
  Map<String, dynamic>? body;

  @override
  Future<T> post<T>(String path, {Object? body, Map<String, dynamic>? query, dynamic cancelToken, Duration? timeout}) async {
    this.path = path;
    this.body = body as Map<String, dynamic>?;
    return <String, dynamic>{'message': 'Thank you.'} as T;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

Cached<dynamic> _handOver() => Cached<dynamic>(
      fetchedAt: DateTime.now(),
      data: {
        'enabled': true,
        'awaiting': 1,
        'data': [
          {
            'id': 9,
            'student_name': 'Ada Obi',
            'status': 'awaiting',
            'issued_at': '2026-10-09T09:00:00Z',
            'lines': [
              {'id': 1, 'item_name': 'School shirt', 'variant': 'Age 10-11', 'quantity': 2, 'unit_price': '4500.00', 'charged': true, 'status': 'pending'},
              {'id': 2, 'item_name': 'Atlas', 'variant': null, 'quantity': 1, 'unit_price': '5000.00', 'charged': false, 'status': 'pending'},
            ],
          },
        ],
      },
    );

Future<_RecordingApi> _pump(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 2.0;
  addTearDown(tester.view.reset);
  final api = _RecordingApi();
  await tester.pumpWidget(ProviderScope(
    overrides: [
      apiClientProvider.overrideWithValue(api),
      parentStoreProvider.overrideWith((ref) async => _handOver()),
    ],
    child: const MaterialApp(home: ParentStoreScreen()),
  ));
  await tester.pumpAndSettle();
  return api;
}

void main() {
  testWidgets('a parent confirms everything with one tap', (tester) async {
    final api = await _pump(tester);

    expect(find.text('2 × School shirt (Age 10-11)'), findsOneWidget);
    expect(find.text('No charge'), findsOneWidget);

    await tester.tap(find.text('Yes, we received everything'));
    await tester.pumpAndSettle();

    expect(api.path, '/api/v1/parent/inventory/issues/9/confirm');
    expect(api.body!['lines'], [
      {'id': 1, 'received': true},
      {'id': 2, 'received': true},
    ]);
  });

  testWidgets('an unticked item needs a note before it can be sent', (tester) async {
    final api = await _pump(tester);

    await tester.tap(find.text('1 × Atlas'));
    await tester.pumpAndSettle();

    final send = find.widgetWithText(FilledButton, 'Tell the school what is missing');
    expect(tester.widget<FilledButton>(send).onPressed, isNull);

    await tester.enterText(find.byType(TextField), 'Not in her bag');
    await tester.pumpAndSettle();
    await tester.tap(send);
    await tester.pumpAndSettle();

    expect(api.body!['lines'], [
      {'id': 1, 'received': true},
      {'id': 2, 'received': false},
    ]);
    expect(api.body!['note'], 'Not in her bag');
  });
}
