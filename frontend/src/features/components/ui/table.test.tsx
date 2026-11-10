import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import {
  Table,
  TableBody,
  TableCell,
  TableFit,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"

function SampleTable(props: React.ComponentProps<"table">) {
  return (
    <Table aria-label="Accounts" {...props}>
      <TableHeader>
        <TableRow>
          <TableHead>Name</TableHead>
          <TableHead>Email</TableHead>
          <TableHead>Actions</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow>
          <TableCell>Ana</TableCell>
          <TableCell>ana@grc.test</TableCell>
          <TableCell>
            <button type="button">Edit</button>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
  )
}

describe("Table", () => {
  it("lets cells and headings wrap, so a wide table can narrow instead of scrolling", () => {
    render(<SampleTable />)

    const cell = screen.getByRole("cell", { name: "Ana" })
    const heading = screen.getByRole("columnheader", { name: "Email" })
    expect(cell.className).not.toContain("whitespace-nowrap")
    expect(heading.className).not.toContain("whitespace-nowrap")
  })

  it("labels each cell with its column heading, ready for the stacked layout", () => {
    render(<SampleTable />)

    expect(screen.getByRole("cell", { name: "Ana" })).toHaveAttribute(
      "data-label",
      "Name",
    )
    expect(screen.getByRole("cell", { name: "ana@grc.test" })).toHaveAttribute(
      "data-label",
      "Email",
    )
    const actions = screen.getByRole("button", { name: "Edit" }).closest("td")
    expect(actions).toHaveAttribute("data-label", "Actions")
    expect(actions).toHaveAttribute("data-stack", "full")
  })

  it("leaves a table that opts out exactly as it was written", () => {
    render(<SampleTable data-no-fit />)

    expect(screen.getByRole("cell", { name: "Ana" })).not.toHaveAttribute(
      "data-label",
    )
  })

  it("leaves a table that already stacks itself on phones to do so", () => {
    render(<SampleTable data-stack-mobile />)

    expect(screen.getByRole("cell", { name: "Ana" })).not.toHaveAttribute(
      "data-label",
    )
  })
})

describe("TableFit", () => {
  it("gives a hand-written table the same treatment", () => {
    render(
      <TableFit>
        <table aria-label="Payments">
          <thead>
            <tr>
              <th>Date</th>
              <th>Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Oct 15</td>
              <td>1,000.00</td>
            </tr>
          </tbody>
        </table>
      </TableFit>,
    )

    expect(screen.getByRole("cell", { name: "1,000.00" })).toHaveAttribute(
      "data-label",
      "Amount",
    )
  })
})
