import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../api/endpoints.dart';
import '../auth/session.dart';
import '../providers.dart';
import 'cached.dart';

/// A class (JSS2A) and a term (Second Term) — the two things almost every
/// staff screen needs before it can ask the server anything.
class ClassRef {
  const ClassRef({required this.id, required this.name});

  final int id;
  final String name;
}

class TermRef {
  const TermRef({required this.id, required this.name, this.isCurrent = false});

  final int id;
  final String name;
  final bool isCurrent;
}

/// The classes this user can act on.
///
/// **The backend has no `GET /classes`** (gap G12). What it does have is
/// `GET /teachers/{id}/subjects`, which returns a teacher's assignments with
/// their class ids attached — so for the role that most needs a class picker,
/// there is a real source. The canonical route is tried first so this starts
/// working properly the day it exists.
final classesProvider =
    FutureProvider.autoDispose<List<ClassRef>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);
  final user = ref.watch(currentUserProvider);

  Future<List<ClassRef>> viaCanonicalRoute() async {
    final cached = await cachedGet<dynamic>(
      cache: cache,
      key: 'ref:classes',
      fetch: () => api.get<dynamic>('${Api.version}/classes'),
    );
    return _parseClasses(listOf(cached.data, ['classes']));
  }

  Future<List<ClassRef>> viaTeacherAssignments() async {
    if (user == null) return const [];
    final cached = await cachedGet<dynamic>(
      cache: cache,
      key: 'ref:classes:teacher:${user.id}',
      fetch: () => api.get<dynamic>(Api.teacherSubjects(user.id)),
    );

    // Assignments carry `class_id` and usually a nested class. Deduplicated,
    // because a teacher who takes three subjects in JSS2A has three rows.
    final rows = listOf(cached.data, ['subjects', 'assignments']);
    final seen = <int, ClassRef>{};
    for (final row in rows) {
      final id = intOf(row['class_id'] ?? row['class']?['id']);
      if (id == 0) continue;
      seen[id] = ClassRef(
        id: id,
        name: stringOf(
          row['class']?['name'] ?? row['class_name'],
          'Class $id',
        ),
      );
    }
    return seen.values.toList();
  }

  try {
    final canonical = await viaCanonicalRoute();
    if (canonical.isNotEmpty) return canonical;
  } catch (error) {
    final failure = asApiException(error);
    if (failure.isOffline) rethrow;
    // 404/403 means the route is missing or not ours — fall through.
  }

  if (user != null && (user.role == UserRole.teacher || user.role.isAdmin)) {
    try {
      return await viaTeacherAssignments();
    } catch (error) {
      if (asApiException(error).isOffline) rethrow;
    }
  }

  return const [];
});

List<ClassRef> _parseClasses(List<Map<String, dynamic>> rows) => [
      for (final row in rows)
        ClassRef(
          id: intOf(row['id']),
          name: stringOf(row['name'] ?? row['class_name'], 'Class'),
        ),
    ];

/// The school's terms.
///
/// Also missing from the backend (G12). Without it the app cannot tell which
/// term "now" belongs to, and every endpoint that takes a `term_id` is
/// unreachable — so this is the gap that most limits the staff side.
final termsProvider = FutureProvider.autoDispose<List<TermRef>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  try {
    final cached = await cachedGet<dynamic>(
      cache: cache,
      key: 'ref:terms',
      fetch: () => api.get<dynamic>('${Api.version}/terms'),
    );

    final rows = listOf(cached.data, ['terms']);
    return [
      for (final row in rows)
        TermRef(
          id: intOf(row['id']),
          name: stringOf(row['name'], 'Term'),
          isCurrent: row['is_current'] == true || row['current'] == true,
        ),
    ];
  } catch (error) {
    if (asApiException(error).isOffline) rethrow;
    return const [];
  }
});

/// The term the user is working in.
///
/// Defaults to whichever term the server flags as current. Persisted only for
/// the session — a term is not a preference, it is a fact about the calendar.
final selectedTermProvider = StateProvider<int?>((ref) {
  final terms = ref.watch(termsProvider).valueOrNull ?? const [];
  if (terms.isEmpty) return null;
  return terms.firstWhere((t) => t.isCurrent, orElse: () => terms.last).id;
});

final selectedClassProvider = StateProvider<int?>((ref) {
  final classes = ref.watch(classesProvider).valueOrNull ?? const [];
  return classes.isEmpty ? null : classes.first.id;
});
