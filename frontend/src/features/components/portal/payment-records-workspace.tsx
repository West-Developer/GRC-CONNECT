"use client"

import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { Paginator } from "@/features/components/portal/paginator"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { useCashierTransactionsQuery } from "@/features/hooks/use-cashier-transactions"

/**
 * Accounting Staff's transaction history, plus Registrar Head oversight —
 * combines enrollment payments and balance-payment receipts without changing
 * the guided Payment Queue used to process the current line.
 */
export function PaymentRecordsWorkspace() {
  const { session } = useAuth()
  const authorized =
    session?.role === "accounting_staff" || session?.role === "registrar_head"
  const [page, setPage] = useState(1)
  const [studentNumberInput, setStudentNumberInput] = useState("")
  const [processedOnInput, setProcessedOnInput] = useState("")
  const [studentNumber, setStudentNumber] = useState("")
  const [processedOn, setProcessedOn] = useState("")

  const transactionsQuery = useCashierTransactionsQuery(
    {
      student_number: studentNumber || undefined,
      processed_on: processedOn || undefined,
      page,
      per_page: 20,
    },
    { enabled: authorized },
  )

  return (
    <WorkspacePage
      title="Transaction history"
      description="Review enrollment payments and balance-payment receipts."
      unauthorized={!authorized}
      lastUpdated={transactionsQuery.dataUpdatedAt}
    >
      <Card>
        <CardHeader>
          <CardTitle level={2}>Find transactions</CardTitle>
        </CardHeader>
        <CardContent>
          <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              setStudentNumber(studentNumberInput.trim())
              setProcessedOn(processedOnInput)
              setPage(1)
            }}
          >
            <Field className="min-w-52 flex-1">
              <FieldLabel htmlFor="transaction-student-number">
                Student number
              </FieldLabel>
              <Input
                id="transaction-student-number"
                value={studentNumberInput}
                onChange={(event) => setStudentNumberInput(event.target.value)}
              />
            </Field>
            <Field className="max-w-xs">
              <FieldLabel htmlFor="processed-on">Processed on</FieldLabel>
              <Input
                id="processed-on"
                type="date"
                value={processedOnInput}
                onChange={(event) => setProcessedOnInput(event.target.value)}
              />
            </Field>
            <Button type="submit">Search</Button>
          </form>
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle level={2}>Transaction history</CardTitle>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={{ ...transactionsQuery, data: transactionsQuery.data?.data }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="No transactions match this filter."
            loadingLabel="Loading transaction history…"
          >
            {(transactions) => (
              <DataTable
                caption="Transaction history"
                rowKey={(transaction) => transaction.id}
                rows={transactions}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (transaction) => (
                      <span className="grid gap-1">
                        <span>{transaction.student_name}</span>
                        <span className="text-xs text-muted-foreground">
                          {transaction.student_number}
                        </span>
                      </span>
                    ),
                  },
                  {
                    key: "transaction_type",
                    header: "Type",
                    render: (transaction) =>
                      transaction.transaction_type === "enrollment_payment"
                        ? "Enrollment payment"
                        : "Balance payment",
                  },
                  {
                    key: "enrollment",
                    header: "Enrollment",
                    render: (transaction) => `#${transaction.enrollment_id}`,
                  },
                  {
                    key: "amount",
                    header: "Amount",
                    render: (transaction) => `₱${transaction.amount}`,
                  },
                  {
                    key: "processed_at",
                    header: "Processed at",
                    render: (transaction) =>
                      new Date(transaction.processed_at).toLocaleString(),
                  },
                ]}
              />
            )}
          </AsyncBoundary>
          <div className="mt-4">
            <Paginator
              currentPage={transactionsQuery.data?.meta.current_page ?? 1}
              lastPage={transactionsQuery.data?.meta.last_page ?? 1}
              onPageChange={setPage}
            />
          </div>
        </CardContent>
      </Card>
    </WorkspacePage>
  )
}
