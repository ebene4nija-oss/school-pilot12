import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/auth/session.dart';
import '../../core/providers.dart';

class QuickAction {
  const QuickAction(this.icon, this.label, this.path);

  final IconData icon;
  final String label;
  final String path;
}

/// The handful of More items each role reaches for most, one tap from home.
/// More still lists everything; this only saves the trip.
List<QuickAction> quickActionsFor(UserRole role) => switch (role) {
      UserRole.student => const [
          QuickAction(Icons.quiz_outlined, 'My tests', '/my-tests'),
          QuickAction(Icons.auto_awesome_outlined, 'AI tutor', '/learn/tutor'),
          QuickAction(Icons.menu_book_outlined, 'Library', '/library'),
          QuickAction(Icons.badge_outlined, 'ID card', '/id-card'),
          QuickAction(Icons.forum_outlined, 'Messages', '/messages'),
        ],
      UserRole.teacher => const [
          QuickAction(Icons.assignment_outlined, 'Homework', '/set-homework'),
          QuickAction(Icons.quiz_outlined, 'CBT exams', '/cbt-exams'),
          QuickAction(Icons.description_outlined, 'Lesson notes', '/lesson-notes'),
          QuickAction(Icons.auto_awesome_outlined, 'AI Studio', '/ai-studio'),
          QuickAction(Icons.forum_outlined, 'Messages', '/messages'),
          QuickAction(Icons.qr_code_scanner, 'Library desk', '/library/desk'),
          QuickAction(Icons.beach_access_outlined, 'Leave', '/leave'),
        ],
      UserRole.schoolAdmin || UserRole.superAdmin => const [
          QuickAction(Icons.task_alt, 'Approvals', '/approvals'),
          QuickAction(Icons.campaign_outlined, 'Send notice', '/send-notification'),
          QuickAction(Icons.forum_outlined, 'Messages', '/messages'),
          QuickAction(Icons.calendar_month_outlined, 'Timetable', '/timetable'),
          QuickAction(Icons.pin_outlined, 'Result PINs', '/result-pins'),
          QuickAction(Icons.payments_outlined, 'Payroll', '/payroll'),
          QuickAction(Icons.account_balance_outlined, 'Transfers', '/fees/transfers'),
          QuickAction(Icons.auto_awesome_outlined, 'AI Studio', '/ai-studio'),
        ],
      UserRole.parent => const [
          QuickAction(Icons.forum_outlined, 'Messages', '/messages'),
          QuickAction(Icons.shield_outlined, 'Pickup & consent', '/safety'),
          QuickAction(Icons.notifications_outlined, 'Notifications', '/notifications'),
        ],
      UserRole.bursar => const [
          QuickAction(Icons.account_balance_outlined, 'Transfers', '/fees/transfers'),
          QuickAction(Icons.forum_outlined, 'Messages', '/messages'),
          QuickAction(Icons.receipt_long_outlined, 'Payslips', '/payslips'),
        ],
      UserRole.nurse => const [
          QuickAction(Icons.forum_outlined, 'Messages', '/messages'),
          QuickAction(Icons.beach_access_outlined, 'Leave', '/leave'),
          QuickAction(Icons.receipt_long_outlined, 'Payslips', '/payslips'),
        ],
      UserRole.other => const [],
    };

/// A horizontal row of shortcut tiles for the signed-in role.
class QuickActionsRow extends ConsumerWidget {
  const QuickActionsRow({super.key, this.inset = AppSpacing.screenMargin});

  /// Side margin. Zero where the surrounding list already pads its children.
  final double inset;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final actions = quickActionsFor(ref.watch(currentRoleProvider));
    if (actions.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
      child: SizedBox(
        height: 88,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: EdgeInsets.symmetric(horizontal: inset),
          itemCount: actions.length,
          separatorBuilder: (_, __) => const SizedBox(width: AppSpacing.sm),
          itemBuilder: (context, i) {
            final a = actions[i];
            return SizedBox(
              width: 84,
              child: Material(
                color: AppColors.surfaceContainerLow,
                borderRadius: AppRadius.cardRadius,
                child: InkWell(
                  borderRadius: AppRadius.cardRadius,
                  onTap: () => context.push(a.path),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4, vertical: AppSpacing.sm),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(a.icon, color: AppColors.primaryContainer),
                        const SizedBox(height: 6),
                        Text(
                          a.label,
                          textAlign: TextAlign.center,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: AppText.labelSm.copyWith(color: AppColors.onSurface),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}
