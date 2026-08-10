import 'package:flutter/material.dart';

import '../../app/theme.dart';
import 'formatters.dart';

/// A white card with a 1px outline. Level 1 of the elevation system — there is
/// no shadow anywhere in this app.
class OutlinedCard extends StatelessWidget {
  const OutlinedCard({
    super.key,
    required this.child,
    this.statusColor,
    this.padding = const EdgeInsets.all(AppSpacing.md),
    this.onTap,
    this.background,
  });

  final Widget child;

  /// Draws the 4px left border that denotes status: green paid/present, red
  /// owing/absent, amber pending.
  final Color? statusColor;

  final EdgeInsets padding;
  final VoidCallback? onTap;
  final Color? background;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: background ?? AppColors.surfaceContainerLowest,
      borderRadius: AppRadius.cardRadius,
      child: InkWell(
        onTap: onTap,
        borderRadius: AppRadius.cardRadius,
        child: Container(
          decoration: BoxDecoration(
            borderRadius: AppRadius.cardRadius,
            border: Border.all(color: AppColors.outlineVariant),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (statusColor != null)
                Container(
                  width: 4,
                  decoration: BoxDecoration(
                    color: statusColor,
                    borderRadius: const BorderRadius.horizontal(
                      left: Radius.circular(AppRadius.card),
                    ),
                  ),
                ),
              Expanded(
                child: Padding(padding: padding, child: child),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Small high-contrast badge: JSS2A, Present, Paid, Overdue, Pending Approval.
class StatusChip extends StatelessWidget {
  const StatusChip(
    this.label, {
    super.key,
    this.color = AppColors.onSurfaceVariant,
    this.icon,
  });

  const StatusChip.present(String label, {Key? key})
      : this(label, key: key, color: AppColors.present);

  const StatusChip.absent(String label, {Key? key})
      : this(label, key: key, color: AppColors.absent);

  const StatusChip.pending(String label, {Key? key})
      : this(label, key: key, color: AppColors.late);

  final String label;
  final Color color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        // A light tint of the status colour with dark text of the same hue —
        // readable in direct sunlight, unlike a saturated fill.
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 14, color: color),
            const SizedBox(width: 4),
          ],
          Text(
            label,
            style: AppText.labelSm.copyWith(
              color: color,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

/// Initials on a tonal circle.
///
/// The default avatar everywhere. A photograph loads only where a school has
/// actually uploaded one and the screen is not a long scrolling list — remote
/// headshots on a roster are somebody's data bundle (§B6).
class InitialsAvatar extends StatelessWidget {
  const InitialsAvatar(this.name, {super.key, this.size = 40, this.color});

  final String name;
  final double size;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final parts = name
        .trim()
        .split(RegExp(r'[\s,]+'))
        .where((p) => p.isNotEmpty)
        .toList();
    final initials = parts.isEmpty
        ? '?'
        : parts.length == 1
            ? parts.first.substring(0, 1).toUpperCase()
            : (parts.first.substring(0, 1) + parts[1].substring(0, 1))
                .toUpperCase();

    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: (color ?? AppColors.primaryContainer).withValues(alpha: 0.12),
        shape: BoxShape.circle,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Text(
        initials,
        style: AppText.labelLg.copyWith(
          color: color ?? AppColors.primaryContainer,
          fontSize: size * 0.36,
        ),
      ),
    );
  }
}

/// A dashboard number: big figure, small uppercase caption.
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.label,
    required this.value,
    this.caption,
    this.valueColor,
    this.onTap,
  });

  final String label;
  final String value;
  final String? caption;
  final Color? valueColor;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return OutlinedCard(
      onTap: onTap,
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
          const SizedBox(height: AppSpacing.sm),
          Text(
            value,
            style: AppText.headlineLgMobile.copyWith(
              color: valueColor ?? AppColors.onSurface,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          if (caption != null) ...[
            const SizedBox(height: 2),
            Text(
              caption!,
              style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
              maxLines: 2,
            ),
          ],
        ],
      ),
    );
  }
}

/// Section heading with an optional trailing action.
class SectionHeader extends StatelessWidget {
  const SectionHeader(this.title, {super.key, this.action, this.onAction});

  final String title;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(
        left: AppSpacing.screenMargin,
        right: AppSpacing.sm,
        top: AppSpacing.lg,
        bottom: AppSpacing.sm,
      ),
      child: Row(
        children: [
          Expanded(child: Text(title, style: AppText.headlineSm)),
          if (action != null)
            TextButton(
              onPressed: onAction,
              style: TextButton.styleFrom(minimumSize: const Size(0, 36)),
              child: Text(action!),
            ),
        ],
      ),
    );
  }
}

/// A number rendered with tabular figures, so columns line up.
class NumericText extends StatelessWidget {
  const NumericText(
    this.value, {
    super.key,
    this.size = 14,
    this.weight = FontWeight.w500,
    this.color,
  });

  final String value;
  final double size;
  final FontWeight weight;
  final Color? color;

  @override
  Widget build(BuildContext context) => Text(
        value,
        style: AppText.numericAt(size, weight: weight, color: color),
      );
}

/// Naira, formatted the one correct way.
class MoneyText extends StatelessWidget {
  const MoneyText(this.amount, {super.key, this.size = 16, this.color, this.weight});

  final num? amount;
  final double size;
  final Color? color;
  final FontWeight? weight;

  @override
  Widget build(BuildContext context) => NumericText(
        Money.format(amount),
        size: size,
        weight: weight ?? FontWeight.w600,
        color: color,
      );
}

/// A full-width bottom action bar that stays above the navigation bar.
class BottomActionBar extends StatelessWidget {
  const BottomActionBar({super.key, required this.child, this.caption});

  final Widget child;
  final Widget? caption;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(
        AppSpacing.screenMargin,
        AppSpacing.sm,
        AppSpacing.screenMargin,
        AppSpacing.sm + MediaQuery.paddingOf(context).bottom,
      ),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.outlineVariant)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (caption != null) ...[
            caption!,
            const SizedBox(height: AppSpacing.sm),
          ],
          SizedBox(width: double.infinity, child: child),
        ],
      ),
    );
  }
}

/// A thin progress bar with no rounded-corner drama.
class ProgressBar extends StatelessWidget {
  const ProgressBar({super.key, required this.value, this.color, this.height = 8});

  final double value;
  final Color? color;
  final double height;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(height),
      child: LinearProgressIndicator(
        value: value.clamp(0, 1),
        minHeight: height,
        backgroundColor: AppColors.surfaceContainerHigh,
        valueColor: AlwaysStoppedAnimation(color ?? AppColors.primaryContainer),
      ),
    );
  }
}
