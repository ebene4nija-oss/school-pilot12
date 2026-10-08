import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/app/navigation.dart';
import 'package:schoolpilot/core/auth/session.dart';
import 'package:schoolpilot/core/data/cached.dart';
import 'package:schoolpilot/core/providers.dart';
import 'package:schoolpilot/features/clinic/clinic_screen.dart';
import 'package:schoolpilot/features/dashboard/role_homes.dart';
import 'package:schoolpilot/features/finance/fees_screen.dart';

void main() {
  group('roles from the server', () {
    test('bursars and nurses get their own role, not the student app', () {
      expect(UserRole.fromWire('bursar'), UserRole.bursar);
      expect(UserRole.fromWire('nurse'), UserRole.nurse);
      expect(UserRole.fromWire('teacher'), UserRole.teacher);
    });

    test('a role this app does not know is never treated as a student', () {
      expect(UserRole.fromWire('librarian'), UserRole.other);
      expect(UserRole.fromWire(null), UserRole.other);
      // The sentinel itself is not a wire value the server can send.
      expect(UserRole.fromWire('other'), UserRole.other);
    });

    test('each role gets tabs that its account can actually use', () {
      expect(RoleNav.forRole(UserRole.bursar).map((d) => d.path), ['/home', '/fees', '/more']);
      expect(RoleNav.forRole(UserRole.nurse).map((d) => d.path), ['/home', '/more']);
      expect(RoleNav.forRole(UserRole.other).map((d) => d.path), ['/home', '/more']);
      expect(RoleNav.canReach(UserRole.bursar, '/students'), isFalse);
      expect(RoleNav.canReach(UserRole.nurse, '/fees'), isFalse);
    });

    test('bursars see the arrears list; staff flags are right', () {
      expect(UserRole.bursar.isFinance, isTrue);
      expect(UserRole.teacher.isFinance, isFalse);
      expect(UserRole.nurse.isStaff, isTrue);
      expect(UserRole.other.isStaff, isFalse);
    });
  });

  testWidgets('the bursar home shows what is outstanding and the largest balances', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          currentUserProvider.overrideWith((ref) => null),
          defaultersProvider.overrideWith((ref) async => Cached<dynamic>(
                data: {
                  'defaulters': [
                    {'name': 'Ada Obi', 'outstanding': 20000},
                    {'name': 'Tunde Bello', 'outstanding': 85000},
                  ],
                },
                fetchedAt: DateTime.now(),
              )),
        ],
        child: const MaterialApp(home: BursarHome()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('2'), findsOneWidget);
    expect(find.text('Largest balances'), findsOneWidget);
    // Largest first.
    final tunde = tester.getTopLeft(find.text('Tunde Bello')).dy;
    final ada = tester.getTopLeft(find.text('Ada Obi')).dy;
    expect(tunde, lessThan(ada));
  });

  testWidgets('the nurse home lists sick-bay visits with symptoms and treatment', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          clinicVisitsProvider.overrideWith((ref) async => Cached<dynamic>(
                data: {
                  'data': [
                    {
                      'student_name': 'Chinedu Eze',
                      'admission_number': 'GF/2024/018',
                      'symptoms': 'Headache',
                      'treatment': 'Rest and water',
                      'visited_at': '2026-10-08T10:15:00Z',
                    },
                  ],
                },
                fetchedAt: DateTime.now(),
              )),
        ],
        child: const MaterialApp(home: ClinicScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Chinedu Eze'), findsOneWidget);
    expect(find.text('Symptoms: Headache'), findsOneWidget);
    expect(find.text('Treatment: Rest and water'), findsOneWidget);
    expect(find.text('Record a visit'), findsOneWidget);
  });

  testWidgets('an unknown role is pointed to the web portal', (tester) async {
    await tester.pumpWidget(const ProviderScope(child: MaterialApp(home: WebOnlyHome())));
    expect(find.text('Use the web portal for your work'), findsOneWidget);
  });
}
