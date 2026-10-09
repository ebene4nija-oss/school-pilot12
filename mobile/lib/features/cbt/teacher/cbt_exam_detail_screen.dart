import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/endpoints.dart';
import '../../../core/data/cached.dart';
import '../../../core/providers.dart';
import '../../../core/ui/formatters.dart';
import '../../../core/ui/states.dart';
import '../../../core/ui/widgets.dart';
import 'cbt_results_screen.dart';
import 'cbt_teacher_data.dart';

/// One paper: its questions, and the publish → close → results path.
///
/// Questions can only be changed while the paper is a draft; the server
/// refuses once it is published, and the screen hides the controls to match.
class CbtExamDetailScreen extends ConsumerStatefulWidget {
  const CbtExamDetailScreen({super.key, required this.examId});

  final int examId;

  @override
  ConsumerState<CbtExamDetailScreen> createState() =>
      _CbtExamDetailScreenState();
}

class _CbtExamDetailScreenState extends ConsumerState<CbtExamDetailScreen> {
  bool _busy = false;

  void _say(String message) {
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(message)));
  }

  Future<bool> _confirm(String title, String body, String action) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: Text(action),
          ),
        ],
      ),
    );
    return ok ?? false;
  }

  Future<void> _run(Future<dynamic> Function() call, String done) async {
    setState(() => _busy = true);
    try {
      final res = mapOf(await call());
      // Publishing can carry a notice or schedule warnings worth reading.
      final notice = stringOf(res['notice']);
      _say(notice.isEmpty ? done : '$done $notice');
      ref.invalidate(examDetailProvider(widget.examId));
      ref.invalidate(teacherExamsProvider);
    } catch (error) {
      if (mounted) _say(asApiException(error).message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _publish() async {
    if (!await _confirm(
      'Publish this exam?',
      'Students in the class will see it and can start once it opens. '
          'Questions cannot be changed after publishing.',
      'Publish',
    )) {
      return;
    }
    await _run(
      () => ref.read(apiClientProvider).post<dynamic>(Api.cbtExamPublish(widget.examId)),
      'Published.',
    );
  }

  Future<void> _close() async {
    if (!await _confirm(
      'Close this exam?',
      'No one can start it after this, and anyone still writing is submitted '
          'automatically with what they have saved.',
      'Close exam',
    )) {
      return;
    }
    await _run(
      () => ref.read(apiClientProvider).post<dynamic>(Api.cbtExamClose(widget.examId)),
      'Closed.',
    );
  }

  /// Release this paper's scores to students and parents. Report cards are
  /// not affected. Unmarked theory makes the server ask first.
  Future<void> _release() async {
    if (!await _confirm(
      'Release the results?',
      'Students who sat this paper, and their parents, will see their scores. '
          'Term report cards are not affected.',
      'Release',
    )) {
      return;
    }
    setState(() => _busy = true);
    try {
      await ref.read(apiClientProvider).post<dynamic>(Api.cbtReleaseResults(widget.examId));
      _say('Results released.');
    } catch (error) {
      final failure = asApiException(error);
      if (failure.statusCode == 409 && mounted && await _confirm('Not ready yet', failure.message, 'Release anyway')) {
        try {
          await ref.read(apiClientProvider).post<dynamic>(Api.cbtReleaseResults(widget.examId), body: {'include_unmarked': true});
          _say('Results released.');
        } catch (again) {
          if (mounted) _say(asApiException(again).message);
        }
      } else if (failure.statusCode != 409 && mounted) {
        _say(failure.message);
      }
    } finally {
      ref.invalidate(examDetailProvider(widget.examId));
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _withdraw() async {
    if (!await _confirm(
      'Withdraw the results?',
      'Students and parents stop seeing the scores until you release them again.',
      'Withdraw',
    )) {
      return;
    }
    await _run(
      () => ref.read(apiClientProvider).post<dynamic>(Api.cbtWithdrawResults(widget.examId)),
      'Results withdrawn.',
    );
  }

  Future<void> _remove(int questionId) => _run(
        () => ref
            .read(apiClientProvider)
            .delete<dynamic>(Api.cbtExamQuestion(widget.examId, questionId)),
        'Question removed.',
      );

  Future<void> _addFromBank(Map<String, dynamic> exam, Set<int> attached) async {
    final added = await Navigator.of(context).push<int>(
      MaterialPageRoute(
        builder: (_) => QuestionBankPicker(
          examId: widget.examId,
          subjectId: intOf(exam['subject_id']),
          classId: exam['class_id'] == null ? null : intOf(exam['class_id']),
          alreadyOnPaper: attached,
        ),
      ),
    );
    if (added != null && added > 0) {
      _say('Added $added ${added == 1 ? 'question' : 'questions'}.');
      ref.invalidate(examDetailProvider(widget.examId));
      ref.invalidate(teacherExamsProvider);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(examDetailProvider(widget.examId));

    return Scaffold(
      appBar: AppBar(title: const Text('Exam')),
      body: detail.when(
        loading: () => const LoadingState(rows: 5),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(examDetailProvider(widget.examId)),
        ),
        data: (data) {
          final exam = mapOf(data['exam']);
          final status = examStatus(exam['status']);
          final isDraft = stringOf(exam['status'], 'draft') == 'draft';
          final isPublished = stringOf(exam['status']) == 'published';
          final rows = listOf(exam['exam_questions']);
          final attached = {for (final r in rows) intOf(r['question_id'])};
          final attempts = intOf(data['attempt_count']);

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              OutlinedCard(
                statusColor: status.color,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            stringOf(exam['title'], 'Exam'),
                            style: AppText.headlineSm,
                          ),
                        ),
                        StatusChip(status.label, color: status.color),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Text(
                      [
                        stringOf(nameOf(exam['subject'])),
                        '${intOf(exam['duration_minutes'])} min',
                        '${numOf(data['total_marks'])} marks',
                      ].where((s) => s.isNotEmpty).join(' · '),
                      style: AppText.bodyMd
                          .copyWith(color: AppColors.onSurfaceVariant),
                    ),
                    if (exam['opens_at'] != null || exam['closes_at'] != null)
                      Text(
                        'Opens ${Dates.dayAndTime(Dates.tryParse(exam['opens_at']))}'
                        ' · closes ${Dates.dayAndTime(Dates.tryParse(exam['closes_at']))}',
                        style: AppText.bodyMd
                            .copyWith(color: AppColors.onSurfaceVariant),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              if (isDraft)
                FilledButton.icon(
                  onPressed: _busy || rows.isEmpty ? null : _publish,
                  icon: const Icon(Icons.publish_outlined),
                  label: Text(rows.isEmpty
                      ? 'Add questions to publish'
                      : 'Publish exam'),
                ),
              if (isPublished)
                OutlinedButton.icon(
                  onPressed: _busy ? null : _close,
                  icon: const Icon(Icons.lock_outline),
                  label: const Text('Close exam'),
                ),
              if (attempts > 0 || !isDraft) ...[
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton.icon(
                  onPressed: () => Navigator.of(context).push<void>(
                    MaterialPageRoute(
                      builder: (_) => CbtResultsScreen(examId: widget.examId),
                    ),
                  ),
                  icon: const Icon(Icons.assessment_outlined),
                  label: Text('Results and marking ($attempts sat)'),
                ),
              ],
              if (!isDraft && exam['show_results_immediately'] != true) ...[
                const SizedBox(height: AppSpacing.sm),
                if (exam['results_released_at'] == null)
                  FilledButton.icon(
                    onPressed: _busy ? null : _release,
                    icon: const Icon(Icons.campaign_outlined),
                    label: const Text('Release results to students and parents'),
                  )
                else
                  OutlinedButton.icon(
                    onPressed: _busy ? null : _withdraw,
                    icon: const Icon(Icons.undo),
                    label: const Text('Results released · withdraw'),
                  ),
              ],
              SectionHeader(
                'Questions (${rows.length})',
                action: isDraft ? 'Add from bank' : null,
                onAction: isDraft && !_busy
                    ? () => _addFromBank(exam, attached)
                    : null,
              ),
              if (rows.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: AppSpacing.md),
                  child: Text(
                    'No questions yet. Add them from the question bank — '
                    'questions saved from AI Studio are there too.',
                    style: AppText.bodyMd
                        .copyWith(color: AppColors.onSurfaceVariant),
                  ),
                ),
              for (var i = 0; i < rows.length; i++)
                _QuestionRow(
                  index: i + 1,
                  row: rows[i],
                  onRemove: isDraft && !_busy
                      ? () => _remove(intOf(rows[i]['question_id']))
                      : null,
                ),
              const SizedBox(height: AppSpacing.xl),
            ],
          );
        },
      ),
    );
  }
}

class _QuestionRow extends StatelessWidget {
  const _QuestionRow({required this.index, required this.row, this.onRemove});

  final int index;
  final Map<String, dynamic> row;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    final q = mapOf(row['question']);
    final marks = numOf(row['marks'] ?? q['marks'], 1);

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: OutlinedCard(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '$index. ${stringOf(q['question'])}',
                    style: AppText.bodyMd,
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    [
                      isHandMarked(q['question_type'])
                          ? 'Theory — you mark it'
                          : 'Marked automatically',
                      '$marks ${marks == 1 ? 'mark' : 'marks'}',
                      stringOf(q['topic']),
                    ].where((s) => s.isNotEmpty).join(' · '),
                    style: AppText.labelSm
                        .copyWith(color: AppColors.onSurfaceVariant),
                  ),
                ],
              ),
            ),
            if (onRemove != null)
              IconButton(
                tooltip: 'Remove from exam',
                icon: const Icon(Icons.remove_circle_outline),
                onPressed: onRemove,
              ),
          ],
        ),
      ),
    );
  }
}

