import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// The administrator's academics hub: timetable, PIN stock, and the one action
/// only a school admin may take — releasing results.
class AcademicsScreen extends ConsumerWidget {
  const AcademicsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Academics')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          _HubCard(
            icon: Icons.lock_open_outlined,
            title: 'Release results',
            detail: 'Publish a term to parents',
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => const ResultReleaseScreen()),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          _HubCard(
            icon: Icons.confirmation_number_outlined,
            title: 'Result-checker PINs',
            detail: 'Stock, sales and counter sales',
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => const PinInventoryScreen()),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          _HubCard(
            icon: Icons.calendar_month_outlined,
            title: 'Timetable',
            detail: 'The published week',
            onTap: () => context.push('/timetable'),
          ),
        ],
      ),
    );
  }
}

class _HubCard extends StatelessWidget {
  const _HubCard({
    required this.icon,
    required this.title,
    required this.detail,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String detail;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return OutlinedCard(
      onTap: onTap,
      child: Row(
        children: [
          Icon(icon, color: AppColors.primaryContainer),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: AppText.bodyLg),
                Text(
                  detail,
                  style: AppText.bodyMd
                      .copyWith(color: AppColors.onSurfaceVariant),
                ),
              ],
            ),
          ),
          const Icon(Icons.chevron_right, color: AppColors.outline),
        ],
      ),
    );
  }
}

// -------------------------------------------------------------- releases

final releasesProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final data = await api.get<dynamic>(Api.resultReleases);
  return listOf(data, ['releases']);
});

/// Releasing a term is the school's own academic decision — the route is
/// `school_admin` only, with no super-admin fallback, and the copy here says
/// what the action means to a parent.
class ResultReleaseScreen extends ConsumerWidget {
  const ResultReleaseScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final releases = ref.watch(releasesProvider);
    final termId = ref.watch(selectedTermProvider);
    final classes = ref.watch(classesProvider).valueOrNull ?? const [];

    return Scaffold(
      appBar: AppBar(title: const Text('Release results')),
      body: releases.when(
        loading: () => const LoadingState(rows: 4),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(releasesProvider),
        ),
        data: (list) {
          final released = {
            for (final r in list)
              '${intOf(r['class_id'])}:${intOf(r['term_id'])}': true,
          };

          if (classes.isEmpty) {
            return const EmptyState(
              icon: Icons.help_outline,
              title: 'No classes available',
              message:
                  'This school server does not publish its class list to the '
                  'app yet.',
            );
          }

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              for (final c in classes)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: _ReleaseRow(
                    classId: c.id,
                    className: c.name,
                    termId: termId,
                    released: released['${c.id}:$termId'] ?? false,
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _ReleaseRow extends ConsumerStatefulWidget {
  const _ReleaseRow({
    required this.classId,
    required this.className,
    required this.termId,
    required this.released,
  });

  final int classId;
  final String className;
  final int? termId;
  final bool released;

  @override
  ConsumerState<_ReleaseRow> createState() => _ReleaseRowState();
}

class _ReleaseRowState extends ConsumerState<_ReleaseRow> {
  bool _busy = false;

  Future<void> _release() async {
    if (widget.termId == null) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('Release ${widget.className}?'),
        content: const Text(
          'Parents will be able to check these results with a PIN. This '
          'cannot be undone from the app.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Release'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;
    setState(() => _busy = true);

    try {
      await ref.read(apiClientProvider).post<dynamic>(
        Api.resultRelease,
        body: {'class_id': widget.classId, 'term_id': widget.termId},
      );
      ref.invalidate(releasesProvider);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(asApiException(error).message)),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return OutlinedCard(
      statusColor: widget.released ? AppColors.present : AppColors.late,
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(widget.className, style: AppText.bodyLg),
                Text(
                  widget.released ? 'Released to parents' : 'Not released',
                  style: AppText.bodyMd
                      .copyWith(color: AppColors.onSurfaceVariant),
                ),
              ],
            ),
          ),
          if (widget.released)
            const StatusChip('Released', color: AppColors.present)
          else
            FilledButton(
              onPressed: _busy || widget.termId == null ? null : _release,
              child: const Text('Release'),
            ),
        ],
      ),
    );
  }
}

// ------------------------------------------------------------------ PINs

final pinInventoryProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.pinInventory));
});

class PinInventoryScreen extends ConsumerWidget {
  const PinInventoryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final inventory = ref.watch(pinInventoryProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Result-checker PINs')),
      body: inventory.when(
        loading: () => const LoadingState(rows: 3),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(pinInventoryProvider),
        ),
        data: (data) {
          final stock = intOf(data['available'] ?? data['stock']);
          final sold = intOf(data['sold'] ?? data['sold_this_term']);
          final revenue = numOf(data['revenue'] ?? data['total_revenue']);

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              StatTile(
                label: 'PINs in stock',
                value: '$stock',
                caption: stock < 200 ? 'Running low — reorder soon' : null,
                valueColor: stock < 200 ? AppColors.late : null,
              ),
              const SizedBox(height: AppSpacing.sm),
              Row(
                children: [
                  Expanded(
                    child: StatTile(label: 'Sold', value: '$sold'),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: 'Revenue',
                      value: Money.whole(revenue),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.lg),
              Text(
                'Buying more stock and selling over the counter are done from '
                'the web portal, where the payment and receipt trail lives.',
                style:
                    AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
          );
        },
      ),
    );
  }
}
