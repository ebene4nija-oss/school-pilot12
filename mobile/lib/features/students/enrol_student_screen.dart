import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';

/// Enrolling one child at the front desk: the child, their class and a parent.
///
/// Only what the school needs to start. Health details, documents and the
/// rest are completed on the web, where the full record lives (NDPA data
/// minimisation — nothing here beyond the enrolment form's own fields).
class EnrolStudentScreen extends ConsumerStatefulWidget {
  const EnrolStudentScreen({super.key});

  @override
  ConsumerState<EnrolStudentScreen> createState() => _EnrolStudentScreenState();
}

class _EnrolStudentScreenState extends ConsumerState<EnrolStudentScreen> {
  final _name = TextEditingController();
  final _admission = TextEditingController();
  final _parentName = TextEditingController();
  final _parentPhone = TextEditingController();
  String? _gender;
  int? _classId;
  DateTime? _dob;
  String _relationship = 'parent';
  bool _saving = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void dispose() {
    for (final c in [_name, _admission, _parentName, _parentPhone]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty || _gender == null || _classId == null) {
      setState(() => _error = "The child's name, gender and class are needed.");
      return;
    }
    final hasParent = _parentName.text.trim().isNotEmpty || _parentPhone.text.trim().isNotEmpty;
    if (hasParent && (_parentName.text.trim().isEmpty || _parentPhone.text.trim().isEmpty)) {
      setState(() => _error = "Give the parent's name and phone number together.");
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.students,
        body: {
          'name': _name.text.trim(),
          'gender': _gender,
          'class_id': _classId,
          if (_admission.text.trim().isNotEmpty) 'admission_number': _admission.text.trim(),
          if (_dob != null) 'date_of_birth': Dates.apiDate(_dob!),
          if (hasParent)
            'guardians': [
              {'name': _parentName.text.trim(), 'phone': _parentPhone.text.trim(), 'relationship': _relationship},
            ],
        },
      ));
      if (mounted) Navigator.pop(context, stringOf(res['message'], 'Student enrolled.'));
    } catch (error) {
      if (!mounted) return;
      final failure = asApiException(error);
      setState(() {
        _saving = false;
        _error = failure.message;
        _fieldErrors = failure.fieldErrors;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final classes = ref.watch(classesProvider).valueOrNull ?? const <ClassRef>[];

    return Scaffold(
      appBar: AppBar(title: const Text('Enrol a student')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          Text('The child', style: AppText.labelLg),
          TextField(
            controller: _name,
            textCapitalization: TextCapitalization.words,
            decoration: InputDecoration(labelText: 'Full name', errorText: _fieldErrors['name']),
          ),
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
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<int>(
            initialValue: _classId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Class'),
            items: [for (final c in classes) DropdownMenuItem(value: c.id, child: Text(c.name))],
            onChanged: (v) => setState(() => _classId = v),
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _admission,
            decoration: InputDecoration(
              labelText: 'Admission number (optional)',
              helperText: 'Can be added later',
              errorText: _fieldErrors['admission_number'],
            ),
          ),
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.cake_outlined),
            title: const Text('Date of birth (optional)'),
            subtitle: Text(_dob == null ? 'Not set' : Dates.full(_dob)),
            onTap: () async {
              final picked = await showDatePicker(
                context: context,
                initialDate: _dob ?? DateTime(DateTime.now().year - 10),
                firstDate: DateTime(DateTime.now().year - 25),
                lastDate: DateTime.now().subtract(const Duration(days: 1)),
              );
              if (picked != null) setState(() => _dob = picked);
            },
          ),
          const SizedBox(height: AppSpacing.md),
          Text('A parent or guardian', style: AppText.labelLg),
          Text(
            'They get an app account and are linked to this child. A second child with the same number joins the same parent.',
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
          TextField(
            controller: _parentName,
            textCapitalization: TextCapitalization.words,
            decoration: InputDecoration(labelText: 'Name', errorText: _fieldErrors['guardians.0.name']),
          ),
          TextField(
            controller: _parentPhone,
            keyboardType: TextInputType.phone,
            decoration: InputDecoration(
              labelText: 'Phone number',
              hintText: '0803 123 4567',
              errorText: _fieldErrors['guardians.0.phone'],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<String>(
            initialValue: _relationship,
            decoration: const InputDecoration(labelText: 'Relationship'),
            items: const [
              DropdownMenuItem(value: 'mother', child: Text('Mother')),
              DropdownMenuItem(value: 'father', child: Text('Father')),
              DropdownMenuItem(value: 'guardian', child: Text('Guardian')),
              DropdownMenuItem(value: 'parent', child: Text('Parent')),
              DropdownMenuItem(value: 'other', child: Text('Other')),
            ],
            onChanged: (v) => setState(() => _relationship = v ?? 'parent'),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.sm),
              child: Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            ),
          const SizedBox(height: AppSpacing.lg),
          FilledButton.icon(
            onPressed: _saving ? null : _save,
            icon: _saving
                ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.person_add_alt_1),
            label: const Text('Enrol'),
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'Health details, documents and the rest of the record are added on the web.',
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
        ],
      ),
    );
  }
}
