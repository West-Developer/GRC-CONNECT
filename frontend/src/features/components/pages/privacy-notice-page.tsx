"use client"

import { PublicFooter } from "@/features/components/layouts/public-footer"
import { PublicHeader } from "@/features/components/layouts/public-header"
import { PrivacyNoticeBody } from "@/features/components/privacy/privacy-notice-body"
import { usePrivacyNoticeInfoQuery } from "@/features/hooks/use-privacy-notice"
import { PRIVACY_LAW } from "@/features/lib/privacy/notice-content"

/**
 * The public Data Privacy Notice (ADR 0055). No sign-in: a person can read how their data is used before they
 * give any of it, and the same link works from every form.
 */
export function PrivacyNoticePage() {
  const info = usePrivacyNoticeInfoQuery()

  return (
    <div className="grid min-h-svh grid-rows-[auto_1fr_auto] bg-background">
      <PublicHeader />
      <main className="mx-auto grid w-full max-w-4xl content-start gap-8 px-4 py-10 sm:px-6">
        <header className="grid gap-2">
          <p className="eyebrow">Data Privacy Notice</p>
          <h1 className="text-3xl font-semibold tracking-tight">
            How GRC uses your personal data
          </h1>
          <p className="text-sm leading-6 text-muted-foreground">
            Under the {PRIVACY_LAW.name}.
            {info.data
              ? ` Version ${info.data.version}, in effect since ${info.data.effective_date}.`
              : ""}
          </p>
        </header>
        <PrivacyNoticeBody info={info.data} />
      </main>
      <PublicFooter />
    </div>
  )
}
