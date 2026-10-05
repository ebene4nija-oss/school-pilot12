import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// Never cached: approving or paying from a stale list is how money moves twice.
final payrollRunsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  return listOf(await ref.watch(apiClientProvider).get<dynamic>(Api.payrollRuns));
});

final payrollRunProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) async {
  return mapOf(await ref.watch(apiClientProvider).get<dynamic>(Api.payrollRun(id)));
});

String payrollMonth(Object? period) {
  final d = DateTime.tryParse('${stringOf(period)}-01');
  return d == null ? stringOf(period) : DateFormat('MMMM yyyy').format(d);
}

const _runStatus = {
  'draft': ('Draft, needs approval', AppColors.pending),
  'approved': ('Approved, not yet paid', AppColors.primaryContainer),
  'paid': ('Paid', AppColors.paid),
  'superseded': ('Superseded', AppColors.excused),
};

/// Payroll on the phone for an administrator (TASK 20): see each month, its
/// totals and payslips, prepare a month, approve it, record the payment, or
/// reopen an approved month as a new version. Salary structures and pay items
/// stay on the web.
class PayrollScreen extends ConsumerWidget {
  const PayrollScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final runs = ref.watch(payrollRunsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Payroll')),
      floatingActionButton: FloatingActionButton.extended(
        icon: const Icon(Icons.add),
        label: const Text('Prepare a month'),
        onPressed: () => _prepare(context, ref),
      ),
      body: runs.when(
        loading: () => const LoadingState(),
        error: (e, _) => ErrorState(error: asApiException(e), onRetry: () => ref.invalidate(payrollRunsProvider)),
        data: (rows) => rows.isEmpty
            ? const EmptyState(
                icon: Icons.payments_outlined,
                title: 'No payroll yet',
                message: 'Prepare a month once salaries are set up on the web.',
              )
            : RefreshIndicator(
                onRefresh: () async => ref.refresh(payrollRunsProvider.future),
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.screenMargin, AppSpacing.screenMargin, 96),
                  children: [
                    for (final r in rows)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: OutlinedCard(
                          statusColor: _runStatus[stringOf(r['status'])]?.$2,
                          onTap: () => Navigator.of(context).push(
                            MaterialPageRoute<void>(builder: (_) => PayrollRunScreen(runId: intOf(r['id']))),
                          ),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      '${payrollMonth(r['period'])}${intOf(r['version'], 1) > 1 ? ' · v${intOf(r['version'])}' : ''}',
                                      style: AppText.bodyLg,
                                    ),
                                    Text(
                                      '${_runStatus[stringOf(r['status'])]?.$1 ?? stringOf(r['status'])} · ${intOf(r['staff_count'])} staff',
                                      style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                                    ),
                                  ],
                                ),
                              ),
                              MoneyText(numOf(r['total_net'])),
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

  Future<void> _prepare(BuildContext context, WidgetRef ref) async {
    final now = DateTime.now();
    final months = [for (var i = 0; i < 3; i++) DateTime(now.year, now.month - i)];
    final picked = await showModalBottomSheet<DateTime>(
      context: context,
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.all(AppSpacing.md),
              child: Text('Prepare payroll for', style: AppText.headlineSm),
            ),
            for (final m in months)
              ListTile(title: Text(DateFormat('MMMM yyyy').format(m)), onTap: () => Navigator.pop(context, m)),
          ],
        ),
      ),
    );
    if (picked == null || !context.mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.payrollRuns,
        body: {'period': DateFormat('yyyy-MM').format(picked)},
      ));
      messenger.showSnackBar(SnackBar(content: Text(stringOf(res['message'], 'Prepared.'))));
    } catch (error) {
      messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
    }
    ref.invalidate(payrollRunsProvider);
  }
}

class PayrollRunScreen extends ConsumerStatefulWidget {
  const PayrollRunScreen({super.key, required this.runId});

  final int runId;

  @override
  ConsumerState<PayrollRunScreen> createState() => _PayrollRunScreenState();
}

class _PayrollRunScreenState extends ConsumerState<PayrollRunScreen> {
  bool _busy = false;

