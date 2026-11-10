/**
 * Reads the first worksheet of an `.xlsx` workbook into rows of text, in the browser, with no
 * spreadsheet library: an `.xlsx` is a zip of XML parts, so this reads the zip's central
 * directory, inflates the few parts it needs with the browser's own `DecompressionStream`, and
 * walks the sheet XML. Used by the professor grade upload (the legacy Grading Sheet is an Excel
 * file). Formulas are read as the value Excel last saved; `.xls` (the pre-2007 binary format) is
 * not an `.xlsx` and is rejected.
 */

export class XlsxReadError extends Error {
  constructor(message: string) {
    super(message)
    this.name = "XlsxReadError"
  }
}

const MAIN_NS = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
const REL_NS =
  "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
const PACKAGE_REL_NS = "http://schemas.openxmlformats.org/package/2006/relationships"

const MAX_ROWS = 20_000
const MAX_COLUMNS = 300

interface ZipEntry {
  method: number
  compressedSize: number
  localHeaderOffset: number
}

function readZipDirectory(view: DataView): Map<string, ZipEntry> {
  // The end-of-central-directory record sits at the very end (after an optional comment).
  let eocd = -1
  for (let i = view.byteLength - 22; i >= Math.max(0, view.byteLength - 66_000); i--) {
    if (view.getUint32(i, true) === 0x06054b50) {
      eocd = i
      break
    }
  }
  if (eocd === -1) throw new XlsxReadError("This is not a valid Excel (.xlsx) file.")

  const count = view.getUint16(eocd + 10, true)
  let offset = view.getUint32(eocd + 16, true)
  const decoder = new TextDecoder()
  const entries = new Map<string, ZipEntry>()

  for (let i = 0; i < count; i++) {
    if (offset + 46 > view.byteLength || view.getUint32(offset, true) !== 0x02014b50) {
      throw new XlsxReadError("This is not a valid Excel (.xlsx) file.")
    }
    const nameLength = view.getUint16(offset + 28, true)
    const extraLength = view.getUint16(offset + 30, true)
    const commentLength = view.getUint16(offset + 32, true)
    const name = decoder.decode(
      new Uint8Array(view.buffer, view.byteOffset + offset + 46, nameLength),
    )
    entries.set(name, {
      method: view.getUint16(offset + 10, true),
      compressedSize: view.getUint32(offset + 20, true),
      localHeaderOffset: view.getUint32(offset + 42, true),
    })
    offset += 46 + nameLength + extraLength + commentLength
  }
  return entries
}

async function readZipText(
  view: DataView,
  entries: Map<string, ZipEntry>,
  name: string,
): Promise<string | null> {
  const entry = entries.get(name)
  if (!entry) return null

  const header = entry.localHeaderOffset
  if (header + 30 > view.byteLength || view.getUint32(header, true) !== 0x04034b50) {
    throw new XlsxReadError("This is not a valid Excel (.xlsx) file.")
  }
  const dataStart =
    header + 30 + view.getUint16(header + 26, true) + view.getUint16(header + 28, true)
  const data = new Uint8Array(
    view.buffer,
    view.byteOffset + dataStart,
    entry.compressedSize,
  )

  if (entry.method === 0) return new TextDecoder().decode(data)
  if (entry.method !== 8) {
    throw new XlsxReadError("This Excel file uses a compression this page cannot read. Save it as CSV instead.")
  }
  if (typeof DecompressionStream === "undefined") {
    throw new XlsxReadError("This browser cannot open Excel files. Save the sheet as CSV, or use a current Chrome, Edge, Firefox or Safari.")
  }
  const inflated = new Response(data.slice()).body?.pipeThrough(
    new DecompressionStream("deflate-raw"),
  )
  if (!inflated) throw new XlsxReadError("The Excel file could not be read.")
  return new TextDecoder().decode(await new Response(inflated).arrayBuffer())
}

function parseXml(text: string): Document {
  const doc = new DOMParser().parseFromString(text, "application/xml")
  if (doc.getElementsByTagName("parsererror").length > 0) {
    throw new XlsxReadError("The Excel file's contents could not be read.")
  }
  return doc
}

