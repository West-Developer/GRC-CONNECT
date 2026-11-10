"use client"

import { useGSAP } from "@gsap/react"
import { zodResolver } from "@hookform/resolvers/zod"
import {
  ArrowLeft,
  BookOpen,
  LockKeyhole,
  ShieldCheck,
  UsersRound,
} from "lucide-react"
import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { useCallback, useEffect, useRef, useState } from "react"
import { useForm } from "react-hook-form"
import { z } from "zod"

import { useAuth } from "@/features/auth/use-auth"
import { isAuthError } from "@/features/auth/auth-error"
import { isLoginOtpRequiredError } from "@/features/auth/login-otp-error"
import { DataUseNotice } from "@/features/components/privacy/data-use-notice"
import { Button } from "@/features/components/ui/button"
import { GoogleSignInButton } from "@/features/components/ui/google-sign-in-button"
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { PasswordInput } from "@/features/components/ui/password-input"
import { useReducedMotion } from "@/features/hooks/use-reduced-motion"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import { gsap } from "@/features/lib/gsap"
import { getSafeReturnPath } from "@/features/router/safe-return-path"
import { isApiClientError } from "@/features/services/api-client"

const loginSchema = z.object({
  email: z
    .string()
    .trim()
    .toLowerCase()
    .pipe(z.email("Enter a valid email address.")),
  password: z.string().min(1, "Enter your password."),
})

type LoginValues = z.infer<typeof loginSchema>

const otpSchema = z.object({
  code: z
    .string()
    .trim()
    .regex(/^[0-9]{6}$/, "Enter the 6-digit code from your email."),
})

type OtpValues = z.infer<typeof otpSchema>

interface OtpChallengeState {
  challengeToken: string
  email: string
}

/**
 * One generic message for every rejected credential. The API returns the same
 * 401 whether the account was missing, the password wrong, or the account
 * disabled, and the UI must not widen that into an account-enumeration hint.
 */
const invalidCredentialsMessage =
  "The email or password you entered was not recognized."
const queueKioskSurfaceMessage =
  "This device identity must sign in through the Queue Kiosk."
const expiredOtpSessionMessage =
  "Your sign-in session expired. Please sign in again."

/** Fallback when the API's 429 carries no Retry-After header for some reason. */
const defaultLockoutSeconds = 60

const copy = {
  eyebrow: "Enrollment portal",
  formIntro: "Sign in with your institutional GRC account.",
  guideDescription: "Seeded development identities",
  guidePath: "docs/testing/SEEDED_IDENTITIES.md",
  purposeDescription:
    "Enter your credentials to reach the role-guided enrollment portal assigned to your account.",
} as const

const trustStatements = [
  {
    icon: UsersRound,
    title: "Role-aware navigation",
    description: "Each identity opens its assigned portal pathway.",
  },
  {
    icon: LockKeyhole,
    title: "Private records stay private",
    description: "This interface loads no student record on sign-in.",
  },
  {
    icon: ShieldCheck,
    title: "Authorized workflows",
    description: "Every protected action requires server authorization.",
  },
] as const

