import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/reference_data.dart';
import '../../core/providers.dart';
import '../../core/ui/widgets.dart';

/// Staff clock-in by phone GPS, checked against the school's geofence
/// server-side.
///
/// Hardware-free by design (doc §3): no reader, no card, no fingerprint. If the
/// user refuses location, the card explains the fallback rather than blocking —
/// a teacher who will not share their location still has to be able to work.
class StaffClockInCard extends ConsumerStatefulWidget {
  const StaffClockInCard({super.key});

  @override
  ConsumerState<StaffClockInCard> createState() => _StaffClockInCardState();
}

class _StaffClockInCardState extends ConsumerState<StaffClockInCard> {
  bool _busy = false;
  String? _message;
  bool _success = false;

  Future<void> _clockIn() async {
    setState(() {
      _busy = true;
      _message = null;
    });

    try {
      // Location switched off in the phone's settings throws from
      // getCurrentPosition with a message written for developers.
      if (!await Geolocator.isLocationServiceEnabled()) {
        setState(() {
          _busy = false;
          _message = "Your phone's location is switched off. Turn it on in "
              'Settings, then tap Clock in again.';
        });
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        setState(() {
          _busy = false;
          _message =
              'Location is off, so we cannot confirm you are on the school '
              'premises. Ask your administrator to mark you present.';
        });
        return;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 20),
        ),
      );

      // Awaited rather than read: on a cold start nothing has loaded the terms
      // yet, the selected term is still null, and the server refuses a
      // clock-in without one.
      final terms = await ref.read(termsProvider.future);
      final termId = ref.read(selectedTermProvider) ??
          (terms.isEmpty
              ? null
              : terms.firstWhere((t) => t.isCurrent, orElse: () => terms.last).id);

      if (termId == null) {
        setState(() {
          _busy = false;
          _message = 'No school term is set up yet, so clock-in cannot be '
              'recorded. Ask your administrator to add the current term.';
        });
        return;
      }

      await ref.read(apiClientProvider).post<dynamic>(
        Api.attendanceStaffGps,
        body: {
          // No staff_id: the server resolves the caller's own staff record.
          // Sending the *user* id here was read as a staff id, and every
          // teacher whose two ids differ got "not found" or "only yourself".
          'term_id': termId,
          'latitude': position.latitude,
          'longitude': position.longitude,
        },
      );

      setState(() {
        _busy = false;
        _success = true;
        _message = 'Clocked in.';
      });
    } on TimeoutException {
      setState(() {
        _busy = false;
        _message = 'Could not get a GPS fix. Step outside or near a window '
            'and try again.';
      });
    } catch (error) {
      final failure = asApiException(error);
      setState(() {
        _busy = false;
        _message = failure.isOffline
            ? 'You need a connection to clock in — your location has to be '
                'checked against the school.'
            : failure.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return OutlinedCard(
      statusColor: _success ? AppColors.present : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.location_on_outlined,
                  color: AppColors.primaryContainer),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text('Staff clock-in', style: AppText.headlineSm),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'Confirms you are on the school premises using your phone location. '
            'Nothing is tracked afterwards.',
            style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
          ),
          if (_message != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(
              _message!,
              style: AppText.bodyMd.copyWith(
                color: _success ? AppColors.present : AppColors.absent,
              ),
            ),
          ],
          const SizedBox(height: AppSpacing.md),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _busy || _success ? null : _clockIn,
              child: Text(_success ? 'Clocked in' : 'Clock in'),
            ),
          ),
        ],
      ),
    );
  }
}
