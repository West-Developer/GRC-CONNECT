"use client"

import { useMemo, useState } from "react"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
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
import { useArchiveAndCreateNextTermMutation } from "@/features/hooks/use-reference-data"
import type { AcademicTerm } from "@/features/schemas/reference-data-schema"
import {
  formatAcademicTerm,
  getNextAcademicTermSequence,
} from "@/features/services/reference-data-service"

/**
 * The last step of a semester, automated for seamless transition: the Registrar archives
 * the current term, and the system automatically determines and opens the next chronological
 * semester (1st -> 2nd of same SY, 2nd -> 1st of next SY) as a Draft term with no manual typing required.
 */
export function ArchiveTermDialog({
  term,
  trigger,
}: {
  term: AcademicTerm
  trigger: React.ReactNode
}) {
  const [open, setOpen] = useState(false)
  const [requestError, setRequestError] = useState("")
  const mutation = useArchiveAndCreateNextTermMutation()

  const nextTerm = useMemo(
    () => term.next_term_sequence ?? getNextAcademicTermSequence(term),
    [term],
  )

  const submit = async () => {
    setRequestError("")
    try {
      await mutation.mutateAsync({
        academicTermId: term.id,
        next: {
          school_year: nextTerm.school_year,
          semester: nextTerm.semester,
        },
      })
      setOpen(false)
    } catch {
      setRequestError(
        "The term could not be archived. Please check your connection and retry.",
      )
    }
  }

  const nextSemesterLabel =
    nextTerm.semester === "1st" ? "1st Semester" : "2nd Semester"

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        setOpen(nextOpen)
        if (!nextOpen) {
          setRequestError("")
        }
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            Archive {formatAcademicTerm(term)}
          </DialogTitle>
          <DialogDescription>
            The current semester will be closed and kept in history — nothing is deleted.
            The next semester will be opened automatically as a Draft term.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-4 py-2">
          {requestError && (
            <Alert variant="destructive">
              <AlertDescription>{requestError}</AlertDescription>
            </Alert>
          )}
          <div className="rounded-lg border bg-muted/40 p-4 space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Automatic Next Semester
              </span>
              <Badge variant="outline" className="text-xs">
                Next in sequence
              </Badge>
            </div>
            <div className="space-y-1">
              <div className="text-lg font-bold text-foreground">
                {nextTerm.school_year} · {nextSemesterLabel}
              </div>
              <p className="text-xs text-muted-foreground">
                Will be automatically created as a Draft term for section planning and enrollment setup.
              </p>
            </div>
          </div>
        </div>
        <DialogFooter className="mt-4">
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
            disabled={mutation.isPending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={() => void submit()}
            disabled={mutation.isPending}
          >
            {mutation.isPending
              ? "Archiving..."
              : `Archive and open ${nextTerm.school_year} · ${nextTerm.semester === "1st" ? "1st Sem" : "2nd Sem"}`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
