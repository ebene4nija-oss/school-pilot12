import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/endpoints.dart';
import '../../../core/data/cached.dart';
import '../../../core/data/reference_data.dart';
import '../../../core/providers.dart';
import '../../../core/ui/cached_view.dart';
import '../../../core/ui/formatters.dart';
import '../../../core/ui/states.dart';
import '../../../core/ui/widgets.dart';
import 'cbt_exam_detail_screen.dart';
import 'cbt_teacher_data.dart';

/// A teacher's CBT papers: set one up, fill it from the question bank,
/// publish it, close it and mark it — the job the web CBT setup page does.
class CbtExamsScreen extends ConsumerWidget {
  const CbtExamsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final exams = ref.watch(teacherExamsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('CBT exams')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _create(context, ref),
        icon: const Icon(Icons.add),
        label: const Text('New exam'),
      ),
      body: exams.when(
        loading: () => const LoadingState(rows: 4),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(teacherExamsProvider),
        ),
        data: (list) {
          if (list.isEmpty) {
            return const EmptyState(
              icon: Icons.quiz_outlined,
              title: 'No CBT exams yet',
              message: 'Tap New exam to set one up. Questions come from the '
                  'question bank — AI Studio can draft them for you.',
            );
          }

          return RefreshableList(
            onRefresh: () async => ref.refresh(teacherExamsProvider.future),
            children: [
              for (final exam in list)
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.screenMargin,
                    AppSpacing.sm,
                    AppSpacing.screenMargin,
                    0,
                  ),
                  child: _ExamCard(exam: exam),
                ),
              const SizedBox(height: 88),
            ],
          );
        },
      ),
    );
  }

  Future<void> _create(BuildContext context, WidgetRef ref) async {
    final navigator = Navigator.of(context);
    final id = await navigator.push<int>(
      MaterialPageRoute(builder: (_) => const CreateExamScreen()),
    );
    ref.invalidate(teacherExamsProvider);
    if (id == null || id == 0) return;

    // Straight on to adding questions — a paper with none cannot be published.
    await navigator.push<void>(
      MaterialPageRoute(builder: (_) => CbtExamDetailScreen(examId: id)),
    );
    ref.invalidate(teacherExamsProvider);
  }
}

class _ExamCard extends ConsumerWidget {
  const _ExamCard({required this.exam});

  final Map<String, dynamic> exam;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = examStatus(exam['status']);
    final questions = intOf(exam['exam_questions_count']);
    final attempts = intOf(exam['attempts_count']);
    final opens = Dates.tryParse(exam['opens_at']);

    return OutlinedCard(
      statusColor: status.color,
      onTap: () async {
        await Navigator.of(context).push<void>(
          MaterialPageRoute(
            builder: (_) => CbtExamDetailScreen(examId: intOf(exam['id'])),
          ),
        );
        ref.invalidate(teacherExamsProvider);
      },
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  stringOf(exam['title'], 'Exam'),
                  style: AppText.bodyLg,
                ),
              ),
              StatusChip(status.label, color: status.color),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            [
              stringOf(nameOf(exam['subject'])),
              '${intOf(exam['duration_minutes'])} min',
              '$questions ${questions == 1 ? 'question' : 'questions'}',
              if (attempts > 0) '$attempts sat',
              if (opens != null) 'opens ${Dates.dayAndTime(opens)}',
            ].where((s) => s.isNotEmpty).join(' · '),
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
        ],
      ),
    );
  }
}

/// The paper's settings. Questions are added on the next screen.
class CreateExamScreen extends ConsumerStatefulWidget {
  const CreateExamScreen({super.key});

  @override
  ConsumerState<CreateExamScreen> createState() => _CreateExamScreenState();
}

class _CreateExamScreenState extends ConsumerState<CreateExamScreen> {
  final _title = TextEditingController();
  final _duration = TextEditingController(text: '40');
  final _instructions = TextEditingController();
  int? _subjectId;
  int? _classId;
  DateTime? _opensAt;
  DateTime? _closesAt;
  bool _saving = false;
  String? _error;

  // During the test, and what students see when they finish.
  bool _shuffleQuestions = true;
  bool _shuffleOptions = true;
  bool _showScore = true;
  bool _showAnswers = true;
  bool _showCorrect = false;

  @override
  void dispose() {
    _title.dispose();
    _duration.dispose();
    _instructions.dispose();
    super.dispose();
  }

  Widget _toggle(String label, bool value, void Function(bool) set) => SwitchListTile(
        contentPadding: EdgeInsets.zero,
        dense: true,
        title: Text(label),
        value: value,
        onChanged: _saving ? null : (v) => setState(() => set(v)),
      );

  Future<DateTime?> _pickDateTime(DateTime? initial) async {
    final now = DateTime.now();
    final date = await showDatePicker(
      context: context,
      initialDate: initial ?? now,
      firstDate: now.subtract(const Duration(days: 1)),
      lastDate: now.add(const Duration(days: 365)),
    );
    if (date == null || !mounted) return null;

    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(initial ?? now),
    );
    if (time == null) return null;

