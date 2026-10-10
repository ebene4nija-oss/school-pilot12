import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/auth/session.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/pickers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../dashboard/dashboard_providers.dart';
import '../finance/gateway_checkout.dart';
import '../learn/learning_widgets.dart';

/// Whose results are being looked at: the signed-in student, or the guardian's
/// selected child.
///
/// A student's own id is their *student record*, from /me/dashboard. The login
/// id was sent before; the server found no such student (or someone else's
/// record) and every student's Results tab read "could not load".
final resultSubjectIdProvider = FutureProvider.autoDispose<int?>((ref) async {
  final role = ref.watch(currentRoleProvider);
  if (role == UserRole.parent) return ref.watch(selectedChildIdProvider);
  final me = mapOf((await ref.watch(myDayProvider.future)).data);
  final id = intOf(fieldOf(me['student'], 'id'));
  return id == 0 ? null : id;
});

final resultSummaryProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final studentId = await ref.watch(resultSubjectIdProvider.future);
  final termId = ref.watch(selectedTermProvider);
  if (studentId == null || termId == null) return const {};

  final api = ref.watch(apiClientProvider);
  return api.get<Map<String, dynamic>>(Api.resultSummary(studentId, termId));
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
                      if (!_isReleased(data)) {
                        return _NotReleased(message: stringOf(data['message']));
                      }
                      if (!_isUnlocked(data)) return const PinGate();
                      return ReportCardView(summary: data);
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
      // What /summary sends. The other spellings were read alone before, so
      // every released result read "not released yet".
      stringOf(data['status']) == 'released' ||
      data['released'] == true ||
      data['is_released'] == true ||
      stringOf(data['release_status']) == 'released';

  static bool _isUnlocked(Map<String, dynamic> data) =>
      data['unlocked'] == true ||
      data['has_access'] == true ||
      data['access'] == true;
}

class _NotReleased extends StatelessWidget {
  const _NotReleased({this.message = ''});

  /// The server's own reason, e.g. "No scores have been recorded...".
  final String message;

  @override
  Widget build(BuildContext context) {
    return EmptyState(
      icon: Icons.lock_clock,
      title: 'Results not released yet',
      message: message.isNotEmpty
          ? message
          : 'Your school has not published results for this term. They will '
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
    final studentId = await ref.read(resultSubjectIdProvider.future);
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
    final studentId = await ref.read(resultSubjectIdProvider.future);
    final termId = ref.read(selectedTermProvider);
    if (studentId == null || termId == null) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      // No gateway named: the guardian has no idea which merchant account
      // their school holds, so the server picks the one it connected.
      final res = await ref.read(apiClientProvider).post<Map<String, dynamic>>(
        Api.purchasePin,
        body: {'student_id': studentId, 'term_id': termId},
      );

      if (!mounted) return;
      setState(() => _busy = false);

      final url = stringOf(res['authorization_url']);
      if (url.isEmpty) return;

      await GatewayCheckout.open(context, url: url, title: 'Buy a PIN');

      if (!mounted) return;

      // Re-ask the server either way. The PIN is allocated by the signed
      // webhook, which the app never sees, and a guardian may background the
      // app rather than wait for the redirect.
      ref.invalidate(resultSummaryProvider);

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Confirming your payment. This result unlocks as soon as it '
            'arrives.',
          ),
        ),
      );
    } catch (error) {
      if (!mounted) return;
      final failure = asApiException(error);
      setState(() {
        _busy = false;
        // 409 here is the school not selling online — not the guardian's
        // mistake, and the server's wording already says to buy at the office.
        _error = failure.message;
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

/// The report card itself: the school's own design, as a PDF.
///
/// Fetched exactly once per opening, because with result PINs every fetch
/// spends one of the PIN's views. Viewing, saving and sharing all use that one
/// downloaded file, so "Save" never costs a parent a second view.
class ReportCardView extends ConsumerStatefulWidget {
  const ReportCardView({super.key, required this.summary});

  final Map<String, dynamic> summary;

  @override
  ConsumerState<ReportCardView> createState() => _ReportCardViewState();
}

class _ReportCardViewState extends ConsumerState<ReportCardView> {
  Future<File>? _pdf;

  static String _slug(String value) =>
      value.trim().replaceAll(RegExp(r'[^A-Za-z0-9]+'), '-');

  String get _fileName =>
      'Report-card-${_slug(stringOf(widget.summary['student_name'], 'student'))}'
      '-${_slug(stringOf(widget.summary['term_name'], 'term'))}.pdf';

  Future<File> _download() async {
    final studentId = intOf(widget.summary['student_id']);
    final termId = intOf(widget.summary['term_id']);
    final bytes = await ref
        .read(apiClientProvider)
        .getBytes('${Api.result(studentId, termId)}?format=pdf');
    // Kept in the app's documents rather than temp, so a reopen of this screen
    // in the same session never needs a second (paid) fetch.
    final dir = await getApplicationDocumentsDirectory();
    final file = File(p.join(dir.path, _fileName));
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }

  Future<void> _share(File file) async {
    await SharePlus.instance.share(ShareParams(
      files: [XFile(file.path, mimeType: 'application/pdf')],
      subject: 'Report card - ${stringOf(widget.summary['student_name'])}',
    ));
  }

  @override
  Widget build(BuildContext context) {
    final preview = mapOf(widget.summary['preview']);
    final access = mapOf(widget.summary['access']);
    final viewsLeft = access['views_remaining'];

    if (_pdf == null) {
      return ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          OutlinedCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${stringOf(widget.summary['student_name'])} · '
                  '${stringOf(widget.summary['term_name'])}',
                  style: AppText.labelLg,
                ),
                const SizedBox(height: AppSpacing.sm),
                Row(
                  children: [
                    Expanded(
                      child: _Metric(
                        label: 'Average',
                        value: '${numOf(preview['average']).toStringAsFixed(1)}%',
                      ),
                    ),
                    Expanded(
                      child: _Metric(
                        label: 'Subjects',
                        value: '${intOf(preview['subjects_count'])}',
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            onPressed: () => setState(() => _pdf = _download()),
            icon: const Icon(Icons.picture_as_pdf_outlined),
            label: const Text('Open report card'),
          ),
          if (viewsLeft != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(
              'Opening uses one view of your PIN ($viewsLeft left). '
              'Save it once it opens and you can read it again for free.',
              style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
            ),
          ],
        ],
      );
    }

    return FutureBuilder<File>(
      future: _pdf,
      builder: (context, snap) {
        if (snap.hasError) {
          return ErrorState(
            error: asApiException(snap.error!),
            onRetry: () => setState(() => _pdf = _download()),
          );
        }
        if (!snap.hasData) return const LoadingState(rows: 4);
        final file = snap.data!;

        return Column(
          children: [
            Expanded(
              child: PdfPreview(
                build: (_) => file.readAsBytes(),
                pdfFileName: _fileName,
                canChangePageFormat: false,
                canChangeOrientation: false,
                canDebug: false,
                // Our own Save / Share below; Print stays because Android's
                // print dialog also offers "Save as PDF".
                allowSharing: false,
                allowPrinting: true,
              ),
            ),
            BottomActionBar(
              child: FilledButton.icon(
                onPressed: () => _share(file),
                icon: const Icon(Icons.download_outlined),
                label: const Text('Save or share PDF'),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value});

  final String label;
  final String value;

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
      ],
    );
  }
}
