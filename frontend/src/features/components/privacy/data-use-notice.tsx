import { ShieldCheck } from "lucide-react"
import Link from "next/link"

import {
  INLINE_NOTICES,
  type InlineNoticeId,
} from "@/features/lib/privacy/notice-content"
import { cn } from "@/features/lib/utils"

/**
 * The short “why we ask” notice beside a form that collects personal details (ADR 0055). It says what the form
 * asks for, what it is used for and who can see it, and links to the whole Data Privacy Notice. The words come
 * from the same place as the `/privacy` page, so they cannot differ.
 */
export function DataUseNotice({
  notice,
  className,
}: {
  notice: InlineNoticeId
  className?: string
}) {
  const text = INLINE_NOTICES[notice]

  return (
    <aside
      aria-label="Data privacy"
      className={cn(
        "flex gap-3 rounded-lg border border-border bg-muted/40 p-3 text-left text-sm leading-6",
        className,
      )}
    >
      <ShieldCheck
        aria-hidden="true"
        className="mt-0.5 size-4 shrink-0 text-primary"
      />
      <div className="grid gap-1">
        <p>
          <span className="font-semibold">Data privacy. </span>
          We ask for {text.collects}, {text.purpose}. Who can see it:{" "}
          {text.seenBy}.
        </p>
        <p className="text-muted-foreground">
          Read the whole{" "}
          <Link
            className="underline underline-offset-4"
            href="/privacy"
            rel="noreferrer"
            target="_blank"
          >
            Data Privacy Notice
          </Link>{" "}
          (opens in a new tab).
        </p>
      </div>
    </aside>
  )
}
