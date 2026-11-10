"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { KeyRound, MailCheck, ShieldCheck } from "lucide-react"
import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"

import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import { resetPasswordSchema } from "@/features/schemas/reset-password-schema"
import { resetPassword } from "@/features/services/password-reset-service"

interface ResetPasswordFormValues {
  email: string
  code: string
  password: string
  password_confirmation: string
}

export function ResetPasswordPage() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const emailParam = searchParams.get("email") ?? ""
  const codeParam = searchParams.get("code") ?? ""

  const [completed, setCompleted] = useState(false)
  const [requestError, setRequestError] = useState("")

  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
    setError,
    setValue,
  } = useForm<ResetPasswordFormValues>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: {
      email: emailParam,
      code: codeParam,
      password: "",
      password_confirmation: "",
    },
  })

  useEffect(() => {
    if (emailParam) {
      setValue("email", emailParam)
    }
    if (codeParam) {
      setValue("code", codeParam)
    }
  }, [emailParam, codeParam, setValue])

  useEffect(() => {
    if (!completed) return
    const redirectTimer = window.setTimeout(
      () => router.replace("/login?passwordReset=complete"),
      1500,
    )
    return () => window.clearTimeout(redirectTimer)
  }, [completed, router])

  const submit = async (values: ResetPasswordFormValues) => {
    setRequestError("")
    try {
      await resetPassword(values)
      setCompleted(true)
    } catch (error) {
      if (!applyApiFieldErrors(error, setError)) {
        setRequestError(
          "The reset code could not be verified. It may be invalid, expired, or already used.",
        )
      }
    }
  }

  return (
    <main className="login-shell">
      <section
        className="login-institutional-panel"
        aria-labelledby="reset-password-purpose-title"
      >
        <Link
          className="login-brand"
          href="/"
          aria-label="Return to GRC enrollment home"
        >
          <span className="grc-monogram" aria-hidden="true">
            GRC
          </span>
          <span>
            <strong>Global Reciprocal Colleges</strong>
            <small>Automated Enrollment System</small>
          </span>
        </Link>
        <div className="login-purpose">
          <p className="eyebrow">Account recovery</p>
          <h2 id="reset-password-purpose-title">Choose a new password.</h2>
          <p>
            Enter the reset code sent to your email along with your new
            password.
          </p>
        </div>
        <ul className="login-trust-list">
          <li>
            <MailCheck aria-hidden="true" />
            <div>
              <strong>Separate reset code</strong>
              <span>
                Enter the 6-digit code delivered to your email to continue.
              </span>
            </div>
          </li>
          <li>
            <KeyRound aria-hidden="true" />
            <div>
              <strong>One use only</strong>
              <span>
                The code expires after an hour, allows only a few wrong
                attempts, and cannot be reused.
              </span>
            </div>
          </li>
          <li>
            <ShieldCheck aria-hidden="true" />
            <div>
              <strong>Every session signed out</strong>
              <span>
                Resetting your password signs you out everywhere else, too.
              </span>
            </div>
          </li>
        </ul>
      </section>
      <section
        className="login-form-panel"
        aria-labelledby="reset-password-title"
      >
        <div className="login-form-card">
          {completed ? (
            <div className="space-y-5" role="status" aria-live="polite">
              <div>
                <p className="eyebrow">Password reset</p>
                <h1 id="reset-password-title">Your password has changed.</h1>
              </div>
              <Alert>
                <MailCheck aria-hidden="true" />
                <AlertTitle>Password updated</AlertTitle>
                <AlertDescription>
                  You can now sign in with your new password. Redirecting to
                  sign in…
                </AlertDescription>
              </Alert>
              <Button
                type="button"
                onClick={() => router.replace("/login?passwordReset=complete")}
              >
                Continue to sign in
              </Button>
            </div>
          ) : (
            <>
              <div>
                <p className="eyebrow">Account recovery</p>
                <h1 id="reset-password-title">Reset your password</h1>
                <p className="login-form-intro">
                  Enter the email and one-time code from your reset email,
                  then choose a new password.
                </p>
              </div>
              {requestError && (
                <Alert variant="destructive">
                  <AlertTitle>Reset not completed</AlertTitle>
                  <AlertDescription className="space-y-2">
                    <p>{requestError}</p>
                    <div>
                      <Button asChild variant="outline" size="sm">
                        <Link href="/forgot-password">Request a new code</Link>
                      </Button>
                    </div>
                  </AlertDescription>
                </Alert>
              )}
              <form
                noValidate
                onSubmit={(event) => void handleSubmit(submit)(event)}
              >
                <FieldGroup>
                  <Field data-invalid={Boolean(errors.email)}>
                    <FieldLabel htmlFor="reset-password-email">
                      Email address
                    </FieldLabel>
                    <Input
                      id="reset-password-email"
                      type="email"
                      autoComplete="username"
                      disabled={isSubmitting}
                      {...register("email")}
                    />
                    <FieldError>{errors.email?.message}</FieldError>
                  </Field>
                  <Field data-invalid={Boolean(errors.code)}>
                    <FieldLabel htmlFor="reset-password-code">
                      One-time reset code
                    </FieldLabel>
                    <Input
                      id="reset-password-code"
                      autoComplete="one-time-code"
                      inputMode="numeric"
                      maxLength={6}
                      disabled={isSubmitting}
                      {...register("code")}
                    />
                    <FieldDescription>
                      The 6-digit code expires an hour after it was sent. Too
                      many wrong attempts lock it; request a new one.
                    </FieldDescription>
                    <FieldError>{errors.code?.message}</FieldError>
                  </Field>
                  <Field data-invalid={Boolean(errors.password)}>
                    <FieldLabel htmlFor="reset-password-password">
                      New password
                    </FieldLabel>
                    <Input
                      id="reset-password-password"
                      type="password"
                      autoComplete="new-password"
                      disabled={isSubmitting}
                      {...register("password")}
                    />
                    <FieldError>{errors.password?.message}</FieldError>
                  </Field>
                  <Field data-invalid={Boolean(errors.password_confirmation)}>
                    <FieldLabel htmlFor="reset-password-confirm">
                      Confirm new password
                    </FieldLabel>
                    <Input
                      id="reset-password-confirm"
                      type="password"
                      autoComplete="new-password"
                      disabled={isSubmitting}
                      {...register("password_confirmation")}
                    />
                    <FieldError>
                      {errors.password_confirmation?.message}
                    </FieldError>
                  </Field>
                </FieldGroup>
                <Button
                  className="login-submit"
                  type="submit"
                  size="lg"
                  disabled={isSubmitting}
                >
                  {isSubmitting ? "Resetting password…" : "Reset password"}
                </Button>
              </form>
              <Button asChild variant="ghost">
                <Link href="/login">Return to sign in</Link>
              </Button>
            </>
          )}
        </div>
      </section>
    </main>
  )
}
