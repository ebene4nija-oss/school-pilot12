import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/widgets.dart';
import '../timetable/timetable_screen.dart';

/// The student's learning record: virtual classes coming up, recent
/// materials, homework state and virtual-class attendance (TASK 14 §8.1).
final myLearningProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'me:learning',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.myLearning),
  );
});

/// Today, as the student portal sees it: periods with their room and any
/// cover teacher (TASK 02).
final myDayProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  return cachedGet<dynamic>(
    cache: ref.watch(cacheStoreProvider),
    key: 'me:dashboard',
    fetch: () => ref.watch(apiClientProvider).get<dynamic>(Api.myDashboard),
  );
});

/// Opens a learning material in the phone's own viewer through a short-lived
/// signed link, so the app's token never leaves secure storage.
Future<void> openMaterial(BuildContext context, WidgetRef ref, int id) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    final res = await ref.read(apiClientProvider).get<dynamic>(Api.learningMaterialLink(id));
    final url = Uri.tryParse(stringOf(mapOf(res)['url']));
    if (url == null || !await launchUrl(url, mode: LaunchMode.externalApplication)) {
      messenger.showSnackBar(const SnackBar(content: Text('This phone has nothing that can open that file.')));
    }
  } catch (error) {
    messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
  }
}

/// Today's lessons on the student's home screen.
class TodayLessonsCard extends ConsumerWidget {
  const TodayLessonsCard({super.key, this.showWhenEmpty = false});

  /// On the student home the section stays, saying there is nothing today,
  /// rather than vanishing and leaving the student to wonder whether it broke.
  final bool showWhenEmpty;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(myDayProvider);
    final day = state.valueOrNull?.data;
    final today = mapOf(mapOf(day)['today']);
    final periods = listOf(today['periods']);
    if (periods.isEmpty) {
      if (!showWhenEmpty || state.isLoading) return const SizedBox.shrink();
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const SectionHeader("Today's lessons"),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin),
            child: Text(
              state.hasError
                  ? "Could not load today's timetable. Pull down to try again."
                  : 'No lessons on your timetable today.',
              style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
            ),
          ),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SectionHeader("${stringOf(today['day'], 'Today')}'s lessons"),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screenMargin),
          child: Column(children: [for (final p in periods) PeriodRow(entry: p)]),
        ),
      ],
    );
  }
}

/// Virtual classes coming up, with Join while a class is open.
class VirtualClassesSection extends StatelessWidget {
  const VirtualClassesSection({super.key, required this.classes});

  final List<Map<String, dynamic>> classes;

  @override
  Widget build(BuildContext context) {
    if (classes.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SectionHeader('Virtual classes'),
        for (final c in classes)
          Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, 0, AppSpacing.screenMargin, AppSpacing.sm),
            child: _VirtualClassCard(liveClass: c),
          ),
      ],
    );
  }
}

class _VirtualClassCard extends ConsumerStatefulWidget {
  const _VirtualClassCard({required this.liveClass});

  final Map<String, dynamic> liveClass;

  @override
  ConsumerState<_VirtualClassCard> createState() => _VirtualClassCardState();
}

/// Joining hands out the meeting link, opens the video app, and keeps a
/// heartbeat going while SchoolPilot is in front. Where the school has
/// connected Zoom or Meet, attendance is read from the call itself.
class _VirtualClassCardState extends ConsumerState<_VirtualClassCard> with WidgetsBindingObserver {
  Timer? _beat;
  bool _busy = false;
  bool _joined = false;

  int get _id => intOf(widget.liveClass['id']);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    _beat?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (_joined && state == AppLifecycleState.resumed) unawaited(_heartbeat());
  }

  Future<void> _heartbeat() async {
    try {
      await ref.read(apiClientProvider).post<dynamic>(Api.liveClassHeartbeat(_id));
    } catch (_) {
      // A missed beat counts as a short gap, not a failure to show anyone.
    }
  }

  Future<void> _join() async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _busy = true);
    try {
      final res = mapOf(await ref.read(apiClientProvider).post<dynamic>(Api.liveClassJoin(_id)));
      final notice = stringOf(res['recording_notice']);
      final url = Uri.tryParse(stringOf(res['meeting_url']));
      final every = intOf(res['heartbeat_seconds'], 60);

      _joined = true;
      _beat?.cancel();
      _beat = Timer.periodic(Duration(seconds: every), (_) => _heartbeat());

      if (notice.isNotEmpty) {
        messenger.showSnackBar(SnackBar(content: Text('Recording: $notice'), duration: const Duration(seconds: 6)));
      }
      if (url == null || !await launchUrl(url, mode: LaunchMode.externalApplication)) {
        final code = [stringOf(res['meeting_id']), stringOf(res['passcode'])].where((s) => s.isNotEmpty).join(' · passcode ');
        messenger.showSnackBar(SnackBar(content: Text(code.isEmpty ? 'Could not open the class link.' : 'Join with meeting ID $code')));
      }
      if (mounted) setState(() {});
    } catch (error) {
      messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.liveClass;
    final joinable = c['is_joinable'] == true;
    final when = Dates.tryParse(c['scheduled_for'])?.toLocal();

    return OutlinedCard(
      statusColor: joinable ? AppColors.present : null,
      child: Row(
        children: [
          const Icon(Icons.videocam_outlined, color: AppColors.secondary),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(stringOf(c['title'], 'Virtual class'), style: AppText.bodyLg),
                Text(
                  [stringOf(c['subject']), Dates.dayAndTime(when)].where((s) => s.isNotEmpty).join(' · '),
                  style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
                ),
                if (_joined)
                  Text(
                    'Stay in the call under your own name for the whole lesson.',
                    style: AppText.labelSm.copyWith(color: AppColors.present),
                  ),
              ],
            ),
          ),
          if (joinable)
            FilledButton(
              onPressed: _busy ? null : _join,
              child: Text(_joined ? 'Rejoin' : 'Join'),
            )
          else
            const StatusChip('Opens 5 min before'),
        ],
      ),
    );
  }
}

/// Recent materials shared with the student; tap to open.
class MaterialsSection extends ConsumerWidget {
  const MaterialsSection({super.key, required this.materials, this.title = 'New materials'});

  final List<Map<String, dynamic>> materials;
  final String title;

  static const _icons = {
    'pdf': Icons.picture_as_pdf_outlined,
    'document': Icons.description_outlined,
    'presentation': Icons.slideshow_outlined,
    'image': Icons.image_outlined,
    'video': Icons.ondemand_video_outlined,
    'audio': Icons.headphones_outlined,
    'link': Icons.link,
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (materials.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SectionHeader(title),
        for (final m in materials)
          ListTile(
            leading: Icon(_icons[stringOf(m['type'])] ?? Icons.insert_drive_file_outlined, color: AppColors.primaryContainer),
            title: Text(stringOf(m['title'])),
            subtitle: Text(
              [stringOf(m['subject']), stringOf(m['topic'])].where((s) => s.isNotEmpty).join(' · '),
            ),
            trailing: const Icon(Icons.open_in_new, size: 18, color: AppColors.outline),
            onTap: () => openMaterial(context, ref, intOf(m['id'])),
          ),
      ],
    );
  }
}
