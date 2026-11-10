"use client"

import type { ReactNode } from "react"

import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Empty, EmptyDescription } from "@/features/components/ui/empty"
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"

export interface DataTableColumn<Row> {
  key: string
  header: string
  render: (row: Row) => ReactNode
  cellClassName?: string
}

export interface DataTableProps<Row> {
  /** Screen-reader-only by default (sr-only) — the table normally sits under a heading that already names it visually. */
  caption: string
  columns: DataTableColumn<Row>[]
  rows: readonly Row[]
  rowKey: (row: Row) => string | number
  /** Overrides the auto-generated card for narrow viewports; falls back to a column-label/value list built from `columns`. */
  renderCard?: (row: Row) => ReactNode
  /** When set, renders this instead of an empty table/card list once `rows` is empty — callers no longer need their own ternary guard. */
  emptyMessage?: string
}

/**
 * A `<table>` with `<caption>` and `<th scope="col">` — neither existed
 * anywhere in the 13 tables audited across the portal — plus the card
 * fallback for narrow viewports that only 2 of those 13 tables had.
 */
export function DataTable<Row>({
  caption,
  columns,
  rows,
  rowKey,
  renderCard,
  emptyMessage,
}: DataTableProps<Row>) {
  if (rows.length === 0 && emptyMessage) {
    return (
      <Empty>
        <EmptyDescription>{emptyMessage}</EmptyDescription>
      </Empty>
    )
  }

  return (
    <>
      <div className="hidden min-w-0 md:block">
        <Table>
          <TableCaption className="sr-only">{caption}</TableCaption>
          <TableHeader>
            <TableRow>
              {columns.map((column) => (
                <TableHead key={column.key} scope="col">
                  {column.header}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row) => (
              <TableRow key={rowKey(row)}>
                {columns.map((column) => (
                  <TableCell key={column.key} className={column.cellClassName}>
                    {column.render(row)}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <div className="grid gap-3 md:hidden">
        {rows.map((row) =>
          renderCard ? (
            <div key={rowKey(row)}>{renderCard(row)}</div>
          ) : (
            <Card key={rowKey(row)}>
              <CardHeader>
                {/* The first column is the row's natural identifying value
                    (a code, a name, a number) in every current caller —
                    a real database id was shown here before, which meant
                    nothing to a reader. */}
                <CardTitle level={3}>
                  {columns[0]?.render(row) ?? String(rowKey(row))}
                </CardTitle>
              </CardHeader>
              <CardContent>
                {/* `minmax(0, 1fr)` columns and `anywhere` wrapping: a long value
                    (an email address, a long name) breaks to the next line
                    instead of pushing the card past the edge of a phone. */}
                <dl className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-2 text-sm">
                  {columns.slice(1).map((column) => (
                    <div key={column.key} className="contents">
                      <dt className="min-w-0 text-muted-foreground [overflow-wrap:anywhere]">
                        {column.header}
                      </dt>
                      <dd className="min-w-0 [overflow-wrap:anywhere]">
                        {column.render(row)}
                      </dd>
                    </div>
                  ))}
                </dl>
              </CardContent>
            </Card>
          ),
        )}
      </div>
    </>
  )
}
