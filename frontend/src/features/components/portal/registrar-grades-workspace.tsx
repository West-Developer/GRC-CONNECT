"use client"

import { useState } from "react"
import {
  Clock3,
  History,
  Lock,
  Search,
  User,
} from "lucide-react"
import { toast } from "sonner"

import { useAuth } from "@/features/auth/use-auth"
import { AcademicRecordView } from "@/features/components/portal/academic-record-view"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { GradeApprovalsDrilldown } from "@/features/components/portal/grade-approvals-drilldown"
import { Paginator } from "@/features/components/portal/paginator"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { formatYearLevel } from "@/features/lib/format-year-level"
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
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"
import {
  useAcademicGradesQuery,
  useGradeApprovalProfessorsQuery,
  useLockAllAcademicGradesMutation,
  useUpdateAcademicGradeMutation,
} from "@/features/hooks/use-academic-grades"
import { useAcademicTermSelection } from "@/features/hooks/use-academic-term-selection"
import { gradeBadgeVariant } from "@/features/lib/grade-presentation"
import type { AcademicGrade } from "@/features/schemas/academic-grade-schema"
import type { AcademicRecordStudentLookup } from "@/features/schemas/academic-record-schema"
import { searchAcademicRecordStudents } from "@/features/services/academic-record-service"
import { formatAcademicTerm } from "@/features/services/reference-data-service"

const workspaceHeadings: Record<string, string> = {
  "grade-approvals": "Grade approvals",
  "academic-transcripts": "Academic transcripts",
}

const departments = [
  { id: "all", label: "All Departments" },
  { id: "ccs", label: "CCS" },
  { id: "cbae", label: "CBAE" },
  { id: "coe", label: "COE" },
  { id: "coa", label: "COA" },
] as const

/**
 * Locking is permanent — there is no unlock/reject/return in the grade state
 * machine — so a submitted grade can only ever move forward. The confirmation
 * dialog exists for exactly this reason.
 */
