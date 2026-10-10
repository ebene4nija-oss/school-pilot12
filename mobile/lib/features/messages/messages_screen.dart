import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/auth/session.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import 'new_message_screen.dart';

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

/// Who a conversation is with, seen from my side: the parent for the teacher,
/// the teacher for the parent.
///
/// Threads have no `participant` field — they carry `teacher` and `parent`.
/// Reading the one that does not exist titled every row "Conversation".
String threadWith(Map<String, dynamic> thread, int? meId) {
  final iAmTeacher = meId != null && intOf(thread['teacher_id']) == meId;
  final other = nameOf(iAmTeacher ? thread['parent'] : thread['teacher']);
  return stringOf(other ?? thread['subject'], 'Conversation');
}

/// "About Ada Obi · Homework" — the child and subject, when there are any.
String threadAbout(Map<String, dynamic> thread) {
  final child = stringOf(nameOf(fieldOf(thread['student'], 'user')));
  final subject = stringOf(thread['subject']);
  return [
    if (child.isNotEmpty) 'About $child',
    if (subject.isNotEmpty) subject,
  ].join(' · ');
}

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
    final role = ref.watch(currentRoleProvider);
    final meId = ref.watch(currentUserProvider)?.id;

    return Scaffold(
      appBar: AppBar(title: const Text('Messages')),
      // Staff write to a child's parents; a parent writes to their child's
      // teachers. Both start from the child.
      floatingActionButton: role == UserRole.teacher || role.isAdmin || role == UserRole.parent
          ? FloatingActionButton.extended(
              onPressed: () => _compose(context, parent: role == UserRole.parent),
              icon: const Icon(Icons.edit_outlined),
              label: const Text('New message'),
            )
          : null,
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
                  meId: meId,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => ThreadScreen(
                        threadId: intOf(thread['id']),
                        title: threadWith(thread, meId),
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

  /// Opens the new conversation once it is sent, so the teacher sees it land.
  Future<void> _compose(BuildContext context, {bool parent = false}) async {
    final navigator = Navigator.of(context);
    final threadId = await navigator.push<int>(
      MaterialPageRoute(
        builder: (_) => parent ? const ParentNewMessageScreen() : const NewMessageScreen(),
      ),
    );

    if (threadId == null || threadId == 0) return;
    await navigator.push<void>(
      MaterialPageRoute(
        builder: (_) => ThreadScreen(threadId: threadId, title: 'Conversation'),
      ),
    );
  }
}

class _ThreadRow extends StatelessWidget {
  const _ThreadRow({required this.thread, required this.meId, required this.onTap});

  final Map<String, dynamic> thread;
  final int? meId;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final title = threadWith(thread, meId);
    final about = threadAbout(thread);
    final unread = intOf(thread['unread_count'] ?? thread['unread']);
    final preview = stringOf(
      fieldOf(thread['last_message'], 'body') ?? thread['latest_message'],
    );

    return ListTile(
      onTap: onTap,
      leading: InitialsAvatar(title),
      title: Text(title, style: AppText.bodyLg),
      isThreeLine: about.isNotEmpty && preview.isNotEmpty,
      subtitle: about.isEmpty && preview.isEmpty
          ? null
          : Text(
              [if (about.isNotEmpty) about, if (preview.isNotEmpty) preview].join('\n'),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
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
  final _scroll = ScrollController();
  bool _sending = false;
  Timer? _poll;
  int _shown = -1;

  @override
  void initState() {
    super.initState();
    // While the conversation is open and the app is in front, check for
    // replies every few seconds, so it reads like a chat. The previous
    // messages stay on screen during the refresh.
    _poll = Timer.periodic(const Duration(seconds: 8), (_) {
      if (!mounted || _sending) return;
      if (WidgetsBinding.instance.lifecycleState != AppLifecycleState.resumed) return;
      ref.invalidate(threadProvider(widget.threadId));
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    _scroll.dispose();
    _input.dispose();
    super.dispose();
  }

  /// Jump to the newest message when the count changes, not on every refresh.
  void _followNewest(int count) {
    if (count == _shown) return;
    _shown = count;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (_scroll.hasClients) _scroll.jumpTo(_scroll.position.maxScrollExtent);
      // Opening or receiving marks messages read: refresh the list's badges.
      ref.invalidate(threadsProvider);
    });
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

    // The thread names both people, so a conversation opened straight after
    // sending (title not yet known) still says who it is with.
    final loaded = thread.valueOrNull;
    final title = loaded == null ? widget.title : threadWith(mapOf(loaded['thread']), me?.id);
    final about = loaded == null ? '' : threadAbout(mapOf(loaded['thread']));

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title),
            if (about.isNotEmpty)
              Text(about, style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant)),
          ],
        ),
      ),
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

                _followNewest(messages.length);
                return ListView.builder(
                  controller: _scroll,
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
                              '${Dates.time(Dates.tryParse(message['created_at']))}'
                              '${mine ? (message['read_at'] != null ? ' · Seen' : ' · Sent') : ''}',
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
