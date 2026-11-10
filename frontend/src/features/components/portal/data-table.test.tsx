import { render, screen, within } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { DataTable } from "@/features/components/portal/data-table"

interface Row {
  id: number
  studentNumber: string
  status: string
}

const rows: Row[] = [
  { id: 1, studentNumber: "2026-0001", status: "Enrolled" },
  { id: 2, studentNumber: "2026-0002", status: "Dropped" },
]

const columns = [
  {
    key: "student",
    header: "Student",
    render: (row: Row) => row.studentNumber,
  },
  { key: "status", header: "Status", render: (row: Row) => row.status },
]

describe("DataTable", () => {
  it("renders a captioned table with scoped column headers", () => {
    render(
      <DataTable
        caption="Class roster for Section 1A"
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
      />,
    )

    const table = screen.getByRole("table", {
      name: "Class roster for Section 1A",
    })
    const headers = within(table).getAllByRole("columnheader")
    expect(headers.map((header) => header.textContent)).toEqual([
      "Student",
      "Status",
    ])
    expect(within(table).getByText("2026-0001")).toBeInTheDocument()
  })

  it("renders every row in both the table and the card fallback", () => {
    render(
      <DataTable
        caption="Class roster"
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
      />,
    )

    expect(screen.getAllByText("2026-0001")).toHaveLength(2)
    expect(screen.getAllByText("Dropped")).toHaveLength(2)
  })

  it("uses a custom card renderer when provided", () => {
    render(
      <DataTable
        caption="Class roster"
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
        renderCard={(row) => <p>custom card for {row.studentNumber}</p>}
      />,
    )

    expect(screen.getByText("custom card for 2026-0001")).toBeInTheDocument()
  })

  it("titles the default mobile card with the first column's value, not the raw row key", () => {
    render(
      <DataTable
        caption="Class roster"
        columns={columns}
        rows={rows}
        rowKey={(row) => row.id}
      />,
    )

    expect(
      screen.getByRole("heading", { level: 3, name: "2026-0001" }),
    ).toBeInTheDocument()
    expect(screen.queryByText("1")).not.toBeInTheDocument()
  })

  it("renders the empty message instead of an empty table when rows is empty", () => {
    render(
      <DataTable
        caption="Class roster"
        columns={columns}
        rows={[]}
        rowKey={(row: Row) => row.id}
        emptyMessage="No students are enrolled in this section yet."
      />,
    )

    expect(
      screen.getByText("No students are enrolled in this section yet."),
    ).toBeInTheDocument()
    expect(screen.queryByRole("table")).not.toBeInTheDocument()
  })
})
