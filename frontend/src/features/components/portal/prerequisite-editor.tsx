"use client"

import { useState } from "react"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import { Field, FieldLabel } from "@/features/components/ui/field"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import type { CurriculumSubjectInput } from "@/features/schemas/curriculum-schema"
import type { Subject } from "@/features/schemas/reference-data-schema"

export interface PrerequisiteEditorProps {
  subjects: readonly CurriculumSubjectInput[]
  subjectCatalog: readonly Subject[]
  onChange: (subjects: CurriculumSubjectInput[]) => void
  graphError?: string
}

function wouldCreateCycle(
  subjects: readonly CurriculumSubjectInput[],
  subjectId: number,
  prerequisiteId: number,
): boolean {
  const graph = new Map(
    subjects.map((subject) => [
      subject.subject_id,
      subject.prerequisites.map((edge) => edge.prerequisite_subject_id),
    ]),
  )
  graph.set(subjectId, [...(graph.get(subjectId) ?? []), prerequisiteId])
  const walk = (current: number, seen: Set<number>): boolean => {
    if (current === subjectId) return true
    if (seen.has(current)) return false
    seen.add(current)
    return (graph.get(current) ?? []).some((next) => walk(next, seen))
  }
  return walk(prerequisiteId, new Set())
}

export function PrerequisiteEditor({
  subjects,
  subjectCatalog,
  onChange,
  graphError,
}: PrerequisiteEditorProps) {
  const [subjectId, setSubjectId] = useState(0)
  const [prerequisiteId, setPrerequisiteId] = useState(0)
  const [error, setError] = useState("")
  const selected = subjects.find((subject) => subject.subject_id === subjectId)

  const addEdge = () => {
    setError("")
    if (!selected || prerequisiteId <= 0)
      return setError("Select both a curriculum subject and its prerequisite.")
    if (subjectId === prerequisiteId)
      return setError("A subject cannot be its own prerequisite.")
    if (
      selected.prerequisites.some(
        (edge) => edge.prerequisite_subject_id === prerequisiteId,
      )
    )
      return setError("This prerequisite edge already exists.")
    if (wouldCreateCycle(subjects, subjectId, prerequisiteId))
      return setError("This relationship would create a prerequisite cycle.")
    onChange(
      subjects.map((subject) =>
        subject.subject_id === subjectId
          ? {
              ...subject,
              prerequisites: [
                ...subject.prerequisites,
                {
                  prerequisite_subject_id: prerequisiteId,
                  minimum_grade: "75",
                },
              ],
            }
          : subject,
      ),
    )
  }

  const nameFor = (id: number) =>
    subjectCatalog.find((subject) => subject.id === id)?.code ?? `Subject ${id}`

  return (
    <section aria-label="Prerequisite editor" className="grid gap-3">
      <h2>Prerequisites</h2>
      {(error || graphError) && (
        <Alert variant="destructive">
          <AlertDescription>{error || graphError}</AlertDescription>
        </Alert>
      )}
      <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
        <Field>
          <FieldLabel htmlFor="prerequisite-subject">
            Subject for prerequisite
          </FieldLabel>
          <Select
            value={subjectId > 0 ? String(subjectId) : ""}
            onValueChange={(value) => setSubjectId(Number(value))}
          >
            <SelectTrigger id="prerequisite-subject" className="w-full">
              <SelectValue placeholder="Select a placed subject" />
            </SelectTrigger>
            <SelectContent>
              {subjects.map((subject) => (
                <SelectItem
                  key={subject.subject_id}
                  value={String(subject.subject_id)}
                >
                  {nameFor(subject.subject_id)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </Field>
        <Field>
          <FieldLabel htmlFor="prerequisite-edge">
            Prerequisite subject
          </FieldLabel>
          <Select
            value={prerequisiteId > 0 ? String(prerequisiteId) : ""}
            onValueChange={(value) => setPrerequisiteId(Number(value))}
          >
            <SelectTrigger id="prerequisite-edge" className="w-full">
              <SelectValue placeholder="Select prerequisite" />
            </SelectTrigger>
            <SelectContent>
              {subjectCatalog.map((subject) => (
                <SelectItem key={subject.id} value={String(subject.id)}>
                  {subject.code} — {subject.title}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </Field>
        <div className="flex items-end">
          <Button type="button" onClick={addEdge}>
            Add prerequisite
          </Button>
        </div>
      </div>
      <ul className="grid gap-2" aria-label="Current prerequisites">
        {subjects.flatMap((subject) =>
          subject.prerequisites.map((edge) => (
            <li
              key={`${subject.subject_id}-${edge.prerequisite_subject_id}`}
              className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
            >
              <span>
                {nameFor(subject.subject_id)} requires{" "}
                {nameFor(edge.prerequisite_subject_id)}
              </span>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-label={`Remove prerequisite ${subject.subject_id}-${edge.prerequisite_subject_id}`}
                onClick={() =>
                  onChange(
                    subjects.map((row) =>
                      row.subject_id === subject.subject_id
                        ? {
                            ...row,
                            prerequisites: row.prerequisites.filter(
                              (item) =>
                                item.prerequisite_subject_id !==
                                edge.prerequisite_subject_id,
                            ),
                          }
                        : row,
                    ),
                  )
                }
              >
                Remove
              </Button>
            </li>
          )),
        )}
      </ul>
    </section>
  )
}
