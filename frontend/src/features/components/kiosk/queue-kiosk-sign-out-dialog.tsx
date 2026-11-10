"use client"

import { useState } from "react"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { login, logout } from "@/features/services/auth-service"

export function QueueKioskSignOutDialog({
  open,
  onOpenChange,
  onConfirmSignOut,
  kioskEmail = "queue@grc.com",
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onConfirmSignOut: () => void
  kioskEmail?: string
}) {
  const [password, setPassword] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const handleVerify = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!password) return
    setLoading(true)
    setError(null)
    try {
      const result = await login({
        email: kioskEmail.trim().toLowerCase(),
        password,
      })
      // A queue_kiosk account is always exempt from OTP server-side, so this
      // should never resolve as a challenge — treated as a failed
      // verification rather than left to crash on the absent token field.
      if (result.kind === "otp_required") {
        setError("Incorrect password. Device sign-out aborted.")
        return
      }
      // Revoke the verification token
      void logout(undefined, {
        token: result.session.token,
        suppressUnauthorizedHandler: true,
      }).catch(() => undefined)
      setPassword("")
      onOpenChange(false)
      onConfirmSignOut()
    } catch {
      setError("Incorrect password. Device sign-out aborted.")
    } finally {
      setLoading(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Confirm Device Sign Out</DialogTitle>
          <DialogDescription>
            Enter the password for <strong>{kioskEmail}</strong> to sign out this
            device. This prevents students taking queue tickets from
            accidentally logging out the kiosk.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleVerify} className="space-y-4">
          <Field>
            <FieldLabel htmlFor="kiosk-signout-password">Password</FieldLabel>
            <Input
              id="kiosk-signout-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Enter queue password"
              autoComplete="current-password"
              autoFocus
              required
            />
          </Field>
          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setPassword("")
                setError(null)
                onOpenChange(false)
              }}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="destructive"
              disabled={!password || loading}
            >
              {loading ? "Verifying…" : "Verify & Sign out"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

