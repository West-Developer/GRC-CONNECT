"use client"

import { useCallback, useEffect, useRef, useState } from "react"

import type { Credentials } from "@/features/auth/auth-types"
import {
  createBrowserKioskTokenStore,
  type KioskTokenStore,
} from "@/features/kiosk/kiosk-token"
import type { AuthenticatedUser } from "@/features/schemas/auth-schema"
import {
  fetchCurrentUser,
  login,
  loginBehindQueueKiosk,
  logout,
} from "@/features/services/auth-service"

export type QueueKioskSessionState =
  | { status: "restoring-device" }
  | { status: "device-login"; error: string | null }
  | {
      status: "student-login"
      kioskToken: string
      kioskUser: AuthenticatedUser
      error: string | null
    }
  | {
      status: "student-active"
      kioskToken: string
      kioskUser: AuthenticatedUser
      studentToken: string
      studentUser: AuthenticatedUser
    }

interface QueueKioskSessionOptions {
  tokenStore?: KioskTokenStore
}

const deviceRoleError = "This account is not authorized for the Queue Kiosk."
const studentRoleError =
  "This account is not authorized to view a Student queue."
const storageError =
  "This device cannot securely retain its kiosk session. Enable browser storage and try again."
const credentialError = "The email or password you entered was not recognized."

function revokeToken(token: string): void {
  void logout(undefined, {
    token,
    suppressUnauthorizedHandler: true,
  }).catch(() => undefined)
}

/**
 * A kiosk owns a persistent device session and one disposable in-memory
 * Student session. The separate state branches make invalid transitions
 * impossible to render and keep tokens out of query keys and browser storage.
 */
export function useQueueKioskSession({
  tokenStore: suppliedTokenStore,
}: QueueKioskSessionOptions = {}) {
  const [tokenStore] = useState<KioskTokenStore>(
    () => suppliedTokenStore ?? createBrowserKioskTokenStore(),
  )
  const operationRef = useRef(0)
  const invalidateOperation = useCallback(() => {
    operationRef.current++
  }, [])
  const [state, setState] = useState<QueueKioskSessionState>({
    status: "restoring-device",
  })

  useEffect(() => {
    const operation = ++operationRef.current
    const token = tokenStore.read()

    if (token === null) {
      queueMicrotask(() => {
        if (operation === operationRef.current) {
          setState({ status: "device-login", error: null })
        }
      })
      return () => {
        invalidateOperation()
      }
    }

    void fetchCurrentUser(undefined, {
      token,
      suppressUnauthorizedHandler: true,
    })
      .then((user) => {
        if (operation !== operationRef.current) return
        if (user.role !== "queue_kiosk") {
          tokenStore.clear()
          revokeToken(token)
          setState({ status: "device-login", error: null })
          return
        }
        setState({
          status: "student-login",
          kioskToken: token,
          kioskUser: user,
          error: null,
        })
      })
      .catch(() => {
        if (operation !== operationRef.current) return
        tokenStore.clear()
        setState({ status: "device-login", error: null })
      })
    return () => {
      invalidateOperation()
    }
  }, [invalidateOperation, tokenStore])

  const signInDevice = useCallback(
    async (credentials: Credentials) => {
      const operation = ++operationRef.current
      let result: Awaited<ReturnType<typeof login>>
      try {
        result = await login({
          email: credentials.email.trim().toLowerCase(),
          password: credentials.password,
        })
      } catch {
        if (operation === operationRef.current) {
          setState({ status: "device-login", error: credentialError })
        }
        return
      }

      if (result.kind === "otp_required") {
        // A queue_kiosk account is always exempt from OTP server-side
        // (LoginOtpPolicy), so this should be unreachable for the device
        // role — treated as a role mismatch rather than left to crash on
        // the absent token/user fields.
        if (operation === operationRef.current) {
          setState({ status: "device-login", error: deviceRoleError })
        }
        return
      }

      const payload = result.session

      if (operation !== operationRef.current) {
        revokeToken(payload.token)
        return
      }

      if (payload.user.role !== "queue_kiosk") {
        revokeToken(payload.token)
        if (operation === operationRef.current) {
          setState({ status: "device-login", error: deviceRoleError })
        }
        return
      }

      if (!tokenStore.write(payload.token)) {
        revokeToken(payload.token)
        if (operation === operationRef.current) {
          setState({ status: "device-login", error: storageError })
        }
        return
      }

      if (operation === operationRef.current) {
        setState({
          status: "student-login",
          kioskToken: payload.token,
          kioskUser: payload.user,
          error: null,
        })
      }
    },
    [tokenStore],
  )

  const signInStudent = useCallback(
    async (credentials: Credentials) => {
      if (state.status !== "student-login") return
      const currentDevice = state
      const operation = ++operationRef.current
      let result: Awaited<ReturnType<typeof loginBehindQueueKiosk>>
      try {
        result = await loginBehindQueueKiosk(
          {
            email: credentials.email.trim().toLowerCase(),
            password: credentials.password,
          },
          currentDevice.kioskToken,
        )
      } catch {
        if (operation === operationRef.current) {
          setState({ ...currentDevice, error: credentialError })
        }
        return
      }

      if (result.kind === "otp_required") {
        // The kiosk-token proof carried above makes this unreachable in
        // practice (see LoginController::isVerifiedByKioskDevice) — surfaced
        // as a generic credential failure rather than left to crash on the
        // absent token/user fields.
        if (operation === operationRef.current) {
          setState({ ...currentDevice, error: credentialError })
        }
        return
      }

      const payload = result.session

      if (operation !== operationRef.current) {
        revokeToken(payload.token)
        return
      }

      if (payload.user.role !== "student") {
        revokeToken(payload.token)
        if (operation === operationRef.current) {
          setState({ ...currentDevice, error: studentRoleError })
        }
        return
      }

      if (operation === operationRef.current) {
        setState({
          status: "student-active",
          kioskToken: currentDevice.kioskToken,
          kioskUser: currentDevice.kioskUser,
          studentToken: payload.token,
          studentUser: payload.user,
        })
      }
    },
    [state],
  )

  const finishStudent = useCallback(() => {
    if (state.status !== "student-active") return
    const { kioskToken, kioskUser, studentToken } = state
    ++operationRef.current
    setState({ status: "student-login", kioskToken, kioskUser, error: null })
    revokeToken(studentToken)
  }, [state])

  const signOutDevice = useCallback(() => {
    const priorState = state
    ++operationRef.current
    tokenStore.clear()
    setState({ status: "device-login", error: null })

    if (priorState.status === "student-active")
      revokeToken(priorState.studentToken)
    if (
      priorState.status === "student-login" ||
      priorState.status === "student-active"
    ) {
      revokeToken(priorState.kioskToken)
    }
  }, [state, tokenStore])

  return { state, signInDevice, signInStudent, finishStudent, signOutDevice }
}
