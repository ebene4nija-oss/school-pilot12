import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';

/// Which channels can deliver right now, and why not where they cannot.
final notificationChannelsProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.notificationChannels));
});

/// The audiences `/notifications/broadcast` understands.
const audiences = <String, String>{
  'parents': 'Parents',
  'students': 'Students',
  'teachers': 'Teachers',
  'staff': 'All staff',
};

/// Audiences that can be narrowed to one class.
const classScoped = {'parents', 'students'};

const _channelLabels = <String, (String, String)>{
  'push': ('In-app notification', 'Free'),
  'sms': ('SMS', 'Charged to the school per message'),
  'whatsapp': ('WhatsApp', 'Charged per message'),
  'email': ('Email', 'Free, within the daily limit'),
};

/// An administrator's announcement to a group: parents (all, or one class),
/// students, teachers or all staff.
class SendNotificationScreen extends ConsumerStatefulWidget {
  const SendNotificationScreen({super.key});

  @override
  ConsumerState<SendNotificationScreen> createState() => _SendNotificationScreenState();
}

class _SendNotificationScreenState extends ConsumerState<SendNotificationScreen> {
  final _title = TextEditingController();
  final _body = TextEditingController();
  String _audience = 'parents';
  int? _classId;
  final _channels = <String>{'push'};

  Map<String, dynamic>? _preview;
  bool _previewing = false;
  Timer? _debounce;
  bool _sending = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadPreview();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _title.dispose();
    _body.dispose();
    super.dispose();
  }

  Map<String, dynamic> get _audiencePayload => {
        'type': _audience,
        if (_classId != null && classScoped.contains(_audience)) 'class_id': _classId,
      };

  void _audienceChanged() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 250), _loadPreview);
  }

  Future<void> _loadPreview() async {
    setState(() {
      _previewing = true;
      _preview = null;
    });
    try {
      final res = await ref.read(apiClientProvider).post<dynamic>(
        Api.notificationAudiencePreview,
        body: {'audience': _audiencePayload},
      );
      if (mounted) setState(() => _preview = mapOf(res));
    } catch (_) {
      // The preview is a courtesy; sending still works without it.
    } finally {
      if (mounted) setState(() => _previewing = false);
    }
  }

  String get _who {
    final group = audiences[_audience]!.toLowerCase();
    if (_classId == null || !classScoped.contains(_audience)) return 'all $group';
    final name = (ref.read(classesProvider).valueOrNull ?? const <ClassRef>[])
        .where((c) => c.id == _classId)
        .map((c) => c.name)
        .firstOrNull;
    return '$group in ${name ?? 'the class'}';
  }

  Future<void> _send() async {
    if (_body.text.trim().isEmpty) {
      setState(() => _error = 'Write the message first.');
      return;
    }
    final count = intOf(_preview?['recipients']);

    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Send this notification?'),
        content: Text(
          count > 0
              ? 'It goes to $count ${count == 1 ? 'person' : 'people'} ($_who).'
              : 'It goes to $_who.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Send'),
          ),
        ],
      ),
    );
    if (ok != true) return;

    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.notificationBroadcast,
        body: {
          'audience': _audiencePayload,
          if (_title.text.trim().isNotEmpty) 'title': _title.text.trim(),
          'body': _body.text.trim(),
          'channels': _channels.toList(),
        },
      ));
      if (!mounted) return;
      final sent = intOf(res['recipients']);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('On its way to $sent ${sent == 1 ? 'person' : 'people'}.'),
      ));
      Navigator.pop(context);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _sending = false;
        _error = asApiException(error).message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final classes = ref.watch(classesProvider).valueOrNull ?? const <ClassRef>[];
    final channels = ref.watch(notificationChannelsProvider).valueOrNull;
    final preview = _preview;
    final tooMany = preview?['too_many'] == true;

    return Scaffold(
      appBar: AppBar(title: const Text('Send notification')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenMargin),
        children: [
          Text('Who', style: AppText.labelLg),
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.sm,
            children: [
              for (final entry in audiences.entries)
                ChoiceChip(
                  label: Text(entry.value),
                  selected: _audience == entry.key,
                  showCheckmark: false,
                  selectedColor: AppColors.primaryContainer,
                  labelStyle: AppText.labelLg.copyWith(
                    color: _audience == entry.key ? AppColors.onPrimary : AppColors.onSurface,
                  ),
                  onSelected: _sending
                      ? null
                      : (_) {
                          setState(() => _audience = entry.key);
                          _audienceChanged();
                        },
                ),
            ],
          ),
          if (classScoped.contains(_audience)) ...[
            const SizedBox(height: AppSpacing.md),
            DropdownButtonFormField<int?>(
              initialValue: _classId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Class'),
              items: [
                const DropdownMenuItem<int?>(value: null, child: Text('Whole school')),
                for (final c in classes) DropdownMenuItem<int?>(value: c.id, child: Text(c.name)),
              ],
              onChanged: _sending
                  ? null
                  : (v) {
                      setState(() => _classId = v);
                      _audienceChanged();
                    },
            ),
          ],
          const SizedBox(height: AppSpacing.sm),
          if (_previewing)
            const LinearProgressIndicator()
          else if (preview != null)
            Text(
              tooMany
                  ? 'That is ${intOf(preview['recipients'])} people — more than the '
                      '${intOf(preview['max_recipients'])} one message can reach. Choose a class.'
                  : 'Reaches ${intOf(preview['recipients'])} '
                      '${intOf(preview['recipients']) == 1 ? 'person' : 'people'} · '
                      '${intOf(preview['with_phone'])} with a phone number · '
                      '${intOf(preview['with_email'])} with an email',
              style: AppText.bodyMd.copyWith(
                color: tooMany ? AppColors.error : AppColors.onSurfaceVariant,
              ),
            ),
          const SizedBox(height: AppSpacing.lg),
          TextField(
            controller: _title,
            maxLength: 120,
            decoration: const InputDecoration(
              labelText: 'Title (optional)',
              hintText: 'e.g. Mid-term break',
            ),
          ),
          TextField(
            controller: _body,
            maxLength: 1000,
            minLines: 4,
            maxLines: 10,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              labelText: 'Message',
              hintText: 'School closes on Friday at 12 noon for mid-term break.',
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          Text('Send by', style: AppText.labelLg),
          for (final entry in _channelLabels.entries)
            Builder(builder: (context) {
              final state = mapOf(channels?[entry.key]);
              // Until readiness loads, only the free in-app channel is offered.
              final ready = channels == null ? entry.key == 'push' : state['ready'] == true;
              return CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: _channels.contains(entry.key) && ready,
                onChanged: !ready || _sending
                    ? null
                    : (on) => setState(() => on == true
                        ? _channels.add(entry.key)
                        : _channels.remove(entry.key)),
                title: Text(entry.value.$1),
                subtitle: Text(
                  ready ? entry.value.$2 : stringOf(state['reason'], 'Not set up for this school'),
                ),
              );
            }),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.sm),
              child: Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            ),
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            onPressed: _sending ||
                    tooMany ||
                    _body.text.trim().isEmpty ||
                    _channels.isEmpty ||
                    intOf(preview?['recipients'], 1) == 0
                ? null
                : _send,
            icon: _sending
                ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.campaign_outlined),
            label: const Text('Send'),
          ),
          const SizedBox(height: AppSpacing.xl),
        ],
      ),
    );
  }
}
