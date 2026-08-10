import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../data/reference_data.dart';
import 'states.dart';

/// The class + term bar that sits under the app bar on every staff screen.
///
/// When the backend cannot tell the app what the classes and terms are (gap
/// G12), this says so in one line instead of leaving an empty dropdown that
/// looks like a bug in the app.
class ClassTermBar extends ConsumerWidget {
  const ClassTermBar({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final classes = ref.watch(classesProvider).valueOrNull ?? const [];
    final terms = ref.watch(termsProvider).valueOrNull ?? const [];
    final classId = ref.watch(selectedClassProvider);
    final termId = ref.watch(selectedTermProvider);

    if (classes.isEmpty && terms.isEmpty) return const SizedBox.shrink();

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.screenMargin,
        vertical: AppSpacing.sm,
      ),
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: AppColors.outlineVariant)),
      ),
      child: Row(
        children: [
          if (classes.isNotEmpty)
            Expanded(
              child: _Dropdown<int>(
                value: classId,
                hint: 'Class',
                items: {for (final c in classes) c.id: c.name},
                onChanged: (v) =>
                    ref.read(selectedClassProvider.notifier).state = v,
              ),
            ),
          if (classes.isNotEmpty && terms.isNotEmpty)
            const SizedBox(width: AppSpacing.sm),
          if (terms.isNotEmpty)
            Expanded(
              child: _Dropdown<int>(
                value: termId,
                hint: 'Term',
                items: {for (final t in terms) t.id: t.name},
                onChanged: (v) =>
                    ref.read(selectedTermProvider.notifier).state = v,
              ),
            ),
        ],
      ),
    );
  }
}

class _Dropdown<T> extends StatelessWidget {
  const _Dropdown({
    required this.value,
    required this.hint,
    required this.items,
    required this.onChanged,
  });

  final T? value;
  final String hint;
  final Map<T, String> items;
  final ValueChanged<T?> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: AppSpacing.tapTarget,
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm + 4),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.cardRadius,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<T>(
          value: items.containsKey(value) ? value : null,
          hint: Text(hint, style: AppText.bodyMd),
          isExpanded: true,
          icon: const Icon(Icons.expand_more, size: 20),
          style: AppText.labelLg.copyWith(color: AppColors.onSurface),
          items: [
            for (final entry in items.entries)
              DropdownMenuItem(value: entry.key, child: Text(entry.value)),
          ],
          onChanged: onChanged,
        ),
      ),
    );
  }
}

/// Shown where a screen cannot proceed because the server never told the app
/// what the classes or terms are.
class MissingReferenceData extends StatelessWidget {
  const MissingReferenceData({super.key, required this.what});

  final String what;

  @override
  Widget build(BuildContext context) {
    return EmptyState(
      icon: Icons.help_outline,
      title: 'No $what available',
      message:
          'This school server does not yet publish the list of $what to the '
          'app, so this screen has nothing to work with. Your administrator '
          'can do this from the web portal in the meantime.',
    );
  }
}
