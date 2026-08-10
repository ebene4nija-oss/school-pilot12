import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api/api_client.dart';
import 'auth/auth_controller.dart';
import 'auth/auth_repository.dart';
import 'auth/session.dart';
import 'auth/session_store.dart';
import 'db/app_database.dart';
import 'db/cache_store.dart';
import 'db/outbox.dart';
import 'sync/sync_service.dart';

/// Everything below is constructed in `main()` and injected as an override, so
/// a test can swap the client, the database or the clock without the widget
/// tree knowing.
final sessionHolderProvider = Provider<SessionHolder>(
  (_) => throw UnimplementedError('overridden in main()'),
);

final databaseProvider = Provider<AppDatabase>(
  (_) => throw UnimplementedError('overridden in main()'),
);

final apiClientProvider = Provider<ApiClient>(
  (_) => throw UnimplementedError('overridden in main()'),
);

final sessionStoreProvider = Provider<SessionStore>(
  (_) => throw UnimplementedError('overridden in main()'),
);

final cacheStoreProvider = Provider<CacheStore>(
  (ref) => CacheStore(ref.watch(databaseProvider)),
);

final outboxProvider = Provider<Outbox>(
  (ref) => Outbox(ref.watch(databaseProvider)),
);

final syncServiceProvider = Provider<SyncService>(
  (_) => throw UnimplementedError('overridden in main()'),
);

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(ref.watch(apiClientProvider)),
);

final authControllerProvider =
    StateNotifierProvider<AuthController, AuthState>((ref) {
  return AuthController(
    repository: ref.watch(authRepositoryProvider),
    store: ref.watch(sessionStoreProvider),
    holder: ref.watch(sessionHolderProvider),
    database: ref.watch(databaseProvider),
  );
});

/// The signed-in user, or null. Read constantly, so it gets its own provider
/// rather than a `.select` at every call site.
final currentUserProvider = Provider<AuthUser?>(
  (ref) => ref.watch(authControllerProvider).user,
);

final currentRoleProvider = Provider<UserRole>(
  (ref) => ref.watch(authControllerProvider).role,
);
