import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/auth/session.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/pickers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../dashboard/dashboard_providers.dart';

/// Whose results are being looked at: the signed-in student, or the guardian's
/// selected child.
final resultSubjectIdProvider = Provider<int?>((ref) {
  final role = ref.watch(currentRoleProvider);
  if (role == UserRole.parent) return ref.watch(selectedChildIdProvider);
  return ref.watch(currentUserProvider)?.id;
});

final resultSummaryProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final studentId = ref.watch(resultSubjectIdProvider);
  final termId = ref.watch(selectedTermProvider);
  if (studentId == null || termId == null) return const {};

  final api = ref.watch(apiClientProvider);
  return api.get<Map<String, dynamic>>(Api.resultSummary(studentId, termId));
});

final fullResultProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final studentId = ref.watch(resultSubjectIdProvider);
  final termId = ref.watch(selectedTermProvider);
  if (studentId == null || termId == null) return const {};

  final api = ref.watch(apiClientProvider);
  return api.get<Map<String, dynamic>>(Api.result(studentId, termId));
});

/// Results, gated twice.
///
/// First by release: until the school publishes the term for that class,
/// nothing is shown and nothing is sold. Then by PIN: once released, the
/// guardian either redeems a PIN bought at the office or buys one.
class ResultsScreen extends ConsumerWidget {
  const ResultsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final termId = ref.watch(selectedTermProvider);
    final summary = ref.watch(resultSummaryProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Results')),
      body: Column(
        children: [
          const ClassTermBar(),
          Expanded(
            child: termId == null
                ? const MissingReferenceData(what: 'terms')
                : summary.when(
                    loading: () => const LoadingState(rows: 3),
                    error: (error, _) => ErrorState(
                      error: asApiException(error),
                      onRetry: () => ref.invalidate(resultSummaryProvider),
                    ),
                    data: (data) {
                      if (!_isReleased(data)) return const _NotReleased();
                      if (!_isUnlocked(data)) return const PinGate();
                      return const _ResultDetail();
                    },
                  ),
          ),
        ],
      ),
    );
  }

  /// Fail closed. Anything that does not positively say "released" is treated
  /// as not released — showing an unpublished result to a parent is far worse
  /// than making them wait.
  static bool _isReleased(Map<String, dynamic> data) =>
      data['released'] == true ||
      data['is_released'] == true ||
      stringOf(data['release_status']) == 'released';

  static bool _isUnlocked(Map<String, dynamic> data) =>
      data['unlocked'] == true ||
      data['has_access'] == true ||
      data['access'] == true;
}

class _NotReleased extends StatelessWidget {
  const _NotReleased();

  @override
  Widget build(BuildContext context) {
    return const EmptyState(
      icon: Icons.lock_clock,
      title: 'Results not released yet',
      message:
          'Your school has not published results for this term. They will '
          'appear here once marking is complete.',
    );
  }
}

/// The PIN gate. Two clearly separated paths, and no purchase offered until
/// the school has actually released the term.
class PinGate extends ConsumerStatefulWidget {
  const PinGate({super.key});

  @override
  ConsumerState<PinGate> createState() => _PinGateState();
}

class _PinGateState extends ConsumerState<PinGate> {
  final _pin = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _pin.dispose();
    super.dispose();
  }

  Future<void> _redeem() async {
    final studentId = ref.read(resultSubjectIdProvider);
    final termId = ref.read(selectedTermProvider);
    if (studentId == null || termId == null) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await ref.read(apiClientProvider).post<dynamic>(
        Api.redeemPin,
        body: {
          'pin': _pin.text.trim(),
          'student_id': studentId,
          'term_id': termId,
        },
      );
      ref.invalidate(resultSummaryProvider);
    } catch (error) {
      final failure = asApiException(error);
      setState(() {
        _busy = false;
        _error = failure.isRateLimited
            ? 'Too many tries. Wait a minute before trying again.'
            : failure.message;
      });
    }
  }

  Future<void> _buy() async {
    final studentId = ref.read(resultSubjectIdProvider);
    final termId = ref.read(selectedTermProvider);
    if (studentId == null || termId == null) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final res = await ref.read(apiClientProvider).post<Map<String, dynamic>>(
        Api.purchasePin,
        body: {
          'student_id': studentId,
          'term_id': termId,
          'gateway': 'paystack',
        },
      );

      if (!mounted) return;
      setState(() => _busy = false);

      final sale = mapOf(res['sale']);
      // The reference is minted server-side and the gateway runs client-side
      // against the school's own public key — which nothing exposes to the app
      // (gap G8). So the app hands the parent their reference rather than
      // opening a checkout it cannot configure.
      showDialog<void>(
        context: context,
        builder: (_) => AlertDialog(
          title: const Text('Payment reference created'),
          content: Text(
            'Quote this reference when you pay at the school office or by '
            'transfer:\n\n${stringOf(sale['reference'], '—')}\n\n'
            'Amount: ${Money.format(numOf(sale['amount']))}\n\n'
            'The result unlocks automatically once your payment is confirmed.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Close'),
            ),
          ],
        ),
      );
    } catch (error) {
      setState(() {
        _busy = false;
        _error = asApiException(error).message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.screenMargin),
      children: [
        OutlinedCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.lock_outline,
                      color: AppColors.primaryContainer),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Text('Unlock this result',
                        style: AppText.headlineSm),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                'Results for this term have been released. Use a '
                'result-checker PIN to open them.',
                style:
                    AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.lg),
        Text('I have a PIN', style: AppText.headlineSm),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          controller: _pin,
          autocorrect: false,
          decoration: InputDecoration(
            labelText: 'Result-checker PIN',
            errorText: _error,
            prefixIcon: const Icon(Icons.key_outlined),
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        OutlinedButton(
          onPressed: _busy ? null : _redeem,
          child: const Text('Redeem'),
        ),
        const SizedBox(height: AppSpacing.lg),
        Text('Buy a PIN', style: AppText.headlineSm),
        const SizedBox(height: AppSpacing.sm),
        OutlinedCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'One PIN opens this result for the whole term.',
                style:
                    AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
              const SizedBox(height: AppSpacing.md),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: _busy ? null : _buy,
                  child: const Text('Buy a PIN'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        Text(
          'Bought a PIN at the school office? Enter it above.',
          style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
        ),
      ],
    );
  }
}

