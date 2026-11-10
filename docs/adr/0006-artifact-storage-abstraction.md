# ADR 0006 — Artifact Storage Abstraction

**Status:** Accepted at the abstraction level; production disk blocked  
**Date:** 2026-07-26  
**Decision source:** PRD §10.3, §10.4, §15.8, and §17

## Context

The product will store enrollment documents, compliance exports, and approved model artifacts. Hosting, retention, backup, restoration, and disposal policies are not confirmed.

## Decision

- Application artifacts use framework storage abstractions rather than hard-coded local filesystem paths.
- Laravel-managed documents and exports use configured Laravel Filesystem disks.
- Prediction model artifacts stay behind the private prediction-service boundary and are versioned separately from source code.
- Repository-local artifact/model directories contain placeholders only; generated or sensitive artifacts are ignored.
- The production storage provider, encryption controls, signed-download strategy, retention schedule, and backup target remain deferred to authorized infrastructure and policy decisions.

## Consequences

- Development can use non-sensitive local test artifacts without constraining production hosting.
- API Resources must never expose raw storage paths.
- Deployment and recovery tests cannot complete until the production storage decision is approved.
