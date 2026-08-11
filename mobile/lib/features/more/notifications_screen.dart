import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';

/// The notification inbox.
///
/// Every role has one now. `GET /notifications/history` is the school's
/// delivery ledger — every message to every family, with the totals a bursar
/// checks an SMS bill against — and stays admin-only; this reads
/// `GET /me/notifications`, which is scoped to the caller's own rows.
///
/// It exists because a push is a banner that disappears. A parent who taps away
/// a fee-deadline notification on the bus had, until this, no way to find out
/// what it said.
final notificationsProvider =
    FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<dynamic>(
    cache: cache,
    key: 'notifications:inbox',
    fetch: () => api.get<dynamic>(Api.myNotifications),
  );
});

/// What the badge shows. Read from the same payload rather than counted in the
/// client, so it stays right when the list is only the first page.
final unreadNotificationsProvider = Provider.autoDispose<int>((ref) {
  final data = ref.watch(notificationsProvider).valueOrNull?.data;

  return data is Map ? intOf(data['unread_count']) : 0;
});

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifications = ref.watch(notificationsProvider);
    final unread = ref.watch(unreadNotificationsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          if (unread > 0)
            TextButton(
              onPressed: () => _markAllRead(ref),
              child: const Text('Mark all read'),
            ),
        ],
      ),
      body: CachedView<dynamic>(
        value: notifications,
        onRetry: () => ref.invalidate(notificationsProvider),
        builder: (context, data, meta) {
          final list = listOf(data, ['notifications']);

          if (list.isEmpty) {
            return const EmptyState(
              icon: Icons.notifications_none,
              title: "You're all caught up",
              message: 'Messages from your school will appear here.',
            );
          }

          return RefreshIndicator(
            onRefresh: () async => ref.refresh(notificationsProvider.future),
            child: ListView.separated(
              itemCount: list.length,
              separatorBuilder: (_, __) => const Divider(height: 1),
              itemBuilder: (context, i) => _NotificationTile(item: list[i]),
            ),
          );
        },
      ),
    );
  }

  Future<void> _markAllRead(WidgetRef ref) async {
    try {
      await ref.read(apiClientProvider).post<dynamic>(Api.myNotificationsRead);
      ref.invalidate(notificationsProvider);
    } catch (_) {
      // Clearing a badge is not worth an error dialog. The next refresh will
      // show whatever the server actually thinks.
    }
  }
}

class _NotificationTile extends StatelessWidget {
  const _NotificationTile({required this.item});

  final Map<String, dynamic> item;

  @override
  Widget build(BuildContext context) {
    final channel = stringOf(item['channel'], 'push');
    final read = item['read'] == true;

    return ListTile(
      leading: CircleAvatar(
        backgroundColor: AppColors.primaryContainer.withValues(alpha: 0.12),
        child: Icon(
          switch (stringOf(item['category'])) {
            'fee_reminder' => Icons.receipt_long_outlined,
            'result_published' => Icons.workspace_premium_outlined,
            'homework' => Icons.assignment_outlined,
            'attendance' => Icons.event_available_outlined,
            _ => switch (channel) {
                'sms' => Icons.sms_outlined,
                'whatsapp' => Icons.chat_outlined,
                _ => Icons.notifications_outlined,
              },
          },
          size: 20,
          color: AppColors.primaryContainer,
        ),
      ),
      title: Text(
        stringOf(item['body'], '—'),
        maxLines: 3,
        overflow: TextOverflow.ellipsis,
        // Unread reads heavier, which is the only thing distinguishing a
        // message a parent has dealt with from one they have not.
        style: read ? null : AppText.bodyMd.copyWith(fontWeight: FontWeight.w600),
      ),
      subtitle: Text(
        Dates.dayAndTime(Dates.tryParse(item['sent_at'])),
        style: AppText.labelSm,
      ),
      trailing: read
          ? null
          : Container(
              width: 8,
              height: 8,
              decoration: const BoxDecoration(
                color: AppColors.primaryContainer,
                shape: BoxShape.circle,
              ),
            ),
    );
  }
}
