import 'dart:convert';

/// The school-side roles the app ships for.
///
/// `super_admin` is deliberately absent as a *destination*: the platform
/// console stays on web (`docs/mobile-app.md` §A1). A super admin who signs in
/// on a handset is treated as a school admin so the app is usable, but no
/// platform-only screen is reachable.
///
/// A role the app does not know (a newer backend, a custom role) becomes
/// [other], which shows a "use the web portal" home rather than any other
/// role's screens. It used to become a student: a bursar or nurse signing in
/// got the student app and a wall of refused requests.
enum UserRole {
  schoolAdmin('school_admin'),
  teacher('teacher'),
  bursar('bursar'),
  nurse('nurse'),
  student('student'),
  parent('parent'),
  superAdmin('super_admin'),
  other('other');

  const UserRole(this.wire);

  /// The value the backend stores in `user_profiles.role`.
  final String wire;

  static UserRole fromWire(String? value) {
    return UserRole.values.firstWhere(
      (r) => r.wire == value && r != other,
      orElse: () => other,
    );
  }

  bool get isStaff =>
      this == schoolAdmin || this == superAdmin || this == teacher || this == bursar || this == nurse;

  bool get isAdmin => this == schoolAdmin || this == superAdmin;

  /// Sees the school's arrears rather than one family's statement.
  bool get isFinance => isAdmin || this == bursar;

  String get label => switch (this) {
        schoolAdmin => 'Administrator',
        superAdmin => 'Administrator',
        teacher => 'Teacher',
        bursar => 'Bursar',
        nurse => 'Nurse',
        student => 'Student',
        parent => 'Parent',
        other => 'Staff',
      };
}

class AuthUser {
  const AuthUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    this.schoolId,
  });

  final int id;
  final String name;
  final String email;
  final UserRole role;
  final int? schoolId;

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    // `/auth/login` returns a flat user; `/user` returns the model with a
    // nested `user_profile`. Both land here.
    final profile = json['user_profile'] as Map<String, dynamic>?;
    return AuthUser(
      id: (json['id'] as num).toInt(),
      name: json['name'] as String? ?? '',
      email: json['email'] as String? ?? '',
      role: UserRole.fromWire(
        (json['role'] ?? profile?['role']) as String?,
      ),
      schoolId: (json['school_id'] ?? profile?['school_id']) as int?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'role': role.wire,
        'school_id': schoolId,
      };

  String get initials {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty);
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts.first.substring(0, 1).toUpperCase();
    return (parts.first.substring(0, 1) + parts.last.substring(0, 1))
        .toUpperCase();
  }

  static AuthUser? decode(String? raw) {
    if (raw == null || raw.isEmpty) return null;
    try {
      return AuthUser.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  String encode() => jsonEncode(toJson());
}

/// Read synchronously by the Dio interceptors, written by the auth controller.
///
/// Interceptors cannot await a secure-storage read on every request, and the
/// tenant header has to be on the *first* request of a cold start, so the two
/// values live here in memory once loaded.
class SessionHolder {
  String? token;
  String? schoolCode;

  /// Set by the router so a 401 anywhere can end the session exactly once,
  /// rather than each screen discovering it separately.
  void Function()? onUnauthorized;

  bool get isAuthenticated => token != null && token!.isNotEmpty;

  void clear() {
    token = null;
  }
}
