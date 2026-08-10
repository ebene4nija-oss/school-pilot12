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
import '../../core/ui/widgets.dart';
import '../cbt/cbt_attempt_screen.dart';

final availableExamsProvider =
    FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<dynamic>(
    cache: cache,
    key: 'cbt:available',
    fetch: () => api.get<dynamic>(Api.cbtAvailable),
  );
});

/// The student's Learn tab: papers to sit, and the tutor.
///
/// Homework would live here too, but the backend has no student-facing
/// homework list — only submit-by-id (gap G5) — so it is not shown rather than
/// shown empty and blamed on the app.
class LearnTab extends ConsumerWidget {
  const LearnTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final exams = ref.watch(availableExamsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Learn')),
      body: CachedView<dynamic>(
        value: exams,
        onRetry: () => ref.invalidate(availableExamsProvider),
        builder: (context, data, meta) {
          final list = listOf(data, ['exams', 'available']);

          return RefreshableList(
            onRefresh: () async => ref.refresh(availableExamsProvider.future),
            children: [
              Padding(
                padding: const EdgeInsets.all(AppSpacing.screenMargin),
                child: OutlinedCard(
                  onTap: () => context.push('/learn/tutor'),
                  child: Row(
                    children: [
                      const Icon(Icons.auto_awesome_outlined,
                          color: AppColors.secondary),
                      const SizedBox(width: AppSpacing.md),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('AI tutor', style: AppText.bodyLg),
                            Text(
                              'Work through a problem step by step',
                              style: AppText.bodyMd.copyWith(
                                color: AppColors.onSurfaceVariant,
                              ),
                            ),
                          ],
                        ),
                      ),
                      const Icon(Icons.chevron_right, color: AppColors.outline),
                    ],
                  ),
                ),
              ),
              const SectionHeader('Exams and tests'),
              if (list.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(AppSpacing.screenMargin),
                  child: Text(
                    'Nothing to sit right now. Papers appear here when your '
                    'teacher publishes them.',
                    style: AppText.bodyMd,
                  ),
                ),
              for (final exam in list)
                Padding(
                  padding: const EdgeInsets.only(
                    left: AppSpacing.screenMargin,
                    right: AppSpacing.screenMargin,
                    bottom: AppSpacing.sm,
                  ),
                  child: _ExamCard(exam: exam),
                ),
              const SizedBox(height: AppSpacing.xl),
            ],
          );
        },
      ),
    );
  }
}

class _ExamCard extends ConsumerStatefulWidget {
  const _ExamCard({required this.exam});

  final Map<String, dynamic> exam;

  @override
  ConsumerState<_ExamCard> createState() => _ExamCardState();
}

class _ExamCardState extends ConsumerState<_ExamCard> {
  bool _starting = false;

  Future<void> _start() async {
    setState(() => _starting = true);

    try {
      final res = await ref.read(apiClientProvider).post<Map<String, dynamic>>(
            Api.cbtStart(intOf(widget.exam['id'])),
          );

      final attemptId = intOf(
        res['attempt_id'] ?? mapOf(res, ['attempt'])['id'] ?? res['id'],
      );

      if (!mounted) return;
      setState(() => _starting = false);

      final submitted = await Navigator.of(context).push<bool>(
        MaterialPageRoute(
          builder: (_) => CbtAttemptScreen(attemptId: attemptId),
        ),
      );

      if ((submitted ?? false) && mounted) {
        ref.invalidate(availableExamsProvider);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Paper submitted')),
        );
      }
    } catch (error) {
      if (!mounted) return;
      setState(() => _starting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(asApiException(error).message)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final exam = widget.exam;
    final opensAt = Dates.tryParse(exam['opens_at'] ?? exam['starts_at']);
    final open = opensAt == null || opensAt.isBefore(DateTime.now());
    final duration = intOf(exam['duration_minutes']);
    final questions = intOf(exam['question_count'] ?? exam['questions_count']);

    return OutlinedCard(
      statusColor: open ? AppColors.present : AppColors.late,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(stringOf(exam['title'], 'Exam'), style: AppText.bodyLg),
          const SizedBox(height: 4),
          Text(
            [
              stringOf(exam['subject']?['name'] ?? exam['subject_name']),
              if (questions > 0) '$questions questions',
              if (duration > 0) '$duration minutes',
            ].where((s) => s.isNotEmpty).join(' · '),
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          if (!open) ...[
            const SizedBox(height: AppSpacing.sm),
            StatusChip.pending('Opens ${Dates.dayAndTime(opensAt)}'),
          ],
          const SizedBox(height: AppSpacing.md),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: !open || _starting ? null : _start,
              child: Text(_starting ? 'Starting…' : 'Start'),
            ),
          ),
        ],
      ),
    );
  }
}
