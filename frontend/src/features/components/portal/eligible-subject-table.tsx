"use client"

import { useMemo, useState } from "react"
import {
  CalendarDays,
  Clock,
  Info,
  Layers,
  ListIcon,
  MapPin,
  Plus,
  Search,
  User,
  Users,
} from "lucide-react"

import {
  DataTable,
  type DataTableColumn,
} from "@/features/components/portal/data-table"
import {
  SectionScheduleCalendar,
  type SectionScheduleItem,
} from "@/features/components/portal/section-schedule-calendar"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import {
  ToggleGroup,
  ToggleGroupItem,
} from "@/features/components/ui/toggle-group"
import {
  isAdvanceSubject,
  isBacklogSubject,
} from "@/features/lib/curriculum-ordinal"
import { formatTimeRange } from "@/features/lib/format-time"
import { groupPairedSubjects } from "@/features/lib/group-paired-subjects"
import {
  compareBySchedule,
  hasScheduleConflict,
} from "@/features/lib/schedule-order"
import {
  evaluateScheduleFit,
  generateScheduleRecommendation,
  type RecommendationMode,
} from "@/features/lib/schedule-recommendation"
import type { EligibleSubject } from "@/features/schemas/enrollment-schema"

type EligibleSection = EligibleSubject["available_sections"][number]

/**
 * Every other subject's currently chosen section, each tagged with the
 * subject's own code — the conflict check needs to know both what to
 * compare against and what to name in the "Conflicts with X" message.
 */
function otherSelectedSections(
  subjects: readonly EligibleSubject[],
  selections: Record<number, number>,
  excludeSubjectId: number,
  excludePairedSubjectId?: number | null,
): { section: EligibleSection; subjectCode: string }[] {
  const others: { section: EligibleSection; subjectCode: string }[] = []

  for (const subject of subjects) {
    if (
      subject.subject_id === excludeSubjectId ||
      (excludePairedSubjectId != null &&
        subject.subject_id === excludePairedSubjectId)
    ) {
      continue
    }
    const sectionId = selections[subject.subject_id]
    if (sectionId === undefined) continue
    const section = subject.available_sections.find(
      (candidate) => candidate.id === sectionId,
    )
    if (section) others.push({ section, subjectCode: subject.code })
  }

  return others
}

/** The other subject's code this option's day/time overlaps, or null when it's free to pick. */
function conflictingSubjectCode(
  option: EligibleSection,
  others: readonly { section: EligibleSection; subjectCode: string }[],
): string | null {
  const conflict = others.find((other) =>
    hasScheduleConflict(option, other.section),
  )
  return conflict?.subjectCode ?? null
}

function collegeLabel(college: string | null): string {
  return college === null ? "Other department" : college.toUpperCase()
}

function seatsLabel(section: EligibleSection): string {
  return `${section.remaining_seats} seat${section.remaining_seats === 1 ? "" : "s"} open`
}

/**
 * Once a section is actually chosen, the closed trigger shows only the
 * section code and seat count — not its schedule, which would just repeat
 * the adjacent "Schedule" column, nor the cross-department course name,
 * already carried by the badge rendered below the picker. The open dropdown
 * (`SelectItem`, below) still shows the full detail: that's the one place a
 * student is actively comparing sections against each other, so schedule
 * and cross-department context both stay load-bearing there.
 */
function triggerLabel(section: EligibleSection): string {
  return `Section ${section.section_code} · ${seatsLabel(section)}`
}

function scheduleLabel(section: EligibleSection | undefined): string {
  if (!section) return "Not selected"
  if (
    !section.schedule_days ||
    !section.starts_at_time ||
    !section.ends_at_time
  )
    return "To be confirmed"
  return `${section.schedule_days} · ${formatTimeRange(section.starts_at_time, section.ends_at_time)}`
}

function selectedSectionOf(
  subject: EligibleSubject,
  selections: Record<number, number>,
): EligibleSection | undefined {
  const sectionId = selections[subject.subject_id]
  return subject.available_sections.find((section) => section.id === sectionId)
}

