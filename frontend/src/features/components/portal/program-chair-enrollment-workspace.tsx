"use client"

import { useEffect, useMemo, useState } from "react"
import {
  ArrowUpRight,
  CalendarDays,
  MinusIcon,
  PlusIcon,
  UndoDot,
} from "lucide-react"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { motion } from "motion/react"
import Link from "next/link"

import { useAuth } from "@/features/auth/use-auth"
import { ProgramChairEnrollmentTabs } from "@/features/components/portal/program-chair-enrollment-tabs"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { CurriculumPill } from "@/features/components/portal/curriculum-pill"
import { DemandForecastDialog } from "@/features/components/portal/demand-forecast-dialog"
import {
  SectionScheduleCalendarDialog,
} from "@/features/components/portal/section-schedule-calendar-dialog"
import {
  RoomScheduleAssignmentDialog,
  type RoomScheduleAssignmentResult,
} from "@/features/components/portal/room-schedule-assignment-dialog"
import type { SectionScheduleItem } from "@/features/components/portal/section-schedule-calendar"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
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
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { Textarea } from "@/features/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"
import { useFacultyDirectoryQuery } from "@/features/hooks/use-faculty-directory"
import { useFacultySubjectPreferencesQuery } from "@/features/hooks/use-faculty-input"
import {
  latestScheduleGenerationRunQueryKey,
  useLatestScheduleGenerationRunQuery,
} from "@/features/hooks/use-schedule-generation"
import {
  useAcademicTermsQuery,
  useCurriculaQuery,
  useProgramsQuery,
  useSectionsQuery,
  useSubjectsQuery,
  sectionsQueryKey,
} from "@/features/hooks/use-reference-data"
import {
  useSectionPlanMutations,
  useSectionPlansQuery,
} from "@/features/hooks/use-section-plans"
import {
  scheduleProposalsQueryKey,
  useScheduleProposalsQuery,
} from "@/features/hooks/use-scheduling"
import { useRoomOptionsQuery } from "@/features/hooks/use-room-catalog"
import { scheduleProposalPresentation } from "@/features/lib/schedule-status"
import {
  findCurriculumForSection,
  findProgramForSection,
  getProgramShortLabel,
} from "@/features/lib/program-major-utils"
import { getLocalRoomOptions } from "@/features/services/room-catalog-service"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import type {
  Curriculum,
  Program,
  Section,
} from "@/features/schemas/reference-data-schema"
import type { SectionInput } from "@/features/schemas/scheduling-schema"
import type { ScheduleProposal } from "@/features/schemas/scheduling-schema"
import {
  formatAcademicTerm,
  getActiveAcademicTerm,
} from "@/features/services/reference-data-service"
import { replaceSection } from "@/features/services/scheduling-service"
import { isApiClientError } from "@/features/services/api-client"
import {
  getScheduleGenerationRun,
  startScheduleGeneration,
} from "@/features/services/schedule-generation-service"

const years = [1, 2, 3, 4] as const
const days = [
  "M",
  "T",
  "W",
  "Th",
  "F",
  "Sat",
  "MW",
  "TTh",
  "MWF",
  "FSat",
] as const
const modalityLabels = {
  hyflex_a: "Hyflex A",
  hyflex_b: "Hyflex B",
  f2f: "F2F",
} as const

function yearLabel(year: number) {
  return `${year}${year === 1 ? "st" : year === 2 ? "nd" : year === 3 ? "rd" : "th"} Year`
}

function sectionYearLevel(sectionCode: string) {
  const generated = /(\d)\d{2}$/u.exec(sectionCode)
  if (generated) return Number(generated[1])
  const legacy = Number(sectionCode.charAt(0))
  return years.includes(legacy as (typeof years)[number]) ? legacy : 1
}

function timeWithSeconds(value: string) {
  return value ? (value.length === 5 ? `${value}:00` : value) : null
}

function scheduleErrorMessage(error: unknown) {
  if (isApiClientError(error)) {
    const fieldMessage = Object.values(error.fieldErrors ?? {}).flat()[0]
    return fieldMessage ?? error.message
  }

  return "Schedule could not be saved. Check the professor availability and time range."
}

// A program's curriculum is "new" only relative to its own other versions —
// never to the current term's year. GRC replaces a curriculum roughly every
// six years but keeps the prior version selectable as long as students who
// entered under it (e.g. a 2023 entrant, still in 4th year) haven't finished,
// so this compares within the program rather than against `currentTerm`.
function curriculumAgeLabel(
  curriculum: Pick<Curriculum, "id" | "program_id"> | null | undefined,
  newestIdByProgram: Map<number, number>,
) {
  if (!curriculum) return ""
  return newestIdByProgram.get(curriculum.program_id) === curriculum.id
    ? "New curriculum"
    : "Old curriculum"
}

function toNumber(value: number | "" | undefined, fallback = 0) {
  return value === "" || value === undefined ? fallback : value
}

function approvalPresentation(
  proposal: ScheduleProposal | undefined,
  submitted: boolean,
) {
  if (proposal) return scheduleProposalPresentation(proposal)
  if (!submitted) return null
  // No proposal object yet in this render (the mutation just succeeded and
  // `proposalsQuery` has not refetched) — this transient state is exactly
  // equivalent to "draft, submitted, not yet decided", so reuse the same
  // presentation instead of duplicating its copy here.
  return scheduleProposalPresentation({
    status: "draft",
    is_submitted: true,
    is_returned: false,
    returned_by_role: null,
    decided_by_name: null,
  })
}

function ApprovalStatusCard({
  proposal,
  submitted,
}: {
  proposal: ScheduleProposal | undefined
  submitted: boolean
}) {
  const presentation = approvalPresentation(proposal, submitted)
  const history = proposal?.decision_history ?? []
  if (!presentation && history.length === 0) return null
  const PresentationIcon = presentation?.icon

  return (
    <Card>
      <CardHeader className="border-b bg-muted/30">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <CardTitle className="flex items-center gap-2">
              {PresentationIcon && (
                <PresentationIcon className="size-4" aria-hidden="true" />
              )}
              {presentation?.title ?? "Approval history"}
            </CardTitle>
            {presentation && (
              <CardDescription>{presentation.description}</CardDescription>
            )}
          </div>
          {presentation && (
            <Badge variant={presentation.badgeVariant}>
              {presentation.label}
            </Badge>
          )}
        </div>
      </CardHeader>
      {history.length > 0 && (
        <CardContent>
          <p className="mb-3 text-sm font-medium">Review activity</p>
          <ol className="grid gap-3" aria-label="Schedule approval history">
            {history.map((decision, index) => {
              const isReturn =
                decision.action === "dean_return" ||
                decision.action === "executive_return"
              return (
                <li
                  key={`${decision.action}-${decision.decided_at}-${index}`}
                  className={`grid gap-1 border-l-2 pl-3 ${isReturn ? "border-destructive" : "border-success"}`}
                >
                  <p className="font-medium">{decision.action_label}</p>
                  <p className="text-sm text-muted-foreground">
                    {decision.actor_name} ·{" "}
                    {new Date(decision.decided_at).toLocaleString()}
                  </p>
                  {decision.notes && (
                    <p
                      className={`rounded-md p-2 text-sm ${isReturn ? "bg-destructive/10" : "bg-muted"}`}
                    >
                      <span className="font-medium">Note:</span>{" "}
                      {decision.notes}
                    </p>
                  )}
                </li>
              )
            })}
          </ol>
        </CardContent>
      )}
    </Card>
  )
}

function BlockSectionCard({
  blockCode,
  sections,
  year,
  curriculum,
  program,
  unitsFor,
  setCalendarSection,
}: {
  blockCode: string
  sections: Section[]
  year: number
  curriculum?: Curriculum | null
  program?: { id: number; code: string; name: string } | null
  unitsFor: (subjectId: number) => number | string
  setCalendarSection: (val: {
    blockCode: string
    year: number
    sections: Section[]
  }) => void
}) {
  const totalUnits = sections.reduce((sum, s) => {
    const u = unitsFor(s.subject_id)
    return sum + (typeof u === "number" ? u : Number(u) || 0)
  }, 0)
  // `sections` only ever contains sections already grouped under this exact
  // blockCode, so it is never empty in practice — but if it ever were, show
  // that honestly rather than silently defaulting to a fake capacity of 40
  // (Stakeholder Doc 17).
  const capacity = sections[0]?.capacity

  const handleOpen = () => {
    setCalendarSection({
      blockCode,
      year,
      sections,
    })
  }

  return (
    <Card
      key={blockCode}
      role="article"
      aria-label={`${blockCode} section`}
      className="group flex flex-col justify-between rounded-xl border p-4 text-center transition-all duration-200 hover:border-primary/80 hover:shadow-md hover:bg-muted/10 cursor-pointer active:scale-[0.99]"
      onClick={handleOpen}
    >
      <div className="flex flex-col items-center gap-2">
        <div className="flex items-center justify-center">
          <span className="font-heading text-2xl font-bold tracking-tight text-foreground group-hover:text-primary transition-colors">
            {blockCode}
          </span>
        </div>

        <div className="flex flex-wrap items-center justify-center gap-1.5">
          <Badge variant="secondary" className="text-xs">
            {capacity === undefined
              ? "Capacity not set"
              : sections.every((s) => s.capacity === capacity)
                ? `${capacity} seats`
                : "Mixed seats"}
          </Badge>
          {totalUnits > 0 && (
            <Badge variant="outline" className="text-xs">
              {totalUnits} units
            </Badge>
          )}
          <Badge variant="outline" className="text-xs">
            {yearLabel(year)}
          </Badge>
          {program && (
            <Badge variant="outline" className="text-[10px] text-muted-foreground">
              {program.code}
            </Badge>
          )}
        </div>

        {curriculum && (
          <CurriculumPill
            name={curriculum.name}
            effectiveSchoolYear={curriculum.effective_school_year}
          />
        )}

        <p className="text-xs text-muted-foreground">
          {sections.length} subject{sections.length === 1 ? "" : "s"}
        </p>
      </div>

      <div className="mt-4 pt-3 border-t">
        <Button
          type="button"
          size="sm"
          className="w-full gap-1.5"
          onClick={(e) => {
            e.stopPropagation()
            handleOpen()
          }}
        >
          <CalendarDays className="size-4" aria-hidden="true" />
          View schedule & assign
        </Button>
      </div>
    </Card>
  )
}

