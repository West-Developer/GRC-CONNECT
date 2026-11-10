import { readFileSync } from "node:fs"
import { resolve } from "node:path"

import { describe, expect, it } from "vitest"

const globalStyles = readFileSync(
  resolve(process.cwd(), "src/app/globals.css"),
  "utf8",
)

describe("wide-screen entry layouts", () => {
  it("keeps the public landing shell edge-to-edge", () => {
    expect(globalStyles).toMatch(
      /\.institutional-shell\s*\{[^}]*width:\s*100%;[^}]*margin-inline:\s*0;[^}]*border-inline:\s*0;[^}]*box-shadow:\s*none;/s,
    )
  })

  it("allows the primary GRC Connect action to wrap inside its card", () => {
    expect(globalStyles).toMatch(
      /\.grc-connect-hero__action\s+\[data-slot="button"\]\s*\{[^}]*min-width:\s*0;[^}]*max-width:\s*100%;[^}]*min-height:\s*2\.75rem;[^}]*white-space:\s*normal;/s,
    )
  })
})
