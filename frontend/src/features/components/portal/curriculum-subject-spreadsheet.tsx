"use client"

import { useState } from "react"
import { Trash2Icon, XIcon } from "lucide-react"

import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Input } from "@/features/components/ui/input"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import type { CurriculumSubjectInput } from "@/features/schemas/curriculum-schema"
import type { Subject } from "@/features/schemas/reference-data-schema"

export interface CurriculumSubjectSpreadsheetProps {
  yearLevel: number
  subjects: readonly CurriculumSubjectInput[]
  subjectCatalog: readonly Subject[]
  prerequisiteSubjects: readonly Subject[]
  isLocked: boolean
  onChange: (subjects: CurriculumSubjectInput[]) => void
  onAddRow: () => void
}

const fixedPrerequisiteMinimumGrade = "75"

export function CurriculumSubjectSpreadsheet({
  yearLevel,
  subjects,
  subjectCatalog,
  prerequisiteSubjects,
  isLocked,
  onChange,
  onAddRow,
}: CurriculumSubjectSpreadsheetProps) {
  const [prerequisiteEditorIndex, setPrerequisiteEditorIndex] = useState<
    number | null
  >(null)
  const [prerequisiteSearch, setPrerequisiteSearch] = useState("")
  const rows = subjects
    .map((subject, index) => ({ subject, index }))
    .filter(({ subject }) => subject.year_level === yearLevel)
  const subjectFor = (id: number) =>
    subjectCatalog.find((subject) => subject.id === id)
  const codeFor = (id: number) => subjectFor(id)?.code ?? `Subject ${id}`
  const replaceRow = (index: number, update: Partial<CurriculumSubjectInput>) =>
    onChange(
      subjects.map((subject, row) =>
        row === index ? { ...subject, ...update } : subject,
      ),
    )
  const removeRow = (index: number) =>
    onChange(subjects.filter((_, row) => row !== index))
  const addPrerequisite = (index: number, prerequisiteSubjectId: number) => {
    const subject = subjects[index]
    replaceRow(index, {
      prerequisites: [
        ...subject.prerequisites,
        {
          prerequisite_subject_id: prerequisiteSubjectId,
          minimum_grade: fixedPrerequisiteMinimumGrade,
        },
      ],
    })
  }
  const removePrerequisite = (index: number, prerequisiteSubjectId: number) => {
    const subject = subjects[index]
    replaceRow(index, {
      prerequisites: subject.prerequisites.filter(
        (edge) => edge.prerequisite_subject_id !== prerequisiteSubjectId,
      ),
    })
  }
  const clearPrerequisites = (index: number) =>
    replaceRow(index, { prerequisites: [] })
  const editingPrerequisiteSubject =
    prerequisiteEditorIndex === null ? null : subjects[prerequisiteEditorIndex]
  const prerequisiteCandidates = editingPrerequisiteSubject
    ? prerequisiteSubjects.filter(
        (candidate) =>
          candidate.id !== editingPrerequisiteSubject.subject_id &&
          !editingPrerequisiteSubject.prerequisites.some(
            (edge) => edge.prerequisite_subject_id === candidate.id,
          ) &&
          `${candidate.code} ${candidate.title}`
            .toLowerCase()
            .includes(prerequisiteSearch.trim().toLowerCase()),
      )
    : []
  const openPrerequisiteEditor = (index: number) => {
    setPrerequisiteSearch("")
    setPrerequisiteEditorIndex(index)
  }
  const yearName = `${yearLevel}${yearLevel === 1 ? "st" : yearLevel === 2 ? "nd" : yearLevel === 3 ? "rd" : "th"} Year`

  return (
    <div className="flex flex-col gap-3">
      <div className="flex justify-end">
        <Button
          type="button"
          variant="outline"
          disabled={isLocked}
          onClick={onAddRow}
        >
          Add subject row
        </Button>
      </div>
      <Table aria-label={`${yearName} subjects`}>
        <TableHeader>
          <TableRow>
            <TableHead>Subject Code</TableHead>
            <TableHead>Description</TableHead>
            <TableHead>Units</TableHead>
            <TableHead>Semester</TableHead>
            <TableHead>Prerequisite</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.length === 0 && (
            <TableRow>
              <TableCell
                colSpan={5}
                className="h-20 text-center text-muted-foreground"
              >
                No subjects in this year level yet.
              </TableCell>
            </TableRow>
          )}
          {rows.map(({ subject, index }) => {
            const code = codeFor(subject.subject_id)
            const details = subjectFor(subject.subject_id)
            return (
              <TableRow key={subject.subject_id}>
                <TableCell>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">{code}</span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon-sm"
                      aria-label={`Remove ${code} row`}
                      disabled={isLocked}
                      onClick={() => removeRow(index)}
                    >
                      <Trash2Icon aria-hidden="true" />
                    </Button>
                  </div>
                </TableCell>
                <TableCell className="whitespace-normal">
                  {details?.title ?? "—"}
                </TableCell>
                <TableCell>{details?.units ?? "—"}</TableCell>
                <TableCell>
                  <Select
                    value={subject.semester}
                    onValueChange={(semester) => {
                      if (semester === "1st" || semester === "2nd")
                        replaceRow(index, { semester })
                    }}
                    disabled={isLocked}
                  >
                    <SelectTrigger
                      aria-label={`Semester for ${code}`}
                      className="min-w-36"
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectGroup>
                        <SelectItem value="1st">1st Semester</SelectItem>
                        <SelectItem value="2nd">2nd Semester</SelectItem>
                      </SelectGroup>
                    </SelectContent>
                  </Select>
                </TableCell>
                <TableCell className="min-w-64 align-top whitespace-normal">
                  <div className="flex flex-wrap items-center gap-2">
                    {subject.prerequisites.length === 0 && (
                      <span className="text-muted-foreground">None</span>
                    )}
                    {subject.prerequisites.map((edge) => (
                      <div
                        key={edge.prerequisite_subject_id}
                        className="flex items-center gap-1"
                      >
                        <Badge variant="secondary">
                          {codeFor(edge.prerequisite_subject_id)}
                        </Badge>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon-sm"
                          aria-label={`Remove prerequisite ${codeFor(edge.prerequisite_subject_id)} from ${code}`}
                          disabled={isLocked}
                          onClick={() =>
                            removePrerequisite(
                              index,
                              edge.prerequisite_subject_id,
                            )
                          }
                        >
                          <XIcon aria-hidden="true" />
                        </Button>
                      </div>
                    ))}
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      aria-label={
                        subject.prerequisites.length > 0
                          ? `Edit prerequisites for ${code}`
                          : `Add prerequisite for ${code}`
                      }
                      disabled={isLocked}
                      onClick={() => openPrerequisiteEditor(index)}
                    >
                      {subject.prerequisites.length > 0 ? "Edit" : "Add"}
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
      <Dialog
        open={prerequisiteEditorIndex !== null}
        onOpenChange={(open) => !open && setPrerequisiteEditorIndex(null)}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editingPrerequisiteSubject
                ? `Edit prerequisites for ${codeFor(editingPrerequisiteSubject.subject_id)}`
                : "Edit prerequisites"}
            </DialogTitle>
            <DialogDescription>
              Search subjects from the latest active curriculum.
            </DialogDescription>
          </DialogHeader>
          <Input
            aria-label="Search prerequisites"
            placeholder="Search by subject code or description"
            value={prerequisiteSearch}
            onChange={(event) => setPrerequisiteSearch(event.target.value)}
          />
          <div className="grid max-h-56 gap-1 overflow-y-auto">
            <Button
              type="button"
              variant="outline"
              className="justify-start"
              disabled={!editingPrerequisiteSubject?.prerequisites.length}
              onClick={() => {
                if (prerequisiteEditorIndex !== null)
                  clearPrerequisites(prerequisiteEditorIndex)
                setPrerequisiteEditorIndex(null)
              }}
            >
              None
            </Button>
            {prerequisiteCandidates.map((candidate) => {
              return (
                <Button
                  key={candidate.id}
                  type="button"
                  variant="outline"
                  className="justify-start"
                  onClick={() => {
                    if (prerequisiteEditorIndex !== null)
                      addPrerequisite(prerequisiteEditorIndex, candidate.id)
                    setPrerequisiteEditorIndex(null)
                  }}
                >
                  {candidate.code} — {candidate.title}
                </Button>
              )
            })}
            {prerequisiteCandidates.length === 0 && (
              <p className="py-3 text-sm text-muted-foreground">
                No eligible subjects found.
              </p>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
