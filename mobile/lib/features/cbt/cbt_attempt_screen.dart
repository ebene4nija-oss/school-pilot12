import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import 'cbt_answer_buffer.dart';
import 'question_content.dart';

final answerBufferProvider = Provider<CbtAnswerBuffer>(
  (ref) => CbtAnswerBuffer(ref.watch(databaseProvider)),
);

/// Sitting a paper.
///
/// A focus environment: no bottom navigation, and the back gesture asks before
/// it leaves. Answers are written to SQLite on every tap and pushed to the
/// server opportunistically, so a candidate who loses signal mid-paper keeps
/// answering and nothing is lost.
class CbtAttemptScreen extends ConsumerStatefulWidget {
  const CbtAttemptScreen({super.key, required this.attemptId});

  final int attemptId;

  @override
  ConsumerState<CbtAttemptScreen> createState() => _CbtAttemptScreenState();
}

class _CbtAttemptScreenState extends ConsumerState<CbtAttemptScreen>
    with WidgetsBindingObserver {
  List<Map<String, dynamic>> _questions = const [];
  Map<int, Object?> _answers = {};
  final Set<int> _flagged = {};

  int _index = 0;
  bool _loading = true;
  bool _submitting = false;
  ApiException? _error;

  DateTime? _endsAt;
  Timer? _ticker;
  Timer? _flushTimer;
  int _unsynced = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _flushTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Tab-away and focus loss are logged as proctoring signals. This is not a
  /// lockdown — the app does not attempt kiosk mode, it just tells the school
  /// what happened.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.inactive) {
      unawaited(_logEvent('focus_lost'));
    } else if (state == AppLifecycleState.resumed) {
      unawaited(_logEvent('focus_regained'));
      unawaited(_flush());
    }
  }

  Future<void> _load() async {
    try {
      final api = ref.read(apiClientProvider);
      final data =
          await api.get<Map<String, dynamic>>(Api.cbtAttempt(widget.attemptId));

      final questions = listOf(data, ['questions', 'items']);
      final buffered = await ref.read(answerBufferProvider).answers(widget.attemptId);

      // Server-side answers seed the buffer, then anything answered locally
      // since the last sync wins — the local copy is always the newer one.
      final serverAnswers = <int, Object?>{};
      for (final q in questions) {
        final given = q['response'] ?? q['answer'];
        if (given != null) serverAnswers[intOf(q['id'])] = given;
      }

      final duration = intOf(
        mapOf(data, ['exam'])['duration_minutes'] ?? data['duration_minutes'],
      );
      final started = Dates.tryParse(data['started_at']) ?? DateTime.now();
      final expires = Dates.tryParse(data['expires_at'] ?? data['ends_at']);

      if (!mounted) return;
      setState(() {
        _questions = questions;
        _answers = {...serverAnswers, ...buffered};
        _endsAt = expires ??
            (duration > 0 ? started.add(Duration(minutes: duration)) : null);
        _loading = false;
      });

      _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
        if (mounted) setState(() {});
      });

      // Opportunistic flush. Never on every keystroke — that is somebody's
      // data bundle in an exam hall of forty candidates.
      _flushTimer = Timer.periodic(
        const Duration(seconds: 20),
        (_) => unawaited(_flush()),
      );

      await _refreshUnsynced();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = asApiException(error);
      });
    }
  }

  Future<void> _refreshUnsynced() async {
    final count =
        await ref.read(answerBufferProvider).unsyncedCount(widget.attemptId);
    if (mounted) setState(() => _unsynced = count);
  }

  Future<void> _answer(int questionId, Object? response) async {
    setState(() => _answers[questionId] = response);
    await ref.read(answerBufferProvider).save(
          attemptId: widget.attemptId,
          questionId: questionId,
          response: response,
        );
    await _refreshUnsynced();
  }

  Future<void> _flush() async {
    final buffer = ref.read(answerBufferProvider);
    final payload = await buffer.unsyncedPayload(widget.attemptId);
    if (payload.isEmpty) return;

    try {
      await ref.read(apiClientProvider).post<dynamic>(
            Api.cbtAnswers(widget.attemptId),
            body: {'answers': payload},
          );
      await buffer.markSynced(
        widget.attemptId,
        [for (final a in payload) a['question_id'] as int],
      );
      await _refreshUnsynced();
    } catch (_) {
      // Offline is the expected case here; the answers stay on disk and the
      // next flush, or the submit path, will carry them.
    }
  }

  Future<void> _submit() async {
    final unanswered = _questions.length - _answers.length;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Submit your paper?'),
        content: Text(
          unanswered > 0
              ? 'You have $unanswered '
                  '${unanswered == 1 ? 'question' : 'questions'} unanswered. '
                  'You cannot change your answers after submitting.'
              : 'You cannot change your answers after submitting.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Keep working'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Submit'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;
    setState(() => _submitting = true);

    final api = ref.read(apiClientProvider);
    final buffer = ref.read(answerBufferProvider);

    try {
      // Everything still on the device goes up with the submit, through the
      // offline-sync route that understands client sequencing.
      final pending = await buffer.unsyncedPayload(widget.attemptId);
      if (pending.isNotEmpty) {
        await api.post<dynamic>(
          Api.cbtOfflineSync,
          body: {
            'attempt_id': widget.attemptId,
            'answers': pending,
            'submit': true,
          },
        );
      } else {
        await api.post<dynamic>(Api.cbtSubmit(widget.attemptId));
      }

      await buffer.clear(widget.attemptId);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } catch (error) {
      if (!mounted) return;
      setState(() => _submitting = false);
      final failure = asApiException(error);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            failure.isOffline
                ? 'No connection. Your answers are safe on this phone — '
                    'reconnect and submit again.'
                : failure.message,
          ),
        ),
      );
    }
  }

  Future<void> _logEvent(String type) async {
    try {
      await ref.read(apiClientProvider).post<dynamic>(
            Api.cbtEvents(widget.attemptId),
            body: {'event_type': type, 'occurred_at': DateTime.now().toUtc().toIso8601String()},
          );
    } catch (_) {
      // A proctoring signal is best-effort; it must never interrupt the paper.
    }
  }

  Duration? get _remaining =>
      _endsAt?.difference(DateTime.now());

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    if (_error != null) {
      return Scaffold(
        appBar: AppBar(),
        body: ErrorState(error: _error!, onRetry: _load),
      );
    }

    if (_questions.isEmpty) {
      return Scaffold(
        appBar: AppBar(),
        body: const EmptyState(
          icon: Icons.quiz_outlined,
          title: 'No questions',
          message: 'This paper has no questions attached to it yet.',
        ),
      );
    }

    final question = _questions[_index];
    final questionId = intOf(question['id']);
    final options = listOf(question['options'] ?? question['choices']);
    final remaining = _remaining;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        final leave = await showDialog<bool>(
          context: context,
          builder: (_) => AlertDialog(
            title: const Text('Leave the exam?'),
            content: const Text(
              'Your answers are saved on this phone, but the timer keeps '
              'running.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Stay'),
              ),
              TextButton(
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Leave'),
              ),
            ],
          ),
        );
        if (!context.mounted) return;
        if (leave ?? false) Navigator.of(context).pop();
      },
      child: Scaffold(
        body: SafeArea(
          child: Column(
            children: [
              _ExamHeader(
                index: _index,
                total: _questions.length,
                remaining: remaining,
              ),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.all(AppSpacing.screenMargin),
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: QuestionContent(
                            text: stringOf(
                              question['question_text'] ??
                                  question['body'] ??
                                  question['text'],
                            ),
                            imageUrls: _imageUrls(question),
                          ),
                        ),
                        const SizedBox(width: AppSpacing.sm),
                        IconButton(
                          onPressed: () => setState(
                            () => _flagged.contains(questionId)
                                ? _flagged.remove(questionId)
                                : _flagged.add(questionId),
                          ),
                          icon: Icon(
                            _flagged.contains(questionId)
                                ? Icons.flag
                                : Icons.outlined_flag,
                            color: _flagged.contains(questionId)
                                ? AppColors.late
                                : AppColors.outline,
                          ),
                          tooltip: 'Flag for review',
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.lg),
                    if (options.isEmpty)
                      TextField(
                        maxLines: 6,
                        controller: TextEditingController(
                          text: stringOf(_answers[questionId]),
                        ),
                        onChanged: (v) => _answer(questionId, v),
                        decoration: const InputDecoration(
                          labelText: 'Your answer',
                          alignLabelWithHint: true,
                        ),
                      )
                    else
                      for (final option in options)
                        Padding(
                          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                          child: _OptionCard(
                            label: stringOf(
                              option['text'] ?? option['label'] ?? option,
                            ),
                            selected: _answers[questionId] ==
                                (option['id'] ?? option['key'] ?? option['text']),
                            onTap: () => _answer(
                              questionId,
                              option['id'] ?? option['key'] ?? option['text'],
                            ),
                          ),
                        ),
                    const SizedBox(height: AppSpacing.xl),
                  ],
                ),
              ),
              _SavedIndicator(unsynced: _unsynced),
              _ExamFooter(
                canGoBack: _index > 0,
                isLast: _index == _questions.length - 1,
                submitting: _submitting,
                onPrevious: () => setState(() => _index--),
                onNext: () => setState(() => _index++),
                onSubmit: _submit,
                onGrid: _showNavigator,
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<String> _imageUrls(Map<String, dynamic> question) {
    final media = question['media'] ?? question['images'] ?? question['assets'];
    if (media is List) {
      return [
        for (final m in media)
          if (m is Map)
            stringOf(m['url'] ?? m['path'])
          else
            stringOf(m),
      ].where((s) => s.isNotEmpty).toList();
    }
    final single = stringOf(question['image_url']);
    return single.isEmpty ? const [] : [single];
  }

  /// The question navigator — answered green, flagged amber, unanswered
  /// outlined. Missing from the design set; a 40-question paper is unusable
  /// without it.
  void _showNavigator() {
    showModalBottomSheet<void>(
      context: context,
      builder: (_) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Questions', style: AppText.headlineSm),
              const SizedBox(height: AppSpacing.md),
              GridView.builder(
                shrinkWrap: true,
                itemCount: _questions.length,
                gridDelegate:
                    const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 5,
                  mainAxisSpacing: AppSpacing.sm,
                  crossAxisSpacing: AppSpacing.sm,
                ),
                itemBuilder: (context, i) {
                  final id = intOf(_questions[i]['id']);
                  final answered = _answers.containsKey(id);
                  final flagged = _flagged.contains(id);
                  final colour = flagged
                      ? AppColors.late
                      : answered
                          ? AppColors.present
                          : null;

                  return InkWell(
                    onTap: () {
                      setState(() => _index = i);
                      Navigator.pop(context);
                    },
                    child: Container(
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: colour ?? AppColors.surfaceContainerLowest,
                        borderRadius: AppRadius.cardRadius,
                        border: Border.all(
                          color: colour ?? AppColors.outlineVariant,
                        ),
                      ),
                      child: NumericText(
                        '${i + 1}',
                        weight: FontWeight.w700,
                        color: colour == null ? AppColors.onSurface : Colors.white,
                      ),
                    ),
                  );
                },
              ),
              const SizedBox(height: AppSpacing.md),
              Wrap(
                spacing: AppSpacing.sm,
                children: const [
                  StatusChip('Answered', color: AppColors.present),
                  StatusChip('Flagged', color: AppColors.late),
                  StatusChip('Unanswered'),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ExamHeader extends StatelessWidget {
  const _ExamHeader({
    required this.index,
    required this.total,
    required this.remaining,
  });

  final int index;
  final int total;
  final Duration? remaining;

  @override
  Widget build(BuildContext context) {
    final minutes = remaining?.inMinutes ?? 999;
    final colour = minutes < 1
        ? AppColors.absent
        : minutes < 5
            ? AppColors.late
            : AppColors.onSurface;

    return Column(
      children: [
        ProgressBar(value: (index + 1) / total, height: 4),
        Padding(
          padding: const EdgeInsets.all(AppSpacing.screenMargin),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  'Question ${index + 1} of $total',
                  style: AppText.headlineSm,
                ),
              ),
              if (remaining != null)
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.sm + 4,
                    vertical: AppSpacing.sm,
                  ),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(999),
                    border: Border.all(color: AppColors.outlineVariant),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.schedule, size: 16, color: colour),
                      const SizedBox(width: 6),
                      NumericText(
                        Dates.countdown(remaining!),
                        weight: FontWeight.w700,
                        color: colour,
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),
        const Divider(height: 1),
      ],
    );
  }
}

