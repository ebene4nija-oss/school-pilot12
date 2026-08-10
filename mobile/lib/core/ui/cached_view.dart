import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../data/cached.dart';
import 'states.dart';

/// Renders the four states of a cached endpoint so no screen has to.
///
/// Loading, error and content are obvious. The fourth — content that came off
/// disk because the network was unreachable — is the one screens forget, so it
/// is handled here: the data renders, with the offline banner above it.
class CachedView<T> extends StatelessWidget {
  const CachedView({
    super.key,
    required this.value,
    required this.builder,
    required this.onRetry,
    this.loadingRows = 4,
  });

  final AsyncValue<Cached<T>> value;
  final Widget Function(BuildContext context, T data, Cached<T> meta) builder;
  final VoidCallback onRetry;
  final int loadingRows;

  @override
  Widget build(BuildContext context) {
    return value.when(
      loading: () => LoadingState(rows: loadingRows),
      error: (error, _) => ErrorState(
        error: asApiException(error),
        onRetry: onRetry,
      ),
      data: (cached) {
        final content = builder(context, cached.data, cached);
        if (!cached.stale) return content;

        return Column(
          children: [
            OfflineBanner(fetchedAt: cached.fetchedAt, onRetry: onRetry),
            Expanded(child: content),
          ],
        );
      },
    );
  }
}

/// Pull-to-refresh wrapper that always leaves something scrollable, so the
/// gesture works on a short or empty list too.
class RefreshableList extends StatelessWidget {
  const RefreshableList({
    super.key,
    required this.onRefresh,
    required this.children,
    this.padding,
  });

  final Future<void> Function() onRefresh;
  final List<Widget> children;
  final EdgeInsets? padding;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: padding ?? const EdgeInsets.only(bottom: 32),
        children: children,
      ),
    );
  }
}
