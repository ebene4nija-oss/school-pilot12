import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/theme.dart';
import '../../core/ui/widgets.dart';
import 'cbt_paper.dart';
import 'question_content.dart';

/// The input for one question, chosen by its type.
///
/// The screen used to render a single text box for anything that had no
/// options, which meant a sequencing question, a matching question and a
/// diagram-labelling question all arrived as "type your answer here" — and
/// were then marked against a response shape none of them could produce.
/// Every branch here emits the envelope its own grader reads, via
/// [CbtResponse].
class CbtAnswerInput extends StatelessWidget {
  const CbtAnswerInput({
    super.key,
    required this.question,
    required this.response,
    required this.onChanged,
  });

  final CbtQuestion question;
  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    switch (question.type) {
      case 'true_false':
        return _TrueFalseInput(response: response, onChanged: onChanged);

      case 'multiple_response':
        return _MultipleResponseInput(
          options: question.options,
          response: response,
          onChanged: onChanged,
        );

      case 'ordering':
        return _OrderingInput(
          items: question.interaction.items,
          response: response,
          onChanged: onChanged,
        );

      case 'matching':
        return _MatchingInput(
          interaction: question.interaction,
          response: response,
          onChanged: onChanged,
        );

      case 'numeric':
        return _NumericInput(
          key: ValueKey('numeric-${question.questionId}'),
          interaction: question.interaction,
          response: response,
          onChanged: onChanged,
        );

      case 'diagram_label':
        return _DiagramLabelInput(
          question: question,
          response: response,
          onChanged: onChanged,
        );

      case 'hotspot':
        return _HotspotInput(
          question: question,
          response: response,
          onChanged: onChanged,
        );

      case 'theory':
        return _TheoryInput(
          key: ValueKey('theory-${question.questionId}'),
          interaction: question.interaction,
          response: response,
          onChanged: onChanged,
        );

      case 'fill_blank':
        return _FreeTextInput(
          key: ValueKey('fill-${question.questionId}'),
          response: response,
          onChanged: onChanged,
          label: 'Your answer',
          maxLines: 2,
        );

      // multiple_choice, image_choice, and anything a newer backend adds that
      // still ships a list of options.
      default:
        if (question.options.isEmpty) {
          return _FreeTextInput(
            key: ValueKey('free-${question.questionId}'),
            response: response,
            onChanged: onChanged,
            label: 'Your answer',
            maxLines: 6,
          );
        }
        return _SingleChoiceInput(
          options: question.options,
          response: response,
          onChanged: onChanged,
        );
    }
  }
}

// ---------------------------------------------------------------------------
// Choice inputs
// ---------------------------------------------------------------------------

class _SingleChoiceInput extends StatelessWidget {
  const _SingleChoiceInput({
    required this.options,
    required this.response,
    required this.onChanged,
  });

  final List<CbtOption> options;
  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    final selected = CbtResponse.selectedChoice(response);

    return Column(
      children: [
        for (final option in options)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.sm),
            child: ChoiceCard(
              optionKey: option.key,
              text: option.text,
              image: option.image,
              selected: selected == option.key,
              multiple: false,
              onTap: () => onChanged(CbtResponse.choice(option.key)),
            ),
          ),
      ],
    );
  }
}

class _MultipleResponseInput extends StatelessWidget {
  const _MultipleResponseInput({
    required this.options,
    required this.response,
    required this.onChanged,
  });

  final List<CbtOption> options;
  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    final selected = CbtResponse.selectedChoices(response);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: Text(
            'Choose all that apply.',
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
        ),
        for (final option in options)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.sm),
            child: ChoiceCard(
              optionKey: option.key,
              text: option.text,
              image: option.image,
              selected: selected.contains(option.key),
              multiple: true,
              onTap: () {
                final next = Set<String>.from(selected);
                if (!next.remove(option.key)) next.add(option.key);
                // Order the keys the way the paper presents them, so a
                // resumed attempt redraws in a stable order.
                final ordered = [
                  for (final o in options)
                    if (next.contains(o.key)) o.key,
                ];
                onChanged(CbtResponse.choices(ordered));
              },
            ),
          ),
      ],
    );
  }
}

