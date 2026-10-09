import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../cbt/teacher/cbt_teacher_data.dart';

/// The homework this teacher has set, recent and upcoming.
final teacherHomeworkProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final data = await api.get<dynamic>(Api.homework, query: {'include_past': 1});
  return listOf(data, ['homework'])
    ..sort((a, b) => stringOf(b['due_date']).compareTo(stringOf(a['due_date'])));
});

final submissionsProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, int>((ref, homeworkId) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.homeworkSubmissions(homeworkId)));
});

/// A teacher's homework: set it, see who handed in, mark it.
class TeacherHomeworkScreen extends ConsumerWidget {
  const TeacherHomeworkScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final homework = ref.watch(teacherHomeworkProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Homework')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final message = await Navigator.of(context).push<String>(
            MaterialPageRoute(builder: (_) => const SetHomeworkScreen()),
          );
          ref.invalidate(teacherHomeworkProvider);
          if (message != null && context.mounted) {
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
          }
        },
        icon: const Icon(Icons.add),
        label: const Text('Set homework'),
      ),
      body: homework.when(
        loading: () => const LoadingState(rows: 4),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(teacherHomeworkProvider),
        ),
        data: (list) {
          if (list.isEmpty) {
            return const EmptyState(
              icon: Icons.assignment_outlined,
              title: 'No homework set',
              message: 'Tap Set homework. Students are told as soon as it is published.',
            );
          }
          final today = Dates.apiDate(DateTime.now());

          return RefreshableList(
            onRefresh: () async => ref.refresh(teacherHomeworkProvider.future),
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, 96),
            children: [
              for (final h in list)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: OutlinedCard(
                    statusColor: stringOf(h['due_date']).compareTo(today) >= 0 ? AppColors.pending : AppColors.outline,
                    onTap: () => Navigator.of(context).push<void>(
                      MaterialPageRoute(
                        builder: (_) => SubmissionsScreen(
                          homeworkId: intOf(h['id']),
                          title: stringOf(h['title'], 'Homework'),
                          maxScore: h['max_score'] == null ? null : numOf(h['max_score']),
                        ),
                      ),
                    ),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(stringOf(h['title'], 'Homework'), style: AppText.bodyLg),
                              Text(
                                [
                                  stringOf(h['class_name']),
                                  stringOf(h['subject']),
                                  'due ${Dates.dayMonth(Dates.tryParse(h['due_date']))}',
                                ].where((s) => s.isNotEmpty).join(' · '),
                                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                              ),
                            ],
                          ),
                        ),
                        if (stringOf(h['publish_status']) == 'draft')
                          const StatusChip('Draft', color: AppColors.outline),
                        const Icon(Icons.chevron_right, color: AppColors.outline),
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

const _submissionTypes = <String, String>{
  'text_or_file': 'Typed answer or photo/file',
  'text': 'Typed answer only',
  'file': 'Photo or file only',
};

class SetHomeworkScreen extends ConsumerStatefulWidget {
  const SetHomeworkScreen({super.key});

  @override
  ConsumerState<SetHomeworkScreen> createState() => _SetHomeworkScreenState();
}

class _SetHomeworkScreenState extends ConsumerState<SetHomeworkScreen> {
  final _title = TextEditingController();
  final _instructions = TextEditingController();
  final _maxScore = TextEditingController(text: '10');
  int? _classId;
  int? _subjectId;
  DateTime _due = DateTime.now().add(const Duration(days: 2));
  String _type = 'text_or_file';
  bool _allowLate = true;
  bool _saving = false;
  bool _drafting = false;
  String? _error;

  @override
  void dispose() {
    _title.dispose();
    _instructions.dispose();
    _maxScore.dispose();
    super.dispose();
  }

  String _subjectName(List<Map<String, dynamic>> subjects) => stringOf(
        subjects.where((s) => intOf(s['id']) == _subjectId).map((s) => s['name']).firstOrNull,
      );

