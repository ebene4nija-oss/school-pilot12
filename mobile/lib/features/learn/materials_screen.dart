import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/auth/session.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import '../dashboard/dashboard_providers.dart';
import 'learning_widgets.dart';

/// Everything the school has shared with this student: lesson notes,
/// documents, video and audio recordings, and links.
///
/// The Learn tab shows only the newest ten; this is the whole library, by
/// subject. A parent sees their selected child's, when the school shares
/// materials with parents.
final materialsLibraryProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final role = ref.watch(currentRoleProvider);
  final childId = role == UserRole.parent ? ref.watch(selectedChildIdProvider) : null;
  final data = await ref.watch(apiClientProvider).get<dynamic>(
        Api.learningMaterials,
        query: {if (childId != null) 'student_id': childId},
      );
  return mapOf(data);
});

class MaterialsScreen extends ConsumerStatefulWidget {
  const MaterialsScreen({super.key});

  @override
  ConsumerState<MaterialsScreen> createState() => _MaterialsScreenState();
}

class _MaterialsScreenState extends ConsumerState<MaterialsScreen> {
  String? _subject;
  String? _type;
  String _search = '';

  static const _types = {
    'video': ('Video', Icons.ondemand_video_outlined),
    'audio': ('Audio', Icons.headphones_outlined),
    'document': ('Notes', Icons.description_outlined),
    'link': ('Links', Icons.link),
  };

  /// The server's types grouped for filtering: pdf, word, slides and images
  /// all read as "Notes" to a student.
  static String _kind(Object? type) => switch (stringOf(type)) {
        'video' => 'video',
        'audio' => 'audio',
        'link' => 'link',
        _ => 'document',
      };

  @override
  Widget build(BuildContext context) {
    final library = ref.watch(materialsLibraryProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Lesson notes & materials')),
      body: library.when(
        loading: () => const LoadingState(rows: 6),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(materialsLibraryProvider),
        ),
        data: (data) {
          final all = listOf(data['data']);
          if (all.isEmpty) {
            return EmptyState(
              icon: Icons.menu_book_outlined,
              title: 'Nothing shared yet',
              message: stringOf(
                data['message'],
                'Lesson notes, recordings and links from your teachers appear here.',
              ),
            );
          }

          final subjects = {
            for (final m in all)
              if (stringOf(m['subject']).isNotEmpty) stringOf(m['subject']),
          }.toList()
            ..sort();
          final q = _search.toLowerCase();
          final shown = all.where((m) {
            if (_subject != null && stringOf(m['subject']) != _subject) return false;
            if (_type != null && _kind(m['type']) != _type) return false;
            if (q.isEmpty) return true;
            return '${m['title']} ${m['topic'] ?? ''} ${m['subject'] ?? ''}'
                .toLowerCase()
                .contains(q);
          }).toList();

          return RefreshIndicator(
            onRefresh: () async => ref.refresh(materialsLibraryProvider.future),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.screenMargin, AppSpacing.sm, AppSpacing.screenMargin, AppSpacing.xl,
              ),
              children: [
                TextField(
                  decoration: const InputDecoration(
                    hintText: 'Search by title or topic',
                    prefixIcon: Icon(Icons.search),
                    isDense: true,
                  ),
                  onChanged: (v) => setState(() => _search = v.trim()),
                ),
                const SizedBox(height: AppSpacing.sm),
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      for (final e in _types.entries)
                        Padding(
                          padding: const EdgeInsets.only(right: AppSpacing.sm),
                          child: FilterChip(
                            avatar: Icon(e.value.$2, size: 18),
                            label: Text(e.value.$1),
                            selected: _type == e.key,
                            onSelected: (on) => setState(() => _type = on ? e.key : null),
                          ),
                        ),
                    ],
                  ),
                ),
                if (subjects.length > 1) ...[
                  const SizedBox(height: AppSpacing.xs),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        Padding(
                          padding: const EdgeInsets.only(right: AppSpacing.sm),
                          child: ChoiceChip(
                            label: const Text('All subjects'),
                            selected: _subject == null,
                            onSelected: (_) => setState(() => _subject = null),
                          ),
                        ),
                        for (final s in subjects)
                          Padding(
                            padding: const EdgeInsets.only(right: AppSpacing.sm),
                            child: ChoiceChip(
                              label: Text(s),
                              selected: _subject == s,
                              onSelected: (_) => setState(() => _subject = s),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: AppSpacing.md),
                if (shown.isEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: AppSpacing.lg),
                    child: Text(
                      'Nothing matches. Clear a filter to see more.',
                      textAlign: TextAlign.center,
                      style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                    ),
                  ),
                for (final m in shown) ...[
                  _MaterialRow(material: m, icon: _types[_kind(m['type'])]!.$2),
                  const SizedBox(height: AppSpacing.sm),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

class _MaterialRow extends ConsumerWidget {
  const _MaterialRow({required this.material, required this.icon});

  final Map<String, dynamic> material;
  final IconData icon;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final m = material;
    final when = Dates.tryParse(m['published_at']);
    final detail = [
      stringOf(m['subject']),
      stringOf(m['topic']),
      if (stringOf(m['uploaded_by']).isNotEmpty) 'by ${stringOf(m['uploaded_by'])}',
      if (when != null) Dates.dayMonth(when),
    ].where((s) => s.isNotEmpty).join(' · ');

    return OutlinedCard(
      onTap: () => openMaterial(context, ref, intOf(m['id'])),
      child: Row(
        children: [
          Icon(icon, color: AppColors.primaryContainer),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(stringOf(m['title'], 'Material'), style: AppText.bodyLg),
                if (detail.isNotEmpty)
                  Text(
                    detail,
                    style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                  ),
              ],
            ),
          ),
          const Icon(Icons.open_in_new, size: 18, color: AppColors.outline),
        ],
      ),
    );
  }
}
