import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// A reader's library books: on loan, due dates, reservations. A student or
/// member of staff sees their own and can renew and reserve; a parent passes
/// [studentId] and sees their child's, read-only.
final libraryBooksProvider = FutureProvider.autoDispose
    .family<Cached<dynamic>, int?>((ref, studentId) async {
  final api = ref.watch(apiClientProvider);
  final cache = ref.watch(cacheStoreProvider);

  return cachedGet<dynamic>(
    cache: cache,
    key: studentId == null ? 'library:me' : 'library:child:$studentId',
    fetch: () => api.get<dynamic>(
      studentId == null ? Api.libraryMine : Api.libraryChild(studentId),
    ),
  );
});

class LibraryScreen extends ConsumerWidget {
  const LibraryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('My library books')),
      body: const LibraryBooksView(),
    );
  }
}

/// The body, reused by the parent's child screen.
class LibraryBooksView extends ConsumerWidget {
  const LibraryBooksView({super.key, this.studentId});

  final int? studentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(libraryBooksProvider(studentId));

    return CachedView<dynamic>(
      value: value,
      onRetry: () => ref.invalidate(libraryBooksProvider(studentId)),
      builder: (context, data, meta) {
        final view = mapOf(data);
        final loans = listOf(view['loans']);
        final holds = listOf(view['reservations']);
        final history = listOf(view['history']);
        final canReserve = view['can_reserve'] == true;

        return RefreshableList(
          onRefresh: () async {
            ref.invalidate(libraryBooksProvider(studentId));
            await ref.read(libraryBooksProvider(studentId).future);
          },
          children: [
            FreshnessLine(
              fetchedAt: meta.fetchedAt,
              onRefresh: () => ref.invalidate(libraryBooksProvider(studentId)),
            ),
            const SectionHeader('On loan'),
            if (loans.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin),
                child: Text('No books out.', style: AppText.bodyMd),
              ),
            for (final loan in loans)
              _pad(_LoanCard(loan: loan, studentId: studentId)),
            if (holds.isNotEmpty) ...[
              const SectionHeader('Reserved'),
              for (final hold in holds)
                _pad(_HoldCard(hold: hold, canCancel: canReserve, studentId: studentId)),
            ],
            if (canReserve) _pad(_ReserveButton(studentId: studentId)),
            if (history.isNotEmpty) ...[
              const SectionHeader('Returned recently'),
              for (final h in history.take(10))
                ListTile(
                  dense: true,
                  title: Text(stringOf(h['title']), style: AppText.bodyMd),
                  subtitle: Text(
                    'Returned ${Dates.dayMonth(Dates.tryParse(h['returned_at']))}',
                    style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
                  ),
                ),
            ],
          ],
        );
      },
    );
  }

  static Widget _pad(Widget child) => Padding(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.screenMargin,
          0,
          AppSpacing.screenMargin,
          AppSpacing.sm,
        ),
        child: child,
      );
}

class _LoanCard extends ConsumerStatefulWidget {
  const _LoanCard({required this.loan, required this.studentId});

  final Map<String, dynamic> loan;
  final int? studentId;

  @override
  ConsumerState<_LoanCard> createState() => _LoanCardState();
}

class _LoanCardState extends ConsumerState<_LoanCard> {
  bool _busy = false;

  Future<void> _renew() async {
    setState(() => _busy = true);
    try {
      final res = await ref.read(apiClientProvider).post<dynamic>(
            Api.libraryRenew(intOf(widget.loan['id'])),
          );
      final due = Dates.dayMonth(Dates.tryParse(mapOf(res, ['loan'])['due_date']));
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Renewed. Now due $due.')),
      );
      ref.invalidate(libraryBooksProvider(widget.studentId));
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(asApiException(error).message)),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final loan = widget.loan;
    final late = intOf(loan['days_overdue']);

    return OutlinedCard(
      statusColor: late > 0 ? AppColors.overdue : AppColors.present,
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(stringOf(loan['title'], 'Book'), style: AppText.bodyLg),
                Text(
                  stringOf(loan['author']),
                  style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                ),
                const SizedBox(height: AppSpacing.xs),
                late > 0
                    ? StatusChip.absent('$late day${late == 1 ? '' : 's'} late')
                    : StatusChip(
                        'Due ${Dates.dayMonth(Dates.tryParse(loan['due_date']))}',
                        color: AppColors.primaryContainer,
                      ),
              ],
            ),
          ),
          if (loan['can_renew'] == true)
            TextButton(
              onPressed: _busy ? null : _renew,
              child: Text(_busy ? 'Renewing…' : 'Renew'),
            ),
        ],
      ),
    );
  }
}

