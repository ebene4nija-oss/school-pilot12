import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

/// Local SQLite: the read cache, the write outbox, and the CBT answer buffer.
///
/// Nothing sensitive lives here. Under NDPA data minimisation
/// (`docs/mobile-app.md` §B6) student health data is never written to disk, and
/// the whole database is dropped on sign-out.
class AppDatabase {
  AppDatabase._(this.db);

  final Database db;

  static const _fileName = 'schoolpilot.db';

  /// v2 added `cbt_answers.flagged` so a "flag for review" survives a restart
  /// and travels to the invigilator with the answer.
  static const _version = 2;

  static Future<AppDatabase> open() async {
    final path = p.join(await getDatabasesPath(), _fileName);
    final db = await openDatabase(
      path,
      version: _version,
      // Migrated rather than recreated: an upgrade can land on a handset with
      // a paper half-answered on it, and dropping the table would take the
      // candidate's answers with it.
      onUpgrade: (db, oldVersion, newVersion) async {
        if (oldVersion < 2) {
          await db.execute(
            'ALTER TABLE cbt_answers ADD COLUMN flagged INTEGER NOT NULL '
            'DEFAULT 0',
          );
        }
      },
      onCreate: (db, _) async {
        await db.execute('''
          CREATE TABLE cache_entries (
            key         TEXT PRIMARY KEY,
            payload     TEXT NOT NULL,
            fetched_at  INTEGER NOT NULL
          )
        ''');

        await db.execute('''
          CREATE TABLE outbox (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            method           TEXT NOT NULL,
            path             TEXT NOT NULL,
            body             TEXT,
            idempotency_key  TEXT NOT NULL,
            label            TEXT NOT NULL,
            created_at       INTEGER NOT NULL,
            attempts         INTEGER NOT NULL DEFAULT 0,
            next_attempt_at  INTEGER NOT NULL DEFAULT 0,
            status           TEXT NOT NULL DEFAULT 'pending',
            last_error       TEXT
          )
        ''');

        await db.execute(
          'CREATE INDEX idx_outbox_status ON outbox (status, next_attempt_at)',
        );

        // The CBT answer buffer. `client_sequence` is what lets the server
        // resolve two writes for the same question that arrive out of order
        // after a reconnect — see CbtController::syncOfflineAnswers.
        await db.execute('''
          CREATE TABLE cbt_answers (
            attempt_id        INTEGER NOT NULL,
            question_id       INTEGER NOT NULL,
            response          TEXT,
            client_sequence   INTEGER NOT NULL,
            client_timestamp  TEXT NOT NULL,
            flagged           INTEGER NOT NULL DEFAULT 0,
            synced            INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (attempt_id, question_id)
          )
        ''');
      },
    );

    return AppDatabase._(db);
  }

  /// Called on sign-out and on a session-ending 401.
  Future<void> wipe() async {
    await db.delete('cache_entries');
    await db.delete('outbox');
    await db.delete('cbt_answers');
  }

  Future<void> close() => db.close();
}
