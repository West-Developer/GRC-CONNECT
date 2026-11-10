"use client"

import { toast } from "sonner"

import { Button } from "@/features/components/ui/button"
import { useResendQueueLinkMutation } from "@/features/hooks/use-student-records"
import type { StudentProfile } from "@/features/schemas/admission-schema"

/**
 * Sends a freshman's "view the live queue" link again — the old link stops
 * working. Each button owns its own request, so a row in the directory only
 * shows "Resending…" for itself.
 */
export function ResendQueueLinkButton({
  profile,
  label = "Resend queue link",
  size,
  variant = "outline",
  onResent,
}: {
  profile: StudentProfile
  label?: string
  size?: "sm"
  variant?: "outline" | "secondary"
  onResent?: (updated: StudentProfile) => void
}) {
  const resend = useResendQueueLinkMutation()

  return (
    <Button
      type="button"
      size={size}
      variant={variant}
      disabled={resend.isPending}
      onClick={() =>
        void resend
          .mutateAsync(profile.id)
          .then((updated) => {
            onResent?.(updated)
            toast.success(`Queue link resent to ${updated.email}.`)
          })
          .catch(() => {
            toast.error("Failed to resend the queue link.")
          })
      }
    >
      {resend.isPending ? "Resending…" : label}
    </Button>
  )
}
