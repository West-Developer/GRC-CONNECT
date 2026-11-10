"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { ArrowRightIcon } from "lucide-react"
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

const studentCredentialsSchema = z.object({
  email: z
    .string()
    .trim()
    .toLowerCase()
    .pipe(z.email("Enter a valid email address.")),
  password: z.string().min(1, "Enter your password."),
})

type StudentCredentials = z.infer<typeof studentCredentialsSchema>

interface QueueKioskStudentLoginProps {
  error: string | null
  onSubmit: (credentials: StudentCredentials) => Promise<void>
}

export function QueueKioskStudentLogin({
  error,
  onSubmit,
}: QueueKioskStudentLoginProps) {
  const errorRef = useRef<HTMLDivElement>(null)
  const emailErrorId = useId()
  const passwordErrorId = useId()
  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
  } = useForm<StudentCredentials>({
    resolver: zodResolver(studentCredentialsSchema),
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
          <AlertTitle>Student sign-in unavailable</AlertTitle>
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      <FieldGroup>
        <Field data-invalid={Boolean(errors.email)}>
          <FieldLabel htmlFor="queue-kiosk-student-email">
            Student email
          </FieldLabel>
          <Input
            id="queue-kiosk-student-email"
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
          <FieldLabel htmlFor="queue-kiosk-student-password">
            Student password
          </FieldLabel>
          <Input
            id="queue-kiosk-student-password"
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
          <ArrowRightIcon data-icon="inline-start" />
          {isSubmitting ? "Opening queue…" : "View queue"}
        </Button>
      </FieldGroup>
    </form>
  )
}
