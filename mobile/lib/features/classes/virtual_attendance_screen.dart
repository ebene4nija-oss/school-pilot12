import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// The teacher's virtual classes from the last fortnight.
final recentVirtualClassesProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  return listOf(await ref.watch(apiClientProvider).get<dynamic>(Api.liveClasses, query: {'recent': 1}));
});

/// One class's register. Never cached: it changes when the provider report
/// is read or a mark is corrected, and an old copy would mislead.
final virtualRegisterProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) async {
  return mapOf(await ref.watch(apiClientProvider).get<dynamic>(Api.liveClassAttendance(id)));
});

const _statusColour = {
  'present': AppColors.present,
  'late': AppColors.late,
  'partial': AppColors.late,
  'absent': AppColors.absent,
};

class VirtualClassesScreen extends ConsumerWidget {
  const VirtualClassesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final classes = ref.watch(recentVirtualClassesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Virtual class attendance')),
      body: classes.when(
        loading: () => const LoadingState(),
        error: (e, _) => ErrorState(error: asApiException(e), onRetry: () => ref.invalidate(recentVirtualClassesProvider)),
        data: (rows) => rows.isEmpty
            ? const EmptyState(
                icon: Icons.videocam_off_outlined,
                title: 'No virtual classes',
                message: 'Classes you taught in the last two weeks appear here.',
              )
            : RefreshIndicator(
                onRefresh: () async => ref.refresh(recentVirtualClassesProvider.future),
                child: ListView(
                  padding: const EdgeInsets.all(AppSpacing.screenMargin),
                  children: [
                    for (final c in rows)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: OutlinedCard(
                          statusColor: c['status'] == 'cancelled' ? AppColors.excused : null,
                          onTap: c['status'] == 'cancelled'
                              ? null
                              : () => Navigator.of(context).push(MaterialPageRoute<void>(
                                    builder: (_) => VirtualRegisterScreen(liveClass: c),
                                  )),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(stringOf(c['title']), style: AppText.bodyLg),
                                    Text(
                                      [stringOf(c['class']), stringOf(c['subject']), Dates.dayAndTime(Dates.tryParse(c['scheduled_for'])?.toLocal())]
                                          .where((s) => s.isNotEmpty)
                                          .join(' · '),
                                      style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                                    ),
                                  ],
                                ),
                              ),
                              if (c['status'] == 'cancelled') const StatusChip('Cancelled') else const Icon(Icons.chevron_right),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
              ),
      ),
    );
  }
}

/// Who attended, read from Zoom or Meet where the school has connected it.
/// The teacher can ask for the report now, say who an unknown participant
/// was, and correct a mark with a reason.
class VirtualRegisterScreen extends ConsumerStatefulWidget {
  const VirtualRegisterScreen({super.key, required this.liveClass});

  final Map<String, dynamic> liveClass;

  @override
  ConsumerState<VirtualRegisterScreen> createState() => _VirtualRegisterScreenState();
}

class _VirtualRegisterScreenState extends ConsumerState<VirtualRegisterScreen> {
  bool _busy = false;

  int get _id => intOf(widget.liveClass['id']);

