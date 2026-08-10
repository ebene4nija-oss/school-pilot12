import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/db/outbox.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

enum AttendanceStatus {
  present('present', 'P', AppColors.present),
  absent('absent', 'A', AppColors.absent),
  late('late', 'L', AppColors.late),
  excused('excused', 'E', AppColors.excused);

  const AttendanceStatus(this.wire, this.letter, this.colour);

  final String wire;
  final String letter;
  final Color colour;
}

class RegisterQuery {
  const RegisterQuery({required this.classId, required this.termId, required this.date});

  final int classId;
  final int termId;
  final DateTime date;

  @override
  bool operator ==(Object other) =>
      other is RegisterQuery &&
      other.classId == classId &&
      other.termId == termId &&
      Dates.apiDate(other.date) == Dates.apiDate(date);

  @override
  int get hashCode => Object.hash(classId, termId, Dates.apiDate(date));
}

final registerProvider = FutureProvider.autoDispose
    .family<Cached<Map<String, dynamic>>, RegisterQuery>((ref, query) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);
  final date = Dates.apiDate(query.date);

  return cachedGet<Map<String, dynamic>>(
    cache: cache,
    key: 'register:${query.classId}:${query.termId}:$date',
    fetch: () => api.get<Map<String, dynamic>>(
      Api.attendanceRegister,
      query: {
        'class_id': query.classId,
        'term_id': query.termId,
        'date': date,
      },
    ),
  );
});

/// Roll call.
///
/// The screen the offline machinery exists for. A teacher marks 34 children in
/// a classroom with no signal and taps submit once; the write goes to the
/// outbox with an idempotency key minted when the register was opened, and
/// `/attendance/bulk` deduplicates on that key when it eventually arrives.
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({
    super.key,
    required this.classId,
    required this.termId,
    required this.className,
    this.date,
  });

  final int classId;
  final int termId;
  final String className;
  final DateTime? date;

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  /// Minted when the register is opened, not when it is submitted, so a double
  /// tap and a retried request carry the same key.
  late final String _idempotencyKey = Outbox.newKey();

  final Map<int, AttendanceStatus> _marks = {};
  bool _submitting = false;
  bool _submitted = false;

  late final RegisterQuery _query = RegisterQuery(
    classId: widget.classId,
    termId: widget.termId,
    date: widget.date ?? DateTime.now(),
  );

  void _mark(int studentId, AttendanceStatus status) {
    setState(() => _marks[studentId] = status);
  }

  void _markAllPresent(List<Map<String, dynamic>> students) {
    setState(() {
      for (final s in students) {
        _marks[intOf(s['id'] ?? s['student_id'])] = AttendanceStatus.present;
      }
    });
  }

  Future<void> _submit(List<Map<String, dynamic>> students) async {
    if (_marks.isEmpty) return;
    setState(() => _submitting = true);

    final records = [
      for (final entry in _marks.entries)
        {'student_id': entry.key, 'status': entry.value.wire},
    ];

    final body = {
      'term_id': widget.termId,
      'date': Dates.apiDate(_query.date),
      'records': records,
      'idempotency_key': _idempotencyKey,
    };

    // Straight to the outbox, online or not. The queue is the single path for
    // this write, so the online and offline cases cannot drift apart — and a
    // register submitted as the signal drops is already safe.
    await ref.read(outboxProvider).enqueue(
          method: 'POST',
          path: Api.attendanceBulk,
          label: 'Register · ${widget.className} · ${Dates.dayMonth(_query.date)}',
          body: body,
          idempotencyKey: _idempotencyKey,
        );

    final sync = ref.read(syncServiceProvider);
    await sync.refreshCounts();
    unawaited(sync.drain());

    if (!mounted) return;
    setState(() {
      _submitting = false;
      _submitted = true;
    });

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Register saved')),
    );
  }

  @override
  Widget build(BuildContext context) {
    final register = ref.watch(registerProvider(_query));

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text('${widget.className} · Register', style: AppText.headlineSm),
            Text(
              Dates.dayMonth(_query.date),
              style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
            ),
          ],
        ),
      ),
      body: CachedView<Map<String, dynamic>>(
        value: register,
        onRetry: () => ref.invalidate(registerProvider(_query)),
        builder: (context, data, meta) {
          final students = listOf(data, ['students', 'register', 'records']);

          if (students.isEmpty) {
            return const EmptyState(
              icon: Icons.groups_outlined,
              title: 'No students in this class',
              message:
                  'Ask your administrator to add students to this class before '
                  'taking a register.',
            );
          }

          // Whatever the server already has for today pre-fills the marks, so
          // a teacher correcting one child does not have to re-mark 33 others.
          for (final s in students) {
            final id = intOf(s['id'] ?? s['student_id']);
            final existing = s['status'] ?? s['attendance']?['status'];
            if (existing != null && !_marks.containsKey(id)) {
              _marks[id] = AttendanceStatus.values.firstWhere(
                (v) => v.wire == existing.toString(),
                orElse: () => AttendanceStatus.present,
              );
            }
          }

          return Column(
            children: [
              _Tallies(marks: _marks, total: students.length),
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.screenMargin,
                  vertical: AppSpacing.sm,
                ),
                child: SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: _submitted ? null : () => _markAllPresent(students),
                    icon: const Icon(Icons.done_all, size: 18),
                    label: const Text('Mark all present'),
                  ),
                ),
              ),
              const Divider(height: 1),
              Expanded(
                child: ListView.separated(
                  itemCount: students.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final student = students[i];
                    final id = intOf(student['id'] ?? student['student_id']);
                    return _StudentRow(
                      name: stringOf(
                        student['name'] ??
                            student['full_name'] ??
                            student['user']?['name'],
                        'Student',
                      ),
                      admissionNumber: stringOf(
                        student['admission_number'] ?? student['adm_no'],
                      ),
                      status: _marks[id],
                      enabled: !_submitted,
                      onChanged: (status) => _mark(id, status),
                    );
                  },
                ),
              ),
              BottomActionBar(
                caption: Column(
                  children: [
                    Text(
                      '${_marks.length} of ${students.length} marked',
                      style: AppText.labelLg,
                    ),
                    if (_marks.length < students.length)
                      Text(
                        '${students.length - _marks.length} students pending',
                        style: AppText.labelSm.copyWith(color: AppColors.absent),
                      ),
                  ],
                ),
                child: FilledButton(
                  onPressed: _submitting || _submitted || _marks.isEmpty
                      ? null
                      : () => _submit(students),
                  child: Text(_submitted ? 'Register saved' : 'Submit register'),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _Tallies extends StatelessWidget {
  const _Tallies({required this.marks, required this.total});

  final Map<int, AttendanceStatus> marks;
  final int total;

  @override
  Widget build(BuildContext context) {
    int count(AttendanceStatus s) =>
        marks.values.where((v) => v == s).length;

    return Padding(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.screenMargin,
        vertical: AppSpacing.sm,
      ),
      child: Wrap(
        spacing: AppSpacing.sm,
        runSpacing: AppSpacing.sm,
        children: [
          for (final status in AttendanceStatus.values)
            StatusChip(
              '${_label(status)}: ${count(status)}',
              color: status.colour,
            ),
        ],
      ),
    );
  }

  static String _label(AttendanceStatus s) =>
      s.wire[0].toUpperCase() + s.wire.substring(1);
}

/// One child.
///
/// The status control is full width beneath the name and 48dp tall. The design
/// set drew it as a 36px strip beside the name, which collapsed to unusable
/// glyphs on a 360dp screen — see `docs/mobile-app.md` §B13.
class _StudentRow extends StatelessWidget {
  const _StudentRow({
    required this.name,
    required this.admissionNumber,
    required this.status,
    required this.enabled,
    required this.onChanged,
  });

  final String name;
  final String admissionNumber;
  final AttendanceStatus? status;
  final bool enabled;
  final ValueChanged<AttendanceStatus> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: status?.colour.withValues(alpha: 0.04),
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.screenMargin,
        vertical: AppSpacing.sm + 2,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              InitialsAvatar(name, size: 36, color: status?.colour),
              const SizedBox(width: AppSpacing.sm + 4),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(name, style: AppText.bodyLg, maxLines: 1),
                    if (admissionNumber.isNotEmpty)
                      Text(
                        admissionNumber,
                        style: AppText.labelSm
                            .copyWith(color: AppColors.onSurfaceVariant),
                      ),
                  ],
                ),
              ),
              if (status == null)
                const StatusChip('Unmarked', color: AppColors.absent),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          _StatusSelector(
            value: status,
            enabled: enabled,
            onChanged: onChanged,
          ),
        ],
      ),
    );
  }
}

