import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../hostel/hostel_screen.dart';
import '../idcard/id_card_screen.dart';
import '../learn/learning_widgets.dart';
import '../library/library_screen.dart';

/// One child's learning record, as a guardian sees it (TASK 14 §8.1).
final childLearningProvider = FutureProvider.autoDispose.family<Cached<dynamic>, int>((ref, id) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'parent:learning:$id',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.parentLearning, query: {'student_id': id}),
  );
});

/// Hostel and digital ID card for all of a guardian's children. Each answers
/// 403/404 when the school does not use it; that hides the card, not the page.
final parentHostelProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  return _optional(() async => listOf(await ref.watch(apiClientProvider).get<dynamic>(Api.parentHostel), ['children']));
});

final parentIdCardsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  return _optional(() async => listOf(await ref.watch(apiClientProvider).get<dynamic>(Api.parentIdCards), ['children']));
});

/// A child's scores on CBT papers the school has released (not the report card).
final childCbtResultsProvider = FutureProvider.autoDispose.family<List<Map<String, dynamic>>, int>((ref, id) async {
  return _optional(() async => listOf(await ref.watch(apiClientProvider).get<dynamic>(Api.parentChildCbtResults(id)), ['data']));
});

Future<List<Map<String, dynamic>>> _optional(Future<List<Map<String, dynamic>>> Function() fetch) async {
  try {
    return await fetch();
  } catch (error) {
    final failure = asApiException(error);
    if (failure.isForbidden || failure.isNotFound) return const [];
    rethrow;
  }
}

class ChildLearningView extends ConsumerWidget {
  const ChildLearningView({super.key, required this.childId});

  final int childId;

  static const _states = {
    'pending': ('To do', AppColors.pending),
    'returned': ('Returned to redo', AppColors.pending),
    'missed': ('Missed', AppColors.absent),
    'submitted': ('Handed in', AppColors.primaryContainer),
    'graded': ('Marked', AppColors.present),
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return CachedView<dynamic>(
      value: ref.watch(childLearningProvider(childId)),
      onRetry: () => ref.invalidate(childLearningProvider(childId)),
      builder: (context, data, meta) {
        final children = listOf(data, ['children']);
        final record = children.isEmpty ? const <String, dynamic>{} : children.first;
        final assignments = mapOf(record['assignments']);
        final work = [
          ...listOf(assignments['pending']),
          ...listOf(assignments['submitted']),
          ...listOf(assignments['graded']),
        ];
        final attendance = mapOf(record['virtual_attendance']);
        final counts = mapOf(attendance['counts']);

        return RefreshableList(
          onRefresh: () async {
            ref.invalidate(childLearningProvider(childId));
            await ref.read(childLearningProvider(childId).future);
          },
          children: [
            FreshnessLine(fetchedAt: meta.fetchedAt, onRefresh: () => ref.invalidate(childLearningProvider(childId))),
            if (listOf(record['upcoming_classes']).isNotEmpty) ...[
              const SectionHeader('Virtual classes coming up'),
              for (final c in listOf(record['upcoming_classes']))
                ListTile(
                  leading: const Icon(Icons.videocam_outlined),
                  title: Text(stringOf(c['title'])),
                  subtitle: Text([stringOf(c['subject']), Dates.dayAndTime(Dates.tryParse(c['scheduled_for'])?.toLocal())].where((s) => s.isNotEmpty).join(' · ')),
                ),
            ],
            if (counts.isNotEmpty) ...[
              const SectionHeader('Virtual class attendance, last 30 days'),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin),
                child: Wrap(
                  spacing: AppSpacing.sm,
                  runSpacing: AppSpacing.sm,
                  children: [
                    for (final e in counts.entries)
                      StatusChip(
                        '${e.key}: ${intOf(e.value)}',
                        color: switch (e.key) {
                          'present' => AppColors.present,
                          'absent' => AppColors.absent,
                          _ => AppColors.late,
                        },
                      ),
                  ],
                ),
              ),
            ],
            _CbtResults(childId: childId),
            const SectionHeader('Homework'),
            if (work.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin),
                child: Text('No homework in the last two months.', style: AppText.bodyMd),
              ),
            for (final w in work)
              Padding(
                padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, 0, AppSpacing.screenMargin, AppSpacing.sm),
                child: OutlinedCard(
                  statusColor: _states[stringOf(w['state'])]?.$2,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(stringOf(w['title']), style: AppText.bodyLg),
                      Text(
                        '${stringOf(w['subject'])} · due ${Dates.dayMonth(Dates.tryParse(w['due_date']))}',
                        style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                      ),
                      const SizedBox(height: AppSpacing.xs),
                      Row(
                        children: [
                          StatusChip(
                            _states[stringOf(w['state'])]?.$1 ?? stringOf(w['state']),
                            color: _states[stringOf(w['state'])]?.$2 ?? AppColors.onSurfaceVariant,
                          ),
                          if (w['marks_awarded'] != null) ...[
                            const SizedBox(width: AppSpacing.sm),
                            Text(
                              '${numOf(w['marks_awarded'])}${w['marks_available'] != null ? ' / ${numOf(w['marks_available'])}' : ''}',
                              style: AppText.labelLg,
                            ),
                          ],
                        ],
                      ),
                      if (stringOf(w['return_note']).isNotEmpty)
                        Text('Teacher: ${stringOf(w['return_note'])}', style: AppText.bodyMd),
                      if (stringOf(w['feedback']).isNotEmpty)
                        Text('Feedback: ${stringOf(w['feedback'])}', style: AppText.bodyMd),
                    ],
                  ),
                ),
              ),
            if (record['materials'] is List)
              MaterialsSection(materials: listOf(record['materials']), title: 'Materials shared with the class'),
          ],
        );
      },
    );
  }
}

