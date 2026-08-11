import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../../app/theme.dart';
import '../../core/api/endpoints.dart';

/// Whether a URL the webview is about to open means checkout is over.
///
/// Deliberately *not* "any host that isn't the gateway's". A card that asks for
/// 3-D Secure sends the payer through their own bank's domain in the middle of
/// the payment, so closing on an unfamiliar host would abandon the checkout at
/// the exact moment the parent was verifying it. Only the server's own return
/// page counts, matched on path so it works against any school's host and
/// survives the `?trxref=…` the gateway appends.
///
/// Top-level and visible for testing because this one predicate is the whole
/// difference between a payment flow that works and one that dies silently on
/// every 3-D Secure card.
@visibleForTesting
bool isCheckoutReturnUrl(String url) =>
    Uri.tryParse(url)?.path == Api.paymentReturn;

/// The school's hosted gateway checkout, in a webview.
///
/// This is the app's entire payment integration. The server opens the checkout
/// on the school's own merchant account and hands back an `authorization_url`;
/// the app opens it and watches for the end. No key of any kind is compiled
/// into the binary, no card field is ever rendered by us, and nothing here
/// decides whether a payment succeeded — only the gateway's signed callback to
/// the server does that.
///
/// Returns `true` when checkout ran to its end, which means *the flow finished*
/// and the caller should re-ask the server, not that money moved.
class GatewayCheckout {
  const GatewayCheckout._();

  static Future<bool> open(
    BuildContext context, {
    required String url,
    required String title,
  }) async {
    final finished = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => _CheckoutScreen(url: url, title: title),
      ),
    );

    return finished ?? false;
  }
}

class _CheckoutScreen extends StatefulWidget {
  const _CheckoutScreen({required this.url, required this.title});

  final String url;
  final String title;

  @override
  State<_CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<_CheckoutScreen> {
  late final WebViewController _controller;
  double _progress = 0;
  bool _closing = false;

  @override
  void initState() {
    super.initState();

    _controller = WebViewController()
      // Every hosted checkout is a JavaScript application; without this the
      // page renders as a blank white rectangle and looks like a crash.
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onProgress: (value) {
            if (mounted) setState(() => _progress = value / 100);
          },
          onNavigationRequest: (request) {
            if (isCheckoutReturnUrl(request.url)) {
              _finish();
              return NavigationDecision.prevent;
            }
            return NavigationDecision.navigate;
          },
          // Some gateways reach the callback by a redirect the delegate above
          // does not see as a request, so the arrival is checked twice.
          onPageStarted: (url) {
            if (isCheckoutReturnUrl(url)) _finish();
          },
        ),
      )
      ..loadRequest(Uri.parse(widget.url));
  }

  void _finish() {
    if (_closing) return;
    _closing = true;
    if (mounted) Navigator.of(context).pop(true);
  }

  /// Leaving early is not the same as finishing.
  ///
  /// The caller still refreshes on `false` — a payer can complete a transfer
  /// and then background the app rather than wait for the redirect — but it is
  /// the difference between "confirming your payment" and "no payment started".
  Future<void> _abandon() async {
    final leave = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Leave payment?'),
        content: const Text(
          'If you have already paid, your school will still receive it and '
          'your receipt will appear shortly.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Keep paying'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Leave'),
          ),
        ],
      ),
    );

    if (leave == true && mounted) Navigator.of(context).pop(false);
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && !_closing) _abandon();
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(widget.title),
          leading: IconButton(
            icon: const Icon(Icons.close),
            onPressed: _abandon,
            tooltip: 'Leave payment',
          ),
          bottom: _progress >= 1
              ? null
              : PreferredSize(
                  preferredSize: const Size.fromHeight(2),
                  child: LinearProgressIndicator(
                    value: _progress,
                    minHeight: 2,
                    backgroundColor: AppColors.outlineVariant,
                  ),
                ),
        ),
        body: WebViewWidget(controller: _controller),
      ),
    );
  }
}
