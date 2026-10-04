import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../app/theme.dart';

/// Reads one barcode or QR code with the phone's camera and returns its text.
///
/// The phone is the scanner: no scanner gun or reader is ever needed
/// (CLAUDE.md, doc §3). Returns null if the person backs out.
Future<String?> scanBarcode(BuildContext context, {String title = 'Scan'}) {
  return Navigator.of(context).push<String>(
    MaterialPageRoute(builder: (_) => _ScanScreen(title: title)),
  );
}

class _ScanScreen extends StatefulWidget {
  const _ScanScreen({required this.title});

  final String title;

  @override
  State<_ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<_ScanScreen> {
  final _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
  );
  bool _done = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_done) return;
    final value = capture.barcodes
        .map((b) => b.rawValue)
        .firstWhere((v) => v != null && v.trim().isNotEmpty, orElse: () => null);
    if (value == null) return;
    _done = true;
    Navigator.of(context).pop(value.trim());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        actions: [
          IconButton(
            tooltip: 'Torch',
            icon: const Icon(Icons.flashlight_on_outlined),
            onPressed: () => _controller.toggleTorch(),
          ),
        ],
      ),
      body: Stack(
        children: [
          MobileScanner(controller: _controller, onDetect: _onDetect),
          Align(
            alignment: Alignment.bottomCenter,
            child: Container(
              width: double.infinity,
              color: Colors.black54,
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Text(
                'Hold the barcode inside the frame. It reads on its own.',
                textAlign: TextAlign.center,
                style: AppText.bodyMd.copyWith(color: Colors.white),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
