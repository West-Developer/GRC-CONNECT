"use client"

import { ExternalLink, LogOut, ShieldCheck } from "lucide-react"
import Link from "next/link"
import { useId, useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { PrivacyNoticeBody } from "@/features/components/privacy/privacy-notice-body"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import { Checkbox } from "@/features/components/ui/checkbox"
import {
  useAcceptPrivacyNoticeMutation,
  usePrivacyNoticeInfoQuery,
} from "@/features/hooks/use-privacy-notice"
import {
  agreementTextForRole,
  PRIVACY_NOTICE_CONTENT_VERSION,
} from "@/features/lib/privacy/notice-content"
import { isApiClientError } from "@/features/services/api-client"

/**
 * The read-and-agree screen (ADR 0055). Shown in place of the portal to everyone who has not agreed to the
 * current Data Privacy Notice: a first sign-in, or the first sign-in after the notice changed. It is not a
 * security boundary (the API still answers); it is the moment the person is told what is collected, why and who
 * sees it, and agrees. Their agreement is recorded with the version they read.
 */
export function PrivacyAgreementGate() {
  const { replaceSession, session, signOut } = useAuth()
  const info = usePrivacyNoticeInfoQuery()
  const accept = useAcceptPrivacyNoticeMutation()
  const [agreed, setAgreed] = useState(false)
  const checkboxId = useId()
  const readingId = useId()

  const currentVersion = session?.privacyNotice?.version ?? null
  // The wording on screen is the version this app was built with. If the server's current version is a different
  // one (the two were deployed a moment apart), agreeing would record a version the person did not read.
  const outOfDate =
    currentVersion !== null && currentVersion !== PRIVACY_NOTICE_CONTENT_VERSION
  const hadAgreedBefore = session?.privacyNotice?.acceptedVersion != null

  const errorMessage = accept.isError
    ? isApiClientError(accept.error)
      ? accept.error.message
      : "We could not save your agreement. Check your connection and try again."
    : null

  const submit = () => {
    if (!agreed || outOfDate || currentVersion === null) {
      return
    }

    accept.mutate(currentVersion, {
      onSuccess: (user) => replaceSession(user),
    })
  }

  return (
    <main
      className="mx-auto grid min-h-svh w-full max-w-4xl content-start gap-6 px-4 py-8 sm:px-6"
      aria-labelledby="privacy-gate-title"
    >
      <header className="grid gap-2">
        <p className="eyebrow">
          <ShieldCheck aria-hidden="true" />
          Data Privacy Notice
        </p>
        <h1
          id="privacy-gate-title"
          className="text-2xl font-semibold tracking-tight"
        >
          {hadAgreedBefore
            ? "Our Data Privacy Notice has changed"
            : "Before you continue, please read how we use your data"}
        </h1>
        <p className="text-sm leading-6 text-muted-foreground">
          {session?.displayName ? `${session.displayName}, ` : ""}
          GRC keeps personal data in this system. Read the notice below, then
          agree to continue. You can read it again at any time from the Data
          Privacy link.
        </p>
      </header>

      <div
        // A long page that scrolls inside the screen must be reachable with the keyboard.
        // eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex
        tabIndex={0}
        role="region"
        aria-label="Data Privacy Notice"
        id={readingId}
        className="max-h-[55svh] overflow-y-auto rounded-lg border border-border bg-background p-4 sm:p-5"
      >
        <PrivacyNoticeBody info={info.data} />
      </div>

      {outOfDate ? (
        <Alert variant="destructive">
          <AlertTitle>The notice is being updated</AlertTitle>
          <AlertDescription>
            A newer version of the Data Privacy Notice has just been published.
            Reload this page to read it before you agree.
          </AlertDescription>
        </Alert>
      ) : null}

      {errorMessage ? (
        <Alert variant="destructive">
          <AlertTitle>Your agreement was not saved</AlertTitle>
          <AlertDescription>{errorMessage}</AlertDescription>
        </Alert>
      ) : null}

      <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
        <div className="flex items-start gap-3">
          <Checkbox
            id={checkboxId}
            checked={agreed}
            onCheckedChange={(value) => setAgreed(value === true)}
            disabled={accept.isPending || outOfDate}
            aria-describedby={readingId}
          />
          <label
            htmlFor={checkboxId}
            className="text-sm leading-6"
            data-testid="privacy-agreement-text"
          >
            {agreementTextForRole(session?.role)}
          </label>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button
            type="button"
            onClick={submit}
            disabled={!agreed || outOfDate || accept.isPending}
          >
            {accept.isPending ? "Saving…" : "I agree and continue"}
          </Button>
          <Button type="button" variant="outline" onClick={signOut}>
            <LogOut aria-hidden="true" />I do not agree — sign out
          </Button>
          <Button asChild variant="ghost">
            <Link href="/privacy" rel="noreferrer" target="_blank">
              Open as a page
              <ExternalLink aria-hidden="true" />
            </Link>
          </Button>
        </div>
        <p className="text-xs leading-5 text-muted-foreground">
          If you do not agree, you cannot use the system. Nothing is changed in
          your records when you sign out.
        </p>
      </div>
    </main>
  )
}
