import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// Downloads a payment's receipt PDF and opens the share sheet, so the bursar
/// can send it to the parent on WhatsApp or by email there and then.
Future<void> shareReceipt(BuildContext context, WidgetRef ref, int paymentId, String receiptNumber) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    final bytes = await ref.read(apiClientProvider).getBytes(Api.paymentReceipt(paymentId));
    final dir = await getTemporaryDirectory();
    final file = File(p.join(dir.path, '${receiptNumber.isEmpty ? 'receipt-$paymentId' : receiptNumber}.pdf'));
    await file.writeAsBytes(bytes, flush: true);
    await SharePlus.instance.share(ShareParams(
      files: [XFile(file.path, mimeType: 'application/pdf')],
      subject: 'School fees receipt $receiptNumber',
    ));
  } catch (error) {
    messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
  }
}

// ------------------------------------------------------------ payments ledger

enum _Period { today, week, month, all }

final ledgerProvider = FutureProvider.autoDispose
    .family<Map<String, dynamic>, ({String? from, String q})>((ref, args) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.payments, query: {
    if (args.from != null) 'from': args.from,
    if (args.q.isNotEmpty) 'q': args.q,
    'per_page': 100,
  }));
});

/// Every payment taken, newest first, with its receipt one tap away.
class PaymentsLedgerScreen extends ConsumerStatefulWidget {
  const PaymentsLedgerScreen({super.key});

  @override
  ConsumerState<PaymentsLedgerScreen> createState() => _PaymentsLedgerScreenState();
}

class _PaymentsLedgerScreenState extends ConsumerState<PaymentsLedgerScreen> {
  _Period _period = _Period.week;
  final _search = TextEditingController();
  String _q = '';

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  String? get _from {
    final n = DateTime.now();
    final today = DateTime(n.year, n.month, n.day);
    return switch (_period) {
      _Period.today => Dates.apiDate(today),
      _Period.week => Dates.apiDate(today.subtract(Duration(days: today.weekday - 1))),
      _Period.month => Dates.apiDate(DateTime(n.year, n.month)),
      _Period.all => null,
    };
  }

