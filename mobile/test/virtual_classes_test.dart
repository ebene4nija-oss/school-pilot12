import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/reference_data.dart';
import 'package:schoolpilot/features/classes/schedule_virtual_class_screen.dart';
import 'package:schoolpilot/features/classes/virtual_attendance_screen.dart';

void main() {
  test('the provider is read from the pasted link', () {
    expect(ScheduleVirtualClassScreenStateProbe.providerFor('https://us05web.zoom.us/j/8123'), 'zoom');
    expect(ScheduleVirtualClassScreenStateProbe.providerFor('https://meet.google.com/abc-defg-hij'), 'google_meet');
    expect(ScheduleVirtualClassScreenStateProbe.providerFor('https://teams.microsoft.com/l/meetup'), 'other');
  });

  testWidgets('scheduling needs a title, a class and a real link', (tester) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(ProviderScope(
      overrides: [classesProvider.overrideWith((ref) async => const <ClassRef>[])],
      child: const MaterialApp(home: ScheduleVirtualClassScreen()),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Schedule and tell students'));
    await tester.pumpAndSettle();
    expect(find.textContaining('meeting link (starting https://) are needed'), findsOneWidget);
  });

  testWidgets('upcoming classes are listed above past attendance, with Open', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        recentVirtualClassesProvider.overrideWith((ref) async => [
              {'id': 1, 'title': 'Algebra recap', 'status': 'ended', 'scheduled_for': '2026-10-05T09:00:00Z'},
            ]),
        upcomingVirtualClassesProvider.overrideWith((ref) async => [
              {'id': 2, 'title': 'Quadratics live', 'scheduled_for': '2026-10-12T09:00:00Z', 'meeting_url': 'https://meet.google.com/abc-defg-hij'},
            ]),
      ],
      child: const MaterialApp(home: VirtualClassesScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Upcoming'), findsOneWidget);
    expect(find.text('Quadratics live'), findsOneWidget);
    expect(find.text('Open'), findsOneWidget);
    expect(find.text('Schedule a class'), findsOneWidget);
  });
}
