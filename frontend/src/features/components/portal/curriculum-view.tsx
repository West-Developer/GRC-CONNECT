"use client"

import { useMemo, useState } from "react"

import { Badge } from "@/features/components/ui/badge"
import { Field, FieldLabel } from "@/features/components/ui/field"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import type {
  Curriculum,
  Program,
} from "@/features/schemas/reference-data-schema"
import { formatYearLevel } from "@/features/lib/format-year-level"

const yearFilterOptions = [
  { value: "all", label: "All years" },
  { value: "1", label: "1st Year" },
  { value: "2", label: "2nd Year" },
  { value: "3", label: "3rd Year" },
  { value: "4", label: "4th Year" },
]
const semesterFilterOptions = [
  { value: "all", label: "All semesters" },
  { value: "1st", label: "1st Semester" },
  { value: "2nd", label: "2nd Semester" },
]


/** The most recent version (by effective_school_year) is "New curriculum";
 * every earlier version for that program is "Old curriculum" — mirrors the
 * labeling in program-chair-enrollment-workspace.tsx's curriculumAgeLabel. */
function curriculumVersionLabel(index: number) {
  return index === 0 ? "New curriculum" : "Old curriculum"
}

/**
 * Mirrors the backend's `SemesterCoverage::parse` bucketing (see
 * `backend/app/Domain/Curriculum/SemesterCoverage.php`) so a composite
 * placement (`"1st|2nd"`, offered either semester) matches both specific
 * semester filters instead of only the first one found.
 */
function semesterSlots(raw: string): ("1st" | "2nd")[] {
  const normalized = raw.toLowerCase()
  const hasFirst = normalized.includes("1st")
  const hasSecond = normalized.includes("2nd")
  if (hasFirst && hasSecond) return ["1st", "2nd"]
  if (hasSecond) return ["2nd"]
  return ["1st"]
}

interface ViewRow {
  year_level: number
  semester: "1st" | "2nd"
  subjects: Curriculum["subjects"]
}

