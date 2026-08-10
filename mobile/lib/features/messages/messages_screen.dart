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

final threadsProvider =
    FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<dynamic>(
    cache: cache,
    key: 'messages:threads',
    fetch: () => api.get<dynamic>(Api.messageThreads),
  );
});

final threadProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, int>((ref, id) async {
  final api = ref.watch(apiClientProvider);
  return api.get<Map<String, dynamic>>(Api.messageThread(id));
});

class MessagesScreen extends ConsumerWidget {
  const MessagesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final threads = ref.watch(threadsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Messages')),
      body: CachedView<dynamic>(
        value: threads,
        onRetry: () => ref.invalidate(threadsProvider),
        builder: (context, data, meta) {
          final list = listOf(data, ['threads']);

          if (list.isEmpty) {
            return const EmptyState(
              icon: Icons.forum_outlined,
              title: 'No messages',
              message: 'Conversations with the school will appear here.',
            );
          }

          return RefreshableList(
            onRefresh: () async => ref.refresh(threadsProvider.future),
            children: [
              for (final thread in list)
                _ThreadRow(
                  thread: thread,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => ThreadScreen(
                        threadId: intOf(thread['id']),
                        title: _title(thread),
                      ),
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }

  static String _title(Map<String, dynamic> thread) => stringOf(
        thread['subject'] ??
            thread['title'] ??
            thread['participant']?['name'] ??
            thread['other_party'],
        'Conversation',
      );
}

class _ThreadRow extends StatelessWidget {
  const _ThreadRow({required this.thread, required this.onTap});

  final Map<String, dynamic> thread;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final title = MessagesScreen._title(thread);
    final unread = intOf(thread['unread_count'] ?? thread['unread']);
    final preview = stringOf(
      thread['last_message']?['body'] ?? thread['latest_message'],
    );

    return ListTile(
      onTap: onTap,
      leading: InitialsAvatar(title),
      title: Text(title, style: AppText.bodyLg),
      subtitle: preview.isEmpty
          ? null
          : Text(preview, maxLines: 1, overflow: TextOverflow.ellipsis),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(
            Dates.dayMonth(
              Dates.tryParse(thread['updated_at'] ?? thread['created_at']),
            ),
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
          if (unread > 0) ...[
            const SizedBox(height: 4),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
              decoration: const BoxDecoration(
                color: AppColors.action,
                shape: BoxShape.circle,
              ),
              child: NumericText(
                '$unread',
                size: 11,
                weight: FontWeight.w700,
                color: AppColors.onAction,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class ThreadScreen extends ConsumerStatefulWidget {
  const ThreadScreen({super.key, required this.threadId, required this.title});

  final int threadId;
  final String title;

  @override
  ConsumerState<ThreadScreen> createState() => _ThreadScreenState();
}

class _ThreadScreenState extends ConsumerState<ThreadScreen> {
  final _input = TextEditingController();
  bool _sending = false;

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final body = _input.text.trim();
    if (body.isEmpty) return;
    setState(() => _sending = true);

    try {
      await ref.read(apiClientProvider).post<dynamic>(
        Api.sendMessage,
        body: {'thread_id': widget.threadId, 'body': body, 'message': body},
      );
      _input.clear();
      ref.invalidate(threadProvider(widget.threadId));
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(asApiException(error).message)),
      );
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final thread = ref.watch(threadProvider(widget.threadId));
    final me = ref.watch(currentUserProvider);

    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: Column(
        children: [
          Expanded(
            child: thread.when(
              loading: () => const LoadingState(rows: 5),
              error: (error, _) => ErrorState(
                error: asApiException(error),
                onRetry: () => ref.invalidate(threadProvider(widget.threadId)),
              ),
              data: (data) {
                final messages = listOf(data, ['messages']);
                if (messages.isEmpty) {
                  return const EmptyState(
                    icon: Icons.chat_bubble_outline,
                    title: 'No messages yet',
                    message: 'Say something to start the conversation.',
                  );
                }

                return ListView.builder(
                  padding: const EdgeInsets.all(AppSpacing.screenMargin),
                  itemCount: messages.length,
                  itemBuilder: (context, i) {
                    final message = messages[i];
                    final mine = intOf(message['sender_id'] ?? message['user_id']) ==
                        (me?.id ?? -1);

                    return Align(
                      alignment:
                          mine ? Alignment.centerRight : Alignment.centerLeft,
                      child: Container(
                        constraints: BoxConstraints(
                          maxWidth: MediaQuery.sizeOf(context).width * 0.8,
                        ),
                        margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                        padding: const EdgeInsets.all(AppSpacing.md),
                        decoration: BoxDecoration(
                          color: mine
                              ? AppColors.primaryContainer
                              : AppColors.surfaceContainerLowest,
                          borderRadius: AppRadius.cardRadius,
                          border: mine
                              ? null
                              : Border.all(color: AppColors.outlineVariant),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              stringOf(message['body'] ?? message['message']),
                              style: AppText.bodyMd.copyWith(
                                color: mine
                                    ? AppColors.onPrimary
                                    : AppColors.onSurface,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              Dates.time(Dates.tryParse(message['created_at'])),
                              style: AppText.labelSm.copyWith(
                                color: mine
                                    ? AppColors.onPrimaryContainer
                                    : AppColors.onSurfaceVariant,
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                );
              },
            ),
          ),
          Container(
            padding: EdgeInsets.fromLTRB(
              AppSpacing.sm + 4,
              AppSpacing.sm,
              AppSpacing.sm + 4,
              AppSpacing.sm + MediaQuery.paddingOf(context).bottom,
            ),
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: AppColors.outlineVariant)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _input,
                    minLines: 1,
                    maxLines: 4,
                    decoration: const InputDecoration(
                      hintText: 'Message',
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                SizedBox(
                  width: AppSpacing.tapTarget,
                  height: AppSpacing.tapTarget,
                  child: IconButton.filled(
                    onPressed: _sending ? null : _send,
                    style: IconButton.styleFrom(
                      backgroundColor: AppColors.action,
                      foregroundColor: AppColors.onAction,
                    ),
                    icon: const Icon(Icons.send),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
