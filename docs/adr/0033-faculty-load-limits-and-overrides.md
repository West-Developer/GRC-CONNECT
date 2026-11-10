# ADR 0033 — Faculty Load Limits per Employment Type and Per-Professor Overrides

**Status:** Accepted
**Date:** 2026-09-26
**Amends:** PRD §3.4 (Program Head plans faculty loading) and §3.5 (Dean oversight). Builds on the single college threshold in `faculty_load_thresholds`.

## Context

Stakeholder Doc 14: the Dean and Program Head must be able to control how many units a professor may carry, the limit must differ for full-time and part-time professors, and when no professor is available a specific professor's limit must be raisable (or lowerable). The owner confirmed on 2026-09-26: configurable, no invented numbers, Dean scope is the Dean's own college.

Before this change (verified in code): one `max_units` per term and college (`faculty_load_thresholds`), set by the Program Head only. `users.employment_type` (full-time or part-time) was planning metadata and did not affect any limit. `BuildFacultyLoadReport` flagged a professor `overloaded` when their units exceeded that single number.

## Decisions

1. **Three layers, highest wins.** For a professor in a term: (1) their own override, (2) the limit for their employment type in that college and term, (3) the college default (the existing threshold), (4) no limit. Implemented in `EffectiveFacultyLoadLimit::resolve`. A professor with no limit is never flagged overloaded, and the report says "No limit set". No default number exists anywhere in code.
2. **Storage.** `faculty_load_limits` (term, college, employment type, `max_units`, unique per triple) and `faculty_load_overrides` (term, professor, `max_units`, reason, set by, unique per pair). Both reversible; `dateTime` columns.
3. **Who.** Program Head and Dean, own college only. `FacultyLoadLimitPolicy::manage` needs the role and a college on the account; the college comes from the acting user, never from the request. An override can only target a Faculty user of the same college (a professor with no college on file is allowed, because some legacy faculty accounts have none).
4. **Reason and audit.** An override needs a reason (3 to 1000 characters). Setting or changing a limit, setting an override, and clearing an override each write an audit row with before and after values (`faculty_load_limit.updated`, `faculty_load_override.set`, `faculty_load_override.cleared`). Repeating the value already in place, or clearing an override that is not there, changes nothing and writes no audit row.
5. **Report.** `faculty-load-report` gains `limits` (per type, `max_units` null when unset) and, per professor, `employment_type`, `max_units`, `limit_source` (`override`, `employment_type`, `college_default`, or null), `override`, and `overloaded` now judged against the effective maximum. The existing `threshold_units` and `PUT faculty-load-threshold` stay as the college default.
6. **Assigning is unchanged.** A limit never blocks an assignment; it only flags. Assigning professors keeps using `UpdateSection` with `manual_override_reason`.

## Consequences

- The Program Head's Faculty Loading page shows Full-time, Part-time, and default maximums and, per professor, the load against the maximum that applies and where it came from; "Set max load" opens a shared dialog (also for the Dean's page, S15).
- Existing colleges keep working: with only the old threshold configured, every professor resolves to "College default".
- Not covered here: the Dean's own page and roster of all professors (S15).