  /// Asks AI Studio for ideas on the topic typed in the title, and fills the
  /// form from the one the teacher picks. They still read and edit it.
  Future<void> _ideas(List<Map<String, dynamic>> subjects, List<ClassRef> classes) async {
    final subject = _subjectName(subjects);
    if (subject.isEmpty || _title.text.trim().isEmpty) {
      setState(() => _error = 'Pick the subject and type the topic as the title, then ask for ideas.');
      return;
    }
    setState(() {
      _drafting = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.aiHomeworkIdeas,
        body: {
          'subject': subject,
          'topic': _title.text.trim(),
          if (_classId != null) 'class_level': classes.firstWhere((c) => c.id == _classId).name,
        },
        timeout: const Duration(seconds: 90),
      ));
      final ideas = listOf(mapOf(res, ['homework_ideas'])['ideas']);
      if (!mounted) return;
      setState(() => _drafting = false);
      if (ideas.isEmpty) {
        setState(() => _error = 'No ideas came back. Try a more specific topic.');
        return;
      }
      final pick = await showModalBottomSheet<Map<String, dynamic>>(
        context: context,
        isScrollControlled: true,
        builder: (sheet) => SafeArea(
          child: ListView(
            shrinkWrap: true,
            padding: const EdgeInsets.all(AppSpacing.md),
            children: [
              Text('Pick an idea to start from', style: AppText.headlineSm),
              for (final idea in ideas)
                ListTile(
                  title: Text(stringOf(idea['title'], 'Idea')),
                  subtitle: Text(stringOf(idea['instructions']), maxLines: 3, overflow: TextOverflow.ellipsis),
                  onTap: () => Navigator.pop(sheet, idea),
                ),
            ],
          ),
        ),
      );
      if (pick == null) return;
      setState(() {
        _title.text = stringOf(pick['title'], _title.text);
        _instructions.text = stringOf(pick['instructions']);
        if (intOf(pick['marks']) > 0) _maxScore.text = '${intOf(pick['marks'])}';
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _drafting = false;
        _error = asApiException(error).message;
      });
    }
  }

  Future<void> _save({required bool draft}) async {
    if (_classId == null || _subjectId == null || _title.text.trim().isEmpty || _instructions.text.trim().isEmpty) {
      setState(() => _error = 'Class, subject, title and instructions are all needed.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.homework,
        body: {
          'class_id': _classId,
          'subject_id': _subjectId,
          'title': _title.text.trim(),
          'description': _instructions.text.trim(),
          'due_date': Dates.apiDate(_due),
          if (num.tryParse(_maxScore.text.trim()) != null) 'max_score': num.parse(_maxScore.text.trim()),
          'submission_type': _type,
          'allow_late': _allowLate,
          'draft': draft,
        },
      ));
      if (mounted) Navigator.pop(context, stringOf(res['message'], 'Homework set.'));
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
    final classes = ref.watch(classesProvider).valueOrNull ?? const <ClassRef>[];
    final subjects = ref.watch(classSubjectsProvider(_classId)).valueOrNull ?? const <Map<String, dynamic>>[];

    return Scaffold(
      appBar: AppBar(title: const Text('Set homework')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          DropdownButtonFormField<int>(
            initialValue: _classId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Class'),
            items: [for (final c in classes) DropdownMenuItem(value: c.id, child: Text(c.name))],
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
              for (final s in subjects)
                DropdownMenuItem(value: intOf(s['id']), child: Text(stringOf(s['name'], 'Subject'))),
            ],
            onChanged: (v) => setState(() => _subjectId = v),
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _title,
            decoration: const InputDecoration(labelText: 'Title or topic', hintText: 'e.g. Simple interest'),
          ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: _drafting || _saving ? null : () => _ideas(subjects, classes),
              icon: _drafting
                  ? const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.auto_awesome_outlined),
              label: Text(_drafting ? 'Getting ideas…' : 'Get ideas from AI'),
            ),
          ),
          TextField(
            controller: _instructions,
            minLines: 4,
            maxLines: 10,
            decoration: const InputDecoration(labelText: 'Instructions for students', alignLabelWithHint: true),
          ),
          const SizedBox(height: AppSpacing.md),
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.event_outlined),
            title: const Text('Due'),
            subtitle: Text(Dates.full(_due)),
            onTap: () async {
              final picked = await showDatePicker(
                context: context,
                initialDate: _due,
                firstDate: DateTime.now(),
                lastDate: DateTime.now().add(const Duration(days: 180)),
              );
              if (picked != null) setState(() => _due = picked);
            },
          ),
          TextField(
            controller: _maxScore,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Marked out of'),
          ),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<String>(
            initialValue: _type,
            decoration: const InputDecoration(labelText: 'Students hand in'),
            items: [
              for (final e in _submissionTypes.entries) DropdownMenuItem(value: e.key, child: Text(e.value)),
            ],
            onChanged: (v) => setState(() => _type = v ?? 'text_or_file'),
          ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Accept late work'),
            value: _allowLate,
            onChanged: (v) => setState(() => _allowLate = v),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            ),
          FilledButton.icon(
            onPressed: _saving ? null : () => _save(draft: false),
            icon: const Icon(Icons.send),
            label: const Text('Set and tell students'),
          ),
          const SizedBox(height: AppSpacing.sm),
          OutlinedButton(
            onPressed: _saving ? null : () => _save(draft: true),
            child: const Text('Save as draft'),
          ),
          const SizedBox(height: AppSpacing.xl),
        ],
      ),
    );
  }
}

