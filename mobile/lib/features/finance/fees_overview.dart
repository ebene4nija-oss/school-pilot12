import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
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
import '../dashboard/dashboard_providers.dart';
import 'fees_screen.dart';

/// Money received since [from], from the payments ledger's own total.
final collectedSinceProvider =
    FutureProvider.autoDispose.family<num, DateTime>((ref, from) async {
  final api = ref.watch(apiClientProvider);
  final data = mapOf(await api.get<dynamic>(Api.payments, query: {
    'status': 'successful',
    'from': Dates.apiDate(from),
    'per_page': 1,
  }));
  return numOf(data['total_received']);
});

/// The ageing bands `/finance/defaulters` sorts each bill into, oldest last.
const ageingBands = <String, String>{
  'not_yet_due': 'Not yet due',
  '1-30_days': '1–30 days',
  '31-60_days': '31–60 days',
  '61-90_days': '61–90 days',
  'over_90_days': 'Over 90 days',
};

Color _bandColour(String band) => switch (band) {
      'not_yet_due' => AppColors.outline,
      '1-30_days' => AppColors.pending,
      '31-60_days' => AppColors.late,
      _ => AppColors.overdue,
    };

enum _Sort { balance, overdue }

/// The money view for an administrator or bursar: what has come in, what is
/// owed and how old it is, who owes it — and reminders to those families in
/// two taps. Everything here was already served by `/finance/defaulters`,
/// `/finance/payments` and `/finance/defaulters/remind`; the app showed a
/// total and a list.
class FeesOverviewScreen extends ConsumerStatefulWidget {
  const FeesOverviewScreen({super.key});

  @override
  ConsumerState<FeesOverviewScreen> createState() => _FeesOverviewScreenState();
}

class _FeesOverviewScreenState extends ConsumerState<FeesOverviewScreen> {
  String? _class;
  String? _band;
  _Sort _sort = _Sort.balance;
  final _selected = <int>{};

  static DateTime get _today {
    final n = DateTime.now();
    return DateTime(n.year, n.month, n.day);
  }

  static DateTime get _weekStart => _today.subtract(Duration(days: _today.weekday - 1));
  static DateTime get _monthStart => DateTime(_today.year, _today.month);

  Future<void> _refresh() async {
    ref.invalidate(collectedSinceProvider);
    ref.invalidate(defaultersProvider);
    await ref.read(defaultersProvider.future);
  }

