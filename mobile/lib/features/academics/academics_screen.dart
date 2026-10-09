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
import '../finance/gateway_checkout.dart';

/// The administrator's academics hub: generating report cards, releasing
/// results (the one action only a school admin may take), PIN stock and the
/// timetable.
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
            icon: Icons.picture_as_pdf_outlined,
            title: 'Generate report cards',
            detail: 'Build term report cards per class',
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                  builder: (_) => const GenerateReportCardsScreen()),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
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
          // Withdrawn rows stay in the list with is_released false, and a
          // school-wide row (no class) covers every class — as the API reads it.
          final released = {
            for (final r in list)
              if (r['is_released'] == true)
                '${r['class_id'] == null ? '*' : intOf(r['class_id'])}:${intOf(r['term_id'])}':
                    true,
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
                    released: (released['${c.id}:$termId'] ?? false) ||
                        (released['*:$termId'] ?? false),
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
      builder: (dialogContext) => AlertDialog(
        title: Text('Release ${widget.className}?'),
        content: const Text(
          'Parents will be able to check these results with a PIN. This '
          'cannot be undone from the app.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
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

// ------------------------------------------------------- report cards

/// `{total, ready, pending}` for one class in the selected term.
final generatedCardsProvider = FutureProvider.autoDispose
    .family<Map<String, int>, ({int classId, int termId})>((ref, key) async {
  final api = ref.watch(apiClientProvider);
  final data = await api.get<dynamic>(
    Api.generatedReportCards,
    query: {'term_id': key.termId, 'class_id': key.classId},
  );
  final rows = listOf(data, ['data']);
  return {
    'total': rows.length,
    'ready': rows.where((r) => r['ready'] == true).length,
    'pending': rows.fold(0, (n, r) => n + intOf(r['pending_ai_comments'])),
  };
});

/// Queues a class's report cards and follows the run. The PDFs themselves are
/// checked and opened from the web portal; releasing stays a separate step.
class GenerateReportCardsScreen extends ConsumerWidget {
  const GenerateReportCardsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final termId = ref.watch(selectedTermProvider);
    final classes = ref.watch(classesProvider).valueOrNull ?? const [];

    return Scaffold(
      appBar: AppBar(title: const Text('Generate report cards')),
      body: termId == null
          ? const EmptyState(
              icon: Icons.event_busy_outlined,
              title: 'No term selected',
              message:
                  'Set up the current term before generating report cards.',
            )
          : classes.isEmpty
              ? const EmptyState(
                  icon: Icons.help_outline,
                  title: 'No classes available',
                  message:
                      'This school server does not publish its class list to '
                      'the app yet.',
                )
              : ListView(
                  padding: const EdgeInsets.all(AppSpacing.screenMargin),
                  children: [
                    for (final c in classes)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: _GenerateRow(
                          classId: c.id,
                          className: c.name,
                          termId: termId,
                        ),
                      ),
                  ],
                ),
    );
  }
}

class _GenerateRow extends ConsumerStatefulWidget {
  const _GenerateRow({
    required this.classId,
    required this.className,
    required this.termId,
  });

  final int classId;
  final String className;
  final int termId;

  @override
  ConsumerState<_GenerateRow> createState() => _GenerateRowState();
}

class _GenerateRowState extends ConsumerState<_GenerateRow> {
  bool _running = false;
  double _progress = 0;

  ({int classId, int termId}) get _key =>
      (classId: widget.classId, termId: widget.termId);

  Future<void> _generate() async {
    setState(() {
      _running = true;
      _progress = 0;
    });

    final api = ref.read(apiClientProvider);
    final messenger = ScaffoldMessenger.of(context);

    try {
      final started = mapOf(await api.post<dynamic>(
        Api.generateReportCards,
        body: {'term_id': widget.termId, 'class_id': widget.classId},
      ));
      final batchId = started['batch_id']?.toString();
      if (batchId == null) throw StateError('No batch id returned.');

      // Polled, not held open: a class of cards outlives any sane request.
      // Leaving the screen stops the polling, not the run.
      while (mounted) {
        final status = mapOf(await api.get<dynamic>(Api.job(batchId)));
        if (!mounted) return;
        setState(
            () => _progress = numOf(status['progress_percentage']) / 100);

        if (status['finished'] == true || status['cancelled'] == true) {
          final done = intOf(status['processed']);
          final failed = intOf(status['failed']);
          messenger.showSnackBar(SnackBar(
            content: Text(failed > 0
                ? '${widget.className}: $done generated, $failed blocked.'
                : '${widget.className}: $done report card(s) generated.'),
          ));
          break;
        }

        await Future<void>.delayed(const Duration(seconds: 2));
      }
    } catch (error) {
      messenger.showSnackBar(
        SnackBar(content: Text(asApiException(error).message)),
      );
    } finally {
      if (mounted) {
        setState(() => _running = false);
        ref.invalidate(generatedCardsProvider(_key));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final counts = ref.watch(generatedCardsProvider(_key)).valueOrNull;
    final total = counts?['total'] ?? 0;
    final ready = counts?['ready'] ?? 0;
    final pending = counts?['pending'] ?? 0;

    final String detail;
    if (counts == null) {
      detail = 'Checking…';
    } else if (pending > 0) {
      detail = '$ready of $total ready · $pending AI comment(s) awaiting review';
    } else {
      detail = '$ready of $total ready';
    }

    return OutlinedCard(
      statusColor: total > 0 && ready == total
          ? AppColors.present
          : AppColors.late,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(widget.className, style: AppText.bodyLg),
                    Text(
                      detail,
                      style: AppText.bodyMd
                          .copyWith(color: AppColors.onSurfaceVariant),
                    ),
                  ],
                ),
              ),
              FilledButton(
                onPressed: _running ? null : _generate,
                child: Text(ready > 0 ? 'Regenerate' : 'Generate'),
              ),
            ],
          ),
          if (_running) ...[
            const SizedBox(height: AppSpacing.sm),
            LinearProgressIndicator(value: _progress == 0 ? null : _progress),
          ],
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
              FilledButton.icon(
                onPressed: () => _order(context, ref),
                icon: const Icon(Icons.add_shopping_cart),
                label: const Text('Order PINs'),
              ),
              const SizedBox(height: AppSpacing.md),
              Text(
                'Selling over the counter is done from the web portal, where '
                'the receipt trail lives.',
                style:
                    AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _order(BuildContext context, WidgetRef ref) async {
    final ordered = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _OrderPinsSheet(),
    );

    // The PINs arrive when the gateway's callback reaches the server, not when
    // the checkout closes — so this says "on the way", never "added".
    if ((ordered ?? false) && context.mounted) {
      ref.invalidate(pinInventoryProvider);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Payment sent for confirmation. Your PINs are added to stock once '
            'it clears.',
          ),
        ),
      );
    }
  }
}

final pinPricingProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.pinPriceTiers));
});

/// The per-PIN price for an order of this size — the same rule as the web
/// portal's `quote()`. Display only: the server prices the order itself.
num? _quote(Map<String, dynamic> pricing, int quantity) {
  if (quantity < 1) return null;
  if (pricing['negotiated'] == true && pricing['unit_price'] != null) {
    return numOf(pricing['unit_price']);
  }

  final tiers = listOf(pricing['tiers'])
    ..sort((a, b) => intOf(b['min_quantity']).compareTo(intOf(a['min_quantity'])));

  for (final tier in tiers) {
    if (quantity >= intOf(tier['min_quantity'])) return numOf(tier['unit_price']);
  }
  return null;
}

/// Buys a batch of result-checker PINs from SchoolPilot.
///
/// Sends a quantity and a gateway and nothing else: the server computes the
/// price (honouring any negotiated rate) and returns a checkout link.
class _OrderPinsSheet extends ConsumerStatefulWidget {
  const _OrderPinsSheet();

  @override
  ConsumerState<_OrderPinsSheet> createState() => _OrderPinsSheetState();
}

class _OrderPinsSheetState extends ConsumerState<_OrderPinsSheet> {
  final _quantity = TextEditingController(text: '100');
  String _gateway = 'paystack';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _quantity.dispose();
    super.dispose();
  }

  int get _qty => int.tryParse(_quantity.text.trim()) ?? 0;

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final res = await ref.read(apiClientProvider).post<Map<String, dynamic>>(
        Api.pinBatches,
        body: {'quantity': _qty, 'gateway': _gateway},
      );

      final url = stringOf(res['authorization_url']);
      if (!mounted) return;
      if (url.isEmpty) {
        setState(() {
          _busy = false;
          _error = 'The payment page could not be opened. Please try again.';
        });
        return;
      }

      await GatewayCheckout.open(context, url: url, title: 'Pay for PINs');
      if (mounted) Navigator.pop(context, true);
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
    final pricing = ref.watch(pinPricingProvider);

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          AppSpacing.lg,
          AppSpacing.lg,
          AppSpacing.lg,
          AppSpacing.lg + MediaQuery.of(context).viewInsets.bottom,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Order PINs', style: AppText.headlineSm),
            const SizedBox(height: AppSpacing.md),
            TextField(
              controller: _quantity,
              keyboardType: TextInputType.number,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(labelText: 'How many PINs'),
            ),
            const SizedBox(height: AppSpacing.md),
            DropdownButtonFormField<String>(
              initialValue: _gateway,
              decoration: const InputDecoration(labelText: 'Pay with'),
              items: const [
                DropdownMenuItem(value: 'paystack', child: Text('Paystack')),
                DropdownMenuItem(
                  value: 'flutterwave',
                  child: Text('Flutterwave'),
                ),
              ],
              onChanged: _busy
                  ? null
                  : (v) => setState(() => _gateway = v ?? 'paystack'),
            ),
            const SizedBox(height: AppSpacing.md),
            pricing.when(
              loading: () => const LinearProgressIndicator(),
              error: (_, __) => Text(
                'Price is confirmed on the payment page.',
                style: AppText.bodyMd
                    .copyWith(color: AppColors.onSurfaceVariant),
              ),
              data: (data) {
                final unit = _quote(data, _qty);
                return Text(
                  unit == null
                      ? 'Price is confirmed on the payment page.'
                      : '${Money.format(unit)} per PIN · '
                          'Total ${Money.format(unit * _qty)}',
                  style: AppText.bodyLg,
                );
              },
            ),
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(
                _error!,
                style: AppText.bodyMd.copyWith(color: AppColors.error),
              ),
            ],
            const SizedBox(height: AppSpacing.lg),
            FilledButton(
              onPressed: _busy || _qty < 1 ? null : _submit,
              child: _busy
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Continue to payment'),
            ),
          ],
        ),
      ),
    );
  }
}
