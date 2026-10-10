import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../app/theme.dart';
import '../../core/auth/session.dart';
import '../../core/providers.dart';
import '../../core/sync/sync_service.dart';
import '../../core/ui/widgets.dart';
import '../students/academic_history_screen.dart';
import '../dashboard/dashboard_providers.dart';
import '../../core/api/endpoints.dart';

/// Everything that did not fit in the tabs.
class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final role = ref.watch(currentRoleProvider);
    final sync = ref.watch(syncServiceProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('More')),
      body: ListView(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            child: OutlinedCard(
              onTap: () => context.push('/profile'),
              child: Row(
                children: [
                  InitialsAvatar(user?.name ?? '?', size: 52),
                  const SizedBox(width: AppSpacing.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(user?.name ?? 'Signed in', style: AppText.bodyLg),
                        const SizedBox(height: 4),
                        StatusChip(
                          role.label,
                          color: AppColors.primaryContainer,
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right, color: AppColors.outline),
                ],
              ),
            ),
          ),
          ValueListenableBuilder<SyncState>(
            valueListenable: sync,
            builder: (context, state, _) => _Tile(
              icon: state.hasQueue ? Icons.sync_problem : Icons.cloud_done_outlined,
              label: 'Offline queue',
              detail: state.hasQueue
                  ? '${state.pending + state.failed} waiting'
                  : 'Everything is saved',
              detailColour: state.failed > 0 ? AppColors.absent : null,
              onTap: () => context.push('/pending'),
            ),
          ),
          _Tile(
            icon: Icons.forum_outlined,
            label: 'Messages',
            onTap: () => context.push('/messages'),
          ),
          _Tile(
            icon: Icons.notifications_outlined,
            label: 'Notifications',
            onTap: () => context.push('/notifications'),
          ),
          if (role.isAdmin)
            _Tile(
              icon: Icons.today_outlined,
              label: 'Today',
              detail: 'Registers, absences and staff clock-ins',
              onTap: () => context.push('/school-today'),
            ),
          if (role.isAdmin)
            _Tile(
              icon: Icons.badge_outlined,
              label: 'Staff attendance',
              detail: 'Who clocked in, late and missed days',
              onTap: () => context.push('/staff-attendance'),
            ),
          if (role.isAdmin)
            _Tile(
              icon: Icons.edit_location_alt_outlined,
              label: 'Clock-in location',
              detail: 'Where staff must be to clock in, and the resume time',
              onTap: () => context.push('/clock-in-location'),
            ),
          if (role.isAdmin)
            _Tile(
              icon: Icons.task_alt,
              label: 'Approvals',
              detail: 'Leave, lesson notes, CBT, admissions, broadsheets',
              onTap: () => context.push('/approvals'),
            ),
          if (role.isAdmin)
            _Tile(
              icon: Icons.campaign_outlined,
              label: 'Send notification',
              detail: 'To parents, students, teachers or all staff',
              onTap: () => context.push('/send-notification'),
            ),
          if (role == UserRole.teacher || role.isAdmin)
            _Tile(
              icon: Icons.calendar_month_outlined,
              label: role == UserRole.teacher ? 'My lessons and rooms' : 'Timetable',
              onTap: () => context.push('/timetable'),
            ),
          if (role == UserRole.teacher || role.isAdmin)
            _Tile(
              icon: Icons.videocam_outlined,
              label: 'Virtual class attendance',
              detail: 'Read from Zoom or Meet, match names, correct',
              onTap: () => context.push('/virtual-classes'),
            ),
          if (role != UserRole.parent && role != UserRole.other)
            _Tile(
              icon: Icons.menu_book_outlined,
              label: 'My library books',
              onTap: () => context.push('/library'),
            ),
          if (role == UserRole.teacher || role.isAdmin)
            _Tile(
              icon: Icons.qr_code_scanner,
              label: 'Library desk',
              detail: 'Lend and take back books by scanning',
              onTap: () => context.push('/library/desk'),
            ),
          if (role == UserRole.student || role == UserRole.parent)
            _Tile(
              icon: Icons.history_edu_outlined,
              label: role == UserRole.student ? 'My academic history' : 'Academic history',
              detail: 'Every term, subject by subject, and progress over time',
              onTap: () {
                final childId = ref.read(selectedChildIdProvider);
                if (role == UserRole.parent && childId == null) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Choose a child on the Child tab first.')),
                  );
                  return;
                }
                Navigator.of(context).push(MaterialPageRoute<void>(
                  builder: (_) => AcademicHistoryScreen(
                    path: role == UserRole.student
                        ? Api.myAcademicHistory
                        : Api.childAcademicHistory(childId!),
                  ),
                ));
              },
            ),
          if (role == UserRole.student || role == UserRole.parent)
            _Tile(
              icon: Icons.menu_book_outlined,
              label: 'Lesson notes & materials',
              detail: 'Notes, videos, audio and links from teachers',
              onTap: () => context.push('/materials'),
            ),
          if (role == UserRole.parent)
            _Tile(
              icon: Icons.checkroom_outlined,
              label: 'Items from the school',
              detail: 'Confirm uniforms and books your child received',
              onTap: () => context.push('/store'),
            ),
          if (role == UserRole.parent)
            _Tile(
              icon: Icons.shield_outlined,
              label: 'Pickup and consent',
              detail: 'Who may collect your child; your data choices',
              onTap: () => context.push('/safety'),
            ),
          if (role == UserRole.student)
            _Tile(
              icon: Icons.bed_outlined,
              label: 'Hostel',
              detail: 'For boarders',
              onTap: () => context.push('/hostel'),
            ),
          if (role != UserRole.parent && role != UserRole.other)
            _Tile(
              icon: Icons.badge_outlined,
              label: 'My ID card',
              onTap: () => context.push('/id-card'),
            ),
          if (role == UserRole.teacher || role.isAdmin)
            _Tile(
              icon: Icons.auto_awesome_outlined,
              label: 'AI Studio',
              detail: 'Draft lesson plans, questions and homework',
              onTap: () => context.push('/ai-studio'),
            ),
          if (role == UserRole.teacher || role.isAdmin)
            _Tile(
              icon: Icons.quiz_outlined,
              label: 'CBT exams',
              detail: 'Set papers, publish, close and mark',
              onTap: () => context.push('/cbt-exams'),
            ),
          if (role == UserRole.teacher)
            _Tile(
              icon: Icons.people_outline,
              label: 'My students',
              detail: 'Profiles and parents to call',
              onTap: () => context.push('/students'),
            ),
          if (role == UserRole.teacher || role.isAdmin)
            _Tile(
              icon: Icons.star_rate_outlined,
              label: 'Ratings and comments',
              detail: 'Behaviour, skills and comments for report cards',
              onTap: () => context.push('/report-entries'),
            ),
          if (role == UserRole.student)
            _Tile(
              icon: Icons.payments_outlined,
              label: 'My fees',
              onTap: () => context.push('/fees'),
            ),
          if (role == UserRole.teacher || role.isAdmin)
            _Tile(
              icon: Icons.assignment_outlined,
              label: 'Homework',
              detail: 'Set it, see who handed in, mark it',
              onTap: () => context.push('/set-homework'),
            ),
          if (role == UserRole.teacher || role.isAdmin)
            _Tile(
              icon: Icons.description_outlined,
              label: 'Lesson notes',
              detail: 'Share notes and files with a class',
              onTap: () => context.push('/lesson-notes'),
            ),
          if (role == UserRole.student)
            _Tile(
              icon: Icons.quiz_outlined,
              label: 'My tests',
              detail: 'Sit CBT tests and see how you did',
              onTap: () => context.push('/my-tests'),
            ),
          if (role.isFinance)
            _Tile(
              icon: Icons.fact_check_outlined,
              label: 'Who has paid',
              detail: 'Paid and owing by section, class and arm',
              onTap: () => context.push('/fee-status'),
            ),
          if (role.isFinance)
            _Tile(
              icon: Icons.receipt_long_outlined,
              label: 'Payments ledger',
              detail: 'Every payment, with receipts to share',
              onTap: () => context.push('/payments-ledger'),
            ),
          if (role.isFinance)
            _Tile(
              icon: Icons.shopping_bag_outlined,
              label: 'Expenses and petty cash',
              onTap: () => context.push('/expenses'),
            ),
          if (role.isFinance)
            _Tile(
              icon: Icons.account_balance_outlined,
              label: 'Transfers to confirm',
              detail: 'Approve bank transfers once they reach the account',
              onTap: () => context.push('/fees/transfers'),
            ),
          if (role.isStaff)
            _Tile(
              icon: Icons.beach_access_outlined,
              label: 'My leave',
              onTap: () => context.push('/leave'),
            ),
          if (role.isStaff)
            _Tile(
              icon: Icons.receipt_long_outlined,
              label: 'My payslips',
              onTap: () => context.push('/payslips'),
            ),
          if (role == UserRole.schoolAdmin)
            _Tile(
              icon: Icons.payments_outlined,
              label: 'Payroll',
              detail: 'Prepare, approve and record payment',
              onTap: () => context.push('/payroll'),
            ),
          if (role == UserRole.schoolAdmin)
            _Tile(
              icon: Icons.link,
              label: 'Zoom and Google Meet',
              detail: 'Read virtual-class attendance from the call',
              onTap: () => context.push('/video-providers'),
            ),
          if (role == UserRole.student)
            _Tile(
              icon: Icons.insights_outlined,
              label: 'My progress',
              detail: 'Subjects, homework and topics to work on',
              onTap: () => context.push('/progress'),
            ),
          if (role == UserRole.student)
            _Tile(
              icon: Icons.emoji_events_outlined,
              label: 'Leaderboard',
              onTap: () => context.push('/leaderboard'),
            ),
          const Divider(),
          _Tile(
            icon: Icons.logout,
            label: 'Sign out',
            colour: AppColors.error,
            onTap: () => _signOut(context, ref),
          ),
          const SizedBox(height: AppSpacing.lg),
          // Read from the installed build, not typed in: a hard-coded "1.1.0"
          // made every screenshot look like the same old build.
          Center(
            child: FutureBuilder<PackageInfo>(
              future: PackageInfo.fromPlatform(),
              builder: (context, snap) => Text(
                snap.hasData
                    ? 'SchoolPilot ${snap.data!.version} (build ${snap.data!.buildNumber})'
                    : 'SchoolPilot',
                style: AppText.labelSm.copyWith(color: AppColors.outline),
              ),
            ),
          ),
          const SizedBox(height: AppSpacing.xl),
        ],
      ),
    );
  }

  /// Signing out wipes the local cache and the outbox with it, so anything
  /// still queued is lost. The user is told that before it happens rather than
  /// after — a teacher signing out on a shared handset with an unsent register
  /// deserves the warning.
  Future<void> _signOut(BuildContext context, WidgetRef ref) async {
    final sync = ref.read(syncServiceProvider);
    final queued = sync.value.pending + sync.value.failed;

    final confirmed = await showDialog<bool>(
      context: context,
      // The dialog's own context: the screen's context belongs to the tab
      // shell's navigator, so popping with it closed the wrong route and the
      // confirmation never came back — sign-out silently did nothing.
      builder: (dialogContext) => AlertDialog(
        title: const Text('Sign out?'),
        content: Text(
          queued > 0
              ? 'You have $queued unsent ${queued == 1 ? 'change' : 'changes'}. '
                  'Signing out will discard them.'
              : 'You will need your password to sign back in.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: Text(
              'Sign out',
              style: TextStyle(
                color: queued > 0 ? AppColors.error : null,
              ),
            ),
          ),
        ],
      ),
    );

    if (confirmed ?? false) {
      await ref.read(authControllerProvider.notifier).signOut();
      await sync.refreshCounts();
    }
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.icon,
    required this.label,
    required this.onTap,
    this.detail,
    this.detailColour,
    this.colour,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final String? detail;
  final Color? detailColour;
  final Color? colour;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      onTap: onTap,
      leading: Icon(icon, color: colour ?? AppColors.onSurfaceVariant),
      title: Text(
        label,
        style: AppText.bodyLg.copyWith(color: colour),
      ),
      subtitle: detail == null
          ? null
          : Text(
              detail!,
              style: AppText.bodyMd.copyWith(
                color: detailColour ?? AppColors.onSurfaceVariant,
              ),
            ),
      trailing: colour == null
          ? const Icon(Icons.chevron_right, color: AppColors.outline)
          : null,
    );
  }
}
