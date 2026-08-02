# SchoolPilot — Go-Live & Deployment Operational Guide

## Overview

This document defines the go-live operational requirements, production deployment checklist, backup & disaster recovery policies, and WhatsApp onboarding procedures for SchoolPilot.

---

## 1. Production Pre-Flight Checklist

Before onboarding the first live school tenant, verify all items below against the production environment:

- [ ] **Subdomain Wildcard DNS**: Ensure `*.schoolpilot.ng` resolves to the backend production load balancer.
- [ ] **SSL / TLS**: Wildcard SSL certificate active for all tenant subdomains.
- [ ] **2FA Enforcement**: Mandatory TOTP Two-Factor Authentication enabled for all School Admin and Super Admin accounts.
- [ ] **Live Payment Keys**: Confirm `PAYSTACK_SECRET_KEY` and `FLUTTERWAVE_SECRET_KEY` are operating in Live Mode with signed webhook validation enabled.
- [ ] **Claude AI API Budget Caps**: Verify daily spend cap is set ($25/day per school) with automated alert trigger at 80% budget utilization.
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
