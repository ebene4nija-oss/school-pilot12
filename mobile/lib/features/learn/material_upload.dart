import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';

import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/data/cached.dart';

/// Exactly what `LearningMaterialController::TYPES` accepts, so a teacher
/// cannot pick a file the server will refuse after a long upload.
const materialExtensions = [
  'pdf',
  'doc', 'docx', 'odt', 'rtf', 'txt',
  'ppt', 'pptx', 'odp',
  'jpg', 'jpeg', 'png', 'gif', 'webp',
  'mp4', 'webm', '3gp', 'mov',
  'mp3', 'm4a', 'aac', 'ogg', 'wav',
];

/// A file or link a teacher is about to share, before it is uploaded.
class PendingAttachment {
  PendingAttachment.file(PlatformFile this.file)
      : url = null,
        title = _stem(file.name);

  PendingAttachment.link(String this.url, {String? title})
      : file = null,
        title = (title == null || title.trim().isEmpty) ? url : title.trim();

  final PlatformFile? file;
  final String? url;
  final String title;

  bool get isVideo => const ['mp4', 'webm', '3gp', 'mov'].contains(_ext(file?.name));
  bool get isAudio => const ['mp3', 'm4a', 'aac', 'ogg', 'wav'].contains(_ext(file?.name));

  static String? _ext(String? name) {
    if (name == null) return null;
    final dot = name.lastIndexOf('.');
    return dot < 0 ? null : name.substring(dot + 1).toLowerCase();
  }

  static String _stem(String name) {
    final dot = name.lastIndexOf('.');
    return dot > 0 ? name.substring(0, dot) : name;
  }
}

/// Shares [item] with a whole class as a learning material and returns the
/// server's description of it (`id`, `status`, …).
///
/// Status is `pending_approval` when the school reviews materials before
/// students see them; the caller says so rather than pretending it is live.
Future<Map<String, dynamic>> uploadMaterial(
  ApiClient api,
  PendingAttachment item, {
  required int classId,
  int? subjectId,
  int? termId,
  String? description,
  String? topic,
}) async {
  final file = item.file;
  final form = FormData.fromMap({
    'title': item.title,
    if (description != null && description.isNotEmpty) 'description': description,
    if (topic != null && topic.isNotEmpty) 'topic': topic,
    if (subjectId != null) 'subject_id': subjectId,
    'class_id': classId,
    if (termId != null) 'term_id': termId,
    'downloadable': 1,
    // Who sees it: the whole class.
    'targets[0][target_type]': 'class',
    'targets[0][class_id]': classId,
    if (file != null) 'file': await MultipartFile.fromFile(file.path!, filename: file.name),
    if (file == null) 'url': item.url,
  });

  final res = await api.post<dynamic>(
    Api.learningMaterials,
    body: form,
    // Video over a school's mobile data takes a while to leave the phone.
    timeout: const Duration(minutes: 10),
  );
  return mapOf(mapOf(res)['material']);
}
