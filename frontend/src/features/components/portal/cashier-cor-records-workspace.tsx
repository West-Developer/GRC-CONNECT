"use client"

import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { CertificateOfRegistrationDocument } from "@/features/components/portal/certificate-of-registration-document"
import { DataTable } from "@/features/components/portal/data-table"
import { Paginator } from "@/features/components/portal/paginator"
import {
  DownloadPdfButton,
  PrintDocument,
} from "@/features/components/portal/print-document"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { useDebouncedValue } from "@/features/hooks/use-debounced-value"
import {
  useCertificateOfRegistrationQuery,
  useEnrollmentDocumentsQuery,
} from "@/features/hooks/use-enrollment-documents"

interface SelectedStudent {
  studentName: string
  studentNumber: string
}

/** Authorized Accounting Staff, Registrar Heads, and Registrar Staff can review COR history. */
export function CashierCorRecordsWorkspace() {
  const { session } = useAuth()
  const authorized =
    session?.role === "accounting_staff" ||
    session?.role === "registrar_head" ||
    session?.role === "registrar_staff"
  const [studentNumber, setStudentNumber] = useState("")
  const [studentName, setStudentName] = useState("")
  const [page, setPage] = useState(1)
  const [historyPage, setHistoryPage] = useState(1)
  const [selectedStudent, setSelectedStudent] =
    useState<SelectedStudent | null>(null)
  const [selectedDocumentId, setSelectedDocumentId] = useState<number | null>(
    null,
  )
  // `useDeferredValue` only lowers render priority: it still queried the server
  // on every keystroke. Debounce so a query fires 300 ms after typing stops.
  const deferredStudentNumber = useDebouncedValue(studentNumber.trim(), 300)
  const deferredStudentName = useDebouncedValue(studentName.trim(), 300)
  const documentsQuery = useEnrollmentDocumentsQuery(
    {
      page,
      per_page: 20,
      ...(deferredStudentNumber
        ? { student_number: deferredStudentNumber }
        : {}),
      ...(deferredStudentName ? { student_name: deferredStudentName } : {}),
    },
    { enabled: authorized },
  )
  const historyQuery = useEnrollmentDocumentsQuery(
    {
      page: historyPage,
      per_page: 100,
      ...(selectedStudent
        ? { student_number: selectedStudent.studentNumber }
        : {}),
    },
    { enabled: authorized && selectedStudent !== null },
  )
  const corQuery = useCertificateOfRegistrationQuery(selectedDocumentId, {
    enabled: authorized && selectedDocumentId !== null,
  })

  const openStudentHistory = (student: SelectedStudent) => {
    setHistoryPage(1)
    setSelectedStudent(student)
  }

  return (
    <WorkspacePage
      title="Certificate of Registration Records"
      description="Find, review, and print a student's confirmed COR history."
      unauthorized={!authorized}
      lastUpdated={Math.max(
        documentsQuery.dataUpdatedAt,
        historyQuery.dataUpdatedAt,
        corQuery.dataUpdatedAt,
      )}
    >
      <Card className="print:hidden">
        <CardHeader>
          <CardTitle level={2}>Find a student's COR</CardTitle>
        </CardHeader>
        <CardContent>
          <FieldGroup className="max-w-2xl sm:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="cashier-cor-student-number">
                Student number
              </FieldLabel>
              <Input
                id="cashier-cor-student-number"
                value={studentNumber}
                onChange={(event) => {
                  setStudentNumber(event.target.value)
                  setPage(1)
                }}
                placeholder="e.g. 2026-0001"
              />
            </Field>
            <Field>
              <FieldLabel htmlFor="cashier-cor-student-name">
                Student name
              </FieldLabel>
              <Input
                id="cashier-cor-student-name"
                value={studentName}
                onChange={(event) => {
                  setStudentName(event.target.value)
                  setPage(1)
                }}
                placeholder="e.g. Aurora S. Lopez"
              />
            </Field>
          </FieldGroup>
          <p className="mt-3 text-sm text-muted-foreground">
            Search by either field. Using both fields narrows the results.
          </p>
        </CardContent>
      </Card>

      <Card className="print:hidden">
        <CardHeader>
          <CardTitle level={2}>Confirmed COR history</CardTitle>
        </CardHeader>
        <CardContent>
          <AsyncBoundary
            query={{ ...documentsQuery, data: documentsQuery.data?.data }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="No confirmed COR records match this search."
            loadingLabel="Loading confirmed COR records…"
          >
            {(documents) => (
              <DataTable
                caption="Confirmed Certificate of Registration records"
                rowKey={(document) => document.id}
                rows={documents}
                columns={[
                  {
                    key: "student",
                    header: "Student",
                    render: (document) => (
                      <div className="grid gap-0.5">
                        <Button
                          type="button"
                          variant="link"
                          className="h-auto justify-start p-0 text-left"
                          onClick={() =>
                            openStudentHistory({
                              studentName:
                                document.student_name ??
                                document.student_number,
                              studentNumber: document.student_number,
                            })
                          }
                        >
                          {document.student_name ?? document.student_number}
                        </Button>
                        <span className="text-xs text-muted-foreground">
                          {document.student_number}
                        </span>
                      </div>
                    ),
                  },
                  {
                    key: "cor",
                    header: "COR number",
                    render: (document) => (
                      <span className="font-mono">
                        {document.document_number}
                      </span>
                    ),
                  },
                  {
                    key: "generated",
                    header: "Confirmed",
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
              onPageChange={setPage}
            />
          </div>
        </CardContent>
      </Card>

      <Dialog
        open={selectedStudent !== null}
        onOpenChange={(open) => {
          if (!open) {
            setSelectedDocumentId(null)
            setSelectedStudent(null)
          }
        }}
      >
        <DialogContent className="sm:max-w-4xl">
          <DialogHeader>
            <DialogTitle>
              {selectedStudent
                ? `${selectedStudent.studentName} — COR history`
                : "COR history"}
            </DialogTitle>
            <DialogDescription>
              {selectedStudent
                ? `Student number: ${selectedStudent.studentNumber}`
                : ""}
            </DialogDescription>
          </DialogHeader>
          <AsyncBoundary
            query={{ ...historyQuery, data: historyQuery.data?.data }}
            isEmpty={(rows) => rows.length === 0}
            emptyMessage="This student has no confirmed COR records."
            loadingLabel="Loading student COR history…"
          >
            {(documents) => (
              <DataTable
                caption="Student Certificate of Registration history"
                rowKey={(document) => document.id}
                rows={documents}
                columns={[
                  {
                    key: "cor",
                    header: "COR number",
                    render: (document) => (
                      <span className="font-mono">
                        {document.document_number}
                      </span>
                    ),
                  },
                  {
                    key: "confirmed",
                    header: "Confirmed",
                    render: (document) =>
                      new Date(document.generated_at).toLocaleString(),
                  },
                  {
                    key: "action",
                    header: "Action",
                    render: (document) => (
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setSelectedDocumentId(document.id)}
                      >
                        View COR
                      </Button>
                    ),
                  },
                ]}
              />
            )}
          </AsyncBoundary>
          <Paginator
            currentPage={historyQuery.data?.meta.current_page ?? 1}
            lastPage={historyQuery.data?.meta.last_page ?? 1}
            onPageChange={setHistoryPage}
          />
        </DialogContent>
      </Dialog>

      <Dialog
        open={selectedDocumentId !== null}
        onOpenChange={(open) => {
          if (!open) setSelectedDocumentId(null)
        }}
      >
        <DialogContent className="max-h-[90dvh] sm:max-w-6xl print:max-h-none print:w-full print:max-w-none print:p-0 print:border-none print:shadow-none print:overflow-visible">
          <DialogHeader className="pr-8 print:hidden">
            <DialogTitle>Certificate of Registration</DialogTitle>
            <DialogDescription>
              Review or print the selected official COR.
            </DialogDescription>
          </DialogHeader>
          <AsyncBoundary
            query={{ ...corQuery, data: corQuery.data }}
            isEmpty={(cor) => cor.snapshot === null}
            emptyMessage="This historic COR is being restored. Please try again shortly."
            loadingLabel="Loading official COR…"
          >
            {(cor) =>
              cor.snapshot !== null && (
                <PrintDocument
                  title={cor.document_number}
                  actions={
                    <DownloadPdfButton
                      documentId={cor.id}
                      studentName={cor.snapshot.student.name}
                      generatedAt={cor.generated_at}
                      label="Download COR"
                    />
                  }
                >
                  <CertificateOfRegistrationDocument
                    cor={{ ...cor, snapshot: cor.snapshot }}
                  />
                </PrintDocument>
              )
            }
          </AsyncBoundary>
        </DialogContent>
      </Dialog>
    </WorkspacePage>
  )
}
