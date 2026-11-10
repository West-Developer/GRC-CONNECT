import type { GuideShot } from "./types"
import manifest from "./shots.json"

/**
 * The screenshots the guide shows, keyed by id. `shots.json` is written by
 * `scripts/user-guide/capture.mjs` (the images live in `public/user-guide/`);
 * recapture after the interface changes instead of editing it by hand.
 */
export const userGuideShots: Readonly<Record<string, GuideShot>> = manifest
