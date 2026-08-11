import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import '../api/api_client.dart';
import '../api/endpoints.dart';

/// The client half of push (§B10).
///
/// The backend transport has been live for a while — FCM HTTP v1, with a
/// `POST /notifications/devices/test` that pushes to your own handset and
/// reports per-device delivery. This is everything on this side of it: getting
/// a token, telling the server about it, keeping it current, and drawing a
/// notification when one arrives while the app is open.
///
/// **Every path through this class is allowed to fail without taking the app
/// with it.** Push is a convenience on a product where a parent may be on a
/// handset with no Play Services at all, and a school may deploy without ever
/// creating a Firebase project. A missing `google-services.json` must mean "no
/// push", not "the app will not start" — so `Firebase.initializeApp` is guarded
/// and every later call checks [isAvailable] first.
class PushService {
  PushService({required ApiClient api}) : _api = api;

  final ApiClient _api;
  final FlutterLocalNotificationsPlugin _local = FlutterLocalNotificationsPlugin();

  /// Must match the channel the backend names in its FCM payload. Android 8+
  /// silently drops a notification addressed to a channel that does not exist,
  /// with no error anywhere — so the two sides agree on this string, and it is
  /// written down in `docs/mobile-app.md` §B10.
  static const androidChannelId = 'schoolpilot_default';

  bool _available = false;
  String? _registeredToken;

  /// Whether Firebase came up. False on a deployment with no Firebase config,
  /// on an emulator without Play Services, and on any platform we have not set
  /// up — all of which are ordinary, none of which are errors.
  bool get isAvailable => _available;

  /// Called once at startup, before the first frame.
  ///
  /// Deliberately does not ask for permission and does not register anything:
  /// at this point nobody may be signed in, and a permission prompt on top of a
  /// login screen is how an install gets denied for good on the first run.
  Future<void> initialise() async {
    try {
      await Firebase.initializeApp();
      _available = true;
    } catch (error) {
      // The common case is a missing google-services.json, which is exactly
      // what a fresh clone looks like. Not worth more than a debug line.
      debugPrint('Push unavailable: $error');
      _available = false;

      return;
    }

    await _createAndroidChannel();

    // A notification tapped while the app is running should not draw twice —
    // Android draws it itself when the app is backgrounded, so the foreground
    // case is the only one we render.
    FirebaseMessaging.onMessage.listen(_show);
  }

  /// Ask for permission and hand the token to the server.
  ///
  /// Called after sign-in, so the prompt arrives attached to something the user
  /// has just chosen to do rather than to a cold app launch.
  Future<void> registerForUser() async {
    if (!_available) return;

    try {
      final settings = await FirebaseMessaging.instance.requestPermission();

      if (settings.authorizationStatus == AuthorizationStatus.denied) {
        // Their call. Nothing is broken and nothing needs saying — the school
        // still reaches them by SMS.
        return;
      }

      final token = await FirebaseMessaging.instance.getToken();
      await _sendToken(token);

      // The OS rotates tokens on reinstall, restore, and at its own discretion.
      // A token the server holds after rotation is a notification nobody gets.
      FirebaseMessaging.instance.onTokenRefresh.listen(_sendToken);
    } catch (error) {
      debugPrint('Push registration failed: $error');
    }
  }

  /// Stop this handset receiving on sign-out.
  ///
  /// A shared or family handset is the norm in this market. Leaving the token
  /// registered means the next person to sign in receives the last person's
  /// child's notifications.
  Future<void> unregister() async {
    if (!_available || _registeredToken == null) return;

    try {
      await _api.delete<dynamic>(
        Api.notificationDevices,
        body: {'token': _registeredToken},
      );
    } catch (error) {
      // A failed unregister must never block sign-out. The server drops tokens
      // FCM disowns anyway, and the session is ending either way.
      debugPrint('Push unregister failed: $error');
    }

    _registeredToken = null;
  }

  Future<void> _sendToken(String? token) async {
    if (token == null || token.isEmpty || token == _registeredToken) return;

    try {
      await _api.post<dynamic>(
        Api.notificationDevices,
        body: {
          'token': token,
          'platform': Platform.isIOS ? 'ios' : 'android',
          'device_name': await _deviceLabel(),
        },
      );

      _registeredToken = token;
    } catch (error) {
      debugPrint('Device registration failed: $error');
    }
  }

  /// Something a parent can recognise in a device list, not a fingerprint.
  /// `Platform.localHostname` is the handset's own name, which is what they
  /// called it — it is not an advertising id and not a hardware serial (§12).
  Future<String> _deviceLabel() async {
    try {
      return Platform.localHostname;
    } catch (_) {
      return Platform.isIOS ? 'iPhone' : 'Android phone';
    }
  }

  Future<void> _createAndroidChannel() async {
    const settings = InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      iOS: DarwinInitializationSettings(
        // Requested explicitly at sign-in instead, alongside the FCM prompt.
        requestAlertPermission: false,
        requestBadgePermission: false,
        requestSoundPermission: false,
      ),
    );

    await _local.initialize(settings);

    const channel = AndroidNotificationChannel(
      androidChannelId,
      'School notifications',
      description: 'Fee reminders, results, homework and school announcements.',
      importance: Importance.high,
    );

    await _local
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(channel);
  }

  Future<void> _show(RemoteMessage message) async {
    final notification = message.notification;
    if (notification == null) return;

    await _local.show(
      // Collapse on the FCM message id so a redelivery replaces the banner
      // rather than stacking a second copy of the same fee reminder.
      message.messageId.hashCode,
      notification.title ?? 'SchoolPilot',
      notification.body ?? '',
      const NotificationDetails(
        android: AndroidNotificationDetails(
          androidChannelId,
          'School notifications',
          importance: Importance.high,
          priority: Priority.high,
        ),
        iOS: DarwinNotificationDetails(),
      ),
    );
  }
}