class _TrueFalseInput extends StatelessWidget {
  const _TrueFalseInput({required this.response, required this.onChanged});

  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    final selected = CbtResponse.selectedBoolean(response);

    return Column(
      children: [
        for (final value in [true, false])
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.sm),
            child: ChoiceCard(
              optionKey: value ? 'T' : 'F',
              text: value ? 'True' : 'False',
              selected: selected == value,
              multiple: false,
              onTap: () => onChanged(CbtResponse.boolean(value)),
            ),
          ),
      ],
    );
  }
}

/// A tappable option row. Radio for single-answer, checkbox for multi-answer,
/// with the option letter shown because candidates read papers by letter.
class ChoiceCard extends StatelessWidget {
  const ChoiceCard({
    super.key,
    required this.optionKey,
    required this.text,
    required this.selected,
    required this.multiple,
    required this.onTap,
    this.image,
  });

  final String optionKey;
  final String text;
  final bool selected;
  final bool multiple;
  final VoidCallback onTap;
  final CbtMedia? image;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected
          ? AppColors.primaryContainer.withValues(alpha: 0.08)
          : AppColors.surfaceContainerLowest,
      borderRadius: AppRadius.cardRadius,
      child: InkWell(
        onTap: onTap,
        borderRadius: AppRadius.cardRadius,
        child: Container(
          constraints: const BoxConstraints(minHeight: AppSpacing.tapTarget + 8),
          padding: const EdgeInsets.all(AppSpacing.md),
          decoration: BoxDecoration(
            borderRadius: AppRadius.cardRadius,
            border: Border.all(
              color:
                  selected ? AppColors.primaryContainer : AppColors.outlineVariant,
              width: selected ? 2 : 1,
            ),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                multiple
                    ? (selected ? Icons.check_box : Icons.check_box_outline_blank)
                    : (selected
                        ? Icons.radio_button_checked
                        : Icons.radio_button_off),
                color:
                    selected ? AppColors.primaryContainer : AppColors.outline,
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (optionKey.isNotEmpty) ...[
                          Text(
                            '$optionKey.',
                            style: AppText.labelLg.copyWith(
                              color: AppColors.onSurfaceVariant,
                            ),
                          ),
                          const SizedBox(width: AppSpacing.sm),
                        ],
                        Expanded(child: QuestionContent(text: text)),
                      ],
                    ),
                    if (image != null) ...[
                      const SizedBox(height: AppSpacing.sm),
                      QuestionImage(media: image!, maxHeight: 160),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Ordering
// ---------------------------------------------------------------------------

class _OrderingInput extends StatelessWidget {
  const _OrderingInput({
    required this.items,
    required this.response,
    required this.onChanged,
  });

  final List<String> items;
  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return const _MissingInteractionData(what: 'items to arrange');
    }

    // The saved order wins on resume; anything the author has since added is
    // appended rather than dropped, and a stale item is discarded.
    final saved = CbtResponse.enteredOrder(response);
    final current = <String>[
      for (final item in saved)
        if (items.contains(item)) item,
    ];
    for (final item in items) {
      if (!current.contains(item)) current.add(item);
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: Text(
            'Drag the handle to arrange these in order.',
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
        ),
        ReorderableListView(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          buildDefaultDragHandles: false,
          onReorder: (oldIndex, newIndex) {
            final next = List<String>.from(current);
            if (newIndex > oldIndex) newIndex -= 1;
            next.insert(newIndex, next.removeAt(oldIndex));
            onChanged(CbtResponse.order(next));
          },
          children: [
            for (final (index, item) in current.indexed)
              Padding(
                key: ValueKey(item),
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: OutlinedCard(
                  child: Row(
                    children: [
                      SizedBox(
                        width: 28,
                        child: NumericText(
                          '${index + 1}',
                          weight: FontWeight.w700,
                          color: AppColors.primaryContainer,
                        ),
                      ),
                      Expanded(child: QuestionContent(text: item)),
                      ReorderableDragStartListener(
                        index: index,
                        child: const Padding(
                          padding: EdgeInsets.only(left: AppSpacing.sm),
                          child: Icon(
                            Icons.drag_handle,
                            color: AppColors.outline,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Matching
// ---------------------------------------------------------------------------

class _MatchingInput extends StatelessWidget {
  const _MatchingInput({
    required this.interaction,
    required this.response,
    required this.onChanged,
  });

  final CbtInteraction interaction;
  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    if (interaction.left.isEmpty || interaction.right.isEmpty) {
      return const _MissingInteractionData(what: 'items to match');
    }

    final pairs = CbtResponse.enteredPairs(response);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: Text(
            'Match each item on the left to one on the right.',
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
        ),
        for (final left in interaction.left)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.sm),
            child: OutlinedCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  QuestionContent(text: left),
                  const SizedBox(height: AppSpacing.sm),
                  DropdownButtonFormField<String>(
                    initialValue:
                        interaction.right.contains(pairs[left]) ? pairs[left] : null,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'Matches',
                      isDense: true,
                    ),
                    items: [
                      for (final right in interaction.right)
                        DropdownMenuItem(
                          value: right,
                          child: Text(right, overflow: TextOverflow.ellipsis),
                        ),
                    ],
                    onChanged: (value) {
                      final next = Map<String, String>.from(pairs);
                      if (value == null) {
                        next.remove(left);
                      } else {
                        next[left] = value;
                      }
                      onChanged(CbtResponse.pairs(next));
                    },
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Numeric
// ---------------------------------------------------------------------------

class _NumericInput extends StatefulWidget {
  const _NumericInput({
    super.key,
    required this.interaction,
    required this.response,
    required this.onChanged,
  });

  final CbtInteraction interaction;
  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  State<_NumericInput> createState() => _NumericInputState();
}

class _NumericInputState extends State<_NumericInput> {
  late final TextEditingController _value =
      TextEditingController(text: CbtResponse.enteredNumber(widget.response));
  late final TextEditingController _unit =
      TextEditingController(text: CbtResponse.enteredUnit(widget.response));

  @override
  void dispose() {
    _value.dispose();
    _unit.dispose();
    super.dispose();
  }

  void _emit() => widget.onChanged(
        CbtResponse.numeric(_value.text.trim(), unit: _unit.text.trim()),
      );

  @override
  Widget build(BuildContext context) {
    final hint = widget.interaction.decimalPlacesHint;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        TextField(
          controller: _value,
          keyboardType: const TextInputType.numberWithOptions(
            decimal: true,
            signed: true,
          ),
          inputFormatters: [
            FilteringTextInputFormatter.allow(RegExp(r'[0-9.\-eE+]')),
          ],
          onChanged: (_) => _emit(),
          decoration: InputDecoration(
            labelText: 'Your answer',
            suffixText: widget.interaction.unit,
            helperText: hint == null
                ? null
                : 'Give your answer to $hint decimal place'
                    '${hint == 1 ? '' : 's'}.',
          ),
        ),
        // Only asked for where the question declares a unit; a bare number
        // question must not grow a box the marking scheme ignores.
        if (widget.interaction.unit != null) ...[
          const SizedBox(height: AppSpacing.sm),
          TextField(
            controller: _unit,
            onChanged: (_) => _emit(),
            decoration: InputDecoration(
              labelText: 'Unit',
              hintText: widget.interaction.unit,
            ),
          ),
        ],
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Free text and theory
// ---------------------------------------------------------------------------

class _FreeTextInput extends StatefulWidget {
  const _FreeTextInput({
    super.key,
    required this.response,
    required this.onChanged,
    required this.label,
    this.maxLines = 6,
    this.maxWords,
    this.expectedWords,
  });

  final Object? response;
  final ValueChanged<Object?> onChanged;
  final String label;
  final int maxLines;
  final int? maxWords;
  final int? expectedWords;

  @override
  State<_FreeTextInput> createState() => _FreeTextInputState();
}

class _FreeTextInputState extends State<_FreeTextInput> {
  late final TextEditingController _controller =
      TextEditingController(text: CbtResponse.enteredText(widget.response));

  int get _words => _countWords(_controller.text);

  void _onChanged(String raw) {
    final limit = widget.maxWords;

    // The server documents `max_words` as a stop, not a suggestion. Truncating
    // is kinder than silently accepting an essay the marker will cut off.
    if (limit != null && _countWords(raw) > limit) {
      final trimmed = _takeWords(raw, limit);
      _controller.value = TextEditingValue(
        text: trimmed,
        selection: TextSelection.collapsed(offset: trimmed.length),
      );
      setState(() {});
      widget.onChanged(CbtResponse.text(trimmed));
      return;
    }

    setState(() {});
    widget.onChanged(CbtResponse.text(raw));
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final limit = widget.maxWords;
    final expected = widget.expectedWords;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        TextField(
          controller: _controller,
          maxLines: widget.maxLines,
          keyboardType: TextInputType.multiline,
          textCapitalization: TextCapitalization.sentences,
          onChanged: _onChanged,
          decoration: InputDecoration(
            labelText: widget.label,
            alignLabelWithHint: true,
            helperText: expected == null ? null : 'About $expected words.',
          ),
        ),
        if (limit != null || expected != null) ...[
          const SizedBox(height: AppSpacing.xs),
          Text(
            limit != null ? '$_words / $limit words' : '$_words words',
            style: AppText.labelSm.copyWith(
              color: limit != null && _words >= limit
                  ? AppColors.late
                  : AppColors.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }
}

/// Theory (§6.3): a short answer, an essay, labelled parts (a)/(b)/(c), or a
/// question the candidate answers in a paper booklet and not on the handset.
class _TheoryInput extends StatelessWidget {
  const _TheoryInput({
    super.key,
    required this.interaction,
    required this.response,
    required this.onChanged,
  });

  final CbtInteraction interaction;
  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    // §6.4: the school printed booklets for this one. Showing a text box would
    // invite a candidate to answer somewhere nobody will ever mark.
    if (interaction.answerMode == 'on_paper') {
      return OutlinedCard(
        background: AppColors.surfaceContainerLow,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.edit_note, color: AppColors.primaryContainer),
            const SizedBox(width: AppSpacing.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Answer this one in your booklet',
                      style: AppText.labelLg),
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    'Write your answer on paper. There is nothing to type '
                    'here, and this question is marked by your teacher.',
                    style: AppText.bodyMd
                        .copyWith(color: AppColors.onSurfaceVariant),
                  ),
                ],
              ),
            ),
          ],
        ),
      );
    }

    if (interaction.responseFormat == 'structured' &&
        interaction.parts.isNotEmpty) {
      return _StructuredTheoryInput(
        parts: interaction.parts,
        maxWords: interaction.maxWords,
        response: response,
        onChanged: onChanged,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _FreeTextInput(
          response: response,
          onChanged: onChanged,
          label: 'Your answer',
          maxLines: interaction.responseFormat == 'essay' ? 12 : 6,
          maxWords: interaction.maxWords,
          expectedWords: interaction.expectedWords,
        ),
        const SizedBox(height: AppSpacing.sm),
        Text(
          'Your teacher marks this question by hand.',
          style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
        ),
      ],
    );
  }
}

class _StructuredTheoryInput extends StatefulWidget {
  const _StructuredTheoryInput({
    required this.parts,
    required this.response,
    required this.onChanged,
    this.maxWords,
  });

  final List<CbtTheoryPart> parts;
  final Object? response;
  final ValueChanged<Object?> onChanged;
  final int? maxWords;

  @override
  State<_StructuredTheoryInput> createState() => _StructuredTheoryInputState();
}

class _StructuredTheoryInputState extends State<_StructuredTheoryInput> {
  late final Map<String, TextEditingController> _controllers = {
    for (final part in widget.parts)
      part.key: TextEditingController(
        text: CbtResponse.enteredParts(widget.response)[part.key] ?? '',
      ),
  };

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  void _emit() => widget.onChanged(
        CbtResponse.parts({
          for (final entry in _controllers.entries) entry.key: entry.value.text,
        }),
      );

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final part in widget.parts) ...[
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.sm),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('(${part.key})', style: AppText.labelLg),
                const SizedBox(width: AppSpacing.sm),
                Expanded(child: QuestionContent(text: part.prompt)),
                if (part.marks != null)
                  StatusChip('${_trimZeros(part.marks!)} marks'),
              ],
            ),
          ),
          TextField(
            controller: _controllers[part.key],
            maxLines: 4,
            keyboardType: TextInputType.multiline,
            textCapitalization: TextCapitalization.sentences,
            onChanged: (_) => _emit(),
            decoration: const InputDecoration(alignLabelWithHint: true),
          ),
          const SizedBox(height: AppSpacing.md),
        ],
        Text(
          'Your teacher marks this question by hand.',
          style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Diagram labelling and hotspots
// ---------------------------------------------------------------------------

class _DiagramLabelInput extends StatelessWidget {
  const _DiagramLabelInput({
    required this.question,
    required this.response,
    required this.onChanged,
  });

  final CbtQuestion question;
  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    final interaction = question.interaction;
    final diagram = _diagramOf(question);

    if (interaction.zones.isEmpty || diagram == null) {
      return const _MissingInteractionData(what: 'a diagram to label');
    }

    final labels = CbtResponse.enteredLabels(response);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Tap each numbered marker and choose its label.',
          style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
        ),
        const SizedBox(height: AppSpacing.sm),
        LayoutBuilder(
          builder: (context, constraints) {
            final width = constraints.maxWidth;
            // Zones are normalised 0–1 against the image box, so the overlay
            // only needs the box to have a known aspect. 4:3 matches the
            // authoring preview the web side draws zones on.
            final height = width * 3 / 4;

            return SizedBox(
              width: width,
              height: height,
              child: Stack(
                children: [
                  Positioned.fill(
                    child: QuestionImage(media: diagram, fit: BoxFit.contain),
                  ),
                  for (final (index, zone) in interaction.zones.indexed)
                    Positioned(
                      left: zone.x * width,
                      top: zone.y * height,
                      // Clamped to a tap target: an author can draw a zone
                      // smaller than a fingertip, and a candidate still has
                      // to be able to hit it.
                      width: (zone.w * width).clamp(AppSpacing.tapTarget, width)
                          .toDouble(),
                      height: (zone.h * height)
                          .clamp(AppSpacing.tapTarget, height)
                          .toDouble(),
                      child: _ZoneMarker(
                        number: index + 1,
                        label: labels[zone.id],
                        onTap: () => _pickLabel(context, zone, labels),
                      ),
                    ),
                ],
              ),
            );
          },
        ),
        const SizedBox(height: AppSpacing.md),
        for (final (index, zone) in interaction.zones.indexed)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.sm),
            child: OutlinedCard(
              onTap: () => _pickLabel(context, zone, labels),
              child: Row(
                children: [
                  _MarkerBadge(number: index + 1),
                  const SizedBox(width: AppSpacing.md),
                  Expanded(
                    child: Text(
                      labels[zone.id] ?? 'Not labelled',
                      style: AppText.bodyLg.copyWith(
                        color: labels[zone.id] == null
                            ? AppColors.onSurfaceVariant
                            : AppColors.onSurface,
                      ),
                    ),
                  ),
                  const Icon(Icons.expand_more, color: AppColors.outline),
                ],
              ),
            ),
          ),
      ],
    );
  }

  Future<void> _pickLabel(
    BuildContext context,
    CbtZone zone,
    Map<String, String> labels,
  ) async {
    final pool = question.interaction.labelPool;

    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (_) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          children: [
            const Padding(
              padding: EdgeInsets.all(AppSpacing.md),
              child: Text('Choose a label', style: AppText.headlineSm),
            ),
            for (final label in pool)
              ListTile(
                title: Text(label),
                trailing: labels[zone.id] == label
                    ? const Icon(Icons.check, color: AppColors.present)
                    : null,
                onTap: () => Navigator.pop(context, label),
              ),
            ListTile(
              leading: const Icon(Icons.clear, color: AppColors.outline),
              title: const Text('Clear this label'),
              onTap: () => Navigator.pop(context, ''),
            ),
          ],
        ),
      ),
    );

    if (choice == null) return;

    final next = Map<String, String>.from(labels);
    if (choice.isEmpty) {
      next.remove(zone.id);
    } else {
      next[zone.id] = choice;
    }
    onChanged(CbtResponse.labels(next));
  }
}

