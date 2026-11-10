"use client"

import { useCallback, useEffect, useState } from "react"

import { printRegion, removePrintFrames } from "@/features/lib/print-region"

/**
 * The print region a button belongs to: the `[data-print-region]` inside the nearest
 * `[data-print-scope]` ancestor (`PrintDocument` renders both). Null when there is none.
 */
export function findPrintRegion(from: Element | null): HTMLElement | null {
  return (
    from
      ?.closest("[data-print-scope]")
      ?.querySelector<HTMLElement>("[data-print-region]") ?? null
  )
}

/**
 * Prints a document region through `printRegion` (an isolated iframe), so the portal around it
 * can never end up on the paper or push the document off the page.
 *
 * `print(source)` prints `source`; without one it prints the last (top-most, so the open dialog's)
 * `[data-print-region]` on the page, and only when the page has none does it fall back to the
 * browser's own page print.
 */
export function usePrintDocument(): {
  print: (source?: HTMLElement | null) => void
  isPrinting: boolean
} {
  const [isPrinting, setIsPrinting] = useState(false)

  // Leaving the page mid-print should not leave a hidden frame behind.
  useEffect(() => removePrintFrames, [])

  const print = useCallback((source?: HTMLElement | null) => {
    const region =
      source ??
      Array.from(
        document.querySelectorAll<HTMLElement>("[data-print-region]"),
      ).at(-1) ??
      null
    if (!region) {
      window.print()
      return
    }
    setIsPrinting(true)
    void printRegion(region, region.dataset.printTitle)
      .catch(() => window.print())
      .finally(() => setIsPrinting(false))
  }, [])

  return { print, isPrinting }
}
