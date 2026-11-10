import { AccordionCard } from "@/features/components/portal/accordion-card"
import { TableFit } from "@/features/components/ui/table"
import { Badge } from "@/features/components/ui/badge"
import type { StudentAccount } from "@/features/schemas/student-account-schema"

function formatPhp(amount: string): string {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(Number(amount))
}

/**
 * Read-only account detail for the authenticated Student. Cashier controls
 * deliberately stay in AccountingPaymentWorkspace so this panel cannot
 * confirm an enrollment or record a payment.
 */
export function StudentAccountBalancePanel({
  account,
  defaultOpen = true,
}: {
  account: StudentAccount
  defaultOpen?: boolean
}) {
  return (
    <AccordionCard
      id="account-balance"
      title="Account balance"
      description="Your remaining balance across active academic terms."
      defaultOpen={defaultOpen}
    >
      <dl className="grid gap-3 sm:grid-cols-2 md:grid-cols-5">
        <div className="rounded-lg border p-3">
          <dt className="text-xs text-muted-foreground">Total assessed</dt>
          <dd className="text-xl font-semibold">
            {formatPhp(account.total_assessed)}
          </dd>
        </div>
        <div className="rounded-lg border p-3">
          <dt className="text-xs text-muted-foreground">Total paid</dt>
          <dd className="text-xl font-semibold text-primary">
            {formatPhp(account.total_paid)}
          </dd>
        </div>
        <div className="rounded-lg border p-3">
          <dt className="text-xs text-muted-foreground">Outstanding balance</dt>
          <dd className={`text-xl font-semibold ${account.outstanding_balance !== "0.00" ? "text-destructive" : "text-emerald-600"}`}>
            {formatPhp(account.outstanding_balance)}
          </dd>
        </div>
        <div className="rounded-lg border p-3">
          <dt className="text-xs text-muted-foreground">Advance payment credit</dt>
          <dd className={`text-xl font-semibold ${account.advance_payment_balance !== "0.00" ? "text-emerald-600 dark:text-emerald-400 font-bold" : "text-muted-foreground"}`}>
            {formatPhp(account.advance_payment_balance)}
          </dd>
        </div>
        <div className="rounded-lg border p-3">
          <dt className="text-xs text-muted-foreground">Prior balance</dt>
          <dd className="text-xl font-semibold">
            {formatPhp(account.prior_balance)}
          </dd>
        </div>
      </dl>
      {account.advance_payment_balance !== "0.00" && (
        <div className="rounded-lg border border-emerald-500/30 bg-emerald-50/50 dark:bg-emerald-950/20 p-3 text-xs text-emerald-800 dark:text-emerald-300 flex items-center justify-between">
          <span>
            <strong>Advance Credit:</strong> You have an advance payment balance of {formatPhp(account.advance_payment_balance)}. This credit is recorded on your student ledger and will apply towards your upcoming assessments or fees.
          </span>
        </div>
      )}
      {account.has_promissory_note_on_file && (
        <Badge variant="outline" className="w-fit border-amber-600/40 text-amber-800 bg-amber-50 dark:bg-amber-950/20">
          Promissory note on file
        </Badge>
      )}
      {account.entries.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          You have no outstanding active balance.
        </p>
      ) : (
        <div className="grid gap-2">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Outstanding Balances by Term
          </h3>
          <ul className="grid gap-2" aria-label="Outstanding balances by term">
            {account.entries.map((entry) => (
              <li
                key={entry.enrollment_id}
                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"
              >
                <span className="font-medium">{entry.academic_term_label}</span>
                <div className="flex items-center gap-3">
                  <span className="text-xs text-muted-foreground">
                    Assessed: {formatPhp(entry.assessment_amount)} · Paid: {formatPhp(entry.confirmed_payment_amount)}
                  </span>
                  <span className="font-semibold text-destructive">
                    {formatPhp(entry.outstanding_balance)}
                  </span>
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
      {account.transactions && account.transactions.length > 0 && (
        <div className="grid gap-2 pt-2 border-t">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Cashier Payment Transactions
          </h3>
          <TableFit className="rounded-lg border">
            <table className="w-full text-left text-sm" aria-label="Payment transactions">
              <thead className="bg-muted/50 text-xs font-medium text-muted-foreground">
                <tr>
                  <th className="p-2.5">Date & Time</th>
                  <th className="p-2.5">Type</th>
                  <th className="p-2.5">Reference / OR</th>
                  <th className="p-2.5">Cashier</th>
                  <th className="p-2.5 text-right">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {account.transactions.map((tx) => (
                  <tr key={tx.id} className="hover:bg-muted/30">
                    <td className="p-2.5 text-xs text-muted-foreground">
                      {new Date(tx.processed_at).toLocaleString()}
                    </td>
                    <td className="p-2.5 font-medium">
                      {tx.transaction_type_label}
                      {tx.promissory_note_on_file && (
                        <Badge variant="secondary" className="ml-1.5 text-[10px] py-0">
                          Promissory
                        </Badge>
                      )}
                    </td>
                    <td className="p-2.5 font-mono text-xs">
                      {tx.reference_number}
                    </td>
                    <td className="p-2.5 text-xs text-muted-foreground">
                      {tx.cashier_name}
                    </td>
                    <td className="p-2.5 text-right font-semibold">
                      {formatPhp(tx.amount)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableFit>
        </div>
      )}
    </AccordionCard>
  )
}
