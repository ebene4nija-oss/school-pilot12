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
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';

/// The student's passport photo, fetched through the protected route: photos
/// are private, so there is no public link to hand an image widget. Null when
/// none is on file.
final studentPhotoProvider = FutureProvider.autoDispose.family<Uint8List?, int>((ref, id) async {
  try {
    final bytes = await ref.watch(apiClientProvider).getBytes(Api.studentPhoto(id));
    return bytes.isEmpty ? null : bytes;
  } catch (error) {
    if (asApiException(error).isNotFound) return null;
    rethrow;
  }
});

/// Take a passport photo with the phone camera and upload it.
///
/// The server decides who may (an admin, or the class's form teacher) and
/// says so if not. Framing guidance comes first, a preview after, so a
/// blurred or badly framed photo is retaken before it reaches an ID card.
Future<bool> takePassportPhoto(BuildContext context, WidgetRef ref, int studentId, String name) async {
  final go = await showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      title: const Text('Passport photo'),
      content: const Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _Tip(Icons.crop_portrait, 'Head and shoulders, face in the middle, looking at the camera.'),
          _Tip(Icons.wallpaper, 'Plain light background — a white wall is ideal.'),
          _Tip(Icons.light_mode_outlined, 'Good light on the face, no shadows; hold the phone upright.'),
          _Tip(Icons.no_photography_outlined, 'No caps or sunglasses.'),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('Cancel')),
        FilledButton.icon(
          onPressed: () => Navigator.pop(dialogContext, true),
          icon: const Icon(Icons.photo_camera_outlined),
          label: const Text('Open camera'),
        ),
      ],
    ),
  );
  if (go != true || !context.mounted) return false;

  while (context.mounted) {
    final shot = await ImagePicker().pickImage(
      source: ImageSource.camera,
      preferredCameraDevice: CameraDevice.rear,
      maxWidth: 900,
      imageQuality: 85,
    );
    if (shot == null || !context.mounted) return false;
    final bytes = await shot.readAsBytes();
    if (!context.mounted) return false;

    final choice = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('Use this photo of $name?'),
        content: AspectRatio(
          aspectRatio: 3 / 4,
          child: ClipRRect(
            borderRadius: AppRadius.cardRadius,
            child: Image.memory(bytes, fit: BoxFit.cover),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext, 'retake'), child: const Text('Retake')),
          FilledButton(onPressed: () => Navigator.pop(dialogContext, 'use'), child: const Text('Use photo')),
        ],
      ),
    );
    if (choice == 'retake') continue;
    if (choice != 'use' || !context.mounted) return false;

    final messenger = ScaffoldMessenger.of(context);
    try {
      await ref.read(apiClientProvider).post<dynamic>(
            Api.studentPhoto(studentId),
            body: FormData.fromMap({
              'photo': MultipartFile.fromBytes(bytes, filename: 'passport.jpg'),
            }),
          );
      ref.invalidate(studentPhotoProvider(studentId));
      messenger.showSnackBar(const SnackBar(content: Text('Passport photo saved.')));
      return true;
    } catch (error) {
      messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
      return false;
    }
  }
  return false;
}

class _Tip extends StatelessWidget {
  const _Tip(this.icon, this.text);

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 20, color: AppColors.primaryContainer),
            const SizedBox(width: AppSpacing.sm),
            Expanded(child: Text(text, style: AppText.bodyMd)),
          ],
        ),
      );
}

final _studentRecordProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) async {
  return mapOf(mapOf(await ref.watch(apiClientProvider).get<dynamic>(Api.student(id)))['student']);
});

/// Correct a student's record: school admins only (the server enforces it).
///
/// The fields a school most often gets wrong at enrolment. Health details are
/// not here: they are a separate, audited record (doc §12).
class EditStudentScreen extends ConsumerStatefulWidget {
  const EditStudentScreen({super.key, required this.studentId});

  final int studentId;

  @override
  ConsumerState<EditStudentScreen> createState() => _EditStudentScreenState();
}

