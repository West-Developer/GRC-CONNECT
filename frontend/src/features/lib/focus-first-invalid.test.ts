import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { focusFirstInvalidField } from "@/features/lib/focus-first-invalid"

describe("focusFirstInvalidField", () => {
  // The shared test setup gives HTMLElement a no-op scrollIntoView (Radix needs one in jsdom).
  let scrollIntoView: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    scrollIntoView = vi
      .spyOn(window.HTMLElement.prototype, "scrollIntoView")
      .mockImplementation(() => undefined)
  })
  afterEach(() => {
    scrollIntoView.mockRestore()
    document.body.innerHTML = ""
  })

  it("scrolls to and focuses the first field marked invalid, whichever kind of control it is", async () => {
    document.body.innerHTML = `
      <form id="form">
        <div data-invalid="false"><input id="ok" /></div>
        <div data-invalid="true" id="first"><label>Category</label><button role="combobox" id="category">Pick</button></div>
        <div data-invalid="true"><input id="later" /></div>
      </form>`

    focusFirstInvalidField(document.getElementById("form"))

    await vi.waitFor(() => expect(scrollIntoView).toHaveBeenCalledTimes(1))
    expect(scrollIntoView.mock.contexts[0]).toBe(document.getElementById("first"))
    expect(document.activeElement).toBe(document.getElementById("category"))
  })

  it("focuses an input that is itself marked aria-invalid", async () => {
    document.body.innerHTML = `<form id="form"><input id="a" /><input id="b" aria-invalid="true" /></form>`

    focusFirstInvalidField(document.getElementById("form"))

    await vi.waitFor(() => expect(document.activeElement).toBe(document.getElementById("b")))
  })

  it("does nothing when nothing is invalid or there is no form", async () => {
    document.body.innerHTML = `<form id="form"><input id="a" data-invalid="false" /></form>`

    focusFirstInvalidField(document.getElementById("form"))
    focusFirstInvalidField(null)
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(scrollIntoView).not.toHaveBeenCalled()
  })
})
