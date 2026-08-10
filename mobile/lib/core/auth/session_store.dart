import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'session.dart';

/// Where the session lives between launches.
///
/// The token goes to the platform keystore, never to SharedPreferences. The
/// school code and the cached user do go to preferences — the code is not a
/// secret (it is in the school's own URL) and the user blob is only there so a
/// cold start can paint the right navigation before `/user` returns.
class SessionStore {
  SessionStore({FlutterSecureStorage? secure, SharedPreferences? prefs})
      : _secure = secure ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            ),
        _prefs = prefs;

  final FlutterSecureStorage _secure;
  SharedPreferences? _prefs;

  static const _tokenKey = 'auth_token';
  static const _schoolKey = 'school_code';
  static const _userKey = 'auth_user';

  Future<SharedPreferences> get _p async =>
      _prefs ??= await SharedPreferences.getInstance();

  Future<String?> readToken() => _secure.read(key: _tokenKey);

  Future<void> writeToken(String token) =>
      _secure.write(key: _tokenKey, value: token);

  Future<void> clearToken() => _secure.delete(key: _tokenKey);

  Future<String?> readSchoolCode() async => (await _p).getString(_schoolKey);

  Future<void> writeSchoolCode(String code) async =>
      (await _p).setString(_schoolKey, code);

  Future<AuthUser?> readUser() async =>
      AuthUser.decode((await _p).getString(_userKey));

  Future<void> writeUser(AuthUser user) async =>
      (await _p).setString(_userKey, user.encode());

  /// Sign-out. The school code deliberately survives — the next person to open
  /// the app on this handset is almost certainly from the same school, and
  /// making them retype it is friction for nothing.
  Future<void> clearSession() async {
    await clearToken();
    await (await _p).remove(_userKey);
  }
}
