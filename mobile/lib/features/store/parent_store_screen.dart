import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final parentStoreProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'parent:inventory',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.parentInventory),
  );
});

/// Items the school handed to the parent's children (uniforms, books and
/// so on). The parent ticks what arrived; anything missing needs a short
/// note, and the school is told. Only the parent confirms, never the child.
class ParentStoreScreen extends ConsumerWidget {
  const ParentStoreScreen({super.key});

  static const _status = {
    'awaiting': ('Please confirm', AppColors.pending),
    'confirmed': ('Received', AppColors.present),
    'disputed': ('Reported missing', AppColors.absent),
    'resolved': ('Sorted out', AppColors.excused),
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Items from the school')),
      body: CachedView<dynamic>(
        value: ref.watch(parentStoreProvider),
        onRetry: () => ref.invalidate(parentStoreProvider),
        builder: (context, data, meta) {
          final body = mapOf(data);
          final issues = listOf(body['data']).map(mapOf).toList();

          if (body['enabled'] != true || issues.isEmpty) {
            return const EmptyState(
              icon: Icons.checkroom_outlined,
              title: 'Nothing to confirm',
              message: 'When the school hands your child uniforms, books or other items, they show here for you to confirm.',
            );
          }

          final waiting = issues.where((i) => i['status'] == 'awaiting').toList();
          final rest = issues.where((i) => i['status'] != 'awaiting').toList();

          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(parentStoreProvider),
            child: ListView(
              padding: const EdgeInsets.all(AppSpacing.screenMargin),
              children: [
                if (waiting.isNotEmpty) ...[
                  const SectionHeader('Please confirm'),
                  for (final issue in waiting) _ConfirmCard(issue: issue),
                ],
                if (rest.isNotEmpty) ...[
                  const SectionHeader('Earlier'),
                  for (final issue in rest)
                    Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: OutlinedCard(
                        statusColor: _status[stringOf(issue['status'])]?.$2,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(child: Text(_title(issue), style: AppText.bodyLg)),
                                StatusChip(
                                  _status[stringOf(issue['status'])]?.$1 ?? stringOf(issue['status']),
                                  color: _status[stringOf(issue['status'])]?.$2 ?? AppColors.onSurfaceVariant,
                                ),
                              ],
                            ),
                            const SizedBox(height: AppSpacing.xs),
                            Text(
                              listOf(issue['lines']).map((l) => '${numOf(l['quantity']).toInt()} × ${stringOf(l['item_name'])}').join(', '),
                              style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

String _title(Map<String, dynamic> issue) {
  final when = Dates.tryParse(issue['issued_at'])?.toLocal();
  final name = stringOf(issue['student_name'], 'Your child');
  return when == null ? name : '$name · ${Dates.dayAndTime(when)}';
}

class _ConfirmCard extends ConsumerStatefulWidget {
  const _ConfirmCard({required this.issue});

  final Map<String, dynamic> issue;

  @override
  ConsumerState<_ConfirmCard> createState() => _ConfirmCardState();
}

class _ConfirmCardState extends ConsumerState<_ConfirmCard> {
  final _received = <int, bool>{};
  final _note = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  List<Map<String, dynamic>> get _pending =>
      listOf(widget.issue['lines']).map(mapOf).where((l) => l['status'] == 'pending').toList();

  bool get _anyMissing => _pending.any((l) => _received[numOf(l['id']).toInt()] == false);

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.parentInventoryConfirm(numOf(widget.issue['id']).toInt()),
        body: {
          'lines': [
            for (final l in _pending) {'id': numOf(l['id']).toInt(), 'received': _received[numOf(l['id']).toInt()] ?? true},
          ],
          'note': _anyMissing ? _note.text.trim() : null,
        },
      ));
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(stringOf(res['message'], 'Thank you.'))));
      ref.invalidate(parentStoreProvider);
    } catch (error) {
      final failure = asApiException(error);
      setState(() {
        _busy = false;
        _error = failure.isOffline ? 'You need a connection to confirm. Try again when you are back online.' : failure.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final lines = listOf(widget.issue['lines']).map(mapOf).toList();
    final missing = _anyMissing;

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: OutlinedCard(
        statusColor: AppColors.pending,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(_title(widget.issue), style: AppText.bodyLg),
            const SizedBox(height: AppSpacing.xs),
            Text('Tick what arrived. Untick anything that did not.', style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
            const SizedBox(height: AppSpacing.sm),
            // Plain rows, not ListTiles: OutlinedCard measures its child with
            // IntrinsicHeight, which ListTile reports wrongly (it overflowed).
            for (final l in lines)
              _LineRow(
                line: l,
                checked: l['status'] == 'pending' ? (_received[numOf(l['id']).toInt()] ?? true) : null,
                onChanged: _busy ? null : (v) => setState(() => _received[numOf(l['id']).toInt()] = v),
              ),
            if (missing) ...[
              const SizedBox(height: AppSpacing.sm),
              TextField(
                controller: _note,
                maxLength: 500,
                enabled: !_busy,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(labelText: 'What is missing?', hintText: 'e.g. The atlas was not in her bag'),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            ],
            const SizedBox(height: AppSpacing.sm),
            FilledButton(
              onPressed: _busy || (missing && _note.text.trim().isEmpty) ? null : _send,
              child: Text(missing ? 'Tell the school what is missing' : 'Yes, we received everything'),
            ),
          ],
        ),
      ),
    );
  }
}

/// One item: a checkbox while it waits for the parent, a tick once answered.
class _LineRow extends StatelessWidget {
  const _LineRow({required this.line, required this.checked, required this.onChanged});

  final Map<String, dynamic> line;

  /// null once the line has been answered.
  final bool? checked;
  final ValueChanged<bool>? onChanged;

  @override
  Widget build(BuildContext context) {
    final qty = numOf(line['quantity']).toInt();
    final name = '$qty × ${stringOf(line['item_name'])}${line['variant'] != null ? ' (${stringOf(line['variant'])})' : ''}';
    final price = line['charged'] == true ? '${Money.format(numOf(line['unit_price']) * qty)} on this term’s fees' : 'No charge';

    final text = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(name, style: AppText.bodyLg),
        Text(checked == null ? stringOf(line['status']) : price, style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
      ],
    );

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
      child: InkWell(
        onTap: checked == null || onChanged == null ? null : () => onChanged!(!checked!),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (checked != null)
              Checkbox(value: checked, onChanged: onChanged == null ? null : (v) => onChanged!(v ?? true))
            else
              const Padding(
                padding: EdgeInsets.all(12),
                child: Icon(Icons.check_circle_outline, color: AppColors.present, size: 24),
              ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(child: Padding(padding: const EdgeInsets.only(top: 10), child: text)),
          ],
        ),
      ),
    );
  }
}
