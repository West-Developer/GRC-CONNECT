"use client"

import Link from "next/link"
import { useState } from "react"
import { FileCheck2 } from "lucide-react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { CertificateOfRegistrationDocument } from "@/features/components/portal/certificate-of-registration-document"
import {
  DownloadPdfButton,
  PrintButton,
  PrintDocument,
} from "@/features/components/portal/print-document"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Button } from "@/features/components/ui/button"
import {
  useCertificateOfRegistrationQuery,
  useEnrollmentDocumentsQuery,
} from "@/features/hooks/use-enrollment-documents"
import { formatGeneratedAt } from "@/features/lib/format-generated-at"

/**
 * The student's own Certificate of Registration: the bill only. Payments and the remaining balance
 * belong to the Statement of Account.
 */
export function StudentDigitalComWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "student"
  const [selectedDocumentId, setSelectedDocumentId] = useState<number | null>(null)
  const documentsQuery = useEnrollmentDocumentsQuery(
    { page: 1, per_page: 20 },
    { enabled: authorized },
  )
  const corQuery = useCertificateOfRegistrationQuery(selectedDocumentId, {
    enabled: authorized && selectedDocumentId !== null,
  })

  return (
    <WorkspacePage
      title="Certificate of Registration"
      description="View and download your official Certificate of Registration."
      unauthorized={!authorized}
      lastUpdated={documentsQuery.dataUpdatedAt}
    >
      <p className="text-sm text-muted-foreground print:hidden">
        Your bill, payments and remaining balance are in your{" "}
        <Link
          href="/portal/statement-of-account"
          className="font-medium text-primary underline underline-offset-4"
        >
          Statement of Account
        </Link>
        .
      </p>

      <AsyncBoundary
        query={{ ...documentsQuery, data: documentsQuery.data?.data }}
        isEmpty={(documents) => documents.length === 0}
        emptyMessage="No Certificate of Registration has been generated yet."
        loadingLabel="Loading your Certificate of Registration…"
      >
        {(documents) => (
          <Card className={selectedDocumentId !== null ? "print:hidden" : "print:shadow-none"}>
            <CardHeader className="flex items-center justify-between gap-2">
              <CardTitle level={2} className="flex items-center gap-2">
                <FileCheck2 aria-hidden="true" className="size-5" />
                Certificate of Registration
              </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3">
              {documents.map((document) => (
                <div
                  key={document.id}
                  className="grid gap-1 rounded-lg border p-4"
                >
                  <p className="font-medium">{document.document_type_label}</p>
                  <p className="font-mono text-sm text-muted-foreground">
                    {document.document_number}
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Generated {formatGeneratedAt(document.generated_at)}
                  </p>
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => setSelectedDocumentId(document.id)}
                    >
                      View COR
                    </Button>
                    <DownloadPdfButton
                      documentId={document.id}
                      studentName={session?.displayName ?? document.document_number}
                      generatedAt={document.generated_at}
                      label="Download COR"
                    />
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        )}
      </AsyncBoundary>

      {selectedDocumentId !== null && (
        <AsyncBoundary
          query={{ ...corQuery, data: corQuery.data }}
          isEmpty={(cor) => cor.snapshot === null}
          emptyMessage="This COR is being restored. Please try again shortly."
          loadingLabel="Loading your official COR…"
        >
          {(cor) =>
            cor.snapshot !== null && (
              <PrintDocument
                title={cor.document_number}
                actions={
                  <div className="flex items-center gap-2">
                    <PrintButton label="Print COR" />
                    <DownloadPdfButton
                      documentId={cor.id}
                      studentName={cor.snapshot.student.name}
                      generatedAt={cor.generated_at}
                      label="Download COR (PDF)"
                    />
                  </div>
                }
              >
                <CertificateOfRegistrationDocument
                  cor={{ ...cor, snapshot: cor.snapshot }}
                />
              </PrintDocument>
            )
          }
        </AsyncBoundary>
      )}
    </WorkspacePage>
  )
}
