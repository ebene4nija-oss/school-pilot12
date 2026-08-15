import '../../core/data/cached.dart';
import '../../core/ui/formatters.dart';

/// The candidate's view of a paper, parsed from `GET /cbt/attempts/{id}`.
///
/// This file exists because the screen used to read the payload inline with
/// speculative fallbacks (`question_text ?? body ?? text`) that matched no
/// field the server has ever sent. Every name below is taken from
/// `CbtExamService::buildCandidatePaper` and `CbtController::paperPayload`;
/// there are no guesses left, and a rename on either side now breaks a test
/// rather than a paper.
class CbtPaper {
  const CbtPaper({
    required this.attemptId,
    required this.status,
    required this.questions,
    this.startedAt,
    this.deadline,
    this.secondsRemaining,
    this.examTitle = '',
    this.instructions,
    this.negativeMarking = false,
  });

  final int attemptId;
  final String status;
  final List<CbtQuestion> questions;
  final DateTime? startedAt;

  /// `attempt.server_deadline_at` — described by the API as "the only clock the
  /// client should trust", so it is preferred over anything derived locally.
  final DateTime? deadline;
  final int? secondsRemaining;
  final String examTitle;
  final String? instructions;
  final bool negativeMarking;

  factory CbtPaper.fromJson(Map<String, dynamic> json) {
    final attempt = mapOf(json, ['attempt']);
    final exam = mapOf(json, ['exam']);

    return CbtPaper(
      attemptId: intOf(attempt['id']),
      status: stringOf(attempt['status']),
      startedAt: Dates.tryParse(attempt['started_at']),
      deadline: Dates.tryParse(attempt['server_deadline_at']),
      secondsRemaining: attempt['seconds_remaining'] == null
          ? null
          : intOf(attempt['seconds_remaining']),
      examTitle: stringOf(exam['title']),
      instructions: _nullIfBlank(exam['instructions']),
      negativeMarking: exam['negative_marking'] == true,
      questions: [
        for (final row in listOf(json, ['questions']))
          CbtQuestion.fromJson(row),
      ],
    );
  }

  /// When the paper ends, preferring the server's own countdown over any
  /// clock on the handset. A phone in an exam hall is frequently minutes out.
  DateTime? endsAt({DateTime? now}) {
    if (secondsRemaining != null) {
      return (now ?? DateTime.now()).add(Duration(seconds: secondsRemaining!));
    }
    return deadline;
  }
}

/// One question as served to a candidate.
class CbtQuestion {
  const CbtQuestion({
    required this.number,
    required this.questionId,
    required this.type,
    required this.text,
    this.contentFormat = 'plain',
    this.topic,
    this.section,
    this.marks = 1,
    this.negativeMarks = 0,
    this.media = const [],
    this.options = const [],
    this.interaction = const CbtInteraction(),
    this.savedResponse,
    this.group,
  });

  final int number;
  final int questionId;
  final String type;
  final String text;
  final String contentFormat;
  final String? topic;
  final String? section;
  final double marks;
  final double negativeMarks;
  final List<CbtMedia> media;
  final List<CbtOption> options;
  final CbtInteraction interaction;
  final Object? savedResponse;

  /// The shared passage, table or diagram this question hangs off, if any.
  final CbtGroup? group;

  factory CbtQuestion.fromJson(Map<String, dynamic> json) {
    final group = json['group'];

    return CbtQuestion(
      number: intOf(json['number']),
      questionId: intOf(json['question_id']),
      type: stringOf(json['question_type'], 'multiple_choice'),
      text: stringOf(json['question']),
      contentFormat: stringOf(json['content_format'], 'plain'),
      topic: _nullIfBlank(json['topic']),
      section: _nullIfBlank(json['section']),
      marks: numOf(json['marks'], 1).toDouble(),
      negativeMarks: numOf(json['negative_marks']).toDouble(),
      media: CbtMedia.listFrom(json['media']),
      options: [
        for (final option in listOf(json['options'])) CbtOption.fromJson(option),
      ],
      interaction: CbtInteraction.fromJson(mapOf(json['interaction'])),
      savedResponse: json['saved_response'],
      group: group is Map
          ? CbtGroup.fromJson(group.cast<String, dynamic>())
          : null,
    );
  }

