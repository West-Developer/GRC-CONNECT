"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { KeyRound, MailCheck, ShieldCheck } from "lucide-react"
import Link from "next/link"
import { useState } from "react"
import { useForm } from "react-hook-form"

import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { DataUseNotice } from "@/features/components/privacy/data-use-notice"
import { Button } from "@/features/components/ui/button"
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { forgotPasswordSchema } from "@/features/schemas/forgot-password-schema"
import { requestPasswordReset } from "@/features/services/password-reset-service"

interface ForgotPasswordFormValues {
  email: string
}

const GENERIC_SENT_MESSAGE =
  "If an account exists for this email, a password reset code has been sent."

export function ForgotPasswordPage() {
  const [sent, setSent] = useState(false)
  const [requestError, setRequestError] = useState("")

  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
  } = useForm<ForgotPasswordFormValues>({
    resolver: zodResolver(forgotPasswordSchema),
    defaultValues: { email: "" },
  })

  const submit = async (values: ForgotPasswordFormValues) => {
    setRequestError("")
    try {
      await requestPasswordReset(values)
    } catch {
      // The endpoint itself never distinguishes a known from an unknown
      // email, so a transport failure is the only thing left to report —
      // and even then, showing the identical generic message keeps this
      // page from ever confirming or denying an account's existence.
      setRequestError(
        "We could not reach the server just now. Please try again.",
      )
      return
    }
    setSent(true)
  }

  return (
    <main className="login-shell">
      <section
        className="login-institutional-panel"
        aria-labelledby="forgot-password-purpose-title"
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
          <h2 id="forgot-password-purpose-title">
            Get back into your account.
          </h2>
          <p>
            Enter the email on your GRC account and, if it exists, we will
            send a one-time code to reset your password.
          </p>
        </div>
        <ul className="login-trust-list">
          <li>
            <MailCheck aria-hidden="true" />
            <div>
              <strong>Same message either way</strong>
              <span>
                We never confirm whether an email has an account — this
                protects everyone&apos;s privacy.
              </span>
            </div>
          </li>
          <li>
            <KeyRound aria-hidden="true" />
            <div>
              <strong>One use only</strong>
              <span>
                The 6-digit reset code expires after an hour and allows only
                a few wrong attempts.
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
        aria-labelledby="forgot-password-title"
      >
        <div className="login-form-card">
          {sent ? (
            <div className="space-y-5" role="status" aria-live="polite">
              <div>
                <p className="eyebrow">Check your email</p>
                <h1 id="forgot-password-title">Reset code on its way.</h1>
              </div>
              <Alert>
                <MailCheck aria-hidden="true" />
                <AlertTitle>Message sent</AlertTitle>
                <AlertDescription>{GENERIC_SENT_MESSAGE}</AlertDescription>
              </Alert>
              <Button asChild className="login-submit">
                <Link href="/reset-password">I have a code</Link>
              </Button>
              <Button asChild variant="ghost">
                <Link href="/login">Return to sign in</Link>
              </Button>
            </div>
          ) : (
            <>
              <div>
                <p className="eyebrow">Account recovery</p>
                <h1 id="forgot-password-title">Forgot your password?</h1>
                <p className="login-form-intro">
                  Enter your email address and we&apos;ll send you a one-time
                  reset code if an account exists for it.
                </p>
              </div>
              {requestError && (
                <Alert variant="destructive">
                  <AlertTitle>Something went wrong</AlertTitle>
                  <AlertDescription>{requestError}</AlertDescription>
                </Alert>
              )}
              <form
                noValidate
                onSubmit={(event) => void handleSubmit(submit)(event)}
              >
                <FieldGroup>
                  <Field data-invalid={Boolean(errors.email)}>
                    <FieldLabel htmlFor="forgot-password-email">
                      Email address
                    </FieldLabel>
                    <Input
                      id="forgot-password-email"
                      type="email"
                      autoComplete="username"
                      placeholder="name@grc.test"
                      aria-invalid={Boolean(errors.email)}
                      aria-describedby={
                        errors.email ? "forgot-password-email-error" : undefined
                      }
                      disabled={isSubmitting}
                      {...register("email")}
                    />
                    <FieldError id="forgot-password-email-error">
                      {errors.email?.message}
                    </FieldError>
                  </Field>
                </FieldGroup>
                <DataUseNotice notice="forgot-password" />
                <Button
                  className="login-submit"
                  type="submit"
                  size="lg"
                  disabled={isSubmitting}
                >
                  {isSubmitting ? "Sending…" : "Send reset code"}
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
