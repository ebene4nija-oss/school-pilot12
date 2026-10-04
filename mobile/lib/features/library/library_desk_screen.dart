import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/barcode_scan.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// Overdue loans for the desk. Not cached: a librarian deciding who to chase
/// must see today's list, not one from the last time there was signal.
final overdueLoansProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final res = await ref.watch(apiClientProvider).get<dynamic>(
    Api.libraryLoans,
    query: {'status': 'overdue'},
  );
  return listOf(res);
});

/// The library desk on a phone (doc §9.2): lend a book by scanning its
/// barcode with the camera, take books back the same way, see who is late.
/// Online only — lending checks copies and limits on the server at that moment.
class LibraryDeskScreen extends ConsumerStatefulWidget {
  const LibraryDeskScreen({super.key});

  @override
  ConsumerState<LibraryDeskScreen> createState() => _LibraryDeskScreenState();
}

class _LibraryDeskScreenState extends ConsumerState<LibraryDeskScreen> {
  Map<String, dynamic>? _reader;
  String? _message;
  bool _ok = true;
  bool _busy = false;

  void _say(String message, {bool ok = true}) => setState(() {
        _message = message;
        _ok = ok;
      });

  Future<void> _lend() async {
    final reader = _reader;
    if (reader == null) return;
    final code = await scanBarcode(context, title: 'Scan the book to lend');
    if (code == null) return;
    setState(() => _busy = true);
    try {
      final res = await ref.read(apiClientProvider).post<dynamic>(
        Api.libraryLoans,
        body: {'barcode': code, 'borrower_user_id': intOf(reader['user_id'])},
      );
      final loan = mapOf(res, ['loan']);
      _say('"${stringOf(loan['title'])}" lent to ${stringOf(reader['name'])}, '
          'due ${Dates.dayMonth(Dates.tryParse(loan['due_date']))}.');
    } catch (error) {
      _say(asApiException(error).message, ok: false);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _return() async {
    final code = await scanBarcode(context, title: 'Scan the book coming back');
    if (code == null || !mounted) return;
    final condition = await showModalBottomSheet<String>(
      context: context,
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.all(AppSpacing.md),
              child: Text('What condition is it in?', style: AppText.headlineSm),
            ),
            ListTile(title: const Text('Good'), onTap: () => Navigator.pop(context, 'good')),
            ListTile(title: const Text('Damaged'), onTap: () => Navigator.pop(context, 'damaged')),
            ListTile(
              title: const Text('Reported lost'),
              subtitle: const Text('Removes the copy from stock'),
              onTap: () => Navigator.pop(context, 'lost'),
            ),
          ],
        ),
      ),
    );
    if (condition == null) return;
    setState(() => _busy = true);
    try {
      final res = await ref.read(apiClientProvider).post<dynamic>(
        Api.libraryReturns,
        body: {'barcode': code, 'condition': condition},
      );
      final loan = mapOf(res, ['loan']);
      final late = intOf(loan['days_overdue']);
      _say('"${stringOf(loan['title'])}" returned by ${stringOf(loan['borrower'])}'
          '${late > 0 ? ', $late day(s) late' : ''}.');
      ref.invalidate(overdueLoansProvider);
    } catch (error) {
      final failure = asApiException(error);
      _say(
        failure.statusCode == 409 && failure.message.startsWith('More than one copy')
            ? '${failure.message} Do this one from the web library page.'
            : failure.message,
        ok: false,
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickReader() async {
    final picked = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _ReaderSearchSheet(),
    );
    if (picked != null) setState(() => _reader = picked);
  }

  @override
  Widget build(BuildContext context) {
    final overdue = ref.watch(overdueLoansProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Library desk')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          if (_message != null)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.md),
              child: OutlinedCard(
                statusColor: _ok ? AppColors.present : AppColors.absent,
                child: Text(_message!, style: AppText.bodyMd),
              ),
            ),
          OutlinedCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Lend a book', style: AppText.headlineSm),
                const SizedBox(height: AppSpacing.sm),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.person_search_outlined),
                  title: Text(_reader == null ? 'Choose the reader' : stringOf(_reader!['name'])),
                  subtitle: _reader == null
                      ? null
                      : Text([stringOf(_reader!['number']), stringOf(_reader!['class'])]
                          .where((s) => s.isNotEmpty)
                          .join(' · ')),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: _pickReader,
                ),
                FilledButton.icon(
                  icon: const Icon(Icons.qr_code_scanner),
                  label: const Text('Scan book and lend'),
                  onPressed: _busy || _reader == null ? null : _lend,
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          OutlinedButton.icon(
            icon: const Icon(Icons.assignment_return_outlined),
            label: const Text('Scan a book coming back'),
            onPressed: _busy ? null : _return,
          ),
          const SectionHeader('Overdue'),
          overdue.when(
            loading: () => const LoadingState(rows: 2),
            error: (e, _) => ErrorState(
              error: asApiException(e),
              onRetry: () => ref.invalidate(overdueLoansProvider),
            ),
            data: (rows) => rows.isEmpty
                ? const Text('Nothing overdue.', style: AppText.bodyMd)
                : Column(
                    children: [
                      for (final l in rows)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(stringOf(l['title'])),
                          subtitle: Text(
                            [stringOf(l['borrower']), stringOf(l['class'])]
                                .where((s) => s.isNotEmpty)
                                .join(' · '),
                          ),
                          trailing: StatusChip.absent('${intOf(l['days_overdue'])}d late'),
                        ),
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}

/// Find a student or member of staff by name, admission or staff number.
class _ReaderSearchSheet extends ConsumerStatefulWidget {
  const _ReaderSearchSheet();

  @override
  ConsumerState<_ReaderSearchSheet> createState() => _ReaderSearchSheetState();
}

class _ReaderSearchSheetState extends ConsumerState<_ReaderSearchSheet> {
  final _query = TextEditingController();
  List<Map<String, dynamic>> _found = const [];
  String? _error;

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    if (_query.text.trim().length < 2) return;
    try {
      final res = await ref.read(apiClientProvider).get<dynamic>(
        Api.libraryBorrowers,
        query: {'q': _query.text.trim()},
      );
      setState(() {
        _found = listOf(res);
        _error = _found.isEmpty ? 'Nobody matches.' : null;
      });
    } catch (error) {
      setState(() => _error = asApiException(error).message);
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
        children: [
          TextField(
            controller: _query,
            autofocus: true,
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => _search(),
            decoration: InputDecoration(
              hintText: 'Name, admission or staff number',
              suffixIcon: IconButton(icon: const Icon(Icons.search), onPressed: _search),
            ),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.all(AppSpacing.sm),
              child: Text(_error!, style: AppText.bodyMd),
            ),
          ConstrainedBox(
            constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.5),
            child: ListView(
              shrinkWrap: true,
              children: [
                for (final r in _found)
                  ListTile(
                    leading: InitialsAvatar(stringOf(r['name'], '?')),
                    title: Text(stringOf(r['name'])),
                    subtitle: Text(
                      [stringOf(r['number']), stringOf(r['class']), stringOf(r['kind'])]
                          .where((s) => s.isNotEmpty)
                          .join(' · '),
                    ),
                    onTap: () => Navigator.pop(context, r),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
