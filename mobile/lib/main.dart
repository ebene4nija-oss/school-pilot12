import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/router.dart';
import 'app/theme.dart';
import 'core/api/api_client.dart';
import 'core/auth/session.dart';
import 'core/auth/session_store.dart';
import 'core/db/app_database.dart';
import 'core/db/outbox.dart';
import 'core/providers.dart';
import 'core/push/push_service.dart';
import 'core/sync/sync_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Portrait only. Every screen in this app is a vertical list or a form, and
  // the register in particular is designed to be worked one-handed.
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  final database = await AppDatabase.open();
  final holder = SessionHolder();
  final api = ApiClient(session: holder);
  final store = SessionStore();
  final outbox = Outbox(database);
  final sync = SyncService(api: api, outbox: outbox);
  final push = PushService(api: api);

  /*
   * Push comes up before the first frame but asks for nothing.
   *
   * `initialise` starts Firebase and creates the Android channel; the
   * permission prompt and the token registration happen at sign-in instead
   * (see AuthController). Prompting on a cold launch, over a login screen, is
   * how an install gets its notifications denied for good on the first run.
   *
   * It cannot throw: a build with no `google-services.json` — which is what a
   * fresh clone is — has to start and run with push simply unavailable.
   */
  await push.initialise();

  final container = ProviderContainer(
    overrides: [
      sessionHolderProvider.overrideWithValue(holder),
      databaseProvider.overrideWithValue(database),
      apiClientProvider.overrideWithValue(api),
      sessionStoreProvider.overrideWithValue(store),
      syncServiceProvider.overrideWithValue(sync),
      pushServiceProvider.overrideWithValue(push),
    ],
  );

  // A 401 anywhere ends the session in one place, so a screen that was
  // mid-request does not have to work out what an expired token means.
  holder.onUnauthorized =
      () => container.read(authControllerProvider.notifier).endSessionFromServer();

  await container.read(authControllerProvider.notifier).restore();
  await sync.start();

  runApp(
    UncontrolledProviderScope(
      container: container,
      child: const SchoolPilotApp(),
    ),
  );
}

class SchoolPilotApp extends ConsumerWidget {
  const SchoolPilotApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp.router(
      title: 'SchoolPilot',
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(),
      routerConfig: ref.watch(routerProvider),
      builder: (context, child) {
        // Respect the user's font-size setting, but stop a 2x system scale
        // from breaking the register into unreadable overlap.
        final scale = MediaQuery.textScalerOf(context).clamp(
          minScaleFactor: 0.9,
          maxScaleFactor: 1.4,
        );
        return MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: scale),
          child: child ?? const SizedBox.shrink(),
        );
      },
    );
  }
}
