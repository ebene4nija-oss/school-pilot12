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

/// Everything waiting on the administrator, from `GET /approvals`.
final approvalsProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.approvals));
});

const approvalSections = <String, String>{
  'leave': 'Staff leave',
  'lesson_note': 'Lesson notes',
  'cbt_exam': 'CBT exams',
  'admission': 'Admission applications',
  'broadsheet': 'Broadsheets',
};

/// One possible answer to an item: what the button says, whether it needs a
/// reason, and the request it makes.
class ApprovalAction {
  const ApprovalAction(this.label, {this.primary = false, this.needsReason = false, required this.send});

  final String label;
  final bool primary;
  final bool needsReason;
  final Future<dynamic> Function(ApiClient api, int id, String? reason) send;
}

/// The decisions each kind takes — each through that kind's own endpoint, so
/// its rules (documents before an offer, whose step a broadsheet is on) hold.
final approvalActions = <String, List<ApprovalAction>>{
  'leave': [
    ApprovalAction('Approve', primary: true, send: (api, id, r) => api.post<dynamic>(Api.leaveDecision(id), body: {'decision': 'approved', if (r != null) 'decision_note': r})),
    ApprovalAction('Decline', needsReason: true, send: (api, id, r) => api.post<dynamic>(Api.leaveDecision(id), body: {'decision': 'rejected', 'decision_note': r})),
  ],
  'lesson_note': [
    ApprovalAction('Approve', primary: true, send: (api, id, r) => api.post<dynamic>(Api.learningMaterialReview(id), body: {'approve': true})),
    ApprovalAction('Send back', needsReason: true, send: (api, id, r) => api.post<dynamic>(Api.learningMaterialReview(id), body: {'approve': false, 'note': r})),
  ],
  'cbt_exam': [
    ApprovalAction('Approve', primary: true, send: (api, id, r) => api.post<dynamic>(Api.cbtApproval(id), body: {'approve': true})),
    ApprovalAction('Send back', needsReason: true, send: (api, id, r) => api.post<dynamic>(Api.cbtApproval(id), body: {'approve': false, 'note': r})),
  ],
  'admission': [
    ApprovalAction('Offer', primary: true, send: (api, id, r) => api.post<dynamic>(Api.admissionDecision(id), body: {'decision': 'offer'})),
    ApprovalAction('Waitlist', send: (api, id, r) => api.post<dynamic>(Api.admissionDecision(id), body: {'decision': 'waitlist'})),
    ApprovalAction('Decline', needsReason: true, send: (api, id, r) => api.post<dynamic>(Api.admissionDecision(id), body: {'decision': 'reject', 'reason': r})),
  ],
  'broadsheet': [
    ApprovalAction('Approve', primary: true, send: (api, id, r) => api.post<dynamic>(Api.broadsheetApprove(id), body: const {})),
    ApprovalAction('Send back', needsReason: true, send: (api, id, r) => api.post<dynamic>(Api.broadsheetReject(id), body: {'reason': r})),
  ],
};

class ApprovalsScreen extends ConsumerWidget {
  const ApprovalsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final approvals = ref.watch(approvalsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Approvals')),
      body: approvals.when(
        loading: () => const LoadingState(rows: 5),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(approvalsProvider),
        ),
        data: (data) {
          final items = listOf(data['items']);
          if (items.isEmpty) {
            return const EmptyState(
              icon: Icons.task_alt,
              title: 'Nothing waiting',
              message: 'Leave requests, lesson notes, CBT papers, admissions and '
                  'broadsheets that need you will appear here.',
            );
          }

          return RefreshableList(
            onRefresh: () async => ref.refresh(approvalsProvider.future),
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, 0, AppSpacing.screenMargin, AppSpacing.xl),
            children: [
              for (final section in approvalSections.entries)
                if (items.any((i) => i['type'] == section.key)) ...[
                  SectionHeader('${section.value} (${items.where((i) => i['type'] == section.key).length})'),
                  for (final item in items.where((i) => i['type'] == section.key))
                    Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: _ApprovalCard(item: item),
                    ),
                ],
            ],
          );
        },
      ),
    );
  }
}

class _ApprovalCard extends ConsumerStatefulWidget {
  const _ApprovalCard({required this.item});

  final Map<String, dynamic> item;

  @override
  ConsumerState<_ApprovalCard> createState() => _ApprovalCardState();
}

class _ApprovalCardState extends ConsumerState<_ApprovalCard> {
  bool _busy = false;

  Future<String?> _askReason(String action) {
    final controller = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(action),
        content: TextField(
          controller: controller,
          autofocus: true,
          minLines: 2,
          maxLines: 4,
          decoration: const InputDecoration(labelText: 'Reason — they will see this'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('Cancel')),
          FilledButton(
            onPressed: () {
              final text = controller.text.trim();
              if (text.length >= 3) Navigator.pop(dialogContext, text);
            },
            child: Text(action),
          ),
        ],
      ),
    ).whenComplete(controller.dispose);
  }

  Future<void> _decide(ApprovalAction action) async {
    String? reason;
    if (action.needsReason) {
      reason = await _askReason(action.label);
      if (reason == null) return;
    }
    setState(() => _busy = true);
    try {
      await action.send(ref.read(apiClientProvider), intOf(widget.item['id']), reason);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('${action.label}: ${stringOf(widget.item['title'])}')),
      );
      ref.invalidate(approvalsProvider);
    } catch (error) {
      if (!mounted) return;
      setState(() => _busy = false);
      // The server says why (e.g. "Required documents are not yet received").
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(asApiException(error).message)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    final actions = approvalActions[item['type']] ?? const <ApprovalAction>[];
    final note = stringOf(item['note']);

    return OutlinedCard(
      statusColor: AppColors.pending,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(stringOf(item['title']), style: AppText.bodyLg),
          Text(
            [
              stringOf(item['detail']),
              if (Dates.tryParse(item['submitted_at']) != null) 'sent ${Dates.dayMonth(Dates.tryParse(item['submitted_at']))}',
            ].where((s) => s.isNotEmpty).join(' · '),
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          if (note.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('“$note”', style: AppText.bodyMd, maxLines: 3, overflow: TextOverflow.ellipsis),
            ),
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.sm,
            children: [
              for (final a in actions)
                a.primary
                    ? FilledButton(onPressed: _busy ? null : () => _decide(a), child: Text(a.label))
                    : OutlinedButton(onPressed: _busy ? null : () => _decide(a), child: Text(a.label)),
            ],
          ),
        ],
      ),
    );
  }
}
