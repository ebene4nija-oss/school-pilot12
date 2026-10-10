import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// The academic record behind [path]: `/me/academic-history` for a student,
/// `/parent/children/{id}/academic-history` for a guardian, and
/// `/students/{id}/academic-history` for staff. The server decides what each
/// may see (families: released terms only, no class comparisons); this
/// screen draws whatever it is given.
final academicHistoryProvider =
    FutureProvider.autoDispose.family<Map<String, dynamic>, String>((ref, path) async {
  return mapOf(await ref.watch(apiClientProvider).get<dynamic>(path));
});

class AcademicHistoryScreen extends ConsumerWidget {
  const AcademicHistoryScreen({super.key, required this.path, this.title = 'Academic history'});

  final String path;
  final String title;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final history = ref.watch(academicHistoryProvider(path));

    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: history.when(
        loading: () => const LoadingState(rows: 6),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(academicHistoryProvider(path)),
        ),
        data: (data) => RefreshIndicator(
          onRefresh: () async => ref.refresh(academicHistoryProvider(path).future),
          child: _Body(data: data),
        ),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.data});

  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final student = mapOf(data['student']);
    final terms = listOf(data['terms']);
    final sessions = listOf(data['sessions']);
    final timeline = listOf(data['timeline']);
    final previous = listOf(data['previous_schools']);

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, AppSpacing.xl,
      ),
      children: [
        Text(stringOf(student['name'], 'Student'), style: AppText.headlineSm),
        Text(
          [stringOf(student['class']), stringOf(student['admission_number'])]
              .where((s) => s.isNotEmpty)
              .join(' · '),
          style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
        ),
        if (data['partial_history'] == true && terms.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.sm),
          Text(
            'Some records here are not yet verified by the school.',
            style: AppText.labelSm.copyWith(color: AppColors.late),
          ),
        ],
        const SizedBox(height: AppSpacing.md),
        if (terms.isEmpty)
          const EmptyState(
            icon: Icons.history_edu_outlined,
            title: 'No term records yet',
            message: 'Each term appears here once the school closes it and releases the results.',
          )
        else ...[
          _ProgressChart(terms: terms),
          if (sessions.isNotEmpty) ...[
            const SectionHeader('By session'),
            for (final s in sessions)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: Row(
                  children: [
                    Expanded(child: Text(stringOf(s['session'], 'Session'), style: AppText.bodyLg)),
                    Text(
                      s['session_average'] == null ? '—' : '${numOf(s['session_average']).toStringAsFixed(1)}%',
                      style: AppText.labelLg,
                    ),
                  ],
                ),
              ),
          ],
          const SectionHeader('Terms'),
          for (final t in terms.reversed) _TermCard(term: t),
        ],
        if (timeline.isNotEmpty) ...[
          const SectionHeader('School journey'),
          for (final e in timeline)
            ListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              leading: const Icon(Icons.timeline, color: AppColors.primaryContainer),
              title: Text('${stringOf(e['session'])} · ${stringOf(e['class'])}'.trim()),
              subtitle: Text(
                [
                  stringOf(e['outcome']).replaceAll('_', ' '),
                  if (stringOf(e['destination']).isNotEmpty) 'to ${stringOf(e['destination'])}',
                ].where((s) => s.isNotEmpty).join(' · '),
              ),
            ),
        ],
        if (previous.isNotEmpty) ...[
          const SectionHeader('Previous schools'),
          for (final p in previous)
            ListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              leading: const Icon(Icons.school_outlined),
              title: Text(stringOf(p['school'], 'Previous school')),
              subtitle: Text(stringOf(p['provenance_label'])),
            ),
        ],
      ],
    );
  }
}

/// Term averages over time: the shape of a student's progress at a glance.
class _ProgressChart extends StatelessWidget {
  const _ProgressChart({required this.terms});

  final List<Map<String, dynamic>> terms;

