"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useMutation, useQueryClient } from "@tanstack/react-query"
import { useState } from "react"
import { Controller, useForm } from "react-hook-form"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataUseNotice } from "@/features/components/privacy/data-use-notice"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
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
  DialogHeader,
  DialogTitle,
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import {
  facultyAvailabilitiesQueryKey,
  useFacultyAvailabilitiesQuery,
} from "@/features/hooks/use-faculty-input"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import { formatTimeRange } from "@/features/lib/format-time"
import {
  facultyAvailabilityInputSchema,
  type FacultyAvailability,
  type FacultyAvailabilityInput,
} from "@/features/schemas/faculty-schema"
import {
  createFacultyAvailability,
  deleteFacultyAvailability,
  updateFacultyAvailability,
} from "@/features/services/faculty-service"

const weekdays = [
  [1, "Monday"],
  [2, "Tuesday"],
  [3, "Wednesday"],
  [4, "Thursday"],
  [5, "Friday"],
  [6, "Saturday"],
] as const

const emptyAvailability: FacultyAvailabilityInput = {
  day_of_week: 1,
  starts_at_time: "",
  ends_at_time: "",
}

function dayLabel(day: number): string {
  return weekdays.find(([value]) => value === day)?.[1] ?? "Unknown day"
}

// Native time inputs report/expect "HH:mm"; the API and validation schema
// use "HH:mm:ss". Round-trip through this helper rather than asking a
// professor to type seconds by hand into a free-text field.
const asTime = (value: string) => (value ? `${value}:00`.slice(0, 8) : "")
const forTimeInput = (value: string) => value.slice(0, 5)

