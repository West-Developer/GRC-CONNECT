import react from "@vitejs/plugin-react"
import { defineConfig } from "vitest/config"

// Vite is no longer the application bundler — Next.js is. It survives here
// only as Vitest's transform pipeline, which still needs the React plugin for
// JSX. The `@/*` alias comes from tsconfig.json via `resolve.tsconfigPaths`.
export default defineConfig({
  plugins: [react()],
  resolve: {
    tsconfigPaths: true,
  },
  test: {
    environment: "jsdom",
    setupFiles: ["./src/tests/setup.tsx"],
    clearMocks: true,
    restoreMocks: true,
    // The full jsdom portal suite exceeds the local runner's concurrent
    // worker capacity and lets a timed-out test contaminate later assertions.
    // Keep testTimeout strict; serialize files so every test observes an
    // isolated DOM and mock lifecycle.
    fileParallelism: false,
    testTimeout: 10_000,
  },
})
