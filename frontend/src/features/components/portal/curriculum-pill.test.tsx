import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import {
  CurriculumPill,
  curriculumPillText,
} from "@/features/components/portal/curriculum-pill"

const LONG_NAME = "BS Information Technology Curriculum 2024-2029"

describe("curriculumPillText", () => {
  it("drops the word Curriculum and does not repeat a year the name already has", () => {
    expect(
      curriculumPillText(LONG_NAME, { effectiveSchoolYear: "2024-2029" }),
    ).toBe("BS Information Technology 2024-2029")
  })

  it("appends the effective school year when the name lacks it", () => {
    expect(
      curriculumPillText("BS Information Technology Curriculum", {
        effectiveSchoolYear: "2024-2029",
      }),
    ).toBe("BS Information Technology (2024-2029)")
  })

  it("appends a suffix such as the curriculum age", () => {
    expect(curriculumPillText(LONG_NAME, { suffix: "New curriculum" })).toBe(
      "BS Information Technology 2024-2029 · New curriculum",
    )
  })
})

describe("CurriculumPill", () => {
  it("shows the whole label, wrapped instead of clipped, with the full name as its title", () => {
    render(
      <CurriculumPill
        name={LONG_NAME}
        effectiveSchoolYear="2024-2029"
        suffix="New curriculum"
      />,
    )

    const label = screen.getByText(
      "BS Information Technology 2024-2029 · New curriculum",
    )
    const pill = label.closest("[data-slot='badge']")

    expect(pill).toHaveAttribute("title", LONG_NAME)
    // The base badge is whitespace-nowrap + fixed height; both must be overridden.
    expect(pill).toHaveClass("whitespace-normal", "h-auto")
    expect(pill).not.toHaveClass("whitespace-nowrap")
    expect(label).not.toHaveClass("truncate")
  })
})
