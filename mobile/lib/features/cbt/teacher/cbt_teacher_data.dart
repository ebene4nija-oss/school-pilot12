import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/endpoints.dart';
import '../../../core/data/cached.dart';
import '../../../core/providers.dart';

/// The teacher's own papers. The server narrows a teacher to what they set;
/// an admin sees the school's.
final teacherExamsProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final data = await api.get<dynamic>(Api.cbtExams, query: {'per_page': 100});
  return listOf(data, ['exams']);
});

final examDetailProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, int>((ref, id) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.cbtExam(id)));
});

final examResultsProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, int>((ref, id) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.cbtExamResults(id)));
});

/// The school's subjects, for the paper and the question bank.
final schoolSubjectsProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);
  final cached = await cachedGet<dynamic>(
    cache: cache,
    key: 'ref:subjects',
    fetch: () => api.get<dynamic>(Api.subjects),
  );
  return listOf(cached.data, ['subjects']);
});

/// The subjects one class takes (its own list, or those tagged for its
/// level), so a Primary 3 paper never offers SS 3 Physics. Falls back to the
/// school's whole list until a class is picked.
final classSubjectsProvider = FutureProvider.autoDispose
    .family<List<Map<String, dynamic>>, int?>((ref, classId) async {
  if (classId == null) return ref.watch(schoolSubjectsProvider.future);
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);
  final cached = await cachedGet<dynamic>(
    cache: cache,
    key: 'ref:subjects:class:$classId',
    fetch: () => api.get<dynamic>(Api.subjects, query: {'class_id': classId}),
  );
  return listOf(cached.data, ['subjects']);
});

/// Question types a teacher marks by hand. Everything else the engine marks.
bool isHandMarked(Object? type) => stringOf(type) == 'theory';

/// Status chip colour and label for a paper.
({String label, Color color}) examStatus(Object? status) =>
    switch (stringOf(status)) {
      'published' => (label: 'Published', color: AppColors.present),
      'closed' => (label: 'Closed', color: AppColors.outline),
      _ => (label: 'Draft', color: AppColors.pending),
    };
