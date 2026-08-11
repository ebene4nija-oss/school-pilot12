/// Every path the app calls, in one file.
///
/// The `/api/v1` prefix is deliberately a single constant. An installed mobile
/// app is the whole reason the backend versions its routes (doc §11), so the
/// version must never be typed out at a call site where it can drift.
class Api {
  const Api._();

  static const String version = '/api/v1';

  // Auth
  static const login = '$version/auth/login';
  static const logout = '$version/auth/logout';
  static const currentUser = '$version/user';
  static const invite = '$version/auth/invite';
  static const forgotPassword = '$version/auth/forgot-password';

  // Analytics dashboards
  static const analyticsPrincipal = '$version/analytics/principal';
  static const analyticsTeacher = '$version/analytics/teacher';
  static const analyticsStudent = '$version/analytics/student';
  static String analyticsParent(int studentId) =>
      '$version/analytics/parent/$studentId';

  // Students
  static const students = '$version/students';
  static String student(int id) => '$version/students/$id';
  static String studentMedical(int id) => '$version/students/$id/medical';
  static String studentSubjects(int id) => '$version/students/$id/subjects';
  static String studentPortfolio(int id) => '$version/students/$id/portfolio';
  static String behaviorHistory(int id) =>
      '$version/students/$id/behavior-reports';
  static const behaviorReport = '$version/students/behavior-report';

  // Attendance
  static const attendanceRegister = '$version/attendance/register';
  static const attendanceBulk = '$version/attendance/bulk';
  static const attendanceMark = '$version/attendance/mark';
  static const attendanceQrToken = '$version/attendance/qr-token';
  static const attendanceStaffGps = '$version/attendance/staff-gps';

  // Timetable
  static const timetableView = '$version/timetable/view';

  // Assessment
  static const assessmentScore = '$version/assessment/score';
  static const broadsheet = '$version/assessment/broadsheet';
  static const caSchemes = '$version/assessment/ca-schemes';
  static String aiComment(int scoreId) =>
      '$version/assessment/score/$scoreId/ai-comment';
  static String reviewComment(int scoreId) =>
      '$version/assessment/score/$scoreId/review-comment';

  // Academic structure — the class and term pickers every staff screen needs
  // before it can ask the server anything.
  static const classes = '$version/classes';
  static const terms = '$version/terms';

  // Subjects
  static const subjects = '$version/subjects';
  static String classSubjects(int classId) =>
      '$version/classes/$classId/subjects';
  static String teacherSubjects(int teacherId) =>
      '$version/teachers/$teacherId/subjects';

  // Homework
  static const homework = '$version/academics/homework';
  static String homeworkSubmit(int id) =>
      '$version/academics/homework/$id/submit';
  static String homeworkMySubmission(int id) =>
      '$version/academics/homework/$id/my-submission';
  static String homeworkSubmissions(int id) =>
      '$version/academics/homework/$id/submissions';
  static String gradeSubmission(int id) =>
      '$version/academics/submissions/$id/grade';
  static String bulkGradeHomework(int id) =>
      '$version/academics/homework/$id/bulk-grade';

  // CBT
  static const cbtAvailable = '$version/cbt/available';
  static const cbtExams = '$version/cbt/exams';
  static String cbtExam(int id) => '$version/cbt/exams/$id';
  static String cbtStart(int examId) => '$version/cbt/exams/$examId/start';
  static String cbtExamResults(int examId) =>
      '$version/cbt/exams/$examId/results';
  static String cbtAttempt(int id) => '$version/cbt/attempts/$id';
  static String cbtAnswers(int id) => '$version/cbt/attempts/$id/answers';
  static String cbtSubmit(int id) => '$version/cbt/attempts/$id/submit';
  static String cbtEvents(int id) => '$version/cbt/attempts/$id/events';
  static String cbtResult(int id) => '$version/cbt/attempts/$id/result';
  static const cbtOfflineSync = '$version/cbt/offline-sync';

  // Finance
  static const payments = '$version/finance/payments';
  // Opens a hosted checkout on the school's own merchant account and returns an
  // `authorization_url`. The app holds no gateway key and mints no reference;
  // the payment is confirmed only by the gateway's signed callback to the
  // server, never by anything the app does.
  static const paymentsInitialize = '$version/finance/payments/initialize';
  // Where the gateway sends the payer when checkout ends. The checkout webview
  // watches for this exact path to know when to close — see GatewayCheckout.
  static const paymentReturn = '$version/payments/return';
  static const defaulters = '$version/finance/defaulters';
  static String studentStatement(int studentId) =>
      '$version/finance/students/$studentId/statement';
  static String invoicePdf(int id) => '$version/finance/invoices/$id/pdf';
  static String paymentReceipt(int id) =>
      '$version/finance/payments/$id/receipt';
  static String installmentPlan(int invoiceId) =>
      '$version/finance/invoices/$invoiceId/installment-plan';

  // Result checker
  static String resultSummary(int studentId, int termId) =>
      '$version/result-checker/$studentId/$termId/summary';
  static String result(int studentId, int termId) =>
      '$version/result-checker/$studentId/$termId';
  static const myPins = '$version/result-checker/my-pins';
  static const purchasePin = '$version/result-checker/purchase';
  static const redeemPin = '$version/result-checker/redeem';

  // Result PIN administration
  static const pinInventory = '$version/result-pins/inventory';
  static const pinBatches = '$version/result-pins/batches';
  static const pinPriceTiers = '$version/result-pins/price-tiers';
  static const pinCounterSale = '$version/result-pins/counter-sale';
  static const pinSalesReport = '$version/result-pins/sales-report';
  static const resultReleases = '$version/results/releases';
  static const resultRelease = '$version/results/release';

  // Parent portal
  //
  // `parentChildren` is the only way to learn a studentId: /students is
  // staff-only and every other parent route takes an id it does not hand out.
  static const parentChildren = '$version/parent/children';
  static String parentFeed(int studentId) => '$version/parent/feed/$studentId';
  static const pickupAuthorization = '$version/parent/pickup-authorization';

  // Messaging and notifications
  static const messageThreads = '$version/messages/threads';
  static String messageThread(int id) => '$version/messages/threads/$id';
  static const sendMessage = '$version/messages/send';
  static const notificationDevices = '$version/notifications/devices';
  // Pushes to the caller's own handsets and reports per-device delivery.
  // 503 means the deployment has no Firebase credentials, not a client bug.
  static const notificationDeviceTest = '$version/notifications/devices/test';
  static const notificationBroadcast = '$version/notifications/broadcast';
  static const notificationHistory = '$version/notifications/history';

  // AI
  static const tutorChat = '$version/ai/tutor-chat';
  static const tutorConversations = '$version/ai/tutor/conversations';
  static String tutorConversation(int id) =>
      '$version/ai/tutor/conversations/$id';
  static const tutorMastery = '$version/ai/tutor/mastery';
  static const aiLessonPlan = '$version/ai/lesson-plan';
  static const aiHomeworkIdeas = '$version/ai/homework-ideas';

  // HR
  static const leave = '$version/hr/leave';
  static String leaveDecision(int id) => '$version/hr/leave/$id/decision';

  // Jobs
  static String job(String batchId) => '$version/jobs/$batchId';
  static const generateReportCards = '$version/report-cards/generate';

  // Gamification
  static const leaderboard = '$version/gamification/leaderboard';
  static String gamificationProfile(int? studentId) => studentId == null
      ? '$version/gamification/profile'
      : '$version/gamification/profile/$studentId';

  static const health = '$version/health';
}
