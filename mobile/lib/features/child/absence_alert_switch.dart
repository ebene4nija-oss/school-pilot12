import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/providers.dart';
import '../dashboard/dashboard_providers.dart';

/// What the school offers, and which of my children I get absence alerts for.
///
/// Not cached: it is a preference the parent is about to change, and a stale
/// "on" would be a promise the school is not keeping.
final absenceAlertsProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final data = await api.get<dynamic>(Api.parentAbsenceAlerts);
  return data is Map<String, dynamic> ? data : <String, dynamic>{};
});

/// "Tell me when this child is marked absent" for the selected child.
///
/// Shown only when the school has switched absence alerts on; otherwise there
/// is nothing to opt in to and the switch would only confuse.
class AbsenceAlertSwitch extends ConsumerStatefulWidget {
  const AbsenceAlertSwitch({super.key});

  @override
  ConsumerState<AbsenceAlertSwitch> createState() => _AbsenceAlertSwitchState();
}

class _AbsenceAlertSwitchState extends ConsumerState<AbsenceAlertSwitch> {
  bool _busy = false;

  Future<void> _set(int childId, bool enabled) async {
    setState(() => _busy = true);

    try {
      await ref.read(apiClientProvider).put<dynamic>(
            Api.parentChildAbsenceAlerts(childId),
            body: {'enabled': enabled},
          );
      ref.invalidate(absenceAlertsProvider);
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
    final childId = ref.watch(selectedChildIdProvider);
    final alerts = ref.watch(absenceAlertsProvider);

    return alerts.maybeWhen(
      data: (data) {
        if (childId == null || data['offered'] != true) {
          return const SizedBox.shrink();
        }

        final children = (data['children'] as List?) ?? const [];
        final mine = children.whereType<Map>().firstWhere(
              (c) => c['student_id'] == childId,
              orElse: () => const {},
            );

        if (mine.isEmpty) return const SizedBox.shrink();

        final channels = (data['channels'] as List?) ?? const ['push'];
        final bySms = channels.contains('sms');

        return Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.screenMargin,
          ),
          child: SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Tell me if my child is absent'),
            subtitle: Text(
              bySms
                  ? 'A notification and a text message the day it happens.'
                  : 'A notification in this app the day it happens.',
            ),
            value: mine['enabled'] == true,
            onChanged: _busy ? null : (value) => _set(childId, value),
          ),
        );
      },
      // A preference is not worth an error panel above the attendance record.
      orElse: () => const SizedBox.shrink(),
    );
  }
}
