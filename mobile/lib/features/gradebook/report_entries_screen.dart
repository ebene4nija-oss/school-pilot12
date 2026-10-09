import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final reportEntriesProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, ({int classId, int termId})>((ref, args) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.reportEntries, query: {'class_id': args.classId, 'term_id': args.termId}));
});

/// Report-card ratings (affective traits, psychomotor skills) and the form
/// teacher's comment, one pupil at a time. The traits and the scale are the
/// school's own; the server says who may write what.
class ReportEntriesScreen extends ConsumerStatefulWidget {
  const ReportEntriesScreen({super.key});

  @override
  ConsumerState<ReportEntriesScreen> createState() => _ReportEntriesScreenState();
}

class _ReportEntriesScreenState extends ConsumerState<ReportEntriesScreen> {
  int? _classId;

  @override
  Widget build(BuildContext context) {
    final classes = ref.watch(classesProvider).valueOrNull ?? const <ClassRef>[];
    ref.watch(termsProvider);
    final termId = ref.watch(selectedTermProvider);
    final classId = _classId ?? (classes.isEmpty ? null : classes.first.id);

    return Scaffold(
      appBar: AppBar(title: const Text('Ratings and comments')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            child: DropdownButtonFormField<int>(
              initialValue: classId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Class', isDense: true),
              items: [for (final c in classes) DropdownMenuItem(value: c.id, child: Text(c.name))],
              onChanged: (v) => setState(() => _classId = v),
            ),
          ),
          Expanded(
            child: classId == null || termId == null
                ? const EmptyState(icon: Icons.class_outlined, title: 'Choose a class', message: 'Ratings are entered per class for the current term.')
                : _Entries(args: (classId: classId, termId: termId)),
          ),
        ],
      ),
    );
  }
}

class _Entries extends ConsumerWidget {
  const _Entries({required this.args});

  final ({int classId, int termId}) args;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final data = ref.watch(reportEntriesProvider(args));

    return data.when(
      loading: () => const LoadingState(rows: 5),
      error: (error, _) => ErrorState(error: asApiException(error), onRetry: () => ref.invalidate(reportEntriesProvider(args))),
      data: (d) {
        final students = listOf(d['students']);
        if (students.isEmpty) {
          return const EmptyState(icon: Icons.groups_outlined, title: 'No students', message: 'This class has no students enrolled.');
        }
        return ListView.builder(
          padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, 0, AppSpacing.screenMargin, AppSpacing.xl),
          itemCount: students.length,
          itemBuilder: (context, i) {
            final s = students[i];
            final rated = mapOf(s['ratings']).length;
            final commented = stringOf(s['form_teacher_comment']).isNotEmpty;
            return ListTile(
              contentPadding: EdgeInsets.zero,
              leading: InitialsAvatar(stringOf(s['name'], '?')),
              title: Text(stringOf(s['name'], 'Student')),
              subtitle: Text('$rated rated${commented ? ' · comment written' : ''}'),
              trailing: Icon(
                rated > 0 && commented ? Icons.check_circle : Icons.chevron_right,
                color: rated > 0 && commented ? AppColors.present : AppColors.outline,
              ),
              onTap: () async {
                final saved = await Navigator.of(context).push<bool>(
                  MaterialPageRoute(
                    builder: (_) => PupilEntryScreen(
                      args: args,
                      settings: mapOf(d['settings']),
                      student: s,
                      canWriteForm: d['can_write_form'] == true,
                      canWritePrincipal: d['can_write_principal'] == true,
                    ),
                  ),
                );
                if (saved == true) ref.invalidate(reportEntriesProvider(args));
              },
            );
          },
        );
      },
    );
  }
}

class PupilEntryScreen extends ConsumerStatefulWidget {
  const PupilEntryScreen({
    super.key,
    required this.args,
    required this.settings,
    required this.student,
    required this.canWriteForm,
    required this.canWritePrincipal,
  });

  final ({int classId, int termId}) args;
  final Map<String, dynamic> settings;
  final Map<String, dynamic> student;
  final bool canWriteForm;
  final bool canWritePrincipal;

  @override
  ConsumerState<PupilEntryScreen> createState() => _PupilEntryScreenState();
}

class _PupilEntryScreenState extends ConsumerState<PupilEntryScreen> {
  late final Map<String, String> _ratings = {
    for (final e in mapOf(widget.student['ratings']).entries) e.key: stringOf(e.value),
  };
  late final _formComment = TextEditingController(text: stringOf(widget.student['form_teacher_comment']));
  late final _principalComment = TextEditingController(text: stringOf(widget.student['principal_comment']));
  bool _saving = false;

  @override
  void dispose() {
    _formComment.dispose();
    _principalComment.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      await ref.read(apiClientProvider).put<dynamic>(Api.reportEntries, body: {
        'class_id': widget.args.classId,
        'term_id': widget.args.termId,
        'entries': [
          {
            'student_id': intOf(widget.student['student_id']),
            'ratings': _ratings,
            if (widget.canWriteForm && widget.settings['form_teacher_comment'] == true)
              'form_teacher_comment': _formComment.text.trim(),
            if (widget.canWritePrincipal && widget.settings['principal_comment'] == true)
              'principal_comment': _principalComment.text.trim(),
          },
        ],
      });
      if (mounted) Navigator.pop(context, true);
    } catch (error) {
      if (!mounted) return;
      setState(() => _saving = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(asApiException(error).message)));
    }
  }

  Widget _group(String title, List<String> items, List<Map<String, dynamic>> scale) {
    if (items.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(title),
        for (final item in items) ...[
          Text(item, style: AppText.bodyMd),
          const SizedBox(height: 4),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final option in scale)
                ChoiceChip(
                  label: Text(stringOf(option['label'], stringOf(option['value']))),
                  selected: _ratings[item] == stringOf(option['value']),
                  showCheckmark: false,
                  selectedColor: AppColors.primaryContainer,
                  labelStyle: AppText.labelSm.copyWith(
                    color: _ratings[item] == stringOf(option['value']) ? AppColors.onPrimary : AppColors.onSurface,
                  ),
                  onSelected: widget.canWriteForm
                      ? (on) => setState(() => on ? _ratings[item] = stringOf(option['value']) : _ratings.remove(item))
                      : null,
                ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
        ],
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final scale = listOf(widget.settings['rating_scale']);
    List<String> names(Object? v) => v is List ? v.map(stringOf).toList() : const [];

    return Scaffold(
      appBar: AppBar(title: Text(stringOf(widget.student['name'], 'Student'))),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          _group('Behaviour', names(widget.settings['affective_traits']), scale),
          _group('Skills', names(widget.settings['psychomotor_skills']), scale),
          if (widget.settings['form_teacher_comment'] == true) ...[
            const SectionHeader("Form teacher's comment"),
            TextField(
              controller: _formComment,
              enabled: widget.canWriteForm,
              minLines: 2,
              maxLines: 5,
              maxLength: 1000,
              decoration: const InputDecoration(hintText: 'e.g. A hardworking pupil who participates well in class.'),
            ),
          ],
          if (widget.settings['principal_comment'] == true && widget.canWritePrincipal) ...[
            SectionHeader("${stringOf(widget.settings['principal_title'], 'Principal')}'s comment"),
            TextField(controller: _principalComment, minLines: 2, maxLines: 5, maxLength: 1000),
          ],
          const SizedBox(height: AppSpacing.md),
          FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Saving…' : 'Save')),
        ],
      ),
    );
  }
}
