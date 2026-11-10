// vitest-axe@0.1.0 ships its matcher-type augmentation as `declare global {
// namespace Vi { ... } }`, which does not merge with this Vitest version's
// `Assertion` interface — Vitest 4 exposes it via `declare module "vitest"`
// instead (see @testing-library/jest-dom/types/vitest.d.ts for the same
// pattern, already working in this project). Re-declared locally so
// `toHaveNoViolations` actually type-checks.
import type { AxeMatchers } from "vitest-axe/matchers"

declare module "vitest" {
  // Both rules disabled together: the generic must be declared to merge with
  // vitest's own `Assertion<T>` (even though AxeMatchers doesn't use it), and
  // an empty body is the correct shape for a pure declaration-merge — this
  // mirrors jest-dom's own working vitest.d.ts augmentation.
  // eslint-disable-next-line @typescript-eslint/no-unused-vars, @typescript-eslint/no-empty-object-type
  interface Assertion<T = unknown> extends AxeMatchers {}
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type -- same declaration-merging reason as above.
  interface AsymmetricMatchersContaining extends AxeMatchers {}
}