  Future<void> _call(String phone) async {
    final ok = await launchUrl(Uri(scheme: 'tel', path: phone));
    if (!ok && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Could not open the dialer. The number is $phone.')),
      );
    }
  }

  Future<void> _remind(List<Map<String, dynamic>> rows) async {
    final message = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _ReminderSheet(rows: rows),
    );
    if (message == null || !mounted) return;
    setState(_selected.clear);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final defaulters = ref.watch(defaultersProvider);
    final role = ref.watch(currentRoleProvider);
    // The principal dashboard carries the collection rate; a bursar cannot
    // read it, so the tile is simply left out for them.
    final revenue = role.isAdmin
        ? mapOf(ref.watch(dashboardProvider).valueOrNull?.data)['revenue']
        : null;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Fees'),
        actions: [
          IconButton(
            tooltip: 'Transfers to confirm',
            icon: const Icon(Icons.account_balance_outlined),
            onPressed: () => context.push('/fees/transfers'),
          ),
        ],
      ),
      body: CachedView<dynamic>(
        value: defaulters,
        onRetry: () => ref.invalidate(defaultersProvider),
        builder: (context, data, meta) {
          final summary = mapOf(data)['summary'];
          final all = listOf(data, ['defaulters', 'students']);
          final total = numOf(fieldOf(summary, 'total_outstanding')) > 0
              ? numOf(fieldOf(summary, 'total_outstanding'))
              : all.fold<num>(0, (s, r) => s + numOf(r['balance'] ?? r['outstanding']));
          final students = intOf(fieldOf(summary, 'defaulting_students')) > 0
              ? intOf(fieldOf(summary, 'defaulting_students'))
              : all.map((r) => r['student_id']).toSet().length;
          final ageing = mapOf(fieldOf(summary, 'by_ageing'));

          final classes = {for (final r in all) stringOf(r['class'])}..remove('');
          final shown = all
              .where((r) => _class == null || stringOf(r['class']) == _class)
              .where((r) => _band == null || stringOf(r['ageing_bucket']) == _band)
              .toList()
            ..sort((a, b) => _sort == _Sort.balance
                ? numOf(b['balance']).compareTo(numOf(a['balance']))
                : intOf(b['days_overdue']).compareTo(intOf(a['days_overdue'])));
          final shownTotal = shown.fold<num>(0, (s, r) => s + numOf(r['balance']));
          final picked = shown.where((r) => _selected.contains(intOf(r['invoice_id']))).toList();

          return Column(
            children: [
              Expanded(
                child: RefreshableList(
                  onRefresh: _refresh,
                  padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, 0, AppSpacing.screenMargin, AppSpacing.xl),
                  children: [
                    FreshnessLine(fetchedAt: meta.fetchedAt, onRefresh: () => ref.invalidate(defaultersProvider)),
                    // ---------------------------------------------- collected
                    Text('COLLECTED', style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant, letterSpacing: 0.6)),
                    const SizedBox(height: AppSpacing.sm),
                    Row(
                      children: [
                        Expanded(child: _Collected(label: 'Today', from: _today)),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(child: _Collected(label: 'This week', from: _weekStart)),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(child: _Collected(label: 'This month', from: _monthStart)),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.md),
                    // --------------------------------------------- outstanding
                    OutlinedCard(
                      statusColor: total > 0 ? AppColors.overdue : AppColors.paid,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('OUTSTANDING', style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant, letterSpacing: 0.6)),
                          const SizedBox(height: AppSpacing.sm),
                          MoneyText(total, size: 26, color: total > 0 ? AppColors.overdue : AppColors.paid),
                          Text(
                            [
                              '$students ${students == 1 ? 'student owes' : 'students owe'}',
                              if (fieldOf(revenue, 'fee_recovery_rate_percentage') != null)
                                '${numOf(fieldOf(revenue, 'fee_recovery_rate_percentage')).toStringAsFixed(0)}% of all fees billed collected',
                            ].join(' · '),
                            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                          ),
                        ],
                      ),
                    ),
                    // ------------------------------------------------- ageing
                    if (ageing.isNotEmpty) ...[
                      const SectionHeader('How overdue'),
                      for (final band in ageingBands.keys)
                        if (ageing[band] != null)
                          _AgeingRow(
                            label: ageingBands[band]!,
                            amount: numOf(fieldOf(ageing[band], 'amount')),
                            invoices: intOf(fieldOf(ageing[band], 'invoices')),
                            share: total > 0 ? numOf(fieldOf(ageing[band], 'amount')) / total : 0,
                            colour: _bandColour(band),
                            selected: _band == band,
                            onTap: () => setState(() => _band = _band == band ? null : band),
                          ),
                    ],
                    // ------------------------------------------------ who owes
                    SectionHeader(
                      'Who owes (${shown.length})',
                      action: _sort == _Sort.balance ? 'Most overdue first' : 'Largest first',
                      onAction: () => setState(() => _sort = _sort == _Sort.balance ? _Sort.overdue : _Sort.balance),
                    ),
                    if (classes.length > 1)
                      SizedBox(
                        height: 44,
                        child: ListView(
                          scrollDirection: Axis.horizontal,
                          children: [
                            _Chip(label: 'All classes', selected: _class == null, onTap: () => setState(() => _class = null)),
                            for (final c in classes.toList()..sort())
                              _Chip(label: c, selected: _class == c, onTap: () => setState(() => _class = c)),
                          ],
                        ),
                      ),
                    if (_band != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: InputChip(
                          label: Text(ageingBands[_band] ?? ''),
                          onDeleted: () => setState(() => _band = null),
                        ),
                      ),
                    if (shown.isEmpty)
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: AppSpacing.lg),
                        child: Text('Nobody owes in this selection.', style: AppText.bodyMd),
                      ),
                    for (final row in shown)
                      _DebtorRow(
                        row: row,
                        selected: _selected.contains(intOf(row['invoice_id'])),
                        onSelect: (on) => setState(() {
                          final id = intOf(row['invoice_id']);
                          on ? _selected.add(id) : _selected.remove(id);
                        }),
                        onCall: _call,
                        onOpen: intOf(row['student_id']) == 0
                            ? null
                            : () => context.push(
                                  '/fees/student/${intOf(row['student_id'])}',
                                  extra: stringOf(row['student_name']),
                                ),
                      ),
                  ],
                ),
              ),
              if (shown.isNotEmpty)
                BottomActionBar(
                  caption: Text(
                    picked.isEmpty
                        ? '${shown.length} ${shown.length == 1 ? 'bill' : 'bills'} · ${Money.whole(shownTotal)} in this view'
                        : '${picked.length} selected · ${Money.whole(picked.fold<num>(0, (s, r) => s + numOf(r['balance'])))}',
                    style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
                  ),
                  child: FilledButton.icon(
                    onPressed: () => _remind(picked.isEmpty ? shown : picked),
                    icon: const Icon(Icons.notifications_active_outlined),
                    label: Text(picked.isEmpty ? 'Remind all ${shown.length}' : 'Remind ${picked.length} selected'),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _Collected extends ConsumerWidget {
  const _Collected({required this.label, required this.from});

  final String label;
  final DateTime from;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(collectedSinceProvider(from));
    return OutlinedCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant)),
          const SizedBox(height: 4),
          value.when(
            data: (n) => FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: MoneyText(n, size: 16, color: AppColors.paid),
            ),
            loading: () => const SizedBox(height: 20, child: LinearProgressIndicator()),
            error: (_, __) => const Text('—'),
          ),
        ],
      ),
    );
  }
}

