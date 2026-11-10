import os from "node:os"
import type { NextConfig } from "next"

function getDevOrigins(): string[] {
  const origins = new Set<string>(["localhost", "127.0.0.1"])

  const interfaces = os.networkInterfaces()
  for (const name of Object.keys(interfaces)) {
    for (const net of interfaces[name] ?? []) {
      if (net.family === "IPv4" || (net.family as unknown as number) === 4) {
        origins.add(net.address)
        origins.add(`${net.address}:3000`)
      }
    }
  }

  const custom = process.env.ALLOWED_DEV_ORIGINS?.split(",") ?? []
  for (const origin of custom) {
    const trimmed = origin.trim()
    if (trimmed) {
      origins.add(trimmed)
    }
  }

  return Array.from(origins)
}

/**
 * The Presentation Layer is client-rendered only (ADR 0013). It holds no
 * server session, never server-renders authorized student data, and does not
 * proxy the Laravel API — `frontend/` and `backend/` stay independently
 * runnable per ADR 0001. There is deliberately no `rewrites()` block here.
 */
const nextConfig: NextConfig = {
  devIndicators: false,
  reactStrictMode: true,
  /**
   * `NEXT_DIST_DIR` lets a production build (`start-local.ps1 -Production`, a
   * bundle-size check) write somewhere other than `.next`, so it never clobbers
   * the build cache a running `next dev` is using (ADR 0029). Default: `.next`.
   */
  distDir: process.env.NEXT_DIST_DIR ?? ".next",
  /**
   * The React Compiler auto-memoizes components, derived values, and
   * callbacks, so the large polled workspaces stop re-rendering subtrees whose
   * inputs did not change. `eslint-plugin-react-hooks` v7's recommended rules
   * already enforce the Rules of React the compiler relies on.
   */
  reactCompiler: true,
  allowedDevOrigins: getDevOrigins(),
  experimental: {
    turbopackFileSystemCacheForDev: false,
  },
}

export default nextConfig
