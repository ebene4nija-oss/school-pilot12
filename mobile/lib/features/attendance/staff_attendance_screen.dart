import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

typedef _Range = ({String from, String to});

final staffAttendanceProvider =
    FutureProvider.autoDispose.family<Map<String, dynamic>, _Range>((ref, range) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(
    Api.staffAttendance,
    query: {'from': range.from, 'to': range.to},
  ));
});

final staffAttendanceMemberProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, ({int staffId, String from, String to})>((ref, args) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(
    Api.staffAttendanceMember(args.staffId),
    query: {'from': args.from, 'to': args.to},
  ));
});

enum _Period { today, week, month, custom }

/// Staff attendance for the administrator: who came in, who was late, who
/// missed days, over today, this week, this month or any range.
///
/// Every figure comes from a GPS clock-in checked against the school's
/// location (Settings → Clock-in location), so this screen also says plainly
/// when that has not been set — until it is, nobody can clock in.
class StaffAttendanceScreen extends ConsumerStatefulWidget {
  const StaffAttendanceScreen({super.key});

  @override
  ConsumerState<StaffAttendanceScreen> createState() => _StaffAttendanceScreenState();
}

class _StaffAttendanceScreenState extends ConsumerState<StaffAttendanceScreen> {
  _Period _period = _Period.week;
  DateTimeRange? _custom;

  _Range get _range {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final (from, to) = switch (_period) {
      _Period.today => (today, today),
      _Period.week => (today.subtract(Duration(days: today.weekday - 1)), today),
      _Period.month => (DateTime(today.year, today.month), today),
      _Period.custom => (_custom!.start, _custom!.end),
    };
    return (from: Dates.apiDate(from), to: Dates.apiDate(to));
  }