  @override
  Widget build(BuildContext context) {
    final points = <FlSpot>[];
    final labels = <String>[];
    for (final t in terms) {
      final avg = mapOf(t['figures'])['average'];
      if (avg == null) continue;
      points.add(FlSpot(points.length.toDouble(), numOf(avg).toDouble()));
      labels.add(_short(stringOf(t['term'])));
    }
    if (points.length < 2) return const SizedBox.shrink();

    final first = points.first.y;
    final last = points.last.y;
    final change = last - first;

    return OutlinedCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text('Academic progress', style: AppText.labelLg)),
              Text(
                '${change >= 0 ? '▲' : '▼'} ${change.abs().toStringAsFixed(1)} since first term',
                style: AppText.labelSm.copyWith(color: change >= 0 ? AppColors.present : AppColors.absent),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          SizedBox(
            height: 160,
            child: LineChart(
              LineChartData(
                minY: 0,
                maxY: 100,
                gridData: const FlGridData(drawVerticalLine: false, horizontalInterval: 25),
                borderData: FlBorderData(show: false),
                titlesData: FlTitlesData(
                  topTitles: const AxisTitles(),
                  rightTitles: const AxisTitles(),
                  leftTitles: const AxisTitles(
                    sideTitles: SideTitles(showTitles: true, reservedSize: 32, interval: 25),
                  ),
                  bottomTitles: AxisTitles(
                    sideTitles: SideTitles(
                      showTitles: true,
                      interval: 1,
                      getTitlesWidget: (v, meta) {
                        final i = v.toInt();
                        if (i < 0 || i >= labels.length || v != i.toDouble()) return const SizedBox.shrink();
                        return Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Text(labels[i], style: AppText.labelSm),
                        );
                      },
                    ),
                  ),
                ),
                lineBarsData: [
                  LineChartBarData(
                    spots: points,
                    isCurved: true,
                    preventCurveOverShooting: true,
                    color: AppColors.primaryContainer,
                    barWidth: 3,
                    dotData: const FlDotData(show: true),
                    belowBarData: BarAreaData(
                      show: true,
                      color: AppColors.primaryContainer.withValues(alpha: 0.08),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// "First Term" → "1st", keeping the axis readable on a phone.
  static String _short(String term) {
    final t = term.toLowerCase();
    if (t.startsWith('first')) return '1st';
    if (t.startsWith('second')) return '2nd';
    if (t.startsWith('third')) return '3rd';
    return term.length > 4 ? term.substring(0, 4) : term;
  }
}

class _TermCard extends StatelessWidget {
  const _TermCard({required this.term});

  final Map<String, dynamic> term;

  @override
  Widget build(BuildContext context) {
    final f = mapOf(term['figures']);
    final subjects = listOf(f['subjects']);
    final position = intOf(f['position']);
    final size = intOf(f['class_size']);

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: OutlinedCard(
        padding: EdgeInsets.zero,
        child: ExpansionTile(
          shape: const Border(),
          title: Text(
            '${stringOf(term['term'])} · ${stringOf(term['session'])}',
            style: AppText.bodyLg,
          ),
          subtitle: Text(
            [
              stringOf(term['class']),
              if (f['average'] != null) 'Average ${numOf(f['average']).toStringAsFixed(1)}%',
              if (position > 0) '${Academic.ordinal(position)}${size > 0 ? ' of $size' : ''}',
            ].where((s) => s.isNotEmpty).join(' · '),
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          childrenPadding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.md),
          children: [
            for (final s in subjects)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: Row(
                  children: [
                    Expanded(child: Text(stringOf(s['subject'], 'Subject'), style: AppText.bodyMd)),
                    SizedBox(
                      width: 48,
                      child: Text(
                        s['total'] == null ? '—' : numOf(s['total']).toStringAsFixed(0),
                        textAlign: TextAlign.right,
                        style: AppText.labelLg,
                      ),
                    ),
                    SizedBox(
                      width: 44,
                      child: Text(stringOf(s['grade'], ''), textAlign: TextAlign.right, style: AppText.labelLg),
                    ),
                  ],
                ),
              ),
            if (stringOf(f['form_teacher_comment']).isNotEmpty) ...[
              const SizedBox(height: AppSpacing.sm),
              Text('Form teacher: ${stringOf(f['form_teacher_comment'])}', style: AppText.bodyMd),
            ],
            if (stringOf(f['principal_comment']).isNotEmpty)
              Text('Principal: ${stringOf(f['principal_comment'])}', style: AppText.bodyMd),
          ],
        ),
      ),
    );
  }
}