class _ResultDetail extends ConsumerWidget {
  const _ResultDetail();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final result = ref.watch(fullResultProvider);

    return result.when(
      loading: () => const LoadingState(rows: 4),
      error: (error, _) => ErrorState(
        error: asApiException(error),
        onRetry: () => ref.invalidate(fullResultProvider),
      ),
      data: (data) {
        final map = mapOf(data, ['result', 'data']);
        final subjects = listOf(map['subjects'] ?? map['scores'], ['subjects']);
        final total = numOf(map['total'] ?? map['total_score']);
        final average = numOf(map['average']);
        final position = intOf(map['position'] ?? map['position_in_class']);
        final classSize = intOf(map['class_size'] ?? map['out_of']);

        return ListView(
          padding: const EdgeInsets.all(AppSpacing.screenMargin),
          children: [
            OutlinedCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Overall', style: AppText.labelSm),
                  const SizedBox(height: AppSpacing.sm),
                  Row(
                    children: [
                      Expanded(
                        child: _Metric(
                          label: 'Total',
                          value: total.toStringAsFixed(0),
                        ),
                      ),
                      Expanded(
                        child: _Metric(
                          label: 'Average',
                          value: '${average.toStringAsFixed(1)}%',
                        ),
                      ),
                      if (position > 0)
                        Expanded(
                          child: _Metric(
                            label: 'Position',
                            value: Academic.ordinal(position),
                            caption: classSize > 0 ? 'of $classSize' : null,
                          ),
                        ),
                    ],
                  ),
                ],
              ),
            ),
            const SectionHeader('Subjects'),
            for (final subject in subjects)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: _SubjectRow(subject: subject),
              ),
            const SizedBox(height: AppSpacing.xl),
          ],
        );
      },
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value, this.caption});

  final String label;
  final String value;
  final String? caption;

  @override
  Widget build(BuildContext context) {
    return Column(
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
        NumericText(value, size: 20, weight: FontWeight.w700),
        if (caption != null)
          Text(
            caption!,
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
      ],
    );
  }
}

class _SubjectRow extends StatelessWidget {
  const _SubjectRow({required this.subject});

  final Map<String, dynamic> subject;

  @override
  Widget build(BuildContext context) {
    final total = numOf(subject['total'] ?? subject['total_score']);
    final firstCa = numOf(subject['first_ca']);
    final secondCa = numOf(subject['second_ca']);
    final exam = numOf(subject['exam']);
    final grade = stringOf(subject['grade'], Academic.grade(total));
    final colour = total >= 50
        ? AppColors.present
        : total >= 40
            ? AppColors.late
            : AppColors.absent;

    return OutlinedCard(
      statusColor: colour,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  stringOf(
                    subject['subject']?['name'] ?? subject['name'],
                    'Subject',
                  ),
                  style: AppText.bodyLg,
                ),
              ),
              StatusChip(grade, color: colour),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'CA ${(firstCa + secondCa).toStringAsFixed(0)}/40 · '
            'Exam ${exam.toStringAsFixed(0)}/60',
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: AppSpacing.sm),
          Row(
            children: [
              NumericText(
                '${total.toStringAsFixed(0)}%',
                size: 18,
                weight: FontWeight.w700,
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: ProgressBar(value: total / 100, color: colour),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
