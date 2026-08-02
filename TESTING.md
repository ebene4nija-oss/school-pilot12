# SchoolPilot — Testing Suite & Execution Guide

## Overview

SchoolPilot relies on a multi-tiered testing suite across the Laravel Backend, Next.js Web Admin Portal, and Flutter Mobile Application.

---

## 1. Backend Testing Suite (Laravel PHPUnit)

The backend test suite covers multi-tenant isolation, Sanctum authentication, SIS workflows, constraint-based timetable scheduling, AI comment approval flows, NDPA parental consent logging, and Phase 1/2 feature modules.

### Running Backend Tests:

```bash
cd backend
php artisan test
```

### Running Specific Test Cases:

```bash
# Test multi-tenant isolation
php artisan test --filter=TenantIsolationTest

# Test NDPA Parental Consent & Data Export
php artisan test --filter=AddedFeaturesTest

# Test Timetable CSP Engine & Attendance QR Tokens
php artisan test --filter=AttendanceAndTimetableTest

# Test Phase 1 & 2 Modules (Scholarships, Bus GPS, Clinic Log)
php artisan test --filter=Phase1And2Test

# Test Gamification (Points, Streaks & Badges)
php artisan test --filter=GamificationTest
```

---

## 2. Web Admin Portal Suite (Next.js & Playwright E2E)

The web admin portal verifies static page generation, TypeScript types, and critical user interaction flows.

### Running Lint & Build Checks:

```bash
cd web
npm run lint
npm run build
```

---

## 3. Coverage Targets & Production Guardrails

- **Financial Transactions**: Paystack and Flutterwave webhooks require 100% test coverage including invalid signature rejection.
- **Tenant Data Security**: Every database query must be verified to isolate data per `school_id`.
- **AI Safety**: AI-generated teacher remarks must be verified in the `pending_approval` state prior to report card PDF printing.
