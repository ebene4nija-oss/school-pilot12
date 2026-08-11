# SchoolPilot Mobile

One Flutter app for the four school-side roles — Admin, Teacher, Student,
Parent — against the Laravel API in `../backend`.

Plan, screen-to-endpoint map and the list of backend gaps: `../docs/mobile-app.md`.
Design system and screens: `../docs/stitch_schoolpilot_mobile_ui_design/`.

## Running it

The app has no hardcoded server. Point it at one:

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
```

`10.0.2.2` is how an Android emulator reaches `php artisan serve` on the host
machine, and it is the default if you pass nothing. For a physical handset on
the same wifi, use the laptop's LAN address; for a deployment, the real host.

On first launch the app asks for a **school code** — the school's subdomain,
e.g. `graceland`. A handset has no subdomain of its own, so that code travels
as `X-School-Subdomain` on every request, which is the fallback
`TenantResolutionMiddleware` already accepts.

## Checks

```bash
flutter analyze
flutter test
```

## Layout

```
lib/
  app/        theme (design tokens), router, role navigation
  core/
    api/      the one Dio client, interceptors, endpoint constants
    auth/     session, secure token, sign-in
    db/       SQLite: read cache, write outbox, CBT answer buffer
    sync/     drains the outbox when a connection returns
    data/     read-through caching, backend reference data
    ui/       shared widgets, the four states, Naira and date formatting
  features/   one folder per area, screens grouped by role
```

## Two things worth knowing before changing anything

**The outbox is not a nicety.** Every write made offline is a row in
`outbox`, and its idempotency key is minted when the user opens the form, not
when they submit. `/attendance/bulk` deduplicates on that key, which is the
only reason a retry after a dropped connection cannot double-mark a register.
Do not regenerate the key on retry.

**An AI comment is never final until a teacher approves it.** It renders with
the pending-approval treatment in `features/gradebook/comment_review_screen.dart`
and appears nowhere else in the app until approved. That is a product rule from
`CLAUDE.md`, not a styling choice.

## Not wired up, and why

Some screens deliberately explain a limitation instead of offering a control
that cannot work. Each one traces to a missing backend route — see
`../docs/mobile-app.md` §B12:

| Area | Why |
|---|---|
| Edit my profile | `PUT /users/{id}/profile` is admin-only (G3) |

That is the whole list now. Sign-out, forgot-password, the parent's children
list, the class and term pickers, the homework list, the notification inbox,
online payment and push all used to sit here. `POST /auth/logout` revokes this
device's token on sign-out, and "Forgot password?" asks the backend to email a
reset link — which opens in the web portal, since the app does not handle deep
links.

## Push notifications

Wired end to end, and off until you give it a Firebase project.

`core/push/push_service.dart` gets an FCM token, registers it at sign-in,
follows `onTokenRefresh`, unregisters at sign-out — a family handset is the norm
here, and a token left registered sends one child's results to whoever picks the
phone up next — and draws foreground messages on the `schoolpilot_default`
channel. That channel id must match the backend's `FCM_ANDROID_CHANNEL_ID`:
Android 8+ silently drops a notification addressed to a channel it does not
know, with no error anywhere.

**To turn it on:**

1. Create a Firebase project and add an Android app with the applicationId
   `ng.schoolpilot.schoolpilot`.
2. Download `google-services.json` into `android/app/`. It is gitignored — it
   carries your project id and key, and it is per-deployment.
3. For iOS, add the APNs key in the Firebase console and drop
   `GoogleService-Info.plist` into `ios/Runner/`.
4. On the backend, set `FCM_CREDENTIALS` to the service-account JSON.
5. Check it with `POST /api/v1/notifications/devices/test`, which pushes to your
   own handset and reports per-device delivery.

**Without any of that the app still builds and runs.** The Gradle plugin is
applied only when `google-services.json` exists, and `Firebase.initializeApp` is
guarded, so a fresh clone gets `isAvailable == false` and no push. A handset
with no Play Services behaves the same way. Push is a convenience on a product
where the school can still reach every family by SMS.

## Paying a school

Worth knowing before touching `features/finance/`, because the interesting part
is what the app deliberately does *not* do.

`GatewayCheckout` is the whole payment integration. The app posts an invoice id
to `POST /finance/payments/initialize` and gets back an `authorization_url` on
the school's own merchant account; it opens that in a webview and watches for
the server's `/api/v1/payments/return` page. That is the entire client side:

- **No key is compiled into the binary**, public ones included. Every school
  holds its own Paystack or Flutterwave account and the server holds the keys.
- **The app sends no amount.** The server charges the balance it computed, so a
  tampered client cannot pay ₦1 against a ₦45,000 bill.
- **The app sends no gateway.** A parent does not know which merchant account
  their school holds, and the endpoint that would tell them is admin-only, so
  the server picks.
- **The app never says "paid".** Settlement is a signed webhook the app cannot
  see. Both screens re-ask the server after checkout and say *confirming*.

The webview closes on the return URL specifically, and never on "a host that
isn't the gateway's" — a card that asks for 3-D Secure routes the payer through
their own bank's domain mid-payment, and host sniffing would abandon checkout at
exactly the wrong moment.
