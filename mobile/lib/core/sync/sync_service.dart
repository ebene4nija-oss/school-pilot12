import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../db/outbox.dart';

class SyncState {
  const SyncState({
    this.pending = 0,
    this.failed = 0,
    this.online = true,
    this.syncing = false,
  });

  final int pending;
  final int failed;
  final bool online;
  final bool syncing;

  bool get hasQueue => pending > 0 || failed > 0;

  SyncState copyWith({int? pending, int? failed, bool? online, bool? syncing}) =>
      SyncState(
        pending: pending ?? this.pending,
        failed: failed ?? this.failed,
        online: online ?? this.online,
        syncing: syncing ?? this.syncing,
      );
}

/// Drains the outbox when there is a connection.
///
/// Triggered by three things and nothing else: a connectivity change, app
/// resume, and an explicit user tap. No polling loop — data costs money on the
/// networks this app runs on.
class SyncService extends ValueNotifier<SyncState> {
  SyncService({required ApiClient api, required Outbox outbox})
      : _api = api,
        _outbox = outbox,
        super(const SyncState());

  final ApiClient _api;
  final Outbox _outbox;

  StreamSubscription<List<ConnectivityResult>>? _connectivity;
  bool _draining = false;

  Future<void> start() async {
    final initial = await Connectivity().checkConnectivity();
    value = value.copyWith(online: _isOnline(initial));

    _connectivity = Connectivity().onConnectivityChanged.listen((results) {
      final online = _isOnline(results);
      value = value.copyWith(online: online);
      if (online) unawaited(drain());
    });

    await refreshCounts();
    if (value.online) unawaited(drain());
  }

  bool _isOnline(List<ConnectivityResult> results) =>
      results.any((r) => r != ConnectivityResult.none);

  Future<void> refreshCounts() async {
    value = value.copyWith(
      pending: await _outbox.pendingCount(),
      failed: await _outbox.failedCount(),
    );
  }

  /// Sends queued writes oldest-first, stopping at the first sign the network
  /// has gone again so the rest keep their place in the queue.
  Future<void> drain() async {
    if (_draining || !value.online) return;
    _draining = true;
    value = value.copyWith(syncing: true);

    try {
      for (final item in await _outbox.due()) {
        try {
          await _api.replay(
            method: item.method,
            path: item.path,
            body: item.body,
          );
          await _outbox.remove(item.id);
        } catch (error) {
          final failure = asApiException(error);

          if (failure.isOffline) {
            value = value.copyWith(online: false);
            break;
          }

          if (failure.isRetryable) {
            await _outbox.backOff(item, failure.message);
          } else {
            // 4xx that will never succeed. Kept and surfaced, never discarded.
            await _outbox.markFailed(item, failure.message);
          }
        }
      }
    } finally {
      _draining = false;
      value = value.copyWith(syncing: false);
      await refreshCounts();
    }
  }

  Future<void> retryFailed() async {
    await _outbox.retryFailed();
    await refreshCounts();
    await drain();
  }

  @override
  void dispose() {
    _connectivity?.cancel();
    super.dispose();
  }
}
