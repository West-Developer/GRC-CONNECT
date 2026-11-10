/**
 * Keeps a data table inside its box, so no table in the system ever needs sideways scrolling.
 *
 * Cells wrap by default, which is enough for most tables. A table that still cannot fit (many columns on a
 * narrow screen) is stacked: each row becomes a small block of label / value pairs, the labels being the
 * column headings (the CSS is in `globals.css`, keyed on `data-stacked`). It is decided by measuring the real
 * table in the real container every time the container is resized or the rows change, so it follows the
 * sidebar being opened or collapsed, the card padding and the actual content, not a guessed breakpoint.
 *
 * Only attributes are written (`data-label`, `data-stack`, `data-stacked`, and `role` while stacked, because
 * some browsers drop table semantics once a table is laid out as blocks). Nothing is added to or removed from
 * the table's children, so React's own rendering is never disturbed.
 */

const STACKED = "data-stacked"
/** Marks a `data-label` this module wrote, so it may rewrite it; a label written by hand is never touched. */
const AUTO_LABEL = "data-label-auto"
/** Marks a `role` this module added while stacked, so it can take exactly those back. */
const AUTO_ROLE = "data-stack-role"

const ROLE_OF: Readonly<Record<string, string>> = {
  TABLE: "table",
  THEAD: "rowgroup",
  TBODY: "rowgroup",
  TFOOT: "rowgroup",
  TR: "row",
  TD: "cell",
}

/** The text of the column headings: the last header row, one entry per column (a spanning heading repeats). */
function headerLabels(table: HTMLTableElement): string[] {
  const head = table.tHead
  const row = head?.rows[head.rows.length - 1]
  const labels: string[] = []
  if (!row) return labels

  for (const cell of Array.from(row.cells)) {
    const text = (cell.textContent ?? "").replace(/\s+/g, " ").trim()
    for (let span = 0; span < cell.colSpan; span++) labels.push(text)
  }

  return labels
}

function bodyRows(table: HTMLTableElement): HTMLTableRowElement[] {
  const rows = Array.from(table.tBodies).flatMap((body) =>
    Array.from(body.rows),
  )
  if (table.tFoot) rows.push(...Array.from(table.tFoot.rows))

  return rows
}

/** Gives every body cell the heading of its column, and makes action and spanning cells use the whole row. */
function labelCells(table: HTMLTableElement): void {
  const labels = headerLabels(table)

  for (const row of bodyRows(table)) {
    let column = 0
    for (const cell of Array.from(row.cells)) {
      const label = labels[column] ?? ""
      if (!cell.hasAttribute("data-label") || cell.hasAttribute(AUTO_LABEL)) {
        cell.setAttribute("data-label", label)
        cell.setAttribute(AUTO_LABEL, "")
      }
      if (
        !cell.hasAttribute("data-stack") &&
        (cell.colSpan > 1 || label === "" || /^actions?$/i.test(label))
      ) {
        cell.setAttribute("data-stack", "full")
      }
      column += cell.colSpan
    }
  }
}

function setStackedRoles(table: HTMLTableElement, on: boolean): void {
  const elements: Element[] = [
    table,
    ...Array.from(table.querySelectorAll("thead, tbody, tfoot, tr, th, td")),
  ]

  for (const element of elements) {
    if (on) {
      if (element.hasAttribute("role")) continue
      const role =
        element.tagName === "TH"
          ? element.closest("thead")
            ? "columnheader"
            : "rowheader"
          : ROLE_OF[element.tagName]
      if (role) {
        element.setAttribute("role", role)
        element.setAttribute(AUTO_ROLE, "")
      }
    } else if (element.hasAttribute(AUTO_ROLE)) {
      element.removeAttribute("role")
      element.removeAttribute(AUTO_ROLE)
    }
  }
}

/** True when the table, laid out as an ordinary table, is wider than the box it lives in. */
function tableOverflows(container: HTMLElement): boolean {
  return container.scrollWidth > container.clientWidth + 1
}

/**
 * Starts keeping `table` inside `container`. Returns the function that stops it. Safe where layout does not
 * exist (tests): a box with no width never overflows, so nothing is stacked.
 */
export function fitTableToContainer(
  container: HTMLElement,
  table: HTMLTableElement,
): () => void {
  let fitting = false
  let lastWidth = -1

  const fit = () => {
    if (fitting) return
    fitting = true
    try {
      labelCells(table)
      // Measure the table as a table first, then decide.
      container.removeAttribute(STACKED)
      setStackedRoles(table, false)
      if (tableOverflows(container)) {
        container.setAttribute(STACKED, "true")
        setStackedRoles(table, true)
      }
    } finally {
      fitting = false
    }
  }

  fit()
  lastWidth = container.clientWidth

  const resize =
    typeof ResizeObserver === "undefined"
      ? null
      : new ResizeObserver(() => {
          // Stacking changes the height, never the width: only a new width needs a new decision.
          const width = container.clientWidth
          if (width === lastWidth) return
          lastWidth = width
          fit()
        })
  resize?.observe(container)

  const mutations =
    typeof MutationObserver === "undefined"
      ? null
      : new MutationObserver(() => fit())
  mutations?.observe(table, { childList: true, subtree: true })

  return () => {
    resize?.disconnect()
    mutations?.disconnect()
  }
}
