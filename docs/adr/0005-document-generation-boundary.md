# ADR 0005 — Enrollment Document Generation Boundary

**Status:** Partially accepted; template and policy decision blocked  
**Date:** 2026-07-26  
**Decision source:** PRD §4.2, §5.3, §10.3, and §17

## Context

Payment confirmation must finalize enrollment and generate a Digital Certificate of Matriculation (COM) idempotently. GRC has not confirmed the official COM format, numbering, signatures, retention, or whether Certificate of Registration (COR) is a separate artifact.

## Decision

- A future Laravel document-generator contract will isolate rendering from the payment-confirmation action.
- The payment-confirmation transaction/safely retryable workflow will create at most one document record per enrollment and approved document type.
- Generated content will have a stored hash and auditable generation time.
- No COM/COR template, numbering scheme, signature field, or separate COR behavior is implemented until GRC resolves PRD §17.

## Consequences

- Backend business logic can depend on an interface and remain testable without selecting a PDF engine prematurely.
- Template acceptance and storage/retention tests remain blocked on institutional decisions.
- This ADR does not mark the Phase 4 document workflow complete.
