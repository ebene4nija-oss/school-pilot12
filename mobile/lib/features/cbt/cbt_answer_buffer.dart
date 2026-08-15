import 'dart:convert';

import 'package:sqflite/sqflite.dart' show ConflictAlgorithm;

import '../../core/db/app_database.dart';

/// The candidate's answers, written to SQLite as they tap.
///
/// `client_sequence` increments per attempt and travels with every answer.
/// It is what lets `CbtController::syncOfflineAnswers` resolve two writes for
/// the same question that arrive out of order after a reconnect — last
/// sequence wins, not last packet to land.
class CbtAnswerBuffer {
  CbtAnswerBuffer(this._db);

  final AppDatabase _db;

  Future<int> _nextSequence(int attemptId) async {
    final rows = await _db.db.rawQuery(
      'SELECT MAX(client_sequence) AS seq FROM cbt_answers WHERE attempt_id = ?',
      [attemptId],
    );
    final current = rows.first['seq'];
    return current == null ? 1 : (current as num).toInt() + 1;
  }

  Future<void> save({
    required int attemptId,
    required int questionId,
    required Object? response,
    bool flagged = false,
  }) async {
    await _db.db.insert(
      'cbt_answers',
      {
        'attempt_id': attemptId,
        'question_id': questionId,
        'response': response == null ? null : jsonEncode(response),
        'client_sequence': await _nextSequence(attemptId),
        'client_timestamp': DateTime.now().toUtc().toIso8601String(),
        'flagged': flagged ? 1 : 0,
        'synced': 0,
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<Map<int, Object?>> answers(int attemptId) async {
    final rows = await _db.db.query(
      'cbt_answers',
      where: 'attempt_id = ?',
      whereArgs: [attemptId],
    );

    return {
      for (final row in rows)
        row['question_id'] as int: row['response'] == null
            ? null
            : jsonDecode(row['response'] as String),
    };
  }

  /// Questions the candidate flagged for review, so reopening the paper does
  /// not lose the marks they made on their way through it.
  Future<Set<int>> flagged(int attemptId) async {
    final rows = await _db.db.query(
      'cbt_answers',
      columns: ['question_id'],
      where: 'attempt_id = ? AND flagged = 1',
      whereArgs: [attemptId],
    );

    return {for (final row in rows) row['question_id'] as int};
  }

  /// The payload `/cbt/attempts/{id}/answers` and `/cbt/offline-sync` both take.
  Future<List<Map<String, dynamic>>> unsyncedPayload(int attemptId) async {
    final rows = await _db.db.query(
      'cbt_answers',
      where: 'attempt_id = ? AND synced = 0',
      whereArgs: [attemptId],
      orderBy: 'client_sequence ASC',
    );

    return [
      for (final row in rows)
        {
          'question_id': row['question_id'],
          'response': row['response'] == null
              ? null
              : jsonDecode(row['response'] as String),
          'client_sequence': row['client_sequence'],
          'client_timestamp': row['client_timestamp'],
          'flagged_for_review': (row['flagged'] as num? ?? 0) != 0,
        },
    ];
  }

  Future<void> markSynced(int attemptId, List<int> questionIds) async {
    if (questionIds.isEmpty) return;
    final placeholders = List.filled(questionIds.length, '?').join(',');
    await _db.db.rawUpdate(
      'UPDATE cbt_answers SET synced = 1 '
      'WHERE attempt_id = ? AND question_id IN ($placeholders)',
      [attemptId, ...questionIds],
    );
  }

  Future<int> unsyncedCount(int attemptId) async {
    final rows = await _db.db.rawQuery(
      'SELECT COUNT(*) AS c FROM cbt_answers WHERE attempt_id = ? AND synced = 0',
      [attemptId],
    );
    return (rows.first['c'] as num).toInt();
  }

  Future<void> clear(int attemptId) => _db.db
      .delete('cbt_answers', where: 'attempt_id = ?', whereArgs: [attemptId]);
}
