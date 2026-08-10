import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/ui/formatters.dart';

void main() {
  group('Money', () {
    test('always carries the Naira sign, separators and two decimals', () {
      expect(Money.format(45000), '₦45,000.00');
      expect(Money.format(1200.5), '₦1,200.50');
      expect(Money.format(0), '₦0.00');
      expect(Money.format(null), '₦0.00');
    });

    test('never emits a dollar sign', () {
      expect(Money.format(1250), isNot(contains(r'$')));
    });

    test('parses the strings Laravel returns for decimal columns', () {
      expect(Money.parse('45000.00'), 45000);
      expect(Money.parse(null), 0);
    });
  });

  group('Academic', () {
    test('uses Nigerian term names, never "Term 2"', () {
      expect(Academic.term(2), 'Second Term');
      expect(Academic.term('second'), 'Second Term');
      expect(Academic.term('Term 3'), 'Third Term');
    });

    test('grades on WAEC bands', () {
      expect(Academic.grade(78), 'A1');
      expect(Academic.grade(72), 'B2');
      expect(Academic.grade(64), 'C4');
      expect(Academic.grade(39), 'F9');
    });

    test('ordinals read the way a position is spoken', () {
      expect(Academic.ordinal(1), '1st');
      expect(Academic.ordinal(2), '2nd');
      expect(Academic.ordinal(3), '3rd');
      expect(Academic.ordinal(6), '6th');
      expect(Academic.ordinal(11), '11th');
      expect(Academic.ordinal(21), '21st');
    });
  });

  group('Dates', () {
    test('countdown pads to a stable width so the timer does not jump', () {
      expect(Dates.countdown(const Duration(minutes: 18, seconds: 42)), '18:42');
      expect(Dates.countdown(const Duration(seconds: 5)), '00:05');
      expect(Dates.countdown(const Duration(hours: 1, minutes: 2, seconds: 3)),
          '1:02:03');
    });

    test('a finished timer shows zero rather than a negative', () {
      expect(Dates.countdown(const Duration(seconds: -30)), '00:00');
    });

    test('freshness distinguishes today from earlier', () {
      final now = DateTime.now();
      expect(Dates.freshness(now), startsWith('Updated '));
      expect(Dates.freshness(null), 'Not yet loaded');
    });
  });
}
