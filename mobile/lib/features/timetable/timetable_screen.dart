import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/auth/session.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// Whose timetable: a student's own class week, a teacher's own lessons, or
/// the whole school for an admin. Each comes back as a flat list of periods.
final timetableProvider =
    FutureProvider.autoDispose<Cached<List<Map<String, dynamic>>>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);
  final role = ref.watch(currentRoleProvider);

  final raw = await cachedGet<dynamic>(
    cache: cache,
    key: 'timetable:${role.wire}',
    fetch: () => switch (role) {
      UserRole.student => api.get<dynamic>(Api.myTimetable),
      UserRole.teacher => api.get<dynamic>(Api.timetableView, query: {'mine': 1}),
      _ => api.get<dynamic>(Api.timetableView),
    },
  );

  return Cached(data: periodsOf(raw.data), fetchedAt: raw.fetchedAt, stale: raw.stale);
});

/// `/me/timetable` groups by day; `/timetable/view` is a flat list. Both
/// become one list of maps with a `day` field.
List<Map<String, dynamic>> periodsOf(Object? data) {
  final days = data is Map ? data['days'] : null;
  if (days is Map) {
    return [
      for (final entry in days.entries)
        for (final p in listOf(entry.value)) {...p, 'day': p['day'] ?? entry.key},
    ];
  }
  return listOf(data, ['entries', 'timetable', 'periods']);
}

const _days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

/// The weekly timetable, cached hard.
///
/// This is the single most-opened screen in the app and the one most often
/// opened with no signal, so it always renders from disk if it has to and says
/// when it last refreshed. A teacher taps a lesson to change where it is held.
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
    final role = ref.watch(currentRoleProvider);
    final canMove = role == UserRole.teacher || role.isAdmin;

    return Scaffold(
      appBar: AppBar(title: Text(role == UserRole.teacher ? 'My lessons' : 'Timetable')),
      body: CachedView<List<Map<String, dynamic>>>(
        value: timetable,
        onRetry: () => ref.invalidate(timetableProvider),
        builder: (context, entries, meta) {
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
                child: entries.isEmpty
                    // A whole empty week is not "nothing on Monday": the
                    // school has not published a timetable for this class.
                    ? EmptyState(
                        icon: Icons.calendar_month_outlined,
                        title: 'No timetable yet',
                        message: role == UserRole.student
                            ? 'Your school has not published a timetable for '
                                'your class yet. It will appear here once it does.'
                            : 'No published timetable includes your lessons yet.',
                      )
                    : forDay.isEmpty
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
                        itemBuilder: (context, i) => PeriodRow(
                          entry: forDay[i],
                          onTap: canMove && forDay[i]['id'] != null && !isBreak(forDay[i])
                              ? () => _changeVenue(forDay[i])
                              : null,
                        ),
                      ),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _changeVenue(Map<String, dynamic> entry) async {
    final changed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _VenueSheet(entry: entry),
    );
    if (changed == true) ref.invalidate(timetableProvider);
  }
}

bool isBreak(Map<String, dynamic> entry) {
  if (entry['is_break'] == true || entry['is_break'] == 1) return true;
  final subject = subjectOf(entry).toLowerCase();
  return subject.contains('break') || subject.contains('assembly');
}

String subjectOf(Map<String, dynamic> e) {
  final s = e['subject'];
  return stringOf(s is Map ? s['name'] : (e['subject_name'] ?? s), 'Period');
}

/// One period. Field names differ between the student and staff endpoints
/// (`teacher` is a name on one, an object on the other), so both are read.
class PeriodRow extends StatelessWidget {
  const PeriodRow({super.key, required this.entry, this.onTap});

  final Map<String, dynamic> entry;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final start = stringOf(entry['start_time'], '—');
    final end = stringOf(entry['end_time']);
    final subject = subjectOf(entry);
    final t = entry['teacher'];
    final teacher = stringOf(entry['cover_teacher'] != null
        ? '${entry['cover_teacher']} (cover)'
        : (t is Map ? t['name'] : (t ?? entry['teacher_name'])));
    final room = stringOf(entry['venue_name'] ?? entry['venue'] ?? entry['room']);
    final className = stringOf(entry['class_name']);

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
                NumericText(short(start), weight: FontWeight.w600),
                if (end.isNotEmpty)
                  NumericText(
                    short(end),
                    size: 12,
                    color: AppColors.onSurfaceVariant,
                  ),
              ],
            ),
          ),
          Expanded(
            child: isBreak(entry)
                ? Container(
                    padding: const EdgeInsets.all(AppSpacing.sm + 4),
                    decoration: BoxDecoration(
                      color: AppColors.surfaceContainer,
                      borderRadius: AppRadius.cardRadius,
                    ),
                    child: Text(subject == 'Period' ? 'Break' : subject, style: AppText.bodyMd),
                  )
                : OutlinedCard(
                    onTap: onTap,
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(subject, style: AppText.bodyLg),
                              if (teacher.isNotEmpty || className.isNotEmpty)
                                Text(
                                  [className, teacher].where((s) => s.isNotEmpty).join(' · '),
                                  style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                                ),
                              if (room.isNotEmpty)
                                Row(
                                  children: [
                                    const Icon(Icons.place_outlined, size: 14, color: AppColors.onSurfaceVariant),
                                    const SizedBox(width: 4),
                                    Text(room, style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant)),
                                  ],
                                ),
                            ],
                          ),
                        ),
                        if (onTap != null)
                          const Icon(Icons.edit_location_alt_outlined, color: AppColors.outline, size: 20),
                      ],
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  /// `08:40:00` from the API becomes `08:40` on screen.
  static String short(String time) =>
      time.length >= 5 ? time.substring(0, 5) : time;
}

