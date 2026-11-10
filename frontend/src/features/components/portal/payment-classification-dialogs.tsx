"use client"

import { GraduationCap, ShieldCheck } from "lucide-react"
import { useState } from "react"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import {
  scholarshipPercentageValues,
  type ScholarshipPercentage,
} from "@/features/schemas/enrollment-schema"

export type PaymentClassification = "payee" | "scholar"

/** The tiers of ADR 0025, worded as the Advance Payment page always did. */
export const SCHOLARSHIP_TIER_LABELS: Record<ScholarshipPercentage, string> = {
  100: "100% Full Academic Scholarship",
  40: "40% Partial Scholarship",
  20: "20% Partial Scholarship",
}

function formatPhp(amount: number): string {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(amount)
}

/**
 * The scholarship discount for `base` at `percentage`, in whole cents rounded
 * half-up. A preview only: the API computes the real amount the same way and
 * is what the payment modal and the COR read.
 */
export function scholarshipDiscountFor(
  base: number,
  percentage: ScholarshipPercentage,
): number {
  const cents = Math.round(base * 100)
  return Math.floor((cents * percentage + 50) / 100) / 100
}

function RadioOption({
  name,
  value,
  checked,
  disabled,
  onSelect,
  icon,
  title,
  description,
}: {
  name: string
  value: string
  checked: boolean
  disabled?: boolean
  onSelect: () => void
  icon?: React.ReactNode
  title: string
  description?: string
}) {
  return (
    <label
      className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring ${checked ? "border-primary bg-primary/5" : ""}`}
    >
      <input
        type="radio"
        name={name}
        value={value}
        checked={checked}
        disabled={disabled}
        onChange={onSelect}
        className="mt-1 size-4 accent-[var(--primary)]"
      />
      <span className="grid gap-0.5">
        <span className="flex items-center gap-1.5 text-sm font-medium">
          {icon}
          {title}
        </span>
        {description && (
          <span className="text-xs text-muted-foreground">{description}</span>
        )}
      </span>
    </label>
  )
}

/**
 * Step 1 of the Cashier's Confirm payment: is the student a regular payee or
 * a scholar? A payee goes straight to the payment modal; a scholar first
 * picks a tier. Mounted only while this step is showing, so it always starts
 * from `initial`.
 */
export function PaymentClassificationDialog({
  studentName,
  initial,
  busy,
  error,
  onContinue,
  onCancel,
}: {
  studentName: string
  initial: PaymentClassification
  busy: boolean
  error: string
  onContinue: (choice: PaymentClassification) => void
  onCancel: () => void
}) {
  const [choice, setChoice] = useState<PaymentClassification>(initial)

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) onCancel()
      }}
    >
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Payment classification</DialogTitle>
          <DialogDescription>
            Is {studentName} a regular payee or a scholar? This decides how the
            payment is collected.
          </DialogDescription>
        </DialogHeader>

        <fieldset className="grid gap-2" disabled={busy}>
          <legend className="sr-only">Student classification</legend>
          <RadioOption
            name="payment-classification"
            value="payee"
            checked={choice === "payee"}
            onSelect={() => setChoice("payee")}
            title="Regular payee"
            description="Pays the assessment. Continues to the payment method."
          />
          <RadioOption
            name="payment-classification"
            value="scholar"
            checked={choice === "scholar"}
            onSelect={() => setChoice("scholar")}
            icon={<GraduationCap className="size-4" aria-hidden="true" />}
            title="Scholar"
            description="Choose a 100%, 40% or 20% scholarship. The discount is deducted from the assessment automatically."
          />
        </fieldset>

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <DialogFooter className="gap-2 sm:gap-0">
          <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={onCancel}
          >
            Cancel
          </Button>
          <Button
            type="button"
            disabled={busy}
            onClick={() => onContinue(choice)}
          >
            {busy ? "Saving…" : "Continue"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Step 2 for a scholar: the 100% / 40% / 20% classification, with a live
 * summary of the discount and the net the student will pay. Applying it asks
 * the API to write the negative assessment line, then the payment modal opens
 * on the net amount.
 */
export function ScholarshipTierDialog({
  studentName,
  baseAmount,
  initialPercentage,
  busy,
  error,
  onApply,
  onBack,
}: {
  studentName: string
  /** The assessment before any scholarship (tuition plus other fees). */
  baseAmount: number
  initialPercentage: ScholarshipPercentage
  busy: boolean
  error: string
  onApply: (percentage: ScholarshipPercentage) => void
  onBack: () => void
}) {
  const [percentage, setPercentage] =
    useState<ScholarshipPercentage>(initialPercentage)
  const discount = scholarshipDiscountFor(baseAmount, percentage)
  const net = Math.max(0, Math.round((baseAmount - discount) * 100) / 100)

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) onBack()
      }}
    >
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Scholarship classification</DialogTitle>
          <DialogDescription>
            Select the scholarship for {studentName}. The percentage is taken
            off the whole assessment (tuition and other fees).
          </DialogDescription>
        </DialogHeader>

        <fieldset className="grid gap-2" disabled={busy}>
          <legend className="sr-only">Scholarship</legend>
          {scholarshipPercentageValues.map((value) => (
            <RadioOption
              key={value}
              name="scholarship-tier"
              value={String(value)}
              checked={percentage === value}
              onSelect={() => setPercentage(value)}
              title={SCHOLARSHIP_TIER_LABELS[value]}
            />
          ))}
        </fieldset>

        <div className="grid gap-2 rounded-lg border bg-muted/40 p-3.5 text-xs">
          <p className="flex items-center gap-1.5 font-semibold text-foreground">
            <ShieldCheck
              className="size-4 text-emerald-600"
              aria-hidden="true"
            />
            Scholarship summary
          </p>
          <div className="flex justify-between text-muted-foreground">
            <span>Current assessment:</span>
            <span>{formatPhp(baseAmount)}</span>
          </div>
          <div className="flex justify-between font-medium text-emerald-600 dark:text-emerald-400">
            <span>Scholarship discount ({percentage}%):</span>
            <span>-{formatPhp(discount)}</span>
          </div>
          <div className="flex justify-between border-t pt-1.5 font-semibold text-foreground">
            <span>Net payable:</span>
            <span>{formatPhp(net)}</span>
          </div>
          {net === 0 && (
            <p className="text-muted-foreground">
              No payment is due: the scholarship covers the whole assessment.
            </p>
          )}
        </div>

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <DialogFooter className="gap-2 sm:gap-0">
          <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={onBack}
          >
            Back
          </Button>
          <Button
            type="button"
            disabled={busy}
            onClick={() => onApply(percentage)}
          >
            {busy ? "Applying…" : "Apply scholarship"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
