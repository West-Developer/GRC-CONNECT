import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { fitTableToContainer } from "@/features/lib/fit-table"

/** jsdom has no layout, so the two widths the helper compares are set by hand. */
function setWidths(
  container: HTMLElement,
  widths: { scrollWidth: number; clientWidth: number },
) {
  Object.defineProperty(container, "scrollWidth", {
    configurable: true,
    value: widths.scrollWidth,
  })
  Object.defineProperty(container, "clientWidth", {
    configurable: true,
    value: widths.clientWidth,
  })
}

function buildTable() {
  const container = document.createElement("div")
  container.innerHTML = `
    <table>
      <thead>
        <tr><th>Name</th><th>Email</th><th>Actions</th><th></th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Ana</td><td>ana@grc.test</td><td><button>Edit</button></td><td>note</td>
        </tr>
        <tr><td colspan="4">A message that spans the row</td></tr>
      </tbody>
    </table>`
  document.body.append(container)

  return { container, table: container.querySelector("table")! }
}

describe("fitTableToContainer", () => {
  let stop: (() => void) | undefined

  beforeEach(() => {
    document.body.innerHTML = ""
  })

  afterEach(() => {
    stop?.()
    stop = undefined
    vi.unstubAllGlobals()
  })

  it("labels each cell with its column heading and gives actions and spanning cells the whole row", () => {
    const { container, table } = buildTable()
    setWidths(container, { scrollWidth: 400, clientWidth: 400 })

    stop = fitTableToContainer(container, table)

    const [name, email, actions, unnamed] = Array.from(
      table.querySelectorAll("tbody tr:first-child td"),
    )
    expect(name).toHaveAttribute("data-label", "Name")
    expect(email).toHaveAttribute("data-label", "Email")
    expect(actions).toHaveAttribute("data-label", "Actions")
    expect(actions).toHaveAttribute("data-stack", "full")
    // A column with no heading has nothing to say, so it takes the whole row too.
    expect(unnamed).toHaveAttribute("data-stack", "full")
    expect(name).not.toHaveAttribute("data-stack")
    expect(table.querySelector("td[colspan]")).toHaveAttribute(
      "data-stack",
      "full",
    )
  })

  it("leaves a table that fits as an ordinary table", () => {
    const { container, table } = buildTable()
    setWidths(container, { scrollWidth: 500, clientWidth: 500 })

    stop = fitTableToContainer(container, table)

    expect(container).not.toHaveAttribute("data-stacked")
    expect(table).not.toHaveAttribute("role")
  })

  it("stacks a table that is wider than its box, and keeps table semantics while stacked", () => {
    const { container, table } = buildTable()
    setWidths(container, { scrollWidth: 900, clientWidth: 500 })

    stop = fitTableToContainer(container, table)

    expect(container).toHaveAttribute("data-stacked", "true")
    expect(table).toHaveAttribute("role", "table")
    expect(table.querySelector("thead")).toHaveAttribute("role", "rowgroup")
    expect(table.querySelector("tr")).toHaveAttribute("role", "row")
    expect(table.querySelector("th")).toHaveAttribute("role", "columnheader")
    expect(table.querySelector("td")).toHaveAttribute("role", "cell")
  })

  it("goes back to a table, and takes back only the roles it added, when the box is wide enough", () => {
    let notify: () => void = () => undefined
    vi.stubGlobal(
      "ResizeObserver",
      class {
        constructor(callback: () => void) {
          notify = callback
        }
        observe = vi.fn()
        disconnect = vi.fn()
      },
    )
    const { container, table } = buildTable()
    table.querySelector("tbody tr")!.setAttribute("role", "row-by-hand")
    setWidths(container, { scrollWidth: 900, clientWidth: 500 })
    stop = fitTableToContainer(container, table)
    expect(container).toHaveAttribute("data-stacked", "true")

    // The sidebar closes: the box grows to fit the table.
    setWidths(container, { scrollWidth: 900, clientWidth: 1000 })
    notify()

    expect(container).not.toHaveAttribute("data-stacked")
    expect(table).not.toHaveAttribute("role")
    expect(table.querySelector("td")).not.toHaveAttribute("role")
    expect(table.querySelector("tbody tr")).toHaveAttribute(
      "role",
      "row-by-hand",
    )

    // And back again when it narrows.
    setWidths(container, { scrollWidth: 900, clientWidth: 600 })
    notify()
    expect(container).toHaveAttribute("data-stacked", "true")
  })

  it("never overwrites a label written by hand, but rewrites its own", () => {
    const { container, table } = buildTable()
    table
      .querySelector("tbody tr:first-child td")!
      .setAttribute("data-label", "Student")
    setWidths(container, { scrollWidth: 400, clientWidth: 400 })
    stop = fitTableToContainer(container, table)

    expect(table.querySelector("tbody tr:first-child td")).toHaveAttribute(
      "data-label",
      "Student",
    )

    // A heading changes: the label this module wrote follows it.
    table.querySelectorAll("th")[1].textContent = "Login email"
    table.querySelector("tbody")!.append(document.createElement("tr"))
    return Promise.resolve().then(() => {
      expect(
        table.querySelectorAll("tbody tr:first-child td")[1],
      ).toHaveAttribute("data-label", "Login email")
    })
  })

  it("labels rows that are added later, such as the next page of results", async () => {
    const { container, table } = buildTable()
    setWidths(container, { scrollWidth: 400, clientWidth: 400 })
    stop = fitTableToContainer(container, table)

    const row = document.createElement("tr")
    row.innerHTML = "<td>Ben</td><td>ben@grc.test</td><td>x</td><td>y</td>"
    table.querySelector("tbody")!.append(row)
    await Promise.resolve()

    expect(row.children[1]).toHaveAttribute("data-label", "Email")
    expect(row.children[2]).toHaveAttribute("data-stack", "full")
  })

  it("stops watching when asked", async () => {
    const { container, table } = buildTable()
    setWidths(container, { scrollWidth: 400, clientWidth: 400 })
    const cleanup = fitTableToContainer(container, table)
    cleanup()

    const row = document.createElement("tr")
    row.innerHTML = "<td>Cy</td><td>cy@grc.test</td>"
    table.querySelector("tbody")!.append(row)
    await Promise.resolve()

    expect(row.children[0]).not.toHaveAttribute("data-label")
  })
})