class _AgeingRow extends StatelessWidget {
  const _AgeingRow({
    required this.label,
    required this.amount,
    required this.invoices,
    required this.share,
    required this.colour,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final num amount;
  final int invoices;
  final num share;
  final Color colour;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: AppRadius.cardRadius,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
        decoration: selected
            ? BoxDecoration(color: AppColors.surfaceContainerLow, borderRadius: AppRadius.cardRadius)
            : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text('$label · $invoices', style: AppText.bodyMd)),
                MoneyText(amount, size: 14, color: colour),
              ],
            ),
            const SizedBox(height: 4),
            ProgressBar(value: share.toDouble().clamp(0, 1), color: colour, height: 6),
          ],
        ),
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({required this.label, required this.selected, required this.onTap});

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: AppSpacing.sm),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        showCheckmark: false,
        selectedColor: AppColors.primaryContainer,
        labelStyle: AppText.labelSm.copyWith(color: selected ? AppColors.onPrimary : AppColors.onSurface),
        onSelected: (_) => onTap(),
      ),
    );
  }
}

class _DebtorRow extends StatelessWidget {
  const _DebtorRow({
    required this.row,
    required this.selected,
    required this.onSelect,
    required this.onCall,
    this.onOpen,
  });

  final Map<String, dynamic> row;
  final bool selected;
  final ValueChanged<bool> onSelect;
  final ValueChanged<String> onCall;
  final VoidCallback? onOpen;

