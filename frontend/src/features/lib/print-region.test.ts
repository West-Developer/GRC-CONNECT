import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { printRegion, removePrintFrames } from "@/features/lib/print-region"

describe("printRegion", () => {
  const printSpy = vi.fn()
  const originalContentWindow = Object.getOwnPropertyDescriptor(
    HTMLIFrameElement.prototype,
    "contentWindow",
  )

  beforeEach(() => {
    printSpy.mockClear()
    // Whatever window the frame ends up with, calling its print() must be observable and must
    // never open a real print dialog.
    Object.defineProperty(HTMLIFrameElement.prototype, "contentWindow", {
      configurable: true,
      get(this: HTMLIFrameElement) {
        const win = originalContentWindow?.get?.call(this) as Window | null
        if (win) Object.defineProperty(win, "print", { value: printSpy, configurable: true })
        return win
      },
    })
    document.head.insertAdjacentHTML(
      "beforeend",
      '<style id="app-style">.cor-document { color: red; }</style>',
    )
    document.documentElement.classList.add("dark")
    document.body.innerHTML = `
      <header id="portal-header">Portal header</header>
      <div id="region" data-print-region><p>The document</p></div>
    `
  })

  afterEach(() => {
    removePrintFrames()
    document.getElementById("app-style")?.remove()
    document.documentElement.classList.remove("dark")
    if (originalContentWindow)
      Object.defineProperty(
        HTMLIFrameElement.prototype,
        "contentWindow",
        originalContentWindow,
      )
  })

  it("prints only the region, in a hidden frame that carries the app styles", async () => {
    const region = document.getElementById("region")!

    await printRegion(region, "Certificate of Registration")

    expect(printSpy).toHaveBeenCalledTimes(1)
    const frame = document.querySelector<HTMLIFrameElement>(
      "iframe[data-print-frame]",
    )
    expect(frame).not.toBeNull()
    expect(frame?.style.width).toBe("0px")
    const doc = frame?.contentDocument
    expect(doc?.body.textContent).toContain("The document")
    // Nothing else from the portal page is in the printed document.
    expect(doc?.getElementById("portal-header")).toBeNull()
    expect(doc?.body.getAttribute("data-printing")).toBe("document")
    expect(doc?.title).toBe("Certificate of Registration")
    expect(doc?.getElementById("app-style")).not.toBeNull()
    // Printed on white, whatever theme the portal is in.
    expect(doc?.documentElement.classList.contains("dark")).toBe(false)
    // The page itself is untouched.
    expect(document.getElementById("portal-header")).not.toBeNull()
  })

  it("replaces an earlier frame instead of stacking them", async () => {
    const region = document.getElementById("region")!

    await printRegion(region)
    await printRegion(region)

    expect(document.querySelectorAll("iframe[data-print-frame]")).toHaveLength(1)
    expect(printSpy).toHaveBeenCalledTimes(2)
  })

  it("removePrintFrames clears any leftover frame", async () => {
    await printRegion(document.getElementById("region")!)

    removePrintFrames()

    expect(document.querySelector("iframe[data-print-frame]")).toBeNull()
  })
})