class _HotspotInput extends StatelessWidget {
  const _HotspotInput({
    required this.question,
    required this.response,
    required this.onChanged,
  });

  final CbtQuestion question;
  final Object? response;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    final diagram = _diagramOf(question);
    if (diagram == null) {
      return const _MissingInteractionData(what: 'an image to point at');
    }

    final point = CbtResponse.enteredPoint(response);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Tap the correct spot on the image.',
          style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
        ),
        const SizedBox(height: AppSpacing.sm),
        LayoutBuilder(
          builder: (context, constraints) {
            final width = constraints.maxWidth;
            final height = width * 3 / 4;

            return SizedBox(
              width: width,
              height: height,
              child: GestureDetector(
                behavior: HitTestBehavior.opaque,
                onTapDown: (details) => onChanged(
                  CbtResponse.point(
                    (details.localPosition.dx / width).clamp(0, 1).toDouble(),
                    (details.localPosition.dy / height).clamp(0, 1).toDouble(),
                  ),
                ),
                child: Stack(
                  children: [
                    Positioned.fill(
                      child: QuestionImage(media: diagram, fit: BoxFit.contain),
                    ),
                    if (point != null)
                      Positioned(
                        left: point.x * width - 16,
                        top: point.y * height - 16,
                        child: Container(
                          width: 32,
                          height: 32,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: AppColors.action.withValues(alpha: 0.35),
                            border: Border.all(
                              color: AppColors.primaryContainer,
                              width: 2,
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            );
          },
        ),
        if (point == null) ...[
          const SizedBox(height: AppSpacing.sm),
          Text(
            'Nothing marked yet.',
            style: AppText.labelSm.copyWith(color: AppColors.onSurfaceVariant),
          ),
        ],
      ],
    );
  }
}

class _ZoneMarker extends StatelessWidget {
  const _ZoneMarker({
    required this.number,
    required this.onTap,
    this.label,
  });

  final int number;
  final String? label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final done = label != null;

    return GestureDetector(
      onTap: onTap,
      child: Container(
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: (done ? AppColors.present : AppColors.action)
              .withValues(alpha: 0.25),
          border: Border.all(
            color: done ? AppColors.present : AppColors.primaryContainer,
            width: 2,
          ),
          borderRadius: AppRadius.cardRadius,
        ),
        child: _MarkerBadge(number: number, done: done),
      ),
    );
  }
}

