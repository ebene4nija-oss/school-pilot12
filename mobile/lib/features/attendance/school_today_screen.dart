import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final schoolTodayProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.attendanceToday));
});

/// The administrator's "how is today going": which registers are in, who is
/// absent (with a parent's number), and which staff have clocked in.
class SchoolTodayScreen extends ConsumerWidget {
  const SchoolTodayScreen({super.key});

  Future<void> _call(BuildContext context, String phone) async {
    final ok = await launchUrl(Uri(scheme: 'tel', path: phone));
    if (!ok && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('The number is $phone.')));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final today = ref.watch(schoolTodayProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Today')),
      body: today.when(
        loading: () => const LoadingState(rows: 6),
        error: (error, _) => ErrorState(error: asApiException(error), onRetry: () => ref.invalidate(schoolTodayProvider)),
        data: (data) {
          final s = mapOf(data['summary']);
          final classes = listOf(data['classes']);
          final unmarked = classes.where((c) => c['marked'] != true).toList();
          final absent = listOf(data['absent']);
          final staff = mapOf(data['staff']);
          final staffList = listOf(staff['list']);
          final notIn = staffList.where((p) => p['clocked_in_at'] == null).toList();

          return RefreshableList(
            onRefresh: () async => ref.refresh(schoolTodayProvider.future),
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, AppSpacing.xl),
            children: [
              Text(Dates.full(Dates.tryParse(data['date'])), style: AppText.labelLg),
              const SizedBox(height: AppSpacing.sm),
              Row(
                children: [
                  Expanded(child: StatTile(label: 'Present', value: '${intOf(s['present']) + intOf(s['late'])}', caption: '${intOf(s['late'])} late')),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(child: StatTile(label: 'Absent', value: '${intOf(s['absent'])}', valueColor: intOf(s['absent']) > 0 ? AppColors.absent : null)),
                ],
              ),
              const SizedBox(height: AppSpacing.sm),
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: 'Registers in',
                      value: '${intOf(s['classes_marked'])}/${intOf(s['classes'])}',
                      valueColor: unmarked.isNotEmpty ? AppColors.late : AppColors.present,
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: 'Staff clocked in',
                      value: '${intOf(staff['clocked_in'])}/${intOf(staff['total'])}',
                    ),
                  ),
                ],
              ),
              if (unmarked.isNotEmpty) ...[
                const SectionHeader('Registers not taken'),
                Text(
                  unmarked.map((c) => stringOf(c['class'])).join(', '),
                  style: AppText.bodyMd.copyWith(color: AppColors.late),
                ),
              ],
              SectionHeader('Absent (${absent.length})'),
              if (absent.isEmpty)
                Text('Nobody marked absent so far.', style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
              for (final a in absent)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: InitialsAvatar(stringOf(a['name'], '?')),
                  title: Text(stringOf(a['name'], 'Student')),
                  subtitle: Text(stringOf(a['class'])),
                  trailing: IconButton(
                    tooltip: a['guardian_phone'] == null ? 'No parent number on file' : 'Call parent',
                    icon: const Icon(Icons.call_outlined),
                    onPressed: a['guardian_phone'] == null ? null : () => _call(context, stringOf(a['guardian_phone'])),
                  ),
                ),
              const SectionHeader('Registers'),
              for (final c in classes)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(
                    children: [
                      Icon(
                        c['marked'] == true ? Icons.check_circle : Icons.radio_button_unchecked,
                        size: 18,
                        color: c['marked'] == true ? AppColors.present : AppColors.late,
                      ),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(child: Text(stringOf(c['class']), style: AppText.bodyMd)),
                      Text(
                        c['marked'] == true
                            ? '${intOf(c['present']) + intOf(c['late'])} in · ${intOf(c['absent'])} out of ${intOf(c['students'])}'
                            : 'not taken · ${intOf(c['students'])} students',
                        style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
              if (staffList.isNotEmpty) ...[
                SectionHeader('Staff not clocked in (${notIn.length})'),
                if (notIn.isEmpty)
                  Text('Everyone has clocked in.', style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant))
                else
                  Text(notIn.map((p) => stringOf(p['name'])).join(', '), style: AppText.bodyMd),
              ],
            ],
          );
        },
      ),
    );
  }
}
