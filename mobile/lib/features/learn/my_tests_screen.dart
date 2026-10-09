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
import 'learn_tab.dart';

/// Every paper set for the student's class, with their attempts.
final myTestsProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'me:tests',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.myTests),
  );
});

const _finalised = {'submitted', 'graded', 'expired'};

/// The student's CBT in one place: what they can sit now, what is coming,
/// and how the ones they have sat went.
class MyTestsScreen extends ConsumerWidget {
  const MyTestsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tests = ref.watch(myTestsProvider);
    // "Open now" comes from /cbt/available, which knows resume and attempts left.
    final available = listOf(ref.watch(availableExamsProvider).valueOrNull?.data, ['exams', 'available']);

    return Scaffold(
      appBar: AppBar(title: const Text('My tests')),
      body: CachedView<dynamic>(
        value: tests,
        onRetry: () => ref.invalidate(myTestsProvider),
        builder: (context, data, meta) {
          final all = listOf(data, ['tests']);
          final upcoming = all.where((t) => t['state'] == 'upcoming').toList()
            ..sort((a, b) => stringOf(a['opens_at']).compareTo(stringOf(b['opens_at'])));
          final done = [
            for (final t in all)
              for (final a in listOf(t['attempts']))
                if (_finalised.contains(stringOf(a['status']))) {...a, 'title': t['title'], 'subject': t['subject']},
          ]..sort((a, b) => stringOf(b['submitted_at']).compareTo(stringOf(a['submitted_at'])));

          return RefreshableList(
            onRefresh: () async {
              ref.invalidate(availableExamsProvider);
              ref.invalidate(myTestsProvider);
              await ref.read(myTestsProvider.future);
            },
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, 0, AppSpacing.screenMargin, AppSpacing.xl),
            children: [
              FreshnessLine(fetchedAt: meta.fetchedAt, onRefresh: () => ref.invalidate(myTestsProvider)),
              const SectionHeader('Open now'),
              if (available.isEmpty)
                _note('Nothing to sit right now.')
              else
                for (final exam in available)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: ExamStartCard(
                      exam: exam,
                      onSubmitted: () => ref.invalidate(myTestsProvider),
                    ),
                  ),
              const SectionHeader('Coming up'),
              if (upcoming.isEmpty)
                _note('No tests scheduled yet.')
              else
                for (final t in upcoming)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: OutlinedCard(
                      statusColor: AppColors.late,
                      child: Row(
                        children: [
                          const Icon(Icons.schedule, color: AppColors.late),
                          const SizedBox(width: AppSpacing.md),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(stringOf(t['title'], 'Test'), style: AppText.bodyLg),
                                Text(
                                  [
                                    stringOf(t['subject']),
                                    '${intOf(t['duration_minutes'])} min',
                                  ].where((s) => s.isNotEmpty).join(' · '),
                                  style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                                ),
                              ],
                            ),
                          ),
                          StatusChip.pending('Opens ${Dates.dayAndTime(Dates.tryParse(t['opens_at']))}'),
                        ],
                      ),
                    ),
                  ),
              const SectionHeader('Done'),
              if (done.isEmpty)
                _note('Tests you have sat appear here.')
              else
                for (final a in done)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: OutlinedCard(
                      onTap: () => Navigator.of(context).push<void>(
                        MaterialPageRoute(
                          builder: (_) => TestResultScreen(
                            attemptId: intOf(a['attempt_id']),
                            title: stringOf(a['title'], 'Result'),
                          ),
                        ),
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(stringOf(a['title'], 'Test'), style: AppText.bodyLg),
                                Text(
                                  [
                                    stringOf(a['subject']),
                                    'Sat ${Dates.dayMonth(Dates.tryParse(a['submitted_at']))}',
                                  ].where((s) => s.isNotEmpty).join(' · '),
                                  style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                                ),
                              ],
                            ),
                          ),
                          a['percentage'] == null
                              ? const StatusChip('Awaiting result', color: AppColors.outline)
                              : Text('${numOf(a['percentage']).toStringAsFixed(0)}%', style: AppText.headlineSm),
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

  static Widget _note(String text) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: Text(text, style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant)),
      );
}

