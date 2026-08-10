import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/pickers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import 'score_entry_screen.dart';

final mySubjectsProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final user = ref.watch(currentUserProvider);
  if (user == null) return const [];

  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  final cached = await cachedGet<dynamic>(
    cache: cache,
    key: 'teacher:subjects:${user.id}',
    fetch: () => api.get<dynamic>(Api.teacherSubjects(user.id)),
  );

  return listOf(cached.data, ['subjects', 'assignments']);
});

class GradebookTab extends ConsumerWidget {
  const GradebookTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final subjects = ref.watch(mySubjectsProvider);
    final classId = ref.watch(selectedClassProvider);
    final termId = ref.watch(selectedTermProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Gradebook')),
      body: Column(
        children: [
          const ClassTermBar(),
          Expanded(
            child: subjects.when(
              loading: () => const LoadingState(rows: 3),
              error: (_, __) => const MissingReferenceData(what: 'subjects'),
              data: (list) => ListView(
                padding: const EdgeInsets.all(AppSpacing.screenMargin),
                children: [
                  OutlinedCard(
                    statusColor: AppColors.late,
                    onTap: () => context.push('/gradebook/comments'),
                    child: Row(
                      children: [
                        const Icon(Icons.auto_awesome_outlined,
                            color: AppColors.secondary),
                        const SizedBox(width: AppSpacing.md),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('AI comment review', style: AppText.bodyLg),
                              Text(
                                'Approve or edit before anything reaches a report card',
                                style: AppText.bodyMd.copyWith(
                                  color: AppColors.onSurfaceVariant,
                                ),
                              ),
                            ],
                          ),
                        ),
                        const Icon(Icons.chevron_right,
                            color: AppColors.outline),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  Text('Enter scores', style: AppText.headlineSm),
                  const SizedBox(height: AppSpacing.sm),
                  if (list.isEmpty)
                    Text(
                      'You have no subjects assigned yet. Your administrator '
                      'assigns these from the web portal.',
                      style: AppText.bodyMd
                          .copyWith(color: AppColors.onSurfaceVariant),
                    ),
                  for (final subject in list)
                    Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: OutlinedCard(
                        onTap: (classId == null || termId == null)
                            ? null
                            : () => Navigator.of(context).push(
                                  MaterialPageRoute<void>(
                                    builder: (_) => ScoreEntryScreen(
                                      subjectId: intOf(
                                        subject['subject_id'] ??
                                            subject['id'] ??
                                            subject['subject']?['id'],
                                      ),
                                      subjectName: stringOf(
                                        subject['name'] ??
                                            subject['subject']?['name'],
                                        'Subject',
                                      ),
                                      classId: classId,
                                      termId: termId,
                                    ),
                                  ),
                                ),
                        child: Row(
                          children: [
                            const Icon(Icons.edit_note,
                                color: AppColors.primaryContainer),
                            const SizedBox(width: AppSpacing.md),
                            Expanded(
                              child: Text(
                                stringOf(
                                  subject['name'] ?? subject['subject']?['name'],
                                  'Subject',
                                ),
                                style: AppText.bodyLg,
                              ),
                            ),
                            const Icon(Icons.chevron_right,
                                color: AppColors.outline),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