class _StatusSelector extends StatelessWidget {
  const _StatusSelector({
    required this.value,
    required this.enabled,
    required this.onChanged,
  });

  final AttendanceStatus? value;
  final bool enabled;
  final ValueChanged<AttendanceStatus> onChanged;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: AppSpacing.tapTarget,
      child: Row(
        children: [
          for (final status in AttendanceStatus.values) ...[
            if (status != AttendanceStatus.present)
              const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: _SegmentButton(
                label: status.letter,
                caption: _caption(status),
                selected: value == status,
                colour: status.colour,
                onTap: enabled ? () => onChanged(status) : null,
              ),
            ),
          ],
        ],
      ),
    );
  }

  static String _caption(AttendanceStatus s) => switch (s) {
        AttendanceStatus.present => 'Present',
        AttendanceStatus.absent => 'Absent',
        AttendanceStatus.late => 'Late',
        AttendanceStatus.excused => 'Excused',
      };
}

class _SegmentButton extends StatelessWidget {
  const _SegmentButton({
    required this.label,
    required this.caption,
    required this.selected,
    required this.colour,
    required this.onTap,
  });

  final String label;
  final String caption;
  final bool selected;
  final Color colour;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      label: caption,
      child: Material(
        color: selected ? colour : AppColors.surfaceContainerLowest,
        borderRadius: BorderRadius.circular(999),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(999),
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(999),
              border: Border.all(
                color: selected ? colour : AppColors.outlineVariant,
              ),
            ),
            alignment: Alignment.center,
            child: Text(
              label,
              style: AppText.labelLg.copyWith(
                color: selected ? Colors.white : AppColors.onSurfaceVariant,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
