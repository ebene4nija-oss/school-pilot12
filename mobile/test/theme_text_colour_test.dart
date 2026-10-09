import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/app/theme.dart';

/// Titles drawn with a colourless style must still be readable: the More
/// screen's app bar title and list-item names once came out white on the
/// light background, visible only as faint ghosts.
void main() {
  Color? colourOf(WidgetTester tester, String text) {
    final rich = tester.widget<RichText>(find.descendant(of: find.text(text), matching: find.byType(RichText)).first);
    return rich.text.style?.color;
  }

  testWidgets('app bar and list titles are dark on the light theme', (tester) async {
    await tester.pumpWidget(MaterialApp(
      theme: buildAppTheme(),
      home: Scaffold(
        appBar: AppBar(title: const Text('More')),
        body: ListView(children: [
          ListTile(title: Text('Messages', style: AppText.bodyLg.copyWith(color: null))),
          const ListTile(title: Text('Notifications')),
          ChoiceChip(label: const Text('This week'), selected: false, onSelected: (_) {}),
          FilterChip(label: const Text('Owing (3)'), selected: true, onSelected: (_) {}),
        ]),
      ),
    ));

    for (final label in ['More', 'Messages', 'Notifications', 'This week', 'Owing (3)']) {
      final colour = colourOf(tester, label);
      expect(colour, AppColors.onSurface, reason: '$label should be drawn in onSurface');
    }
  });
}
