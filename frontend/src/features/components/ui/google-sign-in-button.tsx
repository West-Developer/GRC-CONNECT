"use client"

import Script from "next/script"
import { useCallback, useEffect, useRef, useState } from "react"

import { isAuthError } from "@/features/auth/auth-error"
import { useAuth } from "@/features/auth/use-auth"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"

declare global {
  interface Window {
    google?: {
      accounts: {
        id: {
          initialize: (config: {
            client_id: string
            callback: (response: { credential: string }) => void
          }) => void
          renderButton: (
            parent: HTMLElement,
            options: Record<string, unknown>,
          ) => void
        }
      }
    }
  }
}

interface GoogleSignInButtonProps {
  /** Called once `signInWithGoogle` has established a session. */
  onSignedIn: () => void
}

const notFoundMessage =
  "No GRC account was found for this Google email. Please contact Admission or the Registrar's Office to have your account created."
const genericErrorMessage =
  "Google sign-in could not be completed. Please try again."

/**
 * Renders Google's own "Sign in with Google" button via the Identity
 * Services JS SDK — the frontend receives a signed ID token directly in this
 * callback and posts it once to `POST /auth/google`; no redirect, no client
 * secret. Renders nothing when `NEXT_PUBLIC_GOOGLE_CLIENT_ID` is unset, so an
 * environment that hasn't configured Google yet simply shows no button
 * rather than a broken one.
 */
export function GoogleSignInButton({ onSignedIn }: GoogleSignInButtonProps) {
  const { signInWithGoogle } = useAuth()
  // Lazily true if a previous mount already loaded the script (e.g. after
  // client-side navigation away from and back to this page) — next/script
  // dedupes the tag by `src` and does not reliably re-fire `onLoad` for
  // every remount that reuses it, which otherwise left the button
  // permanently missing until a hard refresh.
  const [scriptLoaded, setScriptLoaded] = useState(
    () => typeof window !== "undefined" && Boolean(window.google?.accounts?.id),
  )
  const [error, setError] = useState<string | null>(null)
  const containerRef = useRef<HTMLDivElement>(null)
  const clientId = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID

  const handleCredential = useCallback(
    (response: { credential: string }) => {
      setError(null)
      void (async () => {
        try {
          await signInWithGoogle(response.credential)
          onSignedIn()
        } catch (cause) {
          setError(
            isAuthError(cause) && cause.code === "GOOGLE_ACCOUNT_NOT_FOUND"
              ? notFoundMessage
              : genericErrorMessage,
          )
        }
      })()
    },
    [signInWithGoogle, onSignedIn],
  )

  // Defensive fallback for the same remount scenario: keeps checking for the
  // global directly rather than trusting `onLoad` alone, so a missed load
  // event self-heals within a fraction of a second instead of needing a
  // manual page refresh.
  useEffect(() => {
    if (scriptLoaded) return

    const interval = window.setInterval(() => {
      if (window.google?.accounts?.id) {
        setScriptLoaded(true)
      }
    }, 200)

    return () => window.clearInterval(interval)
  }, [scriptLoaded])

  useEffect(() => {
    if (
      !scriptLoaded ||
      !clientId ||
      !containerRef.current ||
      !window.google
    ) {
      return
    }

    window.google.accounts.id.initialize({
      client_id: clientId,
      callback: handleCredential,
    })

    window.google.accounts.id.renderButton(containerRef.current, {
      type: "standard",
      theme: "outline",
      size: "large",
      width: 320,
    })
  }, [scriptLoaded, clientId, handleCredential])

  if (!clientId) {
    return null
  }

  return (
    <div className="google-sign-in">
      <Script
        src="https://accounts.google.com/gsi/client"
        strategy="afterInteractive"
        onLoad={() => setScriptLoaded(true)}
      />
      <div ref={containerRef} className="google-sign-in-button" />
      {error && (
        <Alert variant="destructive" className="google-sign-in-alert">
          <AlertTitle>Google sign-in unavailable</AlertTitle>
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
    </div>
  )
}
