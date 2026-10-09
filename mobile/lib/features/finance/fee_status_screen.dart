import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// Who has paid and who is owing for a term, by section, class and arm.
///
/// One fetch per term and section; picking a class, an arm or a status
/// narrows what is already loaded, so moving around costs no data.
final feeStatusProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, ({int termId, String? section})>((ref, args) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.feeStatus, query: {
    'term_id': args.termId,
    if (args.section != null) 'section': args.section,
  }));
});

const _statuses = <(String, String, Color)>[
  ('paid', 'Paid', AppColors.paid),
  ('part_paid', 'Part paid', AppColors.pending),
  ('owing', 'Owing', AppColors.overdue),
  ('not_invoiced', 'Not invoiced', AppColors.onSurfaceVariant),
];

class FeeStatusScreen extends ConsumerStatefulWidget {
  const FeeStatusScreen({super.key});

  @override
  ConsumerState<FeeStatusScreen> createState() => _FeeStatusScreenState();
}

class _FeeStatusScreenState extends ConsumerState<FeeStatusScreen> {
  int? _termId;
  String? _section;
  int? _classId;
  int? _armId; // 0 = students with no arm
  String? _status;

  @override
  Widget build(BuildContext context) {
    final terms = ref.watch(termsProvider).valueOrNull ?? const <TermRef>[];
    _termId ??= (terms.where((t) => t.isCurrent).firstOrNull ?? terms.firstOrNull)?.id;

    return Scaffold(
      appBar: AppBar(title: const Text('Who has paid')),
      body: _termId == null
          ? const LoadingState(rows: 4)
          : _body(terms, ref.watch(feeStatusProvider((termId: _termId!, section: _section)))),
    );
  }