export function RegistrarGradesWorkspace({
  initialModuleId = "grade-approvals",
}: {
  initialModuleId?: string
}) {
  const { session } = useAuth()
  const authorized = session?.role === "registrar_head"
  const heading =
    workspaceHeadings[initialModuleId] ?? workspaceHeadings["grade-approvals"]

  const showApprovals = authorized && initialModuleId === "grade-approvals"
  const showTranscripts =
    authorized && initialModuleId === "academic-transcripts"

  const [activeTab, setActiveTab] = useState<"approvals" | "history">(
    "approvals",
  )
  const [approvalsDepartment, setApprovalsDepartment] = useState<string>("all")
  const [approvalsPage, setApprovalsPage] = useState(1)
  const { term } = useAcademicTermSelection()
  const [lockTarget, setLockTarget] = useState<AcademicGrade | null>(null)
  const [confirmLockAll, setConfirmLockAll] = useState(false)
  const [error, setError] = useState("")

  const [historyDepartment, setHistoryDepartment] = useState<string>("all")
  const [historyPage, setHistoryPage] = useState(1)
  const [historySearchInput, setHistorySearchInput] = useState("")
  const [historySearch, setHistorySearch] = useState("")

  const [lookupTab, setLookupTab] = useState<"number" | "name">("number")
  const [studentIdInput, setStudentIdInput] = useState("")
  const [studentId, setStudentId] = useState<number | null>(null)
  const [studentIdError, setStudentIdError] = useState("")
  const [nameInput, setNameInput] = useState("")
  const [nameError, setNameError] = useState("")
  const [candidateStudents, setCandidateStudents] = useState<
    AcademicRecordStudentLookup[]
  >([])
  const [selectedStudent, setSelectedStudent] =
    useState<AcademicRecordStudentLookup | null>(null)
  const [isSearching, setIsSearching] = useState(false)

  // One row per professor with their real totals, paged over professors (the
  // lower levels of the drill-down load their own data).
  const approvalsQuery = useGradeApprovalProfessorsQuery(
    {
      college: approvalsDepartment === "all" ? undefined : approvalsDepartment,
      page: approvalsPage,
      per_page: 12,
    },
    { enabled: showApprovals && activeTab === "approvals" },
  )
  const pendingGradeCount = approvalsQuery.data?.meta.total_grades

  const historyQuery = useAcademicGradesQuery(
    {
      status: "locked",
      college: historyDepartment === "all" ? undefined : historyDepartment,
      search: historySearch.trim() || undefined,
      page: historyPage,
      per_page: 20,
    },
    { enabled: showApprovals && activeTab === "history" },
  )

  const lockMutation = useUpdateAcademicGradeMutation()
  const lockAllMutation = useLockAllAcademicGradesMutation()

  const confirmLock = async () => {
    if (!lockTarget) return
    setError("")
    try {
      await lockMutation.mutateAsync({
        id: lockTarget.id,
        input: { action: "lock" },
      })
      setLockTarget(null)
    } catch {
      setError(
        "The grade could not be locked. Check the connection and try again.",
      )
    }
  }

  const confirmLockAllGrades = async () => {
    setError("")
    try {
      const result = await lockAllMutation.mutateAsync({
        academic_term_id: term?.id,
        college:
          approvalsDepartment === "all" ? undefined : approvalsDepartment,
      })
      setConfirmLockAll(false)
      toast.success(
        result.message || `Successfully locked ${result.locked_count} grades.`,
      )
    } catch {
      setError("Failed to lock all grades. Check the connection and try again.")
    }
  }

  const viewStudent = async () => {
    const raw = studentIdInput.trim()
    if (!raw) {
      setStudentIdError("Enter a valid student ID.")
      return
    }

    const isValidFormat =
      /^\d+$/.test(raw) ||
      /^\d{4}-\d{2}-\d{5}$/.test(raw) ||
      /^STU-\d+$/i.test(raw)
    if (!isValidFormat) {
      setStudentIdError("Enter a valid student ID.")
      return
    }

    setStudentIdError("")
    setIsSearching(true)
    try {
      const results = await searchAcademicRecordStudents({
        search: raw,
        by: "student_number",
      })
      if (results.length === 1 && results[0]) {
        setSelectedStudent(results[0])
        setStudentId(results[0].id)
        setCandidateStudents([])
      } else if (results.length > 1) {
        setCandidateStudents(results)
        setSelectedStudent(null)
      } else {
        const parsed = Number(raw)
        if (Number.isSafeInteger(parsed) && parsed > 0 && parsed < 1000000) {
          setSelectedStudent(null)
          setStudentId(parsed)
          setCandidateStudents([])
        } else {
          setStudentIdError(
            "No student record found matching this student number.",
          )
        }
      }
    } catch {
      const parsed = Number(raw)
      if (Number.isSafeInteger(parsed) && parsed > 0) {
        setSelectedStudent(null)
        setStudentId(parsed)
        setCandidateStudents([])
      } else {
        setStudentIdError(
          "The student record could not be found. Check the ID and try again.",
        )
      }
    } finally {
      setIsSearching(false)
    }
  }

  const searchByName = async () => {
    const raw = nameInput.trim()
    if (!raw) {
      setNameError("Enter a student name to search.")
      return
    }

    setNameError("")
    setIsSearching(true)
    try {
      const results = await searchAcademicRecordStudents({
        search: raw,
        by: "name",
      })
      setCandidateStudents(results)
      if (results.length === 0) {
        setNameError(`No students found matching "${raw}".`)
      }
    } catch {
      setNameError(
        "Failed to search students. Check your connection and try again.",
      )
    } finally {
      setIsSearching(false)
    }
  }

  const selectCandidate = (student: AcademicRecordStudentLookup) => {
    setSelectedStudent(student)
    setStudentId(student.id)
    setCandidateStudents([])
    setStudentIdError("")
    setNameError("")
  }

  const resetSelection = () => {
    setSelectedStudent(null)
    setStudentId(null)
    setCandidateStudents([])
    setStudentIdError("")
    setNameError("")
  }

  return (
    <WorkspacePage
      title={heading}
      description="Lock submitted grades and review any student's academic transcript."
      unauthorized={!authorized}
      lastUpdated={
        activeTab === "approvals"
          ? approvalsQuery.dataUpdatedAt
          : historyQuery.dataUpdatedAt
      }
    >
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {showApprovals && (
        <div className="space-y-4">
          <div
            className="flex items-center gap-2 border-b pb-2"
            role="group"
            aria-label="Approvals view"
          >
            <Button
              type="button"
              variant={activeTab === "approvals" ? "default" : "outline"}
              size="sm"
              className="gap-1.5"
              onClick={() => setActiveTab("approvals")}
            >
              <Clock3 className="size-4" aria-hidden />
              Pending approvals
              {pendingGradeCount !== undefined && (
                <Badge
                  variant={activeTab === "approvals" ? "secondary" : "outline"}
                  className="ml-1 px-1.5 py-0 text-[10px]"
                >
                  {pendingGradeCount}
                </Badge>
              )}
            </Button>
            <Button
              type="button"
              variant={activeTab === "history" ? "default" : "outline"}
              size="sm"
              className="gap-1.5"
              onClick={() => setActiveTab("history")}
            >
              <History className="size-4" aria-hidden />
              Grade history
            </Button>
          </div>

          {activeTab === "approvals" && (
            <Card>
              <CardHeader className="flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <CardTitle level={2}>
                    Submitted grades awaiting lock
                  </CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Professor → Subjects → Students → a student's grades.
                    Department is a filter.
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <div
                    className="flex flex-wrap items-center gap-1.5"
                    role="group"
                    aria-label="Filter by department"
                  >
                    {departments.map((dept) => (
                      <Button
                        key={dept.id}
                        type="button"
                        variant={
                          approvalsDepartment === dept.id
                            ? "default"
                            : "outline"
                        }
                        size="sm"
                        className="h-7 text-xs"
                        onClick={() => {
                          setApprovalsDepartment(dept.id)
                          setApprovalsPage(1)
                        }}
                      >
                        {dept.label}
                      </Button>
                    ))}
                  </div>

                  {pendingGradeCount !== undefined &&
                    pendingGradeCount > 0 && (
                      <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        className="h-7 gap-1.5 text-xs font-medium"
                        disabled={lockAllMutation.isPending}
                        onClick={() => setConfirmLockAll(true)}
                      >
                        <Lock className="size-3.5" aria-hidden />
                        Lock all grades for this semester
                      </Button>
                    )}
                </div>
              </CardHeader>
              <CardContent>
                <AsyncBoundary
                  query={{
                    ...approvalsQuery,
                    data: approvalsQuery.data?.data,
                  }}
                  isEmpty={(rows) => rows.length === 0 && approvalsPage === 1}
                  emptyMessage="No submitted grades are awaiting lock."
                  loadingLabel="Loading submitted grades…"
                >
                  {(professors) => (
                    <GradeApprovalsDrilldown
                      key={approvalsDepartment}
                      professors={professors}
                      page={approvalsQuery.data?.meta.current_page ?? 1}
                      lastPage={approvalsQuery.data?.meta.last_page ?? 1}
                      onPageChange={setApprovalsPage}
                      department={approvalsDepartment}
                      lockingGradeId={
                        lockMutation.isPending ? (lockTarget?.id ?? null) : null
                      }
                      onLock={(grade) => {
                        setLockTarget(grade)
                        setError("")
                      }}
                    />
                  )}
                </AsyncBoundary>
              </CardContent>
            </Card>
          )}

          {activeTab === "history" && (
            <Card>
              <CardHeader className="flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <CardTitle level={2}>Official Grade History</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Archived and official locked grades across all semesters
                  </p>
                </div>
                <div
                  className="flex flex-wrap items-center gap-1.5"
                  role="group"
                  aria-label="Filter grade history by department"
                >
                  {departments.map((dept) => (
                    <Button
                      key={dept.id}
                      type="button"
                      variant={
                        historyDepartment === dept.id ? "default" : "outline"
                      }
                      size="sm"
                      className="h-7 text-xs"
                      onClick={() => {
                        setHistoryDepartment(dept.id)
                        setHistoryPage(1)
                      }}
                    >
                      {dept.label}
                    </Button>
                  ))}
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                  <div className="relative flex-1 min-w-64 max-w-md">
                    <Search
                      className="absolute left-2.5 top-1/2 -translate-y-1/2 size-4 text-muted-foreground"
                      aria-hidden
                    />
                    <Input
                      placeholder="Search by student number, student name, or subject..."
                      value={historySearchInput}
                      onChange={(e) => setHistorySearchInput(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          e.preventDefault()
                          setHistorySearch(historySearchInput)
                          setHistoryPage(1)
                        }
                      }}
                      className="pl-9 h-9 text-sm"
                      aria-label="Search grade history"
                    />
                  </div>
                  <Button
                    type="button"
                    size="sm"
                    className="h-9"
                    onClick={() => {
                      setHistorySearch(historySearchInput)
                      setHistoryPage(1)
                    }}
                  >
                    Search
                  </Button>
                  {historySearch && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-9 text-xs"
                      onClick={() => {
                        setHistorySearchInput("")
                        setHistorySearch("")
                        setHistoryPage(1)
                      }}
                    >
                      Clear
                    </Button>
                  )}
                </div>

                <AsyncBoundary
                  query={{
                    ...historyQuery,
                    data: historyQuery.data?.data,
                  }}
                  isEmpty={(rows) => rows.length === 0}
                  emptyMessage="No historical grade records found matching the criteria."
                  loadingLabel="Loading grade history…"
                >
                  {(grades) => (
                    <DataTable
                      caption="Official Grade History"
                      rowKey={(grade) => grade.id}
                      rows={grades}
                      columns={[
                        {
                          key: "term",
                          header: "Term",
                          render: (grade) => (
                            <span className="font-mono text-xs">
                              {grade.school_year && grade.semester
                                ? `${grade.school_year} · ${grade.semester}`
                                : "—"}
                            </span>
                          ),
                        },
                        {
                          key: "student",
                          header: "Student",
                          render: (grade) => (
                            <div>
                              <p className="font-medium text-foreground">
                                {grade.student_name || grade.student_number}
                              </p>
                              {grade.student_name && (
                                <p className="font-mono text-xs text-muted-foreground">
                                  {grade.student_number}
                                </p>
                              )}
                            </div>
                          ),
                        },
                        {
                          key: "college",
                          header: "Dept",
                          render: (grade) => (
                            <Badge variant="outline" className="text-xs">
                              {grade.college
                                ? grade.college.toUpperCase()
                                : "—"}
                            </Badge>
                          ),
                        },
                        {
                          key: "subject",
                          header: "Subject",
                          render: (grade) => (
                            <div>
                              <p className="font-mono font-medium text-xs text-primary">
                                {grade.subject_code}
                              </p>
                              {grade.subject_title && (
                                <p className="text-xs text-muted-foreground truncate max-w-56">
                                  {grade.subject_title}
                                </p>
                              )}
                            </div>
                          ),
                        },
                        {
                          key: "section",
                          header: "Section",
                          render: (grade) =>
                            grade.section_code ??
                            (grade.section_id ? `#${grade.section_id}` : "—"),
                        },
                        {
                          key: "professor",
                          header: "Professor",
                          render: (grade) => (
                            <span className="text-xs">
                              {grade.professor_name ?? "—"}
                            </span>
                          ),
                        },
                        {
                          key: "mark",
                          header: "Mark",
                          render: (grade) => (
                            <span className="font-semibold">
                              {grade.mark
                                ? `${grade.mark} — ${grade.mark_label ?? ""}`
                                : "—"}
                            </span>
                          ),
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
                    currentPage={historyQuery.data?.meta.current_page ?? 1}
                    lastPage={historyQuery.data?.meta.last_page ?? 1}
                    onPageChange={setHistoryPage}
                  />
                </div>
              </CardContent>
            </Card>
          )}
        </div>
      )}

      {showTranscripts && (
        <div className="space-y-4">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle level={2}>Look up a student</CardTitle>
              <p className="text-xs text-muted-foreground mt-1">
                Search by Student Number (with or without dashes) or search by
                Student Name to review their complete academic transcript.
              </p>
            </CardHeader>
            <CardContent className="space-y-4">
              <Tabs
                value={lookupTab}
                onValueChange={(val) => {
                  setLookupTab(val as "number" | "name")
                  setCandidateStudents([])
                  setStudentIdError("")
                  setNameError("")
                }}
              >
                <TabsList>
                  <TabsTrigger value="number">By Student Number</TabsTrigger>
                  <TabsTrigger value="name">By Student Name</TabsTrigger>
                </TabsList>

                <TabsContent value="number" className="pt-3">
                  <form
                    onSubmit={(e) => {
                      e.preventDefault()
                      void viewStudent()
                    }}
                    className="space-y-2"
                  >
                    <Field data-invalid={studentIdError !== ""}>
                      <FieldLabel htmlFor="transcript-student-id">
                        Student ID
                      </FieldLabel>
                      <div className="flex flex-wrap gap-2">
                        <Input
                          id="transcript-student-id"
                          aria-label="Student ID"
                          placeholder="e.g. 2024-06-01298 or 20240601298"
                          value={studentIdInput}
                          onChange={(event) =>
                            setStudentIdInput(event.target.value)
                          }
                          aria-describedby={
                            studentIdError
                              ? "transcript-student-id-error"
                              : undefined
                          }
                          className="max-w-xs"
                        />
                        <Button type="submit" disabled={isSearching}>
                          {isSearching ? "Searching…" : "View records"}
                        </Button>
                      </div>
                      {studentIdError && (
                        <p
                          id="transcript-student-id-error"
                          className="text-sm text-destructive"
                        >
                          {studentIdError}
                        </p>
                      )}
                    </Field>
                  </form>
                </TabsContent>

                <TabsContent value="name" className="pt-3">
                  <form
                    onSubmit={(e) => {
                      e.preventDefault()
                      void searchByName()
                    }}
                    className="space-y-2"
                  >
                    <Field data-invalid={nameError !== ""}>
                      <FieldLabel htmlFor="transcript-student-name">
                        Student Name
                      </FieldLabel>
                      <div className="flex flex-wrap gap-2">
                        <Input
                          id="transcript-student-name"
                          aria-label="Student Name"
                          placeholder="e.g. Bonifacio, Pangilinan, Ramirez…"
                          value={nameInput}
                          onChange={(event) => setNameInput(event.target.value)}
                          aria-describedby={
                            nameError
                              ? "transcript-student-name-error"
                              : undefined
                          }
                          className="max-w-xs"
                        />
                        <Button type="submit" disabled={isSearching}>
                          {isSearching ? "Searching…" : "Search"}
                        </Button>
                      </div>
                      {nameError && (
                        <p
                          id="transcript-student-name-error"
                          className="text-sm text-destructive"
                        >
                          {nameError}
                        </p>
                      )}
                    </Field>
                  </form>
                </TabsContent>
              </Tabs>

              {candidateStudents.length > 0 && (
                <div className="space-y-2 rounded-lg border bg-muted/20 p-3">
                  <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                    Matching Students ({candidateStudents.length})
                  </p>
                  <div className="divide-y rounded-md border bg-card">
                    {candidateStudents.map((candidate) => (
                      <div
                        key={candidate.id}
                        className="flex flex-wrap items-center justify-between gap-3 p-3 transition hover:bg-muted/40"
                      >
                        <div className="space-y-0.5">
                          <div className="flex items-center gap-2">
                            <span className="font-semibold text-sm">
                              {candidate.name}
                            </span>
                            <Badge
                              variant="outline"
                              className="font-mono text-xs"
                            >
                              {candidate.student_number}
                            </Badge>
                            {candidate.enrollment_category_label && (
                              <Badge variant="secondary" className="text-xs">
                                {candidate.enrollment_category_label}
                              </Badge>
                            )}
                          </div>
                          <p className="text-xs text-muted-foreground">
                            {candidate.program_name} ({candidate.program_code})
                            · {formatYearLevel(candidate.year_level)} ·{" "}
                            {candidate.academic_standing_label}
                          </p>
                        </div>
                        <Button
                          type="button"
                          size="sm"
                          onClick={() => selectCandidate(candidate)}
                        >
                          View transcript
                        </Button>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          {studentId !== null && (
            <div className="space-y-4">
              {selectedStudent && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-3 shadow-2xs">
                  <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-full bg-primary/10 text-primary">
                      <User className="size-4" aria-hidden />
                    </div>
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="font-semibold text-sm">
                          {selectedStudent.name}
                        </span>
                        <Badge variant="outline" className="font-mono text-xs">
                          {selectedStudent.student_number}
                        </Badge>
                        {selectedStudent.enrollment_category_label && (
                          <Badge variant="secondary" className="text-xs">
                            {selectedStudent.enrollment_category_label}
                          </Badge>
                        )}
                      </div>
                      <p className="text-xs text-muted-foreground">
                        {selectedStudent.program_name} (
                        {selectedStudent.program_code}) ·{" "}
                        {formatYearLevel(selectedStudent.year_level)} ·{" "}
                        {selectedStudent.academic_standing_label}
                      </p>
                    </div>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={resetSelection}
                  >
                    <Search className="size-3.5 mr-1" aria-hidden />
                    Search another student
                  </Button>
                </div>
              )}

              <AcademicRecordView studentId={studentId} />
            </div>
          )}
        </div>
      )}

      <AlertDialog
        open={lockTarget !== null}
        onOpenChange={(open) => {
          if (!open && !lockMutation.isPending) setLockTarget(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Lock this grade?</AlertDialogTitle>
            <AlertDialogDescription>
              {lockTarget && (
                <>
                  Locking <strong>{lockTarget.subject_code}</strong> for{" "}
                  <strong>{lockTarget.student_number}</strong> is permanent — it
                  can never be unlocked, edited, or re-submitted. This also
                  triggers the student&apos;s Regular/Irregular
                  reclassification.
                </>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={lockMutation.isPending}>
              Cancel
            </AlertDialogCancel>
            <Button
              type="button"
              disabled={lockMutation.isPending}
              onClick={() => void confirmLock()}
            >
              {lockMutation.isPending ? "Locking" : "Lock grade"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog
        open={confirmLockAll}
        onOpenChange={(open) => {
          if (!open && !lockAllMutation.isPending) setConfirmLockAll(false)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Lock all submitted grades for this semester?
            </AlertDialogTitle>
            <AlertDialogDescription className="space-y-2">
              {term && (
                <span className="block font-medium text-foreground">
                  Semester: {formatAcademicTerm(term)}
                  {approvalsDepartment !== "all" &&
                    ` (${approvalsDepartment.toUpperCase()})`}
                </span>
              )}
              <span>
                Locking all submitted grades is permanent — they can never be
                unlocked, edited, or re-submitted. This will finalize{" "}
                <strong>{pendingGradeCount ?? 0}</strong>{" "}
                submitted grade(s), notify all affected students, and reclassify
                their enrollment category (Regular / Irregular).
              </span>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={lockAllMutation.isPending}>
              Cancel
            </AlertDialogCancel>
            <Button
              type="button"
              variant="destructive"
              disabled={lockAllMutation.isPending}
              onClick={() => void confirmLockAllGrades()}
            >
              {lockAllMutation.isPending ? "Locking all…" : "Lock all grades"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </WorkspacePage>
  )
}
