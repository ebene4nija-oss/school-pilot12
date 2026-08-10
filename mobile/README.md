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
| Sign out | No `/auth/logout`, so the token is only forgotten locally (G1) |
| Forgot password | No self-service reset endpoint (G2) |
| Edit my profile | `PUT /users/{id}/profile` is admin-only (G3) |
| Parent's children | Nothing lists a guardian's own children (G11) |
| Class / term pickers | No `GET /classes`, no `GET /terms` (G12) |
| Online fee payment | No exposed gateway public key; client-minted references (G8, G9) |
| Push notifications | Backend still calls the decommissioned legacy FCM API (G7) |
