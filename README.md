# SchoolPilot — Cloud-based K-12 School Management Platform

SchoolPilot is a cloud-based school management platform for private K-12 day schools in Nigeria, operating strictly **without biometrics or dedicated hardware** (software-only: phone camera for QR scans, phone GPS for staff/bus tracking, browser & offline desktop software for CBT).

## Monorepo Layout

- `/backend`: Laravel (PHP) API server, multi-tenant middleware, PostgreSQL & Redis services.
- `/web`: Next.js 14 web application & School Admin/Teacher portal (powered by Tailwind CSS & 51 Stitch Design screens).
- `/mobile`: Flutter cross-platform mobile application supporting Admin, Teacher, Student, and Parent roles.
- `/docs`: Product & Agentic Build documentation.
- `/stitch_schoolpilot_management_system`: Stitch UI mockups and design HTML assets.

## Getting Started

### 1. Services Setup (Docker)
Start PostgreSQL and Redis:
```bash
docker compose up -d
```

### 2. Backend (Laravel API)
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```
API endpoints are versioned under `http://localhost:8000/api/v1/`.

### 3. Web Portal (Next.js)
```bash
cd web
npm install
cp .env.example .env.local
npm run dev
```
Open `http://localhost:3000` to view the web dashboard.

### 4. Mobile App (Flutter)
```bash
cd mobile
flutter pub get
flutter run
```

## Key Rules & Architectural Principles
1. **Software-Only**: No biometrics, RFID, or dedicated hardware scanners.
2. **K-12 Focused**: Sessions, Terms, Classes, Arms/Streams (no university faculties/semesters).
3. **API Versioning**: All endpoints sit under `/api/v1/...`.
4. **AI Oversight**: AI-generated report card remarks sit in `pending_approval` until teacher review.
