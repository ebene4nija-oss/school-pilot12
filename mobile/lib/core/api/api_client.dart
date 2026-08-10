import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../auth/session.dart';
import 'api_exception.dart';

/// The one HTTP client. No feature constructs a `Dio`.
///
/// Interceptor order matters and is fixed here: tenancy, then auth, then error
/// mapping. Tenancy comes first because a request that cannot identify a school
/// is not worth authenticating.
class ApiClient {
  ApiClient({required SessionHolder session, Dio? dio, String? baseUrl})
      : _session = session,
        _dio = dio ?? Dio() {
    _dio.options
      ..baseUrl = baseUrl ?? defaultBaseUrl
      ..connectTimeout = const Duration(seconds: 15)
      ..receiveTimeout = const Duration(seconds: 30)
      ..sendTimeout = const Duration(seconds: 30)
      ..headers = {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      }
      // Non-2xx is handled by the error interceptor, not by throwing raw.
      ..validateStatus = (status) => status != null && status < 400;

    _dio.interceptors.add(_TenantInterceptor(_session));
    _dio.interceptors.add(_AuthInterceptor(_session));
    _dio.interceptors.add(_ErrorInterceptor(_session));

    if (kDebugMode) {
      _dio.interceptors.add(
        LogInterceptor(requestBody: false, responseBody: false),
      );
    }
  }

  /// Overridden per build: `--dart-define=API_BASE_URL=https://api.example.ng`.
  ///
  /// The default is the Android emulator's loopback to the host machine, which
  /// is what `php artisan serve` on a developer laptop looks like from a device.
  static const defaultBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000',
  );

  final Dio _dio;
  final SessionHolder _session;

  Dio get raw => _dio;

  Future<T> get<T>(
    String path, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    final res = await _dio.get<T>(
      path,
      queryParameters: query,
      cancelToken: cancelToken,
    );
    return res.data as T;
  }

  Future<T> post<T>(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    final res = await _dio.post<T>(
      path,
      data: body,
      queryParameters: query,
      cancelToken: cancelToken,
    );
    return res.data as T;
  }

  Future<T> put<T>(String path, {Object? body}) async {
    final res = await _dio.put<T>(path, data: body);
    return res.data as T;
  }

  Future<T> delete<T>(String path, {Object? body}) async {
    final res = await _dio.delete<T>(path, data: body);
    return res.data as T;
  }

  /// Replays an outbox row. The path and body were serialised when the user
  /// acted, possibly days ago — nothing about them is recomputed here.
  Future<Response<dynamic>> replay({
    required String method,
    required String path,
    required Object? body,
  }) {
    return _dio.request<dynamic>(
      path,
      data: body,
      options: Options(method: method),
    );
  }
}

class _TenantInterceptor extends Interceptor {
  _TenantInterceptor(this.session);

  final SessionHolder session;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    // A handset has no subdomain to be resolved from, so the tenant travels as
    // a header. `TenantResolutionMiddleware` only consults it when the host did
    // not already identify a school, so this can never override a real
    // subdomain in a hosted deployment.
    final code = session.schoolCode;
    if (code != null && code.isNotEmpty) {
      options.headers['X-School-Subdomain'] = code;
    }
    handler.next(options);
  }
}

class _AuthInterceptor extends Interceptor {
  _AuthInterceptor(this.session);

  final SessionHolder session;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final token = session.token;
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }
}

class _ErrorInterceptor extends Interceptor {
  _ErrorInterceptor(this.session);

  final SessionHolder session;

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final status = err.response?.statusCode;

    if (status == 401) {
      // One place ends the session. Screens that are mid-flight will get the
      // exception too, but they do not each have to decide what it means.
      session.onUnauthorized?.call();
    }

    handler.reject(
      DioException(
        requestOptions: err.requestOptions,
        response: err.response,
        type: err.type,
        error: _map(err),
      ),
    );
  }

  ApiException _map(DioException err) {
    final offline = err.type == DioExceptionType.connectionError ||
        err.type == DioExceptionType.connectionTimeout ||
        err.error is SocketException;

    if (offline) {
      return ApiException(
        message: 'No connection. Showing what we already have.',
        isOffline: true,
      );
    }

    if (err.type == DioExceptionType.receiveTimeout ||
        err.type == DioExceptionType.sendTimeout) {
      return ApiException(
        message: 'The school server took too long to respond.',
        statusCode: 408,
      );
    }

    final status = err.response?.statusCode;
    final data = err.response?.data;
    final body = data is Map<String, dynamic> ? data : const <String, dynamic>{};

    return ApiException(
      statusCode: status,
      message: _message(status, body),
      fieldErrors: _fieldErrors(body),
    );
  }

  String _message(int? status, Map<String, dynamic> body) {
    // Laravel is consistent about `message`; the controllers in this backend
    // also use `error` in places, so both are read.
    final fromBody = (body['message'] ?? body['error']) as String?;
    if (fromBody != null && fromBody.isNotEmpty) return fromBody;

    return switch (status) {
      401 => 'Your session has ended. Please sign in again.',
      403 => "You don't have permission to do that.",
      404 => 'Not found.',
      422 => 'Please check the highlighted fields.',
      429 => 'Too many attempts. Try again in a minute.',
      _ when status != null && status >= 500 =>
        'The school server had a problem. Try again shortly.',
      _ => 'Something went wrong.',
    };
  }

  Map<String, String> _fieldErrors(Map<String, dynamic> body) {
    final errors = body['errors'];
    if (errors is! Map) return const {};

    return {
      for (final entry in errors.entries)
        entry.key.toString(): entry.value is List && (entry.value as List).isNotEmpty
            ? (entry.value as List).first.toString()
            : entry.value.toString(),
    };
  }
}

/// Unwraps whatever Dio threw into the [ApiException] the app speaks.
ApiException asApiException(Object error) {
  if (error is ApiException) return error;
  if (error is DioException) {
    final mapped = error.error;
    if (mapped is ApiException) return mapped;
    return ApiException(
      message: error.message ?? 'Something went wrong.',
      statusCode: error.response?.statusCode,
    );
  }
  return ApiException(message: error.toString());
}
