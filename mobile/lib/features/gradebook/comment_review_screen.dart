import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/pickers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import 'score_entry_screen.dart';

/// The approval gate.
///
/// An AI-generated comment never reaches a report card unreviewed — it sits in
/// `pending_approval` until a teacher approves or edits it. That is a hard
/// product rule, so this screen states it in words as well as colour, and no
/// other screen in the app renders a comment in that state as if it were final.
class CommentReviewScreen extends ConsumerWidget {
  const CommentReviewScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final classId = ref.watch(selectedClassProvider);
    final termId = ref.watch(selectedTermProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Comment review')),
      body: Column(
        children: [
          const ClassTermBar(),
          Expanded(
            child: (classId == null || termId == null)
                ? const MissingReferenceData(what: 'classes or terms')
                : _ReviewList(
                    query: BroadsheetQuery(classId: classId, termId: termId),
                  ),
          ),
        ],
      ),
    );
  }
}

class _ReviewList extends ConsumerWidget {
  const _ReviewList({required this.query});

  final BroadsheetQuery query;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final broadsheet = ref.watch(broadsheetProvider(query));

    return CachedView<Map<String, dynamic>>(
      value: broadsheet,
      onRetry: () => ref.invalidate(broadsheetProvider(query)),
      builder: (context, data, meta) {
        final comments = _extractComments(data);

        if (comments.isEmpty) {
          return const EmptyState(
            icon: Icons.check_circle_outline,
            title: 'Nothing to review',
            message:
                'No AI comments are waiting for approval in this class and '
                'term.',
          );
        }

        final pending = comments.where((c) => c.pending).length;

        return RefreshableList(
          onRefresh: () async => ref.refresh(broadsheetProvider(query).future),
          children: [
            Padding(
              padding: const EdgeInsets.all(AppSpacing.screenMargin),
              child: Row(
                children: [
                  Text('AI review queue', style: AppText.headlineSm),
                  const Spacer(),
                  StatusChip.pending('$pending pending'),
                ],
              ),
            ),
            for (final comment in comments)
              Padding(
                padding: const EdgeInsets.only(
                  left: AppSpacing.screenMargin,
                  right: AppSpacing.screenMargin,
                  bottom: AppSpacing.md,
                ),
                child: _CommentCard(comment: comment, query: query),
              ),
          ],
        );
      },
    );
  }

  static List<_Comment> _extractComments(Map<String, dynamic> data) {
    final students = listOf(data, ['students', 'broadsheet', 'rows']);
    final out = <_Comment>[];

    for (final student in students) {
      final name = stringOf(
        student['name'] ?? student['full_name'] ?? student['user']?['name'],
        'Student',
      );
      final scores = student['scores'];
      if (scores is! List) continue;

      for (final raw in scores.whereType<Map>()) {
        final score = raw.cast<String, dynamic>();
        final text = stringOf(score['ai_comment'] ?? score['teacher_comment']);
        if (text.isEmpty) continue;

        out.add(
          _Comment(
            scoreId: intOf(score['id']),
            studentName: name,
            subject: stringOf(
              score['subject']?['name'] ?? score['subject_name'],
            ),
            text: text,
            // The backend's own vocabulary. Anything not explicitly approved is
            // treated as pending — failing closed is the only safe direction
            // for a comment that could otherwise be printed.
            pending: stringOf(score['comment_status'] ?? score['status']) !=
                'approved',
          ),
        );
      }
    }

    return out;
  }
}

class _Comment {
  const _Comment({
    required this.scoreId,
    required this.studentName,
    required this.subject,
    required this.text,
    required this.pending,
  });

  final int scoreId;
  final String studentName;
  final String subject;
  final String text;
  final bool pending;
}

class _CommentCard extends ConsumerStatefulWidget {
  const _CommentCard({required this.comment, required this.query});

  final _Comment comment;
  final BroadsheetQuery query;

  @override
  ConsumerState<_CommentCard> createState() => _CommentCardState();
}

class _CommentCardState extends ConsumerState<_CommentCard> {
  late final TextEditingController _editor =
      TextEditingController(text: widget.comment.text);
  bool _editing = false;
  bool _busy = false;
  bool _approved = false;

  @override
  void dispose() {
    _editor.dispose();
    super.dispose();
  }

  Future<void> _submit({required bool approve}) async {
    setState(() => _busy = true);

    try {
      await ref.read(apiClientProvider).post<dynamic>(
        Api.reviewComment(widget.comment.scoreId),
        body: {
          'action': approve ? 'approve' : 'edit',
          'approved': approve,
          if (!approve) 'comment': _editor.text.trim(),
          if (!approve) 'teacher_comment': _editor.text.trim(),
        },
      );

      if (!mounted) return;
      setState(() {
        _busy = false;
        _editing = false;
        _approved = true;
      });
      ref.invalidate(broadsheetProvider(widget.query));
    } catch (error) {
      if (!mounted) return;
      setState(() => _busy = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(asApiException(error).message)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final approved = _approved || !widget.comment.pending;

    return OutlinedCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(widget.comment.studentName, style: AppText.bodyLg),
                    if (widget.comment.subject.isNotEmpty)
                      Text(
                        widget.comment.subject,
                        style: AppText.bodyMd
                            .copyWith(color: AppColors.onSurfaceVariant),
                      ),
                  ],
                ),
              ),
              approved
                  ? const StatusChip(
                      'Approved',
                      color: AppColors.present,
                      icon: Icons.check_circle_outline,
                    )
                  : const StatusChip(
                      'Pending approval',
                      color: AppColors.late,
                      icon: Icons.auto_awesome,
                    ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          if (_editing)
            TextField(
              controller: _editor,
              maxLines: 6,
              maxLength: 500,
              decoration: const InputDecoration(
                labelText: 'Comment',
                alignLabelWithHint: true,
              ),
            )
          else
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(AppSpacing.md),
              decoration: BoxDecoration(
                color: AppColors.surfaceContainerLow,
                border: Border(
                  left: BorderSide(
                    color: approved ? AppColors.present : AppColors.late,
                    width: 4,
                  ),
                ),
              ),
              child: Text(widget.comment.text, style: AppText.bodyLg),
            ),
          if (!approved) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(
              'Draft generated by AI — not on the report card until you approve it.',
              style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
            ),
          ],
          const SizedBox(height: AppSpacing.md),
          if (!approved)
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _busy
                        ? null
                        : () => setState(() => _editing = !_editing),
                    icon: const Icon(Icons.edit_outlined, size: 18),
                    label: Text(_editing ? 'Cancel' : 'Edit'),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: FilledButton.icon(
                    onPressed:
                        _busy ? null : () => _submit(approve: !_editing),
                    icon: const Icon(Icons.check, size: 18),
                    label: Text(_editing ? 'Save & approve' : 'Approve'),
                  ),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
