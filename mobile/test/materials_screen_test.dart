import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/learn/materials_screen.dart';

void main() {
  Widget app(Map<String, dynamic> data) => ProviderScope(
        overrides: [materialsLibraryProvider.overrideWith((ref) async => data)],
        child: const MaterialApp(home: MaterialsScreen()),
      );

  final library = {
    'data': [
      {'id': 1, 'title': 'Fractions lesson note', 'type': 'pdf', 'subject': 'Mathematics', 'topic': 'Fractions', 'uploaded_by': 'Mrs Bello'},
      {'id': 2, 'title': 'Photosynthesis explained', 'type': 'video', 'subject': 'Basic Science'},
      {'id': 3, 'title': 'Oral English drill', 'type': 'audio', 'subject': 'English'},
    ],
  };

  testWidgets('every material is listed, with who shared it', (tester) async {
    await tester.pumpWidget(app(library));
    await tester.pumpAndSettle();

    expect(find.text('Fractions lesson note'), findsOneWidget);
    expect(find.text('Photosynthesis explained'), findsOneWidget);
    expect(find.text('Oral English drill'), findsOneWidget);
    expect(find.textContaining('by Mrs Bello'), findsOneWidget);
  });

  testWidgets('the Video filter leaves only video', (tester) async {
    await tester.pumpWidget(app(library));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilterChip, 'Video'));
    await tester.pumpAndSettle();

    expect(find.text('Photosynthesis explained'), findsOneWidget);
    expect(find.text('Fractions lesson note'), findsNothing);
    expect(find.text('Oral English drill'), findsNothing);
  });

  testWidgets('search matches a topic', (tester) async {
    await tester.pumpWidget(app(library));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField), 'fractions');
    await tester.pumpAndSettle();

    expect(find.text('Fractions lesson note'), findsOneWidget);
    expect(find.text('Oral English drill'), findsNothing);
  });

  testWidgets("a parent whose school keeps materials for students hears why", (tester) async {
    await tester.pumpWidget(app({
      'data': <dynamic>[],
      'message': 'The school shares learning materials with students directly.',
    }));
    await tester.pumpAndSettle();

    expect(find.textContaining('shares learning materials with students directly'), findsOneWidget);
  });
}
