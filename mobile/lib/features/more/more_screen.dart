import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/auth/session.dart';
import '../../core/providers.dart';
import '../../core/sync/sync_service.dart';
import '../../core/ui/widgets.dart';

/// Everything that did not fit in four tabs.
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
          if (role == UserRole.teacher || role.isAdmin)
            _Tile(
              icon: Icons.calendar_month_outlined,
              label: 'Timetable',
              onTap: () => context.push('/timetable'),
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
          Center(
            child: Text(
              'SchoolPilot 1.0.0',
              style: AppText.labelSm.copyWith(color: AppColors.outline),
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
      builder: (_) => AlertDialog(
        title: const Text('Sign out?'),
        content: Text(
          queued > 0
              ? 'You have $queued unsent ${queued == 1 ? 'change' : 'changes'}. '
                  'Signing out will discard them.'
              : 'You will need your password to sign back in.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
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