/// The school's question bank for this subject, to tick and add to a paper.
/// A paper set for one class shows that class's questions first.
class QuestionBankPicker extends ConsumerStatefulWidget {
  const QuestionBankPicker({
    super.key,
    required this.examId,
    required this.subjectId,
    required this.alreadyOnPaper,
    this.classId,
  });

  final int examId;
  final int subjectId;
  final int? classId;
  final Set<int> alreadyOnPaper;

  @override
  ConsumerState<QuestionBankPicker> createState() => _QuestionBankPickerState();
}

class _QuestionBankPickerState extends ConsumerState<QuestionBankPicker> {
  final _search = TextEditingController();
  final _chosen = <int>{};
  bool _thisClassOnly = true;
  List<Map<String, dynamic>>? _questions;
  String? _error;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _questions = null;
      _error = null;
    });
    try {
      final data = await ref.read(apiClientProvider).get<dynamic>(
        Api.cbtQuestions,
        query: {
          if (widget.subjectId > 0) 'subject_id': widget.subjectId,
          if (widget.classId != null && _thisClassOnly) 'class_id': widget.classId,
          if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
          'status': 'approved',
          'per_page': 100,
        },
      );
      if (!mounted) return;
      setState(() {
        _questions = listOf(data)
            .where((q) => !widget.alreadyOnPaper.contains(intOf(q['id'])))
            .toList();
        // Ticks on questions no longer listed would be added unseen.
        _chosen.retainAll(_questions!.map((q) => intOf(q['id'])));
      });
    } catch (error) {
      if (mounted) setState(() => _error = asApiException(error).message);
    }
  }

  Future<void> _add() async {
    setState(() => _saving = true);
    try {
      await ref.read(apiClientProvider).post<dynamic>(
        Api.cbtExamQuestions(widget.examId),
        body: {
          'questions': [for (final id in _chosen) {'question_id': id}],
        },
      );
      if (mounted) Navigator.pop(context, _chosen.length);
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
    final questions = _questions;

    return Scaffold(
      appBar: AppBar(title: const Text('Question bank')),
      bottomNavigationBar: BottomActionBar(
        child: FilledButton(
          onPressed: _chosen.isEmpty || _saving ? null : _add,
          child: Text(_chosen.isEmpty
              ? 'Tick questions to add'
              : 'Add ${_chosen.length} to exam'),
        ),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            child: TextField(
              controller: _search,
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _load(),
              decoration: const InputDecoration(
                hintText: 'Search questions',
                prefixIcon: Icon(Icons.search),
                isDense: true,
              ),
            ),
          ),
          if (widget.classId != null)
            SwitchListTile(
              dense: true,
              title: const Text('This class’s questions only'),
              value: _thisClassOnly,
              onChanged: (on) {
                setState(() => _thisClassOnly = on);
                _load();
              },
            ),
          if (questions != null && questions.isNotEmpty)
            CheckboxListTile(
              dense: true,
              controlAffinity: ListTileControlAffinity.leading,
              title: Text('Select all (${questions.length})'),
              value: _chosen.length == questions.length
                  ? true
                  : _chosen.isEmpty
                      ? false
                      : null,
              tristate: true,
              onChanged: (_) => setState(() {
                _chosen.length == questions.length
                    ? _chosen.clear()
                    : _chosen.addAll(questions.map((q) => intOf(q['id'])));
              }),
            ),
          Expanded(
            child: _error != null
                ? EmptyState(
                    icon: Icons.error_outline,
                    title: 'Could not load the question bank',
                    message: _error!,
                  )
                : questions == null
                    ? const LoadingState(rows: 5)
                    : questions.isEmpty
                        ? const EmptyState(
                            icon: Icons.inventory_2_outlined,
                            title: 'No questions to add',
                            message: 'Draft some in AI Studio and save them to '
                                'the question bank, or add them on the web.',
                          )
                        : ListView.builder(
                            itemCount: questions.length,
                            itemBuilder: (context, i) {
                              final q = questions[i];
                              final id = intOf(q['id']);
                              return CheckboxListTile(
                                value: _chosen.contains(id),
                                onChanged: (on) => setState(() {
                                  on == true ? _chosen.add(id) : _chosen.remove(id);
                                }),
                                title: Text(
                                  stringOf(q['question']),
                                  maxLines: 3,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                subtitle: Text(
                                  [
                                    isHandMarked(q['question_type'])
                                        ? 'Theory'
                                        : 'Objective',
                                    '${numOf(q['marks'], 1)} mark(s)',
                                    stringOf(q['difficulty']),
                                    stringOf(q['topic']),
                                  ].where((s) => s.isNotEmpty).join(' · '),
                                ),
                              );
                            },
                          ),
          ),
        ],
      ),
    );
  }
}
