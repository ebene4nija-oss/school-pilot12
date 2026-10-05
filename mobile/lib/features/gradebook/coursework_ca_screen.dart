import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import 'score_entry_screen.dart';

/// The CA parts of the scheme in force for this class and term. Exams are
/// left out: they are entered directly, never filled from coursework.
List<Map<String, dynamic>> caPartsOf(Map<String, dynamic> broadsheet) {
  final scheme = mapOf(broadsheet['ca_scheme']);
  final parts = listOf(scheme['components']);
  final all = parts.isNotEmpty
      ? parts
      : [
          if (scheme.isNotEmpty) {'key': 'ca1', 'name': '1st CA', 'max': scheme['first_ca_weight'], 'kind': 'ca'},
          if (scheme.isNotEmpty) {'key': 'ca2', 'name': '2nd CA', 'max': scheme['second_ca_weight'], 'kind': 'ca'},
        ];
  return all.where((p) => stringOf(p['kind'], 'ca') == 'ca').toList();
}

/// Fill a CA from homework and quizzes (TASK 08, 21). The teacher picks the
/// work that counts, sees each student's suggested mark, changes any of them
/// and only then enters them. Online only: the server checks locks and the
/// scheme's maximum at the moment of entry.
class CourseworkCaScreen extends ConsumerStatefulWidget {
  const CourseworkCaScreen({
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
  ConsumerState<CourseworkCaScreen> createState() => _CourseworkCaScreenState();
}

class _CourseworkCaScreenState extends ConsumerState<CourseworkCaScreen> {
  Map<String, dynamic>? _sources;
  String? _component;
  final Set<int> _homework = {};
  final Set<int> _quizzes = {};
  String _missing = 'zero';
  List<Map<String, dynamic>>? _rows;
  Map<String, dynamic>? _part;
  final Map<int, TextEditingController> _marks = {};
  String? _error;
  String? _notice;
  bool _busy = false;

  Map<String, dynamic> get _scope => {
        'term_id': widget.termId,
        'class_id': widget.classId,
        'subject_id': widget.subjectId,
      };

  @override
  void initState() {
    super.initState();
    _loadSources();
  }

  @override
  void dispose() {
    for (final c in _marks.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _loadSources() async {
    try {
      final res = await ref.read(apiClientProvider).get<dynamic>(Api.courseworkSources, query: _scope);
      setState(() => _sources = mapOf(res));
    } catch (error) {
      setState(() {
        _sources = const {};
        _error = asApiException(error).message;
      });
    }
  }

  Future<void> _suggest() async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(Api.courseworkSuggest, body: {
        ..._scope,
        'component': _component,
        'homework_ids': _homework.toList(),
        'exam_ids': _quizzes.toList(),
        'missing': _missing,
      }));
      final rows = listOf(res['rows']);
      for (final c in _marks.values) {
        c.dispose();
      }
      _marks
        ..clear()
        ..addAll({
          for (final r in rows)
            intOf(r['student_id']): TextEditingController(text: r['suggested'] == null ? '' : _fmt(numOf(r['suggested']))),
        });
      setState(() {
        _rows = rows;
        _part = mapOf(res['part']);
      });
    } catch (error) {
      setState(() => _error = asApiException(error).message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _apply() async {
    final rows = _rows;
    if (rows == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(Api.courseworkApply, body: {
        ..._scope,
        'component': _component,
        'homework_ids': _homework.toList(),
        'exam_ids': _quizzes.toList(),
        'missing': _missing,
        'rows': [
          for (final r in rows)
            {
              'student_id': intOf(r['student_id']),
              'suggested': r['suggested'],
              'score': num.tryParse(_marks[intOf(r['student_id'])]?.text.trim() ?? ''),
            },
        ],
      }));
      final refused = listOf(res['refused']);
      final names = {for (final r in rows) intOf(r['student_id']): stringOf(r['name'])};
      setState(() => _notice = [
            stringOf(res['message']),
            for (final x in refused) '${names[intOf(x['student_id'])] ?? 'A student'}: ${stringOf(x['reason'])}',
          ].join('\n'));
      ref.invalidate(broadsheetProvider(BroadsheetQuery(classId: widget.classId, termId: widget.termId)));
    } catch (error) {
      setState(() => _error = asApiException(error).message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  static String _fmt(num n) => n == n.roundToDouble() ? n.toInt().toString() : n.toString();

  @override
  Widget build(BuildContext context) {
    final sheet = ref.watch(broadsheetProvider(BroadsheetQuery(classId: widget.classId, termId: widget.termId)));
    final parts = caPartsOf(sheet.valueOrNull?.data ?? const {});
    final sources = _sources;

    return Scaffold(
      appBar: AppBar(title: Text('${widget.subjectName} · CA from coursework')),
      body: sources == null
          ? const LoadingState()
          : ListView(
              padding: const EdgeInsets.all(AppSpacing.screenMargin),
              children: [
                if (_error != null) _banner(_error!, ok: false),
                if (_notice != null) _banner(_notice!, ok: true),
                Text('Fills which CA', style: AppText.labelLg),
                Wrap(
                  spacing: AppSpacing.sm,
                  children: [
                    for (final p in parts)
                      ChoiceChip(
                        label: Text('${stringOf(p['name'])} (/${numOf(p['max'])})'),
                        selected: _component == stringOf(p['key']),
                        onSelected: (_) => setState(() {
                          _component = stringOf(p['key']);
                          _rows = null;
                        }),
                      ),
                  ],
                ),
                if (parts.isEmpty) const Text('Loading the marking scheme…', style: AppText.bodyMd),
                const SectionHeader('Homework'),
                ..._checks(listOf(sources['homework']), _homework, (h) => '${stringOf(h['title'])} · ${Dates.dayMonth(Dates.tryParse(h['date']))}'),
                const SectionHeader('Quizzes and tests'),
                ..._checks(listOf(sources['quizzes']), _quizzes, (q) => stringOf(q['title'])),
                const SizedBox(height: AppSpacing.sm),
                Text('Work not handed in', style: AppText.labelLg),
                SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(value: 'zero', label: Text('Counts as 0')),
                    ButtonSegment(value: 'skip', label: Text('Left out')),
                  ],
                  selected: {_missing},
                  showSelectedIcon: false,
                  onSelectionChanged: (s) => setState(() {
                    _missing = s.first;
                    _rows = null;
                  }),
                ),
                const SizedBox(height: AppSpacing.md),
                FilledButton(
                  onPressed: _busy || _component == null || _homework.isEmpty && _quizzes.isEmpty ? null : _suggest,
                  child: const Text('Work out marks'),
                ),
                if (_rows != null) ..._marksList(),
              ],
            ),
    );
  }

  Widget _banner(String text, {required bool ok}) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.md),
        child: OutlinedCard(
          statusColor: ok ? AppColors.present : AppColors.absent,
          child: Text(text, style: AppText.bodyMd),
        ),
      );

  List<Widget> _checks(List<Map<String, dynamic>> items, Set<int> chosen, String Function(Map<String, dynamic>) label) {
    if (items.isEmpty) {
      return const [Text('None this term.', style: AppText.bodyMd)];
    }
    return [
      for (final i in items)
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          value: chosen.contains(intOf(i['id'])),
          title: Text(label(i)),
          subtitle: Text('${intOf(i['graded'])} marked'),
          onChanged: (on) => setState(() {
            on == true ? chosen.add(intOf(i['id'])) : chosen.remove(intOf(i['id']));
            _rows = null;
          }),
        ),
    ];
  }

  List<Widget> _marksList() {
    final rows = _rows!;
    final max = numOf(_part?['max']);

    return [
      const SectionHeader('Marks'),
      Text(
        'Change any mark you need to. A changed mark is highlighted; a blank one is not entered. '
        'Nothing reaches the score sheet until you press Enter marks.',
        style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
      ),
      const SizedBox(height: AppSpacing.sm),
      for (final r in rows)
        _MarkRow(
          row: r,
          controller: _marks[intOf(r['student_id'])]!,
          max: max,
          suggested: r['suggested'] == null ? '' : _fmt(numOf(r['suggested'])),
        ),
      const SizedBox(height: AppSpacing.md),
      FilledButton(onPressed: _busy ? null : _apply, child: const Text('Enter marks')),
      const SizedBox(height: AppSpacing.xl),
    ];
  }
}

class _MarkRow extends StatefulWidget {
  const _MarkRow({required this.row, required this.controller, required this.max, required this.suggested});

  final Map<String, dynamic> row;
  final TextEditingController controller;
  final num max;
  final String suggested;

  @override
  State<_MarkRow> createState() => _MarkRowState();
}

class _MarkRowState extends State<_MarkRow> {
  @override
  Widget build(BuildContext context) {
    final r = widget.row;
    final changed = widget.controller.text.trim() != widget.suggested;
    final unmarked = intOf(r['unmarked']);
    final detail = [
      if (r['average_percent'] != null) 'average ${numOf(r['average_percent'])}%',
      if (unmarked > 0) '$unmarked not yet marked',
      if (r['current'] != null) 'on sheet ${numOf(r['current'])}',
    ].join(' · ');

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(stringOf(r['name'], 'Student'), style: AppText.bodyLg),
                if (detail.isNotEmpty)
                  Text(detail, style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant)),
              ],
            ),
          ),
          SizedBox(
            width: 84,
            child: TextField(
              controller: widget.controller,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
              textAlign: TextAlign.center,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                isDense: true,
                suffixText: '/${widget.max == widget.max.roundToDouble() ? widget.max.toInt() : widget.max}',
                filled: changed,
                fillColor: AppColors.secondaryContainer.withValues(alpha: 0.35),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