/// Who handed in, and marking. Marks are entered per student and saved
/// together through the bulk-grade endpoint.
class SubmissionsScreen extends ConsumerStatefulWidget {
  const SubmissionsScreen({super.key, required this.homeworkId, required this.title, this.maxScore});

  final int homeworkId;
  final String title;
  final num? maxScore;

  @override
  ConsumerState<SubmissionsScreen> createState() => _SubmissionsScreenState();
}

class _SubmissionsScreenState extends ConsumerState<SubmissionsScreen> {
  late final _outOf = TextEditingController(text: widget.maxScore == null ? '10' : _trim(widget.maxScore!));
  final _marks = <int, TextEditingController>{};
  final _feedback = <int, TextEditingController>{};
  bool _saving = false;

  static String _trim(num n) => n == n.roundToDouble() ? '${n.toInt()}' : '$n';

  @override
  void dispose() {
    _outOf.dispose();
    for (final c in [..._marks.values, ..._feedback.values]) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _markFor(Map<String, dynamic> s) => _marks.putIfAbsent(
        intOf(s['submission_id']),
        () => TextEditingController(text: s['marks_awarded'] == null ? '' : _trim(numOf(s['marks_awarded']))),
      );

  TextEditingController _feedbackFor(Map<String, dynamic> s) =>
      _feedback.putIfAbsent(intOf(s['submission_id']), () => TextEditingController());

  Future<void> _save(List<Map<String, dynamic>> handedIn) async {
    final outOf = num.tryParse(_outOf.text.trim());
    if (outOf == null || outOf < 1) {
      _say('Enter what the work is marked out of.');
      return;
    }
    final rows = <Map<String, dynamic>>[];
    for (final s in handedIn) {
      final text = _markFor(s).text.trim();
      if (text.isEmpty) continue;
      final mark = num.tryParse(text);
      if (mark == null || mark < 0 || mark > outOf) {
        _say('${stringOf(s['student_name'])}: a mark must be between 0 and ${_trim(outOf)}.');
        return;
      }
      rows.add({
        'submission_id': intOf(s['submission_id']),
        'marks_awarded': mark,
        if (_feedbackFor(s).text.trim().isNotEmpty) 'teacher_feedback': _feedbackFor(s).text.trim(),
      });
    }
    if (rows.isEmpty) {
      _say('Enter at least one mark.');
      return;
    }
    setState(() => _saving = true);
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.bulkGradeHomework(widget.homeworkId),
        body: {'marks_available': outOf, 'marks': rows},
      ));
      ref.invalidate(submissionsProvider(widget.homeworkId));
      _say(stringOf(res['message'], 'Marks saved.'));
    } catch (error) {
      _say(asApiException(error).message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _say(String text) {
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(submissionsProvider(widget.homeworkId));

    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: data.when(
        loading: () => const LoadingState(rows: 5),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(submissionsProvider(widget.homeworkId)),
        ),
        data: (d) {
          final summary = mapOf(d['summary']);
          final rows = listOf(d['submissions']);
          final handedIn = rows.where((r) => r['submitted'] == true).toList();
          final notYet = rows.where((r) => r['submitted'] != true).toList();

          return Column(
            children: [
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.all(AppSpacing.screenMargin),
                  children: [
                    Row(
                      children: [
                        Expanded(child: StatTile(label: 'Handed in', value: '${intOf(summary['submitted'])}/${intOf(summary['class_size'])}')),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(child: StatTile(label: 'Late', value: '${intOf(summary['late'])}')),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(child: StatTile(label: 'Marked', value: '${intOf(summary['marked'])}')),
                      ],
                    ),
                    if (handedIn.isNotEmpty) ...[
                      const SizedBox(height: AppSpacing.md),
                      TextField(
                        controller: _outOf,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(labelText: 'Marked out of', isDense: true),
                      ),
                    ],
                    SectionHeader('Handed in (${handedIn.length})'),
                    for (final s in handedIn)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: OutlinedCard(
                          statusColor: s['status'] == 'graded' ? AppColors.present : s['is_late'] == true ? AppColors.late : AppColors.pending,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(stringOf(s['student_name'], 'Student'), style: AppText.bodyLg),
                              Text(
                                [
                                  Dates.dayAndTime(Dates.tryParse(s['submitted_at'])),
                                  if (s['is_late'] == true) 'late',
                                  if (s['parent_assisted'] == true) 'sent by parent',
                                  if (listOf(s['files']).isNotEmpty) '${listOf(s['files']).length} file(s)',
                                ].join(' · '),
                                style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
                              ),
                              if (stringOf(s['body']).isNotEmpty)
                                Padding(
                                  padding: const EdgeInsets.only(top: AppSpacing.sm),
                                  child: Text(stringOf(s['body']), style: AppText.bodyMd),
                                ),
                              for (final f in listOf(s['files']))
                                Text('📎 ${stringOf(f['name'])}', style: AppText.labelSm),
                              const SizedBox(height: AppSpacing.sm),
                              Row(
                                children: [
                                  SizedBox(
                                    width: 90,
                                    child: TextField(
                                      controller: _markFor(s),
                                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                      decoration: const InputDecoration(labelText: 'Mark', isDense: true),
                                    ),
                                  ),
                                  const SizedBox(width: AppSpacing.sm),
                                  Expanded(
                                    child: TextField(
                                      controller: _feedbackFor(s),
                                      decoration: const InputDecoration(labelText: 'Feedback (optional)', isDense: true),
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ),
                    SectionHeader('Not handed in (${notYet.length})'),
                    if (notYet.isEmpty)
                      Text('Everyone has handed in.', style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant))
                    else
                      Text(
                        notYet.map((s) => stringOf(s['student_name'])).join(', '),
                        style: AppText.bodyMd,
                      ),
                    const SizedBox(height: AppSpacing.xl),
                  ],
                ),
              ),
              if (handedIn.isNotEmpty)
                BottomActionBar(
                  child: FilledButton(
                    onPressed: _saving ? null : () => _save(handedIn),
                    child: Text(_saving ? 'Saving…' : 'Save marks'),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
