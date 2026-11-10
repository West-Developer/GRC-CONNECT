import { describe, expect, it } from "vitest"

import { readXlsxRows, XlsxReadError } from "@/features/lib/read-xlsx"
import { buildXlsx } from "@/tests/xlsx-fixture"

describe("readXlsxRows", () => {
  it("reads text and number cells of a compressed workbook into rows", async () => {
    const buffer = buildXlsx([
      ["GRADING SHEET"],
      [],
      ["StudentID", "Student Name", "Numeric Grade"],
      ["21204", "ALEJANDRO, ANDRIN JAMES MARTINEZ", 1.75],
    ])

    expect(await readXlsxRows(buffer)).toEqual([
      ["GRADING SHEET"],
      [],
      ["StudentID", "Student Name", "Numeric Grade"],
      ["21204", "ALEJANDRO, ANDRIN JAMES MARTINEZ", "1.75"],
    ])
  })

  it("keeps a cell in its own column when earlier cells in the row are empty", async () => {
    const buffer = buildXlsx([["a", null, null, "d"], [null, "b"]])

    expect(await readXlsxRows(buffer)).toEqual([
      ["a", "", "", "d"],
      ["", "b"],
    ])
  })

  it("reads a workbook whose parts are stored without compression", async () => {
    const buffer = buildXlsx([["x", "y"]], { compress: false })

    expect(await readXlsxRows(buffer)).toEqual([["x", "y"]])
  })

  it("decodes escaped characters in text", async () => {
    const buffer = buildXlsx([["R&D <Lab>"]])

    expect(await readXlsxRows(buffer)).toEqual([["R&D <Lab>"]])
  })

  it("rejects a file that is not an Excel workbook", async () => {
    const notExcel = new TextEncoder().encode("Student Number,Grade\n1,1.00")

    await expect(readXlsxRows(notExcel.buffer)).rejects.toThrow(
      XlsxReadError,
    )
  })
})
