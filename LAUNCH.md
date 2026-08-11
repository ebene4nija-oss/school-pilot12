# SchoolPilot — Go-Live & Deployment Operational Guide

## Overview

This document defines the go-live operational requirements, production deployment checklist, backup & disaster recovery policies, and WhatsApp onboarding procedures for SchoolPilot.

---

## 1. Production Pre-Flight Checklist

Before onboarding the first live school tenant, verify all items below against the production environment:

Items are ordered so that each one is actually doable when you reach it —
2FA enforcement in particular must come *after* the admins have enrolled.

- [ ] **Deployment**: copy `.env.docker.example` to `.env`, set `APP_KEY`
      (`docker compose run --rm backend php artisan key:generate --show`) and
      `POSTGRES_PASSWORD`, then `docker compose up -d --build`. Compose refuses
      to start without those two rather than booting with a default.
- [ ] **Subdomain Wildcard DNS**: Ensure `*.schoolpilot.ng` resolves to the backend production load balancer.
- [ ] **SSL / TLS**: Wildcard SSL certificate active for all tenant subdomains.
      `docker/nginx/conf.d/schoolpilot.conf` terminates plain HTTP on :80; put
      the certificate on your load balancer or add a TLS server block there.
- [ ] **Messaging keys**: set `SMS_API_KEY` / `WHATSAPP_API_KEY`, then send one
      real test message per channel and confirm it arrives. An unset key now
      records a *failed* send rather than a fake success, so an untested
      channel shows up in the notification history as failures.
- [ ] **Live Payment Keys**: Confirm `PAYSTACK_SECRET_KEY` and `FLUTTERWAVE_SECRET_KEY` are operating in Live Mode with signed webhook validation enabled.
- [ ] **Claude AI Budget Caps**: set `AI_DAILY_COST_CAP_KOBO` (2500000 = ₦25,000
      per school per day) and `AI_USD_TO_NGN`. The alert fires once per school
      per day at 80% (`AI_ALERT_THRESHOLD`). Spend is metered whether or not a
      cap is set, so you can watch real usage for a week before choosing the
      number. Left blank, AI spend is uncapped.
- [ ] **Admin 2FA enrolment**: every School Admin and Super Admin signs in and
      completes `/security` in the web portal (scan, confirm, save recovery
      codes). Do this *before* the next item.
- [ ] **2FA Enforcement**: set `AUTH_REQUIRE_ADMIN_2FA=true`. This is the last
      pre-flight step — turning it on before the admins above have enrolled
      locks all of them out of everything except the enrolment endpoint.
- [ ] **NDPA Data Compliance**: Cross-border AI transfer documentation confirmed and parental consent logging active.

---

## 2. Disaster Recovery & Backup Policy

- **Automated Nightly Backups**: Production PostgreSQL database dumps executed daily at 02:00 UTC and stored in encrypted S3 bucket.
- **Monthly Restore Verification**: Required monthly execution of automated database restoration to an isolated test container with data integrity verification.
- **Recovery Time Objective (RTO)**: 2 Hours (Maximum time to restore full platform service following catastrophic failure).
- **Recovery Point Objective (RPO)**: 1 Hour (Maximum acceptable data loss window).

---

## 3. WhatsApp Onboarding Funnel

- **Official Business Number**: Configured with pre-filled lead intake text (`"Hello SchoolPilot team, I would like to onboard my school."`).
- **Migration SLA**: Guarantee 24-hour free data migration from Excel/CSV or rival portals.
- **Data Portability Guarantee**: One-click complete school data export (`POST /api/v1/admin/export-data`) advertised to build trust.
