import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/auth/session.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../dashboard/dashboard_providers.dart';

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

class _StatementScreen extends ConsumerWidget {
  const _StatementScreen();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
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
                    'Paying online opens your school\'s payment page.',
                    style: AppText.labelSm
                        .copyWith(color: AppColors.onSurfaceVariant),
                  ),
                  child: FilledButton(
                    onPressed: () => _explainPayment(context),
                    child: Text('Pay ${Money.format(outstanding)}'),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }

  /// Online payment is not wired up, and deliberately so.
  ///
  /// The backend expects checkout to run client-side against the school's own
  /// public gateway key, but nothing exposes that key to a client, and
  /// `POST /finance/payments` requires the client to invent a unique
  /// `reference` (gaps G8 and G9). Guessing at either would mean shipping a
  /// payment flow that can create orphaned pending rows against a real
  /// merchant account, so the app says what to do instead.
  void _explainPayment(BuildContext context) {
    showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Paying your school'),
        content: const Text(
          'Online payment from the app is not available yet.\n\n'
          'You can pay at the school office, or by bank transfer using the '
          'details on your invoice. Your payment will show here once the '
          'bursar records it.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }
}