    return DateTime(date.year, date.month, date.day, time.hour, time.minute);
  }

  Future<void> _save() async {
    final duration = int.tryParse(_duration.text.trim()) ?? 0;

    if (_title.text.trim().isEmpty || _subjectId == null || duration < 1) {
      setState(() => _error = 'Give the exam a title, a subject and a duration.');
      return;
    }
    if (_opensAt != null && _closesAt != null && !_closesAt!.isAfter(_opensAt!)) {
      setState(() => _error = 'The closing time must be after the opening time.');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    try {
      final res = await ref.read(apiClientProvider).post<dynamic>(
        Api.cbtExams,
        body: {
          'title': _title.text.trim(),
          'subject_id': _subjectId,
          if (_classId != null) 'class_id': _classId,
          if (ref.read(selectedTermProvider) != null)
            'term_id': ref.read(selectedTermProvider),
          'duration_minutes': duration,
          if (_instructions.text.trim().isNotEmpty)
            'instructions': _instructions.text.trim(),
          if (_opensAt != null) 'opens_at': _opensAt!.toIso8601String(),
          if (_closesAt != null) 'closes_at': _closesAt!.toIso8601String(),
          'shuffle_questions': _shuffleQuestions,
          'shuffle_options': _shuffleOptions,
          'show_results_immediately': _showScore,
          'review_answers': _showAnswers,
          'review_correct_answers': _showCorrect,
        },
      );
      if (!mounted) return;
      Navigator.pop(context, intOf(fieldOf(mapOf(res)['exam'], 'id')));
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = asApiException(error).message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final subjects = ref.watch(classSubjectsProvider(_classId));
    final classes = ref.watch(classesProvider);
    // Loads the term so the paper is filed against it.
    ref.watch(termsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('New exam')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          TextField(
            controller: _title,
            decoration: const InputDecoration(
              labelText: 'Title',
              hintText: 'e.g. JSS 2 Mathematics — mid-term test',
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<int?>(
            initialValue: _classId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Class'),
            items: [
              const DropdownMenuItem<int?>(
                value: null,
                child: Text('Any class'),
              ),
              for (final c in classes.valueOrNull ?? const <ClassRef>[])
                DropdownMenuItem<int?>(value: c.id, child: Text(c.name)),
            ],
            // A new class has its own subjects; the old pick may not be one.
            onChanged: (v) => setState(() {
              _classId = v;
              _subjectId = null;
            }),
          ),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<int>(
            key: ValueKey('subject-$_classId'),
            initialValue: _subjectId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Subject'),
            items: [
              for (final s in subjects.valueOrNull ?? const <Map<String, dynamic>>[])
                DropdownMenuItem(
                  value: intOf(s['id']),
                  child: Text(stringOf(s['name'], 'Subject')),
                ),
            ],
            onChanged: (v) => setState(() => _subjectId = v),
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _duration,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Duration (minutes)'),
          ),
          const SizedBox(height: AppSpacing.md),
          _DateRow(
            label: 'Opens',
            value: _opensAt,
            onPick: () async {
              final picked = await _pickDateTime(_opensAt);
              if (picked != null) setState(() => _opensAt = picked);
            },
            onClear: () => setState(() => _opensAt = null),
          ),
          _DateRow(
            label: 'Closes',
            value: _closesAt,
            onPick: () async {
              final picked = await _pickDateTime(_closesAt ?? _opensAt);
              if (picked != null) setState(() => _closesAt = picked);
            },
            onClear: () => setState(() => _closesAt = null),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            controller: _instructions,
            minLines: 2,
            maxLines: 5,
            decoration: const InputDecoration(
              labelText: 'Instructions (optional)',
              hintText: 'Answer all questions.',
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          Text('During the test', style: AppText.labelLg),
          _toggle('Shuffle the questions for each student', _shuffleQuestions, (v) => _shuffleQuestions = v),
          _toggle('Shuffle the options', _shuffleOptions, (v) => _shuffleOptions = v),
          const SizedBox(height: AppSpacing.sm),
          Text('At the end, students see', style: AppText.labelLg),
          _toggle('Their score, as soon as they submit', _showScore, (v) => _showScore = v),
          _toggle('Their answers, marked right or wrong', _showAnswers, (v) => _showAnswers = v),
          _toggle('The correct options (after the test closes)', _showCorrect, (v) => _showCorrect = v),
          Text(
            'You can change these later on the web, even after publishing.',
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
          ],
          const SizedBox(height: AppSpacing.lg),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Create and add questions'),
          ),
        ],
      ),
    );
  }
}

class _DateRow extends StatelessWidget {
  const _DateRow({
    required this.label,
    required this.value,
    required this.onPick,
    required this.onClear,
  });

  final String label;
  final DateTime? value;
  final VoidCallback onPick;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: const Icon(Icons.event_outlined),
      title: Text(label),
      subtitle: Text(
        value == null ? 'Not set — open as soon as published' : Dates.dayAndTime(value),
      ),
      trailing: value == null
          ? const Icon(Icons.chevron_right)
          : IconButton(
              tooltip: 'Clear',
              icon: const Icon(Icons.close),
              onPressed: onClear,
            ),
      onTap: onPick,
    );
  }
}
