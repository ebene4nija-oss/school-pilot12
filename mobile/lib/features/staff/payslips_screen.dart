import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final payslipsProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'me:payslips',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.myPayslips),
  );
});

/// A member of staff's own payslips: approved and paid months only, the
/// latest version of each. Naira throughout.
class PayslipsScreen extends ConsumerWidget {
  const PayslipsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('My payslips')),
      body: CachedView<dynamic>(
        value: ref.watch(payslipsProvider),
        onRetry: () => ref.invalidate(payslipsProvider),
        builder: (context, data, meta) {
          final slips = listOf(data);
          if (slips.isEmpty) {
            return const EmptyState(
              icon: Icons.receipt_long_outlined,
              title: 'No payslips yet',
              message: 'Payslips appear here once the school approves a month’s payroll.',
            );
          }

          return RefreshableList(
            onRefresh: () async {
              ref.invalidate(payslipsProvider);
              await ref.read(payslipsProvider.future);
            },
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              for (final slip in slips)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: OutlinedCard(
                    statusColor: slip['run_status'] == 'paid' ? AppColors.paid : AppColors.pending,
                    onTap: () => _open(context, slip),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(_month(slip['period']), style: AppText.bodyLg),
                              Text(
                                slip['run_status'] == 'paid' ? 'Paid' : 'Approved, not yet paid',
                                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                              ),
                            ],
                          ),
                        ),
                        MoneyText(numOf(slip['net'])),
                      ],
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }

  static String _month(Object? period) {
    final d = DateTime.tryParse('${stringOf(period)}-01');
    return d == null ? stringOf(period) : DateFormat('MMMM yyyy').format(d);
  }

  void _open(BuildContext context, Map<String, dynamic> slip) {
    final earnings = listOf(slip['earnings']);
    final deductions = listOf(slip['deductions']);

    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.7,
        builder: (context, scroll) => ListView(
          controller: scroll,
          padding: const EdgeInsets.all(AppSpacing.screenMargin),
          children: [
            Text(_month(slip['period']), style: AppText.headlineSm),
            const SizedBox(height: AppSpacing.md),
            _line('Basic pay', slip['basic']),
            for (final e in earnings) _line(stringOf(e['name']), e['amount']),
            const Divider(),
            _line('Gross pay', slip['gross'], bold: true),
            const SizedBox(height: AppSpacing.sm),
            for (final d in deductions)
              _line(
                '${stringOf(d['name'])}${d['statutory'] == true ? ' (statutory)' : ''}',
                d['amount'],
                minus: true,
              ),
            const Divider(),
            _line('Net pay', slip['net'], bold: true),
            if (intOf(slip['leave_days']) > 0) ...[
              const SizedBox(height: AppSpacing.md),
              Text(
                '${intOf(slip['leave_days'])} day(s) of approved leave this month.',
                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
          ],
        ),
      ),
    );
  }

  static Widget _line(String label, Object? amount, {bool bold = false, bool minus = false}) {
    final style = bold ? AppText.labelLg : AppText.bodyMd;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(child: Text(label, style: style)),
          Text('${minus ? '− ' : ''}${Money.format(numOf(amount))}', style: style),
        ],
      ),
    );
  }
}
