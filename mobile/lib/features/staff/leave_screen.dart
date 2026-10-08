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

final myLeaveProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'hr:my-leave',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.leave),
  );
});

const leaveTypes = {
  'annual': 'Annual',
  'sick': 'Sick',
  'maternity': 'Maternity',
  'compassionate': 'Compassionate',
  'study': 'Study',
};

Color leaveColour(String status) => switch (status) {
      'approved' => AppColors.paid,
      'rejected' => AppColors.absent,
      'cancelled' => AppColors.outline,
      _ => AppColors.pending,
    };

/// A member of staff's own leave: request it, see the decision, withdraw a
/// request that is still pending. Approval happens on the web portal.
class LeaveScreen extends ConsumerWidget {
  const LeaveScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('My leave')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final sent = await showModalBottomSheet<bool>(
            context: context,
            isScrollControlled: true,
            builder: (_) => const LeaveRequestSheet(),
          );
          if (sent == true) ref.invalidate(myLeaveProvider);
        },
        icon: const Icon(Icons.add),
        label: const Text('Request leave'),
      ),
      body: CachedView<dynamic>(
        value: ref.watch(myLeaveProvider),
        onRetry: () => ref.invalidate(myLeaveProvider),
        builder: (context, data, meta) {
          final rows = listOf(data);
          if (rows.isEmpty) {
            return const EmptyState(
              icon: Icons.beach_access_outlined,
              title: 'No leave requests',
              message: 'Requests you make appear here with the school’s decision.',
            );
          }

          return RefreshableList(
            onRefresh: () async {
              ref.invalidate(myLeaveProvider);
              await ref.read(myLeaveProvider.future);
            },
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.screenMargin, AppSpacing.screenMargin, 96),
            children: [
              for (final r in rows)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: OutlinedCard(
                    statusColor: leaveColour(stringOf(r['status'])),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                '${leaveTypes[stringOf(r['leave_type'])] ?? stringOf(r['leave_type'])} leave',
                                style: AppText.bodyLg,
                              ),
                            ),
                            StatusChip(stringOf(r['status'], 'pending'), color: leaveColour(stringOf(r['status']))),
                          ],
                        ),
                        Text(
                          '${Dates.full(Dates.tryParse(r['start_date']))} – ${Dates.full(Dates.tryParse(r['end_date']))}',
                          style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                        ),
                        if (stringOf(r['decision_note']).isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: Text('Note: ${stringOf(r['decision_note'])}', style: AppText.bodyMd),
                          ),
                        if (r['status'] == 'pending')
                          Align(
                            alignment: Alignment.centerRight,
                            child: TextButton(
                              onPressed: () => _cancel(context, ref, intOf(r['id'])),
                              child: const Text('Withdraw request'),
                            ),
                          ),
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

  Future<void> _cancel(BuildContext context, WidgetRef ref, int id) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      await ref.read(apiClientProvider).post<dynamic>(Api.leaveCancel(id));
      messenger.showSnackBar(const SnackBar(content: Text('Request withdrawn.')));
      ref.invalidate(myLeaveProvider);
    } catch (error) {
      messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
    }
  }
}

class LeaveRequestSheet extends ConsumerStatefulWidget {
  const LeaveRequestSheet({super.key});

  @override
  ConsumerState<LeaveRequestSheet> createState() => _LeaveRequestSheetState();
}

class _LeaveRequestSheetState extends ConsumerState<LeaveRequestSheet> {
  String _type = 'annual';
  DateTimeRange? _dates;
  final _reason = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _pickDates() async {
    final now = DateTime.now();
    final picked = await showDateRangePicker(
      context: context,
      firstDate: now.subtract(const Duration(days: 30)),
      lastDate: now.add(const Duration(days: 365)),
      initialDateRange: _dates,
    );
    if (picked != null) setState(() => _dates = picked);
  }

  Future<void> _send() async {
    final dates = _dates;
    if (dates == null) {
      setState(() => _error = 'Choose the dates.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiClientProvider).post<dynamic>(Api.leave, body: {
        'leave_type': _type,
        'start_date': Dates.apiDate(dates.start),
        'end_date': Dates.apiDate(dates.end),
        if (_reason.text.trim().isNotEmpty) 'reason': _reason.text.trim(),
      });
      if (mounted) Navigator.of(context).pop(true);
    } catch (error) {
      if (mounted) setState(() => _error = asApiException(error).message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final dates = _dates;

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
          Text('Request leave', style: AppText.headlineSm),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<String>(
            initialValue: _type,
            decoration: const InputDecoration(labelText: 'Type'),
            items: [for (final e in leaveTypes.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
            onChanged: (v) => setState(() => _type = v ?? 'annual'),
          ),
          const SizedBox(height: AppSpacing.sm),
          OutlinedButton.icon(
            onPressed: _pickDates,
            icon: const Icon(Icons.date_range_outlined),
            label: Text(dates == null ? 'Choose dates' : '${Dates.full(dates.start)} – ${Dates.full(dates.end)}'),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            controller: _reason,
            minLines: 2,
            maxLines: 4,
            maxLength: 1000,
            decoration: const InputDecoration(labelText: 'Reason (optional)'),
          ),
          if (_error != null) Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
          const SizedBox(height: AppSpacing.sm),
          FilledButton(onPressed: _busy ? null : _send, child: Text(_busy ? 'Sending…' : 'Send request')),
        ],
      ),
    );
  }
}
