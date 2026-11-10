# ADR 0001 — API-First Three-Service Boundaries

**Status:** Accepted  
**Date:** 2026-07-26  
**Decision source:** PRD §6–§8 and §16 Phase 0

## Context

The product requires an independently deployable React SPA, Laravel REST API, centralized MySQL data layer, and private prediction engine. The initial repository contains no implementation, so the first slice must establish boundaries without introducing business behavior or policy values.

## Decision

- `frontend/` is a Vite React strict-TypeScript SPA and communicates only with the Laravel API.
- `backend/` owns public application contracts under `/api/v1`, authorization, business transactions, persistence, and the browser-facing error envelope.
- `ml-service/` is a private FastAPI service. Only Laravel may call its versioned internal endpoints.
- MySQL is accessed by the backend and approved offline/model workflows; the browser never accesses it directly.
- The first Phase 0A contract is a database-independent health endpoint in each server boundary, with narrow tests and no authentication or institutional rules.

```text
Browser
  │ bearer HTTPS
  ▼
React SPA ───────► Laravel /api/v1 ───────► MySQL 8.4 LTS
                         │
                         │ private, versioned HTTP
                         ▼
                 FastAPI /internal/v1
```

## Consequences

- Services can be installed, run, tested, and deployed independently.
- Browser code cannot bypass Laravel authorization by calling the prediction service.
- Contract changes require synchronized API documentation and tests.
- Health-shell completion does not imply that authentication, database migrations, or any enrollment workflow is complete.
- Local MariaDB cannot be used to claim MySQL compatibility.