export function FacultyAvailabilityPanel() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const availabilitiesQuery = useFacultyAvailabilitiesQuery()
  const [editing, setEditing] = useState<FacultyAvailability | null>(null)
  const [removal, setRemoval] = useState<FacultyAvailability | null>(null)
  const [requestError, setRequestError] = useState("")
  const form = useForm<FacultyAvailabilityInput>({
    resolver: zodResolver(facultyAvailabilityInputSchema),
    defaultValues: emptyAvailability,
  })
  const invalidate = () =>
    queryClient.invalidateQueries({
      queryKey: facultyAvailabilitiesQueryKey(session?.userId ?? null),
      exact: true,
    })
  const saveMutation = useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id?: number
      input: FacultyAvailabilityInput
    }) =>
      id === undefined
        ? createFacultyAvailability(input)
        : updateFacultyAvailability(id, input),
    onSuccess: invalidate,
  })
  const removalMutation = useMutation({
    mutationFn: (row: FacultyAvailability) => deleteFacultyAvailability(row.id),
    onSuccess: async () => {
      await invalidate()
      setRemoval(null)
    },
    onError: () =>
      setRequestError(
        "The availability window could not be removed. Try again.",
      ),
  })
  const isSaving = saveMutation.isPending || removalMutation.isPending

  const save = async (input: FacultyAvailabilityInput) => {
    setRequestError("")
    try {
      await saveMutation.mutateAsync({ id: editing?.id, input })
      setEditing(null)
      form.reset(emptyAvailability)
    } catch (error) {
      if (!applyApiFieldErrors(error, form.setError))
        setRequestError(
          "Availability could not be saved. Check the connection and try again.",
        )
    }
  }

  const cancelEdit = () => {
    setEditing(null)
    form.reset(emptyAvailability)
  }

  const formFields = (
    <FieldGroup>
      <Field data-invalid={Boolean(form.formState.errors.day_of_week)}>
        <FieldLabel htmlFor="availability-day">Day</FieldLabel>
        <Controller
          control={form.control}
          name="day_of_week"
          render={({ field }) => (
            <Select
              value={String(field.value)}
              onValueChange={(value) => field.onChange(Number(value))}
              disabled={isSaving}
            >
              <SelectTrigger id="availability-day" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {weekdays.map(([value, label]) => (
                  <SelectItem key={value} value={String(value)}>
                    {label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        />
        <FieldError>{form.formState.errors.day_of_week?.message}</FieldError>
      </Field>
      <Field data-invalid={Boolean(form.formState.errors.starts_at_time)}>
        <FieldLabel htmlFor="availability-start">Start time</FieldLabel>
        <Controller
          control={form.control}
          name="starts_at_time"
          render={({ field }) => (
            <Input
              id="availability-start"
              type="time"
              disabled={isSaving}
              value={forTimeInput(field.value)}
              onChange={(event) => field.onChange(asTime(event.target.value))}
              onBlur={field.onBlur}
            />
          )}
        />
        <FieldError>
          {form.formState.errors.starts_at_time?.message}
        </FieldError>
      </Field>
      <Field data-invalid={Boolean(form.formState.errors.ends_at_time)}>
        <FieldLabel htmlFor="availability-end">End time</FieldLabel>
        <Controller
          control={form.control}
          name="ends_at_time"
          render={({ field }) => (
            <Input
              id="availability-end"
              type="time"
              disabled={isSaving}
              value={forTimeInput(field.value)}
              onChange={(event) => field.onChange(asTime(event.target.value))}
              onBlur={field.onBlur}
            />
          )}
        />
        <FieldError>{form.formState.errors.ends_at_time?.message}</FieldError>
      </Field>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={isSaving}>
          {editing ? "Update availability" : "Save availability"}
        </Button>
        {editing && (
          <Button type="button" variant="outline" onClick={cancelEdit}>
            Cancel edit
          </Button>
        )}
      </div>
    </FieldGroup>
  )

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>Availability windows</CardTitle>
        <CardDescription>
          Set the weekly windows when you can teach. These recur across terms.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <DataUseNotice notice="faculty-availability" />
        {(availabilitiesQuery.isError || requestError) && (
          <Alert variant="destructive">
            <AlertDescription>
              {requestError ||
                "Availability could not be loaded. Refresh and try again."}
            </AlertDescription>
          </Alert>
        )}
        {editing === null && (
          <form
            noValidate
            onSubmit={(event) => void form.handleSubmit(save)(event)}
          >
            {formFields}
          </form>
        )}
        <AsyncBoundary
          query={availabilitiesQuery}
          isEmpty={(rows) => rows.length === 0}
          emptyMessage="No availability windows yet."
          loadingLabel="Loading your availability windows…"
        >
          {(rows) => (
            <div className="overflow-x-auto rounded-md border">
              <Table aria-label="Saved availability windows">
                <TableHeader>
                  <TableRow>
                    <TableHead>Day</TableHead>
                    <TableHead>Window</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell className="font-medium">
                        {dayLabel(row.day_of_week)}
                      </TableCell>
                      <TableCell>
                        {formatTimeRange(row.starts_at_time, row.ends_at_time)}
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          aria-label="Edit availability"
                          onClick={() => {
                            setEditing(row)
                            form.reset({
                              day_of_week: row.day_of_week,
                              starts_at_time: row.starts_at_time,
                              ends_at_time: row.ends_at_time,
                            })
                          }}
                        >
                          Edit
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          className="ml-2"
                          aria-label="Remove availability"
                          onClick={() => setRemoval(row)}
                        >
                          Remove
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </AsyncBoundary>
      </CardContent>
      <Dialog
        open={editing !== null}
        onOpenChange={(open) => !open && cancelEdit()}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Edit availability window</DialogTitle>
            <DialogDescription>
              Update the day and time range for this window.
            </DialogDescription>
          </DialogHeader>
          <form
            noValidate
            onSubmit={(event) => void form.handleSubmit(save)(event)}
          >
            {formFields}
          </form>
        </DialogContent>
      </Dialog>
      <AlertDialog
        open={removal !== null}
        onOpenChange={(open) => !open && setRemoval(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove availability</AlertDialogTitle>
            <AlertDialogDescription>
              This removes the saved availability window. Historical workbook
              evidence remains unchanged.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={removalMutation.isPending}>
              Keep window
            </AlertDialogCancel>
            <AlertDialogAction
              disabled={removalMutation.isPending}
              onClick={() =>
                removal && void removalMutation.mutateAsync(removal)
              }
            >
              Confirm removal
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  )
}
