import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/endpoints.dart';
import '../../core/auth/session.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';

/// The role's own dashboard endpoint, cached so a cold start in a corridor
/// still paints something.
final dashboardProvider =
    FutureProvider.autoDispose<Cached<Map<String, dynamic>>>((ref) async {
  final role = ref.watch(currentRoleProvider);
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  final path = switch (role) {
    UserRole.schoolAdmin || UserRole.superAdmin => Api.analyticsPrincipal,
    UserRole.teacher => Api.analyticsTeacher,
    UserRole.student => Api.analyticsStudent,
    UserRole.parent => null,
  };

  if (path == null) {
    final childId = ref.watch(selectedChildIdProvider);
    if (childId == null) return Cached(data: const {}, fetchedAt: DateTime.now());

    return cachedGet<Map<String, dynamic>>(
      cache: cache,
      key: 'dashboard:parent:$childId',
      fetch: () => api.get<Map<String, dynamic>>(Api.analyticsParent(childId)),
    );
  }

  return cachedGet<Map<String, dynamic>>(
    cache: cache,
    key: 'dashboard:${role.wire}',
    fetch: () => api.get<Map<String, dynamic>>(path),
  );
});

/// Which child a guardian is currently looking at.
///
/// Every parent screen reads this, so switching child at the app bar re-points
/// the whole tab rather than each screen keeping its own idea of "the child".
final selectedChildIdProvider = StateProvider<int?>((ref) => null);

/// The guardian's children.
///
/// `GET /parent/children` is the only way to learn a `studentId`: `/students`
/// is staff-only, and every other parent endpoint takes an id it does not
/// hand out. The server scopes this by the `student_guardian` pivot, not by
/// school membership, and returns an empty list with a message — not a 403 —
/// for a parent the school has not linked to a child yet.
final myChildrenProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  final cached = await cachedGet<dynamic>(
    cache: cache,
    key: 'parent:children',
    fetch: () => api.get<dynamic>(Api.parentChildren),
  );

  final children = listOf(cached.data, ['children', 'students']);

  // Pick the first child automatically. A guardian with one child — most of
  // them — should never have to choose.
  final selected = ref.read(selectedChildIdProvider);
  if (selected == null && children.isNotEmpty) {
    Future.microtask(() {
      ref.read(selectedChildIdProvider.notifier).state =
          intOf(children.first['id']);
    });
  }

  return children;
});

final parentFeedProvider =
    FutureProvider.autoDispose<Cached<Map<String, dynamic>>>((ref) async {
  final childId = ref.watch(selectedChildIdProvider);
  if (childId == null) {
    return Cached(data: const {}, fetchedAt: DateTime.now());
  }

  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<Map<String, dynamic>>(
    cache: cache,
    key: 'parent:feed:$childId',
    fetch: () => api.get<Map<String, dynamic>>(Api.parentFeed(childId)),
  );
});
