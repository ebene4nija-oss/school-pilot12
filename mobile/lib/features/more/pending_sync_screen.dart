import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/db/outbox.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final outboxItemsProvider =
    FutureProvider.autoDispose<List<OutboxItem>>((ref) async {
  return ref.watch(outboxProvider).all();
});

/// What is waiting to reach the server, and what failed.
///
/// The queue is visible on purpose. A write that cannot succeed is kept here
/// with the server's own message rather than dropped — losing a teacher's
/// register silently is the worst thing the offline layer could do.
class PendingSyncScreen extends ConsumerWidget {
  const PendingSyncScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(outboxItemsProvider);
    final sync = ref.watch(syncServiceProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Offline queue'),
        actions: [
          IconButton(
            tooltip: 'Try now',
            onPressed: () async {
              await sync.drain();
              ref.invalidate(outboxItemsProvider);
            },
            icon: const Icon(Icons.sync),
          ),
        ],
      ),
      body: items.when(
        loading: () => const LoadingState(rows: 3),
        error: (_, __) => const EmptyState(
          icon: Icons.error_outline,
          title: 'Could not read the queue',
          message: 'Restart the app and try again.',
        ),
        data: (list) {
          if (list.isEmpty) {
            return const EmptyState(
              icon: Icons.cloud_done_outlined,
              title: 'Everything is saved',
              message: 'Nothing is waiting to be sent to your school.',
            );
          }

          final failed = list.where((i) => i.status == OutboxStatus.failed);

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              if (failed.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.md),
                  child: OutlinedCard(
                    statusColor: AppColors.absent,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${failed.length} could not be saved',
                          style: AppText.bodyLg,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'The school server rejected these. Fix the problem it '
                          'describes, then try again.',
                          style: AppText.bodyMd
                              .copyWith(color: AppColors.onSurfaceVariant),
                        ),
                        const SizedBox(height: AppSpacing.md),
                        SizedBox(
                          width: double.infinity,
                          child: OutlinedButton(
                            onPressed: () async {
                              await sync.retryFailed();
                              ref.invalidate(outboxItemsProvider);
                            },
                            child: const Text('Try all again'),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              for (final item in list)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: OutlinedCard(
                    statusColor: item.status == OutboxStatus.failed
                        ? AppColors.absent
                        : AppColors.late,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(item.label, style: AppText.bodyLg),
                            ),
                            if (item.status == OutboxStatus.failed)
                              const StatusChip('Failed', color: AppColors.absent)
                            else
                              const StatusChip('Waiting', color: AppColors.late),
                          ],
                        ),
                        const SizedBox(height: 4),
                        Text(
                          Dates.dayAndTime(item.createdAt),
                          style: AppText.labelSm
                              .copyWith(color: AppColors.onSurfaceVariant),
                        ),
                        if (item.lastError != null) ...[
                          const SizedBox(height: AppSpacing.sm),
                          Text(
                            item.lastError!,
                            style: AppText.bodyMd
                                .copyWith(color: AppColors.absent),
                          ),
                        ],
                        if (item.status == OutboxStatus.failed) ...[
                          const SizedBox(height: AppSpacing.sm),
                          Align(
                            alignment: Alignment.centerRight,
                            child: TextButton(
                              onPressed: () async {
                                await ref
                                    .read(outboxProvider)
                                    .discard(item.id);
                                await sync.refreshCounts();
                                ref.invalidate(outboxItemsProvider);
                              },
                              child: const Text('Discard'),
                            ),
                          ),
                        ],
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
