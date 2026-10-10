import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/reference_data.dart';
import 'package:schoolpilot/features/teaching/ai_pictures_screen.dart';

void main() {
  Widget app() => ProviderScope(
        overrides: [classesProvider.overrideWith((ref) async => const <ClassRef>[])],
        child: const MaterialApp(home: AiPicturesScreen()),
      );

  testWidgets('picture mode asks for a class, subject and photo before calling AI', (tester) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(app());
    await tester.pumpAndSettle();

    expect(find.text('Take photo'), findsOneWidget);
    await tester.tap(find.text('Write questions'));
    await tester.pumpAndSettle();
    expect(find.textContaining('take a photo first'), findsOneWidget);
  });

  testWidgets('scan mode reads a paper, page by page', (tester) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 2.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(app());
    await tester.pumpAndSettle();
    await tester.tap(find.text('Scan a paper'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Photograph each page flat'), findsOneWidget);
    expect(find.text('Read the paper'), findsOneWidget);
  });
}
