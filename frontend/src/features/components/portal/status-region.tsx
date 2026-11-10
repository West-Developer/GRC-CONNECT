import type { ReactNode } from "react"

export interface StatusRegionProps {
  message: ReactNode | null
  className?: string
}

/**
 * A polite live region for async result/result-count changes — filter
 * submissions, page changes, mutation outcomes. Only one place in the portal
 * (admission-provisioning-workspace.tsx) had anything like this before.
 * Renders nothing when there is no message, so mounting it unconditionally
 * is safe.
 */
export function StatusRegion({ message, className }: StatusRegionProps) {
  if (message === null) {
    return null
  }

  return (
    <p role="status" aria-live="polite" className={className}>
      {message}
    </p>
  )
}
