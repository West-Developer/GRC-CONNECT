"use client"

import { Printer } from "lucide-react"

import { PrintDocument } from "@/features/components/portal/print-document"
import { TableFit } from "@/features/components/ui/table"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { usePrintDocument } from "@/features/hooks/use-print-document"
import type { YearOverYearCount } from "@/features/schemas/dashboard-schema"

interface YearOverYearReportDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  data: readonly YearOverYearCount[]
}

export function YearOverYearReportDialog({
  open,
  onOpenChange,
  data,
}: YearOverYearReportDialogProps) {
  const { print, isPrinting } = usePrintDocument()

  const totalEnrollments = data.reduce(
    (sum, item) => sum + item.enrollment_count,
    0,
  )

  const rowsWithStats = data.map((item, index) => {
    const prev = index > 0 ? data[index - 1] : null
    const diff = prev ? item.enrollment_count - prev.enrollment_count : 0
    const pctChange =
      prev && prev.enrollment_count > 0
        ? ((diff / prev.enrollment_count) * 100).toFixed(1)
        : null
    const share =
      totalEnrollments > 0
        ? ((item.enrollment_count / totalEnrollments) * 100).toFixed(1)
        : "0"

    return {
      ...item,
      diff,
      pctChange,
      share,
    }
  })

  const latestYear = data.length > 0 ? data[data.length - 1] : null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] w-[calc(100vw-2rem)] overflow-y-auto sm:max-w-4xl">
        <DialogHeader className="print:hidden">
          <div className="flex flex-wrap items-center justify-between gap-3 pr-6">
            <div>
              <DialogTitle>Year-over-Year Enrollment Report</DialogTitle>
              <DialogDescription>
                Comparative institutional enrollment volume and growth trends across school years.
              </DialogDescription>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => print()}
              disabled={isPrinting}
              className="gap-1.5"
            >
              <Printer className="size-4" aria-hidden="true" />
              <span>{isPrinting ? "Printing…" : "Print report"}</span>
            </Button>
          </div>
        </DialogHeader>

        <div className="py-2">
          <PrintDocument title="Year-over-Year Enrollment Report">
            <div className="space-y-6 rounded-lg border bg-card p-6 text-card-foreground">
              {/* Institution Official Header */}
              <div className="border-b pb-5 text-center">
                <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                  Republic of the Philippines
                </p>
                <h2 className="text-xl font-bold tracking-tight text-primary">
                  GLOBAL RECIPROCAL COLLEGES
                </h2>
                <p className="text-xs text-muted-foreground">
                  Office of the Executive Director · Institutional Research & Analytics
                </p>
                <h3 className="mt-3 text-base font-semibold tracking-wide uppercase text-foreground">
                  Official Year-over-Year Enrollment Report
                </h3>
                <p className="text-xs text-muted-foreground">
                  Generated on {new Date().toLocaleDateString("en-US", {
                    month: "long",
                    day: "numeric",
                    year: "numeric",
                  })}
                </p>
              </div>

              {/* Metric Highlights */}
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div className="rounded-lg border bg-muted/20 p-3 text-center">
                  <p className="text-xs text-muted-foreground">Total Enrollments</p>
                  <p className="text-xl font-bold tracking-tight text-foreground">
                    {totalEnrollments.toLocaleString()}
                  </p>
                </div>
                <div className="rounded-lg border bg-muted/20 p-3 text-center">
                  <p className="text-xs text-muted-foreground">Latest School Year</p>
                  <p className="text-xl font-bold tracking-tight text-primary">
                    {latestYear?.school_year ?? "—"}
                  </p>
                </div>
                <div className="col-span-2 rounded-lg border bg-muted/20 p-3 text-center sm:col-span-1">
                  <p className="text-xs text-muted-foreground">Latest Enrollment</p>
                  <p className="text-xl font-bold tracking-tight text-foreground">
                    {latestYear ? latestYear.enrollment_count.toLocaleString() : "—"}
                  </p>
                </div>
              </div>

              {/* Data Table */}
              <TableFit>
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="border-b bg-muted/40 text-xs font-semibold text-muted-foreground uppercase">
                      <th className="py-2.5 px-3">School Year</th>
                      <th className="py-2.5 px-3 text-right">Enrollments</th>
                      <th className="py-2.5 px-3 text-right">Annual Change</th>
                      <th className="py-2.5 px-3 text-right">Growth Rate</th>
                      <th className="py-2.5 px-3 text-right">Share of Total</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {rowsWithStats.map((row, idx) => (
                      <tr key={row.school_year} className="hover:bg-muted/10">
                        <td className="py-2.5 px-3 font-medium">
                          {row.school_year}
                          {idx === rowsWithStats.length - 1 && (
                            <Badge variant="secondary" className="ml-2 text-[0.65rem]">
                              Current
                            </Badge>
                          )}
                        </td>
                        <td className="py-2.5 px-3 text-right font-mono font-medium">
                          {row.enrollment_count.toLocaleString()}
                        </td>
                        <td className="py-2.5 px-3 text-right font-mono">
                          {idx === 0 ? (
                            <span className="text-muted-foreground">—</span>
                          ) : row.diff > 0 ? (
                            <span className="text-emerald-600 font-medium">
                              +{row.diff.toLocaleString()}
                            </span>
                          ) : row.diff < 0 ? (
                            <span className="text-destructive font-medium">
                              {row.diff.toLocaleString()}
                            </span>
                          ) : (
                            <span className="text-muted-foreground">0</span>
                          )}
                        </td>
                        <td className="py-2.5 px-3 text-right font-mono">
                          {row.pctChange === null ? (
                            <span className="text-muted-foreground">Baseline</span>
                          ) : Number(row.pctChange) > 0 ? (
                            <span className="text-emerald-600 font-medium">
                              +{row.pctChange}%
                            </span>
                          ) : Number(row.pctChange) < 0 ? (
                            <span className="text-destructive font-medium">
                              {row.pctChange}%
                            </span>
                          ) : (
                            <span className="text-muted-foreground">0.0%</span>
                          )}
                        </td>
                        <td className="py-2.5 px-3 text-right font-mono text-muted-foreground">
                          {row.share}%
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t-2 font-semibold text-foreground">
                      <td className="py-2.5 px-3">Total Historical</td>
                      <td className="py-2.5 px-3 text-right font-mono">
                        {totalEnrollments.toLocaleString()}
                      </td>
                      <td className="py-2.5 px-3 text-right font-mono">—</td>
                      <td className="py-2.5 px-3 text-right font-mono">—</td>
                      <td className="py-2.5 px-3 text-right font-mono">100.0%</td>
                    </tr>
                  </tfoot>
                </table>
              </TableFit>

              {/* Executive Sign-off Block */}
              <div className="mt-8 pt-8 border-t flex flex-wrap justify-between gap-6 text-xs text-muted-foreground">
                <div>
                  <p>Certified Correct:</p>
                  <div className="mt-8 border-t border-foreground/30 pt-1 w-48 text-center text-foreground font-semibold">
                    Executive Director
                  </div>
                  <p className="text-[0.7rem]">Global Reciprocal Colleges</p>
                </div>
                <div className="text-right">
                  <p>Document Ref: GRC-YOY-{new Date().getFullYear()}</p>
                  <p>Classification: Institutional Public Metric</p>
                </div>
              </div>
            </div>
          </PrintDocument>
        </div>
      </DialogContent>
    </Dialog>
  )
}

