import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/navigation.dart';
import '../../app/theme.dart';
import '../../core/providers.dart';
import '../../core/sync/sync_service.dart';

/// The signed-in frame: role-appropriate bottom navigation, and the sync
/// indicator that tells a user their queued work has not left the phone yet.
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.child, required this.location});

  final Widget child;
  final String location;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final role = ref.watch(currentRoleProvider);
    final destinations = RoleNav.forRole(role);

    final index = destinations.indexWhere(
      (d) => location == d.path || location.startsWith('${d.path}/'),
    );

    return Scaffold(
      body: Column(
        children: [
          const _SyncStrip(),
          Expanded(child: child),
        ],
      ),
      bottomNavigationBar: Container(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: AppColors.outlineVariant)),
        ),
        child: NavigationBar(
          selectedIndex: index < 0 ? 0 : index,
          onDestinationSelected: (i) => context.go(destinations[i].path),
          destinations: [
            for (final d in destinations)
              NavigationDestination(
                icon: Icon(d.icon),
                selectedIcon: Icon(d.selectedIcon),
                label: d.label,
              ),
          ],
        ),
      ),
    );
  }
}

/// One line, only when there is something to say: offline, or writes waiting.
class _SyncStrip extends ConsumerStatefulWidget {
  const _SyncStrip();

  @override
  ConsumerState<_SyncStrip> createState() => _SyncStripState();
}

class _SyncStripState extends ConsumerState<_SyncStrip> {
  @override
  Widget build(BuildContext context) {
    final sync = ref.watch(syncServiceProvider);

    return ValueListenableBuilder<SyncState>(
      valueListenable: sync,
      builder: (context, state, _) {
        if (state.online && !state.hasQueue) return const SizedBox.shrink();

        final failed = state.failed > 0;
        final background =
            failed ? AppColors.errorContainer : AppColors.secondaryContainer;
        final foreground =
            failed ? AppColors.onErrorContainer : AppColors.onAction;

        final message = failed
            ? '${state.failed} ${state.failed == 1 ? 'change' : 'changes'} could not be saved'
            : state.online
                ? 'Syncing ${state.pending} ${state.pending == 1 ? 'change' : 'changes'}…'
                : state.pending > 0
                    ? "Offline — ${state.pending} ${state.pending == 1 ? 'change' : 'changes'} will sync when you're back online"
                    : 'Offline — showing saved data';

        return Material(
          color: background,
          child: InkWell(
            onTap: () => context.push('/pending'),
            child: SafeArea(
              bottom: false,
              child: Container(
                height: 44,
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.screenMargin,
                ),
                child: Row(
                  children: [
                    Icon(
                      failed
                          ? Icons.error_outline
                          : state.online
                              ? Icons.sync
                              : Icons.cloud_off_outlined,
                      size: 18,
                      color: foreground,
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: Text(
                        message,
                        style: AppText.labelSm.copyWith(color: foreground),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Icon(Icons.chevron_right, size: 18, color: foreground),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
