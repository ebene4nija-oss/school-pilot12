/// Every failure a screen has to handle, in one shape.
///
/// The rule from `docs/mobile-app.md` §A6: features never see a `DioException`.
/// They see this, and they can branch on [isOffline] / [isUnauthorized] /
/// [isForbidden] / [fieldErrors] without knowing the transport exists.
class ApiException implements Exception {
  ApiException({
    required this.message,
    this.statusCode,
    this.fieldErrors = const {},
    this.isOffline = false,
  });

  /// Safe to put in front of a user as-is. Backend `message` when there is one,
  /// otherwise something written for a parent on a bus, not for a developer.
  final String message;

  final int? statusCode;

  /// Laravel's 422 `errors` map, field name to the first message.
  final Map<String, String> fieldErrors;

  /// No usable connection. Distinct from a server error: the app offers a
  /// cached view and a retry rather than an apology.
  final bool isOffline;

  bool get isUnauthorized => statusCode == 401;
  bool get isForbidden => statusCode == 403;
  bool get isNotFound => statusCode == 404;
  bool get isValidation => statusCode == 422;
  bool get isRateLimited => statusCode == 429;
  bool get isServerError => statusCode != null && statusCode! >= 500;

  /// Whether replaying this exact request could ever succeed. The outbox uses
  /// it to decide between backing off and giving up — a 422 will be a 422
  /// forever, and retrying it silently would hide the problem from the user.
  bool get isRetryable =>
      isOffline || isServerError || isRateLimited || statusCode == 408;

  String? fieldError(String field) => fieldErrors[field];

  @override
  String toString() => 'ApiException($statusCode): $message';
}