  /// True where the candidate writes on paper, not on the handset (§6.4).
  /// Such a question has no on-screen input at all, and must not be counted
  /// as unanswered when the paper is submitted.
  bool get isAnsweredOnPaper => interaction.answerMode == 'on_paper';

  /// Questions a teacher marks by hand. The client never scores these, it
  /// just collects them.
  bool get isManuallyGraded => type == 'theory';
}

/// A shared stimulus — a comprehension passage, a data table, a diagram —
/// with several sub-questions hanging off it.
///
/// The server ships one copy per paper and tags each sub-question with its
/// `position` within the group, so the candidate can be told "2 of 6" without
/// the client counting anything itself.
class CbtGroup {
  const CbtGroup({
    required this.groupId,
    required this.stimulus,
    this.title,
    this.contentFormat = 'plain',
    this.instructions,
    this.media = const [],
    this.position = 1,
    this.of = 1,
  });

  final int groupId;
  final String stimulus;
  final String? title;
  final String contentFormat;
  final String? instructions;
  final List<CbtMedia> media;

  /// Which sub-question of the group this is, and how many there are, in the
  /// order the candidate is actually sitting them.
  final int position;
  final int of;

  factory CbtGroup.fromJson(Map<String, dynamic> json) => CbtGroup(
        groupId: intOf(json['group_id']),
        stimulus: stringOf(json['stimulus']),
        title: _nullIfBlank(json['title']),
        contentFormat: stringOf(json['content_format'], 'plain'),
        instructions: _nullIfBlank(json['instructions']),
        media: CbtMedia.listFrom(json['media']),
        position: intOf(json['position'], 1),
        of: intOf(json['of'], 1),
      );

  /// The stimulus is shown once at the top of the group, not re-read aloud on
  /// every sub-question — but it stays reachable, because a candidate on
  /// question 5 of a comprehension still needs the passage.
  bool get isFirstOfGroup => position <= 1;
}

/// An image from the media library, already hydrated with a URL.
class CbtMedia {
  const CbtMedia({
    required this.url,
    this.assetId,
    this.role = 'stem',
    this.thumbnailUrl,
    this.altText,
    this.caption,
  });

  final String url;
  final int? assetId;
  final String role;
  final String? thumbnailUrl;

  /// Read out by screen readers, and shown when the image cannot be fetched.
  final String? altText;
  final String? caption;

  factory CbtMedia.fromJson(Map<String, dynamic> json) => CbtMedia(
        url: stringOf(json['url']),
        assetId: json['asset_id'] == null ? null : intOf(json['asset_id']),
        role: stringOf(json['role'], 'stem'),
        thumbnailUrl: _nullIfBlank(json['thumbnail_url']),
        altText: _nullIfBlank(json['alt_text']),
        caption: _nullIfBlank(json['caption']),
      );

  static List<CbtMedia> listFrom(Object? raw) {
    if (raw is! List) return const [];
    return [
      for (final entry in raw)
        if (entry is Map)
          CbtMedia.fromJson(entry.cast<String, dynamic>())
          else if (entry is String && entry.isNotEmpty)
            CbtMedia(url: entry),
    ].where((m) => m.url.isNotEmpty).toList();
  }
}

/// One selectable option. `key` is what grading compares against, and it
/// travels with the option so a shuffled paper still marks correctly.
class CbtOption {
  const CbtOption({required this.key, required this.text, this.image});

  final String key;
  final String text;
  final CbtMedia? image;

  factory CbtOption.fromJson(Map<String, dynamic> json) {
    final image = json['image'];

    return CbtOption(
      key: stringOf(json['key']),
      text: stringOf(json['text']),
      image: image is Map
          ? CbtMedia.fromJson(image.cast<String, dynamic>())
          : null,
    );
  }
}

/// The half of `answer_schema` a candidate is allowed to see: geometry, label
/// pools, matching columns, word limits. Never a correct answer, never a
/// marking rubric.
class CbtInteraction {
  const CbtInteraction({
    this.responseFormat = 'short_answer',
    this.answerMode = 'on_screen',
    this.expectedWords,
    this.maxWords,
    this.allowWorkingPhoto = false,
    this.parts = const [],
    this.items = const [],
    this.left = const [],
    this.right = const [],
    this.unit,
    this.decimalPlacesHint,
    this.zones = const [],
    this.labelPool = const [],
  });

