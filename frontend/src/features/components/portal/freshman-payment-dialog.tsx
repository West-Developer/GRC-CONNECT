"use client"

import { useState } from "react"

import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { PaymentTypeChoice } from "@/features/components/portal/payment-type-choice"
import { Input } from "@/features/components/ui/input"
import { Skeleton } from "@/features/components/ui/skeleton"
import {
  useConfirmFreshmanPaymentMutation,
  useFreshmanPaymentPreviewQuery,
} from "@/features/hooks/use-freshman"
import type { QueueTicket } from "@/features/schemas/queue-ticket-schema"
import type { FreshmanPayment } from "@/features/schemas/freshman-schema"
import { isApiClientError } from "@/features/services/api-client"

function formatPhp(amount: string): string {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(Number(amount))
}

function apiMessage(error: unknown, fallback: string): string {
  if (isApiClientError(error)) {
    const first = Object.values(error.fieldErrors ?? {})[0]?.[0]
    if (first) return first
  }
  return fallback
}

/**
 * The Cashier confirms a freshman's first payment (ADR 0043). It shows what will happen before
 * anything is recorded: the section the freshman will be enrolled in (the first with open seats),
 * or that every section is full and the payment will be held until one opens. At least the
 * minimum is required and no promissory note is asked for.
 */
