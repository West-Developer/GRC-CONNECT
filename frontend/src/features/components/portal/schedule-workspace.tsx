"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { CalendarDays, DoorOpen } from "lucide-react"
import { useMemo, useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AcademicTermSelector } from "@/features/components/portal/academic-term-selector"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { CurriculumPill } from "@/features/components/portal/curriculum-pill"
import { SectionScheduleCalendarDialog } from "@/features/components/portal/section-schedule-calendar-dialog"
import type { SectionScheduleItem } from "@/features/components/portal/section-schedule-calendar"
import {
  RoomScheduleAssignmentDialog,
  type RoomScheduleAssignmentResult,
} from "@/features/components/portal/room-schedule-assignment-dialog"
import { LectureLabAdjacencyAlert } from "@/features/components/portal/lecture-lab-adjacency-alert"
import { SectionChangeRequestsPanel } from "@/features/components/portal/section-change-requests-panel"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
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
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
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
import { useAcademicTermSelection } from "@/features/hooks/use-academic-term-selection"
import {
  useCurriculaQuery,
  useProgramsQuery,
  useSectionsQuery,
  useSubjectsQuery,
  sectionsQueryKey,
} from "@/features/hooks/use-reference-data"
import {
  useCreateSectionChangeRequestMutation,
  useSectionChangeRequestsQuery,
} from "@/features/hooks/use-section-change-requests"
import { useSectionPlansQuery } from "@/features/hooks/use-section-plans"
import { buildSectionChangeFields } from "@/features/lib/section-change-diff"
import {
  findCurriculumForSection,
  findProgramForSection,
  getProgramShortLabel,
} from "@/features/lib/program-major-utils"
import {
  replaceSection,
  toSectionReplacement,
} from "@/features/services/scheduling-service"
import { isApiClientError } from "@/features/services/api-client"
import type {
  Curriculum,
  Program,
  Section,
} from "@/features/schemas/reference-data-schema"

const years = [1, 2, 3, 4] as const

function yearLabel(year: number) {
  return `${year}${year === 1 ? "st" : year === 2 ? "nd" : year === 3 ? "rd" : "th"} Year`
}

const asTime = (value: string) => (value ? `${value}:00`.slice(0, 8) : "")

function curriculumAgeLabel(
  curriculum: Curriculum | undefined,
  newestIdByProgram: Map<number, number>,
) {
  if (!curriculum) return ""
  return newestIdByProgram.get(curriculum.program_id) === curriculum.id
    ? "New curriculum"
    : "Old curriculum"
}

function sectionSaveErrorMessages(error: unknown): readonly string[] {
  if (isApiClientError(error)) {
    const fieldErrors = Object.values(error.fieldErrors ?? {}).flat()
    if (fieldErrors.length > 0) return fieldErrors
    return [error.message]
  }

  if (error instanceof Error) return [error.message]

  return ["The section assignment could not be saved. Try again."]
}

