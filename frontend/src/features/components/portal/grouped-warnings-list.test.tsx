import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it } from "vitest"

import { GroupedWarningsList } from "@/features/components/portal/grouped-warnings-list"
import type { ScheduleGenerationWarning } from "@/features/schemas/schedule-generation-schema"

describe("GroupedWarningsList", () => {
  it("groups warnings by type and renders a run-level notice as a plain line with no toggle", () => {
    const warnings: ScheduleGenerationWarning[] = [
      {
        type: "room_metadata_incomplete",
        message: "Section CS101-A is missing room metadata.",
        entity_id: 12,
      },
      {
        type: "room_metadata_incomplete",
        message: "Section CS101-B is missing room metadata.",
        entity_id: 34,
      },
      {
        type: "no_historical_data",
        message: "No historical data was available for this term.",
        entity_id: null,
      },
    ]

    render(<GroupedWarningsList warnings={warnings} />)

    expect(
      screen.getByText("No historical data was available for this term."),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: /show|hide/i }),
    ).toBeInTheDocument()
    expect(
      screen.getByText("2 sections have incomplete room metadata"),
    ).toBeInTheDocument()
    expect(screen.queryByText("#12")).not.toBeInTheDocument()
  })

  it("reveals chips for a group on toggle and can hide them again", async () => {
    const user = userEvent.setup()
    const warnings: ScheduleGenerationWarning[] = [
      {
        type: "room_metadata_incomplete",
        message: "Section CS101-A is missing room metadata.",
        entity_id: 12,
      },
      {
        type: "room_metadata_incomplete",
        message: "Section CS101-B is missing room metadata.",
        entity_id: 34,
      },
    ]

    render(<GroupedWarningsList warnings={warnings} />)

    const trigger = screen.getByRole("button", { name: "Show 2" })
    expect(screen.queryByText("#12")).not.toBeInTheDocument()

    await user.click(trigger)

    expect(screen.getByText("#12")).toBeInTheDocument()
    expect(screen.getByText("#34")).toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Hide" }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Hide" }))

    expect(screen.queryByText("#12")).not.toBeInTheDocument()
  })

  it("still groups and renders a toggle for a lone warning that carries an entity_id", async () => {
    const user = userEvent.setup()
    const warnings: ScheduleGenerationWarning[] = [
      {
        type: "faculty_overload",
        message: "Professor is over the configured teaching load threshold.",
        entity_id: 7,
      },
    ]

    render(<GroupedWarningsList warnings={warnings} />)

    const trigger = screen.getByRole("button", { name: "Show 1" })
    expect(screen.queryByText("#7")).not.toBeInTheDocument()

    await user.click(trigger)

    expect(screen.getByText("#7")).toBeInTheDocument()
  })

  it("falls back to a humanized label for an unrecognized warning type", () => {
    const warnings: ScheduleGenerationWarning[] = [
      {
        type: "some_future_warning_type",
        message: "First future warning.",
        entity_id: 1,
      },
      {
        type: "some_future_warning_type",
        message: "Second future warning.",
        entity_id: 2,
      },
    ]

    render(<GroupedWarningsList warnings={warnings} />)

    expect(
      screen.getByText("Some Future Warning Type (2)"),
    ).toBeInTheDocument()
  })

  it("truncates a long message for a chip when no entity_id is present", async () => {
    const user = userEvent.setup()
    const longMessage =
      "This is a deliberately long warning message that should be truncated once it is rendered as a chip because entity_id is null for this warning."
    const warnings: ScheduleGenerationWarning[] = [
      {
        type: "some_future_warning_type",
        message: longMessage,
        entity_id: null,
      },
      {
        type: "some_future_warning_type",
        message: "Second warning in the same group.",
        entity_id: null,
      },
    ]

    render(<GroupedWarningsList warnings={warnings} />)

    await user.click(screen.getByRole("button", { name: "Show 2" }))

    expect(screen.queryByText(longMessage)).not.toBeInTheDocument()
    expect(
      screen.getByText(`${longMessage.slice(0, 48)}…`),
    ).toBeInTheDocument()
  })
})
