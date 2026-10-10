import 'dart:typed_data';

import 'package:dio/dio.dart' show FormData, MultipartFile;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/widgets.dart';
import '../cbt/teacher/cbt_teacher_data.dart';

/// AI with the phone camera, for the question bank:
///  - photograph a diagram, map or graph and have AI write questions on it;
///  - photograph a printed paper and have AI read it into questions.
///
/// Everything comes back as a draft. The teacher ticks what to keep and, for
/// a scanned objective question with no key on the paper, picks the answer;
/// saving then goes through the bank's own checks.
class AiPicturesScreen extends ConsumerStatefulWidget {
  const AiPicturesScreen({super.key});

  @override
  ConsumerState<AiPicturesScreen> createState() => _AiPicturesScreenState();
}

class _Page {
  _Page(this.bytes, this.name);
  final Uint8List bytes;
  final String name;
}

class _AiPicturesScreenState extends ConsumerState<AiPicturesScreen> {
  bool _scan = false;
  int? _classId;
  int? _subjectId;
  final _pages = <_Page>[];
  final _alt = TextEditingController();
  bool _busy = false;
  String? _status;
  String? _error;
  List<Map<String, dynamic>>? _drafts;
  final _keep = <int>{};

  @override
  void dispose() {
    _alt.dispose();
    super.dispose();
  }

  String _subjectName(List<Map<String, dynamic>> subjects) =>
      stringOf(subjects.where((s) => intOf(s['id']) == _subjectId).map((s) => s['name']).firstOrNull);

  String _className(List<ClassRef> classes) =>
      classes.where((c) => c.id == _classId).map((c) => c.name).firstOrNull ?? '';

  Future<void> _addPhoto(ImageSource source) async {
    final shot = await ImagePicker().pickImage(source: source, maxWidth: 2000, imageQuality: 85);
    if (shot == null) return;
    final bytes = await shot.readAsBytes();
    setState(() {
      if (!_scan) _pages.clear();
      if (_pages.length < 6) _pages.add(_Page(bytes, shot.name.isEmpty ? 'photo.jpg' : shot.name));
      _drafts = null;
      _error = null;
    });
    if (!_scan) await _describe();
  }

