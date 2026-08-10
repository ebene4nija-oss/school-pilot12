import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/auth/session.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import 'dashboard_providers.dart';

/// One route, four dashboards. Which one you get is decided by the role the
/// backend returned at login, not by anything the client chooses.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final role = ref.watch(currentRoleProvider);

    return switch (role) {
      UserRole.schoolAdmin || UserRole.superAdmin => const _AdminDashboard(),
      UserRole.teacher => const _TeacherDashboard(),
      UserRole.student => const _StudentDashboard(),
      UserRole.parent => const _ParentDashboard(),
    };
  }
}

class _DashboardScaffold extends ConsumerWidget {
  const _DashboardScaffold({
    required this.title,
    required this.builder,
    this.subtitle,
  });

  final String title;
  final String? subtitle;
  final List<Widget> Function(Map<String, dynamic> data) builder;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dashboard = ref.watch(dashboardProvider);

    return Scaffold(
      appBar: AppBar(
        titleSpacing: AppSpacing.screenMargin,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(title, style: AppText.headlineSm),
            if (subtitle != null)
              Text(
                subtitle!,
                style:
                    AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
              ),
          ],
        ),
        actions: [
          IconButton(
            onPressed: () => context.push('/notifications'),
            icon: const Icon(Icons.notifications_outlined),
            tooltip: 'Notifications',
          ),
          const SizedBox(width: AppSpacing.sm),
        ],
      ),
      body: CachedView<Map<String, dynamic>>(
        value: dashboard,
        onRetry: () => ref.invalidate(dashboardProvider),
        builder: (context, data, meta) => RefreshableList(
          onRefresh: () async => ref.refresh(dashboardProvider.future),
          children: [
            FreshnessLine(
              fetchedAt: meta.fetchedAt,
              onRefresh: () => ref.invalidate(dashboardProvider),
            ),
            ...builder(data),
            const SizedBox(height: AppSpacing.xl),
          ],
        ),
      ),
    );
  }
}

Widget _pad(Widget child) => Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin),
      child: child,
    );

Widget _tileRow(List<Widget> tiles) => _pad(
      Row(
        children: [
          for (var i = 0; i < tiles.length; i++) ...[
            if (i > 0) const SizedBox(width: AppSpacing.sm),
            Expanded(child: tiles[i]),
          ],
        ],
      ),
    );

// ---------------------------------------------------------------- admin

class _AdminDashboard extends ConsumerWidget {
  const _AdminDashboard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);

    return _DashboardScaffold(
      title: 'Dashboard',
      subtitle: user?.name,
      builder: (data) {
        final enrolment = intOf(data['total_students'] ?? data['enrolment']);
        final attendance = numOf(data['attendance_rate'] ?? data['attendance']);
        final collected = numOf(data['fees_collected'] ?? data['total_collected']);
        final outstanding =
            numOf(data['outstanding'] ?? data['total_outstanding']);
        final expected = collected + outstanding;
        final defaulters = intOf(data['defaulters'] ?? data['defaulter_count']);

        return [
          _tileRow([
            StatTile(
              label: 'Enrolment',
              value: '$enrolment',
              caption: 'students',
              onTap: () => context.go('/students'),
            ),
            StatTile(
              label: 'Attendance today',
              value: '${attendance.toStringAsFixed(1)}%',
            ),
          ]),
          const SizedBox(height: AppSpacing.sm),
          _pad(
            OutlinedCard(
              statusColor: outstanding > 0 ? AppColors.overdue : AppColors.paid,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'FEES COLLECTED THIS TERM',
                    style: AppText.labelSm.copyWith(
                      color: AppColors.onSurfaceVariant,
                      letterSpacing: 0.6,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  MoneyText(collected, size: 24),
                  const SizedBox(height: AppSpacing.sm),
                  ProgressBar(
                    value: expected == 0 ? 0 : collected / expected,
                    color: AppColors.paid,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    'of ${Money.format(expected)} expected · '
                    '${Money.format(outstanding)} outstanding',
                    style: AppText.labelSm
                        .copyWith(color: AppColors.onSurfaceVariant),
                  ),
                ],
              ),
            ),
          ),
          const SectionHeader('Needs attention'),
          if (defaulters > 0)
            _pad(
              OutlinedCard(
                statusColor: AppColors.overdue,
                onTap: () => context.go('/fees'),
                child: _AttentionRow(
                  icon: Icons.money_off,
                  title: '$defaulters fee ${defaulters == 1 ? 'defaulter' : 'defaulters'}',
                  detail: '${Money.format(outstanding)} outstanding',
                  color: AppColors.overdue,
                ),
              ),
            ),
          const SizedBox(height: AppSpacing.sm),
          _pad(
            OutlinedCard(
              statusColor: AppColors.pending,
              onTap: () => context.go('/academics'),
              child: const _AttentionRow(
                icon: Icons.lock_clock,
                title: 'Results release',
                detail: 'Check which classes are ready to publish',
                color: AppColors.pending,
              ),
            ),
          ),
        ];
      },
    );
  }
}

