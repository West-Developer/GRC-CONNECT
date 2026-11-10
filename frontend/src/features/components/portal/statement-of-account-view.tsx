"use client"

import { AccordionCard } from "@/features/components/portal/accordion-card"
import { Badge } from "@/features/components/ui/badge"
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableRow,
} from "@/features/components/ui/table"
import type {
  StatementOfAccount,
  StatementTerm,
} from "@/features/schemas/statement-of-account-schema"

export function formatPhp(amount: string | number): string {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(Number(amount))
}

function formatDate(value: string | null): string {
  if (value === null) return "—"

  return new Intl.DateTimeFormat("en-PH", {
    dateStyle: "medium",
    timeZone: "Asia/Manila",
  }).format(new Date(value))
}

const isZero = (amount: string) => Number(amount) === 0

/**
 * The first thing a student wants from this page: how much is still owed.
 * One dominant figure, the reason for it in a sentence, and the supporting
 * totals kept small beside it.
 */
function BalanceSummary({ statement }: { statement: StatementOfAccount }) {
  const { summary, terms } = statement
  const owed = !isZero(summary.outstanding_balance)
  const unpaidTerms = terms.filter((term) => !isZero(term.outstanding))

  return (
    <section
      aria-label="Balance summary"
      className={`grid gap-5 rounded-xl border border-l-4 bg-card p-5 sm:grid-cols-[1fr_auto] sm:items-end sm:p-6 ${
        owed ? "border-l-destructive" : "border-l-success"
      }`}
    >
      <div className="grid gap-1">
        <p className="text-sm font-medium text-muted-foreground">
          Outstanding balance
        </p>
        <p
          className={`font-heading text-4xl leading-none tabular-nums sm:text-5xl ${
            owed ? "text-destructive" : "text-success"
          }`}
        >
          {formatPhp(summary.outstanding_balance)}
        </p>
        <p className="mt-1 text-sm text-muted-foreground">
          {owed
            ? unpaidTerms
                .map(
                  (term) => `${formatPhp(term.outstanding)} from ${term.label}`,
                )
                .join(" and ")
            : terms.length === 0
              ? "Nothing has been assessed yet."
              : "Everything assessed so far is paid."}
        </p>
      </div>
      <dl className="grid min-w-56 gap-1.5 text-sm">
        <div className="flex justify-between gap-6">
          <dt className="text-muted-foreground">Assessed</dt>
          <dd className="tabular-nums">{formatPhp(summary.total_assessed)}</dd>
        </div>
        <div className="flex justify-between gap-6">
          <dt className="text-muted-foreground">Paid</dt>
          <dd className="tabular-nums">{formatPhp(summary.total_paid)}</dd>
        </div>
        {!isZero(summary.advance_payment_balance) && (
          <div className="flex justify-between gap-6 font-medium text-success">
            <dt>Advance credit</dt>
            <dd className="tabular-nums">
              {formatPhp(summary.advance_payment_balance)}
            </dd>
          </div>
        )}
      </dl>
    </section>
  )
}

function Amount({ value, tone }: { value: string; tone?: "credit" | "owed" }) {
  return (
    <span
      className={`tabular-nums ${
        tone === "credit"
          ? "text-success"
          : tone === "owed"
            ? "font-semibold text-destructive"
            : ""
      }`}
    >
      {value}
    </span>
  )
}

