# ADR 0004 — Private Prediction-Service HTTP Bridge

**Status:** Accepted  
**Date:** 2026-07-26  
**Decision source:** PRD §6.4 and §11

## Context

Prediction workloads require Python libraries and lifecycle controls that do not belong inside page rendering or ad-hoc PHP shell commands. Predictive results are sensitive, advisory, and must not directly mutate enrollment decisions.

## Decision

- Laravel calls FastAPI over a private, versioned internal HTTP contract under `/internal/v1`.
- Browsers never call the prediction service.
- Laravel sends only approved minimum features and validates every response before storage.
- Dashboards read the latest successful stored result; they do not invoke models during rendering.
- The future Laravel client boundary will define timeouts, limited retry behavior, correlation IDs, schema versions, and safe dependency errors.
- Model loading belongs to FastAPI lifespan/startup, not per-request execution.
- Direct `shell_exec()` is not part of the production architecture.

## Consequences

- Deployment must enforce a network rule allowing only the backend to reach FastAPI.
- Student identifiers and features require data-minimization and authorization review before any prediction endpoint ships.
- Missing model, malformed response, timeout, and service-unavailable paths require automated tests.
- Phase 0A exposes only a no-data health contract and does not imply that prediction fallback behavior is implemented.