function MajorshipCurriculumBar({
  program,
  yearLevel,
  curricula,
  selectedCurriculumId,
  onCurriculumChange,
  disabled,
  isSwitching,
  newestIdByProgram,
  ariaLabel,
  label,
}: {
  program: Program | { id: number; code: string; name: string }
  yearLevel: number
  curricula: readonly Curriculum[]
  selectedCurriculumId: number | null
  onCurriculumChange: (newCurriculumId: number) => void
  disabled?: boolean
  isSwitching?: boolean
  newestIdByProgram: Map<number, number>
  ariaLabel?: string
  label?: string
}) {
  const filtered = curricula
    .filter((c) => (program.id ? c.program_id === program.id : true) && c.status !== "draft")
    .sort((a, b) => b.effective_school_year.localeCompare(a.effective_school_year))

  const programCurricula =
    filtered.length > 0
      ? filtered
      : curricula.filter((c) => c.status !== "draft")

  const activeCurriculum =
    programCurricula.find((c) => c.id === selectedCurriculumId) ??
    programCurricula[0] ??
    null

  const displayLabel =
    label ?? `Curriculum for ${program.code} (${yearLabel(yearLevel)}):`
  const displayAriaLabel = ariaLabel ?? `Curriculum for ${yearLabel(yearLevel)}`

  return (
    <div className="my-2 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 px-4 py-2.5">
      <div className="flex flex-wrap items-center gap-3">
        <span className="text-sm font-medium text-foreground whitespace-nowrap">
          {displayLabel}
        </span>
        <Select
          value={activeCurriculum ? String(activeCurriculum.id) : ""}
          onValueChange={(value) => onCurriculumChange(Number(value))}
          disabled={disabled || isSwitching || programCurricula.length === 0}
        >
          <SelectTrigger
            id={`curriculum-${program.id}-${yearLevel}`}
            aria-label={displayAriaLabel}
            className="h-9 w-full sm:w-[380px] bg-background text-left truncate"
          >
            <SelectValue placeholder="Choose curriculum" />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              {programCurricula.map((curriculum) => (
                <SelectItem
                  key={curriculum.id}
                  value={String(curriculum.id)}
                >
                  {curriculum.name} ({curriculumAgeLabel(curriculum, newestIdByProgram)})
                </SelectItem>
              ))}
            </SelectGroup>
          </SelectContent>
        </Select>
        {activeCurriculum && (
          <Badge variant="secondary" className="font-normal text-xs whitespace-nowrap">
            Effectivity: {activeCurriculum.effective_school_year} (
            {curriculumAgeLabel(activeCurriculum, newestIdByProgram)})
          </Badge>
        )}
      </div>
      {isSwitching && (
        <div className="flex items-center gap-2 text-xs text-muted-foreground animate-pulse">
          <span>Applying curriculum & auto-populating schedule and faculty…</span>
        </div>
      )}
    </div>
  )
}

