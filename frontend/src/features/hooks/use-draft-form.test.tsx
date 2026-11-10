import { act, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { useEffect } from "react"
import type { UseFormReturn } from "react-hook-form"
import { describe, expect, it } from "vitest"

import { useDraftForm } from "@/features/hooks/use-draft-form"
import { browserFormDraftStore } from "@/features/services/form-draft-store"
import { renderWithSession } from "@/tests/render-app"

interface Values {
  first_name: string
  email: string
  program_id: number
}

const DEFAULTS: Values = { first_name: "", email: "", program_id: 0 }
const FORM_KEY = "test.form"

interface Handles {
  clear: () => void
  discard: () => void
  form: UseFormReturn<Values>
  rebase: () => void
}

function Harness({ onHandles }: { onHandles?: (handles: Handles) => void }) {
  const { form, draft } = useDraftForm<Values>({
    formKey: FORM_KEY,
    defaultValues: DEFAULTS,
  })

  useEffect(() => {
    onHandles?.({
      clear: draft.clear,
      discard: draft.discard,
      form,
      rebase: draft.rebase,
    })
  })

  return (
    <form>
      <label>
        First name
        <input {...form.register("first_name")} />
      </label>
      <label>
        Email
        <input {...form.register("email")} />
      </label>
      <output aria-label="draft state">
        {JSON.stringify({
          restored: draft.restored,
          hasContent: draft.hasContent,
        })}
      </output>
    </form>
  )
}

function session(userId: string) {
  return {
    userId,
    displayName: "Admission Staff",
    role: "admission_staff" as const,
    signedInAt: "2026-10-04T00:00:00Z",
  }
}

function renderHarness(userId = "5", onHandles?: (handles: Handles) => void) {
  return renderWithSession(<Harness onHandles={onHandles} />, {
    session: session(userId),
  })
}

function draftState(): { restored: boolean; hasContent: boolean } {
  return JSON.parse(
    screen.getByLabelText("draft state").textContent,
  ) as { restored: boolean; hasContent: boolean }
}

describe("useDraftForm", () => {
  it("starts from the draft saved earlier, on the very first render", () => {
    browserFormDraftStore.write("5", FORM_KEY, {
      first_name: "Ana",
      email: "ana@grc.test",
      program_id: 0,
    })

    renderHarness()

    expect(screen.getByLabelText("First name")).toHaveValue("Ana")
    expect(screen.getByLabelText("Email")).toHaveValue("ana@grc.test")
    expect(draftState()).toEqual({ restored: true, hasContent: true })
  })

  it("saves what is typed once typing pauses", async () => {
    const user = userEvent.setup()
    renderHarness()

    await user.type(screen.getByLabelText("First name"), "Ana")

    await waitFor(() =>
      expect(browserFormDraftStore.read("5", FORM_KEY)).toMatchObject({
        first_name: "Ana",
      }),
    )
    await waitFor(() => expect(draftState().hasContent).toBe(true))
  })

  it("keeps the last keystrokes when the form goes away straight after typing", async () => {
    const user = userEvent.setup()
    const { unmount } = renderHarness()

    await user.type(screen.getByLabelText("Email"), "ana@grc.test")
    unmount()

    expect(browserFormDraftStore.read("5", FORM_KEY)).toMatchObject({
      email: "ana@grc.test",
    })
  })

  it("saves nothing for a form nobody typed into", () => {
    const { unmount } = renderHarness()

    unmount()

    expect(browserFormDraftStore.read("5", FORM_KEY)).toBeNull()
  })

  it("does not show one person's draft to another", () => {
    browserFormDraftStore.write("5", FORM_KEY, { first_name: "Ana" })

    renderHarness("6")

    expect(screen.getByLabelText("First name")).toHaveValue("")
    expect(draftState()).toEqual({ restored: false, hasContent: false })
  })

  it("does not save anything when nobody is signed in", async () => {
    const user = userEvent.setup()
    const { unmount } = renderWithSession(<Harness />, {
      session: null,
      status: "anonymous",
    })

    await user.type(screen.getByLabelText("First name"), "Ana")
    unmount()

    expect(browserFormDraftStore.read("5", FORM_KEY)).toBeNull()
  })

  it("forgets the draft when asked to", async () => {
    const user = userEvent.setup()
    let handles: Handles | undefined
    renderHarness("5", (next) => {
      handles = next
    })
    await user.type(screen.getByLabelText("First name"), "Ana")
    await waitFor(() =>
      expect(browserFormDraftStore.read("5", FORM_KEY)).not.toBeNull(),
    )

    act(() => handles?.clear())

    expect(browserFormDraftStore.read("5", FORM_KEY)).toBeNull()
    expect(draftState().hasContent).toBe(false)
  })

  it("discard puts the form back to how it started and forgets the draft", async () => {
    const user = userEvent.setup()
    let handles: Handles | undefined
    renderHarness("5", (next) => {
      handles = next
    })
    await user.type(screen.getByLabelText("First name"), "Ana")
    await waitFor(() =>
      expect(browserFormDraftStore.read("5", FORM_KEY)).not.toBeNull(),
    )

    act(() => handles?.discard())

    expect(screen.getByLabelText("First name")).toHaveValue("")
    expect(browserFormDraftStore.read("5", FORM_KEY)).toBeNull()
    expect(draftState().hasContent).toBe(false)
  })

  it("does not keep a draft for a value the form was reset to on purpose", async () => {
    const user = userEvent.setup()
    let handles: Handles | undefined
    renderHarness("5", (next) => {
      handles = next
    })
    await user.type(screen.getByLabelText("First name"), "Ana")
    act(() => handles?.form.setValue("program_id", 11))
    await waitFor(() =>
      expect(browserFormDraftStore.read("5", FORM_KEY)).toMatchObject({
        program_id: 11,
      }),
    )

    // What a successful save does: blank the person, keep the program for the next one.
    act(() => {
      handles?.form.reset({ ...DEFAULTS, program_id: 11 })
      handles?.clear()
      handles?.rebase()
    })

    await waitFor(() => expect(draftState().hasContent).toBe(false))
    expect(browserFormDraftStore.read("5", FORM_KEY)).toBeNull()
    // …but typing again is content again.
    await user.type(screen.getByLabelText("Email"), "b@grc.test")
    await waitFor(() =>
      expect(browserFormDraftStore.read("5", FORM_KEY)).toMatchObject({
        email: "b@grc.test",
        program_id: 11,
      }),
    )
  })
})
