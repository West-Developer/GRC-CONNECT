import { render } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Sheet, SheetContent, SheetTitle } from "@/features/components/ui/sheet"

describe("responsive panel primitives", () => {
  it("keeps Sheets, dialogs, and confirmation dialogs within a safe scrollable viewport", () => {
    render(
      <>
        <Sheet defaultOpen>
          <SheetContent>
            <SheetTitle>Sheet title</SheetTitle>
          </SheetContent>
        </Sheet>
        <Dialog defaultOpen>
          <DialogContent>
            <DialogTitle>Dialog title</DialogTitle>
            <DialogDescription>Dialog description</DialogDescription>
          </DialogContent>
        </Dialog>
        <AlertDialog defaultOpen>
          <AlertDialogContent>
            <AlertDialogTitle>Confirmation title</AlertDialogTitle>
            <AlertDialogDescription>
              Confirmation description
            </AlertDialogDescription>
          </AlertDialogContent>
        </AlertDialog>
      </>,
    )

    const sheet = document.querySelector<HTMLElement>(
      '[data-slot="sheet-content"]',
    )
    const dialog = document.querySelector<HTMLElement>(
      '[data-slot="dialog-content"]',
    )
    const alertDialog = document.querySelector<HTMLElement>(
      '[data-slot="alert-dialog-content"]',
    )

    expect(sheet).not.toBeNull()
    expect(dialog).not.toBeNull()
    expect(alertDialog).not.toBeNull()

    if (!sheet || !dialog || !alertDialog) return

    expect(sheet.className).toContain("overscroll-contain")
    expect(sheet.className).toContain("max-h-[100dvh]")

    for (const panel of [dialog, alertDialog]) {
      expect(panel.className).toContain("overscroll-contain")
      expect(panel.className).toContain("max-h-[calc(100dvh-2rem)]")
    }
  })
})
