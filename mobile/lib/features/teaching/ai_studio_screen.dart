import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/widgets.dart';

enum StudioTool {
  lessonPlan('Lesson plan', 'lesson_plan'),
  questions('Exam questions', 'exam'),
  homework('Homework ideas', 'homework_ideas');

  const StudioTool(this.label, this.responseKey);
  final String label;
  final String responseKey;

  String get path => switch (this) {
        lessonPlan => Api.aiLessonPlan,
        questions => Api.aiExamQuestions,
        homework => Api.aiHomeworkIdeas,
      };
}

/// AI Studio on the phone: drafts a lesson plan, exam questions or homework
/// ideas for a teacher to read, edit and decide whether to use. Nothing here
/// is sent to students; the school can switch each tool off.
class AiStudioScreen extends ConsumerStatefulWidget {
  const AiStudioScreen({super.key});

  @override
  ConsumerState<AiStudioScreen> createState() => _AiStudioScreenState();
}

class _AiStudioScreenState extends ConsumerState<AiStudioScreen> {
  StudioTool _tool = StudioTool.lessonPlan;
  final _subject = TextEditingController();
  final _topic = TextEditingController();
  final _classLevel = TextEditingController();
  int _count = 5;
  String _difficulty = 'medium';
  bool _busy = false;
  String? _error;
  Map<String, dynamic>? _draft;

  @override
  void dispose() {
    _subject.dispose();
    _topic.dispose();
    _classLevel.dispose();
    super.dispose();
  }

  Future<void> _generate() async {
    if (_subject.text.trim().isEmpty || _topic.text.trim().isEmpty || (_tool == StudioTool.lessonPlan && _classLevel.text.trim().isEmpty)) {
      setState(() => _error = _tool == StudioTool.lessonPlan ? 'Fill in subject, topic and class.' : 'Fill in subject and topic.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _draft = null;
    });
    try {
      final res = await ref.read(apiClientProvider).post<dynamic>(_tool.path, body: {
        'subject': _subject.text.trim(),
        'topic': _topic.text.trim(),
        if (_classLevel.text.trim().isNotEmpty) 'class_level': _classLevel.text.trim(),
        if (_tool == StudioTool.questions) ...{'count': _count, 'difficulty': _difficulty},
      }, timeout: const Duration(seconds: 90));
      if (mounted) setState(() => _draft = mapOf(res, [_tool.responseKey]));
    } catch (error) {
      if (mounted) setState(() => _error = asApiException(error).message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final draft = _draft;

    return Scaffold(
      appBar: AppBar(title: const Text('AI Studio')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          SegmentedButton<StudioTool>(
            segments: [for (final t in StudioTool.values) ButtonSegment(value: t, label: Text(t.label))],
            selected: {_tool},
            showSelectedIcon: false,
            onSelectionChanged: (s) => setState(() {
              _tool = s.first;
              _draft = null;
              _error = null;
            }),
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(controller: _subject, decoration: const InputDecoration(labelText: 'Subject', hintText: 'e.g. Mathematics')),
          const SizedBox(height: AppSpacing.sm),
          TextField(controller: _topic, decoration: const InputDecoration(labelText: 'Topic', hintText: 'e.g. Simple interest')),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            controller: _classLevel,
            decoration: InputDecoration(
              labelText: _tool == StudioTool.lessonPlan ? 'Class' : 'Class (optional)',
              hintText: 'e.g. JSS 2',
            ),
          ),
          if (_tool == StudioTool.questions) ...[
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<int>(
                    initialValue: _count,
                    decoration: const InputDecoration(labelText: 'Questions'),
                    items: [for (final n in [5, 10, 15, 20]) DropdownMenuItem(value: n, child: Text('$n'))],
                    onChanged: (v) => setState(() => _count = v ?? 5),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: _difficulty,
                    decoration: const InputDecoration(labelText: 'Difficulty'),
                    items: const [
                      DropdownMenuItem(value: 'easy', child: Text('Easy')),
                      DropdownMenuItem(value: 'medium', child: Text('Medium')),
                      DropdownMenuItem(value: 'hard', child: Text('Hard')),
                    ],
                    onChanged: (v) => setState(() => _difficulty = v ?? 'medium'),
                  ),
                ),
              ],
            ),
          ],
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            onPressed: _busy ? null : _generate,
            icon: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.auto_awesome_outlined),
            label: Text(_busy ? 'Drafting… this can take up to a minute' : 'Draft ${_tool.label.toLowerCase()}'),
          ),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
          ],
          if (draft != null) ...[
            const SizedBox(height: AppSpacing.lg),
            DraftView(tool: _tool, draft: draft),
          ],
        ],
      ),
    );
  }
}