class _AttentionRow extends StatelessWidget {
  const _AttentionRow({
    required this.icon,
    required this.title,
    required this.detail,
    required this.color,
  });

  final IconData icon;
  final String title;
  final String detail;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, color: color),
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

// -------------------------------------------------------------- teacher

class _TeacherDashboard extends ConsumerWidget {
  const _TeacherDashboard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final hour = DateTime.now().hour;
    final greeting = hour < 12
        ? 'Good morning'
        : hour < 17
            ? 'Good afternoon'
            : 'Good evening';

    return _DashboardScaffold(
      title: '$greeting,',
      subtitle: user?.name,
      builder: (data) {
        final pendingComments =
            intOf(data['pending_comments'] ?? data['comments_pending']);
        final toGrade = intOf(data['ungraded'] ?? data['homework_to_grade']);
        final classes = listOf(data['classes'] ?? data['my_classes']);

        return [
          _pad(
            OutlinedCard(
              background: AppColors.primaryContainer,
              onTap: () => context.go('/register'),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'TODAY',
                          style: AppText.labelSm.copyWith(
                            color: AppColors.onPrimaryContainer,
                            letterSpacing: 0.6,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Take the register',
                          style: AppText.headlineSm
                              .copyWith(color: AppColors.onPrimary),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          Dates.dayMonth(DateTime.now()),
                          style: AppText.bodyMd
                              .copyWith(color: AppColors.onPrimaryContainer),
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.fact_check_outlined,
                      color: AppColors.onPrimary, size: 32),
                ],
              ),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          _tileRow([
            StatTile(
              label: 'Comments pending',
              value: '$pendingComments',
              caption: 'awaiting your approval',
              valueColor: pendingComments > 0 ? AppColors.late : null,
              onTap: () => context.push('/gradebook/comments'),
            ),
            StatTile(
              label: 'To grade',
              value: '$toGrade',
              caption: 'homework submissions',
              onTap: () => context.go('/classes'),
            ),
          ]),
          if (classes.isNotEmpty) ...[
            const SectionHeader('My classes'),
            for (final c in classes)
              Padding(
                padding: const EdgeInsets.only(
                  left: AppSpacing.screenMargin,
                  right: AppSpacing.screenMargin,
                  bottom: AppSpacing.sm,
                ),
                child: OutlinedCard(
                  onTap: () => context.go('/register'),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          stringOf(c['name'] ?? c['class_name'], 'Class'),
                          style: AppText.bodyLg,
                        ),
                      ),
                      if (c['student_count'] != null)
                        NumericText('${intOf(c['student_count'])} students'),
                    ],
                  ),
                ),
              ),
          ],
        ];
      },
    );
  }
}

// -------------------------------------------------------------- student

class _StudentDashboard extends ConsumerWidget {
  const _StudentDashboard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);

    return _DashboardScaffold(
      title: 'Hello, ${user?.name.split(' ').first ?? 'there'}',
      subtitle: user?.role.label,
      builder: (data) {
        final average = numOf(data['average'] ?? data['term_average']);
        final attendance = numOf(data['attendance_rate'] ?? data['attendance']);
        final homework = listOf(data['homework'] ?? data['due_soon']);

        return [
          _tileRow([
            StatTile(
              label: 'Average this term',
              value: '${average.toStringAsFixed(1)}%',
              onTap: () => context.go('/results'),
            ),
            StatTile(
              label: 'Attendance',
              value: '${attendance.toStringAsFixed(0)}%',
            ),
          ]),
          const SizedBox(height: AppSpacing.sm),
          _pad(
            OutlinedCard(
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
                        Text('Ask your tutor', style: AppText.bodyLg),
                        Text(
                          'Stuck on something? Work through it step by step.',
                          style: AppText.bodyMd
                              .copyWith(color: AppColors.onSurfaceVariant),
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right, color: AppColors.outline),
                ],
              ),
            ),
          ),
          if (homework.isNotEmpty) ...[
            const SectionHeader('Due soon'),
            for (final h in homework.take(5))
              Padding(
                padding: const EdgeInsets.only(
                  left: AppSpacing.screenMargin,
                  right: AppSpacing.screenMargin,
                  bottom: AppSpacing.sm,
                ),
                child: OutlinedCard(
                  statusColor: AppColors.pending,
                  onTap: () => context.go('/learn'),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              stringOf(h['title'], 'Homework'),
                              style: AppText.bodyLg,
                            ),
                            Text(
                              stringOf(h['subject']?['name'] ?? h['subject']),
                              style: AppText.bodyMd.copyWith(
                                color: AppColors.onSurfaceVariant,
                              ),
                            ),
                          ],
                        ),
                      ),
                      StatusChip.pending(
                        'Due ${Dates.dayMonth(Dates.tryParse(h['due_date']))}',
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ];
      },
    );
  }
}