function ScheduleBlockCard({
  blockCode,
  sections,
  year,
  curriculum,
  program,
  unitsFor,
  setCalendarSection,
  newestCurriculumIdByProgram,
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
  newestCurriculumIdByProgram?: Map<number, number>
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
            <Badge
              variant="outline"
              className="text-[10px] text-muted-foreground"
            >
              {program.code}
            </Badge>
          )}
        </div>

        {curriculum && (
          <CurriculumPill
            name={curriculum.name}
            suffix={
              newestCurriculumIdByProgram
                ? curriculumAgeLabel(curriculum, newestCurriculumIdByProgram)
                : undefined
            }
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

export function ScheduleWorkspace() {
  const queryClient = useQueryClient()
  const { session } = useAuth()
  const termSelection = useAcademicTermSelection()
  const { term, termId, sortedTerms, isCurrentTerm, setSelectedTermId } =
    termSelection
  const sectionsQuery = useSectionsQuery()
  const subjectsQuery = useSubjectsQuery()
  const facultyQuery = useFacultyDirectoryQuery()
  const preferencesQuery = useFacultySubjectPreferencesQuery()
  const curriculaQuery = useCurriculaQuery()
  const programsQuery = useProgramsQuery()
  const plansQuery = useSectionPlansQuery(termId, term !== null)
  const changeRequestsQuery = useSectionChangeRequestsQuery()
  const createChangeRequest = useCreateSectionChangeRequestMutation()
  const [activeYear, setActiveYear] = useState("1")
  const [selectedMajorId, setSelectedMajorId] = useState<"all" | number>("all")
  const [editing, setEditing] = useState<Section | null>(null)
  const [pickerOpen, setPickerOpen] = useState(false)
  const [requestReason, setRequestReason] = useState("")
  const [calendarSection, setCalendarSection] = useState<{
    blockCode: string
    year: number
    sections: Section[]
  } | null>(null)

  const [draft, setDraft] = useState({
    professor_id: "",
    schedule_days: "",
    starts_at_time: "",
    ends_at_time: "",
    room: "",
    modality: "f2f" as "f2f" | "hyflex_a" | "hyflex_b",
    capacity: 40,
    override_reason: "",
  })

  // Memoized so the block-grouping memo below survives 5s schedule polls
  // (and so the React Compiler can optimize this component at all).
  const currentSections = useMemo(
    () =>
      (sectionsQuery.data ?? []).filter(
        (section) =>
          section.academic_term_id === termId &&
          !/^[1-4][A-Z]$/u.test(section.section_code),
      ),
    [sectionsQuery.data, termId],
  )

  const availablePrograms = useMemo(() => {
    const progs = programsQuery.data ?? []
    if (progs.length > 0) return progs
    const programMap = new Map<
      number,
      { id: number; code: string; name: string }
    >()
    for (const cur of curriculaQuery.data ?? []) {
      if (!programMap.has(cur.program_id)) {
        programMap.set(cur.program_id, {
          id: cur.program_id,
          code:
            cur.name.replace(/\s*(Curriculum|\d{4}-\d{4}).*$/iu, "").trim() ||
            `Program ${cur.program_id}`,
          name:
            cur.name.replace(/\s*(Curriculum|\d{4}-\d{4}).*$/iu, "").trim() ||
            cur.name,
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

  const newestCurriculumIdByProgram = useMemo(() => {
    const map = new Map<number, number>()
    const sorted = [...(curriculaQuery.data ?? [])].sort(
      (left, right) =>
        right.effective_school_year.localeCompare(left.effective_school_year) ||
        right.id - left.id,
    )
    for (const curriculum of sorted) {
      if (!map.has(curriculum.program_id)) {
        map.set(curriculum.program_id, curriculum.id)
      }
    }
    return map
  }, [curriculaQuery.data])

  const planYearById = useMemo(
    () =>
      new Map(
        (plansQuery.data ?? []).map((plan) => [
          plan.id,
          String(plan.year_level),
        ]),
      ),
    [plansQuery.data],
  )

  const planCurriculumById = useMemo(
    () =>
      new Map(
        (plansQuery.data ?? []).map((plan) => [plan.id, plan.curriculum_id]),
      ),
    [plansQuery.data],
  )

  const selectedCurriculumForYear = (year: number) => {
    const plan = (plansQuery.data ?? []).find(
      (item) => item.year_level === year && item.section_count > 0,
    )
    if (plan?.curriculum_id) {
      return (curriculaQuery.data ?? []).find(
        (c) => c.id === plan.curriculum_id,
      )
    }
    // Fallback: look at first section in this year
    const sampleSection = currentSections.find(
      (s) => (planYearById.get(s.section_plan_id ?? -1) ?? "") === String(year),
    )
    if (sampleSection?.section_plan_id) {
      const curId = planCurriculumById.get(sampleSection.section_plan_id)
      if (curId) {
        return (curriculaQuery.data ?? []).find((c) => c.id === curId)
      }
    }
    return (curriculaQuery.data ?? [])[0]
  }

  const subjects = useMemo(
    () => (curriculaQuery.data ?? []).flatMap((c) => c.subjects),
    [curriculaQuery.data],
  )

  const subjectMap = useMemo(
    () =>
      new Map(
        (subjectsQuery.data ?? []).map((subject) => [subject.id, subject]),
      ),
    [subjectsQuery.data],
  )

  const subjectFor = (subjectId: number) =>
    subjects.find((subject) => subject.subject_id === subjectId) ??
    subjectMap.get(subjectId)

  const unitsFor = (subjectId: number) =>
    subjectFor(subjectId)?.units ?? subjectMap.get(subjectId)?.units ?? "—"

  const facultyNameFor = (professorId: number | null) =>
    facultyQuery.data?.find((member) => member.id === professorId)?.name

  const groupedByYear = useMemo(() => {
    const selectedYear = Number(activeYear)
    const groups = new Map<string, Section[]>()
    currentSections
      .filter((section) => {
        const planYear = planYearById.get(section.section_plan_id ?? -1)
        if (planYear) return planYear === activeYear
        const match = /(\d)\d{2}$/u.exec(section.section_code)
        return match ? Number(match[1]) === selectedYear : true
      })
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
    currentSections,
    activeYear,
    planYearById,
    plansQuery.data,
    curriculaQuery.data,
    availablePrograms,
  ])

  const displayedGroups = useMemo(() => {
    if (selectedMajorId === "all") return groupedByYear
    return groupedByYear.filter((g) => g.program?.id === selectedMajorId)
  }, [groupedByYear, selectedMajorId])

  const groupsByProgram = useMemo(() => {
    const map = new Map<
      number,
      {
        program: Program | { id: number; code: string; name: string }
        groups: typeof groupedByYear
      }
    >()

    for (const block of groupedByYear) {
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
  }, [groupedByYear])

  const calendarItems: SectionScheduleItem[] = useMemo(() => {
    if (!calendarSection) return []
    const allSecs = sectionsQuery.data ?? []
    return calendarSection.sections.map((sec) => {
      const section =
        allSecs.find((candidate) => candidate.id === sec.id) ?? sec
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
    facultyQuery.data,
    subjectMap,
  ])

  const currentSectionScheduledItems: SectionScheduleItem[] = useMemo(() => {
    if (!editing) return []
    const allSections = sectionsQuery.data ?? []
    return allSections
      .filter(
        (s) =>
          s.section_code === editing.section_code &&
          s.academic_term_id === editing.academic_term_id &&
          s.id !== editing.id &&
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
  }, [editing, sectionsQuery.data, subjects, facultyQuery.data, subjectMap])

  const capableIds = useMemo(() => {
    if (!editing) return []
    return (preferencesQuery.data ?? [])
      .filter(
        (pref) =>
          pref.subject_id === editing.subject_id &&
          (pref.rank === 1 || pref.rank === 2),
      )
      .map((pref) => pref.professor_id)
  }, [editing, preferencesQuery.data])

  const availableFaculty = useMemo(() => {
    const list = facultyQuery.data ?? []
    return [...list].sort((left, right) => {
      const leftPref = capableIds.includes(left.id)
      const rightPref = capableIds.includes(right.id)
      if (leftPref !== rightPref) return leftPref ? -1 : 1
      return left.name.localeCompare(right.name)
    })
  }, [facultyQuery.data, capableIds])

  const saveSection = useMutation({
    mutationFn: async () => {
      if (!editing) throw new Error("Choose a section to edit.")
      return replaceSection(
        editing.id,
        toSectionReplacement(editing, {
          professor_id: draft.professor_id ? Number(draft.professor_id) : null,
          schedule_days: draft.schedule_days,
          starts_at_time: draft.starts_at_time
            ? asTime(draft.starts_at_time)
            : "",
          ends_at_time: draft.ends_at_time ? asTime(draft.ends_at_time) : "",
          room: draft.room,
          modality: draft.modality,
          capacity: Number(draft.capacity),
          override_reason: draft.override_reason || undefined,
        }),
      )
    },
    onSuccess: async () => {
      setEditing(null)
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: sectionsQueryKey(session?.userId ?? null),
          exact: true,
        }),
        queryClient.invalidateQueries({
          queryKey: ["room-occupancy"],
        }),
        queryClient.invalidateQueries({
          queryKey: ["section-plans"],
        }),
        queryClient.invalidateQueries({
          queryKey: ["rooms"],
        }),
      ])
      await sectionsQuery.refetch()
    },
  })

  // A published section is final (ADR 0032): the professor can still be swapped
  // directly, everything else goes to the Registrar Head as a change request.
  const saveProfessorOnly = useMutation({
    mutationFn: async () => {
      if (!editing) throw new Error("Choose a section to edit.")
      return replaceSection(
        editing.id,
        toSectionReplacement(editing, {
          professor_id: draft.professor_id ? Number(draft.professor_id) : null,
          override_reason: requestReason.trim() || undefined,
        }),
      )
    },
    onSuccess: async () => {
      setEditing(null)
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: sectionsQueryKey(session?.userId ?? null),
          exact: true,
        }),
        queryClient.invalidateQueries({ queryKey: ["rooms"] }),
      ])
    },
  })

  const sendChangeRequest = () => {
    if (!editing) return
    createChangeRequest.mutate(
      {
        sectionId: editing.id,
        input: {
          reason: requestReason,
          changes: buildSectionChangeFields(editing, draft),
        },
      },
      { onSuccess: () => setEditing(null) },
    )
  }

  const open = (section: Section) => {
    setEditing(section)
    setRequestReason("")
    saveSection.reset()
    saveProfessorOnly.reset()
    createChangeRequest.reset()
    setDraft({
      professor_id: section.professor_id ? String(section.professor_id) : "",
      schedule_days: section.schedule_days ?? "",
      starts_at_time: section.starts_at_time?.slice(0, 5) ?? "",
      ends_at_time: section.ends_at_time?.slice(0, 5) ?? "",
      room: section.room ?? "",
      modality: (section.modality as "f2f" | "hyflex_a" | "hyflex_b") ?? "f2f",
      capacity: section.capacity,
      override_reason: "",
    })
  }

  const applyPickedSchedule = (result: RoomScheduleAssignmentResult) => {
    setDraft((current) => ({
      ...current,
      schedule_days: result.scheduleDays,
      starts_at_time: result.startsAtTime,
      ends_at_time: result.endsAtTime,
      room: result.room,
      modality: result.modality,
    }))
  }

  const isPublishedEdit = editing?.status === "published"
  const professorChanged =
    editing !== null &&
    (draft.professor_id ? Number(draft.professor_id) : null) !==
      editing.professor_id
  const changedFields = editing
    ? Object.keys(buildSectionChangeFields(editing, draft))
    : []
  const editError =
    saveSection.error ?? saveProfessorOnly.error ?? createChangeRequest.error

  const query = {
    isPending:
      termSelection.termsQuery.isPending ||
      sectionsQuery.isPending ||
      subjectsQuery.isPending ||
      facultyQuery.isPending ||
      curriculaQuery.isPending ||
      plansQuery.isPending,
    isError:
      termSelection.termsQuery.isError ||
      sectionsQuery.isError ||
      subjectsQuery.isError ||
      facultyQuery.isError ||
      curriculaQuery.isError ||
      plansQuery.isError,
    error:
      termSelection.termsQuery.error ??
      sectionsQuery.error ??
      subjectsQuery.error ??
      facultyQuery.error ??
      curriculaQuery.error ??
      plansQuery.error,
    data: true as const,
    refetch: () => {
      void termSelection.termsQuery.refetch()
      void sectionsQuery.refetch()
      void subjectsQuery.refetch()
      void facultyQuery.refetch()
      void curriculaQuery.refetch()
      void plansQuery.refetch()
    },
  }

  const activeCurriculum = selectedCurriculumForYear(Number(activeYear))

  return (
    <WorkspacePage
      title="Schedule"
      description="Review and edit the generated section schedule and assignments for the selected term."
      lastUpdated={sectionsQuery.dataUpdatedAt}
    >
      <AsyncBoundary
        query={query}
        loadingLabel="Loading the generated schedule…"
      >
        {() => (
          <div className="grid gap-5">
            <AcademicTermSelector
              sortedTerms={sortedTerms}
              term={term}
              isCurrentTerm={isCurrentTerm}
              onSelectTerm={setSelectedTermId}
            />

            <LectureLabAdjacencyAlert termId={term?.id ?? null} />

            <Card>
              <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <CardTitle level={2}>
                      Generated schedule and assignments
                    </CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Faculty matching prioritizes declared subject preference,
                      availability, no conflict, then lower assigned units.
                    </p>
                  </div>
                  <Badge variant="outline">{currentSections.length} rows</Badge>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4">
                <Tabs value={activeYear} onValueChange={setActiveYear}>
                  <TabsList
                    aria-label="Generated section year filter"
                    className="h-auto w-full p-1 sm:w-fit"
                  >
                    {years.map((year) => {
                      const cur = selectedCurriculumForYear(year)
                      return (
                        <TabsTrigger
                          key={year}
                          value={String(year)}
                          aria-label={yearLabel(year)}
                          className="flex flex-col items-center justify-center gap-0.5 px-3.5 py-1.5 h-auto text-xs sm:text-sm"
                        >
                          <span className="font-semibold leading-tight">
                            {yearLabel(year)}
                          </span>
                          {cur && (
                            <span className="text-[10px] font-medium opacity-80 leading-tight">
                              {cur.effective_school_year}
                            </span>
                          )}
                        </TabsTrigger>
                      )
                    })}
                  </TabsList>

                  {/* Majorship Filter Bar below 1st..4th year tabs */}
                  {hasMajorships && (
                    <div className="mt-3 flex flex-wrap items-center gap-2 border-t pt-3">
                      <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mr-1">
                        Majorship:
                      </span>
                      <Button
                        type="button"
                        size="sm"
                        variant={
                          selectedMajorId === "all" ? "default" : "outline"
                        }
                        className="h-7 px-3 text-xs gap-1.5 rounded-full"
                        onClick={() => setSelectedMajorId("all")}
                      >
                        <span>All</span>
                        <Badge
                          variant={
                            selectedMajorId === "all" ? "secondary" : "outline"
                          }
                          className="text-[10px] px-1.5 py-0 h-4 min-w-4 flex items-center justify-center rounded-full"
                        >
                          {groupedByYear.length}
                        </Badge>
                      </Button>
                      {availablePrograms.map((prog) => {
                        const count = groupedByYear.filter(
                          (g) => g.program?.id === prog.id,
                        ).length
                        return (
                          <Button
                            key={prog.id}
                            type="button"
                            size="sm"
                            variant={
                              selectedMajorId === prog.id
                                ? "default"
                                : "outline"
                            }
                            className="h-7 px-3 text-xs gap-1.5 rounded-full"
                            onClick={() => setSelectedMajorId(prog.id)}
                          >
                            <span>{getProgramShortLabel(prog)}</span>
                            <Badge
                              variant={
                                selectedMajorId === prog.id
                                  ? "secondary"
                                  : "outline"
                              }
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
                      <TabsContent
                        key={year}
                        value={String(year)}
                        className="mt-3"
                      >
                        {groupedByYear.length === 0 ? (
                          <Alert>
                            <AlertDescription>
                              No generated schedule rows for {yearLabel(year)}.
                            </AlertDescription>
                          </Alert>
                        ) : hasMajorships && selectedMajorId !== "all" ? (
                          <div className="grid gap-4">
                            {(() => {
                              const selectedProg = availablePrograms.find(
                                (p) => p.id === selectedMajorId,
                              )
                              const cur =
                                displayedGroups[0]?.curriculum ??
                                (curriculaQuery.data ?? []).find(
                                  (c) => c.program_id === selectedMajorId,
                                ) ??
                                activeCurriculum
                              if (!selectedProg || !cur) return null
                              return (
                                <div className="my-1 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 px-4 py-2.5">
                                  <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <span className="font-medium text-foreground">
                                      Curriculum for {selectedProg.code} (
                                      {yearLabel(currentYearNum)}):
                                    </span>
                                    <span className="font-semibold text-primary">
                                      {cur.name}
                                    </span>
                                    <Badge
                                      variant="secondary"
                                      className="text-xs"
                                    >
                                      {curriculumAgeLabel(
                                        cur,
                                        newestCurriculumIdByProgram,
                                      )}
                                    </Badge>
                                    <Badge
                                      variant="outline"
                                      className="text-xs"
                                    >
                                      SY {cur.effective_school_year}
                                    </Badge>
                                  </div>
                                </div>
                              )
                            })()}

                            {displayedGroups.length === 0 ? (
                              <Alert>
                                <AlertDescription>
                                  No generated block sections for this majorship
                                  in {yearLabel(year)}.
                                </AlertDescription>
                              </Alert>
                            ) : (
                              <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                                {displayedGroups.map((block) => (
                                  <ScheduleBlockCard
                                    key={block.blockCode}
                                    blockCode={block.blockCode}
                                    sections={block.sections}
                                    year={currentYearNum}
                                    curriculum={block.curriculum}
                                    program={block.program}
                                    unitsFor={unitsFor}
                                    setCalendarSection={setCalendarSection}
                                    newestCurriculumIdByProgram={
                                      newestCurriculumIdByProgram
                                    }
                                  />
                                ))}
                              </div>
                            )}
                          </div>
                        ) : hasMajorships && groupsByProgram.length > 1 ? (
                          <div className="grid gap-6">
                            {groupsByProgram.map(({ program, groups }) => {
                              const progCurriculum =
                                groups[0]?.curriculum ??
                                (curriculaQuery.data ?? []).find(
                                  (c) => c.program_id === program.id,
                                ) ??
                                activeCurriculum
                              return (
                                <div
                                  key={program.id}
                                  className="grid gap-3 pt-2"
                                >
                                  <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-2">
                                    <div className="flex items-center gap-2">
                                      <h3 className="text-base font-semibold text-foreground">
                                        {program.name} ({program.code})
                                      </h3>
                                      <Badge
                                        variant="secondary"
                                        className="text-xs"
                                      >
                                        {groups.length} block section
                                        {groups.length === 1 ? "" : "s"}
                                      </Badge>
                                    </div>
                                  </div>

                                  {progCurriculum && (
                                    <div className="my-1 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 px-4 py-2.5">
                                      <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-medium text-foreground">
                                          Curriculum for {program.code} (
                                          {yearLabel(currentYearNum)}):
                                        </span>
                                        <span className="font-semibold text-primary">
                                          {progCurriculum.name}
                                        </span>
                                        <Badge
                                          variant="secondary"
                                          className="text-xs"
                                        >
                                          {curriculumAgeLabel(
                                            progCurriculum,
                                            newestCurriculumIdByProgram,
                                          )}
                                        </Badge>
                                        <Badge
                                          variant="outline"
                                          className="text-xs"
                                        >
                                          SY{" "}
                                          {progCurriculum.effective_school_year}
                                        </Badge>
                                      </div>
                                    </div>
                                  )}

                                  <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                                    {groups.map((block) => (
                                      <ScheduleBlockCard
                                        key={block.blockCode}
                                        blockCode={block.blockCode}
                                        sections={block.sections}
                                        year={currentYearNum}
                                        curriculum={block.curriculum}
                                        program={block.program}
                                        unitsFor={unitsFor}
                                        setCalendarSection={setCalendarSection}
                                        newestCurriculumIdByProgram={
                                          newestCurriculumIdByProgram
                                        }
                                      />
                                    ))}
                                  </div>
                                </div>
                              )
                            })}
                          </div>
                        ) : (
                          <div className="grid gap-4">
                            {activeCurriculum && (
                              <div className="my-1 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 px-4 py-2.5">
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                  <span className="font-medium text-foreground">
                                    Curriculum for {yearLabel(currentYearNum)}:
                                  </span>
                                  <span className="font-semibold text-primary">
                                    {activeCurriculum.name}
                                  </span>
                                  <Badge
                                    variant="secondary"
                                    className="text-xs"
                                  >
                                    {curriculumAgeLabel(
                                      activeCurriculum,
                                      newestCurriculumIdByProgram,
                                    )}
                                  </Badge>
                                  <Badge variant="outline" className="text-xs">
                                    SY {activeCurriculum.effective_school_year}
                                  </Badge>
                                </div>
                              </div>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                              {displayedGroups.map((block) => (
                                <ScheduleBlockCard
                                  key={block.blockCode}
                                  blockCode={block.blockCode}
                                  sections={block.sections}
                                  year={currentYearNum}
                                  curriculum={block.curriculum}
                                  program={block.program}
                                  unitsFor={unitsFor}
                                  setCalendarSection={setCalendarSection}
                                  newestCurriculumIdByProgram={
                                    newestCurriculumIdByProgram
                                  }
                                />
                              ))}
                            </div>
                          </div>
                        )}
                      </TabsContent>
                    )
                  })}
                </Tabs>
              </CardContent>
            </Card>
          </div>
        )}
      </AsyncBoundary>

      {(changeRequestsQuery.data?.length ?? 0) > 0 && (
        <SectionChangeRequestsPanel mode="requester" />
      )}

      <Dialog
        open={editing !== null}
        onOpenChange={(open) => !open && setEditing(null)}
      >
        <DialogContent className="max-h-[100dvh] overflow-y-auto rounded-none sm:max-h-[90dvh] sm:max-w-3xl sm:rounded-xl">
          <DialogHeader>
            <DialogTitle>
              {isPublishedEdit ? "Request a schedule change" : "Edit section assignment"}
            </DialogTitle>
            <DialogDescription>
              {editing
                ? `${subjectFor(editing.subject_id)?.code ?? "Subject"} · Sched ID ${editing.id}`
                : "Choose faculty and meeting details for this subject."}
            </DialogDescription>
          </DialogHeader>

          {isPublishedEdit && (
            <Alert>
              <AlertDescription>
                This section is published, so its schedule can only change with
                the Registrar Head&apos;s approval. Adjust the details and send a
                request. You can still change the professor directly.
              </AlertDescription>
            </Alert>
          )}

          {editing && (
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
                  value={draft.professor_id}
                  onValueChange={(value) =>
                    setDraft({ ...draft, professor_id: value })
                  }
                  placeholder="Search professor"
                  emptyMessage="No matching professor."
                />
              </Field>

              {draft.room ? (
                <>
                  <div className="sm:col-span-2">
                    <Button
                      type="button"
                      variant="outline"
                      aria-label="Pick room & schedule"
                      className="w-full justify-start gap-2"
                      onClick={() => setPickerOpen(true)}
                    >
                      <CalendarDays
                        className="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                      />
                      <span>
                        Room & schedule:{" "}
                        <strong className="text-foreground">
                          {draft.room}
                        </strong>{" "}
                        ·{" "}
                        <span className="text-primary font-semibold">
                          {draft.schedule_days}
                        </span>{" "}
                        {draft.starts_at_time}–{draft.ends_at_time} (
                        {draft.modality.toUpperCase()})
                      </span>
                      <span className="ml-auto text-xs text-muted-foreground underline">
                        Change on calendar
                      </span>
                    </Button>
                  </div>

                  <Field>
                    <FieldLabel htmlFor="schedule-day">
                      Schedule days
                    </FieldLabel>
                    <Input
                      id="schedule-day"
                      value={draft.schedule_days}
                      onChange={(event) =>
                        setDraft({
                          ...draft,
                          schedule_days: event.target.value,
                        })
                      }
                      placeholder="e.g. M, TTh, MWF"
                    />
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="schedule-room">Room</FieldLabel>
                    <Input
                      id="schedule-room"
                      value={draft.room}
                      onChange={(event) =>
                        setDraft({ ...draft, room: event.target.value })
                      }
                      placeholder="e.g. 3A, LAB 1"
                    />
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="schedule-starts">
                      Start time
                    </FieldLabel>
                    <Input
                      id="schedule-starts"
                      type="time"
                      value={draft.starts_at_time}
                      onChange={(event) =>
                        setDraft({
                          ...draft,
                          starts_at_time: event.target.value,
                        })
                      }
                    />
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="schedule-ends">End time</FieldLabel>
                    <Input
                      id="schedule-ends"
                      type="time"
                      value={draft.ends_at_time}
                      onChange={(event) =>
                        setDraft({ ...draft, ends_at_time: event.target.value })
                      }
                    />
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="schedule-modality">
                      Modality
                    </FieldLabel>
                    <Select
                      value={draft.modality}
                      onValueChange={(value) =>
                        setDraft({
                          ...draft,
                          modality: value as "f2f" | "hyflex_a" | "hyflex_b",
                        })
                      }
                    >
                      <SelectTrigger
                        id="schedule-modality"
                        aria-label="Schedule modality"
                      >
                        <SelectValue placeholder="Select modality" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectGroup>
                          <SelectItem value="f2f">
                            Face to Face (F2F)
                          </SelectItem>
                          <SelectItem value="hyflex_a">HyFlex A</SelectItem>
                          <SelectItem value="hyflex_b">HyFlex B</SelectItem>
                        </SelectGroup>
                      </SelectContent>
                    </Select>
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="schedule-capacity">Seats</FieldLabel>
                    <Input
                      id="schedule-capacity"
                      type="number"
                      min={1}
                      max={80}
                      value={draft.capacity}
                      onChange={(event) =>
                        setDraft({
                          ...draft,
                          capacity: Number(event.target.value),
                        })
                      }
                    />
                  </Field>
                </>
              ) : (
                <div className="sm:col-span-2">
                  <div
                    onClick={() => setPickerOpen(true)}
                    className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-primary/40 bg-primary/5 p-6 text-center transition-colors hover:border-primary hover:bg-primary/10"
                  >
                    <DoorOpen
                      className="size-8 text-primary"
                      aria-hidden="true"
                    />
                    <div>
                      <p className="font-semibold text-foreground">
                        Select a room
                      </p>
                      <p className="text-xs text-muted-foreground">
                        Browse room availability on the calendar and pick a
                        conflict-free time slot.
                      </p>
                    </div>
                    <Button type="button" size="sm" className="mt-1">
                      <CalendarDays className="size-4" aria-hidden="true" />
                      Room & schedule on calendar
                    </Button>
                  </div>
                </div>
              )}

              {isPublishedEdit ? (
                <Field className="sm:col-span-2">
                  <FieldLabel htmlFor="schedule-change-reason">
                    Reason for the change
                  </FieldLabel>
                  <Input
                    id="schedule-change-reason"
                    value={requestReason}
                    onChange={(event) => setRequestReason(event.target.value)}
                    placeholder="Tell the Registrar Head why this section must change"
                  />
                </Field>
              ) : (
                <Field className="sm:col-span-2">
                  <FieldLabel htmlFor="schedule-override">
                    Override reason
                  </FieldLabel>
                  <Input
                    id="schedule-override"
                    value={draft.override_reason}
                    onChange={(event) =>
                      setDraft({ ...draft, override_reason: event.target.value })
                    }
                    placeholder="Required when changing an established schedule"
                  />
                </Field>
              )}
            </FieldGroup>
          )}

          {editError !== null && (
            <Alert variant="destructive">
              <AlertDescription>
                {sectionSaveErrorMessages(editError).map(
                  (message, index) => (
                    <p key={`${message}-${index}`}>{message}</p>
                  ),
                )}
              </AlertDescription>
            </Alert>
          )}

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setEditing(null)}
            >
              Cancel
            </Button>
            {isPublishedEdit ? (
              <>
                {professorChanged && (
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => saveProfessorOnly.mutate()}
                    disabled={saveProfessorOnly.isPending}
                  >
                    {saveProfessorOnly.isPending ? "Saving…" : "Save professor only"}
                  </Button>
                )}
                <Button
                  type="button"
                  onClick={sendChangeRequest}
                  disabled={
                    createChangeRequest.isPending ||
                    changedFields.length === 0 ||
                    requestReason.trim().length < 3
                  }
                >
                  {createChangeRequest.isPending ? "Sending…" : "Send change request"}
                </Button>
              </>
            ) : (
              <Button
                type="button"
                onClick={() => saveSection.mutate()}
                disabled={saveSection.isPending}
              >
                {saveSection.isPending ? "Saving…" : "Save changes"}
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <SectionScheduleCalendarDialog
        open={calendarSection !== null}
        onOpenChange={(open) => !open && setCalendarSection(null)}
        title={`${calendarSection?.blockCode ?? "Section"} Schedule`}
        subtitle={`${yearLabel(calendarSection?.year ?? 1)} Block Section · ${calendarItems.length} subjects`}
        items={calendarItems}
        actionLabel={(item) => {
          const targetSection = (sectionsQuery.data ?? []).find(
            (s) => s.id === item.id,
          )
          const locked = targetSection?.status === "published"
          if (!isCurrentTerm) return "Archived"
          if (locked) return "Request change"
          return "Edit"
        }}
        isItemDisabled={() => !isCurrentTerm}
        onSelectSubject={(item) => {
          const targetSection =
            (sectionsQuery.data ?? []).find((s) => s.id === item.id) ??
            calendarSection?.sections.find((s) => s.id === item.id)
          if (targetSection) {
            open(targetSection)
          }
        }}
      />

      <RoomScheduleAssignmentDialog
        open={pickerOpen}
        onOpenChange={setPickerOpen}
        termId={termId}
        initialRoom={draft.room || null}
        excludeSectionId={editing?.id}
        sectionCode={editing?.section_code}
        sectionScheduleItems={currentSectionScheduledItems}
        onConfirm={applyPickedSchedule}
      />
    </WorkspacePage>
  )
}
