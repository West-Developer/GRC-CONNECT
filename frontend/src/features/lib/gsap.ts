/**
 * Centralized GSAP initialization.
 *
 * Import `gsap` and `ScrollTrigger` from this module instead of from
 * "gsap" directly, so plugins are registered exactly once for the whole app.
 *
 * This file may only be imported in "use client" components because GSAP
 * requires the DOM and window.
 */

import gsap from "gsap"
import { ScrollTrigger } from "gsap/ScrollTrigger"

if (typeof window !== "undefined") {
  gsap.registerPlugin(ScrollTrigger)
}

export { gsap, ScrollTrigger }
