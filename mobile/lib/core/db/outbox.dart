import 'dart:convert';

import 'package:uuid/uuid.dart';

import 'app_database.dart';

enum OutboxStatus { pending, failed }

class OutboxItem {
  const OutboxItem({
    required this.id,
    required this.method,
    required this.path,
    required this.body,
    required this.idempotencyKey,
    required this.label,
    required this.createdAt,
    required this.attempts,
    required this.nextAttemptAt,
    required this.status,
    this.lastError,
  });

  final int id;
  final String method;
  final String path;
  final Map<String, dynamic>? body;

  /// Generated once, when the user acted, and reused on every retry. This is
  /// the whole reason a dropped connection is safe: `/attendance/bulk` caches
  /// its result against this key per school, so replaying it cannot double-mark
  /// a register.
  final String idempotencyKey;

  /// What to show the user in the pending queue: "Register · JSS2A · Mon 9 Aug".
  final String label;

  final DateTime createdAt;
  final int attempts;
  final DateTime nextAttemptAt;
  final OutboxStatus status;
  final String? lastError;

  factory OutboxItem.fromRow(Map<String, Object?> row) => OutboxItem(
        id: row['id'] as int,
        method: row['method'] as String,
        path: row['path'] as String,
        body: row['body'] == null
            ? null
            : jsonDecode(row['body'] as String) as Map<String, dynamic>,
        idempotencyKey: row['idempotency_key'] as String,
        label: row['label'] as String,
        createdAt: DateTime.fromMillisecondsSinceEpoch(row['created_at'] as int),
        attempts: row['attempts'] as int,
        nextAttemptAt:
            DateTime.fromMillisecondsSinceEpoch(row['next_attempt_at'] as int),
        status: row['status'] == 'failed'
            ? OutboxStatus.failed
            : OutboxStatus.pending,
        lastError: row['last_error'] as String?,
      );
}

class Outbox {
  Outbox(this._db);

  final AppDatabase _db;
  static const _uuid = Uuid();

  /// Mints an idempotency key. Called when a form is *opened*, not when it is
  /// submitted, so the same key survives the user tapping submit twice.
  static String newKey() => _uuid.v4();

  Future<int> enqueue({
    required String method,
    required String path,
    required String label,
    Map<String, dynamic>? body,
    String? idempotencyKey,
  }) {
    final now = DateTime.now().millisecondsSinceEpoch;
    return _db.db.insert('outbox', {
      'method': method,
      'path': path,
      'body': body == null ? null : jsonEncode(body),
      'idempotency_key': idempotencyKey ?? newKey(),
      'label': label,
      'created_at': now,
      'attempts': 0,
      'next_attempt_at': now,
      'status': 'pending',
    });
  }

  Future<List<OutboxItem>> due() async {
    final rows = await _db.db.query(
      'outbox',
      where: 'status = ? AND next_attempt_at <= ?',
      whereArgs: ['pending', DateTime.now().millisecondsSinceEpoch],
      orderBy: 'created_at ASC',
    );
    return rows.map(OutboxItem.fromRow).toList();
  }

  Future<List<OutboxItem>> all() async {
    final rows = await _db.db.query('outbox', orderBy: 'created_at DESC');
    return rows.map(OutboxItem.fromRow).toList();
  }

  Future<int> pendingCount() async {
    final rows = await _db.db.rawQuery(
      "SELECT COUNT(*) AS c FROM outbox WHERE status = 'pending'",
    );
    return (rows.first['c'] as num).toInt();
  }

  Future<int> failedCount() async {
    final rows = await _db.db.rawQuery(
      "SELECT COUNT(*) AS c FROM outbox WHERE status = 'failed'",
    );
    return (rows.first['c'] as num).toInt();
  }

  Future<void> remove(int id) =>
      _db.db.delete('outbox', where: 'id = ?', whereArgs: [id]);

  /// Retryable failure: back off and try again later.
  ///
  /// Exponential, capped at ten minutes. A phone in a school with no signal
  /// until break time should not be burning battery retrying every second.
  Future<void> backOff(OutboxItem item, String error) async {
    final attempts = item.attempts + 1;
    final delaySeconds = (1 << attempts.clamp(0, 9)).clamp(2, 600);
    await _db.db.update(
      'outbox',
      {
        'attempts': attempts,
        'last_error': error,
        'next_attempt_at': DateTime.now()
            .add(Duration(seconds: delaySeconds))
            .millisecondsSinceEpoch,
      },
      where: 'id = ?',
      whereArgs: [item.id],
    );
  }

  /// Terminal failure: the server rejected it and always will.
  ///
  /// The row is kept, not deleted. Silently dropping a teacher's register
  /// because the term id was wrong is the worst thing this queue could do, so
  /// it surfaces in the pending screen with the server's own message.
  Future<void> markFailed(OutboxItem item, String error) => _db.db.update(
        'outbox',
        {'status': 'failed', 'last_error': error, 'attempts': item.attempts + 1},
        where: 'id = ?',
        whereArgs: [item.id],
      );

  Future<void> retryFailed() => _db.db.update(
        'outbox',
        {
          'status': 'pending',
          'attempts': 0,
          'next_attempt_at': DateTime.now().millisecondsSinceEpoch,
        },
        where: 'status = ?',
        whereArgs: ['failed'],
      );

  Future<void> discard(int id) => remove(id);
}
