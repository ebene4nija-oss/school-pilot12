import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/data/reference_data.dart';
import '../../core/ui/pickers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import 'register_screen.dart';
import 'staff_clock_in.dart';

/// The Register tab: pick a class, take the roll, or clock yourself in.
class RegisterTab extends ConsumerWidget {
  const RegisterTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final classes = ref.watch(classesProvider);
    final termId = ref.watch(selectedTermProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Register')),
      body: Column(
        children: [
          const ClassTermBar(),
          Expanded(
            child: classes.when(
              loading: () => const LoadingState(rows: 3),
              error: (_, __) => const MissingReferenceData(what: 'classes'),
              data: (list) {
                if (list.isEmpty) {
                  return const MissingReferenceData(what: 'classes');
                }

                return ListView(
                  padding: const EdgeInsets.all(AppSpacing.screenMargin),
                  children: [
                    const StaffClockInCard(),
                    const SizedBox(height: AppSpacing.lg),
                    Text('Take a register', style: AppText.headlineSm),
                    const SizedBox(height: AppSpacing.sm),
                    if (termId == null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: Text(
                          'Choose a term before taking a register.',
                          style: AppText.bodyMd.copyWith(color: AppColors.late),
                        ),
                      ),
                    for (final c in list)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: OutlinedCard(
                          onTap: termId == null
                              ? null
                              : () => Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: (_) => RegisterScreen(
                                        classId: c.id,
                                        termId: termId,
                                        className: c.name,
                                      ),
                                    ),
                                  ),
                          child: Row(
                            children: [
                              const Icon(Icons.fact_check_outlined,
                                  color: AppColors.primaryContainer),
                              const SizedBox(width: AppSpacing.md),
                              Expanded(
                                child: Text(c.name, style: AppText.bodyLg),
                              ),
                              const Icon(Icons.chevron_right,
                                  color: AppColors.outline),
                            ],
                          ),
                        ),
                      ),
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
