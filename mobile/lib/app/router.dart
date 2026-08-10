import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/auth/auth_controller.dart';
import '../core/providers.dart';
import '../features/academics/academics_screen.dart';
import '../features/attendance/register_tab.dart';
import '../features/auth/find_school_screen.dart';
import '../features/auth/sign_in_screen.dart';
import '../features/child/child_screen.dart';
import '../features/classes/classes_tab.dart';
import '../features/dashboard/home_screen.dart';
import '../features/finance/fees_screen.dart';
import '../features/gradebook/comment_review_screen.dart';
import '../features/gradebook/gradebook_tab.dart';
import '../features/learn/learn_tab.dart';
import '../features/learn/tutor_chat_screen.dart';
import '../features/messages/messages_screen.dart';
import '../features/more/more_screen.dart';
import '../features/more/notifications_screen.dart';
import '../features/more/pending_sync_screen.dart';
import '../features/more/profile_screen.dart';
import '../features/results/results_screen.dart';
import '../features/shell/app_shell.dart';
import '../features/students/students_screen.dart';
import '../features/timetable/timetable_screen.dart';
import 'navigation.dart';

/// Auth gating happens once, here, rather than in every screen.
///
/// Three states decide everything: no school code, no session, signed in.
/// A role that cannot reach a tab is sent home instead of being shown a 403 —
/// the server still enforces the permission, this only stops deep links
/// landing somewhere absurd.
final routerProvider = Provider<GoRouter>((ref) {
  final notifier = _AuthRefresh(ref);

  return GoRouter(
    initialLocation: '/home',
    refreshListenable: notifier,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final path = state.matchedLocation;

      if (auth.status == AuthStatus.unknown) return null;

      if (auth.status == AuthStatus.needsSchool) {
        return path == '/school' ? null : '/school';
      }

      if (auth.status == AuthStatus.signedOut) {
        return path == '/login' ? null : '/login';
      }

      // Signed in: bounce away from the auth screens.
      if (path == '/login' || path == '/school') return '/home';

      final role = auth.role;
      final isTab = RoleNav.forRole(role).any((d) => d.path == path) ||
          const ['/home'].contains(path);

      if (!isTab) return null;
      return RoleNav.canReach(role, path) || path == '/home' ? null : '/home';
    },
    routes: [
      GoRoute(path: '/school', builder: (_, __) => const FindSchoolScreen()),
      GoRoute(path: '/login', builder: (_, __) => const SignInScreen()),

      // Full-screen routes that deliberately sit outside the tab shell.
      GoRoute(path: '/profile', builder: (_, __) => const ProfileScreen()),
      GoRoute(path: '/pending', builder: (_, __) => const PendingSyncScreen()),
      GoRoute(path: '/messages', builder: (_, __) => const MessagesScreen()),
      GoRoute(
        path: '/notifications',
        builder: (_, __) => const NotificationsScreen(),
      ),
      GoRoute(path: '/timetable-full', builder: (_, __) => const TimetableScreen()),
      GoRoute(path: '/learn/tutor', builder: (_, __) => const TutorChatScreen()),
      GoRoute(
        path: '/gradebook/comments',
        builder: (_, __) => const CommentReviewScreen(),
      ),

      ShellRoute(
        builder: (context, state, child) =>
            AppShell(location: state.matchedLocation, child: child),
        routes: [
          GoRoute(path: '/home', builder: (_, __) => const HomeScreen()),

          // Admin
          GoRoute(path: '/students', builder: (_, __) => const StudentsScreen()),
          GoRoute(path: '/academics', builder: (_, __) => const AcademicsScreen()),

          // Teacher
          GoRoute(path: '/register', builder: (_, __) => const RegisterTab()),
          GoRoute(path: '/gradebook', builder: (_, __) => const GradebookTab()),
          GoRoute(path: '/classes', builder: (_, __) => const ClassesTab()),

          // Student
          GoRoute(path: '/learn', builder: (_, __) => const LearnTab()),

          // Parent
          GoRoute(path: '/child', builder: (_, __) => const ChildScreen()),

          // Shared across roles, rendering differently per role.
          GoRoute(path: '/timetable', builder: (_, __) => const TimetableScreen()),
          GoRoute(path: '/fees', builder: (_, __) => const FeesScreen()),
          GoRoute(path: '/results', builder: (_, __) => const ResultsScreen()),
          GoRoute(path: '/more', builder: (_, __) => const MoreScreen()),
        ],
      ),
    ],
    errorBuilder: (context, state) => Scaffold(
      appBar: AppBar(),
      body: Center(child: Text('No screen for ${state.uri}')),
    ),
  );
});

/// Re-runs the redirect whenever the session changes.
class _AuthRefresh extends ChangeNotifier {
  _AuthRefresh(Ref ref) {
    ref.listen<AuthState>(
      authControllerProvider,
      (previous, next) {
        if (previous?.status != next.status) notifyListeners();
      },
    );
  }
}
