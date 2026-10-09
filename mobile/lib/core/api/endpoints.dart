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
  static const approvals = '$version/approvals';
  static const reportEntries = '$version/report-entries';
  static const attendanceToday = '$version/attendance/today';
  static String studentQuickView(int id) => '$version/student-directory/$id/quick-view';
  // Decisions the approvals inbox makes, each through its own endpoint.
  static String learningMaterialReview(int id) =>
      '$version/learning-materials/$id/review';
  static String cbtApproval(int id) => '$version/cbt/exams/$id/approval';
  static String admissionDecision(int id) => '$version/admissions/$id/decision';
  static String broadsheetApprove(int id) => '$version/broadsheets/$id/approve';
  static String broadsheetReject(int id) => '$version/broadsheets/$id/reject';
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

  /// Search-as-you-type: name, admission number and class only. The one
  /// student list a nurse or bursar may read.
  static const studentLookup = '$version/students/lookup';

  // Sick bay
  static const clinicVisits = '$version/health/clinic-visits';
  static const clinicVisit = '$version/health/clinic-visit';

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
  // A CA filled from homework and quizzes; the teacher adjusts, then applies.
  static const courseworkSources = '$version/assessment/coursework-ca/sources';
  static const courseworkSuggest = '$version/assessment/coursework-ca/suggest';
  static const courseworkApply = '$version/assessment/coursework-ca/apply';
  static String aiComment(int scoreId) =>
      '$version/assessment/score/$scoreId/ai-comment';
  static String reviewComment(int scoreId) =>
      '$version/assessment/score/$scoreId/review-comment';

  // Academic structure — the class and term pickers every staff screen needs
  // before it can ask the server anything.
  static const classes = '$version/classes';
  static const terms = '$version/terms';

  // The classes this teacher is form teacher or assistant for, in the current
  // session. Scoped to the caller by construction — unlike `classes`, which is
  // every class in the school.
  static const teacherMyClasses = '$version/teacher/my-classes';

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

  // CBT authoring and marking — staff only.
  static const cbtQuestions = '$version/cbt/questions';
  static const cbtQuestionsImport = '$version/cbt/questions/import';
  static String cbtExamQuestions(int examId) =>
      '$version/cbt/exams/$examId/questions';
  static String cbtExamPublish(int examId) =>
      '$version/cbt/exams/$examId/publish';
  static String cbtExamClose(int examId) => '$version/cbt/exams/$examId/close';
  static String cbtExamQuestion(int examId, int questionId) =>
      '$version/cbt/exams/$examId/questions/$questionId';
  static String cbtGrade(int attemptId) =>
      '$version/cbt/attempts/$attemptId/grade';

  // Grouped questions — one shared passage, table or diagram with several
  // sub-questions. Authoring is staff-only; a candidate never calls these,
  // because the group a question belongs to already travels inside the
  // attempt payload as `question.group`.
  static const cbtQuestionGroups = '$version/cbt/question-groups';
  static String cbtQuestionGroup(int id) => '$version/cbt/question-groups/$id';

  // Finance
  static const payments = '$version/finance/payments';
  static const expenses = '$version/accounting/expenses';
  static const pettyCash = '$version/accounting/petty-cash';
  static const scholarships = '$version/finance/scholarships';
  static String applyDiscount(int invoiceId) =>
      '$version/finance/invoices/$invoiceId/apply-discount';
  // Opens a hosted checkout on the school's own merchant account and returns an
  // `authorization_url`. The app holds no gateway key and mints no reference;
  // the payment is confirmed only by the gateway's signed callback to the
  // server, never by anything the app does.
  static const paymentsInitialize = '$version/finance/payments/initialize';
  // Where the gateway sends the payer when checkout ends. The checkout webview
  // watches for this exact path to know when to close — see GatewayCheckout.
  static const paymentReturn = '$version/payments/return';
  static const defaulters = '$version/finance/defaulters';
  static const feeStatus = '$version/finance/fee-status';
  static const defaultersRemind = '$version/finance/defaulters/remind';
  static const reconcileTransfer = '$version/finance/payments/reconcile-bank-transfer';
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
  // A child's teachers, for a parent starting a conversation.
  static String parentChildContacts(int studentId) =>
      '$version/parent/children/$studentId/contacts';
  static String parentFeed(int studentId) => '$version/parent/feed/$studentId';
  static const pickupAuthorization = '$version/parent/pickup-authorization';
  static const pickupAuthorizations = '$version/parent/pickup-authorizations';
  static String pickupRevoke(int id) => '$version/parent/pickup-authorizations/$id/revoke';
  static const parentConsents = '$version/parent/consents';
  static const parentalConsent = '$version/compliance/parental-consent';
  static String parentalConsentWithdraw(int id) => '$version/compliance/parental-consent/$id/withdraw';

  // Absence alerts: offered only once the school switches them on, then
  // opted into per child.
  static const parentAbsenceAlerts = '$version/parent/absence-alerts';
  static String parentChildAbsenceAlerts(int studentId) =>
      '$version/parent/children/$studentId/absence-alerts';

  // Messaging and notifications
  static const messageThreads = '$version/messages/threads';
  static String messageThread(int id) => '$version/messages/threads/$id';
  static const sendMessage = '$version/messages/send';
  static String studentMessageRecipients(int id) =>
      '$version/students/$id/message-recipients';
  static const notificationDevices = '$version/notifications/devices';
  // Pushes to the caller's own handsets and reports per-device delivery.
  // 503 means the deployment has no Firebase credentials, not a client bug.
  static const notificationDeviceTest = '$version/notifications/devices/test';
  static const notificationBroadcast = '$version/notifications/broadcast';
  static const notificationAudiencePreview =
      '$version/notifications/audience-preview';
  static const notificationChannels = '$version/notifications/channels';
  // The school's delivery ledger — admin-only, and about spend, not about you.
  static const notificationHistory = '$version/notifications/history';
  // The caller's own inbox. Everyone has one; `notificationHistory` is not it.
  static const myNotifications = '$version/me/notifications';
  static const myNotificationsRead = '$version/me/notifications/read';

  // AI
  static const tutorChat = '$version/ai/tutor-chat';
  static const tutorConversations = '$version/ai/tutor/conversations';
  static String tutorConversation(int id) =>
      '$version/ai/tutor/conversations/$id';
  static const tutorMastery = '$version/ai/tutor/mastery';
  static const aiLessonPlan = '$version/ai/lesson-plan';
  static const aiHomeworkIdeas = '$version/ai/homework-ideas';
  static const aiExamQuestions = '$version/ai/exam-generation';

  // HR
  static const leave = '$version/hr/leave';
  static String leaveDecision(int id) => '$version/hr/leave/$id/decision';
  static String leaveCancel(int id) => '$version/hr/leave/$id/cancel';

  // Jobs
  static String job(String batchId) => '$version/jobs/$batchId';
  static const generateReportCards = '$version/report-cards/generate';
  // Which children's cards a run produced, for one class and term.
  static const generatedReportCards = '$version/report-cards/generated';

  // Gamification
  static const leaderboard = '$version/gamification/leaderboard';
  static String gamificationProfile(int? studentId) => studentId == null
      ? '$version/gamification/profile'
      : '$version/gamification/profile/$studentId';

  static const health = '$version/health';

  // Student portal: today with venues and cover teachers.
  static const myDashboard = '$version/me/dashboard';
  // The student's own class week, with each lesson's venue.
  static const myTimetable = '$version/me/timetable';
  static const myTests = '$version/me/tests';
  /// Released results only, with the school's choices about positions.
  static const myProgress = '$version/me/progress';

  // Learning: materials, virtual classes, the student's learning record.
  static const myLearning = '$version/me/learning';
  static const parentLearning = '$version/parent/learning';
  static const learningMaterials = '$version/learning-materials';
  // A ten-minute signed URL the phone's own viewer can open; the app's token
  // never leaves secure storage.
  static String learningMaterialLink(int id) =>
      '$version/learning-materials/$id/link';
  static String liveClassJoin(int id) => '$version/live-classes/$id/join';
  static const liveClasses = '$version/live-classes';
  static String liveClassAttendance(int id) =>
      '$version/live-classes/$id/attendance';
  static String liveClassCorrectAttendance(int id, int studentId) =>
      '$version/live-classes/$id/attendance/$studentId';
  // Read who was in the Zoom / Meet call, and say who an unknown name was.
  static String liveClassProviderAttendance(int id) =>
      '$version/live-classes/$id/provider-attendance';
  static String liveClassProviderMatch(int id) =>
      '$version/live-classes/$id/provider-attendance/match';
  static String liveClassHeartbeat(int id) =>
      '$version/live-classes/$id/heartbeat';

  // Library
  static const libraryBooks = '$version/library/books';
  static const libraryMine = '$version/library/me';
  static String libraryChild(int studentId) =>
      '$version/library/children/$studentId';
  static const libraryLoans = '$version/library/loans';
  static const libraryReturns = '$version/library/returns';
  static String libraryRenew(int loanId) =>
      '$version/library/loans/$loanId/renew';
  static const libraryReservations = '$version/library/reservations';
  static String libraryReservation(int id) =>
      '$version/library/reservations/$id';
  static const libraryBorrowers = '$version/library/borrowers';

  // Hostel (boarders and their parents)
  static const myHostel = '$version/me/hostel';
  static const parentHostel = '$version/parent/hostel';

  // School store: a parent confirms items handed to their child
  static const parentInventory = '$version/parent/inventory';
  static String parentInventoryConfirm(int id) => '$version/parent/inventory/issues/$id/confirm';

  // Digital ID cards, where the school issues them
  static const myIdCard = '$version/me/id-card';
  static const parentIdCards = '$version/parent/id-cards';

  // Staff payslips (approved and paid months only)
  static const myPayslips = '$version/me/payslips';

  // Payroll runs (admin): prepare, approve, record payment, reopen.
  static const payrollRuns = '$version/payroll/runs';
  static String payrollRun(int id) => '$version/payroll/runs/$id';
  static String payrollApprove(int id) => '$version/payroll/runs/$id/approve';
  static String payrollPaid(int id) => '$version/payroll/runs/$id/paid';
  static String payrollReopen(int id) => '$version/payroll/runs/$id/reopen';

  // The school's own Zoom / Google Workspace account, for reading
  // virtual-class attendance. Credentials go up, never come back.
  static const videoProviders = '$version/live-classes/providers';
  static String videoProvider(String provider) =>
      '$version/live-classes/providers/$provider';

  // Timetable venues: where a teacher's lesson is held
  static String freeVenues(int entryId) =>
      '$version/timetable/entries/$entryId/free-venues';
  static String entryVenue(int entryId) =>
      '$version/timetable/entries/$entryId/venue';
  static String entryVenueChange(int entryId) =>
      '$version/timetable/entries/$entryId/venue-changes';
}