/// Where this lesson is held: every week, or on one date. Only venues free at
/// that time are offered; the server refuses any other.
class _VenueSheet extends ConsumerStatefulWidget {
  const _VenueSheet({required this.entry});

  final Map<String, dynamic> entry;

  @override
  ConsumerState<_VenueSheet> createState() => _VenueSheetState();
}

class _VenueSheetState extends ConsumerState<_VenueSheet> {
  DateTime? _date;
  List<Map<String, dynamic>>? _free;
  String? _error;
  bool _busy = false;

  int get _id => intOf(widget.entry['id']);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _free = null);
    try {
      final res = await ref.read(apiClientProvider).get<dynamic>(
        Api.freeVenues(_id),
        query: {if (_date != null) 'date': Dates.apiDate(_date!)},
      );
      setState(() => _free = listOf(res));
    } catch (error) {
      setState(() {
        _free = const [];
        _error = asApiException(error).message;
      });
    }
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: now.add(const Duration(days: 120)),
      initialDate: _nextDay(now),
      selectableDayPredicate: (d) => _matchesDay(d),
    );
    if (picked != null) {
      _date = picked;
      await _load();
    }
  }

  /// The lesson's own weekday, as the timetable names it ("Monday").
  bool _matchesDay(DateTime d) {
    const names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    return stringOf(widget.entry['day_of_week'] ?? widget.entry['day']).toLowerCase().startsWith(names[d.weekday - 1].toLowerCase().substring(0, 3));
  }

  DateTime _nextDay(DateTime from) {
    var d = DateTime(from.year, from.month, from.day);
    for (var i = 0; i < 7 && !_matchesDay(d); i++) {
      d = d.add(const Duration(days: 1));
    }
    return d;
  }

  Future<void> _save(int? venueId) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = ref.read(apiClientProvider);
      if (_date == null) {
        await api.put<dynamic>(Api.entryVenue(_id), body: {'venue_id': venueId});
      } else {
        await api.post<dynamic>(
          Api.entryVenueChange(_id),
          body: {'date': Dates.apiDate(_date!), 'venue_id': venueId},
        );
      }
      if (mounted) Navigator.pop(context, true);
    } catch (error) {
      setState(() => _error = asApiException(error).message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final e = widget.entry;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Where is this lesson?', style: AppText.headlineSm),
            Text(
              [subjectOf(e), stringOf(e['class_name']), stringOf(e['day_of_week']), stringOf(e['slot_name'])]
                  .where((s) => s.isNotEmpty)
                  .join(' · '),
              style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
            ),
            const SizedBox(height: AppSpacing.sm),
            SegmentedButton<bool>(
              segments: const [
                ButtonSegment(value: false, label: Text('Every week')),
                ButtonSegment(value: true, label: Text('One day')),
              ],
              selected: {_date != null},
              showSelectedIcon: false,
              onSelectionChanged: (s) async {
                if (s.first) {
                  await _pickDate();
                } else {
                  _date = null;
                  await _load();
                }
              },
            ),
            if (_date != null)
              TextButton.icon(
                icon: const Icon(Icons.event),
                label: Text(Dates.full(_date)),
                onPressed: _pickDate,
              ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child: Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
              ),
            const SizedBox(height: AppSpacing.sm),
            if (_free == null)
              const LoadingState(rows: 2)
            else
              ConstrainedBox(
                constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.45),
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    ListTile(
                      leading: const Icon(Icons.home_outlined),
                      title: Text(_date == null ? "The class's own room" : 'Its usual venue'),
                      onTap: _busy ? null : () => _save(null),
                    ),
                    for (final v in _free!)
                      ListTile(
                        leading: const Icon(Icons.place_outlined),
                        title: Text(stringOf(v['name'])),
                        subtitle: Text(
                          [stringOf(v['kind']), if (v['capacity'] != null) 'seats ${intOf(v['capacity'])}', if (v['shared'] == true) 'shared']
                              .where((s) => s.isNotEmpty)
                              .join(' · '),
                        ),
                        onTap: _busy ? null : () => _save(intOf(v['id'])),
                      ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}
