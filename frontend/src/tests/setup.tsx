import "@testing-library/jest-dom/vitest"

import { cleanup } from "@testing-library/react"
import type { AnchorHTMLAttributes, ReactNode } from "react"
import { afterEach, expect, vi } from "vitest"
import * as axeMatchers from "vitest-axe/matchers"

import {
  getPathname,
  getRouteParams,
  getSearchParams,
  resetNavigation,
  routerMock,
} from "@/tests/navigation-mock"

// Mocked once here rather than per file: every component that navigates pulls
// from `next/navigation`, and the App Router provides no in-memory router to
// wrap them in the way react-router's MemoryRouter did.
vi.mock("next/navigation", () => ({
  useRouter: () => routerMock,
  usePathname: () => getPathname(),
  useSearchParams: () => getSearchParams(),
  useParams: () => getRouteParams(),
}))

// `next/link` reaches for the real router context to prefetch, which does not
// exist under jsdom. A plain anchor preserves everything the tests assert on:
// the accessible name and the `href`.
vi.mock("next/link", () => ({
  default: ({
    children,
    href,
    ...rest
  }: {
    children: ReactNode
    href: string
  } & AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}))

expect.extend(axeMatchers)

// jsdom implements neither the Pointer Events capture API nor
// `scrollIntoView`. Radix's Select (and other Radix primitives using the same
// pointer-capture-based open/close handling) call these directly, so without
// a polyfill any `userEvent.click()` on a Select trigger throws. jsdom also
// implements no `matchMedia` at all — `motion`'s `useReducedMotion` (and any
// future `prefers-reduced-motion` check) calls it directly, so without a
// stub any component using it throws under test. The stub always reports "no
// preference"; tests that need to assert the reduced-motion branch stub this
// per-test instead of relying on the default.
/* eslint-disable @typescript-eslint/unbound-method -- these are plain
   arrow-function polyfills assigned to the prototype/window, not extracted
   method references; there is no `this` to detach. */
if (typeof window !== "undefined") {
  window.HTMLElement.prototype.hasPointerCapture ??= () => false
  window.HTMLElement.prototype.setPointerCapture ??= () => undefined
  window.HTMLElement.prototype.releasePointerCapture ??= () => undefined
  window.HTMLElement.prototype.scrollIntoView ??= () => undefined
  window.matchMedia ??= (query: string) =>
    ({
      // Return true for prefers-reduced-motion so GSAP animations are
      // skipped entirely in tests — elements that would otherwise be stuck
      // at opacity:0 mid-animation are immediately visible, which lets
      // assertions on DOM content and interactive elements work without
      // timing-based flakiness. Other queries that need non-default
      // behaviour should be stubbed per-test.
      matches: query.includes("prefers-reduced-motion"),
      media: query,
      onchange: null,
      addListener: () => undefined,
      removeListener: () => undefined,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
      dispatchEvent: () => false,
    }) as MediaQueryList
  // jsdom implements no ResizeObserver at all — Radix's Switch and Slider
  // (via @radix-ui/react-use-size) call `new ResizeObserver(...)` directly
  // in a layout effect to track thumb size, so without a stub any component
  // rendering one throws and unmounts the whole tree under test.
  window.ResizeObserver ??= class ResizeObserver {
    observe() {
      // No layout to observe under jsdom — Radix only needs the
      // constructor call itself not to throw.
    }
    unobserve() {
      /* no-op */
    }
    disconnect() {
      /* no-op */
    }
  }
}
/* eslint-enable @typescript-eslint/unbound-method */

afterEach(() => {
  cleanup()
  resetNavigation()
  // Form drafts live in sessionStorage and are saved when a form unmounts, so a
  // test that typed into a form would otherwise hand its draft to the next one.
  // After `cleanup()`, which is what triggers that last save.
  try {
    window.sessionStorage.clear()
  } catch {
    // No storage in this environment: nothing to carry over either.
  }
})