  Future<void> _pickRange() async {
    final now = DateTime.now();
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime(now.year - 2),
      lastDate: now,
      initialDateRange: _custom,
      helpText: 'Up to 120 days',
    );
    if (picked != null) {
      setState(() {
        _custom = picked;
        _period = _Period.custom;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final range = _range;
    final data = ref.watch(staffAttendanceProvider(range));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Staff attendance'),
        actions: [
          IconButton(
            tooltip: 'Clock-in location',
            icon: const Icon(Icons.edit_location_alt_outlined),
            onPressed: () => context.push('/clock-in-location'),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, 0),
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  for (final (p, label) in [
                    (_Period.today, 'Today'),
                    (_Period.week, 'This week'),
                    (_Period.month, 'This month'),
                  ])
                    Padding(
                      padding: const EdgeInsets.only(right: AppSpacing.sm),
                      child: ChoiceChip(
                        label: Text(label),
                        selected: _period == p,
                        onSelected: (_) => setState(() => _period = p),
                      ),
                    ),
                  ChoiceChip(
                    avatar: const Icon(Icons.date_range, size: 18),
                    label: Text(_period == _Period.custom
                        ? '${Dates.dayMonth(_custom!.start)} – ${Dates.dayMonth(_custom!.end)}'
                        : 'Dates…'),
                    selected: _period == _Period.custom,
                    onSelected: (_) => _pickRange(),
                  ),
                ],
              ),
            ),
          ),
          Expanded(
            child: data.when(
              loading: () => const LoadingState(rows: 6),
              error: (error, _) => ErrorState(
                error: asApiException(error),
                onRetry: () => ref.invalidate(staffAttendanceProvider(range)),
              ),
              data: (d) => _Body(data: d, range: range, singleDay: range.from == range.to),
            ),
          ),
        ],
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.data, required this.range, required this.singleDay});

  final Map<String, dynamic> data;
  final _Range range;
  final bool singleDay;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = mapOf(data['summary']);
    final staff = listOf(data['staff']);
    final schoolDays = intOf(data['school_days']);
    final configured = data['location_configured'] == true;

    // Worst first: the admin opens this to find who is missing.
    final sorted = [...staff]..sort((a, b) {
        final byAbsent = intOf(b['days_absent']).compareTo(intOf(a['days_absent']));
        if (byAbsent != 0) return byAbsent;
        return intOf(b['days_late']).compareTo(intOf(a['days_late']));
      });

    return RefreshableList(
      onRefresh: () async => ref.refresh(staffAttendanceProvider(range).future),
      padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, AppSpacing.xl),
      children: [
        if (!configured) ...[
          OutlinedCard(
            statusColor: AppColors.absent,
            onTap: () => context.push('/clock-in-location'),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Clock-in location not set', style: AppText.headlineSm),
                const SizedBox(height: AppSpacing.xs),
                Text(
                  'Staff cannot clock in until the school\'s location is saved. '
                  'Tap here, stand at the school gate, and use your current location.',
                  style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
        ],
        Text(
          '${Dates.full(Dates.tryParse(data['from']))}'
          '${singleDay ? '' : ' – ${Dates.full(Dates.tryParse(data['to']))}'}'
          ' · resume ${stringOf(data['resume_time'])}',
          style: AppText.labelLg,
        ),
        const SizedBox(height: AppSpacing.sm),
        Row(
          children: [
            Expanded(
              child: StatTile(
                label: singleDay ? 'In today' : 'Days in',
                value: singleDay
                    ? '${staff.where((p) => intOf(p['days_in']) > 0).length}/${staff.length}'
                    : '${intOf(s['days_in'])}',
                caption: singleDay ? null : '$schoolDays school days',
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: StatTile(
                label: 'Late',
                value: '${intOf(s['days_late'])}',
                valueColor: intOf(s['days_late']) > 0 ? AppColors.late : null,
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: StatTile(
                label: 'Missed',
                value: '${intOf(s['days_absent'])}',
                valueColor: intOf(s['days_absent']) > 0 ? AppColors.absent : null,
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.md),
        if (staff.isEmpty)
          const EmptyState(icon: Icons.badge_outlined, title: 'No staff yet', message: 'Add staff from the web dashboard.')
        else
          for (final p in sorted) ...[
            _StaffRow(row: p, range: range, singleDay: singleDay),
            const SizedBox(height: AppSpacing.sm),
          ],
      ],
    );
  }
}

class _StaffRow extends StatelessWidget {
  const _StaffRow({required this.row, required this.range, required this.singleDay});

  final Map<String, dynamic> row;
  final _Range range;
  final bool singleDay;

  @override
  Widget build(BuildContext context) {
    final inDays = intOf(row['days_in']);
    final late = intOf(row['days_late']);
    final absent = intOf(row['days_absent']);
    final leave = intOf(row['days_on_leave']);
    final last = Dates.tryParse(row['last_clock_in'])?.toLocal();

    final Color? bar = absent > 0
        ? AppColors.absent
        : late > 0
            ? AppColors.late
            : inDays > 0
                ? AppColors.present
                : null;

    final detail = singleDay
        ? (last != null
            ? 'Clocked in ${Dates.time(last)}${late > 0 ? ' · late' : ''}'
            : leave > 0
                ? 'On leave'
                : absent > 0
                    ? 'Did not clock in'
                    : 'Not in yet')
        : [
            '$inDays in',
            if (late > 0) '$late late',
            if (absent > 0) '$absent missed',
            if (leave > 0) '$leave on leave',
          ].join(' · ');

    return OutlinedCard(
      statusColor: bar,
      onTap: () => context.push(
        '/staff-attendance/${intOf(row['staff_id'])}?from=${range.from}&to=${range.to}',
        extra: stringOf(row['name']),
      ),
      child: Row(
        children: [
          InitialsAvatar(stringOf(row['name'])),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(stringOf(row['name']), style: AppText.labelLg),
                Text(detail, style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
              ],
            ),
          ),
          const Icon(Icons.chevron_right, color: AppColors.outline),
        ],
      ),
    );
  }
}

/// One member of staff, day by day.
class StaffAttendanceMemberScreen extends ConsumerWidget {
  const StaffAttendanceMemberScreen({
    super.key,
    required this.staffId,
    required this.from,
    required this.to,
    this.name,
  });

  final int staffId;
  final String from;
  final String to;
  final String? name;

  static const _labels = {
    'present': ('On time', AppColors.present),
    'late': ('Late', AppColors.late),
    'absent': ('Missed', AppColors.absent),
    'on_leave': ('On leave', AppColors.excused),
    'holiday': ('Holiday', AppColors.excused),
    'not_employed': ('Before start date', AppColors.excused),
    'not_yet': ('Not in yet', AppColors.excused),
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final args = (staffId: staffId, from: from, to: to);
    final data = ref.watch(staffAttendanceMemberProvider(args));

    return Scaffold(
      appBar: AppBar(title: Text(name ?? 'Staff attendance')),
      body: data.when(
        loading: () => const LoadingState(rows: 6),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(staffAttendanceMemberProvider(args)),
        ),
        data: (d) {
          final days = listOf(d['days']);
          return RefreshableList(
            onRefresh: () async => ref.refresh(staffAttendanceMemberProvider(args).future),
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, AppSpacing.xl),
            children: [
              Text(
                '${stringOf(d['designation'], 'Staff')} · resume ${stringOf(d['resume_time'])}',
                style: AppText.labelLg,
              ),
              const SizedBox(height: AppSpacing.sm),
              if (days.isEmpty)
                const EmptyState(
                  icon: Icons.event_busy_outlined,
                  title: 'No school days in this range',
                  message: 'Weekends are not counted. Choose a wider range.',
                )
              else
                for (final day in days) ...[
                  Builder(builder: (context) {
                    final (label, colour) = _labels[stringOf(day['status'])] ?? (stringOf(day['status']), AppColors.excused);
                    final at = Dates.tryParse(day['clocked_in_at'])?.toLocal();
                    return OutlinedCard(
                      child: Row(
                        children: [
                          Expanded(child: Text(Dates.dayMonth(Dates.tryParse(day['date'])), style: AppText.labelLg)),
                          if (at != null) ...[
                            Text(Dates.time(at), style: AppText.bodyMd),
                            const SizedBox(width: AppSpacing.sm),
                          ],
                          StatusChip(label, color: colour),
                        ],
                      ),
                    );
                  }),
                  const SizedBox(height: AppSpacing.xs),
                ],
            ],
          );
        },
      ),
    );
  }
}
