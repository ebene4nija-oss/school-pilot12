import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/db/app_database.dart';
import 'package:schoolpilot/core/db/outbox.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// The outbox is what makes a dropped connection safe, so its two guarantees
/// are tested directly: the idempotency key never changes, and a terminal
/// failure is kept rather than dropped.
void main() {
  late AppDatabase database;
  late Outbox outbox;

  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  setUp(() async {
    database = await AppDatabase.open();
    await database.wipe();
    outbox = Outbox(database);
  });

  tearDown(() async {
    await database.wipe();
    await database.close();
  });

  test('an enqueued write keeps the idempotency key it was given', () async {
    const key = 'register-jss2a-2026-08-09';

    await outbox.enqueue(
      method: 'POST',
      path: '/api/v1/attendance/bulk',
      label: 'Register · JSS2A',
      body: {'term_id': 1, 'records': []},
      idempotencyKey: key,
    );

    final due = await outbox.due();
    expect(due, hasLength(1));
    expect(due.single.idempotencyKey, key);
  });

  test('backing off keeps the row pending and reuses the same key', () async {
    await outbox.enqueue(
      method: 'POST',
      path: '/api/v1/attendance/bulk',
      label: 'Register · JSS2A',
      idempotencyKey: 'stable-key',
    );

    final item = (await outbox.due()).single;
    await outbox.backOff(item, 'connection lost');

    final all = await outbox.all();
    expect(all.single.status, OutboxStatus.pending);
    expect(all.single.attempts, 1);
    // Regenerating the key on retry would let the server treat a replay as a
    // second register. It must survive the backoff untouched.
    expect(all.single.idempotencyKey, 'stable-key');

    // Backed-off rows are not due again immediately.
    expect(await outbox.due(), isEmpty);
  });

  test('a terminal failure is kept and surfaced, never discarded', () async {
    await outbox.enqueue(
      method: 'POST',
      path: '/api/v1/assessment/score',
      label: 'Mathematics · Chidinma Okeke',
    );

    final item = (await outbox.due()).single;
    await outbox.markFailed(item, 'The selected term id is invalid.');

    final all = await outbox.all();
    expect(all, hasLength(1));
    expect(all.single.status, OutboxStatus.failed);
    expect(all.single.lastError, 'The selected term id is invalid.');
    expect(await outbox.pendingCount(), 0);
    expect(await outbox.failedCount(), 1);
  });

  test('retrying failed rows puts them back in the queue', () async {
    await outbox.enqueue(method: 'POST', path: '/x', label: 'X');
    await outbox.markFailed((await outbox.due()).single, 'nope');

    await outbox.retryFailed();

    expect(await outbox.failedCount(), 0);
    expect(await outbox.due(), hasLength(1));
  });

  test('signing out clears the queue with the rest of the cache', () async {
    await outbox.enqueue(method: 'POST', path: '/x', label: 'X');
    await database.wipe();
    expect(await outbox.all(), isEmpty);
  });
}
