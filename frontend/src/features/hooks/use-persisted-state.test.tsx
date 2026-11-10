import { screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it } from "vitest"

import { usePersistedState } from "@/features/hooks/use-persisted-state"
import { browserFormDraftStore } from "@/features/services/form-draft-store"
import { renderWithSession } from "@/tests/render-app"

const KEY = "test.search"

function SearchBox() {
  const [text, setText] = usePersistedState<string>(KEY, "")

  return (
    <label>
      Search
      <input value={text} onChange={(event) => setText(event.target.value)} />
    </label>
  )
}

function render(userId: string | null = "5") {
  return renderWithSession(<SearchBox />, {
    session:
      userId === null
        ? null
        : {
            userId,
            displayName: "Admission Staff",
            role: "admission_staff",
            signedInAt: "2026-10-04T00:00:00Z",
          },
    status: userId === null ? "anonymous" : "authenticated",
  })
}

describe("usePersistedState", () => {
  it("starts from the initial value when nothing was saved", () => {
    render()

    expect(screen.getByLabelText("Search")).toHaveValue("")
  })

  it("is still there after the screen is left and opened again", async () => {
    const user = userEvent.setup()
    const first = render()
    await user.type(screen.getByLabelText("Search"), "Amina")

    first.unmount()
    render()

    expect(screen.getByLabelText("Search")).toHaveValue("Amina")
  })

  it("keeps people apart", async () => {
    const user = userEvent.setup()
    const first = render("5")
    await user.type(screen.getByLabelText("Search"), "Amina")
    first.unmount()

    render("6")

    expect(screen.getByLabelText("Search")).toHaveValue("")
  })

  it("leaves nothing behind once the value is back to the initial one", async () => {
    const user = userEvent.setup()
    render()

    await user.type(screen.getByLabelText("Search"), "A")
    expect(browserFormDraftStore.read("5", KEY)).not.toBeNull()
    await user.clear(screen.getByLabelText("Search"))

    expect(browserFormDraftStore.read("5", KEY)).toBeNull()
  })

  it("saves nothing when nobody is signed in", async () => {
    const user = userEvent.setup()
    render(null)

    await user.type(screen.getByLabelText("Search"), "Amina")

    expect(browserFormDraftStore.read("5", KEY)).toBeNull()
  })

  it("ignores a saved value of the wrong kind", () => {
    browserFormDraftStore.write("5", KEY, { value: 42 })

    render()

    expect(screen.getByLabelText("Search")).toHaveValue("")
  })
})
