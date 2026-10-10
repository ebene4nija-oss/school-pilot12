import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:schoolpilot/app/theme.dart';
import 'package:schoolpilot/core/auth/session.dart';
import 'package:schoolpilot/core/providers.dart';
import 'package:schoolpilot/core/sync/sync_service.dart';
import 'package:schoolpilot/features/more/more_screen.dart';

class _QuietSync extends ValueNotifier<SyncState> implements SyncService {
  _QuietSync() : super(const SyncState());

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

double _luminance(Color c) => c.computeLuminance();

double _contrast(Color a, Color b) {
  final l1 = _luminance(a), l2 = _luminance(b);
  return (l1 > l2 ? l1 + 0.05 : l2 + 0.05) / (l1 > l2 ? l2 + 0.05 : l1 + 0.05);
}

void main() {
  PackageInfo.setMockInitialValues(
    appName: 'SchoolPilot', packageName: 'ng.schoolpilot', version: '1.2.3',
    buildNumber: '6', buildSignature: '',
  );

  for (final role in UserRole.values) {
    testWidgets('every More item is readable for ${role.wire}', (tester) async {
      tester.view.physicalSize = const Size(1080, 6000);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(ProviderScope(
        overrides: [
          currentRoleProvider.overrideWithValue(role),
          currentUserProvider.overrideWithValue(
            AuthUser(id: 1, name: 'Ada Obi', email: 'a@b.c', role: role),
          ),
          syncServiceProvider.overrideWithValue(_QuietSync()),
        ],
        child: MaterialApp(theme: buildAppTheme(), home: const MoreScreen()),
      ));
      await tester.pumpAndSettle();

      final background = buildAppTheme().scaffoldBackgroundColor;
      final problems = <String>[];

      for (final tile in tester.widgetList<ListTile>(find.byType(ListTile))) {
        final finder = find.byWidget(tile);
        for (final text in tester.widgetList<RichText>(
          find.descendant(of: finder, matching: find.byType(RichText)),
        )) {
          final colour = text.text.style?.color;
          final label = text.text.toPlainText();
          if (colour == null || colour.a < 0.5 || _contrast(colour, background) < 3) {
            problems.add('"$label" drawn in $colour');
          }
        }
        final box = tester.getRect(finder);
        if (box.height < 1 || box.width < 1) problems.add('a tile has no size');
      }

      expect(problems, isEmpty, reason: problems.join('\n'));
    });
  }
}
