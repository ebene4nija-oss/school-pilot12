import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/widgets.dart';
import 'academic_history_screen.dart';

final studentQuickViewProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(mapOf(await api.get<dynamic>(Api.studentQuickView(id)))['student']);
});

/// One student at a glance: class, parent with a call button, attendance,
/// latest average, fees and missing record fields. Each field arrives only
/// if the viewer is allowed it (the directory decides), so a hidden field is
/// simply absent here.
class StudentProfileSheet extends ConsumerWidget {
  const StudentProfileSheet({super.key, required this.student});

  /// The roster row, shown straight away while the full view loads.
  final Map<String, dynamic> student;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final id = intOf(student['id']);
    final full = ref.watch(studentQuickViewProvider(id));
    final s = {...student, ...?full.valueOrNull};
    final name = stringOf(s['name'] ?? s['full_name'], 'Student');
    final phone = stringOf(s['guardian_phone']);
    final missing = (s['missing_fields'] is List) ? (s['missing_fields'] as List).map(stringOf).toList() : const <String>[];

    Widget row(String label, String value) => Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: Row(
            children: [
              SizedBox(width: 140, child: Text(label, style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant))),
              Expanded(child: Text(value.isEmpty ? '—' : value, style: AppText.bodyMd)),
            ],
          ),
        );

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                InitialsAvatar(name, size: 56),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(name, style: AppText.headlineSm),
                      Text(
                        [stringOf(nameOf(s['class'])), stringOf(s['arm']), stringOf(s['admission_number'])].where((x) => x.isNotEmpty).join(' · '),
                        style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            if (full.isLoading) const Padding(padding: EdgeInsets.only(top: AppSpacing.sm), child: LinearProgressIndicator()),
            const SizedBox(height: AppSpacing.lg),
            if (s['guardian_name'] != null || phone.isNotEmpty)
              OutlinedCard(
                child: Row(
                  children: [
                    const Icon(Icons.family_restroom, color: AppColors.primaryContainer),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(stringOf(s['guardian_name'], 'Parent'), style: AppText.bodyLg),
                          if (phone.isNotEmpty) Text(phone, style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
                        ],
                      ),
                    ),
                    if (phone.isNotEmpty)
                      IconButton.filled(
                        tooltip: 'Call parent',
                        onPressed: () => launchUrl(Uri(scheme: 'tel', path: phone)),
                        icon: const Icon(Icons.call),
                      ),
                  ],
                ),
              ),
            const SizedBox(height: AppSpacing.md),
            row('Status', stringOf(s['status'] ?? s['profile_status'])),
            row('Gender', stringOf(s['gender'])),
            row('Date of birth', Dates.full(Dates.tryParse(s['date_of_birth']))),
            row('Attendance', s['attendance_rate'] == null ? '' : '${numOf(s['attendance_rate']).toStringAsFixed(0)}%'),
            row('Latest average', s['latest_average'] == null ? '' : '${numOf(s['latest_average']).toStringAsFixed(1)}%'),
            if (s['fee_status'] != null) row('Fees', stringOf(s['fee_status'])),
            if (intOf(s['siblings']) > 0) row('Siblings here', '${intOf(s['siblings'])}'),
            if (missing.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.sm),
                child: Text(
                  'Record incomplete: ${missing.join(', ').replaceAll('_', ' ')}',
                  style: AppText.labelSm.copyWith(color: AppColors.late),
                ),
              ),
            const SizedBox(height: AppSpacing.md),
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(
                builder: (_) => AcademicHistoryScreen(
                  path: Api.studentAcademicHistory(id),
                  title: name,
                ),
              )),
              icon: const Icon(Icons.history_edu_outlined),
              label: const Text('Academic history & progress'),
            ),
            const SizedBox(height: AppSpacing.md),
            // Health data is deliberately not shown here. It is a separate,
            // audited endpoint and is never cached on the device (NDPA, doc §12).
            Text(
              'Health records are held separately and are not shown in the app.',
              style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }
}