/** One term as a statement ledger: charges, then payments, then what is left. */
function TermLedger({ term }: { term: StatementTerm }) {
  const settled = isZero(term.outstanding)

  return (
    <div className="w-full min-w-0 overflow-hidden rounded-lg border">
      <Table>
        <TableCaption className="sr-only">
          Charges and payments for {term.label}
        </TableCaption>
        <TableBody>
          {term.lines.map((line, index) => {
            const discount =
              line.category === "scholarship_discount" ||
              line.category === "full_payment_discount"

            return (
              <TableRow key={`${line.category}-${index}`}>
                <TableCell className="whitespace-normal">
                  {line.label}
                  {line.quantity !== null && line.unit_amount !== null && (
                    <span className="block text-xs text-muted-foreground">
                      {Number(line.quantity)} units ×{" "}
                      {formatPhp(line.unit_amount)}
                    </span>
                  )}
                </TableCell>
                <TableCell className="text-right">
                  <Amount
                    value={formatPhp(line.amount)}
                    tone={discount ? "credit" : undefined}
                  />
                </TableCell>
              </TableRow>
            )
          })}
          <TableRow className="bg-muted/60 font-semibold">
            <TableCell>Total assessed</TableCell>
            <TableCell className="text-right">
              <Amount value={formatPhp(term.assessment_total)} />
            </TableCell>
          </TableRow>

          {term.payments.length === 0 ? (
            <TableRow>
              <TableCell
                colSpan={2}
                className="whitespace-normal text-muted-foreground"
              >
                No payment has been recorded for this term yet.
              </TableCell>
            </TableRow>
          ) : (
            term.payments.map((payment) => (
              <TableRow key={payment.reference_number}>
                <TableCell className="whitespace-normal">
                  <span className="flex flex-wrap items-center gap-x-2">
                    {payment.label}
                    {payment.promissory_note_on_file && (
                      <Badge variant="outline">Promissory note</Badge>
                    )}
                  </span>
                  <span className="block text-xs text-muted-foreground">
                    {formatDate(payment.paid_at)} ·{" "}
                    <span className="font-mono">
                      {payment.reference_number}
                    </span>
                  </span>
                </TableCell>
                <TableCell className="text-right">
                  <Amount
                    value={`− ${formatPhp(payment.amount)}`}
                    tone="credit"
                  />
                </TableCell>
              </TableRow>
            ))
          )}

          <TableRow className="border-t-2 font-semibold">
            <TableCell>Unpaid this term</TableCell>
            <TableCell className="text-right">
              <Amount
                value={formatPhp(term.outstanding)}
                tone={settled ? undefined : "owed"}
              />
            </TableCell>
          </TableRow>
          <TableRow>
            <TableCell className="text-muted-foreground">
              Balance brought forward
            </TableCell>
            <TableCell className="text-right">
              <Amount value={formatPhp(term.prior_balance)} />
            </TableCell>
          </TableRow>
          <TableRow className="bg-muted/60 font-semibold">
            <TableCell>Running balance</TableCell>
            <TableCell className="text-right">
              <Amount value={formatPhp(term.running_balance)} />
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  )
}

/**
 * The Statement of Account, presentational: the caller owns fetching and the
 * term filter (ADR 0036). A term with something unpaid opens by itself; settled
 * terms stay folded to a heading so the page opens on what still needs action.
 */
export function StatementOfAccountView({
  statement,
}: {
  statement: StatementOfAccount
}) {
  return (
    <div className="grid gap-4">
      <BalanceSummary statement={statement} />

      {statement.terms.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          No assessed enrollment to show yet.
        </p>
      ) : (
        // Newest term first: that is the one being paid now.
        [...statement.terms].reverse().map((term) => {
          const settled = isZero(term.outstanding)

          return (
            <AccordionCard
              key={term.enrollment_id}
              id={`soa-term-${term.enrollment_id}`}
              title={term.label}
              defaultOpen={!settled}
              badges={
                <Badge variant={settled ? "secondary" : "outline"}>
                  {settled
                    ? "Settled"
                    : `Unpaid ${formatPhp(term.outstanding)}`}
                </Badge>
              }
              description={
                term.enrollment_status === "enrolled"
                  ? undefined
                  : `Enrollment ${term.enrollment_status.replaceAll("_", " ")}`
              }
            >
              <TermLedger term={term} />
            </AccordionCard>
          )
        })
      )}

      {statement.credits.length > 0 && (
        <AccordionCard
          id="soa-credits"
          title="Advance payments"
          description="Credits that belong to no term yet."
        >
          <ul className="grid gap-2 text-sm">
            {statement.credits.map((credit) => (
              <li
                key={credit.reference_number}
                className="flex flex-wrap justify-between gap-2 border-b pb-2"
              >
                <span>
                  {formatDate(credit.received_at)} ·{" "}
                  <span className="font-mono text-xs">
                    {credit.reference_number}
                  </span>
                </span>
                <Amount value={formatPhp(credit.amount)} tone="credit" />
              </li>
            ))}
          </ul>
        </AccordionCard>
      )}
    </div>
  )
}
