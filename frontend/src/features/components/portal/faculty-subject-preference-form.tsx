"use client"

import { Controller, type UseFormReturn } from "react-hook-form"

import { Button } from "@/features/components/ui/button"
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { SearchableCombobox } from "@/features/components/ui/searchable-combobox"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import type {
  FacultyCurriculumSubjectPreference,
  FacultyCurriculumSubjectPreferenceInput,
  FacultyPreferenceCatalogEntry,
} from "@/features/schemas/faculty-schema"

interface FacultySubjectPreferenceFormProps {
  form: UseFormReturn<FacultyCurriculumSubjectPreferenceInput>
  catalog: readonly FacultyPreferenceCatalogEntry[]
  selectedCurriculum: FacultyPreferenceCatalogEntry | undefined
  selectedSemester: "1st" | "2nd"
  subjectOptions: readonly { value: string; label: string }[]
  isCatalogLoading: boolean
  isSaving: boolean
  editing: FacultyCurriculumSubjectPreference | null
  proficiency: "primary" | "secondary"
  pairedSubjectInfo?: { code: string; title: string } | null
  onProficiencyChange: (value: "primary" | "secondary") => void
  onCancelEdit: () => void
  onSave: (input: FacultyCurriculumSubjectPreferenceInput) => Promise<void>
}

export function FacultySubjectPreferenceForm({
  form,
  catalog,
  selectedCurriculum,
  selectedSemester,
  subjectOptions,
  isCatalogLoading,
  isSaving,
  editing,
  proficiency,
  pairedSubjectInfo,
  onProficiencyChange,
  onCancelEdit,
  onSave,
}: FacultySubjectPreferenceFormProps) {
  return (
    <form
      noValidate
      onSubmit={(event) => void form.handleSubmit(onSave)(event)}
    >
      <FieldGroup>
        <Field data-invalid={Boolean(form.formState.errors.curriculum_id)}>
          <FieldLabel htmlFor="preference-curriculum">Curriculum</FieldLabel>
          <Controller
            control={form.control}
            name="curriculum_id"
            render={({ field }) => (
              <Select
                value={field.value ? String(field.value) : ""}
                onValueChange={(value) => {
                  field.onChange(Number(value))
                  form.setValue("subject_id", 0)
                }}
                disabled={isSaving || isCatalogLoading}
              >
                <SelectTrigger id="preference-curriculum" className="w-full">
                  <SelectValue placeholder="Select a curriculum" />
                </SelectTrigger>
                <SelectContent>
                  {catalog.map((entry) => (
                    <SelectItem
                      key={entry.curriculum_id}
                      value={String(entry.curriculum_id)}
                    >
                      {entry.program_code} ·{" "}
                      {entry.version_label === "new" ? "New" : "Old"} ·{" "}
                      {entry.curriculum_name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError>
            {form.formState.errors.curriculum_id?.message}
          </FieldError>
        </Field>
        <Field>
          <FieldLabel>Semester</FieldLabel>
          <div
            className="grid grid-cols-2 gap-2"
            role="group"
            aria-label="Preference semester"
          >
            {(["1st", "2nd"] as const).map((semester) => (
              <Button
                key={semester}
                type="button"
                variant={selectedSemester === semester ? "default" : "outline"}
                onClick={() => {
                  form.setValue("semester", semester)
                  form.setValue("subject_id", 0)
                }}
              >
                {semester} Semester
              </Button>
            ))}
          </div>
        </Field>
        <Field data-invalid={Boolean(form.formState.errors.subject_id)}>
          <FieldLabel htmlFor="preference-subject">
            Preferred subject
          </FieldLabel>
          <Controller
            control={form.control}
            name="subject_id"
            render={({ field }) => (
              <SearchableCombobox
                id="preference-subject"
                label="Preferred subject"
                options={subjectOptions}
                value={field.value ? String(field.value) : ""}
                onValueChange={(value) => field.onChange(Number(value) || 0)}
                placeholder={
                  selectedCurriculum
                    ? "Search code or subject title"
                    : "Select a curriculum first"
                }
                emptyMessage="No matching curriculum subject."
                disabled={isSaving || !selectedCurriculum}
              />
            )}
          />
          {pairedSubjectInfo && (
            <div className="mt-2 rounded-md border border-sky-200 bg-sky-50 p-2.5 text-xs text-sky-800 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300">
              <span className="font-semibold">Auto-Joint LEC/LAB:</span>{" "}
              {pairedSubjectInfo.code} — {pairedSubjectInfo.title} will automatically be included.
            </div>
          )}
          <FieldError>{form.formState.errors.subject_id?.message}</FieldError>
        </Field>
        <Field data-invalid={Boolean(form.formState.errors.rank)}>
          <FieldLabel htmlFor="preference-rank">Preference rank</FieldLabel>
          <Input
            id="preference-rank"
            type="number"
            min={1}
            placeholder="Append automatically"
            disabled={isSaving}
            {...form.register("rank", {
              setValueAs: (value) => (value === "" ? undefined : Number(value)),
            })}
          />
          <FieldError>{form.formState.errors.rank?.message}</FieldError>
        </Field>
        <Field>
          <FieldLabel htmlFor="preference-proficiency">Proficiency</FieldLabel>
          <Select
            value={proficiency}
            onValueChange={(value) =>
              onProficiencyChange(value === "primary" ? "primary" : "secondary")
            }
            disabled={isSaving}
          >
            <SelectTrigger
              id="preference-proficiency"
              className="w-full"
              aria-label="Proficiency"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="primary">Primary</SelectItem>
              <SelectItem value="secondary">Secondary</SelectItem>
            </SelectContent>
          </Select>
        </Field>
        <div className="flex flex-wrap gap-2">
          <Button type="submit" disabled={isSaving || !selectedCurriculum}>
            {editing ? "Update subject preference" : "Save subject preference"}
          </Button>
          {editing && (
            <Button type="button" variant="outline" onClick={onCancelEdit}>
              Cancel edit
            </Button>
          )}
        </div>
      </FieldGroup>
    </form>
  )
}
