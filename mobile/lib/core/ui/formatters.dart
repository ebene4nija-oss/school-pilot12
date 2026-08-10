import 'package:intl/intl.dart';

/// Naira. Every amount in the app goes through here.
///
/// Two decimals and thousands separators, always — `₦45,000.00`, never
/// `45000` and never a bare number. Nigeria is the only currency this product
/// handles, so there is no locale switch to get wrong.
class Money {
  const Money._();

  static final _full = NumberFormat.currency(
    locale: 'en_NG',
    symbol: '₦',
    decimalDigits: 2,
  );

  static final _compactWhole = NumberFormat.currency(
    locale: 'en_NG',
    symbol: '₦',
    decimalDigits: 0,
  );

  static String format(num? amount) => _full.format(amount ?? 0);

  /// For dashboard tiles where the kobo is noise and the column is narrow.
  static String whole(num? amount) => _compactWhole.format(amount ?? 0);

  static num parse(Object? value) {
    if (value == null) return 0;
    if (value is num) return value;
    return num.tryParse(value.toString()) ?? 0;
  }
}

/// Dates and times, in West Africa Time.
///
/// Nigeria is UTC+1 with no daylight saving, so there is exactly one offset to
/// think about. The wire is ISO 8601; the UI is never ISO.
class Dates {
  const Dates._();

  static final _dayMonth = DateFormat('EEE d MMM');
  static final _dayMonthYear = DateFormat('d MMM yyyy');
  static final _time = DateFormat('HH:mm');
  static final _dayAndTime = DateFormat('EEE d MMM, HH:mm');
  static final _iso = DateFormat('yyyy-MM-dd');

  static DateTime? tryParse(Object? value) {
    if (value == null) return null;
    if (value is DateTime) return value.toLocal();
    return DateTime.tryParse(value.toString())?.toLocal();
  }

  static String dayMonth(DateTime? d) => d == null ? '—' : _dayMonth.format(d);
  static String full(DateTime? d) => d == null ? '—' : _dayMonthYear.format(d);
  static String time(DateTime? d) => d == null ? '—' : _time.format(d);
  static String dayAndTime(DateTime? d) =>
      d == null ? '—' : _dayAndTime.format(d);

  /// The `date` field the attendance endpoints expect.
  static String apiDate(DateTime d) => _iso.format(d);

  /// "Updated 14:32" / "Updated Mon 9 Aug" — what every cached screen shows so
  /// the user can judge the data for themselves.
  static String freshness(DateTime? fetchedAt) {
    if (fetchedAt == null) return 'Not yet loaded';
    final now = DateTime.now();
    final sameDay = fetchedAt.year == now.year &&
        fetchedAt.month == now.month &&
        fetchedAt.day == now.day;
    return sameDay
        ? 'Updated ${_time.format(fetchedAt)}'
        : 'Updated ${_dayMonth.format(fetchedAt)}';
  }

  /// Countdown for the CBT timer. The display only — the server owns the clock.
  static String countdown(Duration remaining) {
    if (remaining.isNegative) return '00:00';
    final h = remaining.inHours;
    final m = remaining.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = remaining.inSeconds.remainder(60).toString().padLeft(2, '0');
    return h > 0 ? '$h:$m:$s' : '$m:$s';
  }
}

/// Nigerian academic vocabulary. Never GPA, semesters, credits or faculties.
class Academic {
  const Academic._();

  static String term(Object? value) {
    final raw = value?.toString().toLowerCase() ?? '';
    if (raw.contains('1') || raw.contains('first')) return 'First Term';
    if (raw.contains('2') || raw.contains('second')) return 'Second Term';
    if (raw.contains('3') || raw.contains('third')) return 'Third Term';
    return value?.toString() ?? '—';
  }

  /// WAEC-style grade bands, used for the chip on a result row.
  static String grade(num score) {
    if (score >= 75) return 'A1';
    if (score >= 70) return 'B2';
    if (score >= 65) return 'B3';
    if (score >= 60) return 'C4';
    if (score >= 55) return 'C5';
    if (score >= 50) return 'C6';
    if (score >= 45) return 'D7';
    if (score >= 40) return 'E8';
    return 'F9';
  }

  static String ordinal(int n) {
    if (n <= 0) return '—';
    if (n % 100 >= 11 && n % 100 <= 13) return '${n}th';
    return switch (n % 10) {
      1 => '${n}st',
      2 => '${n}nd',
      3 => '${n}rd',
      _ => '${n}th',
    };
  }
}