  @override
  Widget build(BuildContext context) {
    final days = intOf(row['days_overdue']);
    final phone = listOf(row['contacts'])
        .map((c) => stringOf(c['phone']))
        .firstWhere((p) => p.isNotEmpty, orElse: () => '');

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: OutlinedCard(
        statusColor: days > 60 ? AppColors.overdue : days > 0 ? AppColors.late : AppColors.outline,
        onTap: onOpen,
        child: Row(
          children: [
            Checkbox(value: selected, onChanged: (v) => onSelect(v ?? false)),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(stringOf(row['student_name'], 'Student'), style: AppText.bodyLg, maxLines: 1, overflow: TextOverflow.ellipsis),
                  Text(
                    [
                      stringOf(row['class']),
                      days > 0 ? '$days days overdue' : 'not yet due',
                    ].where((s) => s.isNotEmpty).join(' · '),
                    style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
                  ),
                ],
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                MoneyText(numOf(row['balance']), size: 14, color: AppColors.overdue),
                Text('of ${Money.whole(numOf(row['total_amount']))}', style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant)),
              ],
            ),
            IconButton(
              tooltip: phone.isEmpty ? 'No phone number on file' : 'Call $phone',
              icon: const Icon(Icons.call_outlined),
              onPressed: phone.isEmpty ? null : () => onCall(phone),
            ),
          ],
        ),
      ),
    );
  }
}

/// Reminders to the families of the chosen bills. In-app is free and on by
/// default; SMS and WhatsApp cost the school money, so they are opt-in and
/// the sheet says so.
class _ReminderSheet extends ConsumerStatefulWidget {
  const _ReminderSheet({required this.rows});

  final List<Map<String, dynamic>> rows;

  @override
  ConsumerState<_ReminderSheet> createState() => _ReminderSheetState();
}

class _ReminderSheetState extends ConsumerState<_ReminderSheet> {
  final _note = TextEditingController();
  final _channels = <String>{'push'};
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.defaultersRemind,
        body: {
          'invoice_ids': [for (final r in widget.rows) intOf(r['invoice_id'])],
          'channels': _channels.toList(),
          if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
        },
      ));
      final queued = intOf(res['reminders_queued']);
      final unreachable = listOf(res['unreachable']).length;
      if (!mounted) return;
      Navigator.pop(
        context,
        [
          queued > 0
              // Queued, not delivered: a background worker sends them.
              ? 'Reminders on their way to $queued ${queued == 1 ? 'parent' : 'parents'}.'
              : stringOf(res['message'], 'Nothing was sent.'),
          if (unreachable > 0) '$unreachable could not be reached — no parent on file.',
        ].join(' '),
      );
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _sending = false;
        _error = asApiException(error).message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final total = widget.rows.fold<num>(0, (s, r) => s + numOf(r['balance']));
    final families = widget.rows.map((r) => r['student_id']).toSet().length;

    Widget channel(String key, String label, String detail) => CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          value: _channels.contains(key),
          onChanged: _sending
              ? null
              : (on) => setState(() => on == true ? _channels.add(key) : _channels.remove(key)),
          title: Text(label),
          subtitle: Text(detail),
        );

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          AppSpacing.lg,
          AppSpacing.lg,
          AppSpacing.lg,
          AppSpacing.lg + MediaQuery.of(context).viewInsets.bottom,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Remind parents', style: AppText.headlineSm),
              const SizedBox(height: 4),
              Text(
                '$families ${families == 1 ? 'family' : 'families'} · ${Money.format(total)} owed. '
                'Each parent is told their own child\'s balance.',
                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
              const SizedBox(height: AppSpacing.sm),
              channel('push', 'In-app notification', 'Free'),
              channel('sms', 'SMS', 'Charged to the school per message'),
              channel('whatsapp', 'WhatsApp', 'Charged to the school per message'),
              TextField(
                controller: _note,
                maxLength: 300,
                minLines: 1,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Add a note (optional)',
                  hintText: 'e.g. Please pay before resumption on Monday.',
                ),
              ),
              if (_error != null)
                Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
              const SizedBox(height: AppSpacing.md),
              FilledButton.icon(
                onPressed: _sending || _channels.isEmpty ? null : _send,
                icon: _sending
                    ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.send),
                label: Text('Send to $families ${families == 1 ? 'family' : 'families'}'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
