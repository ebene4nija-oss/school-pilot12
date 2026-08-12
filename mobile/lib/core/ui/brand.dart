import 'package:flutter/material.dart';

/// The SchoolPilot logo, in the two inks the brand has.
///
/// Every asset here is a PNG derived from the master lockup. Sized by height
/// only — the width follows the artwork so the wordmark never distorts.
class BrandLogo extends StatelessWidget {
  const BrandLogo({super.key, this.height = 32, this.onDark = false});

  /// White ink for navy surfaces, navy ink for light ones. The amber arrow is
  /// in both.
  final bool onDark;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Image.asset(
      onDark ? 'assets/brand/logo-white.png' : 'assets/brand/logo.png',
      height: height,
      fit: BoxFit.contain,
      // Decorative: the wordmark is already legible as an image, and every
      // screen that uses it names the app in its own copy.
      excludeFromSemantics: true,
    );
  }
}

/// The emblem on its own — for app bars and other places too tight for the
/// full lockup.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 32, this.onDark = false});

  final bool onDark;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Image.asset(
      onDark ? 'assets/brand/mark-white.png' : 'assets/brand/mark.png',
      height: size,
      width: size,
      fit: BoxFit.contain,
      excludeFromSemantics: true,
    );
  }
}
