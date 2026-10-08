import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../dashboard/dashboard_providers.dart';
import '../dashboard/home_screen.dart';

/// Who may collect each child, and the guardian's consent choices.
///
/// Not cached: both change from the school's side too, and a stale "allowed
/// to collect" list is the wrong thing to show at the gate.
final safetyProvider = FutureProvider.autoDispose<SafetyData>((ref) async {
  final api = ref.watch(apiClientProvider);
  final pickups = await api.get<dynamic>(Api.pickupAuthorizations);
  List<Map<String, dynamic>> consents = const [];
  try {
    consents = listOf(await api.get<dynamic>(Api.parentConsents));
  } catch (_) {
    // A guardian record the school has not linked yet: show no consent on
    // record rather than failing the whole screen.
  }
  return SafetyData(pickups: listOf(pickups), consents: consents);
});

class SafetyData {
  const SafetyData({required this.pickups, required this.consents});

  final List<Map<String, dynamic>> pickups;
  final List<Map<String, dynamic>> consents;
}

class SafetyScreen extends ConsumerWidget {
  const SafetyScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final childId = ref.watch(selectedChildIdProvider);
    final safety = ref.watch(safetyProvider);

    return Scaffold(
      appBar: AppBar(title: const ChildSwitcher()),
      body: childId == null
          ? const EmptyState(
              icon: Icons.child_care_outlined,
              title: 'No child linked yet',
              message: 'Once the school links your child to your account, you can manage pickup and consent here.',
            )
          : safety.when(
              loading: () => const LoadingState(),
              error: (error, _) => ErrorState(
                error: asApiException(error),
                onRetry: () => ref.invalidate(safetyProvider),
              ),
              data: (data) => RefreshableSafety(
                childId: childId,
                pickups: data.pickups.where((p) => intOf(p['student_id']) == childId).toList(),
                consent: data.consents.where((c) => intOf(c['student_id']) == childId).firstOrNull,
              ),
            ),
    );
  }
}

class RefreshableSafety extends ConsumerWidget {
  const RefreshableSafety({super.key, required this.childId, required this.pickups, required this.consent});

  final int childId;
  final List<Map<String, dynamic>> pickups;
  final Map<String, dynamic>? consent;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return RefreshIndicator(
      onRefresh: () async => ref.refresh(safetyProvider.future),
      child: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          const SectionHeader('Who may collect my child'),
          Text(
            'The school checks this list at the gate. Only you can add or stop someone.',
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: AppSpacing.sm),
          if (pickups.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
              child: Text('Nobody added yet.', style: AppText.bodyMd),
            ),
          for (final p in pickups)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: OutlinedCard(
                statusColor: p['is_active'] == true ? AppColors.paid : AppColors.outline,
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(stringOf(p['authorized_person_name']), style: AppText.bodyLg),
                          Text(
                            '${stringOf(p['relationship'])} · ${stringOf(p['authorized_person_phone'])}',
                            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                          ),
                        ],
                      ),
                    ),
                    if (p['is_active'] == true)
                      TextButton(
                        onPressed: () => _revoke(context, ref, p),
                        child: Text('Stop', style: TextStyle(color: AppColors.error)),
                      )
                    else
                      const StatusChip('Stopped', color: AppColors.outline),
                  ],
                ),
              ),
            ),
          OutlinedButton.icon(
            onPressed: () => _add(context, ref),
            icon: const Icon(Icons.person_add_alt_outlined),
            label: const Text('Add someone'),
          ),
          const SizedBox(height: AppSpacing.xl),
          ConsentCard(
            // Rebuilt from the record whenever it changes, so a refresh or a
            // switch of child never leaves the boxes showing an old choice.
            key: ValueKey('$childId:${consent?['id']}:${consent?['consent_given']}:${consent?['ai_cross_border_consent_given']}:${consent?['withdrawn_at']}'),
            childId: childId,
            consent: consent,
          ),
          const SizedBox(height: AppSpacing.xl),
        ],
      ),
    );
  }

  Future<void> _revoke(BuildContext context, WidgetRef ref, Map<String, dynamic> p) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Stop this person collecting your child?'),
        content: Text(stringOf(p['authorized_person_name'])),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Stop')),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    await _send(context, ref, () => ref.read(apiClientProvider).post<dynamic>(Api.pickupRevoke(intOf(p['id']))), 'Stopped.');
  }

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => AddPickupSheet(childId: childId),
    );
    if (saved == true) ref.invalidate(safetyProvider);
  }
}

