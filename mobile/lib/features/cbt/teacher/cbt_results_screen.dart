import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/endpoints.dart';
import '../../../core/auth/session.dart';
import '../../../core/data/cached.dart';
import '../../../core/providers.dart';
import '../../../core/ui/cached_view.dart';
import '../../../core/ui/states.dart';
import '../../../core/ui/widgets.dart';
import 'cbt_teacher_data.dart';

/// How a paper went, and the scripts still waiting for a teacher's marks.
class CbtResultsScreen extends ConsumerWidget {
  const CbtResultsScreen({super.key, required this.examId});

  final int examId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final results = ref.watch(examResultsProvider(examId));

    return Scaffold(
      appBar: AppBar(title: const Text('Results')),
      body: results.when(
        loading: () => const LoadingState(rows: 5),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(examResultsProvider(examId)),
        ),
        data: (data) {
          final summary = mapOf(data['summary']);
          final candidates = listOf(data['candidates'])
            // Scripts waiting for marks first: that is the job to do.
            ..sort((a, b) {
              final wa = a['requires_manual_grading'] == true ? 0 : 1;
              final wb = b['requires_manual_grading'] == true ? 0 : 1;
              if (wa != wb) return wa.compareTo(wb);
              return stringOf(a['student_name'])
                  .compareTo(stringOf(b['student_name']));
            });
          final waiting = intOf(summary['awaiting_marking']);
          String pct(Object? v) => v == null ? '—' : '${numOf(v).toStringAsFixed(1)}%';

          if (candidates.isEmpty) {
            return const EmptyState(
              icon: Icons.hourglass_empty,
              title: 'No scripts yet',
              message: 'Results appear here as students submit.',
            );
          }

          return RefreshableList(
            onRefresh: () async => ref.refresh(examResultsProvider(examId).future),
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: 'Submitted',
                      value: '${intOf(summary['attempts'])}',
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: 'Average',
                      value: pct(summary['average_percentage']),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.sm),
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: 'Pass rate',
                      value: pct(summary['pass_rate']),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: 'To mark',
                      value: '$waiting',
                      valueColor: waiting > 0 ? AppColors.late : null,
                    ),
                  ),
                ],
              ),
              const SectionHeader('Students'),
              for (final c in candidates)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: InitialsAvatar(stringOf(c['student_name'], '?')),
                  title: Text(stringOf(c['student_name'], 'Student')),
                  subtitle: Text(
                    c['requires_manual_grading'] == true
                        ? 'Theory answers to mark'
                        : 'Grade ${stringOf(c['grade'], '—')}',
                    style: TextStyle(
                      color: c['requires_manual_grading'] == true
                          ? AppColors.late
                          : AppColors.onSurfaceVariant,
                    ),
                  ),
                  trailing: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(pct(c['percentage']), style: AppText.bodyLg),
                      // The server lets only a school admin reset.
                      if (ref.watch(currentRoleProvider) == UserRole.schoolAdmin)
                        PopupMenuButton<String>(
                          tooltip: 'More',
                          onSelected: (_) => _reset(context, ref, c),
                          itemBuilder: (_) => const [
                            PopupMenuItem(value: 'reset', child: Text('Reset attempt')),
                          ],
                        ),
                    ],
                  ),
                  onTap: () async {
                    await Navigator.of(context).push<void>(
                      MaterialPageRoute(
                        builder: (_) => MarkScriptScreen(
                          attemptId: intOf(c['attempt_id']),
                          studentName: stringOf(c['student_name'], 'Student'),
                        ),
                      ),
                    );
                    ref.invalidate(examResultsProvider(examId));
                  },
                ),
            ],
          );
        },
      ),
    );
  }
}

/// Voids [candidate]'s attempt so they can sit the paper again. Asks for a
/// reason (the server requires one and records it); the old attempt and its
/// answers are kept.
Future<void> _reset(BuildContext context, WidgetRef ref, Map<String, dynamic> candidate) async {
  final name = stringOf(candidate['student_name'], 'this student');
  final reason = TextEditingController();
  final ok = await showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      title: Text("Reset $name's attempt?"),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('They can sit the paper again. This attempt and its answers are kept for the record.'),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: reason,
            autofocus: true,
            decoration: const InputDecoration(labelText: 'Reason', hintText: 'e.g. Power went off mid-paper'),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('Cancel')),
        FilledButton(onPressed: () => Navigator.pop(dialogContext, true), child: const Text('Reset')),
      ],
    ),
  );
  final why = reason.text.trim();
  reason.dispose();
  if (ok != true || !context.mounted) return;
  final messenger = ScaffoldMessenger.of(context);
  if (why.length < 5) {
    messenger.showSnackBar(const SnackBar(content: Text('Give a reason of at least a few words.')));
    return;
  }
  try {
    final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
      Api.cbtAttemptReset(intOf(candidate['attempt_id'])),
      body: {'reason': why},
    ));
    messenger.showSnackBar(SnackBar(content: Text(stringOf(res['message'], 'Attempt reset.'))));
    ref.invalidate(examResultsProvider);
  } catch (error) {
    messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
  }
}

final _scriptProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, int>((ref, attemptId) async {
  final api = ref.watch(apiClientProvider);
  final data = mapOf(await api.get<dynamic>(Api.cbtResult(attemptId)));
  return mapOf(data['result']);
});

/// One student's script. Objective answers are shown as the engine marked
/// them; theory answers get a mark box and optional feedback.
class MarkScriptScreen extends ConsumerStatefulWidget {
  const MarkScriptScreen({
    super.key,
    required this.attemptId,
    required this.studentName,
  });

  final int attemptId;
  final String studentName;

  @override
  ConsumerState<MarkScriptScreen> createState() => _MarkScriptScreenState();
}

