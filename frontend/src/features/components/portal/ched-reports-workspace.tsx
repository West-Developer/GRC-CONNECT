"use client"

import { useState } from "react"
import { Download, FileSpreadsheet } from "lucide-react"
import { toast } from "sonner"

import { useAuth } from "@/features/auth/use-auth"
import { AcademicTermSelector } from "@/features/components/portal/academic-term-selector"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { ChedStudentDetailsDialog } from "@/features/components/portal/ched-student-details-dialog"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Input } from "@/features/components/ui/input"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"
import { useAcademicTermSelection } from "@/features/hooks/use-academic-term-selection"
import {
  useChedListQuery,
  useChedSummaryQuery,
} from "@/features/hooks/use-ched-reports"
import { useDebouncedValue } from "@/features/hooks/use-debounced-value"
import {
  chedReportTitle,
  chedStudentName,
  chedTabLabel,
  nstpSubjectOf,
} from "@/features/lib/ched-report-display"
import {
  chedReportKeys,
  chedReportKeySchema,
  type ChedDownloadFormat,
  type ChedNstpComponents,
  type ChedReportKey,
  type ChedReportRow,
} from "@/features/schemas/ched-report-schema"
import type { AcademicTerm } from "@/features/schemas/reference-data-schema"
import {
  chedReportFileName,
  downloadChedReport,
} from "@/features/services/ched-report-service"

const PER_PAGE = 25

type ListReportKey = Exclude<ChedReportKey, "nstp-summary">

/**
 * What the on-screen table shows for each list: just enough to find a student, in columns that fit
 * the page without scrolling sideways. Every field of the file is in the student's details dialog.
 * `@name` is the student's name written out from the name columns.
 */
const NAME_COLUMN = "@name"
const TABLE_COLUMNS: Record<ListReportKey, readonly string[]> = {
  "enrollment-list": [
    "no",
    "student_number",
    NAME_COLUMN,
    "program",
    "year_level",
    "gender",
    "units",
  ],
  "nstp-enrollment": [
    "no",
    NAME_COLUMN,
    "course",
    "gender",
    "birthdate",
    "contact_number",
  ],
  "nstp-graduates": [
    "no",
    NAME_COLUMN,
    "course",
    "gender",
    "birthdate",
    "contact_number",
  ],
}

/** What each report is, in words, for the semester of the chosen term. */
function describeReport(key: ChedReportKey, semester: string | null): string {
  switch (key) {
    case "enrollment-list":
      return "Every student officially enrolled in the term, in the CHED NCR template: program, major, name, citizenship, gender, year level, subjects with units."
    case "nstp-enrollment":
      return `Students officially enrolled in ${nstpSubjectOf(semester)} in the term (CWTS), with their birth date, address and contact details.`
    case "nstp-graduates":
      return "Students who passed NSTP 2 in the term (a locked, passing grade), in the same layout."
    case "nstp-summary":
      return "Enrollment and graduates of the semester you picked, by component and gender. Only that semester has numbers; the other semesters, ROTC and LTS are 0. Only CWTS is offered."
  }
}

/** The tabs: NSTP 1 or NSTP 2 by the semester of the chosen term (NSTP 1 is taken in the 1st, NSTP 2 in the 2nd). */
function reportsFor(semester: string | null) {
  return chedReportKeys.map((key) => ({
    key,
    tab: chedTabLabel(key, semester),
    title: chedReportTitle(key, semester),
    description: describeReport(key, semester),
  }))
}

function plural(count: number, one: string, many: string): string {
  return count === 1 ? one : many
}

