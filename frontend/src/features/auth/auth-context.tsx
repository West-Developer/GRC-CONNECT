"use client"

import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react"

import {
  AuthContext,
  type AuthContextValue,
} from "@/features/auth/auth-context-value"
import { toSession } from "@/features/auth/api-auth-gateway"
import { browserActingContextStore } from "@/features/auth/acting-context-store"
import type {
  AuthGateway,
  AuthSession,
  Credentials,
} from "@/features/auth/auth-types"
import type { AuthenticatedUser } from "@/features/schemas/auth-schema"
import { setUnauthorizedHandler } from "@/features/services/api-client"
import { browserFormDraftStore } from "@/features/services/form-draft-store"

interface AuthProviderProps {
  children: ReactNode
  gateway: AuthGateway
}

export function AuthProvider({ children, gateway }: AuthProviderProps) {
  const [session, setSession] = useState<AuthSession | null>(null)
  const [status, setStatus] = useState<AuthContextValue["status"]>("restoring")
  const [storageAvailable, setStorageAvailable] = useState(true)

  useEffect(() => {
    let active = true

    // Restoring requires a network round-trip to `GET /auth/me`, so the first
    // render is always "restoring". That is also what keeps hydration safe:
    // the server-rendered markup and the first client render agree, and the
    // real session only lands on a subsequent render.
    gateway.restore().then(
      (restored) => {
        if (!active) {
          return
        }

        if (restored?.superAdmin !== undefined) {
          browserActingContextStore.set(restored.superAdmin.actingContext)
        } else {
          browserActingContextStore.clear()
        }

        setSession(restored)
        setStatus(restored ? "authenticated" : "anonymous")
      },
      () => {
        if (active) {
          browserActingContextStore.clear()
          setSession(null)
          setStatus("anonymous")
        }
      },
    )

    return () => {
      active = false
    }
  }, [gateway])

  useEffect(() => {
    // A 401 on an authenticated request means the stored token is already
    // invalid — clear it locally (no revoke round-trip; that would just 401
    // again) and drop to "anonymous" so `RequireSession` performs its normal
    // redirect, instead of leaving a stale authenticated view rendered over a
    // cleared token.
    setUnauthorizedHandler(() => {
      gateway.clearSession()
      browserActingContextStore.clear()
      setSession(null)
      setStatus("anonymous")
    })
  }, [gateway])

  const signIn = useCallback(
    async (credentials: Credentials) => {
      const authenticatedSession = await gateway.signIn(credentials)

      if (authenticatedSession.superAdmin !== undefined) {
        browserActingContextStore.set(authenticatedSession.superAdmin.actingContext)
      } else {
        browserActingContextStore.clear()
      }

      setStorageAvailable(gateway.persistenceAvailable())
      setSession(authenticatedSession)
      setStatus("authenticated")

      return authenticatedSession
    },
    [gateway],
  )

  const verifyLoginOtp = useCallback(
    async (challengeToken: string, code: string) => {
      const authenticatedSession = await gateway.verifyLoginOtp(
        challengeToken,
        code,
      )

      if (authenticatedSession.superAdmin !== undefined) {
        browserActingContextStore.set(authenticatedSession.superAdmin.actingContext)
      } else {
        browserActingContextStore.clear()
      }

      setStorageAvailable(gateway.persistenceAvailable())
      setSession(authenticatedSession)
      setStatus("authenticated")

      return authenticatedSession
    },
    [gateway],
  )

  const resendLoginOtp = useCallback(
    (challengeToken: string) => gateway.resendLoginOtp(challengeToken),
    [gateway],
  )

  const signInWithGoogle = useCallback(
    async (credential: string) => {
      const authenticatedSession = await gateway.signInWithGoogle(credential)

      if (authenticatedSession.superAdmin !== undefined) {
        browserActingContextStore.set(authenticatedSession.superAdmin.actingContext)
      } else {
        browserActingContextStore.clear()
      }

      setStorageAvailable(gateway.persistenceAvailable())
      setSession(authenticatedSession)
      setStatus("authenticated")

      return authenticatedSession
    },
    [gateway],
  )

  const signOut = useCallback(() => {
    browserActingContextStore.clear()
    // Half-typed forms hold other people's personal details: the next person at this machine
    // starts clean. Only an explicit sign-out does this — a session that merely expired keeps
    // them, so signing in again brings back what was being typed.
    browserFormDraftStore.clearAll()
    // Revoke server-side, but clear locally without waiting: a failed or slow
    // revoke must never leave the user stuck in a signed-in UI. The rejection
    // is swallowed deliberately — without it a failed revoke surfaces as an
    // unhandled promise rejection in the browser console.
    void gateway.signOut().catch(() => undefined)

    setSession(null)
    setStatus("anonymous")
  }, [gateway])

  const replaceSession = useCallback(
    (user: AuthenticatedUser) => {
      const nextSession = toSession(user, session?.signedInAt)
      if (nextSession.superAdmin !== undefined) {
        browserActingContextStore.set(nextSession.superAdmin.actingContext)
      } else {
        browserActingContextStore.clear()
      }

      setSession(nextSession)
      setStatus("authenticated")
    },
    [session?.signedInAt],
  )

  const value = useMemo<AuthContextValue>(
    () => ({
      session,
      signIn,
      verifyLoginOtp,
      resendLoginOtp,
      signInWithGoogle,
      signOut,
      replaceSession,
      status,
      storageAvailable,
    }),
    [
      session,
      signIn,
      verifyLoginOtp,
      resendLoginOtp,
      signInWithGoogle,
      signOut,
      replaceSession,
      status,
      storageAvailable,
    ],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
