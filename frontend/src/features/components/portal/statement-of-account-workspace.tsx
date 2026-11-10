"use client"

import { useState } from "react"
import { Download, Search } from "lucide-react"
import { toast } from "sonner"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { StatementOfAccountView } from "@/features/components/portal/statement-of-account-view"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
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
import { useCashierStudentSearchQuery } from "@/features/hooks/use-cashier-transactions"
import { useStatementOfAccountQuery } from "@/features/hooks/use-statement-of-account"
import { formatYearLevel } from "@/features/lib/format-year-level"
import { downloadStatementOfAccountPdf } from "@/features/services/statement-of-account-service"

// The backend rejects a shorter search (a single letter would match everyone).
const MIN_SEARCH_LENGTH = 2
const ALL_TERMS = "all"

/**
 * One student's Statement of Account with a term filter and a PDF download.
 * `studentId` null is the signed-in Student's own; a number is the Student an
 * Accounting Staff member looked up.
 */
function StatementPanel({ studentId }: { studentId: number | null }) {
  const [termChoice, setTermChoice] = useState<string>(ALL_TERMS)
  const [downloading, setDownloading] = useState(false)
  const termId = termChoice === ALL_TERMS ? null : Number(termChoice)

  // The unfiltered statement supplies the term options; it shares its cache
  // entry with the "All terms" view.
  const everything = useStatementOfAccountQuery(studentId, null)
  const filtered = useStatementOfAccountQuery(studentId, termId)
  const options = everything.data?.terms ?? []

  const download = async (studentNumber: string) => {
    setDownloading(true)
    try {
      await downloadStatementOfAccountPdf(studentId, termId, studentNumber)
    } catch {
      toast.error("The Statement of Account PDF could not be downloaded.")
    } finally {
      setDownloading(false)
    }
  }

  return (
    <AsyncBoundary
      query={filtered}
      loadingLabel="Loading the Statement of Account…"
    >
      {(statement) => (
        <div className="grid gap-5">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <p className="font-semibold">{statement.student.name}</p>
              <p className="text-sm text-muted-foreground">
                {statement.student.student_number} ·{" "}
                {statement.student.program_code}
              </p>
            </div>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
              <Field className="w-full sm:w-48">
                <FieldLabel htmlFor="soa-term-filter">Term</FieldLabel>
                <select
                  id="soa-term-filter"
                  value={termChoice}
                  onChange={(event) => setTermChoice(event.target.value)}
                  className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                >
                  <option value={ALL_TERMS}>All terms</option>
                  {options.map((term) => (
                    <option
                      key={term.academic_term_id}
                      value={String(term.academic_term_id)}
                    >
                      {term.label}
                    </option>
                  ))}
                </select>
              </Field>
              <Button
                type="button"
                variant="outline"
                disabled={downloading}
                onClick={() => void download(statement.student.student_number)}
              >
                <Download className="mr-1.5 size-4" />
                {downloading ? "Preparing PDF…" : "Download PDF"}
              </Button>
            </div>
          </div>
          <StatementOfAccountView statement={statement} />
        </div>
      )}
    </AsyncBoundary>
  )
}

function AccountingStatementLookup() {
  const [input, setInput] = useState("")
  const [activeQuery, setActiveQuery] = useState<string | null>(null)
  const [selectedStudentId, setSelectedStudentId] = useState<number | null>(
    null,
  )

  const search = useCashierStudentSearchQuery(activeQuery, {
    enabled: activeQuery !== null,
  })
  const matches = search.data ?? []
  // One match opens straight away; several are listed to choose from.
  const student =
    matches.length === 1
      ? matches[0]
      : (matches.find((match) => match.student_id === selectedStudentId) ??
        null)

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    const trimmed = input.trim()
    if (trimmed.length < MIN_SEARCH_LENGTH) return
    setSelectedStudentId(null)
    if (trimmed === activeQuery) void search.refetch()
    else setActiveQuery(trimmed)
  }

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle level={2}>Find student</CardTitle>
          <CardDescription>
            Search by student number, name, or email to open the student&apos;s
            Statement of Account.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
            <Field className="min-w-64 flex-1">
              <FieldLabel htmlFor="soa-search-student">
                Student number, name, or email
              </FieldLabel>
              <Input
                id="soa-search-student"
                value={input}
                onChange={(event) => setInput(event.target.value)}
                placeholder="e.g. 2026-0001, student name, or email"
              />
            </Field>
            <Button
              type="submit"
              disabled={input.trim().length < MIN_SEARCH_LENGTH}
            >
              <Search className="mr-1.5 size-4" />
              Find student
            </Button>
          </form>

          {search.isFetching && (
            <p className="mt-3 text-sm text-muted-foreground">
              Finding student record…
            </p>
          )}

          {search.isError && (
            <Alert variant="destructive" className="mt-3">
              <AlertDescription>
                The student search failed. Check the connection and try again.
              </AlertDescription>
            </Alert>
          )}

          {search.isSuccess && matches.length === 0 && activeQuery && (
            <Alert variant="destructive" className="mt-3">
              <AlertDescription>
                No student found matching &quot;{activeQuery}&quot;.
              </AlertDescription>
            </Alert>
          )}

          {matches.length > 1 && (
            <ul className="mt-3 grid gap-2" aria-label="Matching students">
              {matches.map((match) => (
                <li key={match.student_id}>
                  <Button
                    type="button"
                    variant={
                      match.student_id === selectedStudentId
                        ? "default"
                        : "outline"
                    }
                    aria-pressed={match.student_id === selectedStudentId}
                    className="h-auto w-full justify-between py-2 text-left"
                    onClick={() => setSelectedStudentId(match.student_id)}
                  >
                    <span>{match.student_name}</span>
                    <span className="font-mono text-xs opacity-80">
                      {match.student_number} ·{" "}
                      {formatYearLevel(match.year_level)}
                    </span>
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      {student && (
        <StatementPanel
          key={student.student_id}
          studentId={student.student_id}
        />
      )}
    </>
  )
}

/** Student: own Statement of Account. Accounting Staff: any student's, by lookup. */
export function StatementOfAccountWorkspace() {
  const { session } = useAuth()
  const role = session?.role
  const authorized = role === "student" || role === "accounting_staff"

  return (
    <WorkspacePage
      title="Statement of Account"
      description={
        role === "accounting_staff"
          ? "Look up a student and review what was assessed, what was paid, and the running balance per term. Print a copy as a PDF."
          : "What was assessed, what you paid, and what you still owe, term by term. Download a copy as a PDF."
      }
      unauthorized={!authorized}
    >
      {role === "accounting_staff" ? (
        <AccountingStatementLookup />
      ) : (
        <StatementPanel studentId={null} />
      )}
    </WorkspacePage>
  )
}
