"use client"

import { FileText, Trash2, Upload } from "lucide-react"
import { useRef, useState } from "react"

import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataUseNotice } from "@/features/components/privacy/data-use-notice"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  useDeleteTorDocumentMutation,
  useTorDocumentsQuery,
  useUploadTorDocumentMutation,
} from "@/features/hooks/use-tor-documents"
import {
  TOR_ACCEPTED_TYPES,
  TOR_MAX_BYTES,
  type TorDocument,
} from "@/features/schemas/tor-document-schema"
import { isApiClientError } from "@/features/services/api-client"
import { openTorDocument } from "@/features/services/tor-document-service"

const uploadedOn = new Intl.DateTimeFormat("en-PH", {
  dateStyle: "medium",
  timeZone: "Asia/Manila",
})

function formatSize(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${Math.max(1, Math.round(bytes / 1024))} KB`
}

/** Why an upload or a view failed, in words for the person using the page. */
function problemMessage(error: unknown, fallback: string): string {
  if (isApiClientError(error)) {
    const first = Object.values(error.fieldErrors ?? {})[0]?.[0]
    if (first) return first
  }
  return fallback
}

/** Opens a TOR in a new tab; the failure, if any, is reported through `onError`. */
function ViewButton({
  document,
  onError,
}: {
  document: TorDocument
  onError: (message: string) => void
}) {
  const [opening, setOpening] = useState(false)

  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      disabled={opening}
      aria-label={`View ${document.original_name}`}
      onClick={() => {
        setOpening(true)
        openTorDocument(document)
          .catch(() => onError("The TOR file could not be opened. Try again."))
          .finally(() => setOpening(false))
      }}
    >
      {opening ? "Opening…" : "View"}
    </Button>
  )
}

function FileRow({
  document,
  onError,
  onRemove,
  removing,
}: {
  document: TorDocument
  onError: (message: string) => void
  onRemove?: (document: TorDocument) => void
  removing?: boolean
}) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2.5 text-xs">
      <span className="flex min-w-0 items-center gap-2">
        <FileText className="size-4 shrink-0 text-primary" aria-hidden />
        <span className="grid min-w-0 gap-0.5">
          <span className="truncate font-medium text-foreground">
            {document.original_name}
          </span>
          <span className="text-muted-foreground">
            {formatSize(document.size_bytes)}
            {document.uploaded_at
              ? ` · uploaded ${uploadedOn.format(new Date(document.uploaded_at))}`
              : ""}
          </span>
        </span>
      </span>
      <span className="flex shrink-0 gap-2">
        <ViewButton document={document} onError={onError} />
        {onRemove && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={removing}
            aria-label={`Remove ${document.original_name}`}
            onClick={() => onRemove(document)}
          >
            <Trash2 className="size-3.5" aria-hidden />
            Remove
          </Button>
        )}
      </span>
    </li>
  )
}

/**
 * The student uploads their Transcript of Records (a PDF, JPG or PNG) so the
 * Program Head can read it while mapping the subjects they took elsewhere.
 * Mapping still happens one subject at a time (the form below it); the TOR is
 * the evidence the Program Head checks them against.
 */
export function StudentTorUpload({ enabled = true }: { enabled?: boolean }) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [error, setError] = useState("")
  const [success, setSuccess] = useState("")

  const filesQuery = useTorDocumentsQuery(
    { page: 1, per_page: 20 },
    { enabled },
  )
  const uploadMutation = useUploadTorDocumentMutation()
  const deleteMutation = useDeleteTorDocumentMutation()

  const handleFile = async (file: File | undefined) => {
    setError("")
    setSuccess("")
    if (!file) return
    if (!TOR_ACCEPTED_TYPES.includes(file.type)) {
      setError("Upload the TOR as a PDF, JPG or PNG file.")
      return
    }
    if (file.size > TOR_MAX_BYTES) {
      setError("The file is too large. Upload a TOR of 8 MB or less.")
      return
    }

    try {
      await uploadMutation.mutateAsync(file)
      setSuccess(
        "TOR uploaded. Your Program Head can now read it and will map your subjects.",
      )
    } catch (uploadError) {
      setError(
        problemMessage(
          uploadError,
          "The TOR could not be uploaded. Check the connection and try again.",
        ),
      )
    } finally {
      // Let the same file be chosen again after a failure.
      if (inputRef.current) inputRef.current.value = ""
    }
  }

  const remove = async (document: TorDocument) => {
    setError("")
    setSuccess("")
    try {
      await deleteMutation.mutateAsync(document.id)
    } catch (removeError) {
      setError(
        problemMessage(removeError, "The file could not be removed. Try again."),
      )
    }
  }

  return (
    <section
      aria-labelledby="tor-upload-heading"
      className="grid gap-3 rounded-lg border bg-muted/30 p-3"
    >
      <div className="grid gap-1">
        <h4 id="tor-upload-heading" className="text-sm font-semibold">
          Your Transcript of Records (TOR)
        </h4>
        <p className="text-xs text-muted-foreground">
          Upload a clear copy of your TOR (PDF, JPG or PNG, up to 8 MB). Your
          Program Head reads it to decide which of your subjects can be credited
          before you enroll.
        </p>
      </div>

      <DataUseNotice notice="tor-upload" />

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      {success && (
        <Alert>
          <AlertDescription>{success}</AlertDescription>
        </Alert>
      )}

      <div>
        <input
          ref={inputRef}
          id="tor-file-input"
          type="file"
          className="sr-only"
          accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
          aria-label="Choose a TOR file"
          disabled={uploadMutation.isPending}
          onChange={(event) => void handleFile(event.target.files?.[0])}
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={uploadMutation.isPending}
          onClick={() => inputRef.current?.click()}
        >
          <Upload className="mr-1.5 size-4" aria-hidden />
          {uploadMutation.isPending ? "Uploading…" : "Upload TOR"}
        </Button>
      </div>

      <AsyncBoundary
        query={filesQuery}
        isEmpty={(page) => page.data.length === 0}
        emptyMessage="You have not uploaded a TOR yet."
        loadingLabel="Loading your uploaded TOR files…"
      >
        {(page) => (
          <ul className="grid gap-2" aria-label="Your uploaded TOR files">
            {page.data.map((document) => (
              <FileRow
                key={document.id}
                document={document}
                onError={setError}
                onRemove={(item) => void remove(item)}
                removing={deleteMutation.isPending}
              />
            ))}
          </ul>
        )}
      </AsyncBoundary>
    </section>
  )
}

/**
 * One student's uploaded TOR files, read-only (the Program Head's view).
 * `headingLevel` is the level of its "Uploaded TOR" heading: 4 under a form card's
 * own subheading, 3 when it sits directly under a card title (no skipped level).
 */
export function StudentTorFiles({
  studentId,
  headingLevel = 4,
}: {
  studentId: number
  headingLevel?: 3 | 4
}) {
  const [error, setError] = useState("")
  const filesQuery = useTorDocumentsQuery({
    student_id: studentId,
    page: 1,
    per_page: 20,
  })
  const Heading = headingLevel === 3 ? "h3" : "h4"

  return (
    <div className="grid gap-2">
      <Heading className="text-sm font-semibold">Uploaded TOR</Heading>
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      <AsyncBoundary
        query={filesQuery}
        isEmpty={(page) => page.data.length === 0}
        emptyMessage="This student has not uploaded a TOR."
        loadingLabel="Loading the student's TOR files…"
      >
        {(page) => (
          <ul className="grid gap-2" aria-label="Uploaded TOR files">
            {page.data.map((document) => (
              <FileRow key={document.id} document={document} onError={setError} />
            ))}
          </ul>
        )}
      </AsyncBoundary>
    </div>
  )
}

export interface TorStudent {
  student_id: number
  student_number: string
  student_name: string
}

/**
 * The Program Head's list of students who uploaded a TOR, newest first, each
 * with their files and a shortcut to record the subjects they took elsewhere.
 */
export function TorUploadsCard({
  onRecordCredit,
}: {
  onRecordCredit: (student: TorStudent) => void
}) {
  const [error, setError] = useState("")
  const query = useTorDocumentsQuery({ page: 1, per_page: 50 })

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>Transcripts of Records</CardTitle>
        <CardDescription>
          TORs uploaded by students in your college. Read one, then record the
          subjects the student took elsewhere so you can map them.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        <AsyncBoundary
          query={query}
          isEmpty={(page) => page.data.length === 0}
          emptyMessage="No student has uploaded a TOR yet."
          loadingLabel="Loading uploaded TORs…"
        >
          {(page) => {
            const byStudent = new Map<
              number,
              { student: TorStudent; files: TorDocument[] }
            >()
            for (const document of page.data) {
              const entry = byStudent.get(document.student_id) ?? {
                student: {
                  student_id: document.student_id,
                  student_number: document.student_number,
                  student_name: document.student_name,
                },
                files: [],
              }
              entry.files.push(document)
              byStudent.set(document.student_id, entry)
            }

            return (
              <ul className="grid gap-3" aria-label="Students with an uploaded TOR">
                {[...byStudent.values()].map(({ student, files }) => (
                  <li
                    key={student.student_id}
                    className="grid gap-2 rounded-lg border p-3"
                  >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="text-sm">
                        <span className="font-medium">{student.student_name}</span>{" "}
                        <span className="font-mono text-xs">
                          {student.student_number}
                        </span>
                      </span>
                      <Button
                        type="button"
                        size="sm"
                        onClick={() => onRecordCredit(student)}
                      >
                        Record credits for {student.student_name}
                      </Button>
                    </div>
                    <ul className="grid gap-2">
                      {files.map((document) => (
                        <FileRow
                          key={document.id}
                          document={document}
                          onError={setError}
                        />
                      ))}
                    </ul>
                  </li>
                ))}
              </ul>
            )
          }}
        </AsyncBoundary>
      </CardContent>
    </Card>
  )
}
