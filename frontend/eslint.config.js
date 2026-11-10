import js from "@eslint/js"
import globals from "globals"
import jsxA11y from "eslint-plugin-jsx-a11y"
import reactHooks from "eslint-plugin-react-hooks"
import tseslint from "typescript-eslint"
import { defineConfig, globalIgnores } from "eslint/config"

/**
 * `eslint-config-next` is deliberately NOT used. It bundles
 * `eslint-plugin-react@7.37.5`, whose peer range stops at ESLint 9.7, so it
 * crashes on this repo's ESLint 10 (`contextOrFilename.getFilename is not a
 * function`). It also drags in a `brace-expansion` advisory chain that fails
 * `npm audit --audit-level=moderate`. The type-checked `typescript-eslint`
 * rules plus `react-hooks` below are stricter than what it would have added.
 * Revisit once it supports ESLint 10.
 */
export default defineConfig([
  globalIgnores([".next", ".next-e2e", "coverage", "next-env.d.ts"]),
  {
    files: ["**/*.{ts,tsx}"],
    extends: [
      js.configs.recommended,
      ...tseslint.configs.recommendedTypeChecked,
      ...tseslint.configs.stylisticTypeChecked,
      reactHooks.configs.flat.recommended,
      jsxA11y.flatConfigs.recommended,
    ],
    languageOptions: {
      ecmaVersion: "latest",
      globals: {
        ...globals.browser,
        ...globals.es2024,
      },
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
  },
])