class _HoldCard extends ConsumerWidget {
  const _HoldCard({required this.hold, required this.canCancel, required this.studentId});

  final Map<String, dynamic> hold;
  final bool canCancel;
  final int? studentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ready = hold['status'] == 'ready';

    return OutlinedCard(
      statusColor: ready ? AppColors.present : AppColors.pending,
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(stringOf(hold['title'], 'Book'), style: AppText.bodyLg),
                const SizedBox(height: AppSpacing.xs),
                ready
                    ? StatusChip.present(
                        'Ready to collect until ${Dates.dayMonth(Dates.tryParse(hold['ready_until']))}',
                      )
                    : const StatusChip.pending('Waiting for a copy'),
              ],
            ),
          ),
          if (canCancel)
            TextButton(
              onPressed: () async {
                try {
                  await ref.read(apiClientProvider).delete<dynamic>(
                        Api.libraryReservation(intOf(hold['id'])),
                      );
                  ref.invalidate(libraryBooksProvider(studentId));
                } catch (error) {
                  if (!context.mounted) return;
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(asApiException(error).message)),
                  );
                }
              },
              child: const Text('Cancel'),
            ),
        ],
      ),
    );
  }
}

class _ReserveButton extends ConsumerWidget {
  const _ReserveButton({required this.studentId});

  final int? studentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return OutlinedButton.icon(
      icon: const Icon(Icons.search),
      label: const Text('Find a book to reserve'),
      onPressed: () async {
        await showModalBottomSheet<void>(
          context: context,
          isScrollControlled: true,
          builder: (_) => const _ReserveSheet(),
        );
        ref.invalidate(libraryBooksProvider(studentId));
      },
    );
  }
}

/// Search the catalogue; a book with no copy on the shelf can be reserved.
class _ReserveSheet extends ConsumerStatefulWidget {
  const _ReserveSheet();

  @override
  ConsumerState<_ReserveSheet> createState() => _ReserveSheetState();
}

class _ReserveSheetState extends ConsumerState<_ReserveSheet> {
  final _query = TextEditingController();
  List<Map<String, dynamic>>? _books;
  String? _message;

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    final term = _query.text.trim();
    if (term.isEmpty) return;
    try {
      final res = await ref.read(apiClientProvider).get<dynamic>(
        Api.libraryBooks,
        query: {'search': term},
      );
      setState(() {
        _books = listOf(res);
        _message = null;
      });
    } catch (error) {
      setState(() => _message = asApiException(error).message);
    }
  }

  Future<void> _reserve(Map<String, dynamic> book) async {
    try {
      await ref.read(apiClientProvider).post<dynamic>(
        Api.libraryReservations,
        body: {'book_id': intOf(book['id'])},
      );
      setState(() => _message = '"${stringOf(book['title'])}" reserved. You will be told when it is ready.');
    } catch (error) {
      setState(() => _message = asApiException(error).message);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: AppSpacing.screenMargin,
        right: AppSpacing.screenMargin,
        top: AppSpacing.md,
        bottom: MediaQuery.of(context).viewInsets.bottom + AppSpacing.md,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Reserve a book', style: AppText.headlineSm),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            controller: _query,
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => _search(),
            decoration: InputDecoration(
              hintText: 'Title or author',
              suffixIcon: IconButton(icon: const Icon(Icons.search), onPressed: _search),
            ),
          ),
          if (_message != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(_message!, style: AppText.bodyMd),
          ],
          if (_books != null)
            ConstrainedBox(
              constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.5),
              child: _books!.isEmpty
                  ? const Padding(
                      padding: EdgeInsets.all(AppSpacing.md),
                      child: Text('No book matches.', style: AppText.bodyMd),
                    )
                  : ListView(
                      shrinkWrap: true,
                      children: [
                        for (final b in _books!)
                          ListTile(
                            title: Text(stringOf(b['title'])),
                            subtitle: Text(stringOf(b['author'])),
                            trailing: intOf(b['available_copies']) > 0
                                ? const StatusChip.present('On the shelf')
                                : TextButton(
                                    onPressed: () => _reserve(b),
                                    child: const Text('Reserve'),
                                  ),
                          ),
                      ],
                    ),
            ),
        ],
      ),
    );
  }
}
