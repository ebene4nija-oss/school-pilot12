import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';
import '../../core/providers.dart';
import '../../core/ui/cached_view.dart';
import '../../core/ui/formatters.dart';
import '../../core/ui/states.dart';
import '../../core/ui/widgets.dart';

/// Not cached to disk: a card reported lost must read as lost at once, never
/// as yesterday's "valid" copy.
final myIdCardProvider = FutureProvider.autoDispose<Cached<dynamic>>((ref) async {
  try {
    final data = await ref.watch(apiClientProvider).get<dynamic>(Api.myIdCard);
    return Cached(data: data, fetchedAt: DateTime.now());
  } catch (error) {
    // 404 is the server's answer when the school has digital cards switched
    // off: a fact to state, not a failure to retry.
    final failure = asApiException(error);
    if (!failure.isNotFound) rethrow;
    return Cached(data: {'card': null, 'unavailable': failure.message}, fetchedAt: DateTime.now());
  }
});

class IdCardScreen extends ConsumerWidget {
  const IdCardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('My ID card')),
      body: CachedView<dynamic>(
        value: ref.watch(myIdCardProvider),
        onRetry: () => ref.invalidate(myIdCardProvider),
        builder: (context, data, meta) {
          final card = mapOf(data)['card'];
          final unavailable = stringOf(mapOf(data)['unavailable']);
          if (unavailable.isNotEmpty) {
            return EmptyState(
              icon: Icons.badge_outlined,
              title: 'No digital ID card',
              message: '$unavailable Ask the school office if you need one.',
            );
          }
          if (card is! Map) {
            return const EmptyState(
              icon: Icons.badge_outlined,
              title: 'No card yet',
              message: 'Your card appears here once the school issues it.',
            );
          }
          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenMargin),
            children: [DigitalIdCard(card: card.cast<String, dynamic>())],
          );
        },
      ),
    );
  }
}

/// The card as the server describes it now: holder, status, and a scannable
/// code only while the card is live.
class DigitalIdCard extends StatelessWidget {
  const DigitalIdCard({super.key, required this.card});

  final Map<String, dynamic> card;

  @override
  Widget build(BuildContext context) {
    final valid = card['valid'] == true;
    final photo = _image(card['photo']);
    final qr = _image(card['qr']);

    return OutlinedCard(
      statusColor: valid ? AppColors.present : AppColors.absent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(stringOf(card['school']), style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant)),
          const SizedBox(height: AppSpacing.sm),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (photo != null)
                ClipRRect(
                  borderRadius: AppRadius.cardRadius,
                  child: Image.memory(photo, width: 80, height: 100, fit: BoxFit.cover),
                )
              else
                InitialsAvatar(stringOf(card['holder_name'], '?'), size: 64),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(stringOf(card['holder_name']), style: AppText.headlineSm),
                    Text(stringOf(card['role']), style: AppText.bodyMd),
                    const SizedBox(height: AppSpacing.xs),
                    Text(stringOf(card['serial']), style: AppText.labelSm),
                    if (card['expires_on'] != null)
                      Text(
                        'Valid to ${Dates.full(Dates.tryParse(card['expires_on']))}',
                        style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
                      ),
                    const SizedBox(height: AppSpacing.sm),
                    valid
                        ? const StatusChip.present('Valid')
                        : StatusChip.absent(stringOf(card['headline'], 'Not valid')),
                  ],
                ),
              ),
            ],
          ),
          if (qr != null) ...[
            const SizedBox(height: AppSpacing.md),
            Center(child: Image.memory(qr, width: 160, height: 160, filterQuality: FilterQuality.none)),
            Center(
              child: Text(
                'Security staff scan this to check the card.',
                style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// `data:image/png;base64,...` from the API.
  static Uint8List? _image(Object? value) {
    final s = stringOf(value);
    final comma = s.indexOf(',');
    if (!s.startsWith('data:image') || comma < 0) return null;
    try {
      return base64Decode(s.substring(comma + 1));
    } catch (_) {
      return null;
    }
  }
}
