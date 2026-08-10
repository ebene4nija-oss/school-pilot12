import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

class BroadsheetQuery {
  const BroadsheetQuery({required this.classId, required this.termId});

  final int classId;
  final int termId;

  @override
  bool operator ==(Object other) =>
      other is BroadsheetQuery &&
      other.classId == classId &&
      other.termId == termId;

  @override
  int get hashCode => Object.hash(classId, termId);
}

final broadsheetProvider = FutureProvider.autoDispose
    .family<Cached<Map<String, dynamic>>, BroadsheetQuery>((ref, q) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<Map<String, dynamic>>(
    cache: cache,
    key: 'broadsheet:${q.classId}:${q.termId}',
    fetch: () => api.get<Map<String, dynamic>>(
      Api.broadsheet,
      query: {'class_id': q.classId, 'term_id': q.termId},
    ),
  );
});

/// Score entry for one subject in one class.
///
/// The columns are the ones the backend actually stores — 1st CA out of 20,
/// 2nd CA out of 20, exam out of 60 (`StoreScoreEntryRequest`). The design set
/// drew CA1/CA2/CA3/Exam/Total and pushed Total off a 360dp screen; three
/// inputs and a computed total fit, and match the server.
class ScoreEntryScreen extends ConsumerStatefulWidget {
  const ScoreEntryScreen({
    super.key,
    required this.subjectId,
    required this.subjectName,
    required this.classId,
    required this.termId,
  });

  final int subjectId;
  final String subjectName;
  final int classId;
  final int termId;

  @override
  ConsumerState<ScoreEntryScreen> createState() => _ScoreEntryScreenState();
}

class _ScoreEntryScreenState extends ConsumerState<ScoreEntryScreen> {
  final Map<int, _Row> _rows = {};
  bool _saving = false;
  bool _loaded = false;

  late final BroadsheetQuery _query =
      BroadsheetQuery(classId: widget.classId, termId: widget.termId);

  @override
  void dispose() {
    for (final row in _rows.values) {
      row.dispose();
    }
    super.dispose();
  }

  void _hydrate(List<Map<String, dynamic>> students) {
    if (_loaded) return;
    _loaded = true;

    for (final student in students) {
      final id = intOf(student['id'] ?? student['student_id']);
      final scores = student['scores'];
      Map<String, dynamic>? mine;

      if (scores is List) {
        for (final s in scores.whereType<Map>()) {
          if (intOf(s['subject_id']) == widget.subjectId) {
            mine = s.cast<String, dynamic>();
            break;
          }
        }
      }

      _rows[id] = _Row(
        name: stringOf(
          student['name'] ?? student['full_name'] ?? student['user']?['name'],
          'Student',
        ),
        firstCa: mine?['first_ca'],
        secondCa: mine?['second_ca'],
        exam: mine?['exam'],
      );
    }
  }

  int get _entered => _rows.values.where((r) => r.hasAnyValue).length;

