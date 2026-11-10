"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { LockKeyholeIcon } from "lucide-react"
import { useEffect, useId, useRef } from "react"
import { useForm } from "react-hook-form"
import { z } from "zod"

import { Button } from "@/features/components/ui/button"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"

const deviceCredentialsSchema = z.object({
  email: z
    .string()
    .trim()
    .toLowerCase()
    .pipe(z.email("Enter a valid email address.")),
  password: z.string().min(1, "Enter the device password."),
})

type DeviceCredentials = z.infer<typeof deviceCredentialsSchema>

interface QueueKioskDeviceLoginProps {
  error: string | null
  onSubmit: (credentials: DeviceCredentials) => Promise<void>
}

export function QueueKioskDeviceLogin({
  error,
  onSubmit,
}: QueueKioskDeviceLoginProps) {
  const errorRef = useRef<HTMLDivElement>(null)
  const emailErrorId = useId()
  const passwordErrorId = useId()
  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
  } = useForm<DeviceCredentials>({
    resolver: zodResolver(deviceCredentialsSchema),
    shouldFocusError: true,
    defaultValues: { email: "", password: "" },
  })

  useEffect(() => {
    if (error) errorRef.current?.focus()
  }, [error])

  return (
    <form noValidate onSubmit={(event) => void handleSubmit(onSubmit)(event)}>
      {error && (
        <Alert ref={errorRef} variant="destructive" tabIndex={-1}>
          <AlertTitle>Device sign-in unavailable</AlertTitle>
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      <FieldGroup>
        <Field data-invalid={Boolean(errors.email)}>
          <FieldLabel htmlFor="queue-kiosk-device-email">
            Device email
          </FieldLabel>
          <Input
            id="queue-kiosk-device-email"
            type="email"
            autoComplete="username"
            aria-invalid={Boolean(errors.email)}
            aria-describedby={errors.email ? emailErrorId : undefined}
            disabled={isSubmitting}
            {...register("email")}
          />
          <FieldError id={emailErrorId}>{errors.email?.message}</FieldError>
        </Field>
        <Field data-invalid={Boolean(errors.password)}>
          <FieldLabel htmlFor="queue-kiosk-device-password">
            Device password
          </FieldLabel>
          <Input
            id="queue-kiosk-device-password"
            type="password"
            autoComplete="current-password"
            aria-invalid={Boolean(errors.password)}
            aria-describedby={errors.password ? passwordErrorId : undefined}
            disabled={isSubmitting}
            {...register("password")}
          />
          <FieldError id={passwordErrorId}>
            {errors.password?.message}
          </FieldError>
        </Field>
        <Button
          className="queue-kiosk-submit"
          type="submit"
          size="lg"
          disabled={isSubmitting}
        >
          <LockKeyholeIcon data-icon="inline-start" />
          {isSubmitting ? "Opening kiosk…" : "Open Student sign-in"}
        </Button>
      </FieldGroup>
    </form>
  )
}
