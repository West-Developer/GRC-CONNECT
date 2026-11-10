"use client"

import { useState } from "react"

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
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyTitle,
} from "@/features/components/ui/empty"
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import type { StoreCurriculumInput } from "@/features/schemas/curriculum-schema"
import type {
  Curriculum,
  Program,
} from "@/features/schemas/reference-data-schema"

interface CurriculumCreationWizardProps {
  programs: readonly Program[]
  curricula?: readonly Curriculum[]
  college: string | null | undefined
  onProceed: (input: StoreCurriculumInput) => Promise<void>
  disabled?: boolean
}

export function CurriculumCreationWizard({
  programs,
  curricula = [],
  college,
  onProceed,
  disabled = false,
}: CurriculumCreationWizardProps) {
  const [open, setOpen] = useState(false)
  const [step, setStep] = useState<1 | 2>(1)
  const [programId, setProgramId] = useState(0)
  const [name, setName] = useState("")
  const [equivalencySourceCurriculumId, setEquivalencySourceCurriculumId] =
    useState(0)
  const [isPending, setIsPending] = useState(false)
  const [requestError, setRequestError] = useState("")

  const close = () => {
    setOpen(false)
    setStep(1)
    setProgramId(0)
    setName("")
    setEquivalencySourceCurriculumId(0)
    setRequestError("")
  }
  const availableSources = curricula.filter(
    (curriculum) =>
      curriculum.program_id === programId &&
      ["active", "archived"].includes(curriculum.status),
  )
  const hasSources = availableSources.length > 0

  const proceed = async () => {
    if (
      programId <= 0 ||
      (hasSources && equivalencySourceCurriculumId <= 0) ||
      !name.trim() ||
      isPending
    )
      return
    setIsPending(true)
    setRequestError("")
    try {
      await onProceed({
        program_id: programId,
        ...(equivalencySourceCurriculumId > 0
          ? { equivalency_source_curriculum_id: equivalencySourceCurriculumId }
          : {}),
        name: name.trim(),
        subjects: [],
      })
      close()
    } catch {
      setRequestError(
        "Curriculum could not be created. Check the details and try again.",
      )
    } finally {
      setIsPending(false)
    }
  }

  return (
    <>
      <Empty className="min-h-72 border">
        <EmptyHeader>
          <EmptyTitle>Create a curriculum</EmptyTitle>
          <EmptyDescription>
            Start with a program, then add the curriculum name and subjects.
          </EmptyDescription>
        </EmptyHeader>
        <EmptyContent>
          <Button
            type="button"
            disabled={disabled}
            onClick={() => setOpen(true)}
          >
            Create new curriculum
          </Button>
        </EmptyContent>
      </Empty>
      <Dialog
        open={open}
        onOpenChange={(nextOpen) => (nextOpen ? setOpen(true) : close())}
      >
        <DialogContent showCloseButton={!isPending}>
          <DialogHeader>
            <DialogTitle>
              {step === 1 ? "Choose a program" : "Name this curriculum"}
            </DialogTitle>
            <DialogDescription>
              Step {step} of 2.{" "}
              {step === 1
                ? "Only programs in your college are available."
                : "The effective school year and status are set by the server."}
            </DialogDescription>
          </DialogHeader>
          <FieldGroup>
            {step === 1 ? (
              <>
                <p className="text-sm text-muted-foreground">
                  College: {(college ?? "unassigned").toUpperCase()}
                </p>
                <Field data-invalid={programId <= 0}>
                  <FieldLabel htmlFor="creation-program">Program</FieldLabel>
                  <Select
                    value={programId > 0 ? String(programId) : ""}
                    onValueChange={(value) => {
                      setProgramId(Number(value))
                      setEquivalencySourceCurriculumId(0)
                    }}
                    disabled={isPending}
                  >
                    <SelectTrigger
                      id="creation-program"
                      aria-invalid={programId <= 0}
                    >
                      <SelectValue placeholder="Select a program" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectGroup>
                        {programs.map((program) => (
                          <SelectItem
                            key={program.id}
                            value={String(program.id)}
                          >
                            {program.code} — {program.name}
                          </SelectItem>
                        ))}
                      </SelectGroup>
                    </SelectContent>
                  </Select>
                  <FieldError>
                    {programId <= 0 ? "Select a program." : null}
                  </FieldError>
                </Field>
              </>
            ) : (
              <>
                {hasSources && (
                  <Field data-invalid={equivalencySourceCurriculumId <= 0}>
                    <FieldLabel htmlFor="equivalency-source-curriculum">
                      Old curriculum source
                    </FieldLabel>
                    <Select
                      value={
                        equivalencySourceCurriculumId > 0
                          ? String(equivalencySourceCurriculumId)
                          : ""
                      }
                      onValueChange={(value) =>
                        setEquivalencySourceCurriculumId(Number(value))
                      }
                      disabled={isPending}
                    >
                      <SelectTrigger
                        id="equivalency-source-curriculum"
                        aria-invalid={equivalencySourceCurriculumId <= 0}
                      >
                        <SelectValue placeholder="Select old curriculum" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectGroup>
                          {availableSources.map((curriculum) => (
                            <SelectItem
                              key={curriculum.id}
                              value={String(curriculum.id)}
                            >
                              {curriculum.name}
                            </SelectItem>
                          ))}
                        </SelectGroup>
                      </SelectContent>
                    </Select>
                    <FieldError>
                      {equivalencySourceCurriculumId <= 0
                        ? "Select the old curriculum source."
                        : null}
                    </FieldError>
                  </Field>
                )}
                <Field data-invalid={!name.trim()}>
                  <FieldLabel htmlFor="curriculum-creation-name">
                    Curriculum name
                  </FieldLabel>
                  <Input
                    id="curriculum-creation-name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    aria-invalid={!name.trim()}
                    disabled={isPending}
                  />
                  <FieldError>
                    {!name.trim() ? "Enter a curriculum name." : null}
                  </FieldError>
                </Field>
              </>
            )}
            {requestError && <FieldError>{requestError}</FieldError>}
          </FieldGroup>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              disabled={isPending}
              onClick={close}
            >
              Cancel
            </Button>
            {step === 2 && (
              <Button
                type="button"
                variant="outline"
                disabled={isPending}
                onClick={() => setStep(1)}
              >
                Back
              </Button>
            )}
            {step === 1 ? (
              <Button
                type="button"
                disabled={programId <= 0 || isPending}
                onClick={() => setStep(2)}
              >
                Next
              </Button>
            ) : (
              <Button
                type="button"
                disabled={
                  !name.trim() ||
                  (hasSources && equivalencySourceCurriculumId <= 0) ||
                  isPending
                }
                onClick={() => void proceed()}
              >
                Proceed
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
