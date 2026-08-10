import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/providers.dart';
import '../../core/ui/widgets.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final auth = ref.watch(authControllerProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Profile')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          Center(
            child: Column(
              children: [
                InitialsAvatar(user?.name ?? '?', size: 88),
                const SizedBox(height: AppSpacing.md),
                Text(user?.name ?? '', style: AppText.headlineMd),
                const SizedBox(height: 4),
                Text(
                  user?.email ?? '',
                  style: AppText.bodyMd
                      .copyWith(color: AppColors.onSurfaceVariant),
                ),
                const SizedBox(height: AppSpacing.sm),
                StatusChip(
                  user?.role.label ?? '',
                  color: AppColors.primaryContainer,
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.xl),
          _Field(label: 'School code', value: auth.schoolCode ?? '—'),
          _Field(label: 'Account', value: user?.email ?? '—'),
          _Field(label: 'Role', value: user?.role.label ?? '—'),
          const SizedBox(height: AppSpacing.lg),
          OutlinedCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Changing your details', style: AppText.bodyLg),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  // `PUT /users/{id}/profile` is admin-only, so there is no
                  // self-service edit to offer here (gap G3). Saying so beats
                  // a form that always returns 403.
                  'Your name, phone number and password are managed by the '
                  'school office. Ask them to update your details.',
                  style: AppText.bodyMd
                      .copyWith(color: AppColors.onSurfaceVariant),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label.toUpperCase(),
            style: AppText.labelSm.copyWith(
              color: AppColors.onSurfaceVariant,
              letterSpacing: 0.6,
            ),
          ),
          const SizedBox(height: 2),
          Text(value, style: AppText.bodyLg),
          const SizedBox(height: AppSpacing.sm),
          const Divider(),
        ],
      ),
    );
  }
}
