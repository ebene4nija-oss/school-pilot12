import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

final videoProvidersProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  return listOf(await ref.watch(apiClientProvider).get<dynamic>(Api.videoProviders));
});

/// Whether the school's Zoom and Google Meet accounts are connected, so
/// virtual-class attendance is read from the call itself (TASK 14 §3.3).
///
/// Zoom's three short values can be typed here. Google needs a service-account
/// key file pasted whole, which belongs on a computer, so the app says so.
/// Credentials are checked by the server before saving and never shown again.
class VideoProvidersScreen extends ConsumerWidget {
  const VideoProvidersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final conns = ref.watch(videoProvidersProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Zoom and Google Meet')),
      body: conns.when(
        loading: () => const LoadingState(rows: 2),
        error: (e, _) => ErrorState(error: asApiException(e), onRetry: () => ref.invalidate(videoProvidersProvider)),
        data: (rows) {
          Map<String, dynamic>? of(String p) => rows.where((c) => c['provider'] == p).firstOrNull;

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [
              Text(
                'Connect the school’s own account and SchoolPilot reads who was in each virtual class, and for how long, after it ends.',
                style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
              const SizedBox(height: AppSpacing.md),
              _ProviderCard(provider: 'zoom', label: 'Zoom', connection: of('zoom'), canConnectHere: true),
              const SizedBox(height: AppSpacing.sm),
              _ProviderCard(provider: 'google_meet', label: 'Google Meet', connection: of('google_meet'), canConnectHere: false),
            ],
          );
        },
      ),
    );
  }
}

class _ProviderCard extends ConsumerWidget {
  const _ProviderCard({required this.provider, required this.label, required this.connection, required this.canConnectHere});

  final String provider;
  final String label;
  final Map<String, dynamic>? connection;
  final bool canConnectHere;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = connection;
    final ok = c?['status'] == 'connected';

    return OutlinedCard(
      statusColor: c == null ? null : (ok ? AppColors.present : AppColors.absent),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(label, style: AppText.bodyLg)),
              c == null
                  ? const StatusChip('Not connected')
                  : (ok ? const StatusChip.present('Connected') : const StatusChip.absent('Failing')),
            ],
          ),
          if (stringOf(c?['last_error']).isNotEmpty)
            Text(stringOf(c?['last_error']), style: AppText.labelSm.copyWith(color: AppColors.error)),
          if (c?['last_synced_at'] != null)
            Text(
              'Last read ${Dates.dayAndTime(Dates.tryParse(c?['last_synced_at'])?.toLocal())}',
              style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
            ),
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.sm,
            children: [
              if (canConnectHere)
                OutlinedButton(
                  onPressed: () => _connectZoom(context, ref),
                  child: Text(c == null ? 'Connect' : 'Replace details'),
                )
              else if (c == null)
                Text('Connect from the web portal on a computer: it needs a key file pasted in.', style: AppText.bodyMd),
              if (c != null)
                TextButton(onPressed: () => _disconnect(context, ref), child: const Text('Disconnect')),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _disconnect(BuildContext context, WidgetRef ref) async {
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Disconnect $label?'),
        content: const Text('Attendance will be counted from the lesson page only. The saved credentials are deleted.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Disconnect')),
        ],
      ),
    );
    if (sure != true || !context.mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    try {
      await ref.read(apiClientProvider).delete<dynamic>(Api.videoProvider(provider));
      messenger.showSnackBar(const SnackBar(content: Text('Disconnected.')));
    } catch (error) {
      messenger.showSnackBar(SnackBar(content: Text(asApiException(error).message)));
    }
    ref.invalidate(videoProvidersProvider);
  }

  Future<void> _connectZoom(BuildContext context, WidgetRef ref) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _ZoomForm(),
    );
    if (saved == true) ref.invalidate(videoProvidersProvider);
  }
}

class _ZoomForm extends ConsumerStatefulWidget {
  const _ZoomForm();

  @override
  ConsumerState<_ZoomForm> createState() => _ZoomFormState();
}

class _ZoomFormState extends ConsumerState<_ZoomForm> {
  final _account = TextEditingController();
  final _client = TextEditingController();
  final _secret = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _account.dispose();
    _client.dispose();
    _secret.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiClientProvider).put<dynamic>(Api.videoProvider('zoom'), body: {
        'account_id': _account.text.trim(),
        'client_id': _client.text.trim(),
        'client_secret': _secret.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
    } catch (error) {
      setState(() => _error = asApiException(error).message);
    } finally {
      if (mounted) setState(() => _busy = false);
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
          const Text('Connect Zoom', style: AppText.headlineSm),
          Text(
            'From a Server-to-Server OAuth app in the Zoom App Marketplace, with the report:read:admin scope.',
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          TextField(controller: _account, decoration: const InputDecoration(labelText: 'Account ID')),
          TextField(controller: _client, decoration: const InputDecoration(labelText: 'Client ID')),
          TextField(
            controller: _secret,
            obscureText: true,
            enableSuggestions: false,
            autocorrect: false,
            decoration: const InputDecoration(labelText: 'Client secret'),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.sm),
              child: Text(_error!, style: AppText.bodyMd.copyWith(color: AppColors.error)),
            ),
          const SizedBox(height: AppSpacing.md),
          FilledButton(onPressed: _busy ? null : _save, child: Text(_busy ? 'Checking…' : 'Check and save')),
        ],
      ),
    );
  }
}