  Future<void> _run(Future<String?> Function() action) async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _busy = true);
    try {
      final message = await action();
      if (message != null) messenger.showSnackBar(SnackBar(content: Text(message)));
    } catch (error) {
      messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
    } finally {
      ref.invalidate(virtualRegisterProvider(_id));
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _readReport() => _run(() async {
        final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(Api.liveClassProviderAttendance(_id)));
        return stringOf(res['message']);
      });

  Future<void> _match(Map<String, dynamic> participant, List<Map<String, dynamic>> students) async {
    final student = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (_) => SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.7),
          child: ListView(
            shrinkWrap: true,
            children: [
              Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text('Who is "${stringOf(participant['name'], 'this')}"?', style: AppText.headlineSm),
              ),
              for (final s in students)
                ListTile(
                  title: Text(stringOf(s['name'])),
                  subtitle: Text(stringOf(s['admission_number'])),
                  onTap: () => Navigator.pop(context, s),
                ),
            ],
          ),
        ),
      ),
    );
    if (student == null) return;
    await _run(() async {
      await ref.read(apiClientProvider).post<dynamic>(
        Api.liveClassProviderMatch(_id),
        body: {'participant_key': stringOf(participant['participant_key']), 'student_id': intOf(student['student_id']), 'remember': true},
      );
      return 'Matched. The same name is recognised in this class next time.';
    });
  }

  Future<void> _correct(Map<String, dynamic> row) async {
    final status = await showModalBottomSheet<String>(
      context: context,
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final s in ['present', 'late', 'partial', 'absent'].where((s) => s != row['status']))
              ListTile(title: Text('Mark $s'), onTap: () => Navigator.pop(context, s)),
          ],
        ),
      ),
    );
    if (status == null || !mounted) return;
    final reason = await _askReason(context, stringOf(row['name']), status);
    if (reason == null) return;
    await _run(() async {
      await ref.read(apiClientProvider).put<dynamic>(
        Api.liveClassCorrectAttendance(_id, intOf(row['student_id'])),
        body: {'status': status, 'reason': reason},
      );
      return 'Attendance corrected.';
    });
  }

  @override
  Widget build(BuildContext context) {
    final register = ref.watch(virtualRegisterProvider(_id));

    return Scaffold(
      appBar: AppBar(title: Text(stringOf(widget.liveClass['title'], 'Attendance'))),
      body: register.when(
        loading: () => const LoadingState(),
        error: (e, _) => ErrorState(error: asApiException(e), onRetry: () => ref.invalidate(virtualRegisterProvider(_id))),
        data: (data) {
          final students = listOf(data['students']);
          final unmatched = listOf(data['unmatched']);
          final sync = mapOf(data['provider_sync']);
          final fromProvider = data['source'] == 'provider';
          final counts = mapOf(data['counts']);

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              Text(
                fromProvider
                    ? 'Time is from the video provider’s report of who was in the call.'
                    : 'Time is from the SchoolPilot lesson page until the provider’s report is read.',
                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
              if (sync['status'] == 'waiting')
                const Padding(
                  padding: EdgeInsets.only(top: AppSpacing.sm),
                  child: StatusChip.pending('Report not ready yet, SchoolPilot will retry'),
                ),
              if (sync['can_sync'] == true)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpacing.sm),
                  child: OutlinedButton.icon(
                    icon: const Icon(Icons.sync),
                    label: Text(fromProvider ? 'Read the report again' : 'Read from Zoom / Meet'),
                    onPressed: _busy ? null : _readReport,
                  ),
                ),
              const SizedBox(height: AppSpacing.sm),
              Wrap(
                spacing: AppSpacing.sm,
                children: [
                  for (final e in counts.entries)
                    StatusChip('${e.key}: ${intOf(e.value)}', color: _statusColour[e.key] ?? AppColors.onSurfaceVariant),
                ],
              ),
              if (unmatched.isNotEmpty) ...[
                const SectionHeader('In the call, not matched'),
                for (final u in unmatched)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.help_outline, color: AppColors.late),
                    title: Text(stringOf(u['name'], 'Unknown')),
                    trailing: TextButton(onPressed: _busy ? null : () => _match(u, students), child: const Text('Who is this?')),
                  ),
              ],
              const SectionHeader('Students'),
              for (final s in students)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(stringOf(s['name'])),
                  subtitle: Text(
                    fromProvider
                        ? '${numOf(s['minutes_in_call'])} min in call'
                        : '${numOf(s['minutes_connected'])} min on the lesson page',
                  ),
                  trailing: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (s['corrected'] == true)
                        const Padding(
                          padding: EdgeInsets.only(right: 4),
                          child: Icon(Icons.edit, size: 14, color: AppColors.outline),
                        ),
                      StatusChip(stringOf(s['status']), color: _statusColour[s['status']] ?? AppColors.onSurfaceVariant),
                    ],
                  ),
                  onTap: _busy ? null : () => _correct(s),
                ),
            ],
          );
        },
      ),
    );
  }
}

Future<String?> _askReason(BuildContext context, String name, String status) {
  final controller = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text('Mark $name $status'),
      content: TextField(
        controller: controller,
        autofocus: true,
        maxLines: 3,
        decoration: const InputDecoration(hintText: 'Why? This is kept on the record.'),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        TextButton(
          onPressed: () {
            final text = controller.text.trim();
            if (text.length >= 3) Navigator.pop(context, text);
          },
          child: const Text('Save'),
        ),
      ],
    ),
  );
}