export function CurriculumView({
  programs,
  curricula,
  preview = false,
}: {
  programs: readonly Program[]
  curricula: readonly Curriculum[]
  preview?: boolean
}) {
  // View is the published catalog. Draft and in-review curricula remain in
  // Manage until the Executive Director grants the final approval.
  const viewableCurricula = useMemo(
    () => curricula.filter((curriculum) => curriculum.status === "active"),
    [curricula],
  )
  const availablePrograms = useMemo(
    () =>
      programs
        .filter((program) =>
          viewableCurricula.some(
            (curriculum) => curriculum.program_id === program.id,
          ),
        )
        .slice()
        .sort((a, b) => a.code.localeCompare(b.code)),
    [programs, viewableCurricula],
  )
  const [programId, setProgramId] = useState(0)
  const [curriculumId, setCurriculumId] = useState(0)
  const [yearFilter, setYearFilter] = useState("all")
  const [semesterFilter, setSemesterFilter] = useState("all")

  const selectedProgramId =
    programId > 0 ? programId : (availablePrograms[0]?.id ?? 0)
  const selectedProgram = availablePrograms.find(
    (program) => program.id === selectedProgramId,
  )
  const programCurricula = useMemo(
    () =>
      viewableCurricula
        .filter((item) => item.program_id === selectedProgramId)
        .slice()
        .sort((a, b) =>
          b.effective_school_year.localeCompare(a.effective_school_year),
        ),
    [viewableCurricula, selectedProgramId],
  )
  const selectedCurriculumId = programCurricula.some(
    (item) => item.id === curriculumId,
  )
    ? curriculumId
    : (programCurricula[0]?.id ?? 0)
  const curriculum = programCurricula.find(
    (item) => item.id === selectedCurriculumId,
  )

  const rows = useMemo<ViewRow[]>(() => {
    if (!curriculum) return []
    const byKey = new Map<string, ViewRow>()
    for (const subject of curriculum.subjects) {
      if (yearFilter !== "all" && subject.year_level !== Number(yearFilter))
        continue
      const slots = semesterSlots(subject.semester).filter(
        (slot) => semesterFilter === "all" || slot === semesterFilter,
      )
      for (const slot of slots) {
        const key = `${subject.year_level}-${slot}`
        const existing = byKey.get(key)
        if (existing) existing.subjects = [...existing.subjects, subject]
        else
          byKey.set(key, {
            year_level: subject.year_level,
            semester: slot,
            subjects: [subject],
          })
      }
    }
    return [...byKey.values()].sort((a, b) =>
      a.year_level !== b.year_level
        ? a.year_level - b.year_level
        : a.semester.localeCompare(b.semester),
    )
  }, [curriculum, yearFilter, semesterFilter])

  return (
    <div className="grid gap-4">
      {!preview && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field>
            <FieldLabel htmlFor="curriculum-view-program">Program</FieldLabel>
            <Select
              value={selectedProgramId > 0 ? String(selectedProgramId) : ""}
              onValueChange={(value) => {
                setProgramId(Number(value))
                setCurriculumId(0)
              }}
            >
              <SelectTrigger
                id="curriculum-view-program"
                className="w-full overflow-hidden"
              >
                <SelectValue placeholder="Select a program">
                  {selectedProgram && (
                    <span className="block truncate">
                      {selectedProgram.code} — {selectedProgram.name}
                    </span>
                  )}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                {availablePrograms.map((program) => (
                  <SelectItem key={program.id} value={String(program.id)}>
                    {program.code} — {program.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
          <Field>
            <FieldLabel htmlFor="curriculum-view-curriculum">
              Curriculum
            </FieldLabel>
            <Select
              value={
                selectedCurriculumId > 0 ? String(selectedCurriculumId) : ""
              }
              onValueChange={(value) => setCurriculumId(Number(value))}
            >
              <SelectTrigger
                id="curriculum-view-curriculum"
                className="w-full overflow-hidden"
              >
                <SelectValue placeholder="Select a curriculum">
                  {curriculum && (
                    <span className="block truncate">
                      {curriculum.name} ·{" "}
                      {curriculumVersionLabel(
                        programCurricula.findIndex(
                          (item) => item.id === curriculum.id,
                        ),
                      )}
                    </span>
                  )}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                {/* `item.name` already carries the school year (e.g. "BSIT
                  Curriculum 2024-2029" — see GrcCurriculumSeeder), so it
                  isn't repeated here. */}
                {programCurricula.map((item, index) => (
                  <SelectItem key={item.id} value={String(item.id)}>
                    <span className="block truncate">
                      {item.name} · {curriculumVersionLabel(index)}
                    </span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
          <Field>
            <FieldLabel htmlFor="curriculum-view-year">Year level</FieldLabel>
            <Select value={yearFilter} onValueChange={setYearFilter}>
              <SelectTrigger id="curriculum-view-year" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {yearFilterOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
          <Field>
            <FieldLabel htmlFor="curriculum-view-semester">Semester</FieldLabel>
            <Select value={semesterFilter} onValueChange={setSemesterFilter}>
              <SelectTrigger id="curriculum-view-semester" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {semesterFilterOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
        </div>
      )}

      {preview && curriculum && selectedProgram && (
        <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border bg-muted/30 p-3">
          <div>
            <p className="text-xs text-muted-foreground">Program</p>
            <p className="font-medium">
              {selectedProgram.code} — {selectedProgram.name}
            </p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Curriculum</p>
            <div className="flex items-center gap-2">
              <p className="font-medium">{curriculum.name}</p>
              {curriculum.effective_max_units && (
                <Badge variant="outline" className="text-xs">
                  Max: {curriculum.effective_max_units} units
                </Badge>
              )}
            </div>
          </div>
        </div>
      )}

      {availablePrograms.length === 0 && (
        <p className="text-sm text-muted-foreground">
          No curriculum is available to view yet.
        </p>
      )}
      {availablePrograms.length > 0 && rows.length === 0 && (
        <p className="text-sm text-muted-foreground">
          No subjects match the selected filters.
        </p>
      )}

      {preview && rows.length > 0 && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Code</TableHead>
              <TableHead>Description</TableHead>
              <TableHead>Units</TableHead>
              <TableHead>Prerequisites</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.flatMap((row) => [
              <TableRow key={`${row.year_level}-${row.semester}-heading`}>
                <TableCell colSpan={4} className="bg-muted/40 font-medium">
                  {formatYearLevel(row.year_level)} · {row.semester} Semester
                </TableCell>
              </TableRow>,
              ...row.subjects.map((subject) => (
                <TableRow key={subject.subject_id}>
                  <TableCell>{subject.code}</TableCell>
                  <TableCell>{subject.title}</TableCell>
                  <TableCell>{subject.units ?? "—"}</TableCell>
                  <TableCell>
                    {subject.prerequisites.length === 0 ? (
                      <span className="text-muted-foreground">None</span>
                    ) : (
                      <div className="flex flex-wrap gap-1">
                        {subject.prerequisites.map((prerequisite) => (
                          <Badge
                            key={prerequisite.prerequisite_subject_id}
                            variant="secondary"
                          >
                            {prerequisite.code}
                          </Badge>
                        ))}
                      </div>
                    )}
                  </TableCell>
                </TableRow>
              )),
            ])}
          </TableBody>
        </Table>
      )}

      {!preview &&
        rows.map((row) => (
          <Table key={`${row.year_level}-${row.semester}`}>
            <TableCaption>
              {formatYearLevel(row.year_level)} · {row.semester} Semester
            </TableCaption>
            <TableHeader>
              <TableRow>
                <TableHead>Code</TableHead>
                <TableHead>Description</TableHead>
                <TableHead>Units</TableHead>
                <TableHead>Prerequisites</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {row.subjects.map((subject) => (
                <TableRow key={subject.subject_id}>
                  <TableCell>{subject.code}</TableCell>
                  <TableCell>{subject.title}</TableCell>
                  <TableCell>{subject.units ?? "—"}</TableCell>
                  <TableCell>
                    {subject.prerequisites.length === 0 ? (
                      <span className="text-muted-foreground">None</span>
                    ) : (
                      <div className="flex flex-wrap gap-1">
                        {subject.prerequisites.map((prerequisite) => (
                          <Badge
                            key={prerequisite.prerequisite_subject_id}
                            variant="secondary"
                          >
                            {prerequisite.code}
                          </Badge>
                        ))}
                      </div>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        ))}
    </div>
  )
}
