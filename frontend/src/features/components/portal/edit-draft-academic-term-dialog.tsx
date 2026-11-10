"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useState } from "react"
import { Controller, useForm } from "react-hook-form"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/features/components/ui/dialog"
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
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import { useUpdateDraftAcademicTermIdentityMutation } from "@/features/hooks/use-reference-data"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import {
  updateDraftAcademicTermIdentityInputSchema,
  type UpdateDraftAcademicTermIdentityInput,
} from "@/features/schemas/academic-term-schema"
import type { AcademicTerm } from "@/features/schemas/reference-data-schema"
import { formatAcademicTerm } from "@/features/services/reference-data-service"

function identityFor(term: AcademicTerm): UpdateDraftAcademicTermIdentityInput {
  return {
    school_year: term.school_year,
    semester: term.semester as "1st" | "2nd",
  }
}

/**
 * A narrow correction flow for Draft terms only. The server remains the
 * authority for whether the term is still Draft when the request arrives.
 */
export function EditDraftAcademicTermDialog({
  term,
  trigger,
}: {
  term: AcademicTerm
  trigger: React.ReactNode
}) {
  const [open, setOpen] = useState(false)
  const [requestError, setRequestError] = useState("")
  const mutation = useUpdateDraftAcademicTermIdentityMutation()
  const form = useForm<UpdateDraftAcademicTermIdentityInput>({
    resolver: zodResolver(updateDraftAcademicTermIdentityInputSchema),
    defaultValues: identityFor(term),
  })

  const submit = async (input: UpdateDraftAcademicTermIdentityInput) => {
    setRequestError("")
    try {
      await mutation.mutateAsync({ academicTermId: term.id, input })
      setOpen(false)
    } catch (error) {
      if (!applyApiFieldErrors(error, form.setError))
        setRequestError(
          "The Draft term could not be updated. Check the school year and semester, then retry.",
        )
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        setOpen(nextOpen)
        if (nextOpen) form.reset(identityFor(term))
        else {
          form.reset(identityFor(term))
          setRequestError("")
        }
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Correct draft term</DialogTitle>
          <DialogDescription>
            Correcting {formatAcademicTerm(term)} changes only the school year
            and semester. The enrollment schedule dates stay as they are.
          </DialogDescription>
        </DialogHeader>
        <form
          noValidate
          onSubmit={(event) => void form.handleSubmit(submit)(event)}
        >
          <FieldGroup>
            {requestError && (
              <Alert variant="destructive">
                <AlertDescription>{requestError}</AlertDescription>
              </Alert>
            )}
            <Field data-invalid={Boolean(form.formState.errors.school_year)}>
              <FieldLabel htmlFor="edit-draft-term-school-year">
                School year
              </FieldLabel>
              <Input
                id="edit-draft-term-school-year"
                placeholder="2026-2027"
                aria-invalid={Boolean(form.formState.errors.school_year)}
                {...form.register("school_year")}
              />
              <FieldError>
                {form.formState.errors.school_year?.message}
              </FieldError>
            </Field>
            <Field>
              <FieldLabel htmlFor="edit-draft-term-semester">
                Semester
              </FieldLabel>
              <Controller
                control={form.control}
                name="semester"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger
                      id="edit-draft-term-semester"
                      className="w-full"
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="1st">1st</SelectItem>
                      <SelectItem value="2nd">2nd</SelectItem>
                    </SelectContent>
                  </Select>
                )}
              />
            </Field>
          </FieldGroup>
          <DialogFooter className="mt-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => setOpen(false)}
              disabled={mutation.isPending}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending ? "Saving changes" : "Save changes"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
