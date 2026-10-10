import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';
import 'staff_attendance_screen.dart';

final clockInLocationProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  return mapOf(await api.get<dynamic>(Api.clockInLocation));
});

/// Where the school is, for staff GPS clock-in (doc §3 — a phone, not a
/// reader).
///
/// The admin stands at the gate and taps "Use my current location", or pastes
/// coordinates from Google Maps. "Test from here" lets them walk to the staff
/// room and check the radius reaches it before anyone is turned away.
class ClockInLocationScreen extends ConsumerWidget {
  const ClockInLocationScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final data = ref.watch(clockInLocationProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Clock-in location')),
      body: data.when(
        loading: () => const LoadingState(rows: 4),
        error: (error, _) => ErrorState(
          error: asApiException(error),
          onRetry: () => ref.invalidate(clockInLocationProvider),
        ),
        data: (d) => _Form(initial: d),
      ),
    );
  }
}

class _Form extends ConsumerStatefulWidget {
  const _Form({required this.initial});

  final Map<String, dynamic> initial;

  @override
  ConsumerState<_Form> createState() => _FormState();
}

class _FormState extends ConsumerState<_Form> {
  late final _lat = TextEditingController(text: _coord(widget.initial['latitude']));
  late final _lng = TextEditingController(text: _coord(widget.initial['longitude']));
  late double _radius = intOf(widget.initial['radius_metres'], 250).toDouble().clamp(50, 1000);
  late TimeOfDay _resume = _parseTime(stringOf(widget.initial['staff_resume_time'], '07:30'));

  bool _locating = false;
  bool _saving = false;
  bool _testing = false;
  String? _message;
  bool _messageOk = false;
  double? _accuracy;

  static String _coord(Object? v) => v == null ? '' : numOf(v).toStringAsFixed(6);

  static TimeOfDay _parseTime(String hm) {
    final parts = hm.split(':');
    return TimeOfDay(hour: int.tryParse(parts[0]) ?? 7, minute: int.tryParse(parts.length > 1 ? parts[1] : '') ?? 30);
  }

  String get _resumeText =>
      '${_resume.hour.toString().padLeft(2, '0')}:${_resume.minute.toString().padLeft(2, '0')}';

  @override
  void dispose() {
    _lat.dispose();
    _lng.dispose();
    super.dispose();
  }

  void _say(String message, {bool ok = false}) => setState(() {
        _message = message;
        _messageOk = ok;
      });

