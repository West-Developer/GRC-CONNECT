import { readFile } from "node:fs/promises"

import { describe, expect, it } from "vitest"

const basePanelPattern = /\.queue-live-panel\s*\{([^}]*)\}/
const keyframesPattern = /@keyframes queue-call-emphasis\s*\{([\s\S]*)\}/

function findClosingBrace(source: string, openingBraceIndex: number): number {
  let depth = 0

  for (let index = openingBraceIndex; index < source.length; index += 1) {
    if (source[index] === "{") {
      depth += 1
    }
    if (source[index] === "}") {
      depth -= 1
      if (depth === 0) {
        return index
      }
    }
  }

  throw new Error("Expected a closing CSS brace.")
}

describe("StudentQueueLivePanel motion CSS", () => {
  it("keeps unavailable kiosk labels on a compact status scale", async () => {
    const css = await readFile("src/app/globals.css", "utf8")
    const kioskStatusMatch =
      /\.queue-live-panel--kiosk \.queue-live-panel__ticket-number--status,\s*\.queue-live-panel--kiosk \.queue-live-panel__serving-number--status\s*\{([^}]*)\}/.exec(
        css,
      )

    expect(kioskStatusMatch?.[1]).toMatch(
      /font-size:\s*clamp\(1\.75rem,\s*4vw,\s*2\.75rem\)/,
    )
  })

  it("keeps call motion opt-in and limits it to transform and opacity", async () => {
    const css = await readFile("src/app/globals.css", "utf8")
    const baseMatch = basePanelPattern.exec(css)
    const motionStart = css.indexOf(
      "@media (prefers-reduced-motion: no-preference)",
    )
    const motionOpeningBrace = css.indexOf("{", motionStart)
    const motionEnd = findClosingBrace(css, motionOpeningBrace)
    const motionBlock = css.slice(motionStart, motionEnd + 1)
    const outsideMotion = `${css.slice(0, motionStart)}${css.slice(motionEnd + 1)}`
    const keyframesMatch = keyframesPattern.exec(motionBlock)
    const calledSurfaceMatch = /\.queue-live-panel--called\s*\{([^}]*)\}/.exec(
      css,
    )
    const nestedSurfaceMatch =
      /\.queue-live-panel--called \.queue-live-panel__ticket,[\s\S]*?\[data-slot="card-footer"\]\s*\{([^}]*)\}/.exec(
        css,
      )

    expect(baseMatch?.[1]).not.toMatch(/transition\s*:/)
    expect(motionBlock).toMatch(
      /\.queue-live-panel--called\s*\{\s*animation:\s*queue-call-emphasis/,
    )
    expect(keyframesMatch?.[1]).toMatch(/opacity\s*:/)
    expect(keyframesMatch?.[1]).toMatch(/transform\s*:/)
    expect(keyframesMatch?.[1]).not.toMatch(/background|color|shadow/i)
    expect(outsideMotion).not.toMatch(
      /queue-call-emphasis|\.queue-live-panel--called\s*\{[^}]*animation\s*:/,
    )
    expect(calledSurfaceMatch?.[1]).toMatch(/background:\s*var\(--primary\)/)
    expect(calledSurfaceMatch?.[1]).toMatch(
      /color:\s*var\(--primary-foreground\)/,
    )
    expect(css).toMatch(
      /\.queue-live-panel--called\s+\.queue-live-panel__label\s*\{[^}]*color:\s*var\(--primary-foreground\)/,
    )
    expect(nestedSurfaceMatch?.[1]).toMatch(
      /background:\s*var\(--primary-foreground\)/,
    )
    expect(nestedSurfaceMatch?.[1]).toMatch(/color:\s*var\(--foreground\)/)
    expect(css).toMatch(
      /\.queue-live-panel--called\s+\.queue-live-panel__ticket\s+\.queue-live-panel__label,[\s\S]*?color:\s*var\(--foreground\)/,
    )
    expect(css).toMatch(
      /\.queue-live-panel--called\s+\[data-slot="card-footer"\]\s+p,[\s\S]*?color:\s*var\(--foreground\)/,
    )
    expect(css).toMatch(
      /\.queue-live-panel--called\s+\.queue-live-panel__call-alert\s+\[data-slot="alert-description"\]\s*\{[^}]*color:\s*var\(--foreground\)/,
    )
  })
})
