"use client"

import { ExternalLink, ShieldCheck } from "lucide-react"
import Link from "next/link"
import { useId } from "react"

import { Checkbox } from "@/features/components/ui/checkbox"
import {
  AGREEMENT_TEXT,
  DATA_USES,
} from "@/features/lib/privacy/notice-content"

/**
 * Admission's step before saving an applicant's details (ADR 0055): show the student the Data Privacy Notice, let
 * them read it and ask questions, and confirm they agree. What the student is told is shown here too, from the
 * same words as the notice itself. The confirmation is recorded with the new account, and the student still
 * agrees themself the first time they sign in.
 */
export function AdmissionPrivacyAcknowledgement({
  checked,
  disabled,
  onCheckedChange,
}: {
  checked: boolean
  disabled?: boolean
  onCheckedChange: (checked: boolean) => void
}) {
  const checkboxId = useId()
  const identity = DATA_USES.find((use) => use.id === "identity")

  return (
    <section
      aria-labelledby={`${checkboxId}-title`}
      className="grid gap-3 rounded-lg border border-border bg-muted/40 p-4"
    >
      <div className="flex items-start gap-3">
        <ShieldCheck
          aria-hidden="true"
          className="mt-0.5 size-5 shrink-0 text-primary"
        />
        <div className="grid gap-2 text-sm leading-6">
          <h3 id={`${checkboxId}-title`} className="font-semibold">
            Data privacy: show this to the student first
          </h3>
          <p>
            The details on this form are the student’s personal data
            {identity ? ` (${identity.data.join("; ").toLowerCase()})` : ""}.
            Before you save them, let the student read the Data Privacy Notice
            and ask questions. The school uses the data to:
          </p>
          <ul className="grid gap-1 pl-5 [&>li]:list-disc">
            {(identity?.purposes ?? []).map((purpose) => (
              <li key={purpose}>{purpose}</li>
            ))}
          </ul>
          <p>Who can see it: {(identity?.seenBy ?? []).join("; ")}.</p>
          <p>
            <Link
              className="inline-flex items-center gap-1 font-medium underline underline-offset-4"
              href="/privacy"
              rel="noreferrer"
              target="_blank"
            >
              Open the Data Privacy Notice for the student
              <ExternalLink aria-hidden="true" className="size-3.5" />
            </Link>
          </p>
        </div>
      </div>
      <div className="flex items-start gap-3">
        <Checkbox
          id={checkboxId}
          checked={checked}
          disabled={disabled}
          onCheckedChange={(value) => onCheckedChange(value === true)}
        />
        <label htmlFor={checkboxId} className="text-sm leading-6">
          {AGREEMENT_TEXT.admission}
        </label>
      </div>
    </section>
  )
}
