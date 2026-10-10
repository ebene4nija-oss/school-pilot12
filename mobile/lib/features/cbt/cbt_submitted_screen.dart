import 'package:flutter/material.dart';

import '../../app/theme.dart';
import '../../core/data/cached.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/widgets.dart';

/// What a student sees the moment a paper is in.
///
/// Deliberately calm: a test is stressful, and the first thing after it
/// should say "it is safe, you are done" before anything else. One soft
/// animation, plain facts, the score only when the school releases it
/// straight away, and one way out.
class CbtSubmittedScreen extends StatelessWidget {
  const CbtSubmittedScreen({
    super.key,
    required this.answered,
    required this.total,
    this.result = const {},
  });

  final int answered;
  final int total;

  /// The server's `result` from the submit, when it sent one.
  final Map<String, dynamic> result;

  static const _calm = Color(0xFF2E7D32);
  static const _calmSoft = Color(0xFFE6F2E7);

  @override
  Widget build(BuildContext context) {
    final title = stringOf(result['exam_title']);
    final submittedAt = Dates.tryParse(result['submitted_at'])?.toLocal() ?? DateTime.now();
    final hasScore = result['percentage'] != null;
    final percentage = numOf(result['percentage']);
    final grade = stringOf(result['grade']);
    final passed = result['passed'] == true;
    final marking = result['requires_manual_grading'] == true;
    final spent = intOf(result['time_spent_seconds']);

    // Replaces the paper on the stack, so Back goes home, never into it.
    return Scaffold(
        backgroundColor: AppColors.surface,
        body: SafeArea(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.lg, AppSpacing.xl * 1.5, AppSpacing.lg, AppSpacing.lg,
            ),
            children: [
              Center(
                child: TweenAnimationBuilder<double>(
                  tween: Tween(begin: 0, end: 1),
                  duration: const Duration(milliseconds: 900),
                  curve: Curves.easeOutCubic,
                  builder: (context, t, child) => Opacity(
                    opacity: t,
                    child: Transform.scale(scale: 0.85 + 0.15 * t, child: child),
                  ),
                  child: Container(
                    width: 112,
                    height: 112,
                    decoration: const BoxDecoration(color: _calmSoft, shape: BoxShape.circle),
                    child: const Icon(Icons.check_rounded, size: 64, color: _calm),
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.lg),
              Text(
                'Paper submitted',
                textAlign: TextAlign.center,
                style: AppText.headlineLgMobile.copyWith(color: AppColors.onSurface),
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                'Well done. Your answers are safely in — take a breath.',
                textAlign: TextAlign.center,
                style: AppText.bodyLg.copyWith(color: AppColors.onSurfaceVariant),
              ),
              if (title.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.xs),
                Text(
                  title,
                  textAlign: TextAlign.center,
                  style: AppText.labelLg.copyWith(color: AppColors.onSurfaceVariant),
                ),
              ],
              const SizedBox(height: AppSpacing.xl),
              OutlinedCard(
                child: Column(
                  children: [
                    _Fact(
                      icon: Icons.task_alt_outlined,
                      label: 'Questions answered',
                      value: '$answered of $total',
                    ),
                    const Divider(height: AppSpacing.lg),
                    _Fact(
                      icon: Icons.schedule_outlined,
                      label: 'Submitted',
                      value: Dates.dayAndTime(submittedAt),
                    ),
                    if (spent > 0) ...[
                      const Divider(height: AppSpacing.lg),
                      _Fact(
                        icon: Icons.timer_outlined,
                        label: 'Time spent',
                        value: _duration(spent),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              if (hasScore)
                OutlinedCard(
                  statusColor: passed ? _calm : AppColors.late,
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('YOUR SCORE',
                                style: AppText.labelSm.copyWith(
                                  color: AppColors.onSurfaceVariant,
                                  letterSpacing: 0.6,
                                )),
                            const SizedBox(height: 4),
                            NumericText('${percentage.toStringAsFixed(0)}%', size: 32, weight: FontWeight.w700),
                            if (marking)
                              Text(
                                'Written answers are still to be marked.',
                                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                              ),
                          ],
                        ),
                      ),
                      if (grade.isNotEmpty) StatusChip(grade, color: passed ? _calm : AppColors.late),
                    ],
                  ),
                )
              else
                Container(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  decoration: BoxDecoration(
                    color: AppColors.surfaceContainerLow,
                    borderRadius: AppRadius.cardRadius,
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.info_outline, color: AppColors.onSurfaceVariant),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                        child: Text(
                          marking
                              ? 'Your teacher will mark the written answers, then your school releases the results.'
                              : 'Your school will release your results. You will be notified when they are ready.',
                          style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                        ),
                      ),
                    ],
                  ),
                ),
              const SizedBox(height: AppSpacing.xl),
              FilledButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Done'),
              ),
            ],
          ),
        ),
    );
  }

  static String _duration(int seconds) {
    final m = seconds ~/ 60;
    final s = seconds % 60;
    if (m >= 60) return '${m ~/ 60} h ${m % 60} min';
    return m > 0 ? '$m min ${s.toString().padLeft(2, '0')} s' : '$s s';
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.icon, required this.label, required this.value});

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, size: 20, color: AppColors.onSurfaceVariant),
        const SizedBox(width: AppSpacing.sm),
        Expanded(child: Text(label, style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant))),
        Text(value, style: AppText.labelLg),
      ],
    );
  }
}
