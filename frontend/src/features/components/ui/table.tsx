"use client"

import * as React from "react"

import { fitTableToContainer } from "@/features/lib/fit-table"
import { cn } from "@/features/lib/utils"

/**
 * The box a table lives in. It keeps the table inside it so no table in the system ever needs sideways
 * scrolling: cells and headings wrap, and a table that still cannot fit is stacked into label / value rows
 * (see `fitTableToContainer`). `Table` uses it; a table written out by hand (a plain `<table>`) goes inside
 * one of these instead of an `overflow-x-auto` div. A table that must keep its exact layout, such as a printed
 * document, opts out with `data-no-fit`; one that already stacks itself on phones (`data-stack-mobile`) is
 * left to do so.
 */
function TableFit({ className, ...props }: React.ComponentProps<"div">) {
  const containerRef = React.useRef<HTMLDivElement>(null)

  React.useLayoutEffect(() => {
    const container = containerRef.current
    const table = container?.querySelector("table")
    if (
      !container ||
      !table ||
      table.hasAttribute("data-no-fit") ||
      table.hasAttribute("data-stack-mobile")
    ) {
      return
    }

    return fitTableToContainer(container, table)
  }, [])

  return (
    <div
      data-slot="table-container"
      className={cn("relative w-full overflow-x-auto", className)}
      {...props}
      ref={containerRef}
    />
  )
}

function Table({ className, ...props }: React.ComponentProps<"table">) {
  return (
    <TableFit>
      <table
        data-slot="table"
        className={cn("w-full caption-bottom pr-4 text-sm", className)}
        {...props}
      />
    </TableFit>
  )
}

function TableHeader({ className, ...props }: React.ComponentProps<"thead">) {
  return (
    <thead
      data-slot="table-header"
      className={cn("[&_tr]:border-b", className)}
      {...props}
    />
  )
}

function TableBody({ className, ...props }: React.ComponentProps<"tbody">) {
  return (
    <tbody
      data-slot="table-body"
      className={cn("[&_tr:last-child]:border-0", className)}
      {...props}
    />
  )
}

function TableFooter({ className, ...props }: React.ComponentProps<"tfoot">) {
  return (
    <tfoot
      data-slot="table-footer"
      className={cn(
        "border-t bg-muted/50 font-medium [&>tr]:last:border-b-0",
        className,
      )}
      {...props}
    />
  )
}

function TableRow({ className, ...props }: React.ComponentProps<"tr">) {
  return (
    <tr
      data-slot="table-row"
      className={cn(
        "border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted",
        className,
      )}
      {...props}
    />
  )
}

function TableHead({ className, ...props }: React.ComponentProps<"th">) {
  return (
    <th
      data-slot="table-head"
      className={cn(
        "h-10 px-2 text-left align-middle font-medium text-foreground [&:has([role=checkbox])]:pr-0",
        className,
      )}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: React.ComponentProps<"td">) {
  return (
    <td
      data-slot="table-cell"
      className={cn(
        "p-2 align-middle [&:has([role=checkbox])]:pr-0",
        className,
      )}
      {...props}
    />
  )
}

function TableCaption({
  className,
  ...props
}: React.ComponentProps<"caption">) {
  return (
    <caption
      data-slot="table-caption"
      className={cn("mt-4 text-sm text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Table,
  TableFit,
  TableBody,
  TableCaption,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
}
