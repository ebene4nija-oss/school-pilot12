import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// The notification inbox.
///
/// `GET /notifications/history` is admin-only and there is no per-user inbox
/// route (gap G6), so for everyone else this explains itself instead of
/// showing an empty list that looks like a bug.
final notificationsProvider =
    FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<dynamic>(
    cache: cache,
    key: 'notifications:history',
    fetch: () => api.get<dynamic>(Api.notificationHistory),
  );
});

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final role = ref.watch(currentRoleProvider);
    final notifications = ref.watch(notificationsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Notifications')),
      body: !role.isAdmin
          ? const EmptyState(
              icon: Icons.notifications_none,
              title: 'No inbox yet',
              message:
                  'Messages your school sends arrive as phone notifications. '
                  'A history of them is not available in the app yet.',
            )
          : notifications.when(
              loading: () => const LoadingState(),
              error: (error, _) => ErrorState(
                error: asApiException(error),
                onRetry: () => ref.invalidate(notificationsProvider),
              ),
              data: (cached) {
                final list = listOf(cached.data, ['notifications', 'history']);

                if (list.isEmpty) {
                  return const EmptyState(
                    icon: Icons.notifications_none,
                    title: "You're all caught up",
                    message: 'Nothing has been sent recently.',
                  );
                }

                return ListView.separated(
                  itemCount: list.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final item = list[i];
                    final channel = stringOf(item['channel'], 'push');
                    final failed = stringOf(item['status']) == 'failed';

                    return ListTile(
                      leading: CircleAvatar(
                        backgroundColor: (failed
                                ? AppColors.absent
                                : AppColors.primaryContainer)
                            .withValues(alpha: 0.12),
                        child: Icon(
                          switch (channel) {
                            'sms' => Icons.sms_outlined,
                            'whatsapp' => Icons.chat_outlined,
                            _ => Icons.notifications_outlined,
                          },
                          size: 20,
                          color: failed
                              ? AppColors.absent
                              : AppColors.primaryContainer,
                        ),
                      ),
                      title: Text(
                        stringOf(item['body'] ?? item['message'], '—'),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      subtitle: Text(
                        '${channel.toUpperCase()} · '
                        '${Dates.dayAndTime(Dates.tryParse(item['created_at']))}',
                        style: AppText.labelSm,
                      ),
                      trailing: failed
                          ? const StatusChip('Failed', color: AppColors.absent)
                          : null,
                    );
                  },
                );
              },
            ),
    );
  }
}
