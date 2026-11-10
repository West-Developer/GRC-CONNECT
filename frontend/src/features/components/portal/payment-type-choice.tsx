"use client"

import { Button } from "@/features/components/ui/button"

/**
 * ADR 0046: the choice a payer makes when a full-payment discount is on offer. Pay in full
 * collects the quoted net; Installment is the ordinary first-payment flow with no discount.
 */
export function PaymentTypeChoice({
  percentage,
  payInFull,
  disabled,
  onChange,
}: {
  percentage: string | null
  payInFull: boolean
  disabled: boolean
  onChange: (mode: "full" | "installment") => void
}) {
  return (
    <div
      role="group"
      aria-label="Payment type"
      className="grid gap-2 sm:grid-cols-2"
    >
      <Button
        type="button"
        variant={payInFull ? "default" : "outline"}
        aria-pressed={payInFull}
        disabled={disabled}
        onClick={() => onChange("full")}
      >
        {percentage === null
          ? "Pay in full"
          : `Pay in full (${Number(percentage)}% off)`}
      </Button>
      <Button
        type="button"
        variant={payInFull ? "outline" : "default"}
        aria-pressed={!payInFull}
        disabled={disabled}
        onClick={() => onChange("installment")}
      >
        Installment (no discount)
      </Button>
    </div>
  )
}