  @override
  Widget build(BuildContext context) {
    final args = (from: _from, q: _q);
    final ledger = ref.watch(ledgerProvider(args));

    return Scaffold(
      appBar: AppBar(title: const Text('Payments')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, 0),
            child: TextField(
              controller: _search,
              textInputAction: TextInputAction.search,
              onSubmitted: (v) => setState(() => _q = v.trim()),
              decoration: const InputDecoration(
                hintText: 'Search by student, reference or receipt',
                prefixIcon: Icon(Icons.search),
                isDense: true,
              ),
            ),
          ),
          SizedBox(
            height: 48,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin, vertical: 6),
              children: [
                for (final (period, label) in const [
                  (_Period.today, 'Today'),
                  (_Period.week, 'This week'),
                  (_Period.month, 'This month'),
                  (_Period.all, 'All'),
                ])
                  Padding(
                    padding: const EdgeInsets.only(right: AppSpacing.sm),
                    child: ChoiceChip(
                      label: Text(label),
                      selected: _period == period,
                      showCheckmark: false,
                      onSelected: (_) => setState(() => _period = period),
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: ledger.when(
              loading: () => const LoadingState(rows: 5),
              error: (error, _) => ErrorState(error: asApiException(error), onRetry: () => ref.invalidate(ledgerProvider(args))),
              data: (data) {
                final rows = listOf(data['data']);
                return RefreshableList(
                  onRefresh: () async => ref.refresh(ledgerProvider(args).future),
                  padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, 0, AppSpacing.screenMargin, AppSpacing.xl),
                  children: [
                    OutlinedCard(
                      statusColor: AppColors.paid,
                      child: Row(
                        children: [
                          Expanded(child: Text('Received', style: AppText.bodyLg)),
                          MoneyText(numOf(data['total_received']), size: 20, color: AppColors.paid),
                        ],
                      ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    if (rows.isEmpty)
                      Padding(
                        padding: const EdgeInsets.all(AppSpacing.lg),
                        child: Text('No payments in this period.', style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
                      ),
                    for (final r in rows)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(stringOf(r['student'], 'Payment')),
                        subtitle: Text(
                          [
                            stringOf(r['class']),
                            stringOf(r['gateway']).replaceAll('_', ' '),
                            Dates.dayAndTime(Dates.tryParse(r['paid_at'] ?? r['created_at'])),
                            if (stringOf(r['status']) != 'successful') stringOf(r['status']),
                          ].where((s) => s.isNotEmpty).join(' · '),
                        ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            MoneyText(numOf(r['amount']), size: 14, color: stringOf(r['status']) == 'successful' ? AppColors.paid : AppColors.onSurfaceVariant),
                            IconButton(
                              tooltip: 'Share receipt',
                              icon: const Icon(Icons.ios_share),
                              onPressed: stringOf(r['status']) != 'successful'
                                  ? null
                                  : () => shareReceipt(context, ref, intOf(r['payment_id']), stringOf(r['receipt_number'])),
                            ),
                          ],
                        ),
                      ),
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------- expenses and petty cash

final expensesProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  return mapOf(await ref.watch(apiClientProvider).get<dynamic>(Api.expenses));
});

final pettyCashProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  return mapOf(await ref.watch(apiClientProvider).get<dynamic>(Api.pettyCash));
});

const _methods = <String, String>{
  'cash': 'Cash',
  'bank_transfer': 'Bank transfer',
  'cheque': 'Cheque',
  'petty_cash': 'Petty cash',
};

/// Money going out: expenses and the petty-cash box.
class ExpensesScreen extends ConsumerWidget {
  const ExpensesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Expenses'),
          bottom: const TabBar(tabs: [Tab(text: 'Expenses'), Tab(text: 'Petty cash')]),
        ),
        body: const TabBarView(children: [_ExpensesTab(), _PettyCashTab()]),
      ),
    );
  }
}

class _ExpensesTab extends ConsumerWidget {
  const _ExpensesTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final expenses = ref.watch(expensesProvider);

    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'expense',
        onPressed: () async {
          final categories = (expenses.valueOrNull?['categories'] as List?)?.map(stringOf).toList() ?? const <String>[];
          final done = await showModalBottomSheet<String>(
            context: context,
            isScrollControlled: true,
            builder: (_) => _ExpenseSheet(categories: categories),
          );
          if (done != null && context.mounted) {
            ref.invalidate(expensesProvider);
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(done)));
          }
        },
        icon: const Icon(Icons.add),
        label: const Text('Record expense'),
      ),
      body: expenses.when(
        loading: () => const LoadingState(rows: 5),
        error: (error, _) => ErrorState(error: asApiException(error), onRetry: () => ref.invalidate(expensesProvider)),
        data: (data) {
          final rows = listOf(data['data']);
          return RefreshableList(
            onRefresh: () async => ref.refresh(expensesProvider.future),
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, 96),
            children: [
              OutlinedCard(
                statusColor: AppColors.overdue,
                child: Row(
                  children: [
                    Expanded(child: Text('Total spent', style: AppText.bodyLg)),
                    MoneyText(numOf(data['total']), size: 20, color: AppColors.overdue),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.sm),
              if (rows.isEmpty) Text('No expenses recorded yet.', style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
              for (final e in rows)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(stringOf(e['title'], 'Expense')),
                  subtitle: Text(
                    [
                      stringOf(e['category']),
                      _methods[stringOf(e['payment_method'])] ?? '',
                      Dates.dayMonth(Dates.tryParse(e['expense_date'])),
                    ].where((s) => s.isNotEmpty).join(' · '),
                  ),
                  trailing: MoneyText(numOf(e['amount']), size: 14),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _ExpenseSheet extends ConsumerStatefulWidget {
  const _ExpenseSheet({required this.categories});

  final List<String> categories;

  @override
  ConsumerState<_ExpenseSheet> createState() => _ExpenseSheetState();
}

class _ExpenseSheetState extends ConsumerState<_ExpenseSheet> {
  final _title = TextEditingController();
  final _category = TextEditingController();
  final _amount = TextEditingController();
  final _notes = TextEditingController();
  String _method = 'cash';
  DateTime _date = DateTime.now();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    for (final c in [_title, _category, _amount, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final amount = num.tryParse(_amount.text.replaceAll(',', '').trim());
    if (_title.text.trim().isEmpty || _category.text.trim().isEmpty || amount == null || amount <= 0) {
      setState(() => _error = 'What it was for, a category and the amount are needed.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(Api.expenses, body: {
        'title': _title.text.trim(),
        'category': _category.text.trim(),
        'amount': amount,
        'expense_date': Dates.apiDate(_date),
        'payment_method': _method,
        if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
      }));
      if (mounted) Navigator.pop(context, stringOf(res['message'], 'Expense recorded.'));
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = asApiException(error).message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(AppSpacing.lg, AppSpacing.lg, AppSpacing.lg, AppSpacing.lg + MediaQuery.of(context).viewInsets.bottom),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Record an expense', style: AppText.headlineSm),
              TextField(controller: _title, decoration: const InputDecoration(labelText: 'What for', hintText: 'e.g. Diesel for generator')),
              TextField(controller: _category, decoration: const InputDecoration(labelText: 'Category', hintText: 'e.g. Utilities')),
              if (widget.categories.isNotEmpty)
                Wrap(
                  spacing: 6,
                  children: [
                    for (final c in widget.categories.take(8))
                      ActionChip(label: Text(c), onPressed: () => setState(() => _category.text = c)),
                  ],
                ),
              TextField(
                controller: _amount,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'Amount (₦)'),
              ),
              DropdownButtonFormField<String>(
                initialValue: _method,
                decoration: const InputDecoration(labelText: 'Paid by'),
                items: [for (final e in _methods.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
                onChanged: (v) => setState(() => _method = v ?? 'cash'),
              ),
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Date'),
                subtitle: Text(Dates.full(_date)),
                onTap: () async {
                  final picked = await showDatePicker(context: context, initialDate: _date, firstDate: DateTime(DateTime.now().year - 1), lastDate: DateTime.now());
                  if (picked != null) setState(() => _date = picked);
                },
              ),
              TextField(controller: _notes, decoration: const InputDecoration(labelText: 'Notes (optional)')),
              if (_error != null) Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
              const SizedBox(height: AppSpacing.md),
              FilledButton(onPressed: _busy ? null : _save, child: Text(_busy ? 'Saving…' : 'Record expense')),
            ],
          ),
        ),
      ),
    );
  }
}

class _PettyCashTab extends ConsumerWidget {
  const _PettyCashTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final petty = ref.watch(pettyCashProvider);

    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'petty',
        onPressed: () async {
          final done = await showModalBottomSheet<String>(
            context: context,
            isScrollControlled: true,
            builder: (_) => const _PettyCashSheet(),
          );
          if (done != null && context.mounted) {
            ref.invalidate(pettyCashProvider);
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(done)));
          }
        },
        icon: const Icon(Icons.add),
        label: const Text('Log entry'),
      ),
      body: petty.when(
        loading: () => const LoadingState(rows: 5),
        error: (error, _) => ErrorState(error: asApiException(error), onRetry: () => ref.invalidate(pettyCashProvider)),
        data: (data) {
          final rows = listOf(data['data']);
          final balance = numOf(data['balance']);
          return RefreshableList(
            onRefresh: () async => ref.refresh(pettyCashProvider.future),
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, 96),
            children: [
              OutlinedCard(
                statusColor: balance >= 0 ? AppColors.paid : AppColors.overdue,
                child: Row(
                  children: [
                    Expanded(child: Text('In the box', style: AppText.bodyLg)),
                    MoneyText(balance, size: 20, color: balance >= 0 ? AppColors.paid : AppColors.overdue),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.sm),
              if (rows.isEmpty) Text('No petty cash entries yet.', style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
              for (final r in rows)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(
                    r['type'] == 'replenishment' ? Icons.add_circle_outline : Icons.remove_circle_outline,
                    color: r['type'] == 'replenishment' ? AppColors.paid : AppColors.overdue,
                  ),
                  title: Text(stringOf(r['purpose'])),
                  subtitle: Text([Dates.dayMonth(Dates.tryParse(r['entry_date'])), stringOf(r['custodian'])].where((s) => s.isNotEmpty).join(' · ')),
                  trailing: MoneyText(numOf(r['amount']), size: 14),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _PettyCashSheet extends ConsumerStatefulWidget {
  const _PettyCashSheet();

  @override
  ConsumerState<_PettyCashSheet> createState() => _PettyCashSheetState();
}

class _PettyCashSheetState extends ConsumerState<_PettyCashSheet> {
  final _amount = TextEditingController();
  final _purpose = TextEditingController();
  String _type = 'disbursement';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _amount.dispose();
    _purpose.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final amount = num.tryParse(_amount.text.replaceAll(',', '').trim());
    if (amount == null || amount <= 0 || _purpose.text.trim().isEmpty) {
      setState(() => _error = 'The amount and what it was for are needed.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(Api.pettyCash, body: {
        'type': _type,
        'amount': amount,
        'purpose': _purpose.text.trim(),
        'entry_date': Dates.apiDate(DateTime.now()),
      }));
      if (mounted) Navigator.pop(context, stringOf(res['message'], 'Logged.'));
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = asApiException(error).message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(AppSpacing.lg, AppSpacing.lg, AppSpacing.lg, AppSpacing.lg + MediaQuery.of(context).viewInsets.bottom),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Petty cash', style: AppText.headlineSm),
            const SizedBox(height: AppSpacing.sm),
            SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'disbursement', label: Text('Money out')),
                ButtonSegment(value: 'replenishment', label: Text('Topped up')),
              ],
              selected: {_type},
              onSelectionChanged: (s) => setState(() => _type = s.first),
            ),
            TextField(
              controller: _amount,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'Amount (₦)'),
            ),
            TextField(controller: _purpose, decoration: const InputDecoration(labelText: 'What for', hintText: 'e.g. Chalk and markers')),
            if (_error != null) Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            const SizedBox(height: AppSpacing.md),
            FilledButton(onPressed: _busy ? null : _save, child: Text(_busy ? 'Saving…' : 'Log entry')),
          ],
        ),
      ),
    );
  }
}