class _MarkScriptScreenState extends ConsumerState<MarkScriptScreen> {
  final _marks = <int, TextEditingController>{};
  final _feedback = <int, TextEditingController>{};
  bool _saving = false;

  @override
  void dispose() {
    for (final c in [..._marks.values, ..._feedback.values]) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _markFor(Map<String, dynamic> q) =>
      _marks.putIfAbsent(intOf(q['question_id']), () {
        final awarded = numOf(q['awarded_marks']);
        // An unmarked theory answer starts empty, not at a misleading 0.
        final marked = stringOf(q['feedback']).isNotEmpty || awarded > 0;
        return TextEditingController(text: marked ? _trim(awarded) : '');
      });

  TextEditingController _feedbackFor(Map<String, dynamic> q) =>
      _feedback.putIfAbsent(
        intOf(q['question_id']),
        () => TextEditingController(text: stringOf(q['feedback'])),
      );

  static String _trim(num n) =>
      n == n.roundToDouble() ? n.toInt().toString() : n.toString();

  /// A theory response may arrive as text or as `{text, photo...}`.
  static String _response(Object? r) {
    if (r is Map) return stringOf(r['text'] ?? r['answer'] ?? r['value']);
    if (r is List) return r.map(stringOf).join(', ');
    return stringOf(r);
  }

  Future<void> _save(List<Map<String, dynamic>> theory) async {
    final rows = <Map<String, dynamic>>[];

    for (final q in theory) {
      final text = _markFor(q).text.trim();
      if (text.isEmpty) continue;
      final mark = num.tryParse(text);
      final max = numOf(q['marks']);
      if (mark == null || mark < 0 || (max > 0 && mark > max)) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Each mark must be between 0 and the question\'s marks.')),
        );
        return;
      }
      rows.add({
        'question_id': intOf(q['question_id']),
        'awarded_marks': mark,
        if (_feedbackFor(q).text.trim().isNotEmpty)
          'feedback': _feedbackFor(q).text.trim(),
      });
    }

    if (rows.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Enter a mark for at least one answer.')),
      );
      return;
    }

    setState(() => _saving = true);
    try {
      await ref.read(apiClientProvider).post<dynamic>(
        Api.cbtGrade(widget.attemptId),
        body: {'marks': rows},
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Marks saved.')),
      );
      Navigator.pop(context);
    } catch (error) {
      if (!mounted) return;
      setState(() => _saving = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(asApiException(error).message)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final script = ref.watch(_scriptProvider(widget.attemptId));

    return Scaffold(
      appBar: AppBar(title: Text(widget.studentName)),
      body: script.when(
        loading: () => const LoadingState(rows: 5),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(_scriptProvider(widget.attemptId)),
        ),
        data: (result) {
          final questions = listOf(result['questions']);
          final theory = questions.where((q) => isHandMarked(q['question_type'])).toList();

          return Column(
            children: [
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.all(AppSpacing.screenMargin),
                  children: [
                    OutlinedCard(
                      child: Text(
                        '${_trim(numOf(result['raw_score']))} / '
                        '${_trim(numOf(result['max_score']))} · '
                        '${numOf(result['percentage']).toStringAsFixed(1)}% · '
                        'Grade ${stringOf(result['grade'], '—')}',
                        style: AppText.bodyLg,
                      ),
                    ),
                    for (var i = 0; i < questions.length; i++)
                      Padding(
                        padding: const EdgeInsets.only(top: AppSpacing.sm),
                        child: OutlinedCard(
                          statusColor: isHandMarked(questions[i]['question_type'])
                              ? AppColors.pending
                              : questions[i]['is_correct'] == true
                                  ? AppColors.present
                                  : AppColors.absent,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${i + 1}. ${stringOf(questions[i]['question'])}',
                                style: AppText.bodyMd,
                              ),
                              const SizedBox(height: AppSpacing.sm),
                              Text(
                                'Answer: ${_response(questions[i]['your_response']).isEmpty ? '(no answer)' : _response(questions[i]['your_response'])}',
                                style: AppText.bodyMd.copyWith(
                                  color: AppColors.onSurfaceVariant,
                                ),
                              ),
                              if (isHandMarked(questions[i]['question_type'])) ...[
                                if (stringOf(questions[i]['correct_answer']).isNotEmpty)
                                  Text(
                                    'Marking guide: ${_response(questions[i]['correct_answer'])}',
                                    style: AppText.labelSm.copyWith(
                                      color: AppColors.onSurfaceVariant,
                                    ),
                                  ),
                                const SizedBox(height: AppSpacing.sm),
                                Row(
                                  children: [
                                    SizedBox(
                                      width: 110,
                                      child: TextField(
                                        controller: _markFor(questions[i]),
                                        keyboardType:
                                            const TextInputType.numberWithOptions(decimal: true),
                                        decoration: InputDecoration(
                                          labelText: 'Mark',
                                          suffixText: '/ ${_trim(numOf(questions[i]['marks']))}',
                                          isDense: true,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: AppSpacing.sm),
                                    Expanded(
                                      child: TextField(
                                        controller: _feedbackFor(questions[i]),
                                        decoration: const InputDecoration(
                                          labelText: 'Feedback (optional)',
                                          isDense: true,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ] else
                                Text(
                                  '${_trim(numOf(questions[i]['awarded_marks']))} / '
                                  '${_trim(numOf(questions[i]['marks']))} · marked automatically',
                                  style: AppText.labelSm.copyWith(
                                    color: AppColors.onSurfaceVariant,
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ),
                    const SizedBox(height: AppSpacing.xl),
                  ],
                ),
              ),
              if (theory.isNotEmpty)
                BottomActionBar(
                  child: FilledButton(
                    onPressed: _saving ? null : () => _save(theory),
                    child: _saving
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Save marks'),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