  // Theory (§6.3)
  final String responseFormat; // short_answer | structured | essay
  final String answerMode; // on_screen | on_paper
  final int? expectedWords;
  final int? maxWords;
  final bool allowWorkingPhoto;
  final List<CbtTheoryPart> parts;

  // Ordering
  final List<String> items;

  // Matching
  final List<String> left;
  final List<String> right;

  // Numeric
  final String? unit;
  final int? decimalPlacesHint;

  // Diagram labelling
  final List<CbtZone> zones;
  final List<String> labelPool;

  factory CbtInteraction.fromJson(Map<String, dynamic> json) {
    return CbtInteraction(
      responseFormat: stringOf(json['response_format'], 'short_answer'),
      answerMode: stringOf(json['answer_mode'], 'on_screen'),
      expectedWords:
          json['expected_words'] == null ? null : intOf(json['expected_words']),
      maxWords: json['max_words'] == null ? null : intOf(json['max_words']),
      // `allow_working_photo` is the current name; `allow_image_answer` is
      // retained by the server for clients written before the rename.
      allowWorkingPhoto: json['allow_working_photo'] == true ||
          (json['allow_working_photo'] == null &&
              json['allow_image_answer'] == true),
      parts: [
        for (final part in listOf(json['parts'])) CbtTheoryPart.fromJson(part),
      ],
      items: _stringList(json['items']),
      left: _stringList(json['left']),
      right: _stringList(json['right']),
      unit: _nullIfBlank(json['unit']),
      decimalPlacesHint: json['decimal_places_hint'] == null
          ? null
          : intOf(json['decimal_places_hint']),
      zones: [
        for (final zone in listOf(json['zones'])) CbtZone.fromJson(zone),
      ],
      labelPool: _stringList(json['label_pool']),
    );
  }
}

/// A labelled sub-field of a structured theory question — (a), (b), (c).
class CbtTheoryPart {
  const CbtTheoryPart({required this.key, required this.prompt, this.marks});

  final String key;
  final String prompt;
  final double? marks;

  factory CbtTheoryPart.fromJson(Map<String, dynamic> json) => CbtTheoryPart(
        key: stringOf(json['key']),
        prompt: stringOf(json['prompt']),
        marks: json['marks'] == null ? null : numOf(json['marks']).toDouble(),
      );
}

/// A drop target on a diagram, in normalised 0–1 coordinates.
class CbtZone {
  const CbtZone({
    required this.id,
    required this.x,
    required this.y,
    required this.w,
    required this.h,
  });

  final String id;
  final double x;
  final double y;
  final double w;
  final double h;

  factory CbtZone.fromJson(Map<String, dynamic> json) => CbtZone(
        id: stringOf(json['id']),
        x: numOf(json['x']).toDouble(),
        y: numOf(json['y']).toDouble(),
        w: numOf(json['w']).toDouble(),
        h: numOf(json['h']).toDouble(),
      );
}

/// Reading and writing the response envelope each question type is graded on.
///
/// `CbtExamService::recordAnswers` wraps any non-map response as
/// `{'value': …}`, and `CbtGradingService` reads a *different* key per type —
/// `option` for multiple choice, `order` for sequencing, `pairs` for matching.
/// Sending a bare string therefore stores something the grader cannot read and
/// marks a correct answer wrong. Every response leaves this app as a map with
/// the key its own grader looks for.
class CbtResponse {
  const CbtResponse._();

  /// Whether a stored response actually carries an answer. Used for the
  /// progress grid and the "you have N unanswered" prompt, so it must agree
  /// with the server's own `isBlank` notion of empty.
  static bool isAnswered(Object? response) {
    if (response == null) return false;

    if (response is Map) {
      if (response.isEmpty) return false;
      return response.values.any((value) => isAnswered(value));
    }

    if (response is Iterable) {
      return response.any((value) => isAnswered(value));
    }

    if (response is String) return response.trim().isNotEmpty;

    return true;
  }