export function FreshmanPaymentDialog({
  ticket,
  onClose,
  onConfirmed,
}: {
  ticket: QueueTicket
  onClose: () => void
  onConfirmed: (result: FreshmanPayment) => void
}) {
  const preview = useFreshmanPaymentPreviewQuery(ticket.student_id)
  const mutation = useConfirmFreshmanPaymentMutation()
  const [amountInput, setAmountInput] = useState<string | null>(null)
  const [reference, setReference] = useState("")
  const [error, setError] = useState("")
  const [mode, setMode] = useState<"full" | "installment">("installment")

  const minimum = preview.data?.minimum_amount ?? "1000.00"
  const alreadyConfirmed = preview.data?.already_confirmed === true
  // ADR 0046: with a discount on offer the Cashier chooses Installment (the default since the
  // owner's 2026-10-13 review, today's minimum-payment flow) or Pay in full (the discounted
  // estimate, never typed), so the discount is only ever applied on purpose. Once the
  // first payment is confirmed there is nothing to choose: confirming again changes nothing.
  const fullQuote = preview.data?.full_payment ?? null
  const discountOffered =
    !alreadyConfirmed && fullQuote?.applies === true && fullQuote.net_total !== null
  const payInFull = discountOffered && mode === "full"
  // Pre-filled with the minimum: the Cashier types the amount actually received.
  const amount =
    payInFull && fullQuote?.net_total ? fullQuote.net_total : (amountInput ?? minimum)
  const amountIsNumber = amount.trim() !== "" && Number.isFinite(Number(amount))
  const belowMinimum =
    !payInFull && amountIsNumber && Number(amount) < Number(minimum)
  const invalidAmount = !amountIsNumber || belowMinimum
  const busy = mutation.isPending
  // A transferee also pays first (ADR 0043, amended 2026-10-05): they already have an account and
  // chose their own subjects, so the dialog shows those instead of "the first section with seats".
  const isTransferee =
    ticket.is_transferee || preview.data?.student_type === "transferee"
  const chosen = preview.data?.enrollment ?? null

  const confirm = async () => {
    if (invalidAmount || busy) return
    setError("")
    try {
      const result = await mutation.mutateAsync({
        studentId: ticket.student_id,
        input: {
          amount: Number(amount).toFixed(2),
          ...(payInFull ? { payment_type: "full" as const } : {}),
          ...(reference.trim() ? { external_reference: reference.trim() } : {}),
        },
      })
      onConfirmed(result)
    } catch (confirmError) {
      setError(
        apiMessage(
          confirmError,
          "The payment could not be confirmed. Check the connection and try again.",
        ),
      )
      // A refused quote means the amount due changed: show the Cashier the refreshed one.
      void preview.refetch()
    }
  }

  return (
    <AlertDialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) onClose()
      }}
    >
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            Confirm first payment{" "}
            {preview.data ? `— ${preview.data.student_name}` : ""}
          </AlertDialogTitle>
          <AlertDialogDescription>
            {isTransferee
              ? preview.data?.can_enroll === false
                ? "This is a transferee paying for the first time. They have not chosen their subjects yet, so the payment is held as credit. No promissory note is needed. Confirming twice has no additional effect."
                : "This is a transferee paying for the first time. Confirming records the payment as credit and sends the subjects they chose to the Registrar. The Registrar approves the enrollment; only then are they enrolled and emailed. They already have an account. No promissory note is needed. Confirming twice has no additional effect."
              : preview.data?.can_enroll === false
                ? // Nothing to enroll in right now: the notice below explains that the payment is held.
                  "This is a freshman paying for the first time. No promissory note is needed. Confirming twice has no additional effect."
                : "This is a freshman paying for the first time. Confirming records the payment and submits them to the first section with open seats. The Registrar approves the enrollment; only then are they enrolled and emailed. Their account setup email is sent now. No promissory note is needed. Confirming twice has no additional effect."}
          </AlertDialogDescription>
        </AlertDialogHeader>

        {preview.isPending ? (
          <Skeleton className="h-24" aria-label="Loading the section" />
        ) : preview.isError ? (
          <Alert variant="destructive">
            <AlertDescription>
              The enrollment preview could not be loaded. You can still confirm
              the payment; the section is chosen when you confirm.
            </AlertDescription>
          </Alert>
        ) : chosen ? (
          <div className="grid gap-2 rounded-lg border bg-muted/20 p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Their chosen subjects
              </span>
              <Badge variant="secondary">{chosen.status_label}</Badge>
              <span className="text-muted-foreground">
                {chosen.total_units} units
              </span>
            </div>
            <ul className="grid gap-0.5 text-xs text-muted-foreground">
              {chosen.subjects.map((subject) => (
                <li key={subject.code}>
                  {subject.code} — {subject.title} ({subject.units} units)
                </li>
              ))}
            </ul>
            {preview.data.estimated_total && (
              <p className="font-medium">
                Estimated assessment {formatPhp(preview.data.estimated_total)}
              </p>
            )}
          </div>
        ) : isTransferee ? (
          <Alert>
            <AlertDescription>
              This transferee has not chosen and submitted their subjects yet.
              The payment will be held as credit and they are told to choose
              their subjects on the Enrollment page; it is applied when the
              Registrar approves.
            </AlertDescription>
          </Alert>
        ) : preview.data.can_enroll && preview.data.block ? (
          <div className="grid gap-2 rounded-lg border bg-muted/20 p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Will be submitted to
              </span>
              <Badge variant="secondary">{preview.data.block.block_code}</Badge>
              <span className="text-muted-foreground">
                {preview.data.block.total_units} units ·{" "}
                {preview.data.block.seats_remaining} seat
                {preview.data.block.seats_remaining === 1 ? "" : "s"} left
              </span>
            </div>
            <ul className="grid gap-0.5 text-xs text-muted-foreground">
              {preview.data.block.subjects.map((subject) => (
                <li key={subject.code}>
                  {subject.code} — {subject.title} ({subject.units} units)
                </li>
              ))}
            </ul>
            {preview.data.estimated_total && (
              <p className="font-medium">
                Estimated assessment {formatPhp(preview.data.estimated_total)}
              </p>
            )}
          </div>
        ) : (
          <Alert>
            <AlertDescription>
              Every section for this freshman&apos;s program and year is full
              right now (or they are not enrolled by block). The payment will be
              held, the account is still set up, and the student is notified
              when a section opens.
            </AlertDescription>
          </Alert>
        )}

        {alreadyConfirmed && (
          <Alert>
            <AlertDescription>
              This freshman&apos;s first payment was already confirmed.
              Confirming again changes nothing.
            </AlertDescription>
          </Alert>
        )}

        <FieldGroup>
          {discountOffered && fullQuote && (
            <PaymentTypeChoice
              percentage={fullQuote.percentage}
              payInFull={payInFull}
              disabled={busy}
              onChange={setMode}
            />
          )}
          <Field data-invalid={invalidAmount}>
            <FieldLabel htmlFor="freshman-payment-amount">
              Amount received
            </FieldLabel>
            <Input
              id="freshman-payment-amount"
              inputMode="decimal"
              value={amount}
              aria-invalid={invalidAmount}
              readOnly={payInFull}
              disabled={busy}
              onChange={(event) => setAmountInput(event.target.value)}
            />
            <FieldDescription>
              {payInFull && fullQuote?.discount_amount ? (
                <>
                  Full-payment discount ({Number(fullQuote.percentage)}%): −
                  {formatPhp(fullQuote.discount_amount)} off the estimated
                  assessment. Pay in full collects the net.
                </>
              ) : (
                <>
                  The first payment must be at least {formatPhp(minimum)}. The
                  balance stays on the student&apos;s account.
                </>
              )}
            </FieldDescription>
            {invalidAmount && (
              <FieldError>
                Enter an amount of at least {formatPhp(minimum)}.
              </FieldError>
            )}
          </Field>
          <Field>
            <FieldLabel htmlFor="freshman-payment-reference">
              Receipt or reference number (optional)
            </FieldLabel>
            <Input
              id="freshman-payment-reference"
              value={reference}
              disabled={busy}
              onChange={(event) => setReference(event.target.value)}
            />
          </Field>
        </FieldGroup>

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <AlertDialogFooter>
          <AlertDialogCancel disabled={busy}>Cancel</AlertDialogCancel>
          <Button
            type="button"
            disabled={invalidAmount || busy}
            onClick={() => void confirm()}
          >
            {busy ? "Confirming payment" : "Confirm first payment"}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
