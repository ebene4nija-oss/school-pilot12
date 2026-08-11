import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/endpoints.dart';
import '../../core/auth/session.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../dashboard/dashboard_providers.dart';
import 'gateway_checkout.dart';

/// One Fees tab, two very different jobs: a bursar chasing arrears, and a
/// guardian looking at one child's bill.
class FeesScreen extends ConsumerWidget {
  const FeesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final role = ref.watch(currentRoleProvider);
    return role.isAdmin ? const _DefaultersScreen() : const _StatementScreen();
  }
}

// ------------------------------------------------------------------ admin

final defaultersProvider =
    FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<dynamic>(
    cache: cache,
    key: 'finance:defaulters',
    fetch: () => api.get<dynamic>(Api.defaulters),
  );
});

class _DefaultersScreen extends ConsumerWidget {
  const _DefaultersScreen();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final defaulters = ref.watch(defaultersProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Defaulters')),
      body: CachedView<dynamic>(
        value: defaulters,
        onRetry: () => ref.invalidate(defaultersProvider),
        builder: (context, data, meta) {
          final rows = listOf(data, ['defaulters', 'students']);
          final total = rows.fold<num>(
            0,
            (sum, r) => sum + numOf(r['outstanding'] ?? r['balance']),
          );

          if (rows.isEmpty) {
            return const EmptyState(
              icon: Icons.check_circle_outline,
              title: 'No defaulters',
              message: 'Every invoice for this term has been settled.',
            );
          }

          return RefreshableList(
            onRefresh: () async => ref.refresh(defaultersProvider.future),
            children: [
              Padding(
                padding: const EdgeInsets.all(AppSpacing.screenMargin),
                child: Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(AppSpacing.md),
                  decoration: BoxDecoration(
                    color: AppColors.primaryContainer,
                    borderRadius: AppRadius.sectionRadius,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'OUTSTANDING',
                        style: AppText.labelSm.copyWith(
                          color: AppColors.onPrimaryContainer,
                          letterSpacing: 0.6,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      MoneyText(total, size: 24, color: AppColors.onPrimary),
                      const SizedBox(height: 4),
                      Text(
                        'across ${rows.length} '
                        '${rows.length == 1 ? 'student' : 'students'}',
                        style: AppText.bodyMd
                            .copyWith(color: AppColors.onPrimaryContainer),
                      ),
                    ],
                  ),
                ),
              ),
              for (final row in rows)
                Padding(
                  padding: const EdgeInsets.only(
                    left: AppSpacing.screenMargin,
                    right: AppSpacing.screenMargin,
                    bottom: AppSpacing.sm,
                  ),
                  child: OutlinedCard(
                    statusColor: AppColors.overdue,
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                stringOf(
                                  row['name'] ??
                                      row['student_name'] ??
                                      row['student']?['name'],
                                  'Student',
                                ),
                                style: AppText.bodyLg,
                              ),
                              Text(
                                stringOf(
                                  row['class']?['name'] ?? row['class_name'],
                                ),
                                style: AppText.bodyMd.copyWith(
                                  color: AppColors.onSurfaceVariant,
                                ),
                              ),
                            ],
                          ),
                        ),
                        MoneyText(
                          numOf(row['outstanding'] ?? row['balance']),
                          color: AppColors.overdue,
                        ),
                      ],
                    ),
                  ),
                ),
              const SizedBox(height: AppSpacing.xl),
            ],
          );
        },
      ),
    );
  }
}

// -------------------------------------------------- guardian and student

final statementProvider =
    FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);
  final role = ref.watch(currentRoleProvider);
  final user = ref.watch(currentUserProvider);

  // A guardian reads the selected child's statement; a student reads their own.
  final studentId = role == UserRole.parent
      ? ref.watch(selectedChildIdProvider)
      : user?.id;

  if (studentId == null) {
    throw StateError('No student selected');
  }

  return cachedGet<dynamic>(
    cache: cache,
    key: 'finance:statement:$studentId',
    fetch: () => api.get<dynamic>(Api.studentStatement(studentId)),
  );
});

