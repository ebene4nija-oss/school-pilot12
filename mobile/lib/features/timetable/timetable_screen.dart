import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final timetableProvider =
    FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<dynamic>(
    cache: cache,
    key: 'timetable:view',
    fetch: () => api.get<dynamic>(Api.timetableView),
  );
});

const _days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

/// The weekly timetable, cached hard.
///
/// This is the single most-opened screen in the app and the one most often
/// opened with no signal, so it always renders from disk if it has to and says
/// when it last refreshed.
class TimetableScreen extends ConsumerStatefulWidget {
  const TimetableScreen({super.key});

  @override
  ConsumerState<TimetableScreen> createState() => _TimetableScreenState();
}

class _TimetableScreenState extends ConsumerState<TimetableScreen> {
  late int _day = (DateTime.now().weekday - 1).clamp(0, 4);

  @override
  Widget build(BuildContext context) {
    final timetable = ref.watch(timetableProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Timetable')),
      body: CachedView<dynamic>(
        value: timetable,
        onRetry: () => ref.invalidate(timetableProvider),
        builder: (context, data, meta) {
          final entries = listOf(data, ['entries', 'timetable', 'periods']);
          final forDay = entries.where((e) {
            final day = stringOf(e['day'] ?? e['day_of_week']).toLowerCase();
            return day.startsWith(_days[_day].toLowerCase()) ||
                day == '${_day + 1}';
          }).toList()
            ..sort((a, b) => stringOf(a['start_time'])
                .compareTo(stringOf(b['start_time'])));

          return Column(
            children: [
              FreshnessLine(
                fetchedAt: meta.fetchedAt,
                onRefresh: () => ref.invalidate(timetableProvider),
              ),
              SizedBox(
                height: 48,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.screenMargin,
                  ),
                  children: [
                    for (var i = 0; i < _days.length; i++)
                      Padding(
                        padding: const EdgeInsets.only(right: AppSpacing.sm),
                        child: ChoiceChip(
                          label: Text(_days[i]),
                          selected: _day == i,
                          showCheckmark: false,
                          selectedColor: AppColors.primaryContainer,
                          labelStyle: AppText.labelLg.copyWith(
                            color: _day == i
                                ? AppColors.onPrimary
                                : AppColors.onSurface,
                          ),
                          onSelected: (_) => setState(() => _day = i),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.sm),
              Expanded(
                child: forDay.isEmpty
                    ? const EmptyState(
                        icon: Icons.event_available_outlined,
                        title: 'Nothing scheduled',
                        message: 'There are no periods for this day.',
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(
                          AppSpacing.screenMargin,
                          0,
                          AppSpacing.screenMargin,
                          AppSpacing.xl,
                        ),
                        itemCount: forDay.length,
                        itemBuilder: (context, i) =>
                            _PeriodRow(entry: forDay[i]),
                      ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _PeriodRow extends StatelessWidget {
  const _PeriodRow({required this.entry});

  final Map<String, dynamic> entry;

  @override
  Widget build(BuildContext context) {
    final start = stringOf(entry['start_time'], '—');
    final end = stringOf(entry['end_time']);
    final subject = stringOf(
      entry['subject']?['name'] ?? entry['subject_name'],
      'Period',
    );
    final teacher = stringOf(entry['teacher']?['name'] ?? entry['teacher_name']);
    final room = stringOf(entry['room'] ?? entry['venue']);
    final isBreak = subject.toLowerCase().contains('break') ||
        subject.toLowerCase().contains('assembly');

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 64,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                NumericText(_short(start), weight: FontWeight.w600),
                if (end.isNotEmpty)
                  NumericText(
                    _short(end),
                    size: 12,
                    color: AppColors.onSurfaceVariant,
                  ),
              ],
            ),
          ),
          Expanded(
            child: isBreak
                ? Container(
                    padding: const EdgeInsets.all(AppSpacing.sm + 4),
                    decoration: BoxDecoration(
                      color: AppColors.surfaceContainer,
                      borderRadius: AppRadius.cardRadius,
                    ),
                    child: Text(subject, style: AppText.bodyMd),
                  )
                : OutlinedCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(subject, style: AppText.bodyLg),
                        if (teacher.isNotEmpty || room.isNotEmpty)
                          Text(
                            [teacher, room]
                                .where((s) => s.isNotEmpty)
                                .join(' · '),
                            style: AppText.bodyMd
                                .copyWith(color: AppColors.onSurfaceVariant),
                          ),
                      ],
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  /// `08:40:00` from the API becomes `08:40` on screen.
  static String _short(String time) =>
      time.length >= 5 ? time.substring(0, 5) : time;
}
