"use client"

import { Button } from "@/features/components/ui/button"

/**
 * An account's email address with a button that copies it. The button sits under the address, not beside it, so
 * the column stays narrow.
 */
export function ItControlEmailCell({
  name,
  email,
}: {
  name: string
  email: string
}) {
  return (
    <div className="flex flex-col items-start gap-1">
      <span>{email}</span>
      <Button
        type="button"
        size="xs"
        variant="outline"
        aria-label={`Copy email for ${name}`}
        onClick={() => void navigator.clipboard?.writeText(email)}
      >
        Copy email
      </Button>
    </div>
  )
}
