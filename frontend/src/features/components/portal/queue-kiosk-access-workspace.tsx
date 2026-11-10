"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { Eye, EyeOff, KeyRound, LockKeyhole } from "lucide-react"
import { useEffect, useId, useRef, useState } from "react"
import { useForm } from "react-hook-form"
import { z } from "zod"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { StatusRegion } from "@/features/components/portal/status-region"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
  useFieldError,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  useQueueKioskCredentialMutation,
  useQueueKioskCredentialQuery,
  isQueueKioskCredentialMutationCancelledError,
} from "@/features/hooks/use-queue-kiosk-credential"

const changePasswordSchema = z.object({
  password: z.string().min(8, "Use at least 8 characters.").max(255),
})

type ChangePasswordInput = z.infer<typeof changePasswordSchema>

function QueueKioskAccessWorkspaceForViewer() {
  const { session } = useAuth()
  const authorized = session?.role === "accounting_staff"
  const credentialQuery = useQueueKioskCredentialQuery()
  const rotationMutation = useQueueKioskCredentialMutation()
  const [passwordVisible, setPasswordVisible] = useState(false)
  const [confirmingRotation, setConfirmingRotation] = useState(false)
  const [statusMessage, setStatusMessage] = useState<string | null>(null)
  const [rotationError, setRotationError] = useState<string | null>(null)
  const rotationLockedRef = useRef(false)
  const retryButtonId = useId()
  const form = useForm<ChangePasswordInput>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: { password: "" },
  })
  const {
    formState: { errors },
    handleSubmit,
    register,
    reset,
  } = form
  const passwordError = useFieldError(Boolean(errors.password))

  const requestRotation = () => {
    setStatusMessage(null)
    setRotationError(null)
    setConfirmingRotation(true)
  }

  const confirmRotation = async () => {
    if (rotationLockedRef.current || rotationMutation.isPending) return

    rotationLockedRef.current = true
    setRotationError(null)
    try {
      await rotationMutation.mutateAsync({
        password: form.getValues("password"),
      })
      reset()
      setConfirmingRotation(false)
      setPasswordVisible(false)
      setStatusMessage("Kiosk password rotated.")
    } catch (error) {
      if (isQueueKioskCredentialMutationCancelledError(error)) {
        return
      }

      setRotationError(
        "The kiosk password could not be rotated. Check the connection and try again.",
      )
    } finally {
      rotationLockedRef.current = false
    }
  }

  useEffect(() => {
    if (!rotationError || !confirmingRotation) return

    document.getElementById(retryButtonId)?.focus()
  }, [confirmingRotation, retryButtonId, rotationError])

  return (
    <WorkspacePage
      title="Queue kiosk access"
      description="Verify the shared kiosk identity and rotate its credential before a controlled front-desk handoff."
      unauthorized={!authorized}
      lastUpdated={credentialQuery.dataUpdatedAt}
    >
      <AsyncBoundary
        query={credentialQuery}
        loadingLabel="Loading kiosk access…"
      >
        {(credential) => (
          <Card>
            <CardHeader>
              <CardTitle level={2}>Queue kiosk credential</CardTitle>
              <CardDescription>
                Use this shared identity only at an authorized queue kiosk.
              </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
              <Alert>
                <LockKeyhole />
                <AlertTitle>Password is hidden by default.</AlertTitle>
                <AlertDescription>
                  Reveal it only for the immediate kiosk handoff, then hide it
                  again before leaving this workspace. This shared-device
                  credential must never be reused for a personal account.
                </AlertDescription>
              </Alert>
              {credential.password === "password" && (
                <Alert variant="destructive">
                  <KeyRound />
                  <AlertTitle>Rotate the default kiosk password now</AlertTitle>
                  <AlertDescription>
                    The seeded development credential is still active. Rotate it
                    immediately before this kiosk is used or handed off.
                  </AlertDescription>
                </Alert>
              )}
              <FieldGroup>
                <Field>
                  <FieldLabel htmlFor="queue-kiosk-email">
                    Kiosk email
                  </FieldLabel>
                  <Input
                    id="queue-kiosk-email"
                    readOnly
                    value={credential.email}
                  />
                </Field>
                <Field>
                  <FieldLabel htmlFor="queue-kiosk-password">
                    Kiosk password
                  </FieldLabel>
                  <Input
                    id="queue-kiosk-password"
                    readOnly
                    type={passwordVisible ? "text" : "password"}
                    value={credential.password}
                  />
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => setPasswordVisible((visible) => !visible)}
                  >
                    {passwordVisible ? (
                      <EyeOff data-icon="inline-start" />
                    ) : (
                      <Eye data-icon="inline-start" />
                    )}
                    {passwordVisible ? "Hide password" : "Reveal password"}
                  </Button>
                </Field>
              </FieldGroup>
              <Alert>
                <KeyRound />
                <AlertDescription>
                  Rotating this password invalidates the prior credential.
                </AlertDescription>
              </Alert>
            </CardContent>
            <CardFooter>
              <form
                noValidate
                className="w-full"
                onSubmit={(event) => void handleSubmit(requestRotation)(event)}
              >
                <FieldGroup>
                  <Field data-invalid={Boolean(errors.password)}>
                    <FieldLabel htmlFor="new-queue-kiosk-password">
                      New kiosk password
                    </FieldLabel>
                    <Input
                      id="new-queue-kiosk-password"
                      type="password"
                      autoComplete="new-password"
                      disabled={rotationMutation.isPending}
                      {...passwordError.inputProps}
                      {...register("password")}
                    />
                    <FieldDescription>
                      Use at least 8 characters. Never share this password in an
                      unapproved channel.
                    </FieldDescription>
                    <FieldError id={passwordError.errorId}>
                      {errors.password?.message}
                    </FieldError>
                  </Field>
                  {rotationError && (
                    <Alert variant="destructive">
                      <AlertDescription>{rotationError}</AlertDescription>
                    </Alert>
                  )}
                  <Field>
                    <Button type="submit" disabled={rotationMutation.isPending}>
                      Rotate password
                    </Button>
                  </Field>
                </FieldGroup>
              </form>
            </CardFooter>
            <StatusRegion message={statusMessage} />
          </Card>
        )}
      </AsyncBoundary>
      <AlertDialog
        open={confirmingRotation}
        onOpenChange={(open) => {
          if (!open && !rotationMutation.isPending) setConfirmingRotation(false)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Rotate the kiosk password?</AlertDialogTitle>
            <AlertDialogDescription>
              Active kiosks will sign out immediately. Staff at those kiosks
              must sign in again using the new password.
            </AlertDialogDescription>
          </AlertDialogHeader>
          {rotationError && (
            <Alert variant="destructive">
              <AlertTitle>Rotation not completed</AlertTitle>
              <AlertDescription>{rotationError}</AlertDescription>
            </Alert>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <Button
              id={retryButtonId}
              type="button"
              disabled={rotationMutation.isPending}
              onClick={() => void confirmRotation()}
            >
              {rotationError ? "Try rotation again" : "Confirm rotation"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </WorkspacePage>
  )
}

/**
 * Rotation state belongs to one Accounting viewer. A key boundary remounts
 * every local field, dialog, status, visibility flag, and synchronous lock
 * before a different viewer's workspace can commit, rather than relying on a
 * post-commit cleanup effect that could expose the prior viewer's state.
 */
export function QueueKioskAccessWorkspace() {
  const { session } = useAuth()
  const viewerKey =
    session?.role === "accounting_staff"
      ? session.userId
      : "not-an-accounting-viewer"

  return <QueueKioskAccessWorkspaceForViewer key={viewerKey} />
}
