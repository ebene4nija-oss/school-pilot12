import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../dashboard/quick_actions.dart';

final clinicVisitsProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'health:clinic-visits',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.clinicVisits),
  );
});

/// The sick bay's log: recent visits, and recording a new one.
///
/// Medical detail is the most sensitive thing the school holds, so this shows
/// only what the clinic log itself records — symptoms and treatment — and
/// finds a student by the minimal lookup (name, admission number, class).
class ClinicScreen extends ConsumerWidget {
  const ClinicScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Sick bay'),
        actions: [
          IconButton(
            onPressed: () => context.push('/notifications'),
            icon: const Icon(Icons.notifications_outlined),
            tooltip: 'Notifications',
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final saved = await showModalBottomSheet<bool>(
            context: context,
            isScrollControlled: true,
            builder: (_) => const RecordVisitSheet(),
          );
          if (saved == true) ref.invalidate(clinicVisitsProvider);
        },
        icon: const Icon(Icons.add),
        label: const Text('Record a visit'),
      ),
      body: CachedView<dynamic>(
        value: ref.watch(clinicVisitsProvider),
        onRetry: () => ref.invalidate(clinicVisitsProvider),
        builder: (context, data, meta) {
          final visits = listOf(data);
          if (visits.isEmpty) {
            return const Column(
              children: [
                QuickActionsRow(),
                Expanded(
                  child: EmptyState(
                    icon: Icons.medical_services_outlined,
                    title: 'No visits recorded',
                    message: 'Visits to the sick bay appear here once they are recorded.',
                  ),
                ),
              ],
            );
          }

          return RefreshableList(
            onRefresh: () async {
              ref.invalidate(clinicVisitsProvider);
              await ref.read(clinicVisitsProvider.future);
            },
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.screenMargin,
              AppSpacing.screenMargin,
              AppSpacing.screenMargin,
              96,
            ),
            children: [
              const QuickActionsRow(inset: 0),
              for (final v in visits)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: OutlinedCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(stringOf(v['student_name'], 'Student'), style: AppText.bodyLg),
                            ),
                            Text(
                              Dates.dayAndTime(Dates.tryParse(v['visited_at'])),
                              style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
                            ),
                          ],
                        ),
                        if (stringOf(v['admission_number']).isNotEmpty)
                          Text(
                            stringOf(v['admission_number']),
                            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                          ),
                        const SizedBox(height: AppSpacing.sm),
                        Text('Symptoms: ${stringOf(v['symptoms'])}', style: AppText.bodyMd),
                        Text('Treatment: ${stringOf(v['treatment'])}', style: AppText.bodyMd),
                        if (stringOf(v['attending_nurse']).isNotEmpty)
                          Text(
                            'Seen by ${stringOf(v['attending_nurse'])}',
                            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
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
}

/// Find a student, then record what was wrong and what was done.
class RecordVisitSheet extends ConsumerStatefulWidget {
  const RecordVisitSheet({super.key});

  @override
  ConsumerState<RecordVisitSheet> createState() => _RecordVisitSheetState();
}

class _RecordVisitSheetState extends ConsumerState<RecordVisitSheet> {
  final _search = TextEditingController();
  final _symptoms = TextEditingController();
  final _treatment = TextEditingController();
  Timer? _debounce;
  List<Map<String, dynamic>> _matches = const [];
  Map<String, dynamic>? _student;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _symptoms.dispose();
    _treatment.dispose();
    super.dispose();
  }

  void _onSearch(String text) {
    _debounce?.cancel();
    if (text.trim().length < 2) {
      setState(() => _matches = const []);
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 350), () async {
      try {
        final res = await ref.read(apiClientProvider).get<dynamic>(
          Api.studentLookup,
          query: {'search': text.trim()},
        );
        if (mounted) setState(() => _matches = listOf(res));
      } catch (error) {
        if (mounted) setState(() => _error = asApiException(error).message);
      }
    });
  }

  Future<void> _save() async {
    final student = _student;
    if (student == null || _symptoms.text.trim().isEmpty || _treatment.text.trim().isEmpty) {
      setState(() => _error = 'Choose the student and fill in symptoms and treatment.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiClientProvider).post<dynamic>(Api.clinicVisit, body: {
        'student_id': intOf(student['id']),
        'symptoms': _symptoms.text.trim(),
        'treatment': _treatment.text.trim(),
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
    final student = _student;

    return Padding(
      padding: EdgeInsets.only(
        left: AppSpacing.screenMargin,
        right: AppSpacing.screenMargin,
        top: AppSpacing.screenMargin,
        bottom: MediaQuery.of(context).viewInsets.bottom + AppSpacing.screenMargin,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Record a visit', style: AppText.headlineSm),
            const SizedBox(height: AppSpacing.md),
            if (student == null) ...[
              TextField(
                controller: _search,
                autofocus: true,
                decoration: const InputDecoration(
                  labelText: 'Student',
                  hintText: 'Name or admission number',
                  prefixIcon: Icon(Icons.search),
                ),
                onChanged: _onSearch,
              ),
              for (final m in _matches)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(stringOf(m['name'])),
                  subtitle: Text([stringOf(m['admission_number']), stringOf(m['class'])]
                      .where((s) => s.isNotEmpty)
                      .join(' · ')),
                  onTap: () => setState(() {
                    _student = m;
                    _matches = const [];
                  }),
                ),
            ] else
              OutlinedCard(
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(stringOf(student['name']), style: AppText.bodyLg),
                          Text(
                            [stringOf(student['admission_number']), stringOf(student['class'])]
                                .where((s) => s.isNotEmpty)
                                .join(' · '),
                            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                          ),
                        ],
                      ),
                    ),
                    TextButton(
                      onPressed: () => setState(() => _student = null),
                      child: const Text('Change'),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: AppSpacing.md),
            TextField(
              controller: _symptoms,
              minLines: 2,
              maxLines: 4,
              decoration: const InputDecoration(labelText: 'Symptoms'),
            ),
            const SizedBox(height: AppSpacing.sm),
            TextField(
              controller: _treatment,
              minLines: 2,
              maxLines: 4,
              decoration: const InputDecoration(labelText: 'Treatment given'),
            ),
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            ],
            const SizedBox(height: AppSpacing.md),
            FilledButton(
              onPressed: _busy ? null : _save,
              child: Text(_busy ? 'Saving…' : 'Save visit'),
            ),
          ],
        ),
      ),
    );
  }
}
