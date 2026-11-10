# ADR 0002 — Laravel 12 Local Compatibility Bridge

**Status:** Accepted with mandatory revisit  
**Date:** 2026-07-26  
**Review by:** Before Phase 0 completion or any production-like deployment

## Context

Laravel 13 is the current stable major and requires PHP 8.3 or newer. The workstation's XAMPP runtime is PHP 8.2.12. Replacing the shared XAMPP runtime is a machine-level operation outside this repository slice.

## Decision

Use Laravel 12 for the initial database-independent Phase 0A scaffold because it supports PHP 8.2. Treat it as a short-lived compatibility bridge:

- do not present Laravel 12/PHP 8.2.12 as the final production platform;
- update local PHP to the latest 8.2 security patch as soon as practical;
- target PHP 8.4 or 8.5 and re-evaluate Laravel 13 before Phase 0 is complete;
- keep application code within documented Laravel APIs to reduce upgrade friction;
- record the exact resolved framework version in `backend/composer.lock`.

## Consequences

- The backend shell can be run and tested on the current workstation.
- A framework/runtime upgrade remains explicit Phase 0 work.
- PHP/Laravel compatibility and the full suite must be rerun after the upgrade.
- This ADR does not authorize older unsupported dependencies in production.