function columns(
  subjects: readonly EligibleSubject[],
  selections: Record<number, number>,
  onChoose: (subjectId: number, sectionId: number) => void,
  onClear: (subjectId: number) => void,
  onRemove: (subjectId: number) => void,
  disabled: boolean,
  isBacklog: (subject: EligibleSubject) => boolean,
  isAdvance: (subject: EligibleSubject) => boolean,
  pairOf: (subject: EligibleSubject) => EligibleSubject | null,
  recommendedSections: Record<number, number> = {},
): DataTableColumn<EligibleSubject>[] {
  return [
    {
      key: "subject",
      header: "Subject",
      render: (subject) => {
        const paired = pairOf(subject)
        const pairedCode = paired?.code ?? null

        return (
          <div>
            <div className="flex items-center gap-1.5">
              <span className="font-medium">{subject.code}</span>
              {isBacklog(subject) && <Badge variant="warning">Backlog</Badge>}
              {isAdvance(subject) && <Badge variant="outline">Next year</Badge>}
              {pairedCode && (
                <Badge variant="secondary">Paired with {pairedCode}</Badge>
              )}
            </div>
            <div className="text-muted-foreground">{subject.title}</div>
          </div>
        )
      },
    },
    {
      key: "units",
      header: "Units",
      render: (subject) => subject.units,
    },
    {
      key: "section",
      header: "Section",
      render: (subject) => {
        const selectedSectionId = selections[subject.subject_id]
        const selectedSection = selectedSectionOf(subject, selections)
        const recommendedSectionId = recommendedSections[subject.subject_id]
        const isRecommendedSelected =
          selectedSection !== undefined &&
          recommendedSectionId !== undefined &&
          selectedSection.id === recommendedSectionId
        const paired = pairOf(subject)
        const others = otherSelectedSections(
          subjects,
          selections,
          subject.subject_id,
          paired?.subject_id,
        )
        const othersSections = others.map((o) => o.section)

        const pairedOptionFor = (
          option: EligibleSection,
        ): EligibleSection | undefined =>
          paired?.available_sections.find(
            (p) => p.section_code === option.section_code,
          )

        // Stakeholder Doc 17: a manual choice that would spread the
        // student's schedule beyond 1-2 days, or across more than one
        // time-of-day block, is disabled with a reason — unless it is the
        // only remaining option for this subject, in which case it stays
        // enabled with a non-blocking warning instead, so no student is
        // ever left with zero way to complete their schedule. This is a
        // narrow, explicit exception to the advisory-only design described
        // in SchedulePreferenceScorer.
        const fitsSchedule = (option: EligibleSection): boolean => {
          const matchingPaired = pairedOptionFor(option)
          const combined = matchingPaired
            ? [...othersSections, option, matchingPaired]
            : [...othersSections, option]
          return !evaluateScheduleFit(combined).violatesFit
        }
        const nonConflictingOptions = subject.available_sections.filter(
          (option) => {
            if (conflictingSubjectCode(option, others) !== null) return false
            const matchingPaired = pairedOptionFor(option)
            if (
              matchingPaired &&
              conflictingSubjectCode(matchingPaired, others) !== null
            ) {
              return false
            }
            return true
          },
        )
        const hasFittingAlternative = nonConflictingOptions.some(fitsSchedule)

        return (
          <div className="grid gap-2">
            <Select
              value={selectedSectionId ? String(selectedSectionId) : ""}
              onValueChange={(value) => {
                const sectionId = Number(value)
                if (sectionId) onChoose(subject.subject_id, sectionId)
                else onClear(subject.subject_id)
              }}
              disabled={disabled}
            >
              <SelectTrigger
                aria-label={`${subject.code} section`}
                className="w-full"
              >
                <SelectValue placeholder="Not selected">
                  {selectedSection ? triggerLabel(selectedSection) : undefined}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                {subject.available_sections.map((option) => {
                  let conflictsWith = conflictingSubjectCode(option, others)

                  if (!conflictsWith && paired) {
                    const matchingPaired = paired.available_sections.find(
                      (p) => p.section_code === option.section_code,
                    )
                    if (matchingPaired) {
                      const pairedConflict = conflictingSubjectCode(
                        matchingPaired,
                        others,
                      )
                      if (pairedConflict) {
                        conflictsWith = `${pairedConflict} (via ${paired.code})`
                      }
                    }
                  }

                  const isOptionRecommended =
                    recommendedSectionId !== undefined &&
                    option.id === recommendedSectionId

                  let fitNote = ""
                  let disableForFit = false
                  if (conflictsWith === null && !fitsSchedule(option)) {
                    if (hasFittingAlternative) {
                      disableForFit = true
                      fitNote =
                        " · Outside a 1–2 day / single time-block schedule"
                    } else {
                      fitNote =
                        " · ⚠ Only option available — outside a 1–2 day / single time-block schedule"
                    }
                  }

                  return (
                    <SelectItem
                      key={option.id}
                      value={String(option.id)}
                      disabled={conflictsWith !== null || disableForFit}
                    >
                      {isOptionRecommended ? "★ Recommended · " : ""}
                      Section {option.section_code}
                      {option.schedule_days
                        ? ` · ${scheduleLabel(option)}`
                        : ""}{" "}
                      {option.room ? ` · ${option.room}` : ""}
                      {option.professor_name
                        ? ` · Prof. ${option.professor_name}`
                        : ""}
                      · {seatsLabel(option)}
                      {option.is_own_department
                        ? ""
                        : ` · ${collegeLabel(option.college)} · ${option.subject_title}`}
                      {conflictsWith
                        ? ` · Conflicts with ${conflictsWith}`
                        : ""}
                      {fitNote}
                    </SelectItem>
                  )
                })}
              </SelectContent>
            </Select>
            {isRecommendedSelected && (
              <Badge
                variant="outline"
                className="w-fit border-primary/40 bg-primary/5 text-primary text-xs font-semibold"
              >
                ★ Recommended Section
              </Badge>
            )}
            {selectedSection && !selectedSection.is_own_department && (
              <Badge variant="outline" className="w-fit">
                {`${collegeLabel(selectedSection.college)} section — ${selectedSection.subject_title}`}
              </Badge>
            )}
          </div>
        )
      },
    },
    {
      key: "schedule",
      header: "Schedule",
      render: (subject) =>
        scheduleLabel(selectedSectionOf(subject, selections)),
    },
    {
      key: "professor",
      header: "Professor",
      render: (subject) => {
        const selected = selectedSectionOf(subject, selections)
        return selected?.professor_name ?? "Announced after enrollment"
      },
    },
    {
      key: "remove",
      header: "Remove",
      render: (subject) => (
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-label={`Remove ${subject.code}`}
          disabled={disabled}
          onClick={() => onRemove(subject.subject_id)}
        >
          Remove
        </Button>
      ),
    },
  ]
}