class _EditStudentScreenState extends ConsumerState<EditStudentScreen> {
  final _name = TextEditingController();
  final _admission = TextEditingController();
  final _state = TextEditingController();
  final _lga = TextEditingController();
  String? _gender;
  DateTime? _dob;
  int? _classId;
  bool _filled = false;
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    for (final c in [_name, _admission, _state, _lga]) {
      c.dispose();
    }
    super.dispose();
  }

  void _fill(Map<String, dynamic> s) {
    if (_filled) return;
    _filled = true;
    _name.text = stringOf(s['name'] ?? s['full_name']);
    _admission.text = stringOf(s['admission_number']);
    _state.text = stringOf(s['state_of_origin']);
    _lga.text = stringOf(s['lga']);
    final g = stringOf(s['gender']).toLowerCase();
    _gender = const ['male', 'female', 'other'].contains(g) ? g : null;
    _dob = Dates.tryParse(s['date_of_birth']);
    final classId = s['class_id'] ?? fieldOf(s['class'], 'id');
    _classId = classId == null ? null : intOf(classId);
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) {
      setState(() => _error = 'A name is needed.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await ref.read(apiClientProvider).put<dynamic>(Api.student(widget.studentId), body: {
        'name': _name.text.trim(),
        'admission_number': _admission.text.trim(),
        if (_gender != null) 'gender': _gender,
        if (_dob != null) 'date_of_birth': Dates.apiDate(_dob!),
        'state_of_origin': _state.text.trim(),
        'lga': _lga.text.trim(),
        if (_classId != null) 'class_id': _classId,
      });
      if (mounted) Navigator.pop(context, true);
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
    final record = ref.watch(_studentRecordProvider(widget.studentId));
    final classes = ref.watch(classesProvider).valueOrNull ?? const <ClassRef>[];

    return Scaffold(
      appBar: AppBar(title: const Text('Edit student details')),
      body: record.when(
        loading: () => const LoadingState(rows: 5),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(_studentRecordProvider(widget.studentId)),
        ),
        data: (s) {
          _fill(s);
          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              TextField(controller: _name, decoration: const InputDecoration(labelText: 'Full name')),
              const SizedBox(height: AppSpacing.md),
              TextField(controller: _admission, decoration: const InputDecoration(labelText: 'Admission number')),
              const SizedBox(height: AppSpacing.md),
              DropdownButtonFormField<String>(
                initialValue: _gender,
                decoration: const InputDecoration(labelText: 'Gender'),
                items: const [
                  DropdownMenuItem(value: 'female', child: Text('Female')),
                  DropdownMenuItem(value: 'male', child: Text('Male')),
                ],
                onChanged: (v) => setState(() => _gender = v),
              ),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.cake_outlined),
                title: const Text('Date of birth'),
                subtitle: Text(_dob == null ? 'Not set' : Dates.full(_dob)),
                onTap: () async {
                  final now = DateTime.now();
                  final picked = await showDatePicker(
                    context: context,
                    initialDate: _dob ?? DateTime(now.year - 10),
                    firstDate: DateTime(now.year - 25),
                    lastDate: now,
                  );
                  if (picked != null) setState(() => _dob = picked);
                },
              ),
              TextField(controller: _state, decoration: const InputDecoration(labelText: 'State of origin')),
              const SizedBox(height: AppSpacing.md),
              TextField(controller: _lga, decoration: const InputDecoration(labelText: 'LGA')),
              const SizedBox(height: AppSpacing.md),
              DropdownButtonFormField<int>(
                initialValue: classes.any((c) => c.id == _classId) ? _classId : null,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Class'),
                items: [for (final c in classes) DropdownMenuItem(value: c.id, child: Text(c.name))],
                onChanged: (v) => setState(() => _classId = v),
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                'Health details are kept in a separate, audited record and are not edited here.',
                style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
              ),
              if (_error != null) ...[
                const SizedBox(height: AppSpacing.sm),
                Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
              ],
              const SizedBox(height: AppSpacing.lg),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save changes'),
              ),
            ],
          );
        },
      ),
    );
  }
}
