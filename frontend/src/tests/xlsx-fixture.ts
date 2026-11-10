import { deflateRawSync } from "node:zlib"

/**
 * Builds a real `.xlsx` (a zip of XML parts) for tests, so the reader is exercised against the
 * actual container format. Only what the reader needs: a workbook, its relationships, shared
 * strings and one worksheet. Pass `rows` as text (kept as shared strings) or numbers (stored
 * as numeric cells, like Excel does for 1.75).
 */
export function buildXlsx(
  rows: readonly (readonly (string | number | null)[])[],
  options: { compress?: boolean } = {},
): ArrayBuffer {
  const shared: string[] = []
  const sharedIndex = (text: string): number => {
    const found = shared.indexOf(text)
    if (found !== -1) return found
    shared.push(text)
    return shared.length - 1
  }
  const column = (index: number): string => {
    let name = ""
    for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) {
      name = String.fromCharCode(65 + ((n - 1) % 26)) + name
    }
    return name
  }
  const escape = (text: string) =>
    text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")

  const sheetRows = rows
    .map((cells, rowIndex) => {
      const xml = cells
        .map((value, columnIndex) => {
          if (value === null || value === "") return ""
          const ref = `${column(columnIndex)}${rowIndex + 1}`
          return typeof value === "number"
            ? `<c r="${ref}"><v>${value}</v></c>`
            : `<c r="${ref}" t="s"><v>${sharedIndex(value)}</v></c>`
        })
        .join("")
      return xml ? `<row r="${rowIndex + 1}">${xml}</row>` : ""
    })
    .join("")

  const files: Record<string, string> = {
    "[Content_Types].xml":
      '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
    "xl/workbook.xml":
      '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
    "xl/_rels/workbook.xml.rels":
      '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
    "xl/worksheets/sheet1.xml": `<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>${sheetRows}</sheetData></worksheet>`,
    "xl/sharedStrings.xml": `<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">${shared
      .map((text) => `<si><t xml:space="preserve">${escape(text)}</t></si>`)
      .join("")}</sst>`,
  }

  return zip(files, options.compress ?? true)
}

function zip(files: Record<string, string>, compress: boolean): ArrayBuffer {
  const encoder = new TextEncoder()
  const locals: Uint8Array[] = []
  const centrals: Uint8Array[] = []
  let offset = 0

  for (const [name, content] of Object.entries(files)) {
    const nameBytes = encoder.encode(name)
    const raw = encoder.encode(content)
    const data = compress ? new Uint8Array(deflateRawSync(raw)) : raw
    const method = compress ? 8 : 0

    const local = new Uint8Array(30 + nameBytes.length + data.length)
    const localView = new DataView(local.buffer)
    localView.setUint32(0, 0x04034b50, true)
    localView.setUint16(4, 20, true)
    localView.setUint16(8, method, true)
    localView.setUint32(18, data.length, true)
    localView.setUint32(22, raw.length, true)
    localView.setUint16(26, nameBytes.length, true)
    local.set(nameBytes, 30)
    local.set(data, 30 + nameBytes.length)

    const central = new Uint8Array(46 + nameBytes.length)
    const centralView = new DataView(central.buffer)
    centralView.setUint32(0, 0x02014b50, true)
    centralView.setUint16(4, 20, true)
    centralView.setUint16(6, 20, true)
    centralView.setUint16(10, method, true)
    centralView.setUint32(20, data.length, true)
    centralView.setUint32(24, raw.length, true)
    centralView.setUint16(28, nameBytes.length, true)
    centralView.setUint32(42, offset, true)
    central.set(nameBytes, 46)

    locals.push(local)
    centrals.push(central)
    offset += local.length
  }

  const centralSize = centrals.reduce((sum, part) => sum + part.length, 0)
  const end = new Uint8Array(22)
  const endView = new DataView(end.buffer)
  endView.setUint32(0, 0x06054b50, true)
  endView.setUint16(8, centrals.length, true)
  endView.setUint16(10, centrals.length, true)
  endView.setUint32(12, centralSize, true)
  endView.setUint32(16, offset, true)

  const out = new Uint8Array(offset + centralSize + 22)
  let cursor = 0
  for (const part of [...locals, ...centrals, end]) {
    out.set(part, cursor)
    cursor += part.length
  }
  return out.buffer
}
