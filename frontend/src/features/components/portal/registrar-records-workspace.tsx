"use client"

import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { Paginator } from "@/features/components/portal/paginator"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
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
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { Textarea } from "@/features/components/ui/textarea"
import { useAcademicGradesQuery } from "@/features/hooks/use-academic-grades"
import { useDebouncedValue } from "@/features/hooks/use-debounced-value"
import { useEnrollmentDocumentsQuery } from "@/features/hooks/use-enrollment-documents"
import { useTransfereeCreditsQuery } from "@/features/hooks/use-transferee-credits"
import {
  useDecideWithdrawalRequestMutation,
  useWithdrawalRequestsQuery,
} from "@/features/hooks/use-withdrawal-requests"
import { gradeBadgeVariant } from "@/features/lib/grade-presentation"
import type { TransfereeCredit } from "@/features/schemas/transferee-credit-schema"

const workspaceHeadings: Record<string, string> = {
  "credit-mappings": "Credit mappings",
  "drops-withdrawals": "Drops & withdrawals",
  "academic-records": "Academic records",
  "enrollment-documents": "Enrollment documents",
}

function pendingBadgeVariant(
  status: TransfereeCredit["status"],
): "default" | "destructive" | "outline" | "secondary" {
  if (status === "rejected") return "destructive"
  if (status === "approved") return "default"
  if (status === "endorsed") return "secondary"
  return "outline"
}

interface DecisionTarget {
  id: number
  action: "approve" | "reject"
}

/**
 * Unlike `AccountingPaymentWorkspace`/`AdmissionProvisioningWorkspace` (whose
 * modules are steps of one flow, so every card renders regardless of which
 * link was clicked), these four modules are unrelated record types — so
 * only the module matching `initialModuleId` renders. Every query below is
 * still called unconditionally (Rules of Hooks); only the inactive ones are
 * `enabled: false`.
 */
