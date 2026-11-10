"use client"

import { useAuth } from "@/features/auth/use-auth"
import { EnrollmentChangeRequestsPanel } from "@/features/components/portal/enrollment-change-requests-panel"
import { WithdrawalRequestsPanel } from "@/features/components/portal/withdrawal-requests-panel"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"

/**
 * One place for everything a student asks the Registrar to change after
 * submitting: withdraw, drop a subject, add a subject, change a section
 * (stakeholder Doc 14). Each tab reuses the existing list and decision
 * dialog. The Registrar Head decides all four; Registrar Staff decides
 * withdrawals and reads the add/drop/change-section requests, as before.
 */
export function EnrollmentRequestsWorkspace() {
  const { session } = useAuth()
  const authorized =
    session?.role === "registrar_head" || session?.role === "registrar_staff"
  const isHead = session?.role === "registrar_head"

  return (
    <WorkspacePage
      title="Enrollment requests"
      description={
        isHead
          ? "Approve or reject student withdrawals, drops, added subjects, and section changes."
          : "Approve or reject withdrawals, and view every student drop, add, and section-change request."
      }
      unauthorized={!authorized}
    >
      <Tabs defaultValue="withdrawals" className="grid gap-4">
        <TabsList aria-label="Request type">
          <TabsTrigger value="withdrawals">Withdrawals</TabsTrigger>
          <TabsTrigger value="drops">Drops</TabsTrigger>
          <TabsTrigger value="adds">Add subject</TabsTrigger>
          <TabsTrigger value="changes">Change section</TabsTrigger>
        </TabsList>
        <TabsContent value="withdrawals" className="grid gap-4">
          <WithdrawalRequestsPanel />
        </TabsContent>
        <TabsContent value="drops" className="grid gap-4">
          <EnrollmentChangeRequestsPanel
            type="drop"
            title="Drop requests"
            caption="Drop requests"
            emptyMessage="No drop requests have been submitted."
            loadingLabel="Loading drop requests…"
          />
        </TabsContent>
        <TabsContent value="adds" className="grid gap-4">
          <EnrollmentChangeRequestsPanel
            type="add"
            title="Add-subject requests"
            caption="Add-subject requests"
            emptyMessage="No add-subject requests have been submitted."
            loadingLabel="Loading add-subject requests…"
          />
        </TabsContent>
        <TabsContent value="changes" className="grid gap-4">
          <EnrollmentChangeRequestsPanel
            type="change_section"
            title="Change-section requests"
            caption="Change-section requests"
            emptyMessage="No change-section requests have been submitted."
            loadingLabel="Loading change-section requests…"
          />
        </TabsContent>
      </Tabs>
    </WorkspacePage>
  )
}