class _StatementScreen extends ConsumerStatefulWidget {
  const _StatementScreen();

  @override
  ConsumerState<_StatementScreen> createState() => _StatementScreenState();
}

class _StatementScreenState extends ConsumerState<_StatementScreen> {
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final ref = this.ref;
    final statement = ref.watch(statementProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('School fees')),
      body: CachedView<dynamic>(
        value: statement,
        onRetry: () => ref.invalidate(statementProvider),
        builder: (context, data, meta) {
          final map = mapOf(data, ['statement', 'data']);
          final outstanding =
              numOf(map['outstanding'] ?? map['balance'] ?? map['total_due']);
          final billed = numOf(map['total_billed'] ?? map['total']);
          final paid = numOf(map['total_paid'] ?? (billed - outstanding));
          final invoices = listOf(map['invoices'] ?? data, ['invoices']);
          final payments = listOf(map['payments'], ['payments']);

          return Column(
            children: [
              Expanded(
                child: RefreshableList(
                  onRefresh: () async => ref.refresh(statementProvider.future),
                  children: [
                    Padding(
                      padding: const EdgeInsets.all(AppSpacing.screenMargin),
                      child: OutlinedCard(
                        statusColor:
                            outstanding > 0 ? AppColors.overdue : AppColors.paid,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              outstanding > 0 ? 'OUTSTANDING' : 'FULLY PAID',
                              style: AppText.labelSm.copyWith(
                                color: AppColors.onSurfaceVariant,
                                letterSpacing: 0.6,
                              ),
                            ),
                            const SizedBox(height: AppSpacing.sm),
                            MoneyText(
                              outstanding,
                              size: 28,
                              color: outstanding > 0
                                  ? AppColors.overdue
                                  : AppColors.paid,
                            ),
                            if (billed > 0) ...[
                              const SizedBox(height: AppSpacing.sm),
                              ProgressBar(
                                value: billed == 0 ? 0 : paid / billed,
                                color: AppColors.paid,
                              ),
                              const SizedBox(height: AppSpacing.sm),
                              Text(
                                '${Money.format(paid)} paid of '
                                '${Money.format(billed)}',
                                style: AppText.bodyMd.copyWith(
                                  color: AppColors.onSurfaceVariant,
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
                    if (invoices.isNotEmpty) ...[
                      const SectionHeader('Breakdown'),
                      for (final invoice in invoices)
                        Padding(
                          padding: const EdgeInsets.only(
                            left: AppSpacing.screenMargin,
                            right: AppSpacing.screenMargin,
                            bottom: AppSpacing.sm,
                          ),
                          child: OutlinedCard(
                            child: Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        stringOf(
                                          invoice['description'] ??
                                              invoice['title'] ??
                                              invoice['term']?['name'],
                                          'Invoice',
                                        ),
                                        style: AppText.bodyMd,
                                      ),
                                      Text(
                                        stringOf(invoice['status'], 'pending'),
                                        style: AppText.labelSm.copyWith(
                                          color: AppColors.onSurfaceVariant,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                MoneyText(
                                  numOf(
                                    invoice['total_amount'] ??
                                        invoice['amount'],
                                  ),
                                  size: 14,
                                ),
                              ],
                            ),
                          ),
                        ),
                    ],
                    if (payments.isNotEmpty) ...[
                      const SectionHeader('Payments made'),
                      for (final payment in payments)
                        Padding(
                          padding: const EdgeInsets.only(
                            left: AppSpacing.screenMargin,
                            right: AppSpacing.screenMargin,
                            bottom: AppSpacing.sm,
                          ),
                          child: OutlinedCard(
                            statusColor: AppColors.paid,
                            child: Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        Dates.full(
                                          Dates.tryParse(
                                            payment['paid_at'] ??
                                                payment['created_at'],
                                          ),
                                        ),
                                        style: AppText.bodyMd,
                                      ),
                                      Text(
                                        stringOf(payment['gateway'], '—'),
                                        style: AppText.labelSm.copyWith(
                                          color: AppColors.onSurfaceVariant,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                MoneyText(
                                  numOf(payment['amount']),
                                  size: 14,
                                  color: AppColors.paid,
                                ),
                              ],
                            ),
                          ),
                        ),
                    ],
                    const SizedBox(height: AppSpacing.xl),
                  ],
                ),
              ),
              if (outstanding > 0)
                BottomActionBar(
                  caption: Text(
                    "Opens your school's own payment page. "
                    'Your receipt appears here once the bank confirms it.',
                    style: AppText.labelSm
                        .copyWith(color: AppColors.onSurfaceVariant),
                  ),
                  child: FilledButton(
                    onPressed: _busy ? null : () => _pay(invoices),
                    child: _busy
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text('Pay ${Money.format(outstanding)}'),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }

  /// Pay a bill.
  ///
  /// The app sends an invoice id and nothing else: no key, no reference, and
  /// no amount — the server settles the outstanding balance it computed, so a
  /// tampered client cannot pay ₦1 against a ₦45,000 bill. It sends no gateway
  /// either; a parent does not know which merchant account their school holds,
  /// so the server picks the one the school connected.
  Future<void> _pay(List<Map<String, dynamic>> invoices) async {
    final payable = [
      for (final invoice in invoices)
        if (numOf(invoice['balance']) > 0) invoice,
    ];

    // A family with one outstanding bill — most of them — is never asked.
    final invoice = payable.length == 1 ? payable.first : await _chooseBill(payable);
    if (invoice == null) return;

    final invoiceId = intOf(invoice['invoice_id'] ?? invoice['id']);
    if (invoiceId == 0) return;

    setState(() => _busy = true);

    try {
      final res = await ref.read(apiClientProvider).post<Map<String, dynamic>>(
        Api.paymentsInitialize,
        body: {'invoice_id': invoiceId},
      );

      final url = stringOf(res['authorization_url']);
      if (!mounted) return;
      setState(() => _busy = false);
      if (url.isEmpty) return;

      await GatewayCheckout.open(context, url: url, title: 'Pay school fees');

      // Refresh either way. Settlement is a webhook the app never sees, and a
      // payer can finish a transfer and then background the app rather than
      // wait for the redirect.
      if (!mounted) return;
      ref.invalidate(statementProvider);
      _announceConfirming();
    } catch (error) {
      if (!mounted) return;
      setState(() => _busy = false);
      _explainFailure(asApiException(error));
    }
  }

  /// Which bill, when a family owes on more than one term.
  Future<Map<String, dynamic>?> _chooseBill(
    List<Map<String, dynamic>> payable,
  ) {
    if (payable.isEmpty) return Future.value(null);

    return showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      builder: (sheetContext) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.all(AppSpacing.md),
              child: Text('Which bill would you like to pay?'),
            ),
            for (final invoice in payable)
              ListTile(
                title: Text(
                  stringOf(invoice['term'] ?? invoice['invoice_number'], 'Bill'),
                ),
                subtitle: Text(stringOf(invoice['status'], 'unpaid')),
                trailing: MoneyText(numOf(invoice['balance']), size: 14),
                onTap: () => Navigator.pop(sheetContext, invoice),
              ),
          ],
        ),
      ),
    );
  }

  /// Deliberately never says "paid".
  ///
  /// The app cannot know: only the gateway's signed callback to the server
  /// settles a payment. Telling a parent their fees are paid and then showing
  /// an unchanged balance on the next refresh is worse than telling them to
  /// wait a moment.
  void _announceConfirming() {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text(
          'Confirming your payment with the school. Your receipt appears here '
          'once it arrives.',
        ),
      ),
    );
  }

  void _explainFailure(ApiException failure) {
    // 409 is the school not having connected a merchant account. That is not a
    // fault the parent can fix or should see as an error — it means this school
    // takes fees another way.
    final title = failure.statusCode == 409
        ? 'Paying your school'
        : 'Payment could not start';

    showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(title),
        content: Text(
          failure.statusCode == 409
              ? '${failure.message}\n\nYour payment will show here once the '
                  'bursar records it.'
              : failure.message,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }
}