/** One cell's text: shared and inline strings resolved, booleans as TRUE/FALSE, errors as empty. */
function cellText(cell: Element, shared: readonly string[]): string {
  const type = cell.getAttribute("t")
  const raw = cell.getElementsByTagNameNS(MAIN_NS, "v")[0]?.textContent ?? ""
  if (type === "s") return shared[Number(raw)] ?? ""
  if (type === "inlineStr") return textOf(cell)
  if (type === "b") return raw === "1" ? "TRUE" : "FALSE"
  if (type === "e") return ""
  return raw
}

/** `A` is 0, `Z` is 25, `AA` is 26. */
function columnIndex(reference: string): number {
  const letters = /^[A-Z]+/i.exec(reference)?.[0].toUpperCase() ?? ""
  let index = 0
  for (const letter of letters) index = index * 26 + (letter.charCodeAt(0) - 64)
  return index - 1
}

function textOf(element: Element): string {
  // Concatenate every text run, skipping phonetic hints (`rPh`).
  let out = ""
  const walk = (node: Element) => {
    for (const child of Array.from(node.children)) {
      if (child.localName === "rPh") continue
      if (child.localName === "t") out += child.textContent ?? ""
      else walk(child)
    }
  }
  walk(element)
  return out
}

async function firstSheetPath(
  view: DataView,
  entries: Map<string, ZipEntry>,
): Promise<string> {
  const workbook = await readZipText(view, entries, "xl/workbook.xml")
  const rels = await readZipText(view, entries, "xl/_rels/workbook.xml.rels")
  if (workbook && rels) {
    const sheet = parseXml(workbook).getElementsByTagNameNS(MAIN_NS, "sheet")[0]
    const relationshipId = sheet?.getAttributeNS(REL_NS, "id")
    if (relationshipId) {
      for (const relationship of Array.from(
        parseXml(rels).getElementsByTagNameNS(PACKAGE_REL_NS, "Relationship"),
      )) {
        if (relationship.getAttribute("Id") === relationshipId) {
          const target = relationship.getAttribute("Target") ?? ""
          return target.startsWith("/")
            ? target.slice(1)
            : `xl/${target.replace(/^\.?\//, "")}`
        }
      }
    }
  }
  return "xl/worksheets/sheet1.xml"
}

/** The first worksheet as rows of text. Empty cells are `""`; numbers keep the text Excel saved. */
export async function readXlsxRows(buffer: ArrayBuffer): Promise<string[][]> {
  const view = new DataView(buffer)
  const entries = readZipDirectory(view)

  const sheetXml = await readZipText(view, entries, await firstSheetPath(view, entries))
  if (!sheetXml) throw new XlsxReadError("The Excel file has no worksheet to read.")

  const sharedXml = await readZipText(view, entries, "xl/sharedStrings.xml")
  const shared: string[] = sharedXml
    ? Array.from(parseXml(sharedXml).getElementsByTagNameNS(MAIN_NS, "si")).map(
        textOf,
      )
    : []

  const rows: string[][] = []
  for (const row of Array.from(
    parseXml(sheetXml).getElementsByTagNameNS(MAIN_NS, "row"),
  )) {
    const rowIndex = Number(row.getAttribute("r")) - 1
    if (!Number.isInteger(rowIndex) || rowIndex < 0 || rowIndex >= MAX_ROWS) continue

    const cells: string[] = []
    let next = 0
    for (const cell of Array.from(row.getElementsByTagNameNS(MAIN_NS, "c"))) {
      const reference = cell.getAttribute("r")
      const column = reference ? columnIndex(reference) : next
      next = column + 1
      if (column < 0 || column >= MAX_COLUMNS) continue

      cells[column] = cellText(cell, shared)
    }
    // Fill the holes so every row is a dense array of strings.
    rows[rowIndex] = Array.from(cells, (value) => value ?? "")
  }
  return Array.from(rows, (row) => row ?? [])
}