export function RegistrarRecordsWorkspace({
  initialModuleId = "credit-mappings",
}: {
  initialModuleId?: string
}) {
  const { session } = useAuth()
  // Credit mapping is the Program Chair's work (`ProgramChairCreditMappingsWorkspace`)
  // and the Registrar Head approves it in `RegistrarHeadCreditReviewWorkspace`
  // (ADR 0026, amended); Registrar Staff only follow the credits here, read-only.
  const authorized = session?.role === "registrar_staff"
  const heading =
    workspaceHeadings[initialModuleId] ?? workspaceHeadings["credit-mappings"]

  const showCredits = authorized && initialModuleId === "credit-mappings"
  const showWithdrawals = authorized && initialModuleId === "drops-withdrawals"
  const showGrades = authorized && initialModuleId === "academic-records"
  const showDocuments = authorized && initialModuleId === "enrollment-documents"

  const [creditsPage, setCreditsPage] = useState(1)
  const [withdrawalsPage, setWithdrawalsPage] = useState(1)
  const [gradesPage, setGradesPage] = useState(1)
  const [documentsPage, setDocumentsPage] = useState(1)
  const [documentsSearch, setDocumentsSearch] = useState("")

  const [decision, setDecision] = useState<DecisionTarget | null>(null)
  const [reason, setReason] = useState("")
  const [error, setError] = useState("")

  const creditsQuery = useTransfereeCreditsQuery(
    { page: creditsPage, per_page: 20 },
    { enabled: showCredits },
  )

  const withdrawalsQuery = useWithdrawalRequestsQuery(
    { page: withdrawalsPage, per_page: 20 },
    { enabled: showWithdrawals },
  )
  const decideWithdrawalMutation = useDecideWithdrawalRequestMutation()

  const gradesQuery = useAcademicGradesQuery(
    { page: gradesPage, per_page: 20 },
    { enabled: showGrades },
  )

  // Query the server 300 ms after typing stops, not on every keystroke.
  const debouncedDocumentsSearch = useDebouncedValue(documentsSearch, 300)
  const documentsQuery = useEnrollmentDocumentsQuery(
    {
      page: documentsPage,
      per_page: 20,
      search: debouncedDocumentsSearch.trim() || undefined,
    },
    { enabled: showDocuments },
  )

  const deciding = decideWithdrawalMutation.isPending
  const reasonRequired = decision?.action === "reject" && !reason.trim()

  const confirmDecision = async () => {
    if (!decision) return
    if (reasonRequired) return
    setError("")
    try {
      await decideWithdrawalMutation.mutateAsync({
        id: decision.id,
        input: {
          action: decision.action,
          reason: reason.trim() || undefined,
        },
      })
      setDecision(null)
      setReason("")
    } catch {
      setError(
        "The decision could not be saved. Check the connection and try again.",
      )
    }
  }

  return (
    <WorkspacePage
      title={heading}
      description="Maintain accurate enrollment and academic records."
      unauthorized={!authorized}
      lastUpdated={Math.max(
        creditsQuery.dataUpdatedAt,
        withdrawalsQuery.dataUpdatedAt,
        gradesQuery.dataUpdatedAt,
        documentsQuery.dataUpdatedAt,
      )}
    >
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {showCredits && (
        <>
          <Card>
            <CardHeader>
              <CardTitle level={2}>Transferee credits</CardTitle>
              <CardDescription>
                Credits the Program Head has endorsed. The Registrar Head
                decides them; you can follow their progress here.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <AsyncBoundary
                query={{
                  ...creditsQuery,
                  data: creditsQuery.data?.data,
                }}
                isEmpty={(rows) => rows.length === 0}
                emptyMessage="No credit requests have reached the Registrar Head yet."
                loadingLabel="Loading transferee credits…"
              >
                {(credits) => (
                  <DataTable
                    caption="Transferee credits"
                    rowKey={(credit) => credit.id}
                    rows={credits}
                    columns={[
                      {
                        key: "student",
                        header: "Student",
                        render: (credit) =>
                          credit.student_name
                            ? `${credit.student_name} (${credit.student_number})`
                            : credit.student_number,
                      },
                      {
                        key: "source",
                        header: "Previous subject",
                        render: (credit) =>
                          `${credit.source_subject_title} — ${credit.source_institution}${
                            credit.source_subject_code
                              ? ` (${credit.source_subject_code})`
                              : ""
                          }`,
                      },
                      {
                        key: "maps-to",
                        header: "Credited as",
                        render: (credit) =>
                          credit.subject_code
                            ? `${credit.subject_code} — ${credit.subject_title ?? ""}`
                            : "Not mapped",
                      },
                      {
                        key: "units",
                        header: "Units",
                        render: (credit) => credit.credited_units,
                      },
                      {
                        key: "status",
                        header: "Status",
                        render: (credit) => (
                          <Badge variant={pendingBadgeVariant(credit.status)}>
                            {credit.status_label}
                          </Badge>
                        ),
                      },
                    ]}
                  />
                )}
              </AsyncBoundary>
              <div className="mt-4">
                <Paginator
                  currentPage={creditsQuery.data?.meta.current_page ?? 1}
                  lastPage={creditsQuery.data?.meta.last_page ?? 1}
                  onPageChange={setCreditsPage}
                />
              </div>
            </CardContent>
          </Card>
        </>
      )}

      {showWithdrawals && (
        <Card>
          <CardHeader>
            <CardTitle level={2}>Withdrawal requests</CardTitle>
          </CardHeader>
          <CardContent>
            <AsyncBoundary
              query={{
                ...withdrawalsQuery,
                data: withdrawalsQuery.data?.data,
              }}
              isEmpty={(rows) => rows.length === 0}
              emptyMessage="No withdrawal requests are pending review."
              loadingLabel="Loading withdrawal requests…"
            >
              {(requests) => (
                <DataTable
                  caption="Withdrawal requests"
                  rowKey={(request) => request.id}
                  rows={requests}
                  columns={[
                    {
                      key: "student",
                      header: "Student",
                      render: (request) => request.student_number,
                    },
                    {
                      key: "enrollment",
                      header: "Enrollment",
                      render: (request) => `#${request.enrollment_id}`,
                    },
                    {
                      key: "reason",
                      header: "Reason",
                      render: (request) => request.reason,
                    },
                    {
                      key: "status",
                      header: "Status",
                      render: (request) => (
                        <Badge variant={pendingBadgeVariant(request.status)}>
                          {request.status_label}
                        </Badge>
                      ),
                    },
                    {
                      key: "actions",
                      header: "Actions",
                      render: (request) =>
                        request.status === "pending" && (
                          <div className="flex flex-wrap gap-2">
                            <Button
                              type="button"
                              size="sm"
                              disabled={deciding}
                              onClick={() => {
                                setDecision({
                                  id: request.id,
                                  action: "approve",
                                })
                                setReason("")
                                setError("")
                              }}
                            >
                              Approve
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="destructive"
                              disabled={deciding}
                              onClick={() => {
                                setDecision({
                                  id: request.id,
                                  action: "reject",
                                })
                                setReason("")
                                setError("")
                              }}
                            >
                              Reject
                            </Button>
                          </div>
                        ),
                    },
                  ]}
                />
              )}
            </AsyncBoundary>
            <div className="mt-4">
              <Paginator
                currentPage={withdrawalsQuery.data?.meta.current_page ?? 1}
                lastPage={withdrawalsQuery.data?.meta.last_page ?? 1}
                onPageChange={setWithdrawalsPage}
              />
            </div>
          </CardContent>
        </Card>
      )}

      {showGrades && (
        <Card>
          <CardHeader>
            <CardTitle level={2}>Academic records</CardTitle>
          </CardHeader>
          <CardContent>
            <AsyncBoundary
              query={{ ...gradesQuery, data: gradesQuery.data?.data }}
              isEmpty={(rows) => rows.length === 0}
              emptyMessage="No academic records exist yet."
              loadingLabel="Loading academic records…"
            >
              {(grades) => (
                <DataTable
                  caption="Academic records"
                  rowKey={(grade) => grade.id}
                  rows={grades}
                  columns={[
                    {
                      key: "student_id",
                      header: "Student ID",
                      render: (grade) => grade.student_id,
                    },
                    {
                      key: "student",
                      header: "Student",
                      render: (grade) => grade.student_number,
                    },
                    {
                      key: "subject",
                      header: "Subject",
                      render: (grade) => grade.subject_code,
                    },
                    {
                      key: "grade",
                      header: "Grade",
                      render: (grade) => grade.final_grade ?? "—",
                    },
                    {
                      key: "status",
                      header: "Status",
                      render: (grade) => (
                        <Badge variant={gradeBadgeVariant(grade.status)}>
                          {grade.status_label}
                        </Badge>
                      ),
                    },
                  ]}
                />
              )}
            </AsyncBoundary>
            <div className="mt-4">
              <Paginator
                currentPage={gradesQuery.data?.meta.current_page ?? 1}
                lastPage={gradesQuery.data?.meta.last_page ?? 1}
                onPageChange={setGradesPage}
              />
            </div>
          </CardContent>
        </Card>
      )}

      {showDocuments && (
        <Card>
          <CardHeader className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <CardTitle level={2}>Enrollment documents</CardTitle>
            <div className="w-full sm:w-96">
              <Input
                type="search"
                placeholder="Search by Student ID, Document Type, Document Number, or Date..."
                aria-label="Search enrollment documents"
                value={documentsSearch}
                onChange={(e) => {
                  setDocumentsSearch(e.target.value)
                  setDocumentsPage(1)
                }}
                className="w-full"
              />
            </div>
          </CardHeader>
          <CardContent>
            <AsyncBoundary
              query={{ ...documentsQuery, data: documentsQuery.data?.data }}
              isEmpty={(rows) => rows.length === 0}
              emptyMessage="No enrollment documents have been generated yet."
              loadingLabel="Loading enrollment documents…"
            >
              {(documents) => (
                <DataTable
                  caption="Enrollment documents"
                  rowKey={(document) => document.id}
                  rows={documents}
                  columns={[
                    {
                      key: "student",
                      header: "Student",
                      render: (document) => document.student_number,
                    },
                    {
                      key: "type",
                      header: "Document type",
                      render: (document) => document.document_type_label,
                    },
                    {
                      key: "number",
                      header: "Document number",
                      render: (document) => (
                        <span className="font-mono">
                          {document.document_number}
                        </span>
                      ),
                    },
                    {
                      key: "generated_at",
                      header: "Generated at",
                      render: (document) =>
                        new Date(document.generated_at).toLocaleString(),
                    },
                  ]}
                />
              )}
            </AsyncBoundary>
            <div className="mt-4">
              <Paginator
                currentPage={documentsQuery.data?.meta.current_page ?? 1}
                lastPage={documentsQuery.data?.meta.last_page ?? 1}
                onPageChange={setDocumentsPage}
              />
            </div>
          </CardContent>
        </Card>
      )}

      <AlertDialog
        open={decision !== null}
        onOpenChange={(open) => {
          if (!open && !deciding) setDecision(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Confirm decision</AlertDialogTitle>
            <AlertDialogDescription>
              This decision is recorded in the operational audit log and
              notifies the student.
            </AlertDialogDescription>
          </AlertDialogHeader>
          {decision?.action === "reject" && (
            <Field data-invalid={reasonRequired}>
              <FieldLabel htmlFor="registrar-record-decision-reason">
                Reason
              </FieldLabel>
              <Textarea
                id="registrar-record-decision-reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                disabled={deciding}
                aria-describedby={
                  reasonRequired
                    ? "registrar-record-decision-reason-error"
                    : undefined
                }
              />
              {reasonRequired && (
                <p
                  id="registrar-record-decision-reason-error"
                  className="text-sm text-destructive"
                >
                  Reason is required to {decision?.action}.
                </p>
              )}
            </Field>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deciding}>Cancel</AlertDialogCancel>
            <Button
              type="button"
              disabled={deciding || reasonRequired}
              onClick={() => void confirmDecision()}
            >
              {deciding ? "Saving decision" : "Confirm decision"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </WorkspacePage>
  )
}
