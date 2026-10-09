import 'package:dio/dio.dart' show FormData, MultipartFile;
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/widgets.dart';

/// What the server accepts on a submission (`HomeworkSubmissionController`).
const homeworkFileExtensions = [
  'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt',
  'jpg', 'jpeg', 'png', 'webp', 'heic',
  'ppt', 'pptx', 'mp3', 'm4a', 'mp4',
];

const _maxFiles = 5;

/// A file waiting to go up with the submission.
class _Attachment {
  const _Attachment(this.path, this.name, this.size);

  final String path;
  final String name;
  final int size;
}

/// Handing in one piece of homework: a typed answer, photos of the exercise
/// book, or files — whatever the teacher allowed. Marked work is read-only;
/// the server refuses changes once a teacher has marked it.
class HandInScreen extends ConsumerStatefulWidget {
  const HandInScreen({super.key, required this.work});

  final Map<String, dynamic> work;

  @override
  ConsumerState<HandInScreen> createState() => _HandInScreenState();
}

class _HandInScreenState extends ConsumerState<HandInScreen> {
  final _answer = TextEditingController();
  final _files = <_Attachment>[];
  bool _sending = false;
  String? _error;

  String get _type => stringOf(widget.work['submission_type'], 'text');
  bool get _takesText => _type != 'file';
  bool get _takesFiles => _type != 'text';
  bool get _marked => stringOf(widget.work['status']) == 'graded';

  @override
  void dispose() {
    _answer.dispose();
    super.dispose();
  }

  void _addFiles(Iterable<_Attachment> more) {
    setState(() {
      for (final f in more) {
        if (_files.length >= _maxFiles) break;
        _files.add(f);
      }
      _error = null;
    });
  }

  Future<void> _takePhoto() async {
    final photo = await ImagePicker().pickImage(
      source: ImageSource.camera,
      // A page of handwriting stays legible well below full resolution, and
      // these uploads travel on paid mobile data.
      maxWidth: 1800,
      imageQuality: 75,
    );
    if (photo == null) return;
    _addFiles([_Attachment(photo.path, photo.name, await photo.length())]);
  }

  Future<void> _chooseFiles() async {
    final result = await FilePicker.pickFiles(
      type: FileType.custom,
      allowedExtensions: homeworkFileExtensions,
      allowMultiple: true,
    );
    if (result == null) return;
    _addFiles([
      for (final f in result.files)
        if (f.path != null) _Attachment(f.path!, f.name, f.size),
    ]);
  }

  Future<void> _submit() async {
    final text = _answer.text.trim();
    if (_type == 'text' && text.isEmpty) {
      setState(() => _error = 'Type your answer first.');
      return;
    }
    if (_type == 'file' && _files.isEmpty) {
      setState(() => _error = 'Attach a photo or a file first.');
      return;
    }
    if (text.isEmpty && _files.isEmpty) {
      setState(() => _error = 'Type an answer or attach a photo or file.');
      return;
    }

    setState(() {
      _sending = true;
      _error = null;
    });

    try {
      final form = FormData.fromMap({
        if (text.isNotEmpty) 'body': text,
        for (var i = 0; i < _files.length; i++)
          'files[$i]': await MultipartFile.fromFile(_files[i].path, filename: _files[i].name),
      });
      await ref.read(apiClientProvider).post<dynamic>(
        Api.homeworkSubmit(intOf(widget.work['id'])),
        body: form,
        timeout: const Duration(minutes: 5),
      );
      if (mounted) Navigator.pop(context, true);
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
    final w = widget.work;
    final submitted = w['submitted'] == true;

    return Scaffold(
      appBar: AppBar(title: Text(stringOf(w['title'], 'Homework'))),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          Text(
            [
              stringOf(w['subject']),
              'due ${Dates.full(Dates.tryParse(w['due_date']))}',
              if (w['max_score'] != null) 'out of ${numOf(w['max_score']).toStringAsFixed(0)}',
            ].where((s) => s.isNotEmpty).join(' · '),
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: AppSpacing.md),
          OutlinedCard(child: Text(stringOf(w['description']), style: AppText.bodyMd)),
          if (submitted) ...[
            const SizedBox(height: AppSpacing.md),
            Text(
              _marked
                  ? 'Marked: ${numOf(w['marks_awarded']).toStringAsFixed(0)}'
                      '${w['marks_available'] != null ? '/${numOf(w['marks_available']).toStringAsFixed(0)}' : ''}'
                  : 'Handed in ${Dates.dayAndTime(Dates.tryParse(w['submitted_at']))}. '
                      'You can hand in again until it is marked.',
              style: AppText.bodyLg.copyWith(color: AppColors.present),
            ),
            if (stringOf(w['teacher_feedback']).isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('Teacher: ${stringOf(w['teacher_feedback'])}', style: AppText.bodyMd),
              ),
          ],
          if (!_marked) ...[
            const SizedBox(height: AppSpacing.lg),
            if (_takesText)
              TextField(
                controller: _answer,
                minLines: 5,
                maxLines: 15,
                maxLength: 20000,
                decoration: InputDecoration(
                  labelText: _type == 'text' ? 'Your answer' : 'Your answer (optional if you attach work)',
                  alignLabelWithHint: true,
                ),
              ),
            if (_takesFiles) ...[
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: _sending || _files.length >= _maxFiles ? null : _takePhoto,
                      icon: const Icon(Icons.photo_camera_outlined),
                      label: const Text('Take photo'),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: _sending || _files.length >= _maxFiles ? null : _chooseFiles,
                      icon: const Icon(Icons.attach_file),
                      label: const Text('Choose file'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                'Up to $_maxFiles photos or files — photograph each page of your exercise book.',
                style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
              ),
              for (final f in _files)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.insert_drive_file_outlined),
                  title: Text(f.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                  subtitle: Text('${(f.size / 1024).toStringAsFixed(0)} KB'),
                  trailing: IconButton(
                    tooltip: 'Remove',
                    icon: const Icon(Icons.close),
                    onPressed: _sending ? null : () => setState(() => _files.remove(f)),
                  ),
                ),
            ],
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.sm),
                child: Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
              ),
            const SizedBox(height: AppSpacing.lg),
            FilledButton.icon(
              onPressed: _sending ? null : _submit,
              icon: _sending
                  ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.send),
              label: Text(_sending ? 'Handing in…' : submitted ? 'Hand in again' : 'Hand in'),
            ),
          ],
        ],
      ),
    );
  }
}
