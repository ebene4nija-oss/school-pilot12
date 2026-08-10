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
/// **There is no backend endpoint for this** — `/students` is staff-only and
/// nothing exposes the `student_guardian` pivot to the guardian themselves
/// (gap G11 in `docs/mobile-app.md` §B12). The call below is written against
/// the route that ought to exist so the parent role works the day it lands;
/// until then it 404s and the UI says exactly what is missing rather than
/// spinning.
final myChildrenProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  final cached = await cachedGet<dynamic>(
    cache: cache,
    key: 'parent:children',
    fetch: () => api.get<dynamic>('${Api.version}/parent/children'),
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
