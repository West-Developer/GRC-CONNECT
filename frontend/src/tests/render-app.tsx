import { QueryClientProvider } from "@tanstack/react-query"
import { render } from "@testing-library/react"
import type { ReactNode } from "react"

import { AuthProvider } from "@/features/auth/auth-context"
import {
  AuthContext,
  type AuthContextValue,
} from "@/features/auth/auth-context-value"
import type { AuthGateway, AuthSession } from "@/features/auth/auth-types"
import { createAppQueryClient } from "@/features/lib/query-client"
import { setLocation, setRouteParams } from "@/tests/navigation-mock"

export const testSession: AuthSession = {
  userId: "1",
  displayName: "Test Person",
  role: "student",
  signedInAt: "2026-07-28T00:00:00.000Z",
}

/**
 * A gateway that resolves immediately, so `AuthProvider` leaves its
 * "restoring" state on the first flush rather than hanging there.
 */
export function createStubGateway(
  overrides: Partial<AuthGateway> = {},
): AuthGateway {
  return {
    signIn: () => Promise.resolve(testSession),
    verifyLoginOtp: () =>
      Promise.reject(new Error("verifyLoginOtp: not implemented in stub")),
    resendLoginOtp: () =>
      Promise.reject(new Error("resendLoginOtp: not implemented in stub")),
    signInWithGoogle: () =>
      Promise.reject(new Error("signInWithGoogle: not implemented in stub")),
    restore: () => Promise.resolve(null),
    signOut: () => Promise.resolve(),
    clearSession: () => undefined,
    persistenceAvailable: () => true,
    ...overrides,
  }
}

interface RenderOptions {
  /** Simulated URL, e.g. `/portal/enrollment?tab=available`. */
  route?: string
  /** Simulated dynamic segments, e.g. `{ moduleId: "enrollment" }`. */
  routeParams?: Record<string, string>
}

/**
 * Renders a tree against the real `AuthProvider` and a stub gateway. Use this
 * when the test exercises sign-in, restore, or sign-out behaviour.
 */
export function renderWithAuthProvider(
  ui: ReactNode,
  {
    gateway = createStubGateway(),
    route = "/",
    routeParams = {},
  }: RenderOptions & { gateway?: AuthGateway } = {},
) {
  setLocation(route)
  setRouteParams(routeParams)

  const queryClient = createAppQueryClient()

  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <AuthProvider gateway={gateway}>{ui}</AuthProvider>
      </QueryClientProvider>,
    ),
    gateway,
    queryClient,
  }
}

/**
 * Renders a tree against a fixed auth state, skipping the provider entirely.
 * Use this when the test cares about what a given session *renders*, not about
 * how that session was established.
 */
export function renderWithSession(
  ui: ReactNode,
  {
    route = "/",
    routeParams = {},
    session = testSession,
    signIn = () => Promise.resolve(testSession),
    verifyLoginOtp = () =>
      Promise.reject(new Error("verifyLoginOtp: not implemented in stub")),
    resendLoginOtp = () =>
      Promise.reject(new Error("resendLoginOtp: not implemented in stub")),
    signInWithGoogle = () =>
      Promise.reject(new Error("signInWithGoogle: not implemented in stub")),
    signOut = () => undefined,
    replaceSession = () => undefined,
    status = "authenticated",
    storageAvailable = true,
  }: RenderOptions & Partial<AuthContextValue> = {},
) {
  setLocation(route)
  setRouteParams(routeParams)

  const value: AuthContextValue = {
    session,
    signIn,
    verifyLoginOtp,
    resendLoginOtp,
    signInWithGoogle,
    signOut,
    replaceSession,
    status,
    storageAvailable,
  }
  const queryClient = createAppQueryClient()

  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider value={value}>{ui}</AuthContext.Provider>
      </QueryClientProvider>,
    ),
    value,
    queryClient,
  }
}
