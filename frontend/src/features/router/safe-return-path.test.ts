import { describe, expect, it } from "vitest"

import { getSafeReturnPath } from "@/features/router/safe-return-path"

describe("getSafeReturnPath", () => {
  it.each([
    ["/portal", "/portal"],
    ["/portal?from=landing", "/portal?from=landing"],
    ["/portal/enrollment", "/portal/enrollment"],
    ["/portal/enrollment?tab=available", "/portal/enrollment?tab=available"],
    ["/portal/reports", "/portal/reports"],
  ])("keeps a known internal portal target", (input, expected) => {
    expect(getSafeReturnPath(input)).toBe(expected)
  })

  it.each([
    [undefined],
    [null],
    [""],
    ["   "],
    ["/"],
    ["/unknown"],
    ["/portal/unknown"],
    ["/portal/enrollment/extra"],
    ["https://evil.example/portal"],
    ["//evil.example/portal"],
    ["javascript:alert(1)"],
    ["https%3A%2F%2Fevil.example"],
    ["https%253A%252F%252Fevil.example"],
    ["%2Fportal%2Fenrollment"],
    ["/portal/%65nrollment"],
    ["/portal\\enrollment"],
    [" /portal"],
    ["/portal#private"],
  ])("falls back for an unsafe or unknown target", (input) => {
    expect(getSafeReturnPath(input)).toBe("/portal")
  })
})
