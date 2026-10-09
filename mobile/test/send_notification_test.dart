import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/reference_data.dart';
import 'package:schoolpilot/features/more/send_notification_screen.dart';

void main() {
  testWidgets('admin picks an audience, a channel the school can use, and writes before sending', (tester) async {
    tester.view.physicalSize = const Size(1080, 3000);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(ProviderScope(
      overrides: [
        classesProvider.overrideWith((ref) async => const [ClassRef(id: 1, name: 'JSS 1')]),
        notificationChannelsProvider.overrideWith((ref) async => {
              'push': {'ready': true},
              'sms': {'ready': false, 'reason': 'Add your SMS account under SMS settings on this page.'},
              'whatsapp': {'ready': true},
              'email': {'ready': true},
            }),
      ],
      child: const MaterialApp(home: SendNotificationScreen()),
    ));
    await tester.pumpAndSettle();

    // Parents can be narrowed to a class; teachers cannot.
    expect(find.text('Class'), findsOneWidget);
    await tester.tap(find.text('Teachers'));
    await tester.pumpAndSettle();
    expect(find.text('Class'), findsNothing);

    // A channel the school has not set up is shown, disabled, with the reason.
    final sms = tester.widget<CheckboxListTile>(find.widgetWithText(CheckboxListTile, 'SMS'));
    expect(sms.onChanged, isNull);
    expect(find.textContaining('Add your SMS account'), findsOneWidget);

    // In-app is on by default; Send waits for a message.
    final push = tester.widget<CheckboxListTile>(find.widgetWithText(CheckboxListTile, 'In-app notification'));
    expect(push.value, isTrue);
    FilledButton send() => tester.widget<FilledButton>(find.ancestor(
          of: find.text('Send'),
          matching: find.byWidgetPredicate((w) => w is FilledButton),
        ));
    expect(send().onPressed, isNull);

    await tester.enterText(find.widgetWithText(TextField, 'Message'), 'School closes at noon on Friday.');
    await tester.pump();
    expect(send().onPressed, isNotNull);
  });
}
