"use client"

import { CheckIcon } from "lucide-react"

import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { cn } from "@/features/lib/utils"

export interface StatusStepperStage {
  label: string
  done: boolean
  current: boolean
}

export interface StatusStepperProps {
  stages: readonly StatusStepperStage[]
  /** When set, renders this instead of the stepper — for a terminal/stopped state (rejected, cancelled, withdrawn). */
  stoppedMessage?: string
}

/**
 * A horizontal progress stepper for a record moving through a fixed sequence
 * of stages — originally built for the student's enrollment status, promoted
 * here (Phase 8b) so other status-tracking workspaces can reuse it instead of
 * inventing their own. Domain-specific stage derivation stays with the
 * caller; this component only renders whatever `stages` it's given.
 */
export function StatusStepper({ stages, stoppedMessage }: StatusStepperProps) {
  if (stoppedMessage) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{stoppedMessage}</AlertDescription>
      </Alert>
    )
  }

  return (
    <ol className="flex flex-row flex-wrap items-start gap-x-2 gap-y-3 sm:flex-nowrap sm:gap-0">
      {stages.map((stage, index) => (
        <li
          key={stage.label}
          className="flex min-w-14 items-center gap-1.5 sm:min-w-0 sm:flex-1 sm:gap-3"
        >
          <div className="flex flex-col items-center gap-1 sm:w-full">
            <span
              className={cn(
                "flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium sm:size-7 sm:text-xs",
                stage.done
                  ? "border-primary bg-primary text-primary-foreground"
                  : stage.current
                    ? "border-primary text-primary"
                    : "border-border text-muted-foreground",
              )}
            >
              {stage.done ? (
                <CheckIcon aria-hidden="true" className="size-3.5 sm:size-4" />
              ) : (
                index + 1
              )}
            </span>
            <span
              className={cn(
                "text-center text-[11px] leading-tight sm:text-xs",
                stage.done || stage.current
                  ? "font-medium text-foreground"
                  : "text-muted-foreground",
              )}
            >
              {stage.label}
            </span>
          </div>
          {index < stages.length - 1 && (
            <div
              aria-hidden="true"
              className={cn(
                "hidden h-px flex-1 sm:block",
                stages[index + 1]?.done || stages[index + 1]?.current
                  ? "bg-primary"
                  : "bg-border",
              )}
            />
          )}
        </li>
      ))}
    </ol>
  )
}