  Future<void> _act(Future<Object?> Function() call, {String? done}) async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _busy = true);
    try {
      final res = mapOf(await call());
      messenger.showSnackBar(SnackBar(content: Text(stringOf(res['message'], done ?? 'Done.'))));
    } catch (error) {
      messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
    } finally {
      ref.invalidate(payrollRunProvider(widget.runId));
      ref.invalidate(payrollRunsProvider);
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Approving your own payroll needs a recorded reason; the server says so,
  /// and the reason is asked for only then.
  Future<void> _approve() async {
    final api = ref.read(apiClientProvider);
    try {
      setState(() => _busy = true);
      await api.post<dynamic>(Api.payrollApprove(widget.runId));
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Approved. Staff can now see their payslips.')));
    } catch (error) {
      final failure = asApiException(error);
      if (failure.statusCode == 409 && failure.message.startsWith('You prepared this payroll') && mounted) {
        final reason = await _ask(context, 'Why are you approving your own payroll?', 'e.g. Only administrator at this school');
        if (reason != null) {
          await _act(() => api.post<dynamic>(Api.payrollApprove(widget.runId), body: {'self_approval_reason': reason}),
              done: 'Approved, with your reason on the record.');
        }
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message)));
      }
    } finally {
      ref.invalidate(payrollRunProvider(widget.runId));
      ref.invalidate(payrollRunsProvider);
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _markPaid() async {
    final reference = await _ask(context, 'Payment reference', 'Bank batch or transfer reference');
    if (reference == null) return;
    await _act(() => ref.read(apiClientProvider).post<dynamic>(
          Api.payrollPaid(widget.runId),
          body: {'payment_reference': reference, 'paid_on': Dates.apiDate(DateTime.now())},
        ));
  }

  Future<void> _reopen() async {
    final reason = await _ask(context, 'What needs correcting?', 'The approved version is kept on record');
    if (reason == null) return;
    await _act(() => ref.read(apiClientProvider).post<dynamic>(Api.payrollReopen(widget.runId), body: {'reason': reason}));
  }

  @override
  Widget build(BuildContext context) {
    final run = ref.watch(payrollRunProvider(widget.runId));

    return Scaffold(
      appBar: AppBar(title: const Text('Payroll')),
      body: run.when(
        loading: () => const LoadingState(),
        error: (e, _) => ErrorState(error: asApiException(e), onRetry: () => ref.invalidate(payrollRunProvider(widget.runId))),
        data: (data) {
          final r = mapOf(data['run']);
          final slips = listOf(data['payslips'])..sort((a, b) => stringOf(a['name']).compareTo(stringOf(b['name'])));
          final status = stringOf(r['status']);
          final negative = slips.where((p) => numOf(p['net']) < 0).length;

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              Text(payrollMonth(r['period']), style: AppText.headlineSm),
              const SizedBox(height: AppSpacing.xs),
              StatusChip(_runStatus[status]?.$1 ?? status, color: _runStatus[status]?.$2 ?? AppColors.onSurfaceVariant),
              const SizedBox(height: AppSpacing.md),
              Row(
                children: [
                  Expanded(child: StatTile(label: 'Gross', value: Money.whole(numOf(r['total_gross'])))),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(child: StatTile(label: 'Net to pay', value: Money.whole(numOf(r['total_net'])))),
                ],
              ),
              if (stringOf(r['self_approval_reason']).isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpacing.sm),
                  child: Text('Self-approved: ${stringOf(r['self_approval_reason'])}', style: AppText.bodyMd),
                ),
              if (stringOf(r['payment_reference']).isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpacing.sm),
                  child: Text('Paid · ref ${stringOf(r['payment_reference'])}', style: AppText.bodyMd),
                ),
              if (negative > 0)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpacing.sm),
                  child: OutlinedCard(
                    statusColor: AppColors.absent,
                    child: Text('$negative payslip(s) have deductions larger than pay. Fix them on the web before approving.', style: AppText.bodyMd),
                  ),
                ),
              const SizedBox(height: AppSpacing.md),
              if (status == 'draft')
                FilledButton(onPressed: _busy ? null : _approve, child: const Text('Approve')),
              if (status == 'approved') ...[
                FilledButton(onPressed: _busy ? null : _markPaid, child: const Text('Record as paid today')),
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton(onPressed: _busy ? null : _reopen, child: const Text('Reopen to correct')),
              ],
              const SectionHeader('Payslips'),
              for (final p in slips)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(stringOf(p['name'], 'Staff')),
                  subtitle: Text('Gross ${Money.format(numOf(p['gross']))} · deductions ${Money.format(numOf(p['total_deductions']))}'),
                  trailing: MoneyText(numOf(p['net']), color: numOf(p['net']) < 0 ? AppColors.absent : null),
                ),
            ],
          );
        },
      ),
    );
  }
}

Future<String?> _ask(BuildContext context, String title, String hint) {
  final controller = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: TextField(controller: controller, autofocus: true, decoration: InputDecoration(hintText: hint)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        TextButton(
          onPressed: () {
            final text = controller.text.trim();
            if (text.length >= 5) Navigator.pop(context, text);
          },
          child: const Text('Save'),
        ),
      ],
    ),
  );
}
