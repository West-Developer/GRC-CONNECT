import { vi } from "vitest"

/**
 * Controllable stand-in for the App Router's navigation state.
 *
 * react-router's `MemoryRouter` used to drive real routing in tests and a
 * `LocationProbe` read the resulting URL back. The App Router has no
 * equivalent, so routing is asserted here through `routerMock.replace`/`push`
 * instead: "the guard tried to send you to /login?returnTo=…". Real
 * end-to-end URL behaviour is covered by Playwright in roadmap Phase 8.
 */
export const routerMock = {
  push: vi.fn(),
  replace: vi.fn(),
  back: vi.fn(),
  forward: vi.fn(),
  refresh: vi.fn(),
  prefetch: vi.fn(),
}

let pathname = "/"
let searchParams = new URLSearchParams()
let params: Record<string, string> = {}

/** Sets the simulated location, e.g. `/portal/enrollment?tab=available`. */
export function setLocation(url: string): void {
  const [path = "/", search = ""] = url.split("?")

  pathname = path
  searchParams = new URLSearchParams(search)
}

/** Sets the simulated dynamic route segments, e.g. `{ moduleId: "grades" }`. */
export function setRouteParams(next: Record<string, string>): void {
  params = next
}

export function getPathname(): string {
  return pathname
}

export function getSearchParams(): URLSearchParams {
  return searchParams
}

export function getRouteParams(): Record<string, string> {
  return params
}

export function resetNavigation(): void {
  pathname = "/"
  searchParams = new URLSearchParams()
  params = {}
}
