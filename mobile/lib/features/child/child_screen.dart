import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../dashboard/dashboard_providers.dart';
import '../dashboard/home_screen.dart';

final behaviourProvider =
    FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  final childId = ref.watch(selectedChildIdProvider);
  if (childId == null) throw StateError('No child selected');

  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<dynamic>(
    cache: cache,
    key: 'behaviour:$childId',
    fetch: () => api.get<dynamic>(Api.behaviorHistory(childId)),
  );
});

/// Attendance and behaviour for the selected child.
class ChildScreen extends ConsumerStatefulWidget {
  const ChildScreen({super.key});

  @override
  ConsumerState<ChildScreen> createState() => _ChildScreenState();
}

class _ChildScreenState extends ConsumerState<ChildScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final childId = ref.watch(selectedChildIdProvider);

    return Scaffold(
      appBar: AppBar(title: const ChildSwitcher()),
      body: childId == null
          ? const EmptyState(
              icon: Icons.family_restroom_outlined,
              title: 'No child selected',
              message: 'Ask the school office to link your child to your account.',
            )
          : Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(AppSpacing.screenMargin),
                  child: SegmentedButton<int>(
                    segments: const [
                      ButtonSegment(value: 0, label: Text('Attendance')),
                      ButtonSegment(value: 1, label: Text('Behaviour')),
                    ],
                    selected: {_tab},
                    showSelectedIcon: false,
                    onSelectionChanged: (s) => setState(() => _tab = s.first),
                  ),
                ),
                Expanded(
                  child: _tab == 0
                      ? const _AttendanceView()
                      : const _BehaviourView(),
                ),
              ],
            ),
    );
  }
}

class _AttendanceView extends ConsumerWidget {
  const _AttendanceView();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final feed = ref.watch(parentFeedProvider);

    return CachedView<Map<String, dynamic>>(
      value: feed,
      onRetry: () => ref.invalidate(parentFeedProvider),
      builder: (context, data, meta) {
        final records = listOf(
          data['attendance'] ?? data['recent_attendance'],
          ['attendance'],
        );

        if (records.isEmpty) {
          return const EmptyState(
            icon: Icons.event_available_outlined,
            title: 'No attendance yet',
            message: 'Attendance appears here once the register is taken.',
          );
        }

        int count(String status) => records
            .where((r) => stringOf(r['status']).toLowerCase() == status)
            .length;

        final exceptions = records
            .where((r) => stringOf(r['status']).toLowerCase() != 'present')
            .toList();

        return RefreshableList(
          onRefresh: () async => ref.refresh(parentFeedProvider.future),
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(
                horizontal: AppSpacing.screenMargin,
              ),
              child: Wrap(
                spacing: AppSpacing.sm,
                runSpacing: AppSpacing.sm,
                children: [
                  StatusChip('Present ${count('present')}',
                      color: AppColors.present),
                  StatusChip('Absent ${count('absent')}',
                      color: AppColors.absent),
                  StatusChip('Late ${count('late')}', color: AppColors.late),
                ],
              ),
            ),
            const SectionHeader('Days to note'),
            if (exceptions.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(
                  horizontal: AppSpacing.screenMargin,
                ),
                child: Text(
                  'Present every day in this period.',
                  style: AppText.bodyMd,
                ),
              ),
            for (final record in exceptions)
              Padding(
                padding: const EdgeInsets.only(
                  left: AppSpacing.screenMargin,
                  right: AppSpacing.screenMargin,
                  bottom: AppSpacing.sm,
                ),
                child: OutlinedCard(
                  statusColor:
                      stringOf(record['status']).toLowerCase() == 'absent'
                          ? AppColors.absent
                          : AppColors.late,
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          Dates.full(Dates.tryParse(record['date'])),
                          style: AppText.bodyMd,
                        ),
                      ),
                      StatusChip(
                        stringOf(record['status'], '—'),
                        color:
                            stringOf(record['status']).toLowerCase() == 'absent'
                                ? AppColors.absent
                                : AppColors.late,
                      ),
                    ],
                  ),
                ),
              ),
            const SizedBox(height: AppSpacing.xl),
          ],
        );
      },
    );
  }
}

class _BehaviourView extends ConsumerWidget {
  const _BehaviourView();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final behaviour = ref.watch(behaviourProvider);

    return CachedView<dynamic>(
      value: behaviour,
      onRetry: () => ref.invalidate(behaviourProvider),
      builder: (context, data, meta) {
        final reports = listOf(data, ['reports', 'behavior_reports']);

        if (reports.isEmpty) {
          return const EmptyState(
            icon: Icons.emoji_emotions_outlined,
            title: 'Nothing recorded',
            message: 'No behaviour notes have been written for your child.',
          );
        }

        return RefreshableList(
          onRefresh: () async => ref.refresh(behaviourProvider.future),
          children: [
            for (final report in reports)
              Padding(
                padding: const EdgeInsets.only(
                  left: AppSpacing.screenMargin,
                  right: AppSpacing.screenMargin,
                  top: AppSpacing.sm,
                ),
                child: OutlinedCard(
                  statusColor: _colour(report),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          StatusChip(
                            stringOf(
                              report['category'] ?? report['type'],
                              'Note',
                            ),
                            color: _colour(report),
                          ),
                          const Spacer(),
                          Text(
                            Dates.dayMonth(
                              Dates.tryParse(
                                report['created_at'] ?? report['date'],
                              ),
                            ),
                            style: AppText.labelSm
                                .copyWith(color: AppColors.onSurfaceVariant),
                          ),
                        ],
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      Text(
                        stringOf(report['description'] ?? report['note'], '—'),
                        style: AppText.bodyMd,
                      ),
                      if (report['teacher'] != null) ...[
                        const SizedBox(height: 4),
                        Text(
                          stringOf(report['teacher']?['name']),
                          style: AppText.labelSm
                              .copyWith(color: AppColors.onSurfaceVariant),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            const SizedBox(height: AppSpacing.xl),
          ],
        );
      },
    );
  }

  static Color _colour(Map<String, dynamic> report) {
    final type = stringOf(report['category'] ?? report['type']).toLowerCase();
    return type.contains('concern') ||
            type.contains('negative') ||
            type.contains('incident')
        ? AppColors.absent
        : AppColors.present;
  }
}