  Future<void> _save() async {
    setState(() => _saving = true);

    final api = ref.read(apiClientProvider);
    final outbox = ref.read(outboxProvider);
    var queued = 0;
    var sent = 0;
    String? failure;

    for (final entry in _rows.entries) {
      final row = entry.value;
      if (!row.hasAnyValue || !row.dirty) continue;

      final body = {
        'term_id': widget.termId,
        'student_id': entry.key,
        'subject_id': widget.subjectId,
        'first_ca': row.value(row.firstCaController),
        'second_ca': row.value(row.secondCaController),
        'exam': row.value(row.examController),
      };

      try {
        await api.post<dynamic>(Api.assessmentScore, body: body);
        row.dirty = false;
        sent++;
      } catch (error) {
        final e = asApiException(error);
        if (e.isOffline) {
          // Queue the rest rather than losing an afternoon of marking.
          await outbox.enqueue(
            method: 'POST',
            path: Api.assessmentScore,
            label: '${widget.subjectName} · ${row.name}',
            body: body,
          );
          queued++;
        } else {
          failure ??= '${row.name}: ${e.message}';
        }
      }
    }

    if (queued > 0) {
      final sync = ref.read(syncServiceProvider);
      await sync.refreshCounts();
      unawaited(sync.drain());
    }

    if (!mounted) return;
    setState(() => _saving = false);

    final message = failure ??
        (queued > 0
            ? 'Saved $sent · $queued queued until you are back online'
            : 'Saved $sent ${sent == 1 ? 'score' : 'scores'}');

    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final broadsheet = ref.watch(broadsheetProvider(_query));

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(widget.subjectName, style: AppText.headlineSm),
            Text(
              'Scores are out of 100 in total',
              style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
            ),
          ],
        ),
      ),
      body: CachedView<Map<String, dynamic>>(
        value: broadsheet,
        onRetry: () => ref.invalidate(broadsheetProvider(_query)),
        builder: (context, data, meta) {
          final students = listOf(data, ['students', 'broadsheet', 'rows']);
          if (students.isEmpty) {
            return const EmptyState(
              icon: Icons.groups_outlined,
              title: 'No students in this class',
              message: 'There is nothing to mark yet.',
            );
          }

          _hydrate(students);

          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(AppSpacing.screenMargin),
                child: Column(
                  children: [
                    Row(
                      children: [
                        Text('Entered', style: AppText.labelSm),
                        const Spacer(),
                        NumericText('$_entered / ${students.length}'),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    ProgressBar(
                      value: students.isEmpty ? 0 : _entered / students.length,
                    ),
                  ],
                ),
              ),
              const _ColumnHeader(),
              Expanded(
                child: ListView.separated(
                  itemCount: students.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final id = intOf(
                      students[i]['id'] ?? students[i]['student_id'],
                    );
                    final row = _rows[id];
                    if (row == null) return const SizedBox.shrink();
                    return _ScoreRow(
                      row: row,
                      onChanged: () => setState(() {}),
                    );
                  },
                ),
              ),
              BottomActionBar(
                child: FilledButton(
                  onPressed: _saving ? null : _save,
                  child: Text(_saving ? 'Saving…' : 'Submit scores'),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _ColumnHeader extends StatelessWidget {
  const _ColumnHeader();

  @override
  Widget build(BuildContext context) {
    Widget cell(String label, String outOf) => Expanded(
          child: Column(
            children: [
              Text(label, style: AppText.labelSm),
              Text(
                outOf,
                style:
                    AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
          ),
        );

    return Container(
      color: AppColors.surfaceContainer,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.screenMargin,
        vertical: AppSpacing.sm,
      ),
      child: Row(
        children: [
          const Expanded(flex: 4, child: Text('Student', style: AppText.labelLg)),
          cell('1st CA', '/20'),
          cell('2nd CA', '/20'),
          cell('Exam', '/60'),
          cell('Total', '/100'),
        ],
      ),
    );
  }
}

class _ScoreRow extends StatelessWidget {
  const _ScoreRow({required this.row, required this.onChanged});

  final _Row row;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final total = row.total;
    final belowPass = total > 0 && total < 40;

    return Padding(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.screenMargin,
        vertical: AppSpacing.xs,
      ),
      child: Row(
        children: [
          Expanded(
            flex: 4,
            child: Text(
              row.name,
              style: AppText.bodyMd,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          _ScoreField(
            controller: row.firstCaController,
            max: 20,
            onChanged: () {
              row.dirty = true;
              onChanged();
            },
          ),
          _ScoreField(
            controller: row.secondCaController,
            max: 20,
            onChanged: () {
              row.dirty = true;
              onChanged();
            },
          ),
          _ScoreField(
            controller: row.examController,
            max: 60,
            onChanged: () {
              row.dirty = true;
              onChanged();
            },
          ),
          Expanded(
            child: Container(
              height: AppSpacing.tapTarget,
              alignment: Alignment.center,
              color: AppColors.surfaceContainerLow,
              child: NumericText(
                total == 0 ? '–' : total.toStringAsFixed(0),
                weight: FontWeight.w700,
                color: belowPass ? AppColors.absent : AppColors.onSurface,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ScoreField extends StatelessWidget {
  const _ScoreField({
    required this.controller,
    required this.max,
    required this.onChanged,
  });

  final TextEditingController controller;
  final num max;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 2),
        child: SizedBox(
          height: AppSpacing.tapTarget,
          child: TextField(
            controller: controller,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textAlign: TextAlign.center,
            style: AppText.numeric,
            inputFormatters: [
              FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
            ],
            onChanged: (_) => onChanged(),
            decoration: InputDecoration(
              isDense: true,
              hintText: '–',
              contentPadding: const EdgeInsets.symmetric(horizontal: 2),
              // Out-of-range is caught here rather than at submit, because a
              // 422 arriving after 34 students have been marked is useless.
              errorText: _tooHigh ? '' : null,
              errorStyle: const TextStyle(height: 0, fontSize: 0),
            ),
          ),
        ),
      ),
    );
  }

  bool get _tooHigh {
    final value = num.tryParse(controller.text);
    return value != null && value > max;
  }
}

class _Row {
  _Row({
    required this.name,
    Object? firstCa,
    Object? secondCa,
    Object? exam,
  })  : firstCaController = TextEditingController(text: _text(firstCa)),
        secondCaController = TextEditingController(text: _text(secondCa)),
        examController = TextEditingController(text: _text(exam));

  final String name;
  final TextEditingController firstCaController;
  final TextEditingController secondCaController;
  final TextEditingController examController;
  bool dirty = false;

  static String _text(Object? value) {
    if (value == null) return '';
    final n = numOf(value, -1);
    if (n < 0) return '';
    return n == n.roundToDouble() ? n.toInt().toString() : n.toString();
  }

  num? value(TextEditingController c) =>
      c.text.trim().isEmpty ? null : num.tryParse(c.text.trim());

  bool get hasAnyValue =>
      firstCaController.text.isNotEmpty ||
      secondCaController.text.isNotEmpty ||
      examController.text.isNotEmpty;

  num get total =>
      (value(firstCaController) ?? 0) +
      (value(secondCaController) ?? 0) +
      (value(examController) ?? 0);

  void dispose() {
    firstCaController.dispose();
    secondCaController.dispose();
    examController.dispose();
  }
}
