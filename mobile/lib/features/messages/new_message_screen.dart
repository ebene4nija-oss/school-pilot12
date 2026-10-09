import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import 'messages_screen.dart';

/// A teacher writing to a family: pick the student, then the parent.
///
/// Starts from the child rather than a parent directory because that is how a
/// teacher thinks ("Ada's mother"), and because the server only hands out the
/// guardians of students this teacher can already see.
class NewMessageScreen extends ConsumerStatefulWidget {
  const NewMessageScreen({super.key});

  @override
  ConsumerState<NewMessageScreen> createState() => _NewMessageScreenState();
}

class _NewMessageScreenState extends ConsumerState<NewMessageScreen> {
  final _search = TextEditingController();
  Timer? _debounce;

  List<Map<String, dynamic>> _students = const [];
  bool _searching = false;
  String? _searchError;

  @override
  void initState() {
    super.initState();
    _find('');
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _onSearch(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () => _find(value));
  }

  /// The roster endpoint already narrows a teacher to the classes they teach.
  Future<void> _find(String term) async {
    setState(() {
      _searching = true;
      _searchError = null;
    });

    try {
      final data = await ref.read(apiClientProvider).get<dynamic>(
        Api.students,
        query: {
          if (term.trim().isNotEmpty) 'search': term.trim(),
          'active_only': 1,
          'per_page': 50,
        },
      );
      if (!mounted) return;
      setState(() {
        _students = listOf(data, ['students']);
        _searching = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _searching = false;
        _searchError = asApiException(error).message;
      });
    }
  }

  Future<void> _pick(Map<String, dynamic> student) async {
    final threadId = await Navigator.of(context).push<int>(
      MaterialPageRoute(
        builder: (_) => _ComposeScreen(
          studentId: intOf(student['id']),
          studentName: stringOf(student['name'], 'Student'),
        ),
      ),
    );

    if (threadId != null && mounted) Navigator.pop(context, threadId);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New message')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            child: TextField(
              controller: _search,
              onChanged: _onSearch,
              decoration: const InputDecoration(
                hintText: 'Find a student by name or admission number',
                prefixIcon: Icon(Icons.search),
                isDense: true,
              ),
            ),
          ),
          if (_searching) const LinearProgressIndicator(),
          Expanded(
            child: _searchError != null
                ? EmptyState(
                    icon: Icons.error_outline,
                    title: 'Could not load students',
                    message: _searchError!,
                  )
                : _students.isEmpty && !_searching
                    ? const EmptyState(
                        icon: Icons.groups_outlined,
                        title: 'No students found',
                        message: 'You can message the families of students '
                            'in the classes you teach.',
                      )
                    : ListView.builder(
                        itemCount: _students.length,
                        itemBuilder: (context, i) {
                          final s = _students[i];
                          final name = stringOf(s['name'], 'Student');
                          return ListTile(
                            leading: InitialsAvatar(name),
                            title: Text(name, style: AppText.bodyLg),
                            subtitle: Text(
                              [
                                stringOf(nameOf(s['class'])),
                                stringOf(s['admission_number']),
                              ].where((t) => t.isNotEmpty).join(' · '),
                            ),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => _pick(s),
                          );
                        },
                      ),
          ),
        ],
      ),
    );
  }
}

final _recipientsProvider = FutureProvider.autoDispose
    .family<List<Map<String, dynamic>>, int>((ref, studentId) async {
  final api = ref.watch(apiClientProvider);
  final data = await api.get<dynamic>(Api.studentMessageRecipients(studentId));
  return listOf(data, ['recipients']);
});

class _ComposeScreen extends ConsumerStatefulWidget {
  const _ComposeScreen({required this.studentId, required this.studentName});

  final int studentId;
  final String studentName;

  @override
  ConsumerState<_ComposeScreen> createState() => _ComposeScreenState();
}

class _ComposeScreenState extends ConsumerState<_ComposeScreen> {
  final _subject = TextEditingController();
  final _body = TextEditingController();
  int? _recipientId;
  bool _sending = false;

  @override
  void dispose() {
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final body = _body.text.trim();
    if (_recipientId == null || body.isEmpty) return;
    setState(() => _sending = true);

    try {
      final res = await ref.read(apiClientProvider).post<dynamic>(
        Api.sendMessage,
        body: {
          'recipient_id': _recipientId,
          'student_id': widget.studentId,
          'subject': _subject.text.trim().isEmpty
              ? 'About ${widget.studentName}'
              : _subject.text.trim(),
          'body': body,
        },
      );

      ref.invalidate(threadsProvider);
      if (!mounted) return;
      Navigator.pop(context, intOf(fieldOf(mapOf(res)['data'], 'thread_id')));
    } catch (error) {
      if (!mounted) return;
      setState(() => _sending = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(asApiException(error).message)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final recipients = ref.watch(_recipientsProvider(widget.studentId));

    return Scaffold(
      appBar: AppBar(title: Text(widget.studentName)),
      body: recipients.when(
        loading: () => const LoadingState(rows: 3),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(_recipientsProvider(widget.studentId)),
        ),
        data: (list) {
          if (list.isEmpty) {
            return const EmptyState(
              icon: Icons.family_restroom,
              title: 'No parent on the app yet',
              message: "This student's parents have no SchoolPilot account to "
                  'receive messages. The school office can link them.',
            );
          }

          // One parent — the common case — is chosen without asking.
          _recipientId ??= list.length == 1 ? intOf(list.first['user_id']) : null;

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              Text('To', style: AppText.labelSm),
              for (final r in list)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(
                    _recipientId == intOf(r['user_id'])
                        ? Icons.check_circle
                        : Icons.radio_button_unchecked,
                    color: _recipientId == intOf(r['user_id'])
                        ? AppColors.action
                        : AppColors.outline,
                  ),
                  onTap: _sending
                      ? null
                      : () => setState(() => _recipientId = intOf(r['user_id'])),
                  title: Text(stringOf(r['name'], 'Parent')),
                  subtitle: stringOf(r['relationship']).isEmpty
                      ? null
                      : Text(stringOf(r['relationship'])),
                ),
              const SizedBox(height: AppSpacing.md),
              TextField(
                controller: _subject,
                decoration: InputDecoration(
                  labelText: 'Subject',
                  hintText: 'About ${widget.studentName}',
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              TextField(
                controller: _body,
                minLines: 4,
                maxLines: 10,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(labelText: 'Message'),
              ),
              const SizedBox(height: AppSpacing.lg),
              FilledButton.icon(
                onPressed: _sending ||
                        _recipientId == null ||
                        _body.text.trim().isEmpty
                    ? null
                    : _send,
                icon: _sending
                    ? const SizedBox(
                        height: 18,
                        width: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.send),
                label: const Text('Send'),
              ),
            ],
          );
        },
      ),
    );
  }
}