/**
 * The irregular-student per-subject selection table. Every row is one
 * eligible subject with a manual section picker — `available_sections`
 * already carries only sections the student may currently choose
 * (`BuildEligibleSubjectPool`), including another department's section for a
 * shared (non-major) subject, flagged via `is_own_department`/`college`. An
 * option that overlaps a section already picked for a different subject is
 * disabled in its own dropdown — the same day/time rule the backend enforces
 * at submission (`SectionConflictDetector`), surfaced here so a student never
 * builds a conflicting set through this table. "Arrange by schedule" is a
 * pure client-side, one-way view action: it reorders the visible rows
 * Monday-to-Saturday by each subject's picked time, sinking not-yet-picked
 * subjects to the bottom; clicking it again is a harmless no-op — there is no
 * un-arrange, but the button itself stays enabled and stays styled the same
 * so it never reads as disabled. It never changes a selection. Remove hides a
 * subject from view for the session and clears any selection it had; nothing
 * here is persisted or affects eligibility.
 *
 * `subjects` can include "advance" entries — one year ahead of the
 * student's own standing, same semester (`BuildEligibleSubjectPool` bounds
 * the pool no further than that). Those start hidden from the table just
 * like a removed row, and share the same "Add subject" combobox to bring
 * them into view — a student who is caught up can still choose to get
 * ahead, without next year's subjects cluttering the default list.
 */