  // -- single choice -------------------------------------------------------

  static Map<String, Object?> choice(String optionKey) => {'option': optionKey};

  static String? selectedChoice(Object? response) {
    final map = _asMap(response);
    final value = map['option'] ?? map['selected_option'] ?? map['value'];
    return value?.toString();
  }

  // -- true / false --------------------------------------------------------

  static Map<String, Object?> boolean(bool value) => {'value': value};

  static bool? selectedBoolean(Object? response) {
    final value = _asMap(response)['value'] ?? _asMap(response)['option'];
    if (value is bool) return value;
    if (value is String) {
      final lower = value.toLowerCase();
      if (lower == 'true' || lower == '1') return true;
      if (lower == 'false' || lower == '0') return false;
    }
    if (value is num) return value != 0;
    return null;
  }

  // -- multiple response ---------------------------------------------------

  static Map<String, Object?> choices(Iterable<String> keys) =>
      {'options': keys.toList()};

  static Set<String> selectedChoices(Object? response) =>
      _stringList(_asMap(response)['options']).toSet();

  // -- free text (fill_blank, theory) --------------------------------------

  static Map<String, Object?> text(String value) => {'text': value};

  static String enteredText(Object? response) {
    final map = _asMap(response);
    return stringOf(map['text'] ?? map['value']);
  }

  // -- structured theory ---------------------------------------------------

  static Map<String, Object?> parts(Map<String, String> byKey) =>
      {'parts': Map<String, String>.from(byKey)};

  static Map<String, String> enteredParts(Object? response) {
    final raw = _asMap(response)['parts'];
    if (raw is! Map) return {};
    return {
      for (final entry in raw.entries)
        entry.key.toString(): stringOf(entry.value),
    };
  }

  // -- numeric -------------------------------------------------------------

  static Map<String, Object?> numeric(String value, {String? unit}) => {
        'value': value,
        if (unit != null && unit.isNotEmpty) 'unit': unit,
      };

  static String enteredNumber(Object? response) =>
      stringOf(_asMap(response)['value']);

  static String enteredUnit(Object? response) =>
      stringOf(_asMap(response)['unit']);

  // -- ordering ------------------------------------------------------------

  static Map<String, Object?> order(Iterable<String> items) =>
      {'order': items.toList()};

  static List<String> enteredOrder(Object? response) =>
      _stringList(_asMap(response)['order']);

  // -- matching ------------------------------------------------------------

  static Map<String, Object?> pairs(Map<String, String> byLeft) =>
      {'pairs': Map<String, String>.from(byLeft)};

  static Map<String, String> enteredPairs(Object? response) {
    final raw = _asMap(response)['pairs'];
    if (raw is! Map) return {};
    return {
      for (final entry in raw.entries)
        entry.key.toString(): stringOf(entry.value),
    };
  }

  // -- diagram labelling ---------------------------------------------------

  static Map<String, Object?> labels(Map<String, String> byZone) =>
      {'labels': Map<String, String>.from(byZone)};

  static Map<String, String> enteredLabels(Object? response) {
    final raw = _asMap(response)['labels'];
    if (raw is! Map) return {};
    return {
      for (final entry in raw.entries)
        entry.key.toString(): stringOf(entry.value),
    };
  }

  // -- hotspot -------------------------------------------------------------

  /// Normalised 0–1 coordinates, as the server declares in
  /// `interaction.coordinate_space`.
  static Map<String, Object?> point(double x, double y) => {'x': x, 'y': y};

  static ({double x, double y})? enteredPoint(Object? response) {
    final map = _asMap(response);
    if (map['x'] == null || map['y'] == null) return null;
    return (x: numOf(map['x']).toDouble(), y: numOf(map['y']).toDouble());
  }

  static Map<String, Object?> _asMap(Object? response) =>
      response is Map ? response.cast<String, Object?>() : const {};
}

List<String> _stringList(Object? raw) {
  if (raw is! List) return const [];
  return [
    for (final value in raw)
      if (value != null) value.toString(),
  ];
}

String? _nullIfBlank(Object? value) {
  final text = stringOf(value);
  return text.isEmpty ? null : text;
}