class _MarkerBadge extends StatelessWidget {
  const _MarkerBadge({required this.number, this.done = false});

  final int number;
  final bool done;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 24,
      height: 24,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: done ? AppColors.present : AppColors.primaryContainer,
      ),
      child: NumericText(
        '$number',
        size: 12,
        weight: FontWeight.w700,
        color: Colors.white,
      ),
    );
  }
}

/// A question whose interaction data did not arrive. The candidate is told
/// plainly rather than shown an empty box they cannot use — and it is worth
/// reporting, because it means an authoring mistake reached a live paper.
class _MissingInteractionData extends StatelessWidget {
  const _MissingInteractionData({required this.what});

  final String what;

  @override
  Widget build(BuildContext context) {
    return OutlinedCard(
      statusColor: AppColors.late,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.warning_amber_outlined, color: AppColors.late),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Text(
              'This question is missing $what. Tell your invigilator, and '
              'move on to the next question.',
              style: AppText.bodyMd,
            ),
          ),
        ],
      ),
    );
  }
}

/// The image a labelling or hotspot question is drawn on: whichever asset the
/// author attached as the diagram, falling back to the stem image.
CbtMedia? _diagramOf(CbtQuestion question) {
  for (final role in ['diagram', 'stem']) {
    for (final media in question.media) {
      if (media.role == role) return media;
    }
  }
  return question.media.isEmpty ? null : question.media.first;
}

int _countWords(String text) =>
    text.trim().isEmpty ? 0 : text.trim().split(RegExp(r'\s+')).length;

String _takeWords(String text, int limit) =>
    text.trim().split(RegExp(r'\s+')).take(limit).join(' ');

String _trimZeros(double value) =>
    value == value.roundToDouble() ? value.toInt().toString() : '$value';
