import 'package:flutter_test/flutter_test.dart';
import 'package:schoolpilot/features/finance/gateway_checkout.dart';

/// When the checkout webview is allowed to close.
///
/// This predicate is small and the failure it prevents is not: close on the
/// wrong URL and every card that asks for 3-D Secure dies mid-verification,
/// after the parent has been debited but before the gateway has been told the
/// payment stands. Nothing else in the app would report that as a bug — the
/// window would just shut and the balance would stay put.
void main() {
  group('isCheckoutReturnUrl', () {
    test('closes on the server return page', () {
      expect(
        isCheckoutReturnUrl('https://graceland.schoolpilot.ng/api/v1/payments/return'),
        isTrue,
      );
    });

    test('closes whatever query the gateway appends to it', () {
      // Paystack sends back `?trxref=…&reference=…`; Flutterwave sends its own
      // set. Matching the whole URL rather than the path would miss both.
      expect(
        isCheckoutReturnUrl(
          'https://graceland.schoolpilot.ng/api/v1/payments/return'
          '?trxref=SPFP_20260811_ABCD1234&reference=SPFP_20260811_ABCD1234',
        ),
        isTrue,
      );
    });

    test('closes on any school host, since each tenant has its own', () {
      expect(
        isCheckoutReturnUrl('http://10.0.2.2:8000/api/v1/payments/return'),
        isTrue,
      );
    });

    test('stays open on the gateway itself', () {
      expect(
        isCheckoutReturnUrl('https://checkout.paystack.com/abc123'),
        isFalse,
      );
      expect(
        isCheckoutReturnUrl('https://checkout.flutterwave.com/v3/hosted/pay/x'),
        isFalse,
      );
    });

    /// The regression this test exists for. A "close on an unfamiliar host"
    /// rule would fire here — in the middle of a card verification, on a domain
    /// belonging to neither us nor the gateway.
    test('stays open on a bank 3-D Secure page mid-payment', () {
      expect(
        isCheckoutReturnUrl('https://3dsecure.gtbank.com/acs/challenge?id=99'),
        isFalse,
      );
    });

    test('stays open on another page of our own API', () {
      expect(
        isCheckoutReturnUrl('https://graceland.schoolpilot.ng/api/v1/health'),
        isFalse,
      );
    });

    test('does not throw on whatever a webview hands it', () {
      expect(isCheckoutReturnUrl(''), isFalse);
      expect(isCheckoutReturnUrl('about:blank'), isFalse);
      expect(isCheckoutReturnUrl('intent://pay#Intent;scheme=upi;end'), isFalse);
    });
  });
}
