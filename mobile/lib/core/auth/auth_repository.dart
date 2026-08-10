import '../api/api_client.dart';
import '../api/endpoints.dart';
import 'session.dart';

class LoginResult {
  const LoginResult({required this.token, required this.user});

  final String token;
  final AuthUser user;
}

/// Raised when the backend asks for a TOTP code instead of a token.
///
/// Not an error state in the UI — it is the second step of a normal
/// administrator sign-in, so it gets its own type rather than being sniffed out
/// of a 422 body at the call site.
class TwoFactorRequired implements Exception {
  const TwoFactorRequired(this.message);

  final String message;
}

class AuthRepository {
  AuthRepository(this._api);

  final ApiClient _api;

  Future<LoginResult> login({
    required String email,
    required String password,
    required String schoolCode,
    String? twoFactorCode,
  }) async {
    try {
      final body = <String, dynamic>{
        'email': email.trim(),
        'password': password,
        // Sent in the body as well as the header. The controller compares it
        // against the user's own school and audits a mismatch, so a user from
        // one school cannot authenticate against another's tenant.
        'subdomain': schoolCode.trim(),
        if (twoFactorCode != null && twoFactorCode.isNotEmpty)
          'two_factor_code': twoFactorCode,
      };

      final res = await _api.post<Map<String, dynamic>>(Api.login, body: body);

      return LoginResult(
        token: res['token'] as String,
        user: AuthUser.fromJson(res['user'] as Map<String, dynamic>),
      );
    } catch (error) {
      final failure = asApiException(error);

      if (failure.statusCode == 422 &&
          failure.message.toLowerCase().contains('two-factor')) {
        throw TwoFactorRequired(failure.message);
      }

      throw failure;
    }
  }

  Future<AuthUser> currentUser() async {
    final res = await _api.get<Map<String, dynamic>>(Api.currentUser);
    return AuthUser.fromJson(res);
  }

  /// Best-effort device de-registration so a shared handset stops receiving
  /// another family's notifications.
  ///
  /// Note that this does *not* revoke the Sanctum token — the backend has no
  /// logout route at all (gap G1 in `docs/mobile-app.md` §B12). Until it does,
  /// signing out only forgets the token locally.
  Future<void> unregisterDevice(String pushToken) async {
    try {
      await _api.delete<dynamic>(
        Api.notificationDevices,
        body: {'token': pushToken},
      );
    } catch (_) {
      // A failed unregister must never block sign-out.
    }
  }
}
