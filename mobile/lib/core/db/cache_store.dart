import 'dart:convert';

import 'package:sqflite/sqflite.dart' show ConflictAlgorithm;

import 'app_database.dart';

/// A JSON blob plus the time it was fetched.
class CachedPayload<T> {
  const CachedPayload(this.value, this.fetchedAt);

  final T value;
  final DateTime fetchedAt;
}

/// The read cache behind every screen a teacher or student opens during the
/// school day.
///
/// Deliberately dumb: whole responses in, whole responses out, keyed by the
/// request. Stale data is shown with its timestamp rather than hidden — a
/// register from 07:15 is worth far more than a spinner in a classroom with no
/// signal.
class CacheStore {
  CacheStore(this._db);

  final AppDatabase _db;

  Future<void> write(String key, Object payload) async {
    await _db.db.insert(
      'cache_entries',
      {
        'key': key,
        'payload': jsonEncode(payload),
        'fetched_at': DateTime.now().millisecondsSinceEpoch,
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<CachedPayload<dynamic>?> read(String key) async {
    final rows = await _db.db.query(
      'cache_entries',
      where: 'key = ?',
      whereArgs: [key],
      limit: 1,
    );
    if (rows.isEmpty) return null;

    final row = rows.first;
    return CachedPayload(
      jsonDecode(row['payload'] as String),
      DateTime.fromMillisecondsSinceEpoch(row['fetched_at'] as int),
    );
  }

  Future<void> evict(String key) =>
      _db.db.delete('cache_entries', where: 'key = ?', whereArgs: [key]);

  Future<void> evictPrefix(String prefix) => _db.db
      .delete('cache_entries', where: 'key LIKE ?', whereArgs: ['$prefix%']);
}
