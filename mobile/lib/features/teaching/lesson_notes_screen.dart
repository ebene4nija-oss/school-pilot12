import 'package:dio/dio.dart' show FormData, MultipartFile;
import 'package:file_picker/file_picker.dart';
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

/// The lesson notes this teacher has shared, newest first.
final myLessonNotesProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final data = await api.get<dynamic>(Api.learningMaterials, query: {'mine': 1});
  return listOf(data, ['materials']);
});

({String label, Color color}) _noteStatus(Object? status) =>
    switch (stringOf(status)) {
      'published' => (label: 'Shared', color: AppColors.present),
      'pending_approval' => (label: 'Awaiting approval', color: AppColors.pending),
      'rejected' => (label: 'Sent back', color: AppColors.absent),
      'archived' => (label: 'Archived', color: AppColors.outline),
      _ => (label: 'Draft', color: AppColors.outline),
    };

/// Lesson notes from the phone: pick a file, say which class it is for.
///
/// The same library students read under Learn, through the same endpoint the
/// web uses — so a school that reviews materials first still does.
class LessonNotesScreen extends ConsumerWidget {
  const LessonNotesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notes = ref.watch(myLessonNotesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Lesson notes')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final message = await Navigator.of(context).push<String>(
            MaterialPageRoute(builder: (_) => const AddLessonNoteScreen()),
          );
          ref.invalidate(myLessonNotesProvider);
          if (message != null && context.mounted) {
            ScaffoldMessenger.of(context)
                .showSnackBar(SnackBar(content: Text(message)));
          }
        },
        icon: const Icon(Icons.upload_file),
        label: const Text('Add lesson note'),
      ),
      body: notes.when(
        loading: () => const LoadingState(rows: 4),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(myLessonNotesProvider),
        ),
        data: (list) {
          if (list.isEmpty) {
            return const EmptyState(
              icon: Icons.description_outlined,
              title: 'No lesson notes yet',
              message: 'Tap Add lesson note to share a PDF, Word or PowerPoint '
                  'file, or a photo of your notes, with a class.',
            );
          }

          return RefreshableList(
            onRefresh: () async => ref.refresh(myLessonNotesProvider.future),
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.screenMargin,
              AppSpacing.sm,
              AppSpacing.screenMargin,
              96,
            ),
            children: [
              for (final note in list)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: _NoteCard(note: note),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _NoteCard extends StatelessWidget {
  const _NoteCard({required this.note});

  final Map<String, dynamic> note;

  @override
  Widget build(BuildContext context) {
    final status = _noteStatus(note['status']);
    final reviewNote = stringOf(note['review_note']);

    return OutlinedCard(
      statusColor: status.color,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(stringOf(note['title'], 'Lesson note'), style: AppText.bodyLg),
              ),
              StatusChip(status.label, color: status.color),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            [
              stringOf(note['subject']),
              stringOf(note['class']),
              stringOf(note['topic']),
              if (Dates.tryParse(note['published_at']) != null)
                Dates.dayMonth(Dates.tryParse(note['published_at'])),
            ].where((s) => s.isNotEmpty).join(' · '),
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          if (reviewNote.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Reviewer: $reviewNote',
                style: AppText.labelSm.copyWith(color: AppColors.absent),
              ),
            ),
        ],
      ),
    );
  }
}

/// Exactly what `LearningMaterialController::TYPES` accepts, so a teacher
/// cannot pick a file the server will refuse after a long upload.
const _allowedExtensions = [
  'pdf',
  'doc', 'docx', 'odt', 'rtf', 'txt',
  'ppt', 'pptx', 'odp',
  'jpg', 'jpeg', 'png', 'gif', 'webp',
  'mp4', 'webm', '3gp', 'mov',
  'mp3', 'm4a', 'aac', 'ogg', 'wav',
];

class AddLessonNoteScreen extends ConsumerStatefulWidget {
  const AddLessonNoteScreen({super.key});

  @override
  ConsumerState<AddLessonNoteScreen> createState() => _AddLessonNoteScreenState();
}

