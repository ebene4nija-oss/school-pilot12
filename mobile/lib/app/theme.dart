import 'package:flutter/material.dart';

/// The "High-Contrast Functionalism" design system.
///
/// Tokens come from
/// `docs/stitch_schoolpilot_mobile_ui_design/.../high_contrast_functionalism/DESIGN.md`.
/// Two conflicts in that file are resolved here once, per `docs/mobile-app.md`
/// §A4.1: the action amber is #feae2c (not the token block's #ffc16a), and
/// corner radii follow the prose (cards 8, sections 16, buttons pill-shaped)
/// rather than the tighter `rounded` scale.
///
/// Nothing outside this file declares a hex value.
class AppColors {
  const AppColors._();

  static const primary = Color(0xFF000E22);
  static const onPrimary = Color(0xFFFFFFFF);
  static const primaryContainer = Color(0xFF002447);
  static const onPrimaryContainer = Color(0xFF718CB5);

  static const secondary = Color(0xFF835500);
  static const onSecondary = Color(0xFFFFFFFF);
  static const secondaryContainer = Color(0xFFFFC16A);
  static const onSecondaryContainer = Color(0xFF784D00);

  /// The action colour. Every primary button is this, with [onAction] text.
  static const action = Color(0xFFFEAE2C);
  static const onAction = Color(0xFF1B1C1B);

  static const tertiary = Color(0xFF001105);
  static const tertiaryContainer = Color(0xFF002A11);

  static const error = Color(0xFFBA1A1A);
  static const onError = Color(0xFFFFFFFF);
  static const errorContainer = Color(0xFFFFDAD6);
  static const onErrorContainer = Color(0xFF93000A);

  static const surface = Color(0xFFFCF9F8);
  static const surfaceDim = Color(0xFFDCD9D8);
  static const surfaceContainerLowest = Color(0xFFFFFFFF);
  static const surfaceContainerLow = Color(0xFFF6F3F2);
  static const surfaceContainer = Color(0xFFF0EDEC);
  static const surfaceContainerHigh = Color(0xFFEAE7E7);
  static const surfaceContainerHighest = Color(0xFFE4E2E1);

  static const onSurface = Color(0xFF1B1C1B);
  static const onSurfaceVariant = Color(0xFF43474E);
  static const inverseSurface = Color(0xFF303030);
  static const inverseOnSurface = Color(0xFFF3F0EF);

  static const outline = Color(0xFF74777F);
  static const outlineVariant = Color(0xFFC3C6CF);

  /// Status colours. Not in the token block, but used across every screen in
  /// the design set, so they are part of the system in practice.
  static const present = Color(0xFF2E7D32);
  static const absent = Color(0xFFC62828);
  static const late = Color(0xFFF5A623);
  static const excused = Color(0xFF74777F);

  static const paid = present;
  static const overdue = absent;
  static const pending = late;
}

/// Corner radii. Cards 8, major sections 16, buttons and chips fully rounded.
class AppRadius {
  const AppRadius._();

  static const double card = 8;
  static const double section = 16;
  static const BorderRadius cardRadius = BorderRadius.all(Radius.circular(card));
  static const BorderRadius sectionRadius =
      BorderRadius.all(Radius.circular(section));
}

/// The 8px linear scale. Every padding, margin and gap is one of these.
class AppSpacing {
  const AppSpacing._();

  static const double xs = 4;
  static const double sm = 8;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;

  /// Safe area kept on every mobile screen.
  static const double screenMargin = 16;

  /// The minimum tap target, enforced even where the visual is smaller.
  static const double tapTarget = 48;
}

/// Text styles named after the design tokens.
///
/// Work Sans and Inter are not bundled — drop the .ttf files into
/// `assets/fonts/` and declare them in pubspec.yaml to switch over. Until then
/// the platform default is used, which on Android (Roboto) sits close enough
/// to Inter that the hierarchy still reads correctly.
class AppText {
  const AppText._();

  static const _heading = null; // 'WorkSans' once bundled
  static const _body = null; // 'Inter' once bundled

  static const headlineLg = TextStyle(
    fontFamily: _heading,
    fontSize: 32,
    fontWeight: FontWeight.w700,
    height: 40 / 32,
    letterSpacing: -0.64,
  );

