import { useState, type ReactNode } from "react"
import { Download, Printer } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/features/components/ui/button"
import {
  findPrintRegion,
  usePrintDocument,
} from "@/features/hooks/use-print-document"
import { downloadEnrollmentDocumentPdf } from "@/features/services/enrollment-document-service"
import { cn } from "@/features/lib/utils"

/**
 * Wraps printable content in the `[data-print-region]`/`.print-document`
 * hooks `globals.css`'s `@media print` rules key on. `actions` (e.g. a
 * `PrintButton` and `DownloadPdfButton`) renders above the document and is hidden from print via
 * `print:hidden` — same convention as everywhere else in the portal.
 *
 * `PrintButton` prints only this region, in an isolated iframe (`printRegion`), so the page
 * around it never reaches the paper.
 */
export function PrintDocument({
  title,
  actions,
  children,
  className,
}: {
  title: string
  actions?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <div data-print-scope className="grid min-w-0 gap-3">
      {actions && (
        <div className="flex flex-wrap items-center justify-between gap-2 print:hidden">
          <h2 className="text-lg font-semibold">{title}</h2>
          <div className="flex items-center gap-2">{actions}</div>
        </div>
      )}
      <div
        data-print-region
        data-print-title={title}
        className={cn("print-document min-w-0 overflow-x-auto", className)}
      >
        {children}
      </div>
    </div>
  )
}

export function PrintButton({
  label = "Print",
}: {
  label?: string
}) {
  const { print, isPrinting } = usePrintDocument()

  return (
    <Button
      type="button"
      variant="outline"
      onClick={(event) => print(findPrintRegion(event.currentTarget))}
      disabled={isPrinting}
    >
      <Printer className="mr-1.5 size-4" />
      {label}
    </Button>
  )
}

export function DownloadPdfButton({
  documentId,
  studentName,
  generatedAt,
  label = "Download PDF",
}: {
  documentId: number
  studentName: string
  generatedAt: string
  label?: string
}) {
  const [downloading, setDownloading] = useState(false)

  const handleDownload = async () => {
    try {
      setDownloading(true)
      await downloadEnrollmentDocumentPdf(documentId, studentName, generatedAt)
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : "Failed to download Certificate of Registration."
      toast.error(message)
    } finally {
      setDownloading(false)
    }
  }

  return (
    <Button
      type="button"
      variant="outline"
      onClick={handleDownload}
      disabled={downloading}
    >
      <Download className="mr-1.5 size-4" />
      {downloading ? "Downloading…" : label}
    </Button>
  )
}
