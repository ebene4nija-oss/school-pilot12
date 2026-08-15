import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/data/reference_data.dart';
import '../../core/ui/pickers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../students/students_screen.dart';

/// The teacher's Classes tab: rosters, the timetable, and homework.
class ClassesTab extends ConsumerWidget {
  const ClassesTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final classes = ref.watch(classesProvider);
    final myClasses = ref.watch(myClassesProvider).valueOrNull ?? const [];

    return Scaffold(
      appBar: AppBar(title: const Text('Classes')),
      body: Column(
        children: [
          const ClassTermBar(),
          Expanded(
            child: classes.when(
              loading: () => const LoadingState(rows: 3),
              error: (_, __) => const MissingReferenceData(what: 'classes'),
              data: (list) => ListView(
                padding: const EdgeInsets.all(AppSpacing.screenMargin),
                children: [
                  OutlinedCard(
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => const StudentsScreen(),
                      ),
                    ),
                    child: const _Row(
                      icon: Icons.groups_outlined,
                      title: 'Student roster',
                      detail: 'Everyone you teach',
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  OutlinedCard(
                    onTap: () => context.push('/timetable'),
                    child: const _Row(
                      icon: Icons.calendar_month_outlined,
                      title: 'My timetable',
                      detail: 'The published week',
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  OutlinedCard(
                    onTap: () => context.push('/messages'),
                    child: const _Row(
                      icon: Icons.forum_outlined,
                      title: 'Parent messages',
                      detail: 'Conversations about your students',
                    ),
                  ),
                  // The classes this teacher actually owns, with the role that
                  // makes it theirs. Absent for a subject teacher who holds no
                  // form class, which is the common case.
                  if (myClasses.isNotEmpty) ...[
                    const SectionHeader('My classes'),
                    for (final c in myClasses)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: OutlinedCard(
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment:
                                      CrossAxisAlignment.start,
                                  children: [
                                    Text(c.name, style: AppText.bodyLg),
                                    Text(
                                      '${c.studentCount} '
                                      '${c.studentCount == 1 ? 'student' : 'students'}',
                                      style: AppText.bodyMd.copyWith(
                                        color: AppColors.onSurfaceVariant,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              StatusChip(
                                c.isFormTeacher ? 'Form teacher' : 'Assistant',
                                color: c.isFormTeacher
                                    ? AppColors.primaryContainer
                                    : AppColors.onSurfaceVariant,
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],

                  if (list.isNotEmpty) ...[
                    const SectionHeader('All classes'),
                    for (final c in list)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: OutlinedCard(
                          child: Text(c.name, style: AppText.bodyLg),
                        ),
                      ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.icon, required this.title, required this.detail});

  final IconData icon;
  final String title;
  final String detail;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, color: AppColors.primaryContainer),
        const SizedBox(width: AppSpacing.md),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: AppText.bodyLg),
              Text(
                detail,
                style: AppText.bodyMd
                    .copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
          ),
        ),
        const Icon(Icons.chevron_right, color: AppColors.outline),
      ],
    );
  }
}