/** The template's own file name, and the two ways to get it. */
function DownloadButtons({
  report,
  title,
  term,
}: {
  report: ChedReportKey
  title: string
  term: AcademicTerm
}) {
  const [busy, setBusy] = useState<ChedDownloadFormat | null>(null)

  const download = async (format: ChedDownloadFormat) => {
    setBusy(format)
    try {
      await downloadChedReport({
        report,
        academicTermId: term.id,
        format,
        fileName: chedReportFileName(report, term, format),
      })
    } catch {
      toast.error(
        `The ${title} ${format === "csv" ? "CSV" : "Excel"} file could not be downloaded. Try again.`,
      )
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="flex flex-wrap gap-2">
      <Button
        type="button"
        variant="outline"
        disabled={busy === "csv"}
        onClick={() => void download("csv")}
      >
        <Download className="mr-1.5 size-4" aria-hidden="true" />
        {busy === "csv" ? "Preparing…" : "Download CSV"}
      </Button>
      <Button
        type="button"
        disabled={busy === "xlsx"}
        onClick={() => void download("xlsx")}
      >
        <FileSpreadsheet className="mr-1.5 size-4" aria-hidden="true" />
        {busy === "xlsx" ? "Preparing…" : "Download Excel"}
      </Button>
    </div>
  )
}

/**
 * The one warning the Registrar Head still needs on the preview: students with no gender on file. The note
 * about generated (demo) details was removed at the owner's request (2026-10-15); they know the details are
 * not real.
 */
function ReportNotices({ missingGender }: { missingGender: number }) {
  if (missingGender === 0) return null

  return (
    <Alert>
      <AlertTitle>Gender missing</AlertTitle>
      <AlertDescription>
        {`${missingGender} listed ${plural(missingGender, "student has", "students have")} no gender on file, so their Gender cell is blank in the file.`}
      </AlertDescription>
    </Alert>
  )
}

function ListReportPanel({
  report,
  title,
  description,
  term,
}: {
  report: ListReportKey
  title: string
  description: string
  term: AcademicTerm
}) {
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<ChedReportRow | null>(null)
  const [search, setSearch] = useState("")
  // Ask the server 300 ms after typing stops, not on every keystroke.
  const searched = useDebouncedValue(search, 300).trim()
  const query = useChedListQuery(
    report,
    term.id,
    page,
    PER_PAGE,
    searched,
    true,
  )

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="grid gap-1">
          <CardTitle className="text-base font-semibold">{title}</CardTitle>
          <CardDescription>{description}</CardDescription>
        </div>
        <DownloadButtons report={report} title={title} term={term} />
      </CardHeader>
      <CardContent className="grid gap-4">
        <div className="grid gap-1">
          <Input
            type="search"
            aria-label="Search students"
            aria-describedby={`${report}-search-hint`}
            placeholder="Search by name or student number…"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            className="w-full sm:w-96"
          />
          <p
            id={`${report}-search-hint`}
            className="text-xs text-muted-foreground"
          >
            The download always has every student of the term, not only the
            search results.
          </p>
        </div>
        <AsyncBoundary query={query} loadingLabel={`Loading the ${title}…`}>
          {(response) => {
            const { meta } = response
            const columns = TABLE_COLUMNS[report].flatMap((key) => {
              if (key === NAME_COLUMN) return [{ key, label: "Name" }]
              const column = response.report.columns.find(
                (candidate) => candidate.key === key,
              )
              return column === undefined ? [] : [column]
            })

            return (
              <>
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-medium">
                    {response.report.term_label}
                  </span>
                  <Badge variant="secondary">
                    {`${meta.total} ${plural(meta.total, "student", "students")}`}
                  </Badge>
                </div>
                <ReportNotices missingGender={response.report.missing_gender} />
                <p className="text-xs text-muted-foreground">
                  Click a student to see every field that goes into the file.
                </p>
                <Table aria-label={`${title}, page ${meta.current_page}`}>
                  <TableHeader>
                    <TableRow>
                      {columns.map((column) => (
                        <TableHead key={column.key} scope="col">
                          {column.label}
                        </TableHead>
                      ))}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {response.data.length === 0 ? (
                      <TableRow>
                        <TableCell
                          colSpan={Math.max(1, columns.length)}
                          className="py-8 text-center text-muted-foreground"
                        >
                          {searched === ""
                            ? "No students are listed for this term."
                            : `No student matches “${searched}”.`}
                        </TableCell>
                      </TableRow>
                    ) : (
                      response.data.map((row, index) => {
                        const name = chedStudentName(row)

                        return (
                          <TableRow
                            key={`${meta.current_page}-${index}`}
                            className="cursor-pointer"
                            onClick={() => setSelected(row)}
                          >
                            {columns.map((column) => {
                              if (column.key === NAME_COLUMN) {
                                return (
                                  <TableCell key={column.key}>
                                    <button
                                      type="button"
                                      aria-label={`View details of ${name}`}
                                      className="rounded-sm text-left font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                      {name}
                                    </button>
                                  </TableCell>
                                )
                              }

                              const value = row[column.key]
                              return (
                                <TableCell
                                  key={column.key}
                                  className="whitespace-normal"
                                >
                                  {value === undefined || value === ""
                                    ? "—"
                                    : String(value)}
                                </TableCell>
                              )
                            })}
                          </TableRow>
                        )
                      })
                    )}
                  </TableBody>
                </Table>
                <ChedStudentDetailsDialog
                  row={selected}
                  report={response.report}
                  onClose={() => setSelected(null)}
                />
                {meta.last_page > 1 && (
                  <div className="flex items-center justify-end gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={meta.current_page <= 1}
                      onClick={() =>
                        setPage((current) => Math.max(1, current - 1))
                      }
                    >
                      Previous
                    </Button>
                    <span className="px-2 text-xs text-muted-foreground">
                      {`Page ${meta.current_page} of ${meta.last_page}`}
                    </span>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={meta.current_page >= meta.last_page}
                      onClick={() => setPage((current) => current + 1)}
                    >
                      Next
                    </Button>
                  </div>
                )}
              </>
            )
          }}
        </AsyncBoundary>
      </CardContent>
    </Card>
  )
}

