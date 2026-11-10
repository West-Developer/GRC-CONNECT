import { renderHook, act } from "@testing-library/react"
import { describe, expect, it, vi, beforeEach } from "vitest"
import type { ReactNode } from "react"
import { QueryClient, QueryClientProvider } from "@tanstack/react-query"
import { toast } from "sonner"

import { useSuperAdminActingContext } from "@/features/hooks/use-super-admin-acting-context"
import { AuthContext, type AuthContextValue } from "@/features/auth/auth-context-value"
import * as superAdminService from "@/features/services/super-admin-service"
import * as authService from "@/features/services/auth-service"
import * as apiClient from "@/features/services/api-client"
import type { AuthenticatedUser } from "@/features/schemas/auth-schema"
import { agreedPrivacyNotice } from "@/tests/privacy-notice-fixture"

const mockReplace = vi.fn()
vi.mock("next/navigation", () => ({
  useRouter: () => ({
    replace: mockReplace,
  }),
}))

vi.mock("sonner", () => ({
  toast: {
    info: vi.fn(),
  },
}))

describe("useSuperAdminActingContext", () => {
  let queryClient: QueryClient
  let replaceSessionMock: (user: AuthenticatedUser) => void

  beforeEach(() => {
    vi.clearAllMocks()
    queryClient = new QueryClient()
    vi.spyOn(queryClient, "cancelQueries")
    vi.spyOn(queryClient, "clear")
    replaceSessionMock = vi.fn()
  })

  function createWrapper(sessionValue: AuthContextValue["session"]) {
    const authValue: AuthContextValue = {
      session: sessionValue,
      status: sessionValue ? "authenticated" : "anonymous",
      storageAvailable: true,
      signIn: vi.fn(),
      verifyLoginOtp: vi.fn(),
      resendLoginOtp: vi.fn(),
      signInWithGoogle: vi.fn(),
      signOut: vi.fn(),
      replaceSession: replaceSessionMock,
    }

    return function Wrapper({ children }: { children: ReactNode }) {
      return (
        <QueryClientProvider client={queryClient}>
          <AuthContext.Provider value={authValue}>{children}</AuthContext.Provider>
        </QueryClientProvider>
      )
    }
  }

  it("identifies super admin in console mode", () => {
    const wrapper = createWrapper({
      userId: "1",
      displayName: "Super Admin",
      role: "super_admin",
      signedInAt: "2026-10-01T00:00:00Z",
      superAdmin: { actingContext: null },
    })

    const { result } = renderHook(() => useSuperAdminActingContext(), { wrapper })

    expect(result.current.isSuperAdmin).toBe(true)
    expect(result.current.isActing).toBe(false)
    expect(result.current.actingContext).toBeNull()
  })

  it("identifies super admin in acting mode", () => {
    const wrapper = createWrapper({
      userId: "1",
      displayName: "Super Admin",
      role: "dean",
      college: "ccs",
      signedInAt: "2026-10-01T00:00:00Z",
      superAdmin: { actingContext: { role: "dean", college: "ccs" } },
    })

    const { result } = renderHook(() => useSuperAdminActingContext(), { wrapper })

    expect(result.current.isSuperAdmin).toBe(true)
    expect(result.current.isActing).toBe(true)
    expect(result.current.actingContext).toEqual({ role: "dean", college: "ccs" })
  })

  it("switches acting context, updates session, clears queries, and navigates", async () => {
    const userResult: AuthenticatedUser = {
      type: "user",
      id: 1,
      name: "Super Admin",
      email: "admin@grc.edu.ph",
      role: "dean",
      role_label: "Dean",
      privacy_notice: agreedPrivacyNotice,
      college: "ccs",
      status: "active",
      acting_context: { role: "dean", college: "ccs" },
    }

    vi.spyOn(superAdminService, "switchActingContext").mockResolvedValueOnce({
      data: userResult,
    })

    const wrapper = createWrapper({
      userId: "1",
      displayName: "Super Admin",
      role: "super_admin",
      signedInAt: "2026-10-01T00:00:00Z",
      superAdmin: { actingContext: null },
    })

    const { result } = renderHook(() => useSuperAdminActingContext(), { wrapper })

    await act(async () => {
      await result.current.switchTo({ role: "dean", college: "ccs" })
    })

    expect(superAdminService.switchActingContext).toHaveBeenCalledWith({
      role: "dean",
      college: "ccs",
    })
    expect(replaceSessionMock).toHaveBeenCalledWith(userResult)
    expect(queryClient.cancelQueries).toHaveBeenCalled()
    expect(queryClient.clear).toHaveBeenCalled()
    expect(mockReplace).toHaveBeenCalledWith("/portal")
  })

  it("exits acting context, returns to console, and navigates", async () => {
    const userResult: AuthenticatedUser = {
      type: "user",
      id: 1,
      name: "Super Admin",
      email: "admin@grc.edu.ph",
      role: "super_admin",
      role_label: "Super Admin",
      privacy_notice: agreedPrivacyNotice,
      college: null,
      status: "active",
      acting_context: null,
    }

    vi.spyOn(superAdminService, "clearActingContext").mockResolvedValueOnce({
      data: userResult,
    })

    const wrapper = createWrapper({
      userId: "1",
      displayName: "Super Admin",
      role: "dean",
      college: "ccs",
      signedInAt: "2026-10-01T00:00:00Z",
      superAdmin: { actingContext: { role: "dean", college: "ccs" } },
    })

    const { result } = renderHook(() => useSuperAdminActingContext(), { wrapper })

    await act(async () => {
      await result.current.exit()
    })

    expect(superAdminService.clearActingContext).toHaveBeenCalled()
    expect(replaceSessionMock).toHaveBeenCalledWith(userResult)
    expect(queryClient.cancelQueries).toHaveBeenCalled()
    expect(queryClient.clear).toHaveBeenCalled()
    expect(mockReplace).toHaveBeenCalledWith("/portal")
  })

  it("resyncs session when acting context conflict occurs across tabs", async () => {
    let conflictHandler: (() => void) | undefined
    vi.spyOn(apiClient, "setActingContextConflictHandler").mockImplementation(
      (handler) => {
        conflictHandler = handler
      },
    )

    const refreshedUser: AuthenticatedUser = {
      type: "user",
      id: 1,
      name: "Super Admin",
      email: "admin@grc.edu.ph",
      role: "registrar_head",
      role_label: "Registrar Head",
      privacy_notice: agreedPrivacyNotice,
      college: null,
      status: "active",
      acting_context: { role: "registrar_head", college: null },
    }

    vi.spyOn(authService, "fetchCurrentUser").mockResolvedValueOnce(refreshedUser)

    const wrapper = createWrapper({
      userId: "1",
      displayName: "Super Admin",
      role: "dean",
      college: "ccs",
      signedInAt: "2026-10-01T00:00:00Z",
      superAdmin: { actingContext: { role: "dean", college: "ccs" } },
    })

    renderHook(() => useSuperAdminActingContext(), { wrapper })

    expect(conflictHandler).toBeDefined()

    await act(async () => {
      await conflictHandler?.()
    })

    expect(authService.fetchCurrentUser).toHaveBeenCalled()
    expect(replaceSessionMock).toHaveBeenCalledWith(refreshedUser)
    expect(queryClient.cancelQueries).toHaveBeenCalled()
    expect(queryClient.clear).toHaveBeenCalled()
    expect(toast.info).toHaveBeenCalledWith(
      "Your workspace changed in another tab — refreshed.",
    )
  })
})