export function ProgramChairEnrollmentWorkspace({
  workspaceTitle = "Enrollment",
  workspaceDescription = "Build block sections, then assign each subject schedule from its section table.",
}: {
  workspaceTitle?: string
  workspaceDescription?: string
} = {}) {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const termsQuery = useAcademicTermsQuery()
  const curriculaQuery = useCurriculaQuery()
  const programsQuery = useProgramsQuery()
  const sectionsQuery = useSectionsQuery()
  const directoryQuery = useFacultyDirectoryQuery()
  const subjectsQuery = useSubjectsQuery()
  const preferencesQuery = useFacultySubjectPreferencesQuery()
  const roomsQuery = useRoomOptionsQuery()
  // A hook result is immutable to the React Compiler; a bare helper call is
  // not, and would taint every memo derived from `termId` below.
  const currentTerm = useMemo(() => {
    // If there is an actionable draft or for_dean_approval term, that takes precedence for planning
    const planningTerm = termsQuery.data?.find(
      (t) => t.status === "draft" || t.status === "for_dean_approval",
    )
    if (planningTerm) return planningTerm

    const term = getActiveAcademicTerm(termsQuery.data)
    if (!term) return null

    // If the active term is ongoing or closed and its enrollment window has closed,
    // display waiting state until Registrar starts the next academic term.
    if (
      term.status === "semester_ongoing" ||
      term.status === "semester_closed"
    ) {
      const isEnrollmentPast =
        Boolean(term.enrollment_closes_at) &&
        new Date(term.enrollment_closes_at as string).getTime() < Date.now()

      if (isEnrollmentPast) {
        return null
      }
    }

    return term
  }, [termsQuery.data])
  const proposalsQuery = useScheduleProposalsQuery({
    enabled: currentTerm !== null,
  })
  // Only the two most recent versions per program are ever relevant to a
  // currently-enrolled student (PRD-adjacent rule, per product owner:
  // a 2023 entrant finishes on the 2018-2023 curriculum while 2024+
  // entrants are on 2024-2029), so drafts and anything older are excluded.
  const curriculaByProgramRecency = useMemo(() => {
    const eligible = (curriculaQuery.data ?? []).filter(
      (item) => item.status !== "draft",
    )
    const byProgram = new Map<number, Curriculum[]>()
    for (const curriculum of eligible) {
      const list = byProgram.get(curriculum.program_id) ?? []
      list.push(curriculum)
      byProgram.set(curriculum.program_id, list)
    }
    for (const list of byProgram.values()) {
      list.sort((left, right) =>
        right.effective_school_year.localeCompare(left.effective_school_year),
      )
    }
    return byProgram
  }, [curriculaQuery.data])
  const selectableCurricula = useMemo(
    () =>
      Array.from(curriculaByProgramRecency.values()).flatMap((versions) =>
        versions.slice(0, 3),
      ),
    [curriculaByProgramRecency],
  )
  const newestCurriculumIdByProgram = useMemo(
    () =>
      new Map(
        Array.from(curriculaByProgramRecency.entries())
          .filter(
            (entry): entry is [number, [Curriculum, ...Curriculum[]]] =>
              entry[1].length > 0,
          )
          .map(([programId, versions]) => [programId, versions[0].id]),
      ),
    [curriculaByProgramRecency],
  )

  const availablePrograms = useMemo(() => {
    const progs = programsQuery.data ?? []
    if (progs.length > 0) return progs
    const programMap = new Map<number, { id: number; code: string; name: string }>()
    for (const cur of curriculaQuery.data ?? []) {
      if (!programMap.has(cur.program_id)) {
        programMap.set(cur.program_id, {
          id: cur.program_id,
          code: cur.name.replace(/\s*(Curriculum|\d{4}-\d{4}).*$/iu, "").trim() || `Program ${cur.program_id}`,
          name: cur.name.replace(/\s*(Curriculum|\d{4}-\d{4}).*$/iu, "").trim() || cur.name,
        })
      }
    }
    return Array.from(programMap.values()).map((p) => ({
      ...p,
      type: "program" as const,
      status: "active" as const,
      status_label: "Active",
    }))
  }, [programsQuery.data, curriculaQuery.data])

  const hasMajorships = useMemo(() => {
    // Only colleges with distinct majors (e.g. Education and Business Admin) use majorship grouping.
    // Information Technology (CCS) and Accountancy (COA) do not have majorships.
    if (session?.college === "ccs" || session?.college === "coa") return false
    return availablePrograms.length > 1
  }, [session?.college, availablePrograms.length])

  const [step, setStep] = useState<"year" | "review" | "subjects">("year")
  const [yearLevel, setYearLevel] = useState(1)
  const [activeYear, setActiveYear] = useState("1")
  const [selectedMajorId, setSelectedMajorId] = useState<"all" | number>("all")
  const [counts, setCounts] = useState<Record<number, number | "">>({
    1: 0,
    2: 0,
    3: 0,
    4: 0,
  })
  // Seat capacity applied to every block generated for a year level. 40
  // matches the column default, so an untouched year keeps today's size.
  const [studentsPerBlock, setStudentsPerBlock] = useState<
    Record<number, number | "">
  >({ 1: 40, 2: 40, 3: 40, 4: 40 })
  const [curriculumIds, setCurriculumIds] = useState<
    Record<number, number | null>
  >({ 1: null, 2: null, 3: null, 4: null })
  const [curriculaByYearAndProgram, setCurriculaByYearAndProgram] = useState<
    Record<string, number | null>
  >({})
  const [editingSection, setEditingSection] = useState<number | null>(null)
  const [scheduleDraft, setScheduleDraft] = useState({
    professor_id: "",
    day: "",
    start: "",
    end: "",
    room: "",
    modality: "f2f",
    capacity: 40,
    override_reason: "",
  })
  const [scheduleError, setScheduleError] = useState("")
  const [calendarSection, setCalendarSection] = useState<{
    blockCode: string
    year: number
    sections: Section[]
  } | null>(null)
  const [roomPickerOpen, setRoomPickerOpen] = useState(false)
  const [confirmSubmit, setConfirmSubmit] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [submitError, setSubmitError] = useState("")
  const [error, setError] = useState("")
  const [forecastOpen, setForecastOpen] = useState(false)
  const [isSwitchingCurriculum, setIsSwitchingCurriculum] = useState(false)
  const termId = currentTerm?.id ?? 0
  const latestGenerationRunQuery = useLatestScheduleGenerationRunQuery(termId)
  const generationRun = latestGenerationRunQuery.data ?? null
  const generationMutation = useMutation({
    mutationFn: () => {
      setError("")
      return startScheduleGeneration(termId)
    },
    onSuccess: (run) => {
      queryClient.setQueryData(
        latestScheduleGenerationRunQueryKey(session?.userId ?? null, termId),
        run,
      )
      setForecastOpen(true)
      void queryClient.invalidateQueries({
        queryKey: sectionsQueryKey(session?.userId ?? null),
        exact: true,
      })
      void queryClient.invalidateQueries({
        queryKey: ["section-plans", session?.userId ?? null, termId],
      })
    },
    onError: (err: unknown) => {
      const message =
        err instanceof Error
          ? err.message
          : "The predictive schedule could not be started. Please try again."
      setError(message)
    },
  })
  useEffect(() => {
    if (
      generationRun?.status !== "queued" &&
      generationRun?.status !== "running"
    )
      return
    const poll = window.setTimeout(() => {
      void getScheduleGenerationRun(generationRun.id)
        .then((run) => {
          queryClient.setQueryData(
            latestScheduleGenerationRunQueryKey(
              session?.userId ?? null,
              termId,
            ),
            run,
          )
          if (run.status !== "queued" && run.status !== "running") {
            void queryClient.invalidateQueries({
              queryKey: sectionsQueryKey(session?.userId ?? null),
              exact: true,
            })
            void queryClient.invalidateQueries({
              queryKey: ["section-plans", session?.userId ?? null, termId],
            })
          }
        })
        .catch(() => undefined)
    }, 1_500)
    return () => window.clearTimeout(poll)
  }, [generationRun, queryClient, session?.userId, termId])

  const currentProposal = (proposalsQuery.data ?? []).find(
    (proposal) =>
      proposal.academic_term_id === termId &&
      (!session?.college || proposal.college === session.college),
  )
  const returnedProposal = currentProposal?.is_returned
    ? currentProposal
    : undefined
  const approvalLocked = submitted || currentProposal?.is_submitted === true
  const plansQuery = useSectionPlansQuery(termId, currentTerm !== null)
  const planMutations = useSectionPlanMutations(termId)
  // Memoized so the block-grouping memo below survives 5s schedule polls
  // (and so the React Compiler can optimize this component at all).
  const visibleSections = useMemo(
    () =>
      (sectionsQuery.data ?? []).filter(
        (section) =>
          section.academic_term_id === termId &&
          !/^[1-4][A-Z]$/u.test(section.section_code),
      ),
    [sectionsQuery.data, termId],
  )
  const selected =
    visibleSections.find((section) => section.id === editingSection) ?? null
  const subjects = (curriculaQuery.data ?? []).flatMap(
    (curriculum) => curriculum.subjects,
  )

  // Restore the planning form from saved section plans whenever the server
  // data changes. Adjusted during render (React's "adjusting state when a
  // prop changes") rather than in an effect: under 5s schedule polling the
  // effect committed a stale form and then re-rendered this whole workspace.
  const restoreSource = [
    plansQuery.data,
    sectionsQuery.data,
    curriculaQuery.data,
    termId,
  ] as const
  const [restoredFrom, setRestoredFrom] = useState<
    typeof restoreSource | null
  >(null)
  if (
    restoredFrom === null ||
    restoreSource.some((value, index) => value !== restoredFrom[index])
  ) {
    setRestoredFrom(restoreSource)
    const next = plansQuery.data ?? []
    if (next.length) {
      const nextCurriculaByYearProg: Record<string, number | null> = {}
      const restoredPlans = Object.fromEntries(
        years.map((year) => {
          const plan = next.find(
            (item) => item.year_level === year && item.section_count > 0,
          )
          return [year, plan]
        }),
      )

      for (const plan of next) {
        if (plan.curriculum_id) {
          const cur = (curriculaQuery.data ?? []).find(
            (c) => c.id === plan.curriculum_id,
          )
          if (cur?.program_id) {
            nextCurriculaByYearProg[`${plan.year_level}_${cur.program_id}`] =
              plan.curriculum_id
          }
        }
      }

      setCurriculaByYearAndProgram((prev) => ({
        ...nextCurriculaByYearProg,
        ...prev,
      }))

      setCounts(
        Object.fromEntries(
          years.map((year) => [year, restoredPlans[year]?.section_count ?? 0]),
        ),
      )

      setStudentsPerBlock(
        Object.fromEntries(
          years.map((year) => [
            year,
            restoredPlans[year]?.students_per_block ?? 40,
          ]),
        ),
      )
      setCurriculumIds(
        Object.fromEntries(
          years.map((year) => [
            year,
            restoredPlans[year]?.curriculum_id ?? null,
          ]),
        ),
      )

      const hasGeneratedSections = (sectionsQuery.data ?? []).some(
        (section) =>
          section.academic_term_id === termId &&
          !/^[1-4][A-Z]$/u.test(section.section_code),
      )
      if (
        hasGeneratedSections &&
        years.every((year) => restoredPlans[year] !== undefined)
      ) {
        setStep("subjects")
      }
    }
  }

  const subjectFor = (subjectId: number) =>
    subjects.find((subject) => subject.subject_id === subjectId)
  const unitsFor = (subjectId: number) =>
    subjectFor(subjectId)?.units ??
    subjectsQuery.data?.find((subject) => subject.id === subjectId)?.units ??
    "—"
  const facultyNameFor = (professorId: number | null) =>
    directoryQuery.data?.find((member) => member.id === professorId)?.name
  const calendarItems: SectionScheduleItem[] = useMemo(() => {
    if (!calendarSection) return []
    const currentSections = sectionsQuery.data ?? []
    return calendarSection.sections.map((sec) => {
      const section =
        currentSections.find((candidate) => candidate.id === sec.id) ?? sec
      const subject = subjectFor(section.subject_id)
      const u = unitsFor(section.subject_id)
      return {
        id: section.id,
        subject_code: subject?.code ?? `Subject #${section.subject_id}`,
        subject_title: subject?.title ?? "Subject",
        units: typeof u === "number" ? u : Number(u) || null,
        section_code: section.section_code,
        room: section.room,
        professor_name: facultyNameFor(section.professor_id) ?? null,
        schedule_days: section.schedule_days,
        starts_at_time: section.starts_at_time,
        ends_at_time: section.ends_at_time,
        modality: section.modality ?? null,
        capacity: section.capacity,
        enrolled_count: section.enrolled_count,
      }
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    calendarSection,
    sectionsQuery.data,
    subjects,
    subjectsQuery.data,
    directoryQuery.data,
  ])
  const currentSectionScheduledItems: SectionScheduleItem[] = useMemo(() => {
    if (!selected) return []
    const allSections = sectionsQuery.data ?? []
    return allSections
      .filter(
        (s) =>
          s.section_code === selected.section_code &&
          s.academic_term_id === selected.academic_term_id &&
          s.id !== selected.id &&
          s.schedule_days &&
          s.starts_at_time &&
          s.ends_at_time,
      )
      .map((s) => {
        const subj = subjectFor(s.subject_id)
        const u = unitsFor(s.subject_id)
        return {
          id: s.id,
          subject_code: subj?.code ?? `Subject #${s.subject_id}`,
          subject_title: subj?.title ?? "Subject",
          units: typeof u === "number" ? u : Number(u) || null,
          section_code: s.section_code,
          room: s.room,
          professor_name: facultyNameFor(s.professor_id) ?? null,
          schedule_days: s.schedule_days,
          starts_at_time: s.starts_at_time,
          ends_at_time: s.ends_at_time,
          modality: s.modality ?? null,
          capacity: s.capacity,
          enrolled_count: s.enrolled_count,
        }
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    selected,
    sectionsQuery.data,
    subjects,
    subjectsQuery.data,
    directoryQuery.data,
  ])
  const capableIds = selected
    ? (preferencesQuery.data ?? [])
        .filter(
          (item) =>
            item.academic_term_id === termId &&
            item.subject_id === selected.subject_id,
        )
        .map((item) => item.professor_id)
    : []
  const availableFaculty =
    selected && capableIds.length
      ? (directoryQuery.data ?? []).filter((member) =>
          capableIds.includes(member.id),
        )
      : (directoryQuery.data ?? [])
  const roomOptions = roomsQuery.data ?? getLocalRoomOptions(session?.college)

  const selectedCurriculaMap = useMemo(
    () =>
      new Map(
        Object.entries(curriculaByYearAndProgram).filter(
          (entry): entry is [string, number] => entry[1] !== null,
        ),
      ),
    [curriculaByYearAndProgram],
  )

  const groupedSections = useMemo(() => {
    const selectedYear = Number(activeYear)
    const groups = new Map<string, Section[]>()

    visibleSections
      .filter(
        (section) => sectionYearLevel(section.section_code) === selectedYear,
      )
      .sort(
        (left, right) =>
          left.section_code.localeCompare(right.section_code) ||
          left.id - right.id,
      )
      .forEach((section) =>
        groups.set(section.section_code, [
          ...(groups.get(section.section_code) ?? []),
          section,
        ]),
      )

    return [...groups.entries()].map(([blockCode, sections]) => {
      const program = findProgramForSection({
        blockCode,
        section: sections[0],
        plans: plansQuery.data ?? [],
        curricula: curriculaQuery.data ?? [],
        programs: availablePrograms,
      })
      const curriculum = findCurriculumForSection({
        blockCode,
        section: sections[0],
        plans: plansQuery.data ?? [],
        curricula: curriculaQuery.data ?? [],
        programs: availablePrograms,
        selectedCurriculaByYearAndProgram: selectedCurriculaMap,
        yearLevel: selectedYear,
      })

      return {
        blockCode,
        sections,
        program,
        curriculum,
      }
    })
  }, [
    visibleSections,
    activeYear,
    plansQuery.data,
    curriculaQuery.data,
    availablePrograms,
    selectedCurriculaMap,
  ])

  const displayedGroups = useMemo(() => {
    if (selectedMajorId === "all") return groupedSections
    return groupedSections.filter((g) => g.program?.id === selectedMajorId)
  }, [groupedSections, selectedMajorId])

  const groupsByProgram = useMemo(() => {
    const map = new Map<
      number,
      {
        program: Program | { id: number; code: string; name: string }
        groups: typeof groupedSections
      }
    >()

    for (const block of groupedSections) {
      if (block.program) {
        const existing = map.get(block.program.id) ?? {
          program: block.program,
          groups: [],
        }
        existing.groups.push(block)
        map.set(block.program.id, existing)
      }
    }

    return Array.from(map.values())
  }, [groupedSections])

  const selectedCurriculumForYear = (year: number) =>
    selectableCurricula.find(
      (curriculum) => curriculum.id === curriculumIds[year],
    ) ?? null
  const selectedCurriculumIds = [
    ...new Set(
      Object.values(curriculumIds).filter((id): id is number => id !== null),
    ),
  ]
  const allYearsHaveCurriculum = years.every(
    (year) => curriculumIds[year] !== null,
  )
  // Incomplete schedule details remain visible to reviewers, but they do
  // not block the Program Chair from submitting a proposal for approval.
  const incompleteScheduleCount = visibleSections.filter(
    (section) =>
      !section.schedule_days ||
      !section.starts_at_time ||
      !section.ends_at_time ||
      !section.room ||
      !section.modality,
  ).length
  const hasInvalidTimeOrder =
    scheduleDraft.start !== "" &&
    scheduleDraft.end !== "" &&
    scheduleDraft.end <= scheduleDraft.start

  if (
    !termsQuery.isPending &&
    !curriculaQuery.isPending &&
    currentTerm === null
  ) {
    return (
      <WorkspacePage
        title="Enrollment"
        description="Program Head enrollment opens after the Registrar creates the next school year and semester."
      >
        <ProgramChairEnrollmentTabs current="enrollment" />
        <Alert>
          <AlertDescription>
            Waiting for Registrar for the school year and semester.
          </AlertDescription>
        </Alert>
      </WorkspacePage>
    )
  }

  const saveSelections = async (nextCounts: Record<number, number | "">) => {
    for (const selectedCurriculumId of selectedCurriculumIds) {
      await planMutations.save.mutateAsync({
        academic_term_id: termId,
        curriculum_id: selectedCurriculumId,
        counts: Object.fromEntries(
          years.map((year) => [
            String(year),
            curriculumIds[year] === selectedCurriculumId
              ? toNumber(nextCounts[year])
              : 0,
          ]),
        ),
        students_per_block: Object.fromEntries(
          years.map((year) => [
            String(year),
            Math.max(1, toNumber(studentsPerBlock[year], 40)),
          ]),
        ),
      })
    }
  }
  const saveCurrentCount = async () => {
    if (curriculumIds[yearLevel] === null)
      throw new Error("curriculum-required")
    const next = {
      ...counts,
      [yearLevel]: Math.max(0, toNumber(counts[yearLevel])),
    }
    setCounts(next)
    await saveSelections(next)
  }
  const continueYear = async () => {
    setError("")
    try {
      await saveCurrentCount()
      if (yearLevel < 4) setYearLevel(yearLevel + 1)
      else setStep("review")
    } catch {
      setError(
        "Choose a curriculum, then save the section count for this year level.",
      )
    }
  }
  const releaseSubjects = async () => {
    setError("")
    try {
      if (!allYearsHaveCurriculum) throw new Error("curriculum-required")
      for (const selectedCurriculumId of selectedCurriculumIds)
        await planMutations.release.mutateAsync({
          curriculumId: selectedCurriculumId,
        })
      await sectionsQuery.refetch()
      setActiveYear("1")
      setStep("subjects")
    } catch {
      setError(
        "Choose a curriculum and complete the section count for all four year levels before generating subjects.",
      )
    }
  }
  const addSection = async () => {
    const year = Number(activeYear)
    const nextCounts = { ...counts, [year]: toNumber(counts[year]) + 1 }
    const selectedCurriculumId = curriculumIds[year]

    setError("")
    try {
      if (selectedCurriculumId === null) throw new Error("curriculum-required")
      await saveSelections(nextCounts)
      await planMutations.release.mutateAsync({
        curriculumId: selectedCurriculumId,
        yearLevel: year,
      })
      setCounts(nextCounts)
      await sectionsQuery.refetch()
    } catch {
      setError(
        `The ${yearLabel(year)} section could not be added. Please check the saved year-level plan and retry.`,
      )
    }
  }
  const removeSection = async () => {
    const year = Number(activeYear)
    const selectedCurriculumId = curriculumIds[year]
    const nextCounts = {
      ...counts,
      [year]: Math.max(0, toNumber(counts[year]) - 1),
    }

    setError("")
    try {
      if (selectedCurriculumId === null) throw new Error("curriculum-required")
      await saveSelections(nextCounts)
      await planMutations.release.mutateAsync({
        curriculumId: selectedCurriculumId,
        yearLevel: year,
      })
      setCounts(nextCounts)
      await sectionsQuery.refetch()
    } catch (caughtError) {
      setError(scheduleErrorMessage(caughtError))
    }
  }
  const addSectionForProgram = async (
    programId: number,
    curriculumId: number | null | undefined,
    currentCount: number,
  ) => {
    const year = Number(activeYear)
    const effectiveCurId =
      curriculumId ?? newestCurriculumIdByProgram.get(programId)
    if (!effectiveCurId) {
      setError("Please select a curriculum for this majorship first.")
      return
    }
    const nextCount = currentCount + 1
    setError("")
    try {
      await planMutations.save.mutateAsync({
        academic_term_id: termId,
        curriculum_id: effectiveCurId,
        counts: { [String(year)]: nextCount },
        students_per_block: {
          [String(year)]: Math.max(1, toNumber(studentsPerBlock[year], 40)),
        },
      })
      await planMutations.release.mutateAsync({
        curriculumId: effectiveCurId,
        yearLevel: year,
      })
      await sectionsQuery.refetch()
    } catch {
      setError(
        `The ${yearLabel(year)} section could not be added for this majorship. Please check the curriculum plan and retry.`,
      )
    }
  }
  const removeSectionForProgram = async (
    programId: number,
    curriculumId: number | null | undefined,
    currentCount: number,
  ) => {
    const year = Number(activeYear)
    const effectiveCurId =
      curriculumId ?? newestCurriculumIdByProgram.get(programId)
    if (!effectiveCurId) {
      setError("Please select a curriculum for this majorship first.")
      return
    }
    const nextCount = Math.max(0, currentCount - 1)
    setError("")
    try {
      await planMutations.save.mutateAsync({
        academic_term_id: termId,
        curriculum_id: effectiveCurId,
        counts: { [String(year)]: nextCount },
        students_per_block: {
          [String(year)]: Math.max(1, toNumber(studentsPerBlock[year], 40)),
        },
      })
      await planMutations.release.mutateAsync({
        curriculumId: effectiveCurId,
        yearLevel: year,
      })
      await sectionsQuery.refetch()
    } catch (caughtError) {
      setError(scheduleErrorMessage(caughtError))
    }
  }
  const generateSubjectsForYear = async (year: number) => {
    const selectedCurriculumId = curriculumIds[year]

    setError("")
    try {
      if (selectedCurriculumId === null) throw new Error("curriculum-required")
      await saveSelections(counts)
      await planMutations.release.mutateAsync({
        curriculumId: selectedCurriculumId,
        yearLevel: year,
      })
      await planMutations.autoAssign.mutateAsync({
        curriculumId: selectedCurriculumId,
        yearLevel: year,
      })
      await sectionsQuery.refetch()
    } catch {
      setError(
        `Subjects for ${yearLabel(year)} could not be generated. Check the selected curriculum and saved section count.`,
      )
    }
  }
  const handleCurriculumChange = async (
    year: number,
    newCurriculumId: number,
    programId?: number,
  ) => {
    setIsSwitchingCurriculum(true)
    setError("")

    try {
      const nextCurriculumIds = {
        ...curriculumIds,
        [year]: newCurriculumId,
      }
      setCurriculumIds(nextCurriculumIds)
      if (programId) {
        setCurriculaByYearAndProgram((prev) => ({
          ...prev,
          [`${year}_${programId}`]: newCurriculumId,
        }))
      }

      const currentCount = Math.max(1, toNumber(counts[year], 1))
      const currentCapacity = Math.max(1, toNumber(studentsPerBlock[year], 40))

      await planMutations.save.mutateAsync({
        academic_term_id: termId,
        curriculum_id: newCurriculumId,
        counts: {
          [String(year)]: currentCount,
        },
        students_per_block: {
          [String(year)]: currentCapacity,
        },
      })

      await planMutations.release.mutateAsync({
        curriculumId: newCurriculumId,
        yearLevel: year,
      })

      await planMutations.autoAssign.mutateAsync({
        curriculumId: newCurriculumId,
        yearLevel: year,
      })

      await Promise.all([sectionsQuery.refetch(), plansQuery.refetch()])
    } catch (caughtError) {
      setError(
        `Failed to update curriculum and auto-populate schedule for ${yearLabel(year)}. ${scheduleErrorMessage(caughtError)}`,
      )
    } finally {
      setIsSwitchingCurriculum(false)
    }
  }
  const openEdit = (section: Section) => {
    setScheduleError("")
    setEditingSection(section.id)
    setScheduleDraft({
      professor_id: section.professor_id ? String(section.professor_id) : "",
      day: section.schedule_days ?? "",
      start: section.starts_at_time?.slice(0, 5) ?? "",
      end: section.ends_at_time?.slice(0, 5) ?? "",
      room: section.room ?? "",
      modality: section.modality ?? "f2f",
      capacity: section.capacity,
      override_reason: section.manual_override_reason ?? "",
    })
  }
  const applyPickedSchedule = (result: RoomScheduleAssignmentResult) => {
    setScheduleDraft((current) => ({
      ...current,
      room: result.room,
      day: result.scheduleDays,
      start: result.startsAtTime,
      end: result.endsAtTime,
      modality: result.modality,
    }))
  }
  const saveSchedule = async () => {
    if (!selected) return

    setError("")
    setScheduleError("")
    if (hasInvalidTimeOrder) return
    if (scheduleDraft.capacity < selected.enrolled_count) return

    const input: SectionInput = {
      academic_term_id: selected.academic_term_id,
      subject_id: selected.subject_id,
      section_code: selected.section_code,
      professor_id: scheduleDraft.professor_id
        ? Number(scheduleDraft.professor_id)
        : null,
      schedule_days: scheduleDraft.day || null,
      starts_at_time: timeWithSeconds(scheduleDraft.start),
      ends_at_time: timeWithSeconds(scheduleDraft.end),
      room: scheduleDraft.room || null,
      modality: scheduleDraft.modality as SectionInput["modality"],
      capacity: Math.max(1, scheduleDraft.capacity),
      viability_threshold: selected.viability_threshold,
      status: selected.status,
      override_reason: scheduleDraft.override_reason.trim() || null,
    }

    try {
      await replaceSection(selected.id, input)
      setEditingSection(null)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["sections"] }),
        queryClient.invalidateQueries({ queryKey: ["room-occupancy"] }),
        queryClient.invalidateQueries({ queryKey: ["section-plans"] }),
        queryClient.invalidateQueries({ queryKey: ["rooms"] }),
      ])
      await sectionsQuery.refetch()
    } catch (caughtError) {
      setScheduleError(scheduleErrorMessage(caughtError))
    }
  }
  const submit = async () => {
    setError("")
    setSubmitError("")
    try {
      for (const selectedCurriculumId of selectedCurriculumIds)
        await planMutations.submit.mutateAsync(selectedCurriculumId)
      await queryClient.invalidateQueries({
        queryKey: scheduleProposalsQueryKey(session?.userId ?? null),
        exact: true,
      })
      setConfirmSubmit(false)
      setSubmitted(true)
    } catch (caughtError) {
      setConfirmSubmit(false)
      setSubmitError(scheduleErrorMessage(caughtError))
    }
  }

  return (
    <WorkspacePage
      title={workspaceTitle}
      description={workspaceDescription}
      lastUpdated={sectionsQuery.dataUpdatedAt}
    >
      <ProgramChairEnrollmentTabs current="enrollment" />
      {(error || termsQuery.isError || curriculaQuery.isError) && (
        <Alert variant="destructive">
          <AlertDescription>
            {error || "Enrollment reference data could not be loaded."}
          </AlertDescription>
        </Alert>
      )}
      {returnedProposal?.decision_reason && (
        <Alert variant="destructive">
          <UndoDot className="size-4" aria-hidden="true" />
          <AlertDescription className="grid gap-1">
            <p className="font-medium">Returned for correction</p>
            <p>{returnedProposal.decision_reason}</p>
            <p className="text-sm">
              Sent back by{" "}
              {returnedProposal.decided_by_name ?? "the assigned reviewer"}. Fix
              the flagged schedules below, then resubmit.
            </p>
          </AlertDescription>
        </Alert>
      )}
      <ApprovalStatusCard proposal={currentProposal} submitted={submitted} />
      {approvalLocked && (
        <Card>
          <CardHeader>
            <CardTitle>Still need to fix a room or professor?</CardTitle>
            <CardDescription>
              Room and professor assignments stay editable in Schedule while
              this schedule waits for Dean and Executive Director review —
              only a published section locks.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Button asChild>
              <Link href="/portal/schedule">
                Open Schedule
                <ArrowUpRight data-icon="inline-end" aria-hidden="true" />
              </Link>
            </Button>
          </CardContent>
        </Card>
      )}
      {!approvalLocked && (
        <Card>
          <CardHeader>
            <CardTitle>Predictive schedule planning</CardTitle>
            <CardDescription>
              {currentTerm
                ? `${formatAcademicTerm(currentTerm)} · Generate advisory section demand first, then review and edit the resulting draft.`
                : "Loading current term…"}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-5">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 p-4">
              <div className="grid gap-1">
                <p className="font-medium">Section Demand Forecasting</p>
                <p className="text-sm text-muted-foreground">
                  Uses aggregate historical enrollment, curriculum placement,
                  ranked faculty subject preferences, and declared availability
                  as planning inputs. Recommendations remain editable.
                </p>
              </div>
              <div className="flex flex-wrap gap-2">
                <motion.span layoutId="demand-forecast-surface">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => setForecastOpen(true)}
                    disabled={termId === 0}
                  >
                    Demand Forecast
                  </Button>
                </motion.span>
                <Button
                  type="button"
                  onClick={() => void generationMutation.mutateAsync()}
                  disabled={termId === 0 || generationMutation.isPending}
                >
                  {generationMutation.isPending
                    ? "Generating schedule…"
                    : "Generate Schedule"}
                </Button>
              </div>
            </div>
            {generationRun && (
              <Alert>
                <AlertDescription>
                  {generationRun.status === "queued" ||
                  generationRun.status === "running"
                    ? "Your predictive schedule is being generated. You can continue reviewing the current editable draft."
                    : generationRun.status === "failed"
                      ? (generationRun.error_summary ??
                        "The forecast could not be generated.")
                      : `Demand forecast complete${generationRun.warnings.length ? ` with ${generationRun.warnings.length} warning(s)` : ""}. Review and edit sections before approval.`}
                </AlertDescription>
              </Alert>
            )}
            {step !== "subjects" && (
              <div
                className="grid gap-2 sm:grid-cols-3 lg:grid-cols-6"
                aria-label="Enrollment planning progress"
              >
                {[
                  "1st Year",
                  "2nd Year",
                  "3rd Year",
                  "4th Year",
                  "Review",
                  "Approval",
                ].map((label, index) => (
                  <Button
                    key={label}
                    type="button"
                    variant={
                      index < 4
                        ? step === "year" && index === yearLevel - 1
                          ? "destructive"
                          : "outline"
                        : step === "review" && index === 4
                          ? "default"
                          : "outline"
                    }
                    disabled={index > 3 && step === "year"}
                    onClick={() =>
                      index < 4
                        ? (setYearLevel(index + 1), setStep("year"))
                        : index === 4
                          ? setStep("review")
                          : undefined
                    }
                  >
                    {label}
                  </Button>
                ))}
              </div>
            )}

            {step === "year" && (
              <Card>
                <CardHeader>
                  <CardTitle>{yearLabel(yearLevel)} sections</CardTitle>
                  <CardDescription>
                    Choose the curriculum version first, then enter the number
                    of block sections for this year level.
                  </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4">
                  <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    <Field>
                      <FieldLabel htmlFor={`curriculum-${yearLevel}`}>
                        Curriculum for {yearLabel(yearLevel)}
                      </FieldLabel>
                      <Select
                        value={
                          curriculumIds[yearLevel]
                            ? String(curriculumIds[yearLevel])
                            : ""
                        }
                        onValueChange={(value) =>
                          setCurriculumIds({
                            ...curriculumIds,
                            [yearLevel]: Number(value),
                          })
                        }
                      >
                        <SelectTrigger id={`curriculum-${yearLevel}`}>
                          <SelectValue placeholder="Choose curriculum" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectGroup>
                            {selectableCurricula.map((curriculum) => (
                              <SelectItem
                                key={curriculum.id}
                                value={String(curriculum.id)}
                              >
                                {curriculum.name} ·{" "}
                                {curriculum.effective_school_year} ·{" "}
                                {curriculumAgeLabel(
                                  curriculum,
                                  newestCurriculumIdByProgram,
                                )}
                              </SelectItem>
                            ))}
                          </SelectGroup>
                        </SelectContent>
                      </Select>
                      {selectedCurriculumForYear(yearLevel) && (
                        <FieldDescription>
                          Effectivity:{" "}
                          {
                            selectedCurriculumForYear(yearLevel)
                              ?.effective_school_year
                          }{" "}
                          <Badge variant="secondary">
                            {curriculumAgeLabel(
                              selectedCurriculumForYear(yearLevel),
                              newestCurriculumIdByProgram,
                            )}
                          </Badge>
                        </FieldDescription>
                      )}
                    </Field>
                    <Field>
                      <FieldLabel htmlFor="section-count">
                        Number of block sections
                      </FieldLabel>
                      <Input
                        id="section-count"
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        value={counts[yearLevel]}
                        onChange={(event) => {
                          const digits = event.target.value.replace(/\D/gu, "")
                          setCounts({
                            ...counts,
                            [yearLevel]:
                              digits === "" ? "" : Math.min(99, Number(digits)),
                          })
                        }}
                      />
                    </Field>
                    <Field>
                      <FieldLabel htmlFor="students-per-block">
                        Students per block
                      </FieldLabel>
                      <Input
                        id="students-per-block"
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        value={studentsPerBlock[yearLevel]}
                        onChange={(event) => {
                          const digits = event.target.value.replace(/\D/gu, "")
                          setStudentsPerBlock({
                            ...studentsPerBlock,
                            [yearLevel]:
                              digits === ""
                                ? ""
                                : Math.min(300, Number(digits)),
                          })
                        }}
                      />
                      <FieldDescription>
                        Seats in every block generated for{" "}
                        {yearLabel(yearLevel)}. You can change a single section
                        later when you assign its schedule.
                      </FieldDescription>
                    </Field>
                  </FieldGroup>
                  <div>
                    <Button
                      type="button"
                      onClick={() => void continueYear()}
                      disabled={
                        curriculumIds[yearLevel] === null ||
                        planMutations.save.isPending
                      }
                    >
                      {yearLevel === 4
                        ? "Continue to review"
                        : "Save and continue"}
                    </Button>
                  </div>
                </CardContent>
              </Card>
            )}

            {step === "review" && (
              <Card>
                <CardHeader>
                  <CardTitle>Review block sections</CardTitle>
                  <CardDescription>
                    Confirm each year level before releasing the
                    current-semester subject lists.
                  </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-3">
                  {years.map((year) => (
                    <div
                      key={year}
                      className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                    >
                      <div className="grid gap-1">
                        <span className="font-medium">{yearLabel(year)}</span>
                        <span className="text-sm text-muted-foreground">
                          {selectedCurriculumForYear(year)
                            ? `${selectedCurriculumForYear(year)?.name} · ${selectedCurriculumForYear(year)?.effective_school_year}`
                            : "Curriculum not selected"}
                        </span>
                      </div>
                      <div className="flex items-center gap-2">
                        <Badge variant="secondary">
                          {toNumber(counts[year])} blocks
                        </Badge>
                        {selectedCurriculumForYear(year) && (
                          <Badge variant="outline">
                            {curriculumAgeLabel(
                              selectedCurriculumForYear(year),
                              newestCurriculumIdByProgram,
                            )}
                          </Badge>
                        )}
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => {
                            setYearLevel(year)
                            setStep("year")
                          }}
                        >
                          Edit
                        </Button>
                      </div>
                    </div>
                  ))}
                  <div className="flex flex-wrap gap-2">
                    <Button
                      type="button"
                      onClick={() => void releaseSubjects()}
                      disabled={planMutations.release.isPending}
                    >
                      Generate subject list
                    </Button>
                  </div>
                </CardContent>
              </Card>
            )}

            {step === "subjects" && (
              <Tabs value={activeYear} onValueChange={setActiveYear}>
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <TabsList
                    aria-label="Generated section year filter"
                    className="h-auto w-full p-1 sm:w-fit group-data-horizontal/tabs:h-auto"
                  >
                    {years.map((year) => {
                      const cur = selectedCurriculumForYear(year)
                      return (
                        <TabsTrigger
                          key={year}
                          value={String(year)}
                          aria-label={yearLabel(year)}
                          className="flex flex-col items-center justify-center gap-0.5 px-3.5 py-1.5 h-auto text-xs sm:text-sm data-active:shadow-sm"
                        >
                          <span className="font-semibold leading-tight">{yearLabel(year)}</span>
                          {cur && (
                            <span className="text-[10px] font-medium opacity-80 leading-tight" aria-hidden="true">
                              {cur.effective_school_year}
                            </span>
                          )}
                        </TabsTrigger>
                      )
                    })}
                  </TabsList>
                  <div className="flex flex-wrap items-center gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => void removeSection()}
                      disabled={
                        approvalLocked ||
                        isSwitchingCurriculum ||
                        toNumber(counts[Number(activeYear)]) <= 0 ||
                        planMutations.save.isPending ||
                        planMutations.release.isPending
                      }
                    >
                      <MinusIcon data-icon="inline-start" />
                      Remove section
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => void addSection()}
                      disabled={
                        approvalLocked ||
                        isSwitchingCurriculum ||
                        planMutations.save.isPending ||
                        planMutations.release.isPending
                      }
                    >
                      <PlusIcon data-icon="inline-start" />
                      Add section
                    </Button>
                  </div>
                </div>

                {/* Majorship Filter Bar below 1st..4th year tabs */}
                {hasMajorships && (
                  <div className="mt-3 flex flex-wrap items-center gap-2 border-t pt-3">
                    <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mr-1">
                      Majorship:
                    </span>
                    <Button
                      type="button"
                      size="sm"
                      variant={selectedMajorId === "all" ? "default" : "outline"}
                      className="h-7 px-3 text-xs gap-1.5 rounded-full"
                      onClick={() => setSelectedMajorId("all")}
                    >
                      <span>All</span>
                      <Badge
                        variant={selectedMajorId === "all" ? "secondary" : "outline"}
                        className="text-[10px] px-1.5 py-0 h-4 min-w-4 flex items-center justify-center rounded-full"
                      >
                        {groupedSections.length}
                      </Badge>
                    </Button>
                    {availablePrograms.map((prog) => {
                      const count = groupedSections.filter(
                        (g) => g.program?.id === prog.id,
                      ).length
                      return (
                        <Button
                          key={prog.id}
                          type="button"
                          size="sm"
                          variant={selectedMajorId === prog.id ? "default" : "outline"}
                          className="h-7 px-3 text-xs gap-1.5 rounded-full"
                          onClick={() => setSelectedMajorId(prog.id)}
                        >
                          <span>{getProgramShortLabel(prog)}</span>
                          <Badge
                            variant={selectedMajorId === prog.id ? "secondary" : "outline"}
                            className="text-[10px] px-1.5 py-0 h-4 min-w-4 flex items-center justify-center rounded-full"
                          >
                            {count}
                          </Badge>
                        </Button>
                      )
                    })}
                  </div>
                )}

                {years.map((year) => {
                  const currentYearNum = year
                  return (
                    <TabsContent key={year} value={String(year)} className="mt-2">
                      {groupedSections.length === 0 ? (
                        <div className="grid gap-4">
                          <MajorshipCurriculumBar
                            program={
                              (selectedMajorId !== "all"
                                ? availablePrograms.find((p) => p.id === selectedMajorId)
                                : availablePrograms[0]) ?? {
                                id: 0,
                                code: "Program",
                                name: "Program",
                              }
                            }
                            yearLevel={currentYearNum}
                            curricula={selectableCurricula}
                            selectedCurriculumId={curriculumIds[year]}
                            onCurriculumChange={(newCurId) =>
                              void handleCurriculumChange(
                                currentYearNum,
                                newCurId,
                              )
                            }
                            disabled={approvalLocked || isSwitchingCurriculum}
                            isSwitching={isSwitchingCurriculum}
                            newestIdByProgram={newestCurriculumIdByProgram}
                            ariaLabel={`Curriculum for ${yearLabel(year)}`}
                            label={`Curriculum for ${yearLabel(year)}:`}
                          />
                          <Alert>
                            <AlertDescription>
                              No generated subject blocks for {yearLabel(year)} yet.
                              Planned blocks: {toNumber(counts[year])}.
                            </AlertDescription>
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              onClick={() => void generateSubjectsForYear(year)}
                              disabled={
                                curriculumIds[year] === null ||
                                planMutations.save.isPending ||
                                planMutations.release.isPending
                              }
                            >
                              Generate subjects for {yearLabel(year)}
                            </Button>
                          </Alert>
                        </div>
                      ) : hasMajorships && selectedMajorId !== "all" ? (
                        <div className="grid gap-4">
                          {(() => {
                            const selectedProg = availablePrograms.find(
                              (p) => p.id === selectedMajorId,
                            )
                            if (!selectedProg) return null
                            const curId =
                              curriculaByYearAndProgram[`${year}_${selectedProg.id}`] ??
                              curriculumIds[year]
                            return (
                              <>
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-2">
                                  <div className="flex items-center gap-2">
                                    <h3 className="text-base font-semibold text-foreground">
                                      {selectedProg.name} ({selectedProg.code})
                                    </h3>
                                    <Badge variant="secondary" className="text-xs">
                                      {displayedGroups.length} block section{displayedGroups.length === 1 ? "" : "s"}
                                    </Badge>
                                  </div>
                                  <div className="flex items-center gap-2">
                                    <Button
                                      type="button"
                                      size="sm"
                                      variant="outline"
                                      onClick={() => void removeSectionForProgram(selectedProg.id, curId, displayedGroups.length)}
                                      disabled={
                                        approvalLocked ||
                                        isSwitchingCurriculum ||
                                        displayedGroups.length <= 0 ||
                                        planMutations.save.isPending ||
                                        planMutations.release.isPending
                                      }
                                    >
                                      <MinusIcon data-icon="inline-start" />
                                      Remove section
                                    </Button>
                                    <Button
                                      type="button"
                                      size="sm"
                                      variant="outline"
                                      onClick={() => void addSectionForProgram(selectedProg.id, curId, displayedGroups.length)}
                                      disabled={
                                        approvalLocked ||
                                        isSwitchingCurriculum ||
                                        planMutations.save.isPending ||
                                        planMutations.release.isPending
                                      }
                                    >
                                      <PlusIcon data-icon="inline-start" />
                                      Add section
                                    </Button>
                                  </div>
                                </div>
                                <MajorshipCurriculumBar
                                program={selectedProg}
                                yearLevel={currentYearNum}
                                curricula={curriculaQuery.data ?? []}
                                selectedCurriculumId={curId}
                                onCurriculumChange={(newCurId) =>
                                  void handleCurriculumChange(
                                    currentYearNum,
                                    newCurId,
                                    selectedProg.id,
                                  )
                                }
                                disabled={approvalLocked || isSwitchingCurriculum}
                                isSwitching={isSwitchingCurriculum}
                                newestIdByProgram={newestCurriculumIdByProgram}
                              />
                            </>
                          )
                          })()}

                          {displayedGroups.length === 0 ? (
                            <Alert>
                              <AlertDescription>
                                No generated block sections for this majorship in {yearLabel(year)}.
                              </AlertDescription>
                            </Alert>
                          ) : (
                            <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                              {displayedGroups.map((block) => (
                                <BlockSectionCard
                                  key={block.blockCode}
                                  blockCode={block.blockCode}
                                  sections={block.sections}
                                  year={currentYearNum}
                                  curriculum={block.curriculum}
                                  program={block.program}
                                  unitsFor={unitsFor}
                                  setCalendarSection={setCalendarSection}
                                />
                              ))}
                            </div>
                          )}
                        </div>
                      ) : hasMajorships && groupsByProgram.length > 1 ? (
                        <div className="grid gap-6">
                          {groupsByProgram.map(({ program, groups }) => {
                            const curId =
                              curriculaByYearAndProgram[`${year}_${program.id}`] ??
                              curriculumIds[year]
                            return (
                              <div key={program.id} className="grid gap-3 pt-2">
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-2">
                                  <div className="flex items-center gap-2">
                                    <h3 className="text-base font-semibold text-foreground">
                                      {program.name} ({program.code})
                                    </h3>
                                    <Badge variant="secondary" className="text-xs">
                                      {groups.length} block section{groups.length === 1 ? "" : "s"}
                                    </Badge>
                                  </div>
                                  <div className="flex items-center gap-2">
                                    <Button
                                      type="button"
                                      size="sm"
                                      variant="outline"
                                      onClick={() => void removeSectionForProgram(program.id, curId, groups.length)}
                                      disabled={
                                        approvalLocked ||
                                        isSwitchingCurriculum ||
                                        groups.length <= 0 ||
                                        planMutations.save.isPending ||
                                        planMutations.release.isPending
                                      }
                                    >
                                      <MinusIcon data-icon="inline-start" />
                                      Remove section
                                    </Button>
                                    <Button
                                      type="button"
                                      size="sm"
                                      variant="outline"
                                      onClick={() => void addSectionForProgram(program.id, curId, groups.length)}
                                      disabled={
                                        approvalLocked ||
                                        isSwitchingCurriculum ||
                                        planMutations.save.isPending ||
                                        planMutations.release.isPending
                                      }
                                    >
                                      <PlusIcon data-icon="inline-start" />
                                      Add section
                                    </Button>
                                  </div>
                                </div>

                                <MajorshipCurriculumBar
                                  program={program}
                                  yearLevel={currentYearNum}
                                  curricula={curriculaQuery.data ?? []}
                                  selectedCurriculumId={curId}
                                  onCurriculumChange={(newCurId) =>
                                    void handleCurriculumChange(
                                      currentYearNum,
                                      newCurId,
                                      program.id,
                                    )
                                  }
                                  disabled={approvalLocked || isSwitchingCurriculum}
                                  isSwitching={isSwitchingCurriculum}
                                  newestIdByProgram={newestCurriculumIdByProgram}
                                />

                                <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                                  {groups.map((block) => (
                                    <BlockSectionCard
                                      key={block.blockCode}
                                      blockCode={block.blockCode}
                                      sections={block.sections}
                                      year={currentYearNum}
                                      curriculum={block.curriculum}
                                      program={block.program}
                                      unitsFor={unitsFor}
                                      setCalendarSection={setCalendarSection}
                                    />
                                  ))}
                                </div>
                              </div>
                            )
                          })}
                        </div>
                      ) : (
                        <div className="grid gap-4">
                          {availablePrograms[0] && (
                            <MajorshipCurriculumBar
                              program={availablePrograms[0]}
                              yearLevel={currentYearNum}
                              curricula={curriculaQuery.data ?? []}
                              selectedCurriculumId={
                                curriculaByYearAndProgram[
                                  `${year}_${availablePrograms[0].id}`
                                ] ?? curriculumIds[year]
                              }
                              onCurriculumChange={(newCurId) =>
                                void handleCurriculumChange(
                                  currentYearNum,
                                  newCurId,
                                  availablePrograms[0].id,
                                )
                              }
                              disabled={approvalLocked || isSwitchingCurriculum}
                              isSwitching={isSwitchingCurriculum}
                              newestIdByProgram={newestCurriculumIdByProgram}
                            />
                          )}

                          <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                            {displayedGroups.map((block) => (
                              <BlockSectionCard
                                key={block.blockCode}
                                blockCode={block.blockCode}
                                sections={block.sections}
                                year={currentYearNum}
                                curriculum={block.curriculum}
                                program={block.program}
                                unitsFor={unitsFor}
                                setCalendarSection={setCalendarSection}
                              />
                            ))}
                          </div>
                        </div>
                      )}
                    </TabsContent>
                  )
                })}
                <div className="grid gap-3">
                  {incompleteScheduleCount > 0 && (
                    <Alert>
                      <AlertDescription>
                        {`${incompleteScheduleCount} schedule assignment${incompleteScheduleCount === 1 ? "" : "s"} remaining will be included for Dean and Executive Director review.`}
                      </AlertDescription>
                    </Alert>
                  )}
                  {submitError && (
                    <Alert variant="destructive">
                      <AlertDescription>{submitError}</AlertDescription>
                    </Alert>
                  )}
                  <div className="flex flex-wrap justify-end gap-2">
                    {!approvalLocked && (
                      <Button
                        type="button"
                        onClick={() => {
                          setSubmitError("")
                          setConfirmSubmit(true)
                        }}
                        disabled={
                          visibleSections.length === 0 ||
                          planMutations.submit.isPending
                        }
                      >
                        Submit for Dean and Executive Director Approval
                      </Button>
                    )}
                  </div>
                </div>
              </Tabs>
            )}
          </CardContent>
        </Card>
      )}

      <DemandForecastDialog
        open={forecastOpen}
        onOpenChange={setForecastOpen}
        run={generationRun}
        loading={latestGenerationRunQuery.isPending}
        error={latestGenerationRunQuery.isError}
        onRetry={() => void latestGenerationRunQuery.refetch()}
        sections={visibleSections}
        plans={plansQuery.data ?? []}
        subjects={subjectsQuery.data ?? []}
      />

      <Dialog
        open={editingSection !== null}
        onOpenChange={(open) => {
          if (!open) {
            setEditingSection(null)
            setScheduleError("")
          }
        }}
      >
        <DialogContent className="max-h-[100dvh] overflow-y-auto rounded-none sm:max-h-[90dvh] sm:max-w-3xl sm:rounded-xl">
          <DialogHeader>
            <DialogTitle>
              Schedule assignment · {selected?.section_code ?? "Block"}
            </DialogTitle>
            <DialogDescription>
              {selected
                ? `${subjectFor(selected.subject_id)?.code ?? "Subject"} · Sched ID ${selected.id}`
                : "Choose faculty and meeting details for this subject."}
            </DialogDescription>
          </DialogHeader>
          {selected && (
            <FieldGroup className="grid gap-3 sm:grid-cols-2">
              <Field className="sm:col-span-2">
                <FieldLabel htmlFor="schedule-professor">Professor</FieldLabel>
                <SearchableCombobox
                  id="schedule-professor"
                  label="Professor"
                  options={availableFaculty.map((member) => ({
                    value: String(member.id),
                    label: member.name,
                  }))}
                  value={scheduleDraft.professor_id}
                  onValueChange={(value) =>
                    setScheduleDraft({ ...scheduleDraft, professor_id: value })
                  }
                  placeholder="Search professor"
                  emptyMessage="No matching professor."
                />
              </Field>

              <div className="sm:col-span-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setRoomPickerOpen(true)}
                  className="w-full"
                >
                  <CalendarDays data-icon="inline-start" aria-hidden="true" />
                  {scheduleDraft.room
                    ? "Change room & schedule on room calendar"
                    : "Select a room & pick time on room calendar"}
                </Button>
              </div>

              <Field>
                <FieldLabel htmlFor="schedule-day">Day</FieldLabel>
                <Select
                  value={scheduleDraft.day}
                  onOpenChange={(open) => {
                    if (open) {
                      setScheduleError("")
                    }
                  }}
                  onValueChange={(value) =>
                    setScheduleDraft({ ...scheduleDraft, day: value })
                  }
                >
                  <SelectTrigger id="schedule-day">
                    <SelectValue placeholder="Select day" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      {days.map((day) => (
                        <SelectItem key={day} value={day}>
                          {day}
                        </SelectItem>
                      ))}
                      {scheduleDraft.day &&
                        !days.some(
                          (opt) =>
                            opt.toLowerCase() ===
                            scheduleDraft.day.toLowerCase(),
                        ) && (
                          <SelectItem
                            key={scheduleDraft.day}
                            value={scheduleDraft.day}
                          >
                            {scheduleDraft.day}
                          </SelectItem>
                        )}
                    </SelectGroup>
                  </SelectContent>
                </Select>
              </Field>
              <Field data-invalid={hasInvalidTimeOrder}>
                <FieldLabel htmlFor="schedule-start">Start time</FieldLabel>
                <Input
                  id="schedule-start"
                  type="time"
                  value={scheduleDraft.start}
                  aria-invalid={hasInvalidTimeOrder}
                  onChange={(event) =>
                    setScheduleDraft({
                      ...scheduleDraft,
                      start: event.target.value,
                    })
                  }
                />
              </Field>
              <Field data-invalid={hasInvalidTimeOrder}>
                <FieldLabel htmlFor="schedule-end">End time</FieldLabel>
                <Input
                  id="schedule-end"
                  type="time"
                  value={scheduleDraft.end}
                  aria-invalid={hasInvalidTimeOrder}
                  onChange={(event) =>
                    setScheduleDraft({
                      ...scheduleDraft,
                      end: event.target.value,
                    })
                  }
                />
                {hasInvalidTimeOrder && (
                  <FieldError>End time must be after start time.</FieldError>
                )}
              </Field>
              <Field>
                <FieldLabel htmlFor="schedule-room">Room</FieldLabel>
                <SearchableCombobox
                  id="schedule-room"
                  label="Room"
                  options={roomOptions.map((room) => ({
                    value: room.name,
                    label: room.name,
                  }))}
                  value={scheduleDraft.room}
                  onValueChange={(value) =>
                    setScheduleDraft({ ...scheduleDraft, room: value })
                  }
                  placeholder="Search room"
                  emptyMessage="No matching room."
                  disabled={roomsQuery.isPending && roomOptions.length === 0}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="schedule-modality">Modality</FieldLabel>
                <Select
                  value={scheduleDraft.modality}
                  onValueChange={(value) =>
                    setScheduleDraft({ ...scheduleDraft, modality: value })
                  }
                >
                  <SelectTrigger id="schedule-modality">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      {Object.entries(modalityLabels).map(([value, label]) => (
                        <SelectItem key={value} value={value}>
                          {label}
                        </SelectItem>
                      ))}
                    </SelectGroup>
                  </SelectContent>
                </Select>
              </Field>

              <Field
                data-invalid={scheduleDraft.capacity < selected.enrolled_count}
              >
                <FieldLabel htmlFor="schedule-capacity">
                  Seats in this section
                </FieldLabel>
                <Input
                  id="schedule-capacity"
                  type="number"
                  min={Math.max(1, selected.enrolled_count)}
                  max={300}
                  value={scheduleDraft.capacity}
                  aria-invalid={
                    scheduleDraft.capacity < selected.enrolled_count
                  }
                  onChange={(event) =>
                    setScheduleDraft({
                      ...scheduleDraft,
                      capacity: Number(event.target.value),
                    })
                  }
                />
                {scheduleDraft.capacity < selected.enrolled_count ? (
                  <FieldError>
                    {selected.enrolled_count} students are already enrolled
                    here. Set at least {selected.enrolled_count} seats.
                  </FieldError>
                ) : (
                  <FieldDescription>
                    Changing this overrides the year-level default for this
                    section only.
                  </FieldDescription>
                )}
              </Field>
              {selected.recommendation_prediction_run_id !== null && (
                <Field className="sm:col-span-2">
                  <FieldLabel htmlFor="schedule-override-reason">
                    Override reason
                  </FieldLabel>
                  <Textarea
                    id="schedule-override-reason"
                    value={scheduleDraft.override_reason}
                    onChange={(event) =>
                      setScheduleDraft({
                        ...scheduleDraft,
                        override_reason: event.target.value,
                      })
                    }
                    placeholder="Required when changing this AI-generated recommendation."
                  />
                  <FieldDescription>
                    The original recommendation remains in the audit trail.
                  </FieldDescription>
                </Field>
              )}
            </FieldGroup>
          )}
          {scheduleError && (
            <Alert variant="destructive">
              <AlertDescription>{scheduleError}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setEditingSection(null)}
            >
              Cancel
            </Button>
            <Button type="button" onClick={() => void saveSchedule()}>
              Save schedule
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={confirmSubmit} onOpenChange={setConfirmSubmit}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Submit this schedule for approval?
            </AlertDialogTitle>
            <AlertDialogDescription>
              The schedule proposal can be submitted with unassigned
              professors, rooms, or timetable schedules. These incomplete
              assignments will pass into submission and remain visible for Dean
              and Executive Director review.
              {incompleteScheduleCount > 0
                ? ` (${incompleteScheduleCount} incomplete schedule assignment${incompleteScheduleCount === 1 ? "" : "s"} currently recorded).`
                : ""}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <Button
              type="button"
              onClick={() => void submit()}
              disabled={planMutations.submit.isPending}
            >
              {planMutations.submit.isPending
                ? "Submitting…"
                : "Confirm submission"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <SectionScheduleCalendarDialog
        open={calendarSection !== null}
        onOpenChange={(open) => !open && setCalendarSection(null)}
        title={`${calendarSection?.blockCode ?? "Section"} Schedule`}
        subtitle={`${yearLabel(calendarSection?.year ?? 1)} Block Section · ${calendarItems.length} subjects`}
        items={calendarItems}
        disabled={approvalLocked}
        actionLabel="Assign schedule"
        onSelectSubject={(item) => {
          const targetSection =
            (sectionsQuery.data ?? []).find((s) => s.id === item.id) ??
            calendarSection?.sections.find((s) => s.id === item.id)
          if (targetSection) {
            setCalendarSection(null)
            openEdit(targetSection)
          }
        }}
      />

      <RoomScheduleAssignmentDialog
        open={roomPickerOpen}
        onOpenChange={setRoomPickerOpen}
        termId={termId}
        initialRoom={scheduleDraft.room || null}
        excludeSectionId={selected?.id}
        sectionCode={selected?.section_code}
        sectionScheduleItems={currentSectionScheduledItems}
        onConfirm={applyPickedSchedule}
      />
    </WorkspacePage>
  )
}