Future<void> _send(BuildContext context, WidgetRef ref, Future<dynamic> Function() call, String done) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    await call();
    messenger.showSnackBar(SnackBar(content: Text(done)));
    ref.invalidate(safetyProvider);
  } catch (error) {
    messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
  }
}

class AddPickupSheet extends ConsumerStatefulWidget {
  const AddPickupSheet({super.key, required this.childId});

  final int childId;

  @override
  ConsumerState<AddPickupSheet> createState() => _AddPickupSheetState();
}

class _AddPickupSheetState extends ConsumerState<AddPickupSheet> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _relationship = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _relationship.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if ([_name, _phone, _relationship].any((c) => c.text.trim().isEmpty)) {
      setState(() => _error = 'Fill in all three.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiClientProvider).post<dynamic>(Api.pickupAuthorization, body: {
        'student_id': widget.childId,
        'authorized_person_name': _name.text.trim(),
        'authorized_person_phone': _phone.text.trim(),
        'relationship': _relationship.text.trim(),
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
          Text('Add someone who may collect your child', style: AppText.headlineSm),
          const SizedBox(height: AppSpacing.md),
          TextField(controller: _name, decoration: const InputDecoration(labelText: 'Full name')),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            controller: _phone,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(labelText: 'Phone number'),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            controller: _relationship,
            decoration: const InputDecoration(labelText: 'Relationship', hintText: 'e.g. Aunt, Driver'),
          ),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
          ],
          const SizedBox(height: AppSpacing.md),
          FilledButton(onPressed: _busy ? null : _save, child: Text(_busy ? 'Saving…' : 'Add')),
        ],
      ),
    );
  }
}

/// The guardian's consent under the NDPA, including the separate choice about
/// AI providers outside Nigeria. Never pre-ticked.
class ConsentCard extends ConsumerStatefulWidget {
  const ConsentCard({super.key, required this.childId, required this.consent});

  final int childId;
  final Map<String, dynamic>? consent;

  @override
  ConsumerState<ConsentCard> createState() => _ConsentCardState();
}

class _ConsentCardState extends ConsumerState<ConsentCard> {
  late bool _records = widget.consent?['consent_given'] == true;
  late bool _ai = widget.consent?['ai_cross_border_consent_given'] == true;
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final current = widget.consent;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SectionHeader('Your consent'),
        Text(
          'Under the Nigeria Data Protection Act. You can change your mind at any time.',
          style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
        ),
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: _records,
          onChanged: (v) => setState(() {
            _records = v ?? false;
            if (!_records) _ai = false;
          }),
          title: const Text('The school may keep and use my child’s records to teach and report on them.'),
        ),
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: _ai,
          onChanged: _records ? (v) => setState(() => _ai = v ?? false) : null,
          title: const Text(
            'AI features (the study tutor, draft report comments) may send my child’s schoolwork and marks to an AI '
            'provider outside Nigeria: Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini), whichever the school '
            'uses. My child’s name is not sent.',
          ),
        ),
        Row(
          children: [
            FilledButton(
              onPressed: _busy
                  ? null
                  : () async {
                      setState(() => _busy = true);
                      await _send(
                        context,
                        ref,
                        () => ref.read(apiClientProvider).post<dynamic>(Api.parentalConsent, body: {
                          'student_id': widget.childId,
                          'consent_given': _records,
                          'ai_cross_border_consent_given': _ai,
                        }),
                        'Saved.',
                      );
                      if (mounted) setState(() => _busy = false);
                    },
              child: const Text('Save my choice'),
            ),
            const SizedBox(width: AppSpacing.sm),
            if (current != null && current['consent_given'] == true)
              TextButton(
                onPressed: _busy
                    ? null
                    : () => _send(
                          context,
                          ref,
                          () => ref.read(apiClientProvider).post<dynamic>(Api.parentalConsentWithdraw(intOf(current['id']))),
                          'Consent withdrawn.',
                        ),
                child: Text('Withdraw', style: TextStyle(color: AppColors.error)),
              ),
          ],
        ),
      ],
    );
  }
}
