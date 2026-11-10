"use client"

import { Button } from "@/features/components/ui/button"

export interface PaginatorProps {
  currentPage: number
  lastPage: number
  onPageChange: (page: number) => void
  disabled?: boolean
}

/**
 * Replaces the 6 verbatim-duplicated Prev/Next paginators across the portal.
 * Plain `Button`s rather than the anchor-based `ui/pagination.tsx` kit — every
 * existing use here changes displayed data via a click handler, not
 * navigation to a new URL, and an `<a>` without a real `href` is both a
 * semantic mismatch and, in some browsers, not keyboard-focusable.
 */
export function Paginator({
  currentPage,
  lastPage,
  onPageChange,
  disabled = false,
}: PaginatorProps) {
  return (
    <nav aria-label="Pagination" className="flex flex-wrap items-center gap-3">
      <Button
        type="button"
        variant="outline"
        disabled={disabled || currentPage <= 1}
        onClick={() => onPageChange(currentPage - 1)}
      >
        Previous page
      </Button>
      <span>
        Page {currentPage} of {lastPage}
      </span>
      <Button
        type="button"
        variant="outline"
        disabled={disabled || currentPage >= lastPage}
        onClick={() => onPageChange(currentPage + 1)}
      >
        Next page
      </Button>
    </nav>
  )
}