class _AddLessonNoteScreenState extends ConsumerState<AddLessonNoteScreen> {
  final _title = TextEditingController();
  final _topic = TextEditingController();
  final _description = TextEditingController();
  int? _subjectId;
  int? _classId;
  PlatformFile? _file;
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _title.dispose();
    _topic.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final result = await FilePicker.pickFiles(
      type: FileType.custom,
      allowedExtensions: _allowedExtensions,
    );
    final file = result?.files.single;
    if (file == null || file.path == null) return;

    setState(() {
      _file = file;
      _error = null;
      // A sensible title from the file name, which the teacher can change.
      if (_title.text.trim().isEmpty) {
        final dot = file.name.lastIndexOf('.');
        _title.text = dot > 0 ? file.name.substring(0, dot) : file.name;
      }
    });
  }

  Future<void> _send() async {
    final file = _file;
    if (file == null || _title.text.trim().isEmpty || _classId == null) {
      setState(() => _error = 'Choose a file, give it a title and pick the class.');
      return;
    }

    setState(() {
      _sending = true;
      _error = null;
    });

    try {
      final termId = ref.read(selectedTermProvider);
      final form = FormData.fromMap({
        'title': _title.text.trim(),
        if (_description.text.trim().isNotEmpty) 'description': _description.text.trim(),
        if (_topic.text.trim().isNotEmpty) 'topic': _topic.text.trim(),
        if (_subjectId != null) 'subject_id': _subjectId,
        'class_id': _classId,
        if (termId != null) 'term_id': termId,
        'downloadable': 1,
        // Who sees it: the whole class.
        'targets[0][target_type]': 'class',
        'targets[0][class_id]': _classId,
        'file': await MultipartFile.fromFile(file.path!, filename: file.name),
      });

      final res = await ref.read(apiClientProvider).post<dynamic>(
        Api.learningMaterials,
        body: form,
        timeout: const Duration(minutes: 5),
      );
      if (!mounted) return;
      Navigator.pop(context, stringOf(mapOf(res)['message'], 'Lesson note shared.'));
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _sending = false;
        _error = asApiException(error).message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final subjects = ref.watch(schoolSubjectsProvider);
    final classes = ref.watch(classesProvider);
    ref.watch(termsProvider);
    final file = _file;

    return Scaffold(
      appBar: AppBar(title: const Text('Add lesson note')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          OutlinedCard(
            onTap: _sending ? null : _pick,
            child: Row(
              children: [
                Icon(
                  file == null ? Icons.upload_file : Icons.insert_drive_file_outlined,
                  color: AppColors.primaryContainer,
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(file == null ? 'Choose a file' : file.name, style: AppText.bodyLg),
                      Text(
                        file == null
                            ? 'PDF, Word, PowerPoint, or a photo of your notes'
                            : '${(file.size / (1024 * 1024)).toStringAsFixed(1)} MB · tap to change',
                        style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _title,
            decoration: const InputDecoration(labelText: 'Title'),
          ),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<int>(
            initialValue: _classId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Class'),
            items: [
              for (final c in classes.valueOrNull ?? const <ClassRef>[])
                DropdownMenuItem(value: c.id, child: Text(c.name)),
            ],
            onChanged: _sending ? null : (v) => setState(() => _classId = v),
          ),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<int>(
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
            onChanged: _sending ? null : (v) => setState(() => _subjectId = v),
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _topic,
            decoration: const InputDecoration(labelText: 'Topic (optional)'),
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _description,
            minLines: 2,
            maxLines: 5,
            decoration: const InputDecoration(labelText: 'Note to students (optional)'),
          ),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
          ],
          const SizedBox(height: AppSpacing.lg),
          FilledButton.icon(
            onPressed: _sending ? null : _send,
            icon: _sending
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.send),
            label: Text(_sending ? 'Uploading…' : 'Share with class'),
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'If your school reviews materials first, the note reaches students '
            'once it is approved.',
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
        ],
      ),
    );
  }
}
