import 'package:flutter/material.dart';

import '../../app/theme.dart';
import '../api/api_exception.dart';
import 'formatters.dart';

/// Loading, empty, error and offline are four distinct widgets, and every list
/// and detail screen in the app has all four. An endless spinner is a bug.

class LoadingState extends StatelessWidget {
  const LoadingState({super.key, this.rows = 4});

  final int rows;

  @override
  Widget build(BuildContext context) {
    // Skeleton rows rather than a spinner: the user sees the shape of what is
    // coming, and a slow 2G response does not read as a hang.
    return ListView.separated(
      padding: const EdgeInsets.all(AppSpacing.screenMargin),
      itemCount: rows,
      separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
      itemBuilder: (_, __) => Container(
        height: 72,
        decoration: BoxDecoration(
          color: AppColors.surfaceContainer,
          borderRadius: AppRadius.cardRadius,
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: AppColors.outline),
            const SizedBox(height: AppSpacing.md),
            Text(title, style: AppText.headlineSm, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.sm),
            Text(
              message,
              style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              textAlign: TextAlign.center,
            ),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: AppSpacing.lg),
              FilledButton(onPressed: onAction, child: Text(actionLabel!)),
            ],
          ],
        ),
      ),
    );
  }
}

class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.error, this.onRetry});

  final ApiException error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    // A 403 is not a failure to load — it is an answer. Saying "couldn't load"
    // would send the user into a retry loop against a permission they will
    // never have.
    final forbidden = error.isForbidden;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              forbidden ? Icons.lock_outline : Icons.error_outline,
              size: 48,
              color: forbidden ? AppColors.outline : AppColors.error,
            ),
            const SizedBox(height: AppSpacing.md),
            Text(
              forbidden ? 'Not available to you' : "Couldn't load this",
              style: AppText.headlineSm,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              error.message,
              style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              textAlign: TextAlign.center,
            ),
            if (!forbidden && onRetry != null) ...[
              const SizedBox(height: AppSpacing.lg),
              OutlinedButton(onPressed: onRetry, child: const Text('Retry')),
            ],
          ],
        ),
      ),
    );
  }
}

/// The slim amber bar that sits under the app bar whenever the screen is
/// showing data it could not refresh.
class OfflineBanner extends StatelessWidget implements PreferredSizeWidget {
  const OfflineBanner({
    super.key,
    this.fetchedAt,
    this.pendingWrites = 0,
    this.onRetry,
  });

  final DateTime? fetchedAt;
  final int pendingWrites;
  final VoidCallback? onRetry;

  @override
  Size get preferredSize => const Size.fromHeight(44);

  @override
  Widget build(BuildContext context) {
    final message = pendingWrites > 0
        ? 'Offline — $pendingWrites ${pendingWrites == 1 ? 'change' : 'changes'} '
            'will sync when you\'re back online'
        : 'Offline — showing data from ${Dates.time(fetchedAt)}';

    return Container(
      height: 44,
      width: double.infinity,
      color: AppColors.secondaryContainer,
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin),
      child: Row(
        children: [
          const Icon(Icons.cloud_off_outlined, size: 18, color: AppColors.onAction),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              message,
              style: AppText.labelSm.copyWith(color: AppColors.onAction),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (onRetry != null)
            TextButton(
              onPressed: onRetry,
              style: TextButton.styleFrom(
                foregroundColor: AppColors.onAction,
                minimumSize: const Size(0, 32),
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
              ),
              child: const Text('Retry'),
            ),
        ],
      ),
    );
  }
}

/// "Updated 14:32" with a refresh affordance. Sits at the top of any screen
/// backed by the cache.
class FreshnessLine extends StatelessWidget {
  const FreshnessLine({super.key, this.fetchedAt, this.onRefresh});

  final DateTime? fetchedAt;
  final VoidCallback? onRefresh;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.screenMargin,
        vertical: AppSpacing.sm,
      ),
      child: Row(
        children: [
          Text(
            Dates.freshness(fetchedAt),
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const Spacer(),
          if (onRefresh != null)
            InkWell(
              onTap: onRefresh,
              borderRadius: BorderRadius.circular(AppSpacing.tapTarget),
              child: const Padding(
                padding: EdgeInsets.all(AppSpacing.sm),
                child: Icon(Icons.refresh, size: 18, color: AppColors.onSurfaceVariant),
              ),
            ),
        ],
      ),
    );
  }
}
