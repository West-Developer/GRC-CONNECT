import { test } from "@playwright/test"

// Journey 14: Prediction-service failure with cached fallback — skipped.
//
// The ml-service is dormant (Phase 9 not started); none of the 48 live API
// routes are prediction routes, and AGENTS.md forbids touching ml-service
// before Phase 9. There is no cached-fallback behavior to exercise yet.
// Recorded as a deliberate, documented gap rather than silently omitted.

test.skip(
  "journey 14 — prediction-service failure with cached fallback (blocked on Phase 9)",
  () => {},
)