  /// AI suggests the alt text a picture needs before it can join the library.
  Future<void> _describe() async {
    if (_pages.isEmpty) return;
    setState(() => _status = 'Reading the picture…');
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
            Api.aiDescribeImage,
            body: FormData.fromMap({'image': MultipartFile.fromBytes(_pages.first.bytes, filename: _pages.first.name)}),
          ));
      if (mounted && stringOf(res['alt_text']).isNotEmpty) _alt.text = stringOf(res['alt_text']);
    } catch (_) {
      // A suggestion only: the teacher can type the description.
    } finally {
      if (mounted) setState(() => _status = null);
    }
  }

  Future<void> _generate(List<Map<String, dynamic>> subjects, List<ClassRef> classes) async {
    if (_classId == null || _subjectId == null || _pages.isEmpty) {
      setState(() => _error = 'Choose the class and subject, and take a photo first.');
      return;
    }
    if (!_scan && _alt.text.trim().length < 3) {
      setState(() => _error = 'Describe the picture in a few words (it is read to students using a screen reader).');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _drafts = null;
    });
    final api = ref.read(apiClientProvider);
    try {
      Map<String, dynamic> res;
      if (_scan) {
        setState(() => _status = 'Reading ${_pages.length} page(s)…');
        res = mapOf(await api.post<dynamic>(
          Api.aiScanPaper,
          body: FormData.fromMap({
            'subject': _subjectName(subjects),
            'class_level': _className(classes),
            'pages[]': [for (final p in _pages) MultipartFile.fromBytes(p.bytes, filename: p.name)],
          }),
          timeout: const Duration(minutes: 3),
        ));
      } else {
        setState(() => _status = 'Adding the picture to the library…');
        final media = mapOf(mapOf(await api.post<dynamic>(
          Api.cbtMedia,
          body: FormData.fromMap({
            'file': MultipartFile.fromBytes(_pages.first.bytes, filename: _pages.first.name),
            'alt_text': _alt.text.trim(),
          }),
        ))['asset']);
        setState(() => _status = 'Writing questions…');
        res = mapOf(await api.post<dynamic>(
          Api.aiQuestionsFromImage,
          body: {
            'asset_id': intOf(media['asset_id'] ?? media['id']),
            'subject': _subjectName(subjects),
            'class_level': _className(classes),
            'count': 5,
          },
          timeout: const Duration(minutes: 2),
        ));
      }
      final drafts = listOf(res['questions']);
      setState(() {
        _drafts = drafts;
        _keep
          ..clear()
          ..addAll(List.generate(drafts.length, (i) => i));
        final why = stringOf(res['reason'] ?? res['unreadable']);
        if (drafts.isEmpty) _error = why.isNotEmpty ? why : 'No questions came back. Try a clearer photo.';
      });
    } catch (error) {
      setState(() => _error = asApiException(error).message);
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _status = null;
        });
      }
    }
  }

  bool _needsKey(Map<String, dynamic> q) {
    final options = q['options'];
    return stringOf(q['question_type']) != 'theory' && (options is! Map || !options.containsKey(q['correct_answer']));
  }

  Future<void> _save() async {
    final drafts = _drafts ?? const [];
    final chosen = [for (final i in _keep.toList()..sort()) drafts[i]];
    if (chosen.any(_needsKey)) {
      setState(() => _error = 'Pick the correct answer for every ticked objective question.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    var saved = 0;
    try {
      for (final q in chosen) {
        final theory = stringOf(q['question_type']) == 'theory';
        await ref.read(apiClientProvider).post<dynamic>(Api.cbtQuestions, body: {
          'subject_id': _subjectId,
          'class_id': _classId,
          'question': stringOf(q['question']),
          'question_type': theory ? 'theory' : 'multiple_choice',
          'options': theory ? null : q['options'],
          'correct_answer': theory ? null : q['correct_answer'],
          if (theory) 'answer_schema': const <dynamic>[],
          'topic': q['topic'],
          'difficulty': q['difficulty'] ?? 'medium',
          'marks': 1,
          if (q['media'] != null) 'media': q['media'],
        });
        saved++;
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Saved $saved question(s) to the bank.')));
      setState(() => _drafts = null);
    } catch (error) {
      setState(() => _error = 'Saved $saved; the next was refused: ${asApiException(error).message}');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final classes = ref.watch(classesProvider).valueOrNull ?? const <ClassRef>[];
    final subjects = ref.watch(classSubjectsProvider(_classId)).valueOrNull ?? const <Map<String, dynamic>>[];
    final drafts = _drafts;

    return Scaffold(
      appBar: AppBar(title: const Text('From a picture or paper')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(value: false, icon: Icon(Icons.image_outlined), label: Text('Picture → questions')),
              ButtonSegment(value: true, icon: Icon(Icons.document_scanner_outlined), label: Text('Scan a paper')),
            ],
            selected: {_scan},
            onSelectionChanged: _busy
                ? null
                : (v) => setState(() {
                      _scan = v.first;
                      _pages.clear();
                      _drafts = null;
                      _alt.clear();
                    }),
          ),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<int>(
            initialValue: _classId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Class'),
            items: [for (final c in classes) DropdownMenuItem(value: c.id, child: Text(c.name))],
            onChanged: (v) => setState(() {
              _classId = v;
              _subjectId = null;
            }),
          ),
          const SizedBox(height: AppSpacing.sm),
          DropdownButtonFormField<int>(
            key: ValueKey('pic-subject-$_classId'),
            initialValue: _subjectId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Subject'),
            items: [for (final s in subjects) DropdownMenuItem(value: intOf(s['id']), child: Text(stringOf(s['name'], 'Subject')))],
            onChanged: (v) => setState(() => _subjectId = v),
          ),
          const SizedBox(height: AppSpacing.md),
          Text(
            _scan
                ? 'Photograph each page flat, in good light, in order (up to six).'
                : 'Photograph a diagram, map or graph — or choose one from the gallery.',
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: AppSpacing.sm),
          if (_pages.isNotEmpty)
            SizedBox(
              height: 96,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: _pages.length,
                separatorBuilder: (_, __) => const SizedBox(width: AppSpacing.sm),
                itemBuilder: (_, i) => Stack(
                  children: [
                    ClipRRect(
                      borderRadius: AppRadius.cardRadius,
                      child: Image.memory(_pages[i].bytes, width: 72, height: 96, fit: BoxFit.cover),
                    ),
                    if (_scan)
                      Positioned(
                        left: 4,
                        top: 4,
                        child: StatusChip('${i + 1}', color: AppColors.primaryContainer),
                      ),
                  ],
                ),
              ),
            ),
          Wrap(
            spacing: AppSpacing.sm,
            children: [
              OutlinedButton.icon(
                onPressed: _busy || (_scan && _pages.length >= 6) ? null : () => _addPhoto(ImageSource.camera),
                icon: const Icon(Icons.photo_camera_outlined),
                label: Text(_scan && _pages.isNotEmpty ? 'Add page' : 'Take photo'),
              ),
              OutlinedButton.icon(
                onPressed: _busy ? null : () => _addPhoto(ImageSource.gallery),
                icon: const Icon(Icons.photo_library_outlined),
                label: const Text('Gallery'),
              ),
              if (_pages.isNotEmpty)
                TextButton(
                  onPressed: _busy ? null : () => setState(() => _pages.clear()),
                  child: const Text('Start again'),
                ),
            ],
          ),
          if (!_scan && _pages.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            TextField(
              controller: _alt,
              maxLength: 255,
              decoration: const InputDecoration(
                labelText: 'What the picture shows',
                helperText: 'Suggested by AI — check it. Read aloud to students using a screen reader.',
              ),
            ),
          ],
          if (_status != null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
              child: Row(
                children: [
                  const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
                  const SizedBox(width: AppSpacing.sm),
                  Text(_status!, style: AppText.bodyMd),
                ],
              ),
            ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.sm),
              child: Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            ),
          const SizedBox(height: AppSpacing.md),
          if (drafts == null)
            FilledButton.icon(
              onPressed: _busy ? null : () => _generate(subjects, classes),
              icon: const Icon(Icons.auto_awesome_outlined),
              label: Text(_scan ? 'Read the paper' : 'Write questions'),
            ),
          if (drafts != null && drafts.isNotEmpty) ...[
            Text('Review — tick what to keep. The AI can be wrong.', style: AppText.labelLg),
            const SizedBox(height: AppSpacing.sm),
            for (var i = 0; i < drafts.length; i++) _draftCard(i, drafts[i]),
            const SizedBox(height: AppSpacing.sm),
            FilledButton.icon(
              onPressed: _busy || _keep.isEmpty ? null : _save,
              icon: const Icon(Icons.save_alt_outlined),
              label: Text('Save ${_keep.length} to the question bank'),
            ),
          ],
          const SizedBox(height: AppSpacing.xl),
        ],
      ),
    );
  }

  Widget _draftCard(int i, Map<String, dynamic> q) {
    final options = q['options'] is Map ? (q['options'] as Map).cast<String, dynamic>() : null;
    final needsKey = _needsKey(q);
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: OutlinedCard(
        statusColor: needsKey && _keep.contains(i) ? AppColors.late : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              value: _keep.contains(i),
              onChanged: (on) => setState(() => on == true ? _keep.add(i) : _keep.remove(i)),
              title: Text('Q${i + 1}. ${stringOf(q['question'])}'),
              subtitle: q['needs_figure'] == true
                  ? const Text('Uses a figure from the paper — attach it after saving.')
                  : null,
            ),
            if (options != null)
              RadioGroup<String>(
                groupValue: q['correct_answer'] == null ? null : stringOf(q['correct_answer']),
                onChanged: (v) => setState(() => q['correct_answer'] = v),
                child: Column(
                  children: [
                    for (final e in options.entries)
                      RadioListTile<String>(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        value: e.key,
                        title: Text('${e.key}. ${stringOf(e.value)}'),
                      ),
                  ],
                ),
              ),
            if (needsKey && _keep.contains(i))
              Text('Pick the correct answer.', style: AppText.labelSm.copyWith(color: AppColors.late)),
          ],
        ),
      ),
    );
  }
}
