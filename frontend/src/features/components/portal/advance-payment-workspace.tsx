"use client"

import { useState } from "react"
import { CheckCircle2, DollarSign, Pencil, Search } from "lucide-react"
import { toast } from "sonner"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { TableFit } from "@/features/components/ui/table"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import { useCashierStudentSearchQuery } from "@/features/hooks/use-cashier-transactions"
import {
  useRecordStudentAccountPaymentMutation,
  useStudentAccountQuery,
} from "@/features/hooks/use-student-account"
import { formatYearLevel } from "@/features/lib/format-year-level"

function formatPhp(amount: string | number): string {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(Number(amount))
}

// The backend rejects a shorter search (a single letter would match everyone).
const MIN_SEARCH_LENGTH = 2

export function AdvancePaymentWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "accounting_staff"

  const [studentSearchInput, setStudentSearchInput] = useState("")
  const [activeSearchQuery, setActiveSearchQuery] = useState<string | null>(null)
  const [selectedStudentId, setSelectedStudentId] = useState<number | null>(null)

  // Any student, whatever their enrollment or queue state. (This page used to
  // reuse the payment queue's candidate lookup, which only finds a student
  // waiting for payment, so an already-enrolled student could not be found.)
  const searchQuery = useCashierStudentSearchQuery(activeSearchQuery, {
    enabled: authorized && activeSearchQuery !== null,
  })
  const matches = searchQuery.data ?? []
  // One match opens straight away; several are listed to choose from.
  const student =
    matches.length === 1
      ? matches[0]
      : (matches.find((match) => match.student_id === selectedStudentId) ??
        null)

  const studentId = student?.student_id ?? null
  const accountQuery = useStudentAccountQuery(studentId, {
    enabled: authorized && studentId !== null,
  })

  const recordPaymentMutation = useRecordStudentAccountPaymentMutation()

  // Payee Dialog State
  const [payeeDialogOpen, setPayeeDialogOpen] = useState(false)
  const [payeeAmount, setPayeeAmount] = useState("")
  const [payeeAmountError, setPayeeAmountError] = useState("")

  // Edit Classification Dialog State
  const [editDialogOpen, setEditDialogOpen] = useState(false)
  const [editClassification, setEditClassification] = useState<"payee" | "scholar">("payee")

  // Success Feedback
  const [successReceipt, setSuccessReceipt] = useState<{
    amount: number
    studentName: string
    studentNumber: string
  } | null>(null)

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault()
    const trimmed = studentSearchInput.trim()
    if (trimmed.length < MIN_SEARCH_LENGTH) return
    setSuccessReceipt(null)
    setSelectedStudentId(null)
    // Searching the same text again refreshes the results instead of doing nothing.
    if (trimmed === activeSearchQuery) void searchQuery.refetch()
    else setActiveSearchQuery(trimmed)
  }

  const account = accountQuery.data

  const handleOpenPayeeDialog = () => {
    setPayeeAmount("")
    setPayeeAmountError("")
    setPayeeDialogOpen(true)
  }

  const handleConfirmPayeePayment = async () => {
    if (!studentId || !student) return
    const numericAmount = Number(payeeAmount)
    if (isNaN(numericAmount) || numericAmount <= 0) {
      setPayeeAmountError("Please enter a valid advance payment amount.")
      return
    }

    try {
      await recordPaymentMutation.mutateAsync({
        studentId,
        amount: numericAmount,
        financial_status: "payee",
      })
      setSuccessReceipt({
        amount: numericAmount,
        studentName: student.student_name,
        studentNumber: student.student_number,
      })
      setPayeeDialogOpen(false)
      toast.success("Payee advance payment recorded successfully.")
    } catch {
      toast.error("Failed to record advance payment. Please try again.")
    }
  }

  return (
    <WorkspacePage
      title="Advance Payment"
      description="Record student advance payments and minimum fee deposits, and edit a student's billing classification. Scholarship discounts are assigned from the Payment Queue when confirming a payment."
      unauthorized={!authorized}
    >
      {/* Student Search */}
      <Card>
        <CardHeader>
          <CardTitle level={2}>Find student</CardTitle>
          <CardDescription>
            Search by student number, student name, or email to record an advance payment or edit the billing classification.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSearch} className="flex flex-wrap items-end gap-3">
            <Field className="min-w-64 flex-1">
              <FieldLabel htmlFor="advance-search-student">
                Student number, name, or email
              </FieldLabel>
              <Input
                id="advance-search-student"
                value={studentSearchInput}
                onChange={(e) => setStudentSearchInput(e.target.value)}
                placeholder="e.g. 2026-0001, student name, or email"
              />
            </Field>
            <Button
              type="submit"
              disabled={studentSearchInput.trim().length < MIN_SEARCH_LENGTH}
            >
              <Search className="mr-1.5 size-4" />
              Find student
            </Button>
          </form>

          {searchQuery.isFetching && (
            <p className="mt-3 text-sm text-muted-foreground">Finding student record…</p>
          )}

          {searchQuery.isError && (
            <Alert variant="destructive" className="mt-3">
              <AlertDescription>
                The student search failed. Check the connection and try again.
              </AlertDescription>
            </Alert>
          )}

          {searchQuery.isSuccess && matches.length === 0 && activeSearchQuery && (
            <Alert variant="destructive" className="mt-3">
              <AlertDescription>
                No student found matching &quot;{activeSearchQuery}&quot;. Please verify the student number or name.
              </AlertDescription>
            </Alert>
          )}

          {matches.length > 1 && (
            <ul className="mt-3 grid gap-2" aria-label="Matching students">
              {matches.map((match) => (
                <li key={match.student_id}>
                  <Button
                    type="button"
                    variant={match.student_id === selectedStudentId ? "default" : "outline"}
                    aria-pressed={match.student_id === selectedStudentId}
                    className="h-auto w-full justify-between py-2 text-left"
                    onClick={() => {
                      setSuccessReceipt(null)
                      setSelectedStudentId(match.student_id)
                    }}
                  >
                    <span>{match.student_name}</span>
                    <span className="font-mono text-xs opacity-80">
                      {match.student_number} · {formatYearLevel(match.year_level)}
                    </span>
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      {/* Success Receipt Alert */}
      {successReceipt && (
        <Alert className="border-emerald-600/40 bg-emerald-50 dark:bg-emerald-950/20">
          <CheckCircle2 className="size-5 text-emerald-600" />
          <AlertDescription className="space-y-1 text-emerald-950 dark:text-emerald-100">
            <p className="font-semibold">Advance Payment Recorded Successfully</p>
            <p className="text-sm">
              Recorded {formatPhp(successReceipt.amount)} for {successReceipt.studentName} ({successReceipt.studentNumber}).
              The credit has been allocated to their account balance and will reflect on their Certificate of Registration (COR / COM).
            </p>
          </AlertDescription>
        </Alert>
      )}

      {/* Student Account Overview */}
      {student && (
        <div className="grid gap-5">
          <Card>
            <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <CardTitle level={2}>{student.student_name}</CardTitle>
                <CardDescription>
                  {student.student_number} · {formatYearLevel(student.year_level)}
                </CardDescription>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant={(account?.financial_status ?? student.financial_status) === "scholar" ? "default" : "secondary"}>
                  {account?.financial_status_label ?? student.financial_status_label}
                </Badge>
                {account?.has_promissory_note_on_file && (
                  <Badge variant="outline" className="border-amber-600/40 text-amber-800 bg-amber-50">
                    Promissory note
                  </Badge>
                )}
              </div>
            </CardHeader>
            <CardContent>
              <AsyncBoundary
                query={accountQuery}
                loadingLabel="Loading account details…"
              >
                {(acc) => (
                  <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-lg border p-4">
                      <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                        Advance Payment Credit
                      </p>
                      <p className="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {formatPhp(acc.advance_payment_balance)}
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        Available balance for future enrollment
                      </p>
                    </div>

                    <div className="rounded-lg border p-4">
                      <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                        Current Outstanding Balance
                      </p>
                      <p className={`mt-1 text-2xl font-bold ${acc.outstanding_balance !== "0.00" ? "text-destructive" : "text-foreground"}`}>
                        {formatPhp(acc.outstanding_balance)}
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        Total unpaid assessment across terms
                      </p>
                    </div>

                    <div className="rounded-lg border p-4">
                      <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                        Prior Term Balance
                      </p>
                      <p className="mt-1 text-2xl font-bold text-foreground">
                        {formatPhp(acc.prior_balance)}
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        Balance from previous academic terms
                      </p>
                    </div>
                  </div>
                )}
              </AsyncBoundary>
            </CardContent>
            <CardFooter className="flex flex-wrap gap-3 border-t pt-4">
              <Button
                type="button"
                onClick={handleOpenPayeeDialog}
                className="gap-1.5"
              >
                <DollarSign className="size-4" />
                Record Payee Advance Payment
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setEditClassification(account?.financial_status ?? "payee")
                  setEditDialogOpen(true)
                }}
                className="gap-1.5"
              >
                <Pencil className="size-4" />
                Edit Classification
              </Button>
            </CardFooter>
          </Card>

          {/* Payment History */}
          {account?.transactions && account.transactions.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle level={3}>Payment History</CardTitle>
                <CardDescription>
                  Previous payments and advance credits recorded with the Cashier.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <TableFit className="rounded-md border">
                  <table className="w-full text-left text-sm" aria-label="Student payment history">
                    <thead className="bg-muted/50 text-xs text-muted-foreground uppercase">
                      <tr>
                        <th className="p-3">Date & Time</th>
                        <th className="p-3">Type</th>
                        <th className="p-3">Reference / OR</th>
                        <th className="p-3">Cashier</th>
                        <th className="p-3 text-right">Amount</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {account.transactions.map((tx) => (
                        <tr key={tx.id} className="hover:bg-muted/30">
                          <td className="p-3 text-muted-foreground">
                            {new Date(tx.processed_at).toLocaleString()}
                          </td>
                          <td className="p-3 font-medium">
                            {tx.transaction_type_label}
                          </td>
                          <td className="p-3 font-mono text-xs">
                            {tx.reference_number}
                          </td>
                          <td className="p-3 text-muted-foreground">
                            {tx.cashier_name}
                          </td>
                          <td className="p-3 text-right font-semibold">
                            {formatPhp(tx.amount)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </TableFit>
              </CardContent>
            </Card>
          )}
        </div>
      )}

      {/* Payee Advance Payment Modal */}
      <Dialog open={payeeDialogOpen} onOpenChange={setPayeeDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Record Payee Advance Payment</DialogTitle>
            <DialogDescription>
              Enter the advance payment amount for {student?.student_name}.
            </DialogDescription>
          </DialogHeader>

          <FieldGroup className="py-2">
            <Field data-invalid={Boolean(payeeAmountError)}>
              <FieldLabel htmlFor="payee-advance-amount">
                Advance Payment Amount (PHP)
              </FieldLabel>
              <Input
                id="payee-advance-amount"
                type="number"
                min="1"
                step="50"
                value={payeeAmount}
                onChange={(e) => {
                  setPayeeAmount(e.target.value)
                  if (Number(e.target.value) > 0) {
                    setPayeeAmountError("")
                  }
                }}
                placeholder="0.00"
              />
              <FieldDescription>
                Payments are allocated to outstanding balances first, with any excess credited as Advance Payment.
              </FieldDescription>
              {payeeAmountError && <FieldError>{payeeAmountError}</FieldError>}
            </Field>

            {Number(payeeAmount) > 0 && (
              <div className="rounded-lg border bg-muted/40 p-3 text-xs space-y-1.5">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Deposit entered:</span>
                  <span className="font-semibold">{formatPhp(payeeAmount)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Classification:</span>
                  <span className="font-medium">Fee-paying Payee</span>
                </div>
              </div>
            )}
          </FieldGroup>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              onClick={() => setPayeeDialogOpen(false)}
            >
              Back
            </Button>
            <Button
              type="button"
              disabled={recordPaymentMutation.isPending || !payeeAmount || Number(payeeAmount) <= 0}
              onClick={() => void handleConfirmPayeePayment()}
            >
              {recordPaymentMutation.isPending ? "Recording…" : "Confirm Advance Payment"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Classification Dialog */}
      <Dialog open={editDialogOpen} onOpenChange={setEditDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Edit Student Classification</DialogTitle>
            <DialogDescription>
              Update {student?.student_name}&apos;s billing classification between Fee-paying Payee and Scholar.
            </DialogDescription>
          </DialogHeader>

          <FieldGroup className="py-2 space-y-3">
            <Field>
              <FieldLabel htmlFor="edit-classification-select">
                Billing Classification
              </FieldLabel>
              <Select
                value={editClassification}
                onValueChange={(val) => setEditClassification(val as "payee" | "scholar")}
              >
                <SelectTrigger id="edit-classification-select" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="payee">Payee (Regular Fee-paying Student)</SelectItem>
                  <SelectItem value="scholar">Scholar (Scholarship Grantee)</SelectItem>
                </SelectContent>
              </Select>
            </Field>

            <div className="rounded-lg border bg-muted/40 p-3 text-xs space-y-1">
              <p className="font-medium text-foreground">Important Note</p>
              <p className="text-muted-foreground">
                Updating this classification takes effect immediately on the student&apos;s account and reflects on their official Certificate of Registration (COR / COM).
              </p>
            </div>
          </FieldGroup>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              onClick={() => setEditDialogOpen(false)}
            >
              Back
            </Button>
            <Button
              type="button"
              disabled={recordPaymentMutation.isPending}
              onClick={async () => {
                if (!studentId) return
                try {
                  await recordPaymentMutation.mutateAsync({
                    studentId,
                    amount: 0,
                    financial_status: editClassification,
                  })
                  setEditDialogOpen(false)
                  toast.success("Student classification updated successfully.")
                } catch {
                  toast.error("Failed to update classification. Please try again.")
                }
              }}
            >
              {recordPaymentMutation.isPending ? "Saving…" : "Save Changes"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </WorkspacePage>
  )
}