const COMPONENTS = [
  ["rotc", "ROTC"],
  ["cwts", "CWTS"],
  ["lts", "LTS"],
] as const

/** The 12 male / female cells of one block (a semester or the graduates): ROTC, CWTS, LTS. */
function countCells(block: ChedNstpComponents, keyPrefix: string) {
  return COMPONENTS.flatMap(([key]) => [
    <TableCell
      key={`${keyPrefix}-${key}-m`}
      className="px-0.5 text-center tabular-nums"
    >
      {block[key].m}
    </TableCell>,
    <TableCell
      key={`${keyPrefix}-${key}-f`}
      className="px-0.5 text-center tabular-nums"
    >
      {block[key].f}
    </TableCell>,
  ])
}

function SummaryPanel({
  title,
  description,
  term,
}: {
  title: string
  description: string
  term: AcademicTerm
}) {
  const query = useChedSummaryQuery(term.id, true)

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="grid gap-1">
          <CardTitle className="text-base font-semibold">{title}</CardTitle>
          <CardDescription>{description}</CardDescription>
        </div>
        <DownloadButtons report="nstp-summary" title={title} term={term} />
      </CardHeader>
      <CardContent className="grid gap-4">
        <AsyncBoundary query={query} loadingLabel={`Loading the ${title}…`}>
          {({ data }) => {
            const blocks = [
              data.enrollment["1st"],
              data.enrollment["2nd"],
              data.enrollment.summer,
              data.graduates,
            ]
            return (
              <>
                <p className="text-sm font-medium">
                  {`Academic Year ${data.academic_year} · ${data.semester} Semester · Region: National Capital Region`}
                </p>
                {data.missing_gender > 0 && (
                  <Alert>
                    <AlertTitle>Gender missing</AlertTitle>
                    <AlertDescription>
                      {`${data.missing_gender} ${plural(data.missing_gender, "student has", "students have")} no gender on file and ${plural(data.missing_gender, "is", "are")} counted in neither the M nor the F column.`}
                    </AlertDescription>
                  </Alert>
                )}
                <Table
                  className="table-fixed text-xs"
                  aria-label="NSTP Form 2-A summary of enrollment and graduates, by semester, component and gender"
                >
                  <colgroup>
                    <col style={{ width: "22%" }} />
                    <col style={{ width: "9%" }} />
                    {Array.from({ length: 24 }, (_, index) => (
                      <col key={index} style={{ width: `${69 / 24}%` }} />
                    ))}
                  </colgroup>
                  <TableHeader>
                    <TableRow>
                      <TableHead
                        rowSpan={4}
                        scope="col"
                        className="px-1 whitespace-normal"
                      >
                        Name of HEI/Campus
                      </TableHead>
                      <TableHead
                        rowSpan={4}
                        scope="col"
                        className="px-1 whitespace-normal"
                      >
                        Classification (Private/Public)
                      </TableHead>
                      <TableHead
                        colSpan={18}
                        scope="colgroup"
                        className="px-0.5 text-center"
                      >
                        Enrollment
                      </TableHead>
                      <TableHead
                        colSpan={6}
                        rowSpan={2}
                        scope="colgroup"
                        className="px-0.5 text-center"
                      >
                        Graduates
                      </TableHead>
                    </TableRow>
                    <TableRow>
                      {["1st Sem", "2nd Sem", "Summer"].map((label) => (
                        <TableHead
                          key={label}
                          colSpan={6}
                          scope="colgroup"
                          className="px-0.5 text-center"
                        >
                          {label}
                        </TableHead>
                      ))}
                    </TableRow>
                    <TableRow>
                      {blocks.flatMap((_, blockIndex) =>
                        COMPONENTS.map(([key, label]) => (
                          <TableHead
                            key={`${blockIndex}-${key}`}
                            colSpan={2}
                            scope="colgroup"
                            className="px-0.5 text-center"
                          >
                            {label}
                          </TableHead>
                        )),
                      )}
                    </TableRow>
                    <TableRow>
                      {blocks.flatMap((_, blockIndex) =>
                        COMPONENTS.flatMap(([key]) => [
                          <TableHead
                            key={`${blockIndex}-${key}-m`}
                            scope="col"
                            className="px-0.5 text-center"
                          >
                            M
                          </TableHead>,
                          <TableHead
                            key={`${blockIndex}-${key}-f`}
                            scope="col"
                            className="px-0.5 text-center"
                          >
                            F
                          </TableHead>,
                        ]),
                      )}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    <TableRow>
                      <TableCell className="px-1 font-medium whitespace-normal">
                        {data.campus}
                      </TableCell>
                      <TableCell className="px-1 whitespace-normal">
                        {data.classification}
                      </TableCell>
                      {blocks.flatMap((block, index) =>
                        countCells(block, `school-${index}`),
                      )}
                    </TableRow>
                    <TableRow className="font-semibold">
                      <TableCell className="px-1">TOTAL</TableCell>
                      <TableCell>1</TableCell>
                      {blocks.flatMap((block, index) =>
                        countCells(block, `total-${index}`),
                      )}
                    </TableRow>
                  </TableBody>
                </Table>
              </>
            )
          }}
        </AsyncBoundary>
      </CardContent>
    </Card>
  )
}

