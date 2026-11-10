import type { EnrollmentBlock } from "@/features/schemas/enrollment-block-schema"

/**
 * Natural order, so `IT102` sorts before `IT110` and a longer block number is
 * never placed ahead of a shorter one the way plain text order would.
 */
function compareBlockCodes(a: EnrollmentBlock, b: EnrollmentBlock): number {
  return a.block_code.localeCompare(b.block_code, undefined, {
    numeric: true,
    sensitivity: "base",
  })
}

/**
 * The one section a Regular student is shown at a time: the first block (in
 * block-number order) that they can actually choose. When the first section
 * fills up, the next one becomes current on the next refresh.
 *
 * - A block that has seats but cannot be chosen for another reason (for
 *   example its schedule is incomplete) is passed over in favour of a later
 *   block that can be chosen.
 * - If nothing can be chosen but some block still has seats (the enrollment
 *   window is closed, say), that block is returned so its reason is visible.
 * - `null` means every block is full, or there are none.
 */
export function pickCurrentBlock(
  blocks: readonly EnrollmentBlock[],
): EnrollmentBlock | null {
  const ordered = [...blocks].sort(compareBlockCodes)
  const hasSeats = (block: EnrollmentBlock) => block.seats_remaining > 0

  return (
    ordered.find((block) => block.is_selectable && hasSeats(block)) ??
    ordered.find(hasSeats) ??
    null
  )
}