/// Released CBT scores. Nothing at all until the school releases a paper.
class _CbtResults extends ConsumerWidget {
  const _CbtResults({required this.childId});

  final int childId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rows = ref.watch(childCbtResultsProvider(childId)).valueOrNull ?? const [];
    if (rows.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SectionHeader('CBT results'),
        for (final r in rows)
          Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, 0, AppSpacing.screenMargin, AppSpacing.sm),
            child: OutlinedCard(
              statusColor: r['passed'] == true ? AppColors.present : AppColors.pending,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(stringOf(r['title']), style: AppText.bodyLg),
                  Text(
                    [stringOf(r['subject']), Dates.dayMonth(Dates.tryParse(r['submitted_at']))].where((s) => s.isNotEmpty).join(' · '),
                    style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    '${numOf(r['raw_score'])} / ${numOf(r['max_score'])} (${numOf(r['percentage']).round()}%)${r['grade'] != null ? ' · ${stringOf(r['grade'])}' : ''}',
                    style: AppText.labelLg,
                  ),
                  if (r['provisional'] == true)
                    Text('Written answers still being marked', style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

/// Library books, hostel and ID card for one child. Sections the school does
/// not use are left out.
class ChildMoreView extends ConsumerWidget {
  const ChildMoreView({super.key, required this.childId});

  final int childId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final hostel = ref.watch(parentHostelProvider);
    final cards = ref.watch(parentIdCardsProvider);

    Map<String, dynamic>? forChild(AsyncValue<List<Map<String, dynamic>>> v) =>
        v.valueOrNull?.where((c) => intOf(c['student_id']) == childId).firstOrNull;

    final hostelRow = forChild(hostel);
    final cardRow = forChild(cards);
    final card = cardRow?['card'];

    return RefreshableList(
      onRefresh: () async {
        ref.invalidate(parentHostelProvider);
        ref.invalidate(parentIdCardsProvider);
        ref.invalidate(libraryBooksProvider(childId));
      },
      padding: const EdgeInsets.only(bottom: AppSpacing.xl),
      children: [
        const SectionHeader('Library books'),
        // Its own scrolling list, so it gets a bounded box inside this one.
        SizedBox(height: 280, child: LibraryBooksView(studentId: childId)),
        if (hostel.isLoading || cards.isLoading) const LoadingState(rows: 2),
        if (hostelRow != null && hostelRow['boarding'] is Map) ...[
          const SectionHeader('Hostel'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin),
            child: HostelSummary(summary: hostelRow),
          ),
        ],
        if (card is Map) ...[
          const SectionHeader('ID card'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin),
            child: DigitalIdCard(card: card.cast<String, dynamic>()),
          ),
        ],
      ],
    );
  }
}
