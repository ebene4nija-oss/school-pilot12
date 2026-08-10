import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final rosterProvider =
    FutureProvider.autoDispose<Cached<List<Map<String, dynamic>>>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  final cached = await cachedGet<dynamic>(
    cache: cache,
    key: 'students:roster',
    fetch: () => api.get<dynamic>(Api.students),
  );

  return Cached(
    data: listOf(cached.data, ['students']),
    fetchedAt: cached.fetchedAt,
    stale: cached.stale,
  );
});

class StudentsScreen extends ConsumerStatefulWidget {
  const StudentsScreen({super.key});

  @override
  ConsumerState<StudentsScreen> createState() => _StudentsScreenState();
}

class _StudentsScreenState extends ConsumerState<StudentsScreen> {
  String _search = '';
  String? _classFilter;

  @override
  Widget build(BuildContext context) {
    final roster = ref.watch(rosterProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Students')),
      body: CachedView<List<Map<String, dynamic>>>(
        value: roster,
        onRetry: () => ref.invalidate(rosterProvider),
        builder: (context, students, meta) {
          final classes = {
            for (final s in students)
              stringOf(s['class']?['name'] ?? s['class_name'])
          }..removeWhere((c) => c.isEmpty);

          final filtered = students.where((s) {
            final name = stringOf(
              s['name'] ?? s['full_name'] ?? s['user']?['name'],
            ).toLowerCase();
            final className =
                stringOf(s['class']?['name'] ?? s['class_name']);
            final matchesSearch =
                _search.isEmpty || name.contains(_search.toLowerCase());
            final matchesClass =
                _classFilter == null || className == _classFilter;
            return matchesSearch && matchesClass;
          }).toList();

          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.screenMargin,
                  vertical: AppSpacing.sm,
                ),
                child: TextField(
                  onChanged: (v) => setState(() => _search = v),
                  decoration: const InputDecoration(
                    hintText: 'Search students',
                    prefixIcon: Icon(Icons.search),
                    isDense: true,
                  ),
                ),
              ),
              if (classes.isNotEmpty)
                SizedBox(
                  height: 44,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSpacing.screenMargin,
                    ),
                    children: [
                      _FilterChip(
                        label: 'All',
                        selected: _classFilter == null,
                        onTap: () => setState(() => _classFilter = null),
                      ),
                      for (final c in classes)
                        _FilterChip(
                          label: c,
                          selected: _classFilter == c,
                          onTap: () => setState(() => _classFilter = c),
                        ),
                    ],
                  ),
                ),
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.screenMargin,
                  vertical: AppSpacing.sm,
                ),
                child: Row(
                  children: [
                    Text(
                      '${filtered.length} '
                      '${filtered.length == 1 ? 'student' : 'students'}',
                      style: AppText.labelSm
                          .copyWith(color: AppColors.onSurfaceVariant),
                    ),
                    const Spacer(),
                    Text(
                      Dates.freshness(meta.fetchedAt),
                      style: AppText.labelSm
                          .copyWith(color: AppColors.onSurfaceVariant),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: filtered.isEmpty
                    ? EmptyState(
                        icon: Icons.groups_outlined,
                        title: _search.isEmpty
                            ? 'No students yet'
                            : 'No match for "$_search"',
                        message: _search.isEmpty
                            ? 'Students are added from the web portal, where '
                                'bulk import is available.'
                            : 'Try a different name.',
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.fromLTRB(
                          AppSpacing.screenMargin,
                          0,
                          AppSpacing.screenMargin,
                          AppSpacing.xl,
                        ),
                        itemCount: filtered.length,
                        separatorBuilder: (_, __) =>
                            const SizedBox(height: AppSpacing.sm),
                        itemBuilder: (context, i) =>
                            _StudentCard(student: filtered[i]),
                      ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: AppSpacing.sm),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) => onTap(),
        showCheckmark: false,
        selectedColor: AppColors.primaryContainer,
        labelStyle: AppText.labelSm.copyWith(
          color: selected ? AppColors.onPrimary : AppColors.onSurface,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

class _StudentCard extends StatelessWidget {
  const _StudentCard({required this.student});

  final Map<String, dynamic> student;

  @override
  Widget build(BuildContext context) {
    final name = stringOf(
      student['name'] ?? student['full_name'] ?? student['user']?['name'],
      'Student',
    );
    final className = stringOf(student['class']?['name'] ?? student['class_name']);
    final admission = stringOf(
      student['admission_number'] ?? student['adm_no'],
    );
    final owing = numOf(student['outstanding'] ?? student['balance']);

    return OutlinedCard(
      statusColor: owing > 0 ? AppColors.overdue : AppColors.paid,
      onTap: () => showModalBottomSheet<void>(
        context: context,
        builder: (_) => _StudentSheet(student: student),
      ),
      child: Row(
        children: [
          InitialsAvatar(name),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(name, style: AppText.bodyLg, maxLines: 1),
                Text(
                  [className, admission].where((s) => s.isNotEmpty).join(' · '),
                  style: AppText.bodyMd
                      .copyWith(color: AppColors.onSurfaceVariant),
                  maxLines: 1,
                ),
              ],
            ),
          ),
          if (owing > 0)
            StatusChip(Money.whole(owing), color: AppColors.overdue)
          else
            const StatusChip('Paid', color: AppColors.paid),
        ],
      ),
    );
  }
}

class _StudentSheet extends StatelessWidget {
  const _StudentSheet({required this.student});

  final Map<String, dynamic> student;

  @override
  Widget build(BuildContext context) {
    final name = stringOf(
      student['name'] ?? student['full_name'] ?? student['user']?['name'],
      'Student',
    );

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                InitialsAvatar(name, size: 56),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(name, style: AppText.headlineSm),
                      Text(
                        stringOf(
                          student['class']?['name'] ?? student['class_name'],
                        ),
                        style: AppText.bodyMd
                            .copyWith(color: AppColors.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.lg),
            _detail('Admission number',
                stringOf(student['admission_number'] ?? student['adm_no'], '—')),
            _detail('Gender', stringOf(student['gender'], '—')),
            _detail(
              'Date of birth',
              Dates.full(Dates.tryParse(student['date_of_birth'])),
            ),
            const SizedBox(height: AppSpacing.md),
            // Health data is deliberately not shown here. It is a separate,
            // audited endpoint restricted to admins and guardians, and it is
            // never cached on the device (NDPA, doc §12).
            Text(
              'Health records are held separately and are not shown in the app.',
              style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }

  Widget _detail(String label, String value) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: Row(
          children: [
            SizedBox(
              width: 150,
              child: Text(
                label,
                style: AppText.labelSm
                    .copyWith(color: AppColors.onSurfaceVariant),
              ),
            ),
            Expanded(child: Text(value, style: AppText.bodyMd)),
          ],
        ),
      );
}
