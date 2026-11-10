import { knownPortalModuleIds } from "@/features/portal/role-capabilities"

const fallbackPath = "/portal"
const safeOrigin = "https://grc.local"

export function getSafeReturnPath(value: string | null | undefined): string {
  if (typeof value !== "string" || value.length === 0) {
    return fallbackPath
  }

  const hasControlCharacter = [...value].some((character) => {
    const codePoint = character.codePointAt(0)

    return codePoint !== undefined && (codePoint <= 31 || codePoint === 127)
  })

  if (
    value !== value.trim() ||
    value.includes("\\") ||
    value.includes("#") ||
    hasControlCharacter
  ) {
    return fallbackPath
  }

  const [rawPath] = value.split("?", 1)

  if (rawPath.includes("%")) {
    return fallbackPath
  }

  let target: URL

  try {
    target = new URL(value, safeOrigin)
  } catch {
    return fallbackPath
  }

  if (
    target.origin !== safeOrigin ||
    target.username ||
    target.password ||
    target.hash
  ) {
    return fallbackPath
  }

  if (target.pathname === fallbackPath) {
    return `${target.pathname}${target.search}`
  }

  const match = /^\/portal\/([a-z0-9-]+)$/u.exec(target.pathname)

  if (!match || !knownPortalModuleIds.has(match[1])) {
    return fallbackPath
  }

  return `${target.pathname}${target.search}`
}