// ------------------------------------------------- scholarship on an invoice

final scholarshipsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  return listOf(await ref.watch(apiClientProvider).get<dynamic>(Api.scholarships));
});

/// Applies one of the school's scholarships to a bill. The server works out
/// the discount from the scholarship and records who applied it and why.
class ApplyScholarshipSheet extends ConsumerStatefulWidget {
  const ApplyScholarshipSheet({super.key, required this.invoice});

  final Map<String, dynamic> invoice;

  @override
  ConsumerState<ApplyScholarshipSheet> createState() => _ApplyScholarshipSheetState();
}

class _ApplyScholarshipSheetState extends ConsumerState<ApplyScholarshipSheet> {
  final _reason = TextEditingController();
  int? _scholarshipId;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _apply() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.applyDiscount(intOf(widget.invoice['invoice_id'] ?? widget.invoice['id'])),
        body: {
          'scholarship_id': _scholarshipId,
          if (_reason.text.trim().isNotEmpty) 'reason': _reason.text.trim(),
        },
      ));
      if (mounted) Navigator.pop(context, stringOf(res['message'], 'Scholarship applied.'));
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = asApiException(error).message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final scholarships = ref.watch(scholarshipsProvider);

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(AppSpacing.lg, AppSpacing.lg, AppSpacing.lg, AppSpacing.lg + MediaQuery.of(context).viewInsets.bottom),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Apply a scholarship', style: AppText.headlineSm),
            Text(
              '${stringOf(widget.invoice['invoice_number'])} · ${Money.format(numOf(widget.invoice['total_amount']))} billed',
              style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
            ),
            const SizedBox(height: AppSpacing.sm),
            scholarships.when(
              loading: () => const LinearProgressIndicator(),
              error: (e, _) => Text(asApiException(e).message, style: AppText.bodyMd.copyWith(color: AppColors.error)),
              data: (list) => list.isEmpty
                  ? Text('The school has no scholarships set up. Add them on the web under Scholarships.',
                      style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant))
                  : DropdownButtonFormField<int>(
                      initialValue: _scholarshipId,
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'Scholarship'),
                      items: [
                        for (final s in list)
                          DropdownMenuItem(
                            value: intOf(s['id']),
                            child: Text(
                              '${stringOf(s['name'], 'Scholarship')} · '
                              '${s['type'] == 'percentage' ? '${numOf(s['value']).toStringAsFixed(0)}% off' : '${Money.format(numOf(s['value']))} off'}',
                            ),
                          ),
                      ],
                      onChanged: (v) => setState(() => _scholarshipId = v),
                    ),
            ),
            TextField(controller: _reason, decoration: const InputDecoration(labelText: 'Reason (optional)')),
            if (_error != null) Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            const SizedBox(height: AppSpacing.md),
            FilledButton(
              onPressed: _busy || _scholarshipId == null ? null : _apply,
              child: Text(_busy ? 'Applying…' : 'Apply to this bill'),
            ),
          ],
        ),
      ),
    );
  }
}