final _resultProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) async {
  return mapOf(await ref.watch(apiClientProvider).get<dynamic>(Api.cbtResult(id)));
});

/// One sat paper: the score, how each topic went, and every question with
/// the student's answer. Shown only once the school releases it.
class TestResultScreen extends ConsumerWidget {
  const TestResultScreen({super.key, required this.attemptId, required this.title});

  final int attemptId;
  final String title;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final result = ref.watch(_resultProvider(attemptId));

    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: result.when(
        loading: () => const LoadingState(rows: 5),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(_resultProvider(attemptId)),
        ),
        data: (data) {
          if (data['released'] == false) {
            return EmptyState(
              icon: Icons.hourglass_top,
              title: 'Submitted',
              message: stringOf(data['message'], 'Your school will release the result.'),
            );
          }

          final r = mapOf(data['result']);
          final topics = listOf(r['topic_breakdown']);
          final questions = listOf(r['questions']);
          final passed = r['passed'] == true;

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              OutlinedCard(
                statusColor: passed ? AppColors.present : AppColors.absent,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${numOf(r['percentage']).toStringAsFixed(1)}%', style: AppText.headlineSm),
                    Text(
                      '${numOf(r['raw_score'])} of ${numOf(r['max_score'])} marks'
                      '${stringOf(r['grade']).isEmpty ? '' : ' · Grade ${stringOf(r['grade'])}'}',
                      style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                    ),
                    if (r['requires_manual_grading'] == true)
                      Text(
                        'Your teacher is still marking the written answers.',
                        style: AppText.labelSm.copyWith(color: AppColors.late),
                      ),
                  ],
                ),
              ),
              if (topics.isNotEmpty) ...[
                const SectionHeader('By topic'),
                for (final t in topics)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${stringOf(t['topic'])} — ${intOf(t['correct'])}/${intOf(t['answered'])}',
                          style: AppText.bodyMd,
                        ),
                        const SizedBox(height: 4),
                        ProgressBar(
                          value: numOf(t['mastery_percentage']) / 100,
                          color: numOf(t['mastery_percentage']) >= 50 ? AppColors.present : AppColors.late,
                        ),
                      ],
                    ),
                  ),
              ],
              if (questions.isNotEmpty) const SectionHeader('Questions'),
              for (var i = 0; i < questions.length; i++)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: OutlinedCard(
                    statusColor: questions[i]['is_correct'] == true
                        ? AppColors.present
                        : questions[i]['is_correct'] == false
                            ? AppColors.absent
                            : AppColors.pending,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${i + 1}. ${stringOf(questions[i]['question'])}', style: AppText.bodyMd),
                        const SizedBox(height: 4),
                        Text(
                          'Your answer: ${_answer(questions[i]['your_response'])}',
                          style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                        ),
                        Text(
                          '${numOf(questions[i]['awarded_marks'])} / ${numOf(questions[i]['marks'])} marks',
                          style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
                        ),
                        if (stringOf(questions[i]['explanation']).isNotEmpty)
                          Text('Why: ${stringOf(questions[i]['explanation'])}', style: AppText.labelSm),
                        if (stringOf(questions[i]['feedback']).isNotEmpty)
                          Text('Teacher: ${stringOf(questions[i]['feedback'])}', style: AppText.labelSm),
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

  static String _answer(Object? r) {
    if (r == null) return '(no answer)';
    if (r is Map) return stringOf(r['text'] ?? r['answer'] ?? r['value'], '(no answer)');
    if (r is List) return r.map(stringOf).join(', ');
    final s = stringOf(r);
    return s.isEmpty ? '(no answer)' : s;
  }
}
