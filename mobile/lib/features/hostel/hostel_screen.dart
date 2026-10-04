import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final myHostelProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'me:hostel',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.myHostel),
  );
});

/// A boarder's hostel: where they sleep, whether they are signed in, their
/// leave and hostel charges (TASK 17). Read-only on the phone; leave and
/// repairs are requested from the house parent or the web portal.
class HostelScreen extends ConsumerWidget {
  const HostelScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Hostel')),
      body: CachedView<dynamic>(
        value: ref.watch(myHostelProvider),
        onRetry: () => ref.invalidate(myHostelProvider),
        builder: (context, data, meta) => ListView(
          padding: const EdgeInsets.all(AppSpacing.screenMargin),
          children: [HostelSummary(summary: mapOf(data))],
        ),
      ),
    );
  }
}

/// One boarder's summary; the parent's child screen shows the same card.
class HostelSummary extends StatelessWidget {
  const HostelSummary({super.key, required this.summary});

  final Map<String, dynamic> summary;

  static const _presence = {
    'in_hostel': ('In the hostel', AppColors.present),
    'on_leave': ('Away on leave', AppColors.pending),
    'checked_out': ('Checked out', AppColors.excused),
    'not_arrived': ('Not yet arrived', AppColors.excused),
  };

  static const _leaveStatus = {
    'submitted': 'Waiting for approval',
    'approved': 'Approved',
    'departed': 'Away',
    'returned': 'Back',
    'rejected': 'Not approved',
    'cancelled': 'Cancelled',
  };

  @override
  Widget build(BuildContext context) {
    final boarding = summary['boarding'];
    if (boarding is! Map) {
      return const EmptyState(
        icon: Icons.bed_outlined,
        title: 'Not a boarder',
        message: 'There is no boarding place on record.',
      );
    }
    final presence = _presence[stringOf(summary['presence'])];
    final leave = listOf(summary['leave']);
    final charges = listOf(summary['charges']);
    final place = [stringOf(summary['hostel']), if (summary['room'] != null) 'Room ${stringOf(summary['room'])}', if (summary['bed'] != null) 'Bed ${stringOf(summary['bed'])}']
        .where((s) => s.isNotEmpty)
        .join(' · ');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        OutlinedCard(
          statusColor: presence?.$2,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(place.isEmpty ? 'Bed not yet allocated' : place, style: AppText.bodyLg),
              Text(
                'Boarding: ${stringOf(boarding['category'], stringOf(boarding['status']))}',
                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
              if (presence != null) ...[
                const SizedBox(height: AppSpacing.sm),
                StatusChip(presence.$1, color: presence.$2),
              ],
            ],
          ),
        ),
        if (leave.isNotEmpty) ...[
          const SectionHeader('Leave'),
          for (final l in leave)
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(stringOf(l['leave_type'], 'Leave').replaceAll('_', ' ')),
              subtitle: Text(
                '${Dates.dayAndTime(Dates.tryParse(l['depart_at'])?.toLocal())} → '
                '${Dates.dayAndTime(Dates.tryParse(l['return_at'])?.toLocal())}',
              ),
              trailing: StatusChip(_leaveStatus[stringOf(l['status'])] ?? stringOf(l['status'])),
            ),
        ],
        if (charges.isNotEmpty) ...[
          const SectionHeader('Hostel charges'),
          for (final c in charges)
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(stringOf(c['title'])),
              subtitle: Text(stringOf(c['status'])),
              trailing: MoneyText(numOf(c['amount'])),
            ),
        ],
      ],
    );
  }
}
