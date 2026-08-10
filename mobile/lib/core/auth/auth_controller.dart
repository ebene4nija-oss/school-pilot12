import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../db/app_database.dart';
import 'auth_repository.dart';
import 'session.dart';
import 'session_store.dart';

enum AuthStatus {
  /// Cold start, before stored credentials have been read.
  unknown,

  /// No school code yet — first launch on this handset.
  needsSchool,

  signedOut,
  signedIn,
}

class AuthState {
  const AuthState({
    required this.status,
    this.user,
    this.schoolCode,
  });

  final AuthStatus status;
  final AuthUser? user;
  final String? schoolCode;

  UserRole get role => user?.role ?? UserRole.student;

  AuthState copyWith({
    AuthStatus? status,
    AuthUser? user,
    String? schoolCode,
    bool clearUser = false,
  }) =>
      AuthState(
        status: status ?? this.status,
        user: clearUser ? null : (user ?? this.user),
        schoolCode: schoolCode ?? this.schoolCode,
      );
}

class AuthController extends StateNotifier<AuthState> {
  AuthController({
    required AuthRepository repository,
    required SessionStore store,
    required SessionHolder holder,
    required AppDatabase database,
  })  : _repository = repository,
        _store = store,
        _holder = holder,
        _database = database,
        super(const AuthState(status: AuthStatus.unknown));

  final AuthRepository _repository;
  final SessionStore _store;
  final SessionHolder _holder;
  final AppDatabase _database;

  /// Reads what the last session left behind and decides where the router
  /// should land. Runs once, before the first frame.
  Future<void> restore() async {
    final code = await _store.readSchoolCode();
    final token = await _store.readToken();
    final user = await _store.readUser();

    _holder.schoolCode = code;
    _holder.token = token;

    if (code == null || code.isEmpty) {
      state = const AuthState(status: AuthStatus.needsSchool);
      return;
    }

    if (token == null || token.isEmpty || user == null) {
      state = AuthState(status: AuthStatus.signedOut, schoolCode: code);
      return;
    }

    // Paint the signed-in shell straight away from the cached user, then
    // confirm against the server. A cold start in a corridor with no signal
    // should not dump a teacher back at the login screen.
    state = AuthState(status: AuthStatus.signedIn, user: user, schoolCode: code);
    unawaitedRefresh();
  }

  void unawaitedRefresh() {
    _repository.currentUser().then((fresh) {
      _store.writeUser(fresh);
      if (mounted) state = state.copyWith(user: fresh);
    }).catchError((_) {
      // A 401 is handled centrally by the interceptor; anything else means the
      // cached user stands.
    });
  }

  Future<void> setSchoolCode(String code) async {
    final normalised = code.trim().toLowerCase();
    await _store.writeSchoolCode(normalised);
    _holder.schoolCode = normalised.isEmpty ? null : normalised;

    // Clearing the code is how "change school" works, and it has to send the
    // router all the way back to the first screen.
    state = AuthState(
      status: normalised.isEmpty ? AuthStatus.needsSchool : AuthStatus.signedOut,
      schoolCode: normalised,
    );
  }

  /// Throws [TwoFactorRequired] or [ApiException]; the screen decides how to
  /// show either.
  Future<void> signIn({
    required String email,
    required String password,
    String? twoFactorCode,
  }) async {
    final code = state.schoolCode ?? '';
    final result = await _repository.login(
      email: email,
      password: password,
      schoolCode: code,
      twoFactorCode: twoFactorCode,
    );

    _holder.token = result.token;
    await _store.writeToken(result.token);
    await _store.writeUser(result.user);

    state = AuthState(
      status: AuthStatus.signedIn,
      user: result.user,
      schoolCode: code,
    );
  }

  /// Ends the session on this device.
  ///
  /// The cache and the outbox go with it: leaving one family's queued messages
  /// on a handset the next person signs into is not acceptable, even though it
  /// means an unsent register is lost. Sign-out warns about a non-empty queue
  /// before reaching here.
  Future<void> signOut() async {
    await _store.clearSession();
    await _database.wipe();
    _holder.clear();
    state = AuthState(status: AuthStatus.signedOut, schoolCode: state.schoolCode);
  }

  /// Called by the API layer on any 401. Same as [signOut] but never blocks on
  /// the network, because the token it would use is already dead.
  void endSessionFromServer() {
    if (state.status != AuthStatus.signedIn) return;
    signOut();
  }
}