class _OptionCard extends StatelessWidget {
  const _OptionCard({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected
          ? AppColors.primaryContainer.withValues(alpha: 0.08)
          : AppColors.surfaceContainerLowest,
      borderRadius: AppRadius.cardRadius,
      child: InkWell(
        onTap: onTap,
        borderRadius: AppRadius.cardRadius,
        child: Container(
          constraints: const BoxConstraints(minHeight: 56),
          padding: const EdgeInsets.all(AppSpacing.md),
          decoration: BoxDecoration(
            borderRadius: AppRadius.cardRadius,
            border: Border.all(
              color: selected ? AppColors.primaryContainer : AppColors.outlineVariant,
              width: selected ? 2 : 1,
            ),
          ),
          child: Row(
            children: [
              Icon(
                selected ? Icons.radio_button_checked : Icons.radio_button_off,
                color: selected ? AppColors.primaryContainer : AppColors.outline,
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(child: QuestionContent(text: label)),
            ],
          ),
        ),
      ),
    );
  }
}

class _SavedIndicator extends StatelessWidget {
  const _SavedIndicator({required this.unsynced});

  final int unsynced;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            unsynced == 0 ? Icons.cloud_done_outlined : Icons.save_outlined,
            size: 16,
            color: AppColors.present,
          ),
          const SizedBox(width: 6),
          Text(
            unsynced == 0
                ? 'Answers saved'
                : 'Answers saved on this device — $unsynced to send',
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
        ],
      ),
    );
  }
}

class _ExamFooter extends StatelessWidget {
  const _ExamFooter({
    required this.canGoBack,
    required this.isLast,
    required this.submitting,
    required this.onPrevious,
    required this.onNext,
    required this.onSubmit,
    required this.onGrid,
  });

  final bool canGoBack;
  final bool isLast;
  final bool submitting;
  final VoidCallback onPrevious;
  final VoidCallback onNext;
  final VoidCallback onSubmit;
  final VoidCallback onGrid;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm + 4),
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: AppColors.outlineVariant)),
      ),
      child: Row(
        children: [
          Expanded(
            child: OutlinedButton(
              onPressed: canGoBack ? onPrevious : null,
              child: const Text('Previous'),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          SizedBox(
            width: AppSpacing.tapTarget,
            height: AppSpacing.tapTarget,
            child: IconButton(
              onPressed: onGrid,
              icon: const Icon(Icons.grid_view_outlined),
              tooltip: 'All questions',
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: FilledButton(
              onPressed: submitting ? null : (isLast ? onSubmit : onNext),
              child: Text(isLast ? 'Submit' : 'Next'),
            ),
          ),
        ],
      ),
    );
  }
}
