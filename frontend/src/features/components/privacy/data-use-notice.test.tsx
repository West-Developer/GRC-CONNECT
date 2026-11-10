import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"
import { axe } from "vitest-axe"

import { AdmissionPrivacyAcknowledgement } from "@/features/components/privacy/admission-privacy-acknowledgement"
import { DataUseNotice } from "@/features/components/privacy/data-use-notice"
import {
  AGREEMENT_TEXT,
  INLINE_NOTICES,
  type InlineNoticeId,
} from "@/features/lib/privacy/notice-content"

describe("DataUseNotice", () => {
  it("says what the form asks for, why, and who sees it, in the notice's own words", () => {
    render(<DataUseNotice notice="profile-change" />)

    const text = INLINE_NOTICES["profile-change"]
    const notice = screen.getByRole("complementary", { name: "Data privacy" })

    expect(notice).toHaveTextContent("Data privacy.")
    expect(notice).toHaveTextContent(text.collects)
    expect(notice).toHaveTextContent(text.purpose)
    expect(notice).toHaveTextContent(`Who can see it: ${text.seenBy}.`)
  })

  it("links to the whole notice in a new tab", () => {
    render(<DataUseNotice notice="sign-in" />)

    const link = screen.getByRole("link", { name: "Data Privacy Notice" })

    expect(link).toHaveAttribute("href", "/privacy")
    expect(link).toHaveAttribute("target", "_blank")
    expect(link).toHaveAttribute("rel", expect.stringContaining("noreferrer"))
  })

  it("reads as a full sentence for every form", () => {
    for (const id of Object.keys(INLINE_NOTICES) as InlineNoticeId[]) {
      const { unmount } = render(<DataUseNotice notice={id} />)
      const text = screen.getByRole("complementary").textContent ?? ""

      expect(text, id).toMatch(/We ask for .+, .+\. Who can see it: .+\./)
      unmount()
    }
  })

  it("has no accessibility violations", async () => {
    const { container } = render(<DataUseNotice notice="tor-upload" />)

    expect(await axe(container)).toHaveNoViolations()
  })
})

describe("AdmissionPrivacyAcknowledgement", () => {
  it("shows what the student is told and asks Admission to confirm they agreed", () => {
    render(
      <AdmissionPrivacyAcknowledgement
        checked={false}
        onCheckedChange={() => undefined}
      />,
    )

    expect(
      screen.getByRole("heading", {
        name: "Data privacy: show this to the student first",
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/Create and identify your student record and account/),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/Who can see it: Admission Staff/),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("link", {
        name: /Open the Data Privacy Notice for the student/,
      }),
    ).toHaveAttribute("href", "/privacy")
    expect(
      screen.getByRole("checkbox", { name: AGREEMENT_TEXT.admission }),
    ).not.toBeChecked()
  })

  it("reports the tick back to the form", () => {
    const calls: boolean[] = []
    render(
      <AdmissionPrivacyAcknowledgement
        checked
        onCheckedChange={(value) => calls.push(value)}
      />,
    )

    expect(screen.getByRole("checkbox")).toBeChecked()
  })

  it("has no accessibility violations", async () => {
    const { container } = render(
      <AdmissionPrivacyAcknowledgement
        checked={false}
        onCheckedChange={() => undefined}
      />,
    )

    expect(await axe(container)).toHaveNoViolations()
  })
})