  /// Dashboard summary numbers. The mobile step-down of [headlineLg].
  static const headlineLgMobile = TextStyle(
    fontFamily: _heading,
    fontSize: 24,
    fontWeight: FontWeight.w700,
    height: 32 / 24,
  );

  static const headlineMd = TextStyle(
    fontFamily: _heading,
    fontSize: 24,
    fontWeight: FontWeight.w600,
    height: 32 / 24,
  );

  static const headlineSm = TextStyle(
    fontFamily: _heading,
    fontSize: 20,
    fontWeight: FontWeight.w600,
    height: 28 / 20,
  );

  static const bodyLg = TextStyle(
    fontFamily: _body,
    fontSize: 16,
    fontWeight: FontWeight.w400,
    height: 24 / 16,
  );

  static const bodyMd = TextStyle(
    fontFamily: _body,
    fontSize: 14,
    fontWeight: FontWeight.w400,
    height: 20 / 14,
  );

  static const labelLg = TextStyle(
    fontFamily: _body,
    fontSize: 14,
    fontWeight: FontWeight.w600,
    height: 20 / 14,
    letterSpacing: 0.14,
  );

  static const labelSm = TextStyle(
    fontFamily: _body,
    fontSize: 12,
    fontWeight: FontWeight.w500,
    height: 16 / 12,
  );

  /// Every score, tally, countdown and Naira amount.
  ///
  /// Tabular figures are the point: columns of numbers that do not align are
  /// the fastest way to make a broadsheet look broken.
  static const numeric = TextStyle(
    fontFamily: _body,
    fontSize: 14,
    fontWeight: FontWeight.w500,
    height: 20 / 14,
    fontFeatures: [FontFeature.tabularFigures()],
  );

  static TextStyle numericAt(double size, {FontWeight? weight, Color? color}) =>
      numeric.copyWith(fontSize: size, fontWeight: weight, color: color);
}