// --------------------------------------------------------------- parent

class _ParentDashboard extends ConsumerWidget {
  const _ParentDashboard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final children = ref.watch(myChildrenProvider);
    final childId = ref.watch(selectedChildIdProvider);

    return Scaffold(
      appBar: AppBar(
        titleSpacing: AppSpacing.screenMargin,
        title: const ChildSwitcher(),
        actions: [
          IconButton(
            onPressed: () => context.push('/notifications'),
            icon: const Icon(Icons.notifications_outlined),
          ),
          const SizedBox(width: AppSpacing.sm),
        ],
      ),
      body: children.when(
        loading: () => const LoadingState(),
        error: (error, _) => const _NoChildrenLinked(),
        data: (list) {
          if (list.isEmpty || childId == null) return const _NoChildrenLinked();
          return const _ParentFeed();
        },
      ),
    );
  }
}

class _ParentFeed extends ConsumerWidget {
  const _ParentFeed();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final feed = ref.watch(parentFeedProvider);

    return CachedView<Map<String, dynamic>>(
      value: feed,
      onRetry: () => ref.invalidate(parentFeedProvider),
      builder: (context, data, meta) {
        final outstanding = numOf(
          data['fees']?['outstanding'] ?? data['outstanding_balance'],
        );
        final attendance = listOf(data['attendance'] ?? data['recent_attendance']);
        final behaviour = listOf(data['behavior'] ?? data['behaviour_reports']);
        final homework = listOf(data['homework']);
        final today = attendance.isEmpty ? null : attendance.first;

        return RefreshableList(
          onRefresh: () async => ref.refresh(parentFeedProvider.future),
          children: [
            FreshnessLine(
              fetchedAt: meta.fetchedAt,
              onRefresh: () => ref.invalidate(parentFeedProvider),
            ),
            if (outstanding > 0)
              _pad(
                OutlinedCard(
                  statusColor: AppColors.overdue,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('School fees', style: AppText.headlineSm),
                      const SizedBox(height: AppSpacing.sm),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.baseline,
                        textBaseline: TextBaseline.alphabetic,
                        children: [
                          MoneyText(outstanding,
                              size: 24, color: AppColors.overdue),
                          const SizedBox(width: AppSpacing.sm),
                          Text(
                            'outstanding',
                            style: AppText.bodyMd
                                .copyWith(color: AppColors.onSurfaceVariant),
                          ),
                        ],
                      ),
                      const SizedBox(height: AppSpacing.md),
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton(
                          onPressed: () => context.go('/fees'),
                          child: const Text('Pay now'),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            if (today != null) ...[
              const SizedBox(height: AppSpacing.sm),
              _pad(_AttendanceTodayCard(record: today)),
            ],
            const SizedBox(height: AppSpacing.sm),
            _pad(
              OutlinedCard(
                onTap: () => context.go('/results'),
                child: Row(
                  children: [
                    const Icon(Icons.assessment_outlined,
                        color: AppColors.secondary),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: Text('Check results', style: AppText.bodyLg),
                    ),
                    const Icon(Icons.chevron_right, color: AppColors.outline),
                  ],
                ),
              ),
            ),
            if (behaviour.isNotEmpty || homework.isNotEmpty)
              const SectionHeader('Recent updates'),
            for (final b in behaviour.take(5))
              Padding(
                padding: const EdgeInsets.only(
                  left: AppSpacing.screenMargin,
                  right: AppSpacing.screenMargin,
                  bottom: AppSpacing.sm,
                ),
                child: OutlinedCard(
                  statusColor: _behaviourColour(b),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          StatusChip(
                            stringOf(b['category'] ?? b['type'], 'Behaviour'),
                            color: _behaviourColour(b),
                          ),
                          const Spacer(),
                          Text(
                            Dates.dayMonth(
                              Dates.tryParse(b['created_at'] ?? b['date']),
                            ),
                            style: AppText.labelSm
                                .copyWith(color: AppColors.onSurfaceVariant),
                          ),
                        ],
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      Text(
                        stringOf(b['description'] ?? b['note'], '—'),
                        style: AppText.bodyMd,
                      ),
                    ],
                  ),
                ),
              ),
            for (final h in homework.take(5))
              Padding(
                padding: const EdgeInsets.only(
                  left: AppSpacing.screenMargin,
                  right: AppSpacing.screenMargin,
                  bottom: AppSpacing.sm,
                ),
                child: OutlinedCard(
                  child: Row(
                    children: [
                      const Icon(Icons.menu_book_outlined,
                          color: AppColors.onSurfaceVariant),
                      const SizedBox(width: AppSpacing.md),
                      Expanded(
                        child: Text(
                          stringOf(h['title'], 'Homework'),
                          style: AppText.bodyMd,
                        ),
                      ),
                      Text(
                        'Due ${Dates.dayMonth(Dates.tryParse(h['due_date']))}',
                        style: AppText.labelSm.copyWith(color: AppColors.late),
                      ),
                    ],
                  ),
                ),
              ),
            const SizedBox(height: AppSpacing.xl),
          ],
        );
      },
    );
  }

  static Color _behaviourColour(Map<String, dynamic> report) {
    final type = stringOf(report['category'] ?? report['type']).toLowerCase();
    return type.contains('concern') ||
            type.contains('negative') ||
            type.contains('incident')
        ? AppColors.absent
        : AppColors.present;
  }
}

