import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_math_fork/flutter_math.dart';
import 'package:flutter_widget_from_html_core/flutter_widget_from_html_core.dart';

import '../../app/theme.dart';
import 'cbt_paper.dart';

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
    this.media = const [],
    this.style,
  });

  final String text;
  final List<CbtMedia> media;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (text.isNotEmpty) _RichBody(text: text, style: style ?? AppText.bodyLg),
        for (final asset in media)
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.md),
            child: Container(
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.outlineVariant),
                borderRadius: AppRadius.cardRadius,
              ),
              clipBehavior: Clip.antiAlias,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  QuestionImage(media: asset),
                  if (asset.caption != null)
                    Padding(
                      padding: const EdgeInsets.all(AppSpacing.sm),
                      child: Text(
                        asset.caption!,
                        style: AppText.labelSm
                            .copyWith(color: AppColors.onSurfaceVariant),
                      ),
                    ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

/// A single image with its alt text, cached so a mid-paper signal drop does
/// not blank a diagram.
class QuestionImage extends StatelessWidget {
  const QuestionImage({
    super.key,
    required this.media,
    this.fit = BoxFit.contain,
    this.maxHeight,
  });

  final CbtMedia media;
  final BoxFit fit;
  final double? maxHeight;

  @override
  Widget build(BuildContext context) {
    final image = CachedNetworkImage(
      imageUrl: media.url,
      fit: fit,
      // Pre-fetched at attempt start, so a mid-paper signal drop does not
      // blank a diagram.
      placeholder: (_, __) => const SizedBox(
        height: 160,
        child: Center(child: CircularProgressIndicator()),
      ),
      // Alt text is the fallback, not a generic apology: a candidate who
      // cannot see the diagram can sometimes still answer from its
      // description.
      errorWidget: (_, __, ___) => SizedBox(
        height: 120,
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.sm),
            child: Text(
              media.altText ?? 'Image unavailable offline',
              style: AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              textAlign: TextAlign.center,
            ),
          ),
        ),
      ),
    );

    return Semantics(
      label: media.altText,
      image: true,
      child: maxHeight == null
          ? image
          : ConstrainedBox(
              constraints: BoxConstraints(maxHeight: maxHeight!),
              child: image,
            ),
    );
  }
}

/// The shared passage, table or diagram a set of sub-questions hangs off.
///
/// Shown in full above the first sub-question of the group, then collapsed on
/// the rest — a candidate on question 5 of a comprehension still needs the
/// passage, but not thirty lines of it between them and the answer boxes.
class GroupStimulusCard extends StatefulWidget {
  const GroupStimulusCard({super.key, required this.group});

  final CbtGroup group;

  @override
  State<GroupStimulusCard> createState() => _GroupStimulusCardState();
}

class _GroupStimulusCardState extends State<GroupStimulusCard> {
  late bool _expanded = widget.group.isFirstOfGroup;

  @override
  void didUpdateWidget(GroupStimulusCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Moving to a different passage re-opens it; moving between sub-questions
    // of the same passage leaves the candidate's own choice alone.
    if (oldWidget.group.groupId != widget.group.groupId) {
      _expanded = widget.group.isFirstOfGroup;
    }
  }

  @override
  Widget build(BuildContext context) {
    final group = widget.group;

    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: AppRadius.sectionRadius,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InkWell(
            onTap: () => setState(() => _expanded = !_expanded),
            borderRadius: AppRadius.sectionRadius,
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          group.title ?? 'Read the passage below',
                          style: AppText.labelLg,
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Question ${group.position} of ${group.of} '
                          'on this passage',
                          style: AppText.labelSm
                              .copyWith(color: AppColors.onSurfaceVariant),
                        ),
                      ],
                    ),
                  ),
                  Icon(
                    _expanded ? Icons.expand_less : Icons.expand_more,
                    color: AppColors.onSurfaceVariant,
                  ),
                ],
              ),
            ),
          ),
          if (_expanded)
            Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.md,
                0,
                AppSpacing.md,
                AppSpacing.md,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (group.instructions != null) ...[
                    Text(
                      group.instructions!,
                      style: AppText.bodyMd.copyWith(
                        color: AppColors.onSurfaceVariant,
                        fontStyle: FontStyle.italic,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                  ],
                  QuestionContent(
                    text: group.stimulus,
                    media: group.media,
                  ),
                ],
              ),
            )
          else
            Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.md,
                0,
                AppSpacing.md,
                AppSpacing.md,
              ),
              child: Row(
                children: [
                  const Icon(
                    Icons.article_outlined,
                    size: 16,
                    color: AppColors.outline,
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Text(
                      'Tap to read it again',
                      style: AppText.labelSm
                          .copyWith(color: AppColors.onSurfaceVariant),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
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
