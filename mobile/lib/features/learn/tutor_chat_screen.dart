import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../cbt/question_content.dart';

class _Turn {
  const _Turn({required this.text, required this.fromStudent});

  final String text;
  final bool fromStudent;
}

/// The student tutor.
///
/// Student-only on the backend, and deliberately framed as a tutor rather than
/// an answer service — the footer says so, because a student who expects
/// answers and gets method will otherwise think it is broken.
class TutorChatScreen extends ConsumerStatefulWidget {
  const TutorChatScreen({super.key});

  @override
  ConsumerState<TutorChatScreen> createState() => _TutorChatScreenState();
}

class _TutorChatScreenState extends ConsumerState<TutorChatScreen> {
  final _input = TextEditingController();
  final _scroll = ScrollController();
  final List<_Turn> _turns = [];

  int? _conversationId;
  bool _sending = false;

  static const _suggestions = [
    'Explain simultaneous equations',
    'Give me a practice question',
    'Why is my answer wrong?',
  ];

  @override
  void dispose() {
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _send([String? preset]) async {
    final message = (preset ?? _input.text).trim();
    if (message.isEmpty || _sending) return;

    setState(() {
      _turns.add(_Turn(text: message, fromStudent: true));
      _input.clear();
      _sending = true;
    });
    _scrollToEnd();

    try {
      final res = await ref.read(apiClientProvider).post<Map<String, dynamic>>(
        Api.tutorChat,
        body: {
          'message': message,
          if (_conversationId != null) 'conversation_id': _conversationId,
        },
      );

      final reply = stringOf(
        res['reply'] ?? res['message'] ?? res['response'] ?? res['content'],
        'No reply.',
      );

      if (!mounted) return;
      setState(() {
        _conversationId = intOf(res['conversation_id'], _conversationId ?? 0);
        if (_conversationId == 0) _conversationId = null;
        _turns.add(_Turn(text: reply, fromStudent: false));
        _sending = false;
      });
      _scrollToEnd();
    } catch (error) {
      if (!mounted) return;
      final failure = asApiException(error);
      setState(() {
        _sending = false;
        _turns.add(
          _Turn(
            text: failure.isOffline
                ? 'You need a connection to ask your tutor.'
                : failure.message,
            fromStudent: false,
          ),
        );
      });
    }
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(
          _scroll.position.maxScrollExtent,
          duration: const Duration(milliseconds: 200),
          curve: Curves.easeOut,
        );
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Tutor')),
      body: Column(
        children: [
          Expanded(
            child: _turns.isEmpty
                ? _Intro(onPick: _send)
                : ListView.builder(
                    controller: _scroll,
                    padding: const EdgeInsets.all(AppSpacing.screenMargin),
                    itemCount: _turns.length,
                    itemBuilder: (context, i) => _Bubble(turn: _turns[i]),
                  ),
          ),
          if (_sending)
            const Padding(
              padding: EdgeInsets.only(bottom: AppSpacing.sm),
              child: Text('Thinking…', style: AppText.labelSm),
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
                    textInputAction: TextInputAction.send,
                    onSubmitted: (_) => _send(),
                    decoration: const InputDecoration(
                      hintText: 'Ask a question',
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                SizedBox(
                  width: AppSpacing.tapTarget,
                  height: AppSpacing.tapTarget,
                  child: IconButton.filled(
                    onPressed: _sending ? null : () => _send(),
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

class _Intro extends StatelessWidget {
  const _Intro({required this.onPick});

  final ValueChanged<String> onPick;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.lg),
      children: [
        const SizedBox(height: AppSpacing.xl),
        const Icon(Icons.auto_awesome_outlined,
            size: 48, color: AppColors.secondary),
        const SizedBox(height: AppSpacing.md),
        Text(
          'Your tutor explains — it will not just give you the answer.',
          textAlign: TextAlign.center,
          style: AppText.bodyLg,
        ),
        const SizedBox(height: AppSpacing.lg),
        Wrap(
          alignment: WrapAlignment.center,
          spacing: AppSpacing.sm,
          runSpacing: AppSpacing.sm,
          children: [
            for (final suggestion in _TutorChatScreenState._suggestions)
              ActionChip(
                label: Text(suggestion),
                onPressed: () => onPick(suggestion),
              ),
          ],
        ),
      ],
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.turn});

  final _Turn turn;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment:
          turn.fromStudent ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(
          maxWidth: MediaQuery.sizeOf(context).width * 0.82,
        ),
        margin: const EdgeInsets.only(bottom: AppSpacing.sm),
        padding: const EdgeInsets.all(AppSpacing.md),
        decoration: BoxDecoration(
          color: turn.fromStudent
              ? AppColors.primaryContainer
              : AppColors.surfaceContainerLowest,
          borderRadius: AppRadius.cardRadius,
          border: turn.fromStudent
              ? null
              : Border.all(color: AppColors.outlineVariant),
        ),
        child: turn.fromStudent
            ? Text(
                turn.text,
                style: AppText.bodyLg.copyWith(color: AppColors.onPrimary),
              )
            // Tutor replies routinely contain LaTeX, so they go through the
            // same renderer the exam paper uses.
            : QuestionContent(text: turn.text),
      ),
    );
  }
}