/**
 * The Registrar Head's CHED reports (ADR 0049): the CHED Enrollment List and NSTP Forms 2-B and 2-A
 * for a term, each previewed on screen and downloaded as CSV or as CHED's own template filled in.
 */
export function ChedReportsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "registrar_head"
  const { term, sortedTerms, isCurrentTerm, setSelectedTermId, termsQuery } =
    useAcademicTermSelection()
  const [active, setActive] = useState<ChedReportKey>("enrollment-list")
  const reports = reportsFor(term?.semester ?? null)

  return (
    <WorkspacePage
      title="CHED Reports"
      description="Preview the CHED Enrollment List and the NSTP forms for a term, and download each as CSV or as CHED's own Excel template, ready to submit."
      unauthorized={!authorized}
    >
      <div className="grid gap-5">
        <AcademicTermSelector
          sortedTerms={sortedTerms}
          term={term}
          isCurrentTerm={isCurrentTerm}
          onSelectTerm={setSelectedTermId}
          isLoading={termsQuery.isPending}
          readOnly
        />

        <Tabs
          value={active}
          onValueChange={(value) => {
            const parsed = chedReportKeySchema.safeParse(value)
            if (parsed.success) setActive(parsed.data)
          }}
        >
          <TabsList aria-label="CHED report">
            {reports.map((report) => (
              <TabsTrigger key={report.key} value={report.key}>
                {report.tab}
              </TabsTrigger>
            ))}
          </TabsList>
          {reports.map((report) => (
            <TabsContent key={report.key} value={report.key}>
              {term === null ? (
                <p className="text-sm text-muted-foreground">
                  Choose a school year and semester to see this report.
                </p>
              ) : report.key === "nstp-summary" ? (
                <SummaryPanel
                  key={term.id}
                  title={report.title}
                  description={report.description}
                  term={term}
                />
              ) : (
                <ListReportPanel
                  key={`${report.key}-${term.id}`}
                  report={report.key}
                  title={report.title}
                  description={report.description}
                  term={term}
                />
              )}
            </TabsContent>
          ))}
        </Tabs>
      </div>
    </WorkspacePage>
  )
}
