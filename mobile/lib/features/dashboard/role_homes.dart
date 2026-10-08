import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../finance/fees_screen.dart';

/// The bursar's home: how much is outstanding this term and who owes most.
/// Cash and bank transfers are recorded from the student's fees screen.
class BursarHome extends ConsumerWidget {
  const BursarHome({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Bursary', style: AppText.headlineSm),
            if (user != null)
              Text(user.name, style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant)),
          ],
        ),
        actions: [
          IconButton(
            onPressed: () => context.push('/notifications'),
            icon: const Icon(Icons.notifications_outlined),
            tooltip: 'Notifications',
          ),
        ],
      ),
      body: CachedView<dynamic>(
        value: ref.watch(defaultersProvider),
        onRetry: () => ref.invalidate(defaultersProvider),
        builder: (context, data, meta) {
          final rows = listOf(data, ['defaulters', 'students'])
            ..sort((a, b) => numOf(b['outstanding'] ?? b['balance']).compareTo(numOf(a['outstanding'] ?? a['balance'])));
          final total = rows.fold<num>(0, (sum, r) => sum + numOf(r['outstanding'] ?? r['balance']));

          return RefreshableList(
            onRefresh: () async => ref.refresh(defaultersProvider.future),
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: 'Outstanding',
                      value: Money.whole(total),
                      caption: 'this term',
                      onTap: () => context.go('/fees'),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: 'Owing',
                      value: '${rows.length}',
                      caption: rows.length == 1 ? 'student' : 'students',
                      onTap: () => context.go('/fees'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.md),
              if (rows.isEmpty)
                const EmptyState(
                  icon: Icons.check_circle_outline,
                  title: 'Nobody owes',
                  message: 'Every invoice for this term has been settled.',
                )
              else ...[
                const SectionHeader('Largest balances'),
                for (final r in rows.take(5))
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: OutlinedCard(
                      statusColor: AppColors.overdue,
                      onTap: intOf(r['student_id']) == 0
                          ? () => context.go('/fees')
                          : () => context.push('/fees/student/${intOf(r['student_id'])}', extra: stringOf(r['name'] ?? r['student_name'])),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              stringOf(r['name'] ?? r['student_name'] ?? r['student']?['name'], 'Student'),
                              style: AppText.bodyLg,
                            ),
                          ),
                          MoneyText(numOf(r['outstanding'] ?? r['balance']), color: AppColors.overdue),
                        ],
                      ),
                    ),
                  ),
              ],
              const SizedBox(height: AppSpacing.md),
              OutlinedCard(
                onTap: () => context.push('/fees/transfers'),
                child: Row(
                  children: [
                    const Icon(Icons.account_balance_outlined),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(child: Text('Bank transfers to confirm', style: AppText.bodyLg)),
                    const Icon(Icons.chevron_right, color: AppColors.outline),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              Text(
                'Tap a student to record cash or a bank transfer. Fee structures and invoices are set up on the web portal.',
                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
          );
        },
      ),
    );
  }
}

/// A role this version of the app has no screens for.
class WebOnlyHome extends ConsumerWidget {
  const WebOnlyHome({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('SchoolPilot')),
      body: const EmptyState(
        icon: Icons.computer_outlined,
        title: 'Use the web portal for your work',
        message: 'Your role does not have screens in this app yet. Sign in to your school’s '
            'SchoolPilot web portal on a computer. Messages and notifications are under More.',
      ),
    );
  }
}