/// A draft, laid out for reading, with a copy button so the teacher can take
/// it into their own notes and edit it.
class DraftView extends StatelessWidget {
  const DraftView({super.key, required this.tool, required this.draft});

  final StudioTool tool;
  final Map<String, dynamic> draft;

  @override
  Widget build(BuildContext context) {
    final lines = textOf(tool, draft);

    return OutlinedCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text('Draft for you to edit', style: AppText.labelLg)),
              IconButton(
                tooltip: 'Copy',
                icon: const Icon(Icons.copy_outlined),
                onPressed: () {
                  Clipboard.setData(ClipboardData(text: lines.join('\n')));
                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Copied.')));
                },
              ),
            ],
          ),
          if (draft['stub'] == true)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: Text(
                'Placeholder: the AI service is not connected, so this is not a real draft.',
                style: AppText.bodyMd.copyWith(color: AppColors.error),
              ),
            ),
          for (final line in lines)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text(
                line,
                style: line.endsWith(':') || RegExp(r'^Q\d+\. ').hasMatch(line) ? AppText.bodyLg : AppText.bodyMd,
              ),
            ),
        ],
      ),
    );
  }

  /// The draft as plain lines: what is shown, and what Copy puts on the
  /// clipboard. Tolerant of missing keys — a model can leave one out.
  static List<String> textOf(StudioTool tool, Map<String, dynamic> d) {
    List<String> strings(Object? v) => v is List ? v.map((e) => stringOf(e)).where((s) => s.isNotEmpty).toList() : const [];

    switch (tool) {
      case StudioTool.lessonPlan:
        return [
          '${stringOf(d['subject'])}: ${stringOf(d['topic'])} (${stringOf(d['class_level'])}), ${intOf(d['duration_minutes'], 40)} minutes',
          'Objectives:',
          for (final o in strings(d['objectives'])) '• $o',
          if (strings(d['materials']).isNotEmpty) ...['Materials:', '• ${strings(d['materials']).join(', ')}'],
          if (stringOf(d['introduction']).isNotEmpty) ...['Introduction:', stringOf(d['introduction'])],
          'Activities:',
          for (final a in listOf(d['activities'])) '• ${stringOf(a['title'])} (${intOf(a['minutes'])} min): ${stringOf(a['description'])}',
          if (stringOf(d['assessment']).isNotEmpty) ...['Assessment:', stringOf(d['assessment'])],
          if (stringOf(d['homework']).isNotEmpty) ...['Homework:', stringOf(d['homework'])],
          if (stringOf(d['differentiation']).isNotEmpty) ...['Differentiation:', stringOf(d['differentiation'])],
        ];
      case StudioTool.questions:
        final questions = listOf(d['questions']);
        return [
          for (var i = 0; i < questions.length; i++) ...[
            'Q${i + 1}. ${stringOf(questions[i]['question'])}',
            for (final e in mapOf(questions[i]['options']).entries) '   ${e.key}. ${stringOf(e.value)}',
            '   Answer: ${stringOf(questions[i]['correct_answer'])}'
                '${stringOf(questions[i]['explanation']).isNotEmpty ? ' — ${stringOf(questions[i]['explanation'])}' : ''}',
          ],
        ];
      case StudioTool.homework:
        return [
          for (final idea in listOf(d['ideas'])) ...[
            '${stringOf(idea['title'])}:',
            stringOf(idea['instructions']),
            '${stringOf(idea['type'])} · ${intOf(idea['minutes'])} min · ${intOf(idea['marks'])} marks',
          ],
        ];
    }
  }
}
