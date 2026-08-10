import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_math_fork/flutter_math.dart';
import 'package:flutter_widget_from_html_core/flutter_widget_from_html_core.dart';

import '../../app/theme.dart';

/// Renders what the CBT authoring side can actually produce: sanitised HTML,
/// referenced images from the media library, and LaTeX.
///
/// The design mock printed the LaTeX source verbatim because Stitch has no
/// maths renderer. Here it is rendered — a candidate cannot answer a question
/// whose formula is showing as backslashes.
class QuestionContent extends StatelessWidget {
  const QuestionContent({
    super.key,
    required this.text,
    this.imageUrls = const [],
    this.style,
  });

  final String text;
  final List<String> imageUrls;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _RichBody(text: text, style: style ?? AppText.bodyLg),
        for (final url in imageUrls)
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.md),
            child: Container(
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.outlineVariant),
                borderRadius: AppRadius.cardRadius,
              ),
              clipBehavior: Clip.antiAlias,
              child: CachedNetworkImage(
                imageUrl: url,
                fit: BoxFit.contain,
                // Pre-fetched at attempt start, so a mid-paper signal drop
                // does not blank a diagram.
                placeholder: (_, __) => const SizedBox(
                  height: 160,
                  child: Center(child: CircularProgressIndicator()),
                ),
                errorWidget: (_, __, ___) => const SizedBox(
                  height: 120,
                  child: Center(
                    child: Text('Image unavailable offline'),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// Splits a string into plain runs and LaTeX runs, then renders each.
///
/// The backend's `MathContentService` emits `\( … \)` for inline maths and
/// `$$ … $$` for display maths, so both delimiters are honoured.
class _RichBody extends StatelessWidget {
  const _RichBody({required this.text, required this.style});

  final String text;
  final TextStyle style;

  static final _pattern = RegExp(
    r'(\\\(.+?\\\)|\$\$.+?\$\$|\\\[.+?\\\])',
    dotAll: true,
  );

  @override
  Widget build(BuildContext context) {
    final looksLikeHtml = text.contains('<') && text.contains('>');

    if (!_pattern.hasMatch(text)) {
      return looksLikeHtml
          ? HtmlWidget(text, textStyle: style)
          : Text(text, style: style);
    }

    final spans = <InlineSpan>[];
    var index = 0;

    for (final match in _pattern.allMatches(text)) {
      if (match.start > index) {
        spans.add(TextSpan(text: text.substring(index, match.start)));
      }

      final raw = match.group(0)!;
      final expression = raw
          .replaceAll(RegExp(r'^\\\(|\\\)$'), '')
          .replaceAll(RegExp(r'^\$\$|\$\$$'), '')
          .replaceAll(RegExp(r'^\\\[|\\\]$'), '')
          .trim();

      spans.add(
        WidgetSpan(
          alignment: PlaceholderAlignment.middle,
          child: Math.tex(
            expression,
            textStyle: style,
            // A malformed expression must not take the whole paper down with
            // it — the candidate sees the source and can still answer.
            onErrorFallback: (_) => Text(expression, style: style),
          ),
        ),
      );

      index = match.end;
    }

    if (index < text.length) {
      spans.add(TextSpan(text: text.substring(index)));
    }

    return Text.rich(TextSpan(style: style, children: spans));
  }
}