export function EligibleSubjectTable({
  subjects,
  selections,
  onChoose,
  onClear,
  onBatchChoose,
  disabled = false,
  currentYearLevel = null,
  currentSemester = null,
  maxUnits = 30.0,
  regularUnits = 24.0,
}: {
  subjects: readonly EligibleSubject[]
  selections: Record<number, number>
  onChoose: (subjectId: number, sectionId: number) => void
  onClear: (subjectId: number) => void
  onBatchChoose?: (newSelections: Record<number, number>) => void
  disabled?: boolean
  /** The student's own current standing — see `curriculum-ordinal.ts`. Unknown (null) tags nothing Backlog rather than guessing. */
  currentYearLevel?: number | null
  currentSemester?: string | null
  maxUnits?: number
  regularUnits?: number
}) {
  // Subjects the student explicitly removed from view this session.
  const [removedIds, setRemovedIds] = useState<ReadonlySet<number>>(new Set())
  // Advance subjects (see isAdvance below) the student explicitly chose to
  // add via the combobox — everything else advance stays hidden by default,
  // computed fresh each render rather than seeded once at mount, since
  // currentYearLevel/currentSemester can resolve after this table's first
  // render.
  const [addedAdvanceIds, setAddedAdvanceIds] = useState<ReadonlySet<number>>(
    new Set(),
  )
  const [arrangedBySchedule, setArrangedBySchedule] = useState(false)
  const [addSubjectValue, setAddSubjectValue] = useState("")
  const [recommendationMode, setRecommendationMode] =
    useState<RecommendationMode>("manual")
  const [view, setView] = useState<"table" | "calendar">("table")
  const [inspectingSubject, setInspectingSubject] = useState<{
    subject: EligibleSubject
    section: EligibleSection
  } | null>(null)

  const isBacklog = (subject: EligibleSubject): boolean =>
    currentYearLevel !== null &&
    currentSemester !== null &&
    isBacklogSubject(
      subject.year_level,
      subject.semester,
      currentYearLevel,
      currentSemester,
    )
  const isAdvance = (subject: EligibleSubject): boolean =>
    currentYearLevel !== null &&
    currentSemester !== null &&
    isAdvanceSubject(
      subject.year_level,
      subject.semester,
      currentYearLevel,
      currentSemester,
    )
  // Hidden from the main table either because the student removed it, or
  // because it's a not-yet-added advance (one-year-ahead) subject — the
  // "Add subject" combobox is how either kind comes back into view.
  const isHidden = (subject: EligibleSubject): boolean =>
    removedIds.has(subject.subject_id) ||
    (isAdvance(subject) && !addedAdvanceIds.has(subject.subject_id))

  const subjectById = new Map(
    subjects.map((subject) => [subject.subject_id, subject]),
  )
  /**
   * A subject's own paired lecture/laboratory component, when the pair
   * actually appears in this pool (it may not — e.g. the other half was
   * already completed and is excluded entirely).
   */
  const pairOf = (subject: EligibleSubject): EligibleSubject | null =>
    subject.paired_subject_id !== null
      ? (subjectById.get(subject.paired_subject_id) ?? null)
      : null

  const reveal = (subjectId: number) => {
    setRemovedIds((prev) => {
      if (!prev.has(subjectId)) return prev
      const next = new Set(prev)
      next.delete(subjectId)
      return next
    })
    setAddedAdvanceIds((prev) =>
      prev.has(subjectId) ? prev : new Set(prev).add(subjectId),
    )
  }

  /**
   * A subject's lecture and laboratory components are institutional policy
   * always taken together, never separately — choosing a section for one
   * automatically chooses the matching section (same section code, the
   * convention the block-generation process already relies on) for the
   * other, revealing it first if it was hidden. If no matching section code
   * exists on the pair's side, nothing is auto-chosen there; the student
   * still can't submit an unpaired half — `StoreEnrollmentRequest` enforces
   * that server-side.
   */
  const choose = (subjectId: number, sectionId: number) => {
    onChoose(subjectId, sectionId)

    const subject = subjectById.get(subjectId)
    const paired = subject ? pairOf(subject) : null
    if (!subject || !paired) return

    const chosenSection = subject.available_sections.find(
      (section) => section.id === sectionId,
    )
    const matchingPairedSection = paired.available_sections.find(
      (section) => section.section_code === chosenSection?.section_code,
    )
    if (!matchingPairedSection) return

    const others = otherSelectedSections(
      subjects,
      selections,
      subjectId,
      paired.subject_id,
    )
    if (conflictingSubjectCode(matchingPairedSection, others) !== null) {
      return
    }

    reveal(paired.subject_id)
    if (selections[paired.subject_id] !== matchingPairedSection.id) {
      onChoose(paired.subject_id, matchingPairedSection.id)
    }
  }

  const clear = (subjectId: number) => {
    onClear(subjectId)

    const subject = subjectById.get(subjectId)
    const paired = subject ? pairOf(subject) : null
    if (paired && selections[paired.subject_id] !== undefined) {
      onClear(paired.subject_id)
    }
  }

  const remove = (subjectId: number) => {
    if (selections[subjectId] !== undefined) clear(subjectId)
    setRemovedIds((prev) => new Set(prev).add(subjectId))

    const subject = subjectById.get(subjectId)
    const paired = subject ? pairOf(subject) : null
    if (paired) {
      if (selections[paired.subject_id] !== undefined)
        onClear(paired.subject_id)
      setRemovedIds((prev) => new Set(prev).add(paired.subject_id))
    }
  }
  const showAll = () => {
    setRemovedIds(new Set())
    setAddedAdvanceIds(
      new Set(subjects.filter(isAdvance).map((subject) => subject.subject_id)),
    )
  }
  const addSubject = (subjectId: number) => {
    reveal(subjectId)

    const subject = subjectById.get(subjectId)
    const paired = subject ? pairOf(subject) : null
    if (paired) reveal(paired.subject_id)
  }

  const hiddenSubjects = subjects.filter(isHidden)
  const hiddenCount = hiddenSubjects.length
  const visibleSubjects = subjects.filter((subject) => !isHidden(subject))
  const [addSubjectModalOpen, setAddSubjectModalOpen] = useState(false)
  const [addSubjectSearch, setAddSubjectSearch] = useState("")

  const filteredHiddenSubjects = useMemo(() => {
    if (!addSubjectSearch.trim()) return hiddenSubjects
    const query = addSubjectSearch.toLowerCase().trim()
    return hiddenSubjects.filter(
      (s) =>
        s.code.toLowerCase().includes(query) ||
        s.title.toLowerCase().includes(query),
    )
  }, [hiddenSubjects, addSubjectSearch])
  const selectedUnits = subjects.reduce((sum, subject) => {
    if (selections[subject.subject_id] !== undefined) {
      return sum + subject.units
    }
    return sum
  }, 0)

  const recommendation = useMemo(
    () => generateScheduleRecommendation(visibleSubjects, recommendationMode),
    [visibleSubjects, recommendationMode],
  )

  const handleSelectRecommendationMode = (mode: RecommendationMode) => {
    setRecommendationMode(mode)
    if (mode === "manual") return

    const result = generateScheduleRecommendation(visibleSubjects, mode)
    if (onBatchChoose) {
      onBatchChoose({ ...selections, ...result.recommendations })
    } else {
      for (const [subjectIdStr, sectionId] of Object.entries(
        result.recommendations,
      )) {
        choose(Number(subjectIdStr), sectionId)
      }
    }
  }

  const selectedCalendarItems: SectionScheduleItem[] = useMemo(() => {
    const items: SectionScheduleItem[] = []
    for (const subject of visibleSubjects) {
      const sectionId = selections[subject.subject_id]
      if (sectionId === undefined) continue
      const section = subject.available_sections.find(
        (candidate) => candidate.id === sectionId,
      )
      if (!section) continue
      items.push({
        id: section.id,
        subject_code: subject.code,
        subject_title: subject.title,
        units: subject.units,
        section_code: section.section_code,
        room: section.room ?? null,
        professor_name: section.professor_name ?? null,
        schedule_days: section.schedule_days ?? null,
        starts_at_time: section.starts_at_time ?? null,
        ends_at_time: section.ends_at_time ?? null,
        modality: null,
        capacity: section.remaining_seats,
      })
    }
    return items
  }, [visibleSubjects, selections])

  const unselectedSubjects = useMemo(
    () => visibleSubjects.filter((s) => selections[s.subject_id] === undefined),
    [visibleSubjects, selections],
  )

  const handleSelectCalendarSubject = (item: SectionScheduleItem) => {
    const matchingSubject = visibleSubjects.find(
      (s) =>
        selections[s.subject_id] === item.id ||
        s.available_sections.some((sec) => sec.id === item.id),
    )
    if (!matchingSubject) return
    const matchingSection = matchingSubject.available_sections.find(
      (sec) => sec.id === item.id,
    )
    if (!matchingSection) return
    setInspectingSubject({ subject: matchingSubject, section: matchingSection })
  }

  // A lecture and its laboratory always show together, whatever the order.
  const rows = groupPairedSubjects(
    arrangedBySchedule
      ? [...visibleSubjects].sort((a, b) =>
          compareBySchedule(
            selectedSectionOf(a, selections) ?? {
              schedule_days: null,
              starts_at_time: null,
              ends_at_time: null,
            },
            selectedSectionOf(b, selections) ?? {
              schedule_days: null,
              starts_at_time: null,
              ends_at_time: null,
            },
          ),
        )
      : visibleSubjects,
  )

  return (
    <div className="grid min-w-0 gap-3">
      <div className="flex flex-col gap-2 rounded-lg border bg-muted/30 p-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Schedule Presets:
            </span>
            <div className="flex flex-wrap items-center gap-1.5">
              <Button
                type="button"
                variant={
                  recommendationMode === "manual" ? "default" : "outline"
                }
                size="sm"
                className="h-8 text-xs"
                onClick={() => handleSelectRecommendationMode("manual")}
                disabled={disabled}
              >
                Manual
              </Button>
              <Button
                type="button"
                variant={
                  recommendationMode === "concise" ? "default" : "outline"
                }
                size="sm"
                className="h-8 text-xs"
                onClick={() => handleSelectRecommendationMode("concise")}
                disabled={disabled}
              >
                ⚡ Concise (1–2 Days)
              </Button>
              <Button
                type="button"
                variant={
                  recommendationMode === "morning" ? "default" : "outline"
                }
                size="sm"
                className="h-8 text-xs"
                onClick={() => handleSelectRecommendationMode("morning")}
                disabled={disabled}
              >
                🌅 Morning
              </Button>
              <Button
                type="button"
                variant={
                  recommendationMode === "afternoon" ? "default" : "outline"
                }
                size="sm"
                className="h-8 text-xs"
                onClick={() => handleSelectRecommendationMode("afternoon")}
                disabled={disabled}
              >
                🌆 Afternoon / Evening
              </Button>
            </div>
          </div>
          {recommendationMode !== "manual" && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-7 text-xs text-muted-foreground"
              onClick={() => handleSelectRecommendationMode("manual")}
            >
              Reset to Manual
            </Button>
          )}
        </div>
        {recommendationMode !== "manual" && (
          <div className="flex flex-wrap items-center gap-2 border-t pt-2 text-xs text-muted-foreground">
            <span className="font-semibold text-foreground">
              {recommendationMode === "concise"
                ? "⚡ Concise Schedule:"
                : recommendationMode === "morning"
                  ? "🌅 Morning Schedule:"
                  : "🌆 Afternoon/Evening Schedule:"}
            </span>
            <span>{recommendation.summary}</span>
            <span className="italic text-foreground/70">
              (You can manually override any section in the table below)
            </span>
          </div>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <ToggleGroup
          type="single"
          value={view}
          onValueChange={(val) => {
            if (val === "table" || val === "calendar") setView(val)
          }}
          variant="outline"
          size="sm"
          aria-label="Schedule layout view"
        >
          <ToggleGroupItem value="table" aria-label="Table view">
            <ListIcon className="size-4 mr-1.5" />
            Table view
          </ToggleGroupItem>
          <ToggleGroupItem value="calendar" aria-label="Calendar view">
            <CalendarDays className="size-4 mr-1.5" />
            Calendar view
          </ToggleGroupItem>
        </ToggleGroup>

        {view === "table" && (
          <Button
            type="button"
            variant="default"
            size="sm"
            aria-pressed={arrangedBySchedule}
            onClick={() => setArrangedBySchedule(true)}
          >
            Arrange by schedule
          </Button>
        )}
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setAddSubjectModalOpen(true)}
          disabled={disabled || hiddenCount === 0}
        >
          <Plus className="size-4 mr-1.5" />
          Add subject
          {hiddenCount > 0 && (
            <Badge variant="secondary" className="ml-1.5 text-xs py-0 px-1.5">
              {hiddenCount}
            </Badge>
          )}
        </Button>
        <div className="sr-only">
          <SearchableCombobox
            id="add-subject"
            label="Add subject"
            options={hiddenSubjects.map((subject) => ({
              value: String(subject.subject_id),
              label: `${subject.code} — ${subject.title}`,
            }))}
            value={addSubjectValue}
            onValueChange={(value) => {
              if (value === "") return
              addSubject(Number(value))
              setAddSubjectValue("")
            }}
            placeholder="Add subject"
            emptyMessage="No subjects available to add."
            disabled={disabled || hiddenCount === 0}
          />
        </div>
        {hiddenCount > 0 && (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <span>
              {hiddenCount} subject{hiddenCount === 1 ? "" : "s"} not shown
            </span>
            <Button type="button" variant="link" size="sm" onClick={showAll}>
              Show all
            </Button>
          </div>
        )}
        <div className="ml-auto flex items-center gap-2">
          <Badge
            variant={
              selectedUnits > maxUnits
                ? "destructive"
                : selectedUnits > regularUnits
                  ? "warning"
                  : selectedUnits > 0
                    ? "secondary"
                    : "outline"
            }
            className="text-xs font-semibold"
          >
            {selectedUnits > maxUnits
              ? `${selectedUnits} / ${maxUnits.toFixed(1)} Max Units (Exceeded)`
              : selectedUnits > regularUnits
                ? `${selectedUnits} / ${maxUnits.toFixed(1)} Max Units (Overload)`
                : `${selectedUnits} / ${regularUnits.toFixed(1)} Regular Units`}
          </Badge>
        </div>
      </div>

      {view === "calendar" ? (
        <div className="grid gap-3">
          {unselectedSubjects.length > 0 && (
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-200">
              <div className="flex items-center gap-2">
                <Info className="size-4 shrink-0" />
                <span>
                  <strong>
                    {unselectedSubjects.length} of {visibleSubjects.length}{" "}
                    subjects
                  </strong>{" "}
                  do not have a section chosen yet (
                  {unselectedSubjects.map((s) => s.code).join(", ")}).
                </span>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-6 text-xs bg-background"
                onClick={() => setView("table")}
              >
                Pick in Table View
              </Button>
            </div>
          )}

          <SectionScheduleCalendar
            items={selectedCalendarItems}
            disabled={disabled}
            onSelectSubject={handleSelectCalendarSubject}
            emptyMessage="No subjects have sections selected yet. Pick sections in the table or apply a schedule preset above to populate your weekly timetable."
          />
        </div>
      ) : (
        <DataTable
          caption="Eligible subjects"
          rowKey={(subject) => subject.subject_id}
          rows={rows}
          columns={columns(
            subjects,
            selections,
            choose,
            clear,
            remove,
            disabled,
            isBacklog,
            isAdvance,
            pairOf,
            recommendation.recommendations,
          )}
          emptyMessage={
            hiddenCount > 0
              ? "Every subject in view is hidden. Use Show all above to bring them back."
              : undefined
          }
        />
      )}

      <Dialog
        open={addSubjectModalOpen}
        onOpenChange={(open) => {
          if (!open) {
            setAddSubjectModalOpen(false)
            setAddSubjectSearch("")
          }
        }}
      >
        <DialogContent className="max-h-[90dvh] max-w-3xl overflow-hidden p-0 sm:max-w-3xl">
          <DialogHeader className="border-b p-4 sm:p-6 pb-4">
            <div className="flex items-center gap-2">
              <DialogTitle className="text-lg font-bold">
                Add Subject to Schedule
              </DialogTitle>
              <Badge variant="secondary">{hiddenCount} available</Badge>
            </div>
            <DialogDescription>
              Browse and select subjects to add to your enrollment schedule.
            </DialogDescription>
            <div className="relative mt-2">
              <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <input
                type="text"
                placeholder="Search by subject code or title…"
                value={addSubjectSearch}
                onChange={(e) => setAddSubjectSearch(e.target.value)}
                className="w-full rounded-md border border-input bg-background py-2 pl-9 pr-4 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
            </div>
          </DialogHeader>
          <div className="max-h-[55vh] overflow-y-auto p-4 sm:p-6">
            {filteredHiddenSubjects.length === 0 ? (
              <p className="text-center py-8 text-sm text-muted-foreground">
                {addSubjectSearch
                  ? "No subjects match your search."
                  : "No additional subjects available to add."}
              </p>
            ) : (
              <div className="grid gap-2.5">
                {filteredHiddenSubjects.map((subject) => (
                  <div
                    key={subject.subject_id}
                    className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-lg border p-3 hover:bg-muted/20 transition-colors"
                  >
                    <div className="grid gap-1 min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold text-sm font-mono text-foreground">
                          {subject.code}
                        </span>
                        <Badge variant="outline" className="text-xs">
                          {subject.units} Units
                        </Badge>
                        {isBacklog(subject) && (
                          <Badge variant="warning">Backlog</Badge>
                        )}
                        {isAdvance(subject) && (
                          <Badge variant="outline">Next year</Badge>
                        )}
                        <span className="text-xs text-muted-foreground">
                          {subject.available_sections.length} section
                          {subject.available_sections.length === 1 ? "" : "s"}
                        </span>
                      </div>
                      <p className="text-xs text-muted-foreground truncate">
                        {subject.title}
                      </p>
                    </div>
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => {
                        addSubject(subject.subject_id)
                        if (hiddenCount <= 1) setAddSubjectModalOpen(false)
                      }}
                    >
                      <Plus className="size-3.5 mr-1" />
                      Add to schedule
                    </Button>
                  </div>
                ))}
              </div>
            )}
          </div>
          <DialogFooter className="border-t p-4 flex-row items-center justify-between gap-2">
            <span className="text-xs text-muted-foreground">
              Current selected: {selectedUnits} / {maxUnits.toFixed(1)} units
            </span>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                setAddSubjectModalOpen(false)
                setAddSubjectSearch("")
              }}
            >
              Done
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={inspectingSubject !== null}
        onOpenChange={(open) => {
          if (!open) setInspectingSubject(null)
        }}
      >
        <DialogContent className="w-full p-6 sm:max-w-xl md:max-w-2xl overflow-hidden">
          <DialogHeader className="gap-2 border-b pb-4">
            <div className="flex flex-wrap items-center gap-2">
              <Badge
                variant="secondary"
                className="font-mono text-xs font-semibold px-2 py-0.5"
              >
                {inspectingSubject?.subject.code}
              </Badge>
              {inspectingSubject && isBacklog(inspectingSubject.subject) && (
                <Badge variant="warning">Backlog</Badge>
              )}
              {inspectingSubject && isAdvance(inspectingSubject.subject) && (
                <Badge variant="outline">Next year</Badge>
              )}
              {inspectingSubject && (
                <Badge variant="outline" className="text-xs">
                  {inspectingSubject.subject.units} Units
                </Badge>
              )}
            </div>
            <DialogTitle className="text-lg font-bold leading-snug">
              {inspectingSubject?.subject.code} —{" "}
              {inspectingSubject?.subject.title}
            </DialogTitle>
            <DialogDescription className="text-xs text-muted-foreground">
              Review current class details or switch to another section for this
              subject.
            </DialogDescription>
          </DialogHeader>

          {inspectingSubject && (
            <div className="grid gap-5 py-1">
              <div className="grid gap-3.5 rounded-xl border bg-muted/20 p-4 sm:grid-cols-2">
                <div className="flex items-start gap-3">
                  <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <Layers className="size-4" aria-hidden="true" />
                  </div>
                  <div className="min-w-0">
                    <span className="text-xs font-medium text-muted-foreground">
                      Current Section
                    </span>
                    <p className="text-sm font-semibold truncate">
                      Section {inspectingSubject.section.section_code}
                    </p>
                  </div>
                </div>

                <div className="flex items-start gap-3">
                  <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <Clock className="size-4" aria-hidden="true" />
                  </div>
                  <div className="min-w-0">
                    <span className="text-xs font-medium text-muted-foreground">
                      Class Schedule
                    </span>
                    <p className="text-sm font-medium">
                      {scheduleLabel(inspectingSubject.section)}
                    </p>
                  </div>
                </div>

                <div className="flex items-start gap-3">
                  <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <MapPin className="size-4" aria-hidden="true" />
                  </div>
                  <div className="min-w-0">
                    <span className="text-xs font-medium text-muted-foreground">
                      Room
                    </span>
                    <p className="text-sm font-medium">
                      {inspectingSubject.section.room ?? "To be confirmed"}
                    </p>
                  </div>
                </div>

                <div className="flex items-start gap-3">
                  <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <User className="size-4" aria-hidden="true" />
                  </div>
                  <div className="min-w-0">
                    <span className="text-xs font-medium text-muted-foreground">
                      Professor
                    </span>
                    <p className="text-sm font-medium truncate">
                      {inspectingSubject.section.professor_name ??
                        "Announced after enrollment"}
                    </p>
                  </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border/60 pt-3 sm:col-span-2 text-xs">
                  <div className="flex items-center gap-2">
                    <Users
                      className="size-3.5 text-muted-foreground"
                      aria-hidden="true"
                    />
                    <span className="text-muted-foreground">
                      Class Capacity:
                    </span>
                    <Badge
                      variant="secondary"
                      className="text-xs font-semibold"
                    >
                      {seatsLabel(inspectingSubject.section)}
                    </Badge>
                  </div>
                  {!inspectingSubject.section.is_own_department && (
                    <Badge variant="outline" className="text-xs">
                      {collegeLabel(inspectingSubject.section.college)} section
                    </Badge>
                  )}
                </div>
              </div>

              <div className="grid gap-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Switch Section:
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {inspectingSubject.subject.available_sections.length}{" "}
                    available section
                    {inspectingSubject.subject.available_sections.length === 1
                      ? ""
                      : "s"}
                  </span>
                </div>
                <Select
                  value={String(
                    selections[inspectingSubject.subject.subject_id] ?? "",
                  )}
                  onValueChange={(value) => {
                    const sectionId = Number(value)
                    if (sectionId) {
                      choose(inspectingSubject.subject.subject_id, sectionId)
                      const newSec =
                        inspectingSubject.subject.available_sections.find(
                          (s) => s.id === sectionId,
                        )
                      if (newSec) {
                        setInspectingSubject({
                          subject: inspectingSubject.subject,
                          section: newSec,
                        })
                      }
                    }
                  }}
                  disabled={disabled}
                >
                  <SelectTrigger className="w-full text-left truncate">
                    <SelectValue placeholder="Select section" />
                  </SelectTrigger>
                  <SelectContent className="max-h-64">
                    {inspectingSubject.subject.available_sections.map(
                      (option) => (
                        <SelectItem key={option.id} value={String(option.id)}>
                          Section {option.section_code} ·{" "}
                          {scheduleLabel(option)} · {seatsLabel(option)}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </div>
            </div>
          )}

          <DialogFooter className="flex-wrap items-center justify-between gap-3 border-t pt-4 sm:justify-between">
            {inspectingSubject && (
              <Button
                type="button"
                variant="destructive"
                size="sm"
                disabled={disabled}
                onClick={() => {
                  clear(inspectingSubject.subject.subject_id)
                  setInspectingSubject(null)
                }}
              >
                Clear Selection
              </Button>
            )}
            <div className="flex items-center gap-2 ml-auto">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setInspectingSubject(null)}
              >
                Close
              </Button>
            </div>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
