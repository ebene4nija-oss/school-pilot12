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

/// A student's attendance, day by day: what the home screen's rate is made
/// of. The current term by default; earlier terms from the chips.
final myAttendanceProvider =
    FutureProvider.autoDispose.family<Map<String, dynamic>, int?>((ref, termId) async {
  return mapOf(await ref.watch(apiClientProvider).get<dynamic>(
        Api.myAttendance,
        query: {if (termId != null) 'term_id': termId},
      ));
});

class MyAttendanceScreen extends ConsumerStatefulWidget {
  const MyAttendanceScreen({super.key});

  @override
  ConsumerState<MyAttendanceScreen> createState() => _MyAttendanceScreenState();
}

class _MyAttendanceScreenState extends ConsumerState<MyAttendanceScreen> {
  int? _termId;

  static const _status = {
    'present': ('Present', AppColors.present),
    'late': ('Late', AppColors.late),
    'absent': ('Absent', AppColors.absent),
    'excused': ('Excused', AppColors.excused),
  };

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(myAttendanceProvider(_termId));

    return Scaffold(
      appBar: AppBar(title: const Text('My attendance')),
      body: data.when(
        loading: () => const LoadingState(rows: 6),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(myAttendanceProvider(_termId)),
        ),
        data: (d) {
          final s = mapOf(d['summary']);
          final days = listOf(d['days']);
          final terms = listOf(d['terms']);
          final current = intOf(fieldOf(d['term'], 'id'));
          final rate = s['rate'];

          return RefreshIndicator(
            onRefresh: () async => ref.refresh(myAttendanceProvider(_termId).future),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, AppSpacing.xl,
              ),
              children: [
                if (terms.length > 1)
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        for (final t in terms)
                          Padding(
                            padding: const EdgeInsets.only(right: AppSpacing.sm),
                            child: ChoiceChip(
                              label: Text(stringOf(t['name'], 'Term')),
                              selected: intOf(t['id']) == current,
                              onSelected: (_) => setState(() => _termId = intOf(t['id'])),
                            ),
                          ),
                      ],
                    ),
                  ),
                const SizedBox(height: AppSpacing.sm),
                Row(
                  children: [
                    Expanded(
                      child: StatTile(
                        label: 'Attendance',
                        value: rate == null ? '—' : '${numOf(rate).toStringAsFixed(0)}%',
                        caption: '${intOf(s['marked_days'])} days marked',
                        valueColor: rate == null
                            ? null
                            : numOf(rate) >= 90
                                ? AppColors.present
                                : numOf(rate) >= 75
                                    ? AppColors.late
                                    : AppColors.absent,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: StatTile(
                        label: 'Absent',
                        value: '${intOf(s['absent'])}',
                        caption: '${intOf(s['late'])} late',
                        valueColor: intOf(s['absent']) > 0 ? AppColors.absent : null,
                      ),
                    ),
                  ],
                ),
                if (intOf(s['excused']) > 0)
                  Padding(
                    padding: const EdgeInsets.only(top: AppSpacing.xs),
                    child: Text(
                      '${intOf(s['excused'])} excused ${intOf(s['excused']) == 1 ? 'day counts' : 'days count'} as school days, so they lower the rate.',
                      style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
                    ),
                  ),
                const SectionHeader('Day by day'),
                if (days.isEmpty)
                  Text(
                    'No register has been taken for you this term yet.',
                    style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                  ),
                for (final day in days)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(Dates.dayMonth(Dates.tryParse(day['date'])), style: AppText.bodyLg),
                        ),
                        Builder(builder: (_) {
                          final (label, colour) = _status[stringOf(day['status'])] ??
                              (stringOf(day['status']), AppColors.excused);
                          return StatusChip(label, color: colour);
                        }),
                      ],
                    ),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}