  /// A fresh, high-accuracy fix, or null with the reason already shown.
  Future<Position?> _position() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      _say("Your phone's location is switched off. Turn it on in Settings and try again.");
      return null;
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
      _say('Location permission is off for SchoolPilot. You can type the coordinates instead.');
      return null;
    }
    try {
      return await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.best, timeLimit: Duration(seconds: 25)),
      );
    } on TimeoutException {
      _say('Could not get a GPS fix. Step outside, away from the building, and try again.');
      return null;
    }
  }

  Future<void> _useCurrent() async {
    setState(() {
      _locating = true;
      _message = null;
    });
    final pos = await _position();
    if (!mounted) return;
    setState(() => _locating = false);
    if (pos == null) return;

    setState(() {
      _lat.text = pos.latitude.toStringAsFixed(6);
      _lng.text = pos.longitude.toStringAsFixed(6);
      _accuracy = pos.accuracy;
    });
    _say(
      pos.accuracy > 50
          ? 'Got it, but only to within ${pos.accuracy.round()} m. Wait a moment outdoors and tap again for a better fix.'
          : 'Got it, accurate to about ${pos.accuracy.round()} m. Tap Save to keep it.',
      ok: pos.accuracy <= 50,
    );
  }

  /// Accepts "6.6018, 3.3515" pasted from Google Maps into either box.
  void _splitPasted(String value) {
    final parts = value.split(RegExp(r'[,\s]+')).where((p) => p.isNotEmpty).toList();
    if (parts.length == 2 && double.tryParse(parts[0]) != null && double.tryParse(parts[1]) != null) {
      _lat.text = parts[0];
      _lng.text = parts[1];
    }
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(context: context, initialTime: _resume, helpText: 'Staff resume by');
    if (picked != null) setState(() => _resume = picked);
  }

  Future<void> _save() async {
    final lat = double.tryParse(_lat.text.trim());
    final lng = double.tryParse(_lng.text.trim());
    if (lat == null || lng == null) {
      _say('Enter both latitude and longitude, or tap "Use my current location".');
      return;
    }
    setState(() {
      _saving = true;
      _message = null;
    });
    try {
      await ref.read(apiClientProvider).put<dynamic>(Api.clockInLocation, body: {
        'latitude': lat,
        'longitude': lng,
        'radius_metres': _radius.round(),
        'staff_resume_time': _resumeText,
      });
      ref.invalidate(clockInLocationProvider);
      ref.invalidate(staffAttendanceProvider);
      if (!mounted) return;
      setState(() => _saving = false);
      _say('Saved. Staff can now clock in within ${_radius.round()} m of this point.', ok: true);
    } catch (error) {
      if (!mounted) return;
      setState(() => _saving = false);
      _say(asApiException(error).message);
    }
  }

  Future<void> _testHere() async {
    setState(() {
      _testing = true;
      _message = null;
    });
    final pos = await _position();
    if (pos == null) {
      if (mounted) setState(() => _testing = false);
      return;
    }
    try {
      final r = mapOf(await ref.read(apiClientProvider).post<dynamic>(
        Api.clockInLocationTest,
        body: {'latitude': pos.latitude, 'longitude': pos.longitude},
      ));
      if (!mounted) return;
      setState(() => _testing = false);
      if (r['configured'] != true) {
        _say('Save a location first, then test.');
      } else {
        final distance = numOf(r['distance_metres']).round();
        _say(
          r['within'] == true
              ? 'A clock-in from here would be accepted — you are $distance m from the saved point.'
              : 'A clock-in from here would be refused — you are $distance m away and the radius is ${intOf(r['radius_metres'])} m.',
          ok: r['within'] == true,
        );
      }
    } catch (error) {
      if (!mounted) return;
      setState(() => _testing = false);
      _say(asApiException(error).message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final configured = widget.initial['configured'] == true;
    final busy = _locating || _saving || _testing;

    return ListView(
      padding: const EdgeInsets.fromLTRB(AppSpacing.screenMargin, AppSpacing.md, AppSpacing.screenMargin, AppSpacing.xl),
      children: [
        Text(
          configured
              ? 'Staff clock in with their phone. A clock-in counts only when the phone is within the radius of this point.'
              : 'Not set yet, so staff cannot clock in. Stand at the school gate or the middle of the compound and tap the button below.',
          style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
        ),
        const SizedBox(height: AppSpacing.md),
        FilledButton.icon(
          onPressed: busy ? null : _useCurrent,
          icon: _locating
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : const Icon(Icons.my_location),
          label: const Text('Use my current location'),
        ),
        const SizedBox(height: AppSpacing.md),
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: _lat,
                keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true),
                decoration: const InputDecoration(labelText: 'Latitude', hintText: '6.601800'),
                onChanged: _splitPasted,
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: TextField(
                controller: _lng,
                keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true),
                decoration: const InputDecoration(labelText: 'Longitude', hintText: '3.351500'),
                onChanged: _splitPasted,
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.xs),
        Text(
          _accuracy != null
              ? 'Last GPS fix accurate to about ${_accuracy!.round()} m.'
              : 'Or paste from Google Maps: long-press the school, copy the numbers.',
          style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
        ),
        const SizedBox(height: AppSpacing.lg),
        Text('Radius: ${_radius.round()} m', style: AppText.labelLg),
        Slider(
          value: _radius,
          min: 50,
          max: 1000,
          divisions: 38,
          label: '${_radius.round()} m',
          onChanged: busy ? null : (v) => setState(() => _radius = v),
        ),
        Text(
          'Big enough to cover the whole compound and allow for GPS drift indoors — '
          '150 to 300 m suits most schools.',
          style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
        ),
        const SizedBox(height: AppSpacing.lg),
        OutlinedCard(
          onTap: busy ? null : _pickTime,
          child: Row(
            children: [
              const Icon(Icons.schedule, color: AppColors.primaryContainer),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Staff resume by', style: AppText.labelLg),
                    Text('A clock-in after this time is marked late.',
                        style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant)),
                  ],
                ),
              ),
              Text(_resumeText, style: AppText.headlineSm),
            ],
          ),
        ),
        if (_message != null) ...[
          const SizedBox(height: AppSpacing.md),
          Text(_message!, style: AppText.bodyMd.copyWith(color: _messageOk ? AppColors.present : AppColors.absent)),
        ],
        const SizedBox(height: AppSpacing.lg),
        FilledButton(
          onPressed: busy ? null : _save,
          child: Text(_saving ? 'Saving…' : 'Save'),
        ),
        const SizedBox(height: AppSpacing.sm),
        OutlinedButton.icon(
          onPressed: busy || !configured ? null : _testHere,
          icon: const Icon(Icons.near_me_outlined),
          label: Text(_testing ? 'Checking…' : 'Test from here'),
        ),
        if (!configured)
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.xs),
            child: Text('Save first, then walk to the staff room and test.',
                style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant)),
          ),
      ],
    );
  }
}
