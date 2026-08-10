import 'package:flutter/material.dart';

import '../core/auth/session.dart';

class NavDestination {
  const NavDestination({
    required this.path,
    required this.label,
    required this.icon,
    required this.selectedIcon,
  });

  final String path;
  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

/// The bottom navigation for each role, and the only place tab order is
/// decided.
///
/// Five destinations maximum — a sixth is past the practical limit on a 5-inch
/// screen, so anything else goes under More.
class RoleNav {
  const RoleNav._();

  static const admin = [
    NavDestination(
      path: '/home',
      label: 'Home',
      icon: Icons.home_outlined,
      selectedIcon: Icons.home,
    ),
    NavDestination(
      path: '/students',
      label: 'Students',
      icon: Icons.people_outline,
      selectedIcon: Icons.people,
    ),
    NavDestination(
      path: '/fees',
      label: 'Fees',
      icon: Icons.payments_outlined,
      selectedIcon: Icons.payments,
    ),
    // Academics rather than the design set's Staff: results, release, timetable
    // and CBT need a destination, and for a principal they are not a "More"
    // item. See docs/mobile-app.md §B3.
    NavDestination(
      path: '/academics',
      label: 'Academics',
      icon: Icons.school_outlined,
      selectedIcon: Icons.school,
    ),
    NavDestination(
      path: '/more',
      label: 'More',
      icon: Icons.more_horiz,
      selectedIcon: Icons.more_horiz,
    ),
  ];

  static const teacher = [
    NavDestination(
      path: '/home',
      label: 'Home',
      icon: Icons.home_outlined,
      selectedIcon: Icons.home,
    ),
    NavDestination(
      path: '/register',
      label: 'Register',
      icon: Icons.fact_check_outlined,
      selectedIcon: Icons.fact_check,
    ),
    NavDestination(
      path: '/gradebook',
      label: 'Gradebook',
      icon: Icons.edit_note_outlined,
      selectedIcon: Icons.edit_note,
    ),
    NavDestination(
      path: '/classes',
      label: 'Classes',
      icon: Icons.menu_book_outlined,
      selectedIcon: Icons.menu_book,
    ),
    NavDestination(
      path: '/more',
      label: 'More',
      icon: Icons.more_horiz,
      selectedIcon: Icons.more_horiz,
    ),
  ];

  static const student = [
    NavDestination(
      path: '/home',
      label: 'Home',
      icon: Icons.home_outlined,
      selectedIcon: Icons.home,
    ),
    NavDestination(
      path: '/timetable',
      label: 'Timetable',
      icon: Icons.calendar_month_outlined,
      selectedIcon: Icons.calendar_month,
    ),
    NavDestination(
      path: '/learn',
      label: 'Learn',
      icon: Icons.school_outlined,
      selectedIcon: Icons.school,
    ),
    NavDestination(
      path: '/results',
      label: 'Results',
      icon: Icons.star_outline,
      selectedIcon: Icons.star,
    ),
    NavDestination(
      path: '/more',
      label: 'More',
      icon: Icons.more_horiz,
      selectedIcon: Icons.more_horiz,
    ),
  ];

  static const parent = [
    NavDestination(
      path: '/home',
      label: 'Home',
      icon: Icons.home_outlined,
      selectedIcon: Icons.home,
    ),
    NavDestination(
      path: '/child',
      label: 'Child',
      icon: Icons.child_care_outlined,
      selectedIcon: Icons.child_care,
    ),
    NavDestination(
      path: '/fees',
      label: 'Fees',
      icon: Icons.payments_outlined,
      selectedIcon: Icons.payments,
    ),
    NavDestination(
      path: '/results',
      label: 'Results',
      icon: Icons.assessment_outlined,
      selectedIcon: Icons.assessment,
    ),
    NavDestination(
      path: '/more',
      label: 'More',
      icon: Icons.more_horiz,
      selectedIcon: Icons.more_horiz,
    ),
  ];

  static List<NavDestination> forRole(UserRole role) => switch (role) {
        UserRole.schoolAdmin || UserRole.superAdmin => admin,
        UserRole.teacher => teacher,
        UserRole.student => student,
        UserRole.parent => parent,
      };

  /// Whether this role can reach this tab at all. The server enforces
  /// permissions; this only keeps a deep link from landing a parent on the
  /// gradebook and showing them a 403 for their trouble.
  static bool canReach(UserRole role, String path) =>
      forRole(role).any((d) => d.path == path);
}
