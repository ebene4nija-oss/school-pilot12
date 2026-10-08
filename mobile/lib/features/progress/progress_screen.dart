import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final myProgressProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'me:progress',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.myProgress),
  );
});

final myMasteryProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'me:mastery',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.tutorMastery),
  );
});

final leaderboardProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'gamification:leaderboard',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.leaderboard),
  );
});

Color scoreColour(num v) => v >= 70
    ? AppColors.paid
    : v >= 50
        ? AppColors.pending
        : AppColors.absent;

/// A student's own progress: released results only, how they changed since
/// last term, homework done, and which topics are strong or need work. No
/// class averages, and positions only if the school shows them.
class ProgressScreen extends ConsumerWidget {
  const ProgressScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mastery = ref.watch(myMasteryProvider).valueOrNull?.data;

    return Scaffold(
      appBar: AppBar(title: const Text('My progress')),
      body: CachedView<dynamic>(
        value: ref.watch(myProgressProvider),
        onRetry: () => ref.invalidate(myProgressProvider),
        builder: (context, data, meta) => RefreshableList(
          onRefresh: () async {
            ref.invalidate(myProgressProvider);
            ref.invalidate(myMasteryProvider);
            await ref.read(myProgressProvider.future);
          },
          padding: const EdgeInsets.all(AppSpacing.screenMargin),
          children: ProgressView.sections(mapOf(data), mapOf(mastery)),
        ),
      ),
    );
  }
}

class ProgressView {
  const ProgressView._();

  static List<Widget> sections(Map<String, dynamic> d, Map<String, dynamic> mastery) {
    final overview = mapOf(d['overview']);
    final subjects = listOf(d['subjects']);
    final completion = mapOf(d['completion']);
    final attendance = mapOf(mapOf(d['attendance'])['totals']);
    final period = mapOf(d['period']);

    if (d['empty'] == true || overview.isEmpty) {
      return [
        const EmptyState(
          icon: Icons.insights_outlined,
          title: 'No released results yet',
          message: 'Your progress appears here once the school releases a term’s results.',
        ),
        ..._topics(mastery),
      ];
    }

    final average = overview['average'];
    final change = overview['change_points'];

    return [
      if (stringOf(period['label']).isNotEmpty)
        Text(stringOf(period['label']), style: AppText.labelLg.copyWith(color: AppColors.onSurfaceVariant)),
      const SizedBox(height: AppSpacing.sm),
      Row(
        children: [
          Expanded(
            child: StatTile(
              label: 'Average',
              value: average == null ? '—' : '${numOf(average).toStringAsFixed(1)}%',
              caption: change == null
                  ? null
                  : '${numOf(change) >= 0 ? '+' : ''}${numOf(change).toStringAsFixed(1)} since last term',
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: StatTile(
              label: 'Passed',
              value: '${intOf(overview['subjects_passed'])}/${intOf(overview['subjects_counted'])}',
              caption: 'subjects',
            ),
          ),
        ],
      ),
      const SizedBox(height: AppSpacing.sm),
      Row(
        children: [
          Expanded(
            child: StatTile(
              label: 'Attendance',
              value: attendance['rate'] == null ? '—' : '${numOf(attendance['rate']).toStringAsFixed(0)}%',
              caption: 'this term',
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: StatTile(
              label: 'Homework',
              value: '${intOf(completion['completed'])}/${intOf(completion['assigned'])}',
              caption: intOf(completion['missed']) > 0 ? '${intOf(completion['missed'])} missed' : 'done',
            ),
          ),
        ],
      ),
      if (overview['position'] != null) ...[
        const SizedBox(height: AppSpacing.sm),
        Text(
          'Position: ${intOf(overview['position'])} of ${intOf(overview['class_size'])}',
          style: AppText.bodyMd,
        ),
      ],
      const SizedBox(height: AppSpacing.lg),
      const SectionHeader('Subjects'),
      for (final s in subjects)
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: OutlinedCard(
            statusColor: s['total'] == null ? AppColors.outline : scoreColour(numOf(s['total'])),
            child: Row(
              children: [
                Expanded(child: Text(stringOf(s['subject']), style: AppText.bodyLg)),
                if (s['change'] != null)
                  Padding(
                    padding: const EdgeInsets.only(right: AppSpacing.sm),
                    child: Text(
                      '${numOf(s['change']) >= 0 ? '▲' : '▼'} ${numOf(s['change']).abs().toStringAsFixed(0)}',
                      style: AppText.labelSm.copyWith(color: numOf(s['change']) >= 0 ? AppColors.paid : AppColors.absent),
                    ),
                  ),
                Text(
                  s['total'] == null ? '—' : '${numOf(s['total']).toStringAsFixed(0)}${stringOf(s['grade']).isNotEmpty ? ' (${stringOf(s['grade'])})' : ''}',
                  style: AppText.bodyLg,
                ),
              ],
            ),
          ),
        ),
      ..._topics(mastery),
    ];
  }

  static List<Widget> _topics(Map<String, dynamic> mastery) {
    final needs = listOf(mastery['needs_work']);
    final developing = listOf(mastery['developing']);
    final mastered = listOf(mastery['mastered']);
    if (needs.isEmpty && developing.isEmpty && mastered.isEmpty) return const [];

    Widget row(Map<String, dynamic> t) => Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${stringOf(t['topic'])}${stringOf(t['subject']).isNotEmpty ? ' · ${stringOf(t['subject'])}' : ''}',
                style: AppText.bodyMd,
              ),
              const SizedBox(height: 4),
              ProgressBar(
                value: (numOf(t['mastery_percentage']) / 100).clamp(0, 1).toDouble(),
                color: scoreColour(numOf(t['mastery_percentage'])),
              ),
            ],
          ),
        );

    return [
      const SizedBox(height: AppSpacing.lg),
      const SectionHeader('Topics'),
      if (needs.isNotEmpty) ...[Text('Needs work', style: AppText.labelLg), for (final t in needs) row(t)],
      if (developing.isNotEmpty) ...[Text('Getting there', style: AppText.labelLg), for (final t in developing) row(t)],
      if (mastered.isNotEmpty) ...[Text('Strong', style: AppText.labelLg), for (final t in mastered) row(t)],
    ];
  }
}

/// Points and badges across the school. Names are shortened by the server.
class LeaderboardScreen extends ConsumerWidget {
  const LeaderboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Leaderboard')),
      body: CachedView<dynamic>(
        value: ref.watch(leaderboardProvider),
        onRetry: () => ref.invalidate(leaderboardProvider),
        builder: (context, data, meta) {
          final rows = listOf(data);
          if (rows.isEmpty) {
            return const EmptyState(
              icon: Icons.emoji_events_outlined,
              title: 'No points yet',
              message: 'Points come from homework, tests and study. Check back soon.',
            );
          }
          return RefreshableList(
            onRefresh: () async {
              ref.invalidate(leaderboardProvider);
              await ref.read(leaderboardProvider.future);
            },
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              for (final r in rows)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: OutlinedCard(
                    statusColor: r['is_me'] == true ? AppColors.primaryContainer : null,
                    child: Row(
                      children: [
                        SizedBox(width: 36, child: Text('${intOf(r['rank'])}', style: AppText.headlineSm)),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${stringOf(r['name'])}${r['is_me'] == true ? ' (you)' : ''}',
                                style: AppText.bodyLg,
                              ),
                              if (stringOf(r['class']).isNotEmpty)
                                Text(stringOf(r['class']), style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
                            ],
                          ),
                        ),
                        Text('${intOf(r['points'])} pts', style: AppText.bodyLg),
                      ],
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