export function LoginPage() {
  const { signIn, verifyLoginOtp, resendLoginOtp } = useAuth()
  const [challenge, setChallenge] = useState<OtpChallengeState | null>(null)
  const [resendStatus, setResendStatus] = useState<
    "idle" | "sending" | "sent" | "error"
  >("idle")
  const [resendMessage, setResendMessage] = useState("")
  const [lockoutSecondsRemaining, setLockoutSecondsRemaining] = useState<
    number | null
  >(null)
  const errorSummaryRef = useRef<HTMLDivElement>(null)
  const router = useRouter()
  const searchParams = useSearchParams()
  const reducedMotion = useReducedMotion()
  const panelRef = useRef<HTMLElement>(null)
  const formPanelRef = useRef<HTMLElement>(null)

  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
    setError,
    setValue,
  } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    shouldFocusError: false,
    defaultValues: {
      email: "",
      password: "",
    },
  })

  const {
    formState: { errors: otpErrors, isSubmitting: otpSubmitting },
    handleSubmit: handleOtpSubmit,
    register: registerOtp,
    setError: setOtpError,
    reset: resetOtpForm,
  } = useForm<OtpValues>({
    resolver: zodResolver(otpSchema),
    defaultValues: { code: "" },
  })

  // ── Institutional panel: brand + purpose + trust list stagger-reveal ────
  useGSAP(
    () => {
      if (reducedMotion) return

      const tl = gsap.timeline({ defaults: { ease: "power2.out" } })

      tl.from(".login-brand", { opacity: 0, x: -20, duration: 0.55 })
        .from(
          ".login-purpose .eyebrow",
          { opacity: 0, y: 12, duration: 0.4 },
          "-=0.25",
        )
        .from(
          ".login-purpose h2",
          { opacity: 0, y: 22, duration: 0.55 },
          "-=0.3",
        )
        .from(
          ".login-purpose > p:last-child",
          { opacity: 0, y: 14, duration: 0.45 },
          "-=0.3",
        )
        .from(
          ".login-trust-list li",
          {
            opacity: 0,
            x: -16,
            duration: 0.4,
            stagger: 0.1,
          },
          "-=0.2",
        )
    },
    { scope: panelRef, dependencies: [reducedMotion] },
  )

  // ── Form card: slide-up fade-in on mount ────────────────────────────────
  useGSAP(
    () => {
      if (reducedMotion) return

      gsap.from(".login-form-card", {
        opacity: 0,
        y: 28,
        duration: 0.65,
        ease: "power2.out",
        delay: 0.15,
      })
    },
    { scope: formPanelRef, dependencies: [reducedMotion] },
  )

  // Ticks the post-lockout countdown down once a second and re-enables the
  // form the moment it reaches zero, without waiting for another submit.
  useEffect(() => {
    if (lockoutSecondsRemaining === null || lockoutSecondsRemaining <= 0) {
      return
    }

    const timer = window.setTimeout(() => {
      setLockoutSecondsRemaining((seconds) =>
        seconds !== null && seconds > 1 ? seconds - 1 : null,
      )
    }, 1000)

    return () => window.clearTimeout(timer)
  }, [lockoutSecondsRemaining])

  const submitLogin = async (values: LoginValues) => {
    try {
      await signIn(values)
    } catch (cause) {
      if (isLoginOtpRequiredError(cause)) {
        setChallenge({ challengeToken: cause.challengeToken, email: cause.email })
        resetOtpForm()
        setResendStatus("idle")
        setResendMessage("")

        return
      }

      // Too many wrong attempts in a row (server-side rate limit, keyed per
      // account+IP) — the form locks visibly instead of silently rejecting
      // every further attempt with the same generic credential message.
      if (isApiClientError(cause) && cause.status === 429) {
        setLockoutSecondsRemaining(
          cause.retryAfterSeconds ?? defaultLockoutSeconds,
        )

        return
      }

      const requiresDevicePortal =
        isAuthError(cause) &&
        cause.code === "QUEUE_KIOSK_REQUIRES_DEVICE_PORTAL"
      setError("root.credentials", {
        message: requiresDevicePortal
          ? queueKioskSurfaceMessage
          : invalidCredentialsMessage,
      })
      setValue("password", "")

      return
    }

    router.replace(getSafeReturnPath(searchParams.get("returnTo")))
  }

  const submitOtp = async (values: OtpValues) => {
    if (!challenge) return

    try {
      await verifyLoginOtp(challenge.challengeToken, values.code)
    } catch (error) {
      if (!applyApiFieldErrors(error, setOtpError)) {
        setOtpError("code", {
          message: "This verification code is invalid or expired.",
        })
      }

      return
    }

    router.replace(getSafeReturnPath(searchParams.get("returnTo")))
  }

  const handleResendOtp = async () => {
    if (!challenge) return
    setResendStatus("sending")
    setResendMessage("")

    try {
      const rotated = await resendLoginOtp(challenge.challengeToken)
      setChallenge({
        challengeToken: rotated.challengeToken,
        email: rotated.email,
      })
      resetOtpForm()
      setResendStatus("sent")
      setResendMessage("A new code was sent to your email.")
    } catch (error) {
      // An expired/unknown challenge token means the whole sign-in attempt is
      // gone — there is nothing left to rotate, so return to credentials.
      if (isApiClientError(error) && error.status === 422) {
        setChallenge(null)
        setResendStatus("idle")
        setResendMessage("")
        setError("root.credentials", { message: expiredOtpSessionMessage })

        return
      }

      setResendStatus("error")
      setResendMessage(
        error instanceof Error
          ? error.message
          : "Failed to resend the code. Please try again.",
      )
    }
  }

  // Stable reference: without it, every re-render (e.g. the lockout
  // countdown ticking once a second) hands GoogleSignInButton a new
  // `onSignedIn` identity, needlessly re-running its own init effect.
  const handleGoogleSignedIn = useCallback(() => {
    router.replace(getSafeReturnPath(searchParams.get("returnTo")))
  }, [router, searchParams])

  const handleBackToCredentials = () => {
    setChallenge(null)
    resetOtpForm()
    setResendStatus("idle")
    setResendMessage("")
  }

  const lockoutActive =
    lockoutSecondsRemaining !== null && lockoutSecondsRemaining > 0

  const errorMessages = [
    errors.email?.message,
    errors.password?.message,
    errors.root?.credentials?.message,
  ].filter((message): message is string => Boolean(message))
  const hasErrors = errorMessages.length > 0

  useEffect(() => {
    if (hasErrors) {
      errorSummaryRef.current?.focus()
    }
  }, [hasErrors])

  // On a phone the two panels stack (globals.css, max-width: 45rem): the
  // institutional panel fills the first screen and the credentials sit a full
  // screen below it. Bring the form into view as soon as the page is ready
  // (stakeholder Doc 13). Scroll only, never focus: focusing an input would open
  // the on-screen keyboard uninvited. Scrolling the panel (not the animated card)
  // avoids landing mid-tween, and smooth scrolling already honours
  // prefers-reduced-motion through the stylesheet. Wide screens show both
  // panels side by side, so nothing scrolls there.
  useEffect(() => {
    if (!window.matchMedia("(max-width: 45rem)").matches) return

    formPanelRef.current?.scrollIntoView({ block: "start" })
  }, [])

  return (
    <main className="login-shell">
      <section
        className="login-institutional-panel"
        aria-labelledby="login-purpose-title"
        ref={panelRef}
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
          <p className="eyebrow">{copy.eyebrow}</p>
          <h2 id="login-purpose-title" className="login-motto">
            Touching Hearts,
            <br />
            Renewing Minds,
            <br />
            Transforming Lives
          </h2>
          <p>{copy.purposeDescription}</p>
        </div>

        <ul className="login-trust-list">
          {trustStatements.map((statement) => {
            const Icon = statement.icon

            return (
              <li key={statement.title}>
                <Icon aria-hidden="true" />
                <div>
                  <strong>{statement.title}</strong>
                  <span>{statement.description}</span>
                </div>
              </li>
            )
          })}
        </ul>
      </section>

      <section
        className="login-form-panel"
        aria-labelledby="login-title"
        ref={formPanelRef}
      >
        <div className="login-form-card">
          {challenge ? (
            <>
              <div>
                <p className="eyebrow">{copy.eyebrow}</p>
                <h1 id="login-title">Check your email</h1>
                <p className="login-form-intro">
                  We sent a 6-digit verification code to{" "}
                  <strong>{challenge.email}</strong>. Enter it below to finish
                  signing in.
                </p>
              </div>

              <form
                noValidate
                onSubmit={(event) => void handleOtpSubmit(submitOtp)(event)}
              >
                <FieldGroup>
                  <Field data-invalid={Boolean(otpErrors.code)}>
                    <FieldLabel htmlFor="login-otp-code">
                      Verification code
                    </FieldLabel>
                    <Input
                      id="login-otp-code"
                      autoComplete="one-time-code"
                      inputMode="numeric"
                      maxLength={6}
                      aria-invalid={Boolean(otpErrors.code)}
                      aria-describedby={
                        otpErrors.code ? "login-otp-code-error" : undefined
                      }
                      disabled={otpSubmitting}
                      {...registerOtp("code")}
                    />
                    <FieldError id="login-otp-code-error">
                      {otpErrors.code?.message}
                    </FieldError>
                  </Field>
                </FieldGroup>

                <Button
                  className="login-submit"
                  type="submit"
                  size="lg"
                  disabled={otpSubmitting}
                >
                  {otpSubmitting ? "Verifying…" : "Verify and sign in"}
                </Button>
                <span className="sr-only" role="status" aria-live="polite">
                  {otpSubmitting ? "Checking verification code." : ""}
                </span>
              </form>

              <div className="rounded-lg border border-border/60 bg-muted/30 p-4 text-center text-sm space-y-2">
                <p className="text-muted-foreground">
                  Didn&apos;t get the code?
                </p>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={resendStatus === "sending"}
                  onClick={() => void handleResendOtp()}
                >
                  {resendStatus === "sending" ? "Sending…" : "Resend code"}
                </Button>
                {resendMessage && (
                  <p
                    className={
                      resendStatus === "sent"
                        ? "text-xs font-medium text-success"
                        : "text-xs text-destructive"
                    }
                    role="status"
                  >
                    {resendMessage}
                  </p>
                )}
              </div>

              <Button
                type="button"
                variant="ghost"
                onClick={handleBackToCredentials}
              >
                <ArrowLeft data-icon="inline-start" aria-hidden="true" />
                Use a different account
              </Button>
            </>
          ) : (
            <>
              <div>
                <p className="eyebrow">{copy.eyebrow}</p>
                <h1 id="login-title">Sign in to your portal</h1>
                <p className="login-form-intro">{copy.formIntro}</p>
              </div>

              {lockoutActive ? (
                <div
                  className="login-error-summary"
                  role="alert"
                  aria-label="Too many sign-in attempts"
                >
                  <strong>Too many attempts.</strong>
                  <p>
                    Please wait{" "}
                    <span aria-live="polite">
                      {lockoutSecondsRemaining}s
                    </span>{" "}
                    before trying again.
                  </p>
                </div>
              ) : (
                hasErrors && (
                  <div
                    ref={errorSummaryRef}
                    className="login-error-summary"
                    role="alert"
                    aria-label="Sign-in errors"
                    tabIndex={-1}
                  >
                    <strong>Check the sign-in details.</strong>
                    <ul>
                      {errorMessages.map((message) => (
                        <li key={message}>{message}</li>
                      ))}
                    </ul>
                    {errors.root?.credentials?.message ===
                      queueKioskSurfaceMessage && (
                      <Link href="/queue">Open Queue Kiosk</Link>
                    )}
                  </div>
                )
              )}

              <form
                noValidate
                onSubmit={(event) => void handleSubmit(submitLogin)(event)}
              >
                <FieldGroup>
                  <Field data-invalid={Boolean(errors.email)}>
                    <FieldLabel htmlFor="login-email">
                      Email address
                    </FieldLabel>
                    <Input
                      id="login-email"
                      type="email"
                      autoComplete="username"
                      placeholder="name@grc.test"
                      aria-invalid={Boolean(errors.email)}
                      aria-describedby={
                        errors.email ? "login-email-error" : undefined
                      }
                      disabled={isSubmitting || lockoutActive}
                      {...register("email")}
                    />
                    <FieldError id="login-email-error">
                      {errors.email?.message}
                    </FieldError>
                  </Field>

                  <Field data-invalid={Boolean(errors.password)}>
                    <FieldLabel htmlFor="login-password">Password</FieldLabel>
                    <PasswordInput
                      id="login-password"
                      wrapperClassName="login-password-row"
                      autoComplete="current-password"
                      aria-invalid={Boolean(errors.password)}
                      aria-describedby={
                        errors.password ? "login-password-error" : undefined
                      }
                      disabled={isSubmitting || lockoutActive}
                      {...register("password")}
                    />
                    <FieldError id="login-password-error">
                      {errors.password?.message}
                    </FieldError>
                    <Link
                      className="login-forgot-password"
                      href="/forgot-password"
                    >
                      Forgot password?
                    </Link>
                  </Field>
                </FieldGroup>

                <DataUseNotice notice="sign-in" />

                <Button
                  className="login-submit"
                  type="submit"
                  size="lg"
                  disabled={isSubmitting || lockoutActive}
                >
                  {lockoutActive
                    ? `Try again in ${lockoutSecondsRemaining}s`
                    : isSubmitting
                      ? "Signing in…"
                      : "Sign in"}
                </Button>
                <span className="sr-only" role="status" aria-live="polite">
                  {isSubmitting ? "Checking credentials." : ""}
                </span>
              </form>

              <div className="login-divider" role="separator" aria-label="or">
                <span>or</span>
              </div>

              <GoogleSignInButton onSignedIn={handleGoogleSignedIn} />

              <div className="login-guide-note">
                <FieldDescription>{copy.guideDescription}</FieldDescription>
                <code>{copy.guidePath}</code>
              </div>

              <Button asChild variant="ghost">
                <Link href="/user-guide">
                  <BookOpen data-icon="inline-start" aria-hidden="true" />
                  Open the user guide
                </Link>
              </Button>

              <Button asChild variant="ghost">
                <Link href="/">
                  <ArrowLeft data-icon="inline-start" aria-hidden="true" />
                  Return to the landing page
                </Link>
              </Button>
            </>
          )}
        </div>
      </section>
    </main>
  )
}
