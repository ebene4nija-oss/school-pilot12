import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../cbt/teacher/cbt_teacher_data.dart';

/// Schedule a Zoom or Google Meet class from the phone, as on the web.
///
/// The teacher creates the meeting in Zoom or Meet and pastes its link; the
/// app tells which it is from the link. Students in the class see it on
/// their Learn tab and join from there. The server checks the teacher
/// teaches the class.
class ScheduleVirtualClassScreen extends ConsumerStatefulWidget {
  const ScheduleVirtualClassScreen({super.key});

  @override
  ConsumerState<ScheduleVirtualClassScreen> createState() => _ScheduleVirtualClassScreenState();
}

class _ScheduleVirtualClassScreenState extends ConsumerState<ScheduleVirtualClassScreen> {
  final _title = TextEditingController();
  final _link = TextEditingController();
  final _passcode = TextEditingController();
  int? _classId;
  int? _subjectId;
  DateTime _when = _nextHour();
  int _minutes = 40;
  bool _weekly = false;
  bool _saving = false;
  String? _error;

  static DateTime _nextHour() {
    final n = DateTime.now().add(const Duration(hours: 1));
    return DateTime(n.year, n.month, n.day, n.hour);
  }

  @override
  void dispose() {
    for (final c in [_title, _link, _passcode]) {
      c.dispose();
    }
    super.dispose();
  }

  /// zoom | google_meet | other, from the link the teacher pasted.
  static String providerFor(String url) {
    final u = url.toLowerCase();
    if (u.contains('zoom.us') || u.contains('zoom.com')) return 'zoom';
    if (u.contains('meet.google.com')) return 'google_meet';
    return 'other';
  }

  Future<void> _pickWhen() async {
    final date = await showDatePicker(
      context: context,
      initialDate: _when,
      firstDate: DateTime.now().subtract(const Duration(days: 1)),
      lastDate: DateTime.now().add(const Duration(days: 180)),
    );
    if (date == null || !mounted) return;
    final time = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(_when));
    if (time == null) return;
    setState(() => _when = DateTime(date.year, date.month, date.day, time.hour, time.minute));
  }

  Future<void> _save() async {
    final link = _link.text.trim();
    if (_title.text.trim().isEmpty || _classId == null || Uri.tryParse(link)?.hasScheme != true) {
      setState(() => _error = 'A title, the class and the meeting link (starting https://) are needed.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(Api.liveClasses, body: {
        'title': _title.text.trim(),
        'class_id': _classId,
        if (_subjectId != null) 'subject_id': _subjectId,
        'provider': providerFor(link),
        'meeting_url': link,
        if (_passcode.text.trim().isNotEmpty) 'passcode': _passcode.text.trim(),
        // The server keeps UTC; send the moment the teacher picked.
        'scheduled_for': _when.toUtc().toIso8601String(),
        'duration_minutes': _minutes,
        if (_weekly) 'repeat_weekly_until': Dates.apiDate(_when.add(const Duration(days: 7 * 12))),
      }));
      if (mounted) Navigator.pop(context, stringOf(res['message'], 'Class scheduled.'));
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = asApiException(error).message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final classes = ref.watch(classesProvider).valueOrNull ?? const <ClassRef>[];
    final subjects = ref.watch(classSubjectsProvider(_classId)).valueOrNull ?? const <Map<String, dynamic>>[];
    final kind = providerFor(_link.text);

    return Scaffold(
      appBar: AppBar(title: const Text('Schedule a virtual class')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          TextField(controller: _title, decoration: const InputDecoration(labelText: 'Title', hintText: 'e.g. Revision: quadratic equations')),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<int>(
            initialValue: _classId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Class'),
            items: [for (final c in classes) DropdownMenuItem(value: c.id, child: Text(c.name))],
            onChanged: (v) => setState(() {
              _classId = v;
              _subjectId = null;
            }),
          ),
          const SizedBox(height: AppSpacing.md),
          DropdownButtonFormField<int>(
            key: ValueKey('subject-$_classId'),
            initialValue: _subjectId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Subject (optional)'),
            items: [
              for (final s in subjects)
                DropdownMenuItem(value: intOf(s['id']), child: Text(stringOf(s['name'], 'Subject'))),
            ],
            onChanged: (v) => setState(() => _subjectId = v),
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _link,
            keyboardType: TextInputType.url,
            decoration: InputDecoration(
              labelText: 'Zoom or Google Meet link',
              hintText: 'https://zoom.us/j/… or https://meet.google.com/…',
              helperText: _link.text.trim().isEmpty
                  ? 'Create the meeting in Zoom or Meet, then paste its link here.'
                  : kind == 'zoom'
                      ? 'Zoom meeting'
                      : kind == 'google_meet'
                          ? 'Google Meet'
                          : 'Other meeting link',
            ),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: AppSpacing.md),
          TextField(controller: _passcode, decoration: const InputDecoration(labelText: 'Passcode (optional)')),
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.event_outlined),
            title: const Text('Starts'),
            subtitle: Text(Dates.dayAndTime(_when)),
            onTap: _pickWhen,
          ),
          DropdownButtonFormField<int>(
            initialValue: _minutes,
            decoration: const InputDecoration(labelText: 'Length'),
            items: [
              for (final m in const [30, 40, 45, 60, 80, 90, 120]) DropdownMenuItem(value: m, child: Text('$m minutes')),
            ],
            onChanged: (v) => setState(() => _minutes = v ?? 40),
          ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Repeat weekly'),
            subtitle: const Text('Same day and time for the next 12 weeks'),
            value: _weekly,
            onChanged: (v) => setState(() => _weekly = v),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            ),
          FilledButton.icon(
            onPressed: _saving ? null : _save,
            icon: const Icon(Icons.videocam_outlined),
            label: Text(_saving ? 'Scheduling…' : 'Schedule and tell students'),
          ),
        ],
      ),
    );
  }
}

/// The link-to-provider rule, for tests.
@visibleForTesting
class ScheduleVirtualClassScreenStateProbe {
  static String providerFor(String url) => _ScheduleVirtualClassScreenState.providerFor(url);
}
