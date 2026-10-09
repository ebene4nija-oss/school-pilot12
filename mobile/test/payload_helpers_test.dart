import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/core/data/cached.dart';

void main() {
  test('a relation sent as a plain string is read, not thrown on', () {
    // StudentResource sends `class` as "JSS 1"; `row['class']?['name']`
    // threw on it and blanked the Students and Fees screens.
    final row = <String, dynamic>{'class': 'JSS 1', 'term': 'First Term'};

    expect(nameOf(row['class']), 'JSS 1');
    expect(fieldOf(row['class'], 'id'), isNull);
  });

  test('a relation sent as an object still reads its name', () {
    final row = <String, dynamic>{
      'class': {'id': 4, 'name': 'SS 2'},
    };

    expect(nameOf(row['class']), 'SS 2');
    expect(fieldOf(row['class'], 'id'), 4);
    expect(nameOf(row['missing']), isNull);
  });
}
