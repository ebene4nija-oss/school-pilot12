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
import 'fees_screen.dart';

/// One student's fees, read live: the bursar is about to take money against
/// it, so a cached balance is the wrong thing to show.
final bursarStatementProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, studentId) async {
  final res = await ref.watch(apiClientProvider).get<dynamic>(Api.studentStatement(studentId));
  return mapOf(res);
});

final pendingTransfersProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final res = await ref.watch(apiClientProvider).get<dynamic>(
    Api.payments,
    query: {'status': 'pending', 'gateway': 'bank_transfer', 'per_page': 100},
  );
  return listOf(res);
});

/// A student's invoices, with "Record payment" on any that still owe.
class BursarStudentScreen extends ConsumerWidget {
  const BursarStudentScreen({super.key, required this.studentId, this.name});

  final int studentId;
  final String? name;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final statement = ref.watch(bursarStatementProvider(studentId));

    return Scaffold(
      appBar: AppBar(title: Text(name ?? 'Student fees')),
      body: statement.when(
        loading: () => const LoadingState(),
        error: (e, _) => ErrorState(error: asApiException(e), onRetry: () => ref.invalidate(bursarStatementProvider(studentId))),
        data: (s) {
          final invoices = listOf(s['invoices']);
          return RefreshIndicator(
            onRefresh: () async => ref.refresh(bursarStatementProvider(studentId).future),
            child: ListView(
              padding: const EdgeInsets.all(AppSpacing.screenMargin),
              children: [
                Row(
                  children: [
                    Expanded(child: StatTile(label: 'Owing', value: Money.whole(numOf(s['balance'])))),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(child: StatTile(label: 'Paid', value: Money.whole(numOf(s['total_paid'])))),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                if (invoices.isEmpty) const EmptyState(icon: Icons.receipt_long_outlined, title: 'No invoices', message: 'Nothing has been billed to this student yet.'),
                for (final inv in invoices) _InvoiceCard(studentId: studentId, invoice: inv),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _InvoiceCard extends ConsumerWidget {
  const _InvoiceCard({required this.studentId, required this.invoice});

  final int studentId;
  final Map<String, dynamic> invoice;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final balance = numOf(invoice['balance']);
    final payments = listOf(invoice['payments']);

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: OutlinedCard(
        statusColor: balance > 0 ? AppColors.overdue : AppColors.paid,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text('${stringOf(invoice['term'], 'Invoice')} · ${stringOf(invoice['invoice_number'])}', style: AppText.bodyLg)),
                MoneyText(balance, color: balance > 0 ? AppColors.overdue : AppColors.paid),
              ],
            ),
            Text(
              '${Money.format(numOf(invoice['amount_paid']))} paid of ${Money.format(numOf(invoice['total_amount']))}',
              style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
            ),
            for (final p in payments)
              Text(
                '${stringOf(p['receipt_number'])} · ${Money.format(numOf(p['amount']))} · ${stringOf(p['gateway']).replaceAll('_', ' ')} · ${Dates.dayMonth(Dates.tryParse(p['paid_at']))}',
                style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
              ),
            if (balance > 0)
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton.tonal(
                  onPressed: () async {
                    final done = await showModalBottomSheet<String>(
                      context: context,
                      isScrollControlled: true,
                      builder: (_) => RecordPaymentSheet(invoice: invoice),
                    );
                    if (done != null && context.mounted) {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(done)));
                      ref.invalidate(bursarStatementProvider(studentId));
                      ref.invalidate(defaultersProvider);
                    }
                  },
                  child: const Text('Record payment'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Cash or a bank transfer taken at the bursary. Sent straight to the server,
/// never through the offline queue: a payment must be confirmed while the
/// bursar is looking, not replayed later. The server refuses anything above
/// the balance and asks before recording the same amount twice.
class RecordPaymentSheet extends ConsumerStatefulWidget {
  const RecordPaymentSheet({super.key, required this.invoice});

  final Map<String, dynamic> invoice;

  @override
  ConsumerState<RecordPaymentSheet> createState() => _RecordPaymentSheetState();
}

class _RecordPaymentSheetState extends ConsumerState<RecordPaymentSheet> {
  late final _amount = TextEditingController(text: numOf(widget.invoice['balance']).toStringAsFixed(2));
  final _reference = TextEditingController();
  String _method = 'cash';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _amount.dispose();
    _reference.dispose();
    super.dispose();
  }

  num get _balance => numOf(widget.invoice['balance']);

  Future<void> _record({bool confirmDuplicate = false}) async {
    final amount = num.tryParse(_amount.text.replaceAll(',', '').trim());
    if (amount == null || amount <= 0) {
      setState(() => _error = 'Enter the amount received.');
      return;
    }
    if (amount > _balance + 0.005) {
      setState(() => _error = 'That is more than the ${Money.format(_balance)} still owed.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final res = await ref.read(apiClientProvider).post<dynamic>(Api.payments, body: {
        'invoice_id': intOf(widget.invoice['invoice_id']),
        'amount': amount,
        'gateway': _method,
        if (_reference.text.trim().isNotEmpty) 'reference': _reference.text.trim(),
        if (confirmDuplicate) 'confirm_duplicate': true,
      });
      final payment = mapOf(res, ['payment']);
      if (mounted) {
        Navigator.of(context).pop('${Money.format(amount)} recorded. Ref ${stringOf(payment['reference'])}.');
      }
    } catch (error) {
      final failure = asApiException(error);
      if (failure.statusCode == 409 && !confirmDuplicate && mounted) {
        final again = await showDialog<bool>(
          context: context,
          builder: (dialogContext) => AlertDialog(
            title: const Text('Record it again?'),
            content: Text(failure.message),
            actions: [
              TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('No, it is the same payment')),
              FilledButton(onPressed: () => Navigator.pop(dialogContext, true), child: const Text('Yes, a second payment')),
            ],
          ),
        );
        if (again == true) {
          await _record(confirmDuplicate: true);
          return;
        }
        if (mounted) setState(() => _error = 'Not recorded again.');
      } else if (mounted) {
        setState(() => _error = failure.isOffline ? 'No connection. Payments are only recorded online. Try again when you have signal.' : failure.message);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: AppSpacing.screenMargin,
        right: AppSpacing.screenMargin,
        top: AppSpacing.screenMargin,
        bottom: MediaQuery.of(context).viewInsets.bottom + AppSpacing.screenMargin,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Record a payment', style: AppText.headlineSm),
          Text(
            '${stringOf(widget.invoice['invoice_number'])} · ${Money.format(_balance)} owed',
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: AppSpacing.md),
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'cash', label: Text('Cash'), icon: Icon(Icons.payments_outlined)),
              ButtonSegment(value: 'bank_transfer', label: Text('Bank transfer'), icon: Icon(Icons.account_balance_outlined)),
            ],
            selected: {_method},
            onSelectionChanged: (s) => setState(() => _method = s.first),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
            decoration: const InputDecoration(labelText: 'Amount received', prefixText: '₦ '),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            controller: _reference,
            maxLength: 100,
            decoration: InputDecoration(
              labelText: _method == 'cash' ? 'Receipt book number (optional)' : 'Bank reference or slip number (optional)',
            ),
          ),
          if (_error != null) Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
          const SizedBox(height: AppSpacing.sm),
          FilledButton(onPressed: _busy ? null : () => _record(), child: Text(_busy ? 'Recording…' : 'Record payment')),
        ],
      ),
    );
  }
}

/// Bank transfers a parent says they made, waiting for the bursar to check
/// the school's bank statement and approve or reject them. Card payments never
/// appear here: the gateway confirms those itself.
class TransfersToConfirmScreen extends ConsumerWidget {
  const TransfersToConfirmScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final transfers = ref.watch(pendingTransfersProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Transfers to confirm')),
      body: transfers.when(
        loading: () => const LoadingState(),
        error: (e, _) => ErrorState(error: asApiException(e), onRetry: () => ref.invalidate(pendingTransfersProvider)),
        data: (rows) => rows.isEmpty
            ? const EmptyState(icon: Icons.task_alt, title: 'Nothing to confirm', message: 'Bank transfers waiting for approval appear here.')
            : RefreshIndicator(
                onRefresh: () async => ref.refresh(pendingTransfersProvider.future),
                child: ListView(
                  padding: const EdgeInsets.all(AppSpacing.screenMargin),
                  children: [
                    Text(
                      'Approve only once the money shows on the school’s bank statement.',
                      style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    for (final t in rows)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: OutlinedCard(
                          statusColor: AppColors.pending,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  Expanded(child: Text(stringOf(t['student'], 'Student'), style: AppText.bodyLg)),
                                  MoneyText(numOf(t['amount'])),
                                ],
                              ),
                              Text(
                                'Ref ${stringOf(t['reference'])} · ${Dates.dayMonth(Dates.tryParse(t['created_at']))}',
                                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                              ),
                              Row(
                                mainAxisAlignment: MainAxisAlignment.end,
                                children: [
                                  TextButton(onPressed: () => _decide(context, ref, t, 'reject'), child: const Text('Reject')),
                                  const SizedBox(width: AppSpacing.sm),
                                  FilledButton(onPressed: () => _decide(context, ref, t, 'approve'), child: const Text('Approve')),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
              ),
      ),
    );
  }

  Future<void> _decide(BuildContext context, WidgetRef ref, Map<String, dynamic> t, String action) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(action == 'approve' ? 'Approve this transfer?' : 'Reject this transfer?'),
        content: Text(
          '${Money.format(numOf(t['amount']))} for ${stringOf(t['student'], 'this student')}, ref ${stringOf(t['reference'])}.'
          '${action == 'approve' ? ' It goes on their invoice and the parent is sent a receipt.' : ''}',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(dialogContext, true), child: Text(action == 'approve' ? 'Approve' : 'Reject')),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;

    final messenger = ScaffoldMessenger.of(context);
    try {
      await ref.read(apiClientProvider).post<dynamic>(Api.reconcileTransfer, body: {
        'payment_id': intOf(t['payment_id']),
        'action': action,
      });
      messenger.showSnackBar(SnackBar(content: Text(action == 'approve' ? 'Approved.' : 'Rejected.')));
      ref.invalidate(pendingTransfersProvider);
      ref.invalidate(defaultersProvider);
    } catch (error) {
      messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
    }
  }
}
