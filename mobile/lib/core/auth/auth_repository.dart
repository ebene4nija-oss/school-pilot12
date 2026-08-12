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

  /// Revokes this device's token server-side.
  ///
  /// Best-effort on purpose: sign-out must complete even with no signal, or a
  /// user handing the phone to someone else cannot get out of their account.
  /// The local session is cleared either way — the cost of a failure here is a
  /// token that stays valid until it is used against a server that still has
  /// it, not a session that stays open on this handset.
  Future<void> logout() async {
    try {
      await _api.post<dynamic>(Api.logout);
    } catch (_) {
      // Already-expired tokens 401 here, which is the desired end state anyway.
    }
  }

  /// Asks the backend to send a reset link.
  ///
  /// The response is deliberately the same whether or not the address is
  /// registered, so there is nothing here to branch on — the screen says the
  /// same thing either way.
  Future<void> requestPasswordReset(String email) async {
    await _api.post<dynamic>(
      Api.forgotPassword,
      body: {'email': email.trim()},
    );
  }
}