ThemeData buildAppTheme() {
  const scheme = ColorScheme(
    brightness: Brightness.light,
    primary: AppColors.primary,
    onPrimary: AppColors.onPrimary,
    primaryContainer: AppColors.primaryContainer,
    onPrimaryContainer: AppColors.onPrimaryContainer,
    secondary: AppColors.secondary,
    onSecondary: AppColors.onSecondary,
    secondaryContainer: AppColors.secondaryContainer,
    onSecondaryContainer: AppColors.onSecondaryContainer,
    tertiary: AppColors.tertiary,
    onTertiary: Colors.white,
    tertiaryContainer: AppColors.tertiaryContainer,
    onTertiaryContainer: Color(0xFF6A9472),
    error: AppColors.error,
    onError: AppColors.onError,
    errorContainer: AppColors.errorContainer,
    onErrorContainer: AppColors.onErrorContainer,
    surface: AppColors.surface,
    onSurface: AppColors.onSurface,
    onSurfaceVariant: AppColors.onSurfaceVariant,
    outline: AppColors.outline,
    outlineVariant: AppColors.outlineVariant,
    inverseSurface: AppColors.inverseSurface,
    onInverseSurface: AppColors.inverseOnSurface,
    surfaceContainerLowest: AppColors.surfaceContainerLowest,
    surfaceContainerLow: AppColors.surfaceContainerLow,
    surfaceContainer: AppColors.surfaceContainer,
    surfaceContainerHigh: AppColors.surfaceContainerHigh,
    surfaceContainerHighest: AppColors.surfaceContainerHighest,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: AppColors.surface,
    splashFactory: InkRipple.splashFactory,

    // Depth is a 1px outline over a tonal ground. There are no shadows in this
    // system, so every surface that defaults to one is flattened here rather
    // than at each call site.
    appBarTheme: const AppBarTheme(
      backgroundColor: AppColors.surface,
      surfaceTintColor: Colors.transparent,
      foregroundColor: AppColors.onSurface,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: AppText.headlineSm,
    ),
    cardTheme: CardThemeData(
      color: AppColors.surfaceContainerLowest,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: AppRadius.cardRadius,
        side: const BorderSide(color: AppColors.outlineVariant),
      ),
    ),
    dialogTheme: const DialogThemeData(
      backgroundColor: AppColors.surfaceContainerLowest,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: AppColors.surfaceContainerLowest,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      modalElevation: 0,
    ),
    floatingActionButtonTheme: const FloatingActionButtonThemeData(
      backgroundColor: AppColors.action,
      foregroundColor: AppColors.onAction,
      elevation: 0,
      focusElevation: 0,
      hoverElevation: 0,
      highlightElevation: 0,
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: AppColors.surface,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      height: 68,
      indicatorColor: AppColors.action,
      indicatorShape: const StadiumBorder(),
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      labelTextStyle: WidgetStateProperty.resolveWith(
        (states) => AppText.labelSm.copyWith(
          fontWeight: states.contains(WidgetState.selected)
              ? FontWeight.w600
              : FontWeight.w500,
          color: states.contains(WidgetState.selected)
              ? AppColors.primaryContainer
              : AppColors.onSurfaceVariant,
        ),
      ),
      iconTheme: WidgetStateProperty.resolveWith(
        (states) => IconThemeData(
          size: 24,
          color: states.contains(WidgetState.selected)
              ? AppColors.onAction
              : AppColors.onSurfaceVariant,
        ),
      ),
    ),

    // Buttons are pill-shaped and 48dp tall, always.
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: AppColors.action,
        foregroundColor: AppColors.onAction,
        disabledBackgroundColor: AppColors.surfaceContainerHigh,
        disabledForegroundColor: AppColors.outline,
        minimumSize: const Size(0, AppSpacing.tapTarget),
        elevation: 0,
        shape: const StadiumBorder(),
        textStyle: AppText.labelLg.copyWith(fontWeight: FontWeight.w700),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: AppColors.primaryContainer,
        minimumSize: const Size(0, AppSpacing.tapTarget),
        side: const BorderSide(color: AppColors.primaryContainer),
        shape: const StadiumBorder(),
        textStyle: AppText.labelLg,
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        foregroundColor: AppColors.primaryContainer,
        minimumSize: const Size(0, AppSpacing.tapTarget),
        textStyle: AppText.labelLg,
      ),
    ),

    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: AppColors.surfaceContainerLowest,
      contentPadding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.md,
      ),
      border: OutlineInputBorder(
        borderRadius: AppRadius.cardRadius,
        borderSide: const BorderSide(color: AppColors.outlineVariant),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: AppRadius.cardRadius,
        borderSide: const BorderSide(color: AppColors.outlineVariant),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: AppRadius.cardRadius,
        borderSide: const BorderSide(color: AppColors.primaryContainer, width: 2),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: AppRadius.cardRadius,
        borderSide: const BorderSide(color: AppColors.error),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: AppRadius.cardRadius,
        borderSide: const BorderSide(color: AppColors.error, width: 2),
      ),
      labelStyle: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
      floatingLabelStyle:
          AppText.labelSm.copyWith(color: AppColors.primaryContainer),
      helperStyle: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
      errorStyle: AppText.labelSm.copyWith(color: AppColors.error),
    ),

    dividerTheme: const DividerThemeData(
      color: AppColors.outlineVariant,
      thickness: 1,
      space: 1,
    ),
    chipTheme: ChipThemeData(
      backgroundColor: AppColors.surfaceContainer,
      side: BorderSide.none,
      shape: const StadiumBorder(),
      labelStyle: AppText.labelSm,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
    ),
    listTileTheme: const ListTileThemeData(
      minVerticalPadding: 12,
      titleTextStyle: AppText.bodyLg,
      subtitleTextStyle: AppText.bodyMd,
    ),
    snackBarTheme: const SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: AppColors.inverseSurface,
      contentTextStyle: TextStyle(color: AppColors.inverseOnSurface),
      elevation: 0,
    ),
    textTheme: const TextTheme(
      displayLarge: AppText.headlineLg,
      headlineLarge: AppText.headlineLgMobile,
      headlineMedium: AppText.headlineMd,
      headlineSmall: AppText.headlineSm,
      titleLarge: AppText.headlineSm,
      bodyLarge: AppText.bodyLg,
      bodyMedium: AppText.bodyMd,
      labelLarge: AppText.labelLg,
      labelSmall: AppText.labelSm,
    ),
  );
}