  Widget _body(List<TermRef> terms, AsyncValue<Map<String, dynamic>> report) {
    final args = (termId: _termId!, section: _section);

    return Column(
      children: [
        SizedBox(
          height: 52,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin, vertical: 6),
            children: [
              if (terms.length > 1)
                Padding(
                  padding: const EdgeInsets.only(right: AppSpacing.sm),
                  child: DropdownButton<int>(
                    value: _termId,
                    underline: const SizedBox.shrink(),
                    items: [for (final t in terms) DropdownMenuItem(value: t.id, child: Text(t.name))],
                    onChanged: (v) => setState(() {
                      _termId = v;
                      _classId = null;
                      _armId = null;
                    }),
                  ),
                ),
              for (final (key, label) in const [(null, 'All'), ('nursery', 'Nursery'), ('primary', 'Primary'), ('secondary', 'Secondary')])
                Padding(
                  padding: const EdgeInsets.only(right: AppSpacing.sm),
                  child: ChoiceChip(
                    label: Text(label),
                    selected: _section == key,
                    showCheckmark: false,
                    onSelected: (_) => setState(() {
                      _section = key;
                      _classId = null;
                      _armId = null;
                    }),
                  ),
                ),
            ],
          ),
        ),
        Expanded(
          child: report.when(
            loading: () => const LoadingState(rows: 5),
            error: (error, _) => ErrorState(error: asApiException(error), onRetry: () => ref.invalidate(feeStatusProvider(args))),
            data: (data) => _report(data, args),
          ),
        ),
      ],
    );
  }

  Widget _report(Map<String, dynamic> data, ({int termId, String? section}) args) {
    final classes = listOf(data['classes']);
    final cls = classes.where((c) => intOf(c['class_id']) == _classId).firstOrNull;
    final arms = cls == null ? const <Map<String, dynamic>>[] : listOf(cls['arms']);
    final arm = arms.where((a) => intOf(a['arm_id']) == _armId).firstOrNull;
    final scope = arm ?? cls ?? mapOf(data['totals']);
    final counts = mapOf(scope['counts']);

    final students = listOf(data['students']).where((s) =>
        (_classId == null || intOf(s['class_id']) == _classId) &&
        (_armId == null || intOf(s['arm_id']) == _armId) &&
        (_status == null || stringOf(s['status']) == _status)).toList();

    return RefreshableList(
      onRefresh: () async => ref.refresh(feeStatusProvider(args).future),
      padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, 0, AppSpacing.screenMargin, AppSpacing.xl),
      children: [
        OutlinedCard(
          statusColor: AppColors.paid,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                [if (cls != null) stringOf(cls['class']), if (arm != null) stringOf(arm['arm'])].join(' ').ifEmpty('Whole school'),
                style: AppText.labelLg,
              ),
              const SizedBox(height: AppSpacing.sm),
              _figure('Billed', numOf(scope['billed']), AppColors.onSurface),
              _figure('Paid', numOf(scope['paid']), AppColors.paid),
              _figure('Outstanding', numOf(scope['outstanding']), AppColors.overdue),
              const SizedBox(height: AppSpacing.sm),
              Text(
                '${intOf(counts['paid'])} of ${intOf(scope['students'])} cleared'
                '${scope['collection_rate'] == null ? '' : ' · ${numOf(scope['collection_rate'])}% collected'}',
                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        DropdownButtonFormField<int?>(
          key: ValueKey('fee-class-${args.termId}-${args.section}'),
          initialValue: _classId,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Class'),
          items: [
            const DropdownMenuItem<int?>(value: null, child: Text('Every class')),
            for (final c in classes)
              DropdownMenuItem<int?>(
                value: intOf(c['class_id']),
                child: Text('${stringOf(c['class'])} · ${intOf(mapOf(c['counts'])['paid'])}/${intOf(c['students'])} paid'),
              ),
          ],
          onChanged: (v) => setState(() {
            _classId = v;
            _armId = null;
          }),
        ),
        if (arms.length > 1) ...[
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.sm,
            children: [
              for (final a in arms)
                ChoiceChip(
                  label: Text('${stringOf(a['arm'])} · ${intOf(mapOf(a['counts'])['paid'])}/${intOf(a['students'])}'),
                  selected: _armId == intOf(a['arm_id']),
                  showCheckmark: false,
                  onSelected: (on) => setState(() => _armId = on ? intOf(a['arm_id']) : null),
                ),
            ],
          ),
        ],
        const SizedBox(height: AppSpacing.sm),
        Wrap(
          spacing: AppSpacing.sm,
          children: [
            for (final (key, label, _) in _statuses)
              FilterChip(
                label: Text('$label (${intOf(counts[key])})'),
                selected: _status == key,
                showCheckmark: false,
                onSelected: (on) => setState(() => _status = on ? key : null),
              ),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        if (students.isEmpty)
          Padding(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Text('No students match.', style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
          ),
        for (final s in students) _studentTile(s),
      ],
    );
  }

  Widget _figure(String label, num amount, Color color) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(
          children: [
            Expanded(child: Text(label, style: AppText.bodyLg)),
            MoneyText(amount, size: 18, color: color),
          ],
        ),
      );

  Widget _studentTile(Map<String, dynamic> s) {
    final status = _statuses.firstWhere((x) => x.$1 == stringOf(s['status']), orElse: () => _statuses.last);
    final invoiced = status.$1 != 'not_invoiced';

    return ListTile(
      contentPadding: EdgeInsets.zero,
      title: Text(stringOf(s['name'], 'Student')),
      subtitle: Text([
        '${stringOf(s['class'])} ${stringOf(s['arm'])}'.trim(),
        stringOf(s['admission_number']),
      ].where((x) => x.isNotEmpty).join(' · ')),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          StatusChip(status.$2, color: status.$3),
          if (invoiced && numOf(s['balance']) > 0) MoneyText(numOf(s['balance']), size: 13, color: AppColors.overdue),
        ],
      ),
    );
  }
}

extension on String {
  String ifEmpty(String fallback) => isEmpty ? fallback : this;
}
