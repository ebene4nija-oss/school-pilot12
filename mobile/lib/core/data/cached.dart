import '../api/api_client.dart';
import '../db/cache_store.dart';

/// A payload plus how old it is and whether the network refused to refresh it.
class Cached<T> {
  const Cached({
    required this.data,
    required this.fetchedAt,
    this.stale = false,
  });

  final T data;
  final DateTime fetchedAt;

  /// True when this came off disk because the network was unreachable. The
  /// screen still renders — it just says so.
  final bool stale;
}

/// Read-through cache for a GET.
///
/// Network first, disk as the fallback. The inversion (disk first) is
/// deliberately not offered: showing a teacher yesterday's register when the
/// school wifi is working would be worse than a two-second wait.
Future<Cached<T>> cachedGet<T>({
  required CacheStore cache,
  required String key,
  required Future<T> Function() fetch,
}) async {
  try {
    final fresh = await fetch();
    await cache.write(key, fresh as Object);
    return Cached(data: fresh, fetchedAt: DateTime.now());
  } catch (error) {
    final failure = asApiException(error);

    // Only a connectivity failure falls back. A 403 or a 500 is an answer
    // about the request, and quietly substituting last week's data for it
    // would hide a real problem.
    if (!failure.isOffline) rethrow;

    final cachedValue = await cache.read(key);
    if (cachedValue == null) rethrow;

    return Cached(
      data: cachedValue.value as T,
      fetchedAt: cachedValue.fetchedAt,
      stale: true,
    );
  }
}

/// Coerces whatever a controller returned into the list of maps a screen wants.
///
/// The backend is not uniform: some endpoints return a bare array, some wrap in
/// `data`, some in a named key. Rather than a bespoke parser per screen, the
/// candidates are tried in order.
List<Map<String, dynamic>> listOf(Object? payload, [List<String> keys = const []]) {
  if (payload is List) {
    return payload.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
  }

  if (payload is Map) {
    for (final key in [...keys, 'data', 'items', 'results']) {
      final value = payload[key];
      if (value is List) {
        return value.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
      }
      // Laravel paginators nest the rows one level further down.
      if (value is Map && value['data'] is List) {
        return (value['data'] as List)
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
      }
    }
  }

  return const [];
}

Map<String, dynamic> mapOf(Object? payload, [List<String> keys = const []]) {
  if (payload is Map) {
    for (final key in keys) {
      final value = payload[key];
      if (value is Map) return value.cast<String, dynamic>();
    }
    return payload.cast<String, dynamic>();
  }
  return const {};
}

/// Reads a possibly-missing numeric field without throwing on a string.
num numOf(Object? value, [num fallback = 0]) {
  if (value is num) return value;
  if (value is String) return num.tryParse(value) ?? fallback;
  return fallback;
}

int intOf(Object? value, [int fallback = 0]) => numOf(value, fallback).toInt();

String stringOf(Object? value, [String fallback = '']) =>
    value?.toString() ?? fallback;