class _AttendanceTodayCard extends StatelessWidget {
  const _AttendanceTodayCard({required this.record});

  final Map<String, dynamic> record;

  @override
  Widget build(BuildContext context) {
    final status = stringOf(record['status'], 'unknown').toLowerCase();
    final colour = switch (status) {
      'present' => AppColors.present,
      'absent' => AppColors.absent,
      'late' => AppColors.late,
      _ => AppColors.excused,
    };

    return OutlinedCard(
      statusColor: colour,
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'TODAY',
                  style: AppText.labelSm.copyWith(
                    color: AppColors.onSurfaceVariant,
                    letterSpacing: 0.6,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  status.isEmpty
                      ? 'Not marked'
                      : status[0].toUpperCase() + status.substring(1),
                  style: AppText.headlineSm.copyWith(color: colour),
                ),
              ],
            ),
          ),
          if (record['time_in'] != null)
            StatusChip(
              'arrived ${Dates.time(Dates.tryParse(record['time_in']))}',
              color: colour,
            ),
        ],
      ),
    );
  }
}

/// The pill in the parent app bar. A guardian with one child sees their name;
/// a guardian with several taps to switch, and every parent tab follows.
class ChildSwitcher extends ConsumerWidget {
  const ChildSwitcher({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final children = ref.watch(myChildrenProvider).valueOrNull ?? const [];
    final selectedId = ref.watch(selectedChildIdProvider);
    final selected = children.firstWhere(
      (c) => intOf(c['id']) == selectedId,
      orElse: () => const <String, dynamic>{},
    );

    final name = stringOf(
      selected['name'] ?? selected['full_name'] ?? selected['user']?['name'],
      'My child',
    );
    final className = stringOf(
      selected['class']?['name'] ?? selected['class_name'],
    );

    return InkWell(
      onTap: children.length < 2
          ? null
          : () => _pick(context, ref, children, selectedId),
      borderRadius: BorderRadius.circular(999),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 4),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            InitialsAvatar(name, size: 32),
            const SizedBox(width: AppSpacing.sm),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(name, style: AppText.labelLg),
                if (className.isNotEmpty)
                  Text(
                    className,
                    style: AppText.labelSm
                        .copyWith(color: AppColors.onSurfaceVariant),
                  ),
              ],
            ),
            if (children.length > 1)
              const Icon(Icons.expand_more, color: AppColors.onSurfaceVariant),
          ],
        ),
      ),
    );
  }

  void _pick(
    BuildContext context,
    WidgetRef ref,
    List<Map<String, dynamic>> children,
    int? selectedId,
  ) {
    showModalBottomSheet<void>(
      context: context,
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.all(AppSpacing.md),
              child: Text('Choose a child', style: AppText.headlineSm),
            ),
            for (final child in children)
              ListTile(
                leading: InitialsAvatar(stringOf(child['name'], '?')),
                title: Text(stringOf(child['name'], 'Child')),
                subtitle: Text(
                  stringOf(child['class']?['name'] ?? child['class_name']),
                ),
                trailing: intOf(child['id']) == selectedId
                    ? const Icon(Icons.check, color: AppColors.present)
                    : null,
                onTap: () {
                  ref.read(selectedChildIdProvider.notifier).state =
                      intOf(child['id']);
                  Navigator.pop(context);
                },
              ),
          ],
        ),
      ),
    );
  }
}

/// What a guardian sees while the backend has no way to tell the app which
/// children are theirs.
class _NoChildrenLinked extends StatelessWidget {
  const _NoChildrenLinked();

  @override
  Widget build(BuildContext context) {
    return const EmptyState(
      icon: Icons.family_restroom_outlined,
      title: 'No children linked yet',
      message:
          'Your account is not linked to a student record that this app can '
          'read. Ask the school office to link your child to your account.',
    );
  }
}
