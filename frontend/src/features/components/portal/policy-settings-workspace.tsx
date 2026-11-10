"use client"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataTable } from "@/features/components/portal/data-table"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Badge } from "@/features/components/ui/badge"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { usePolicySettingsQuery } from "@/features/hooks/use-dashboard"
import type { PolicyValueState } from "@/features/schemas/dashboard-schema"

function statusBadgeVariant(
  status: PolicyValueState["status"],
): "default" | "secondary" | "outline" {
  if (status === "configured") return "default"
  if (status === "provisional") return "secondary"
  return "outline"
}

export function PolicySettingsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "registrar_head"
  const query = usePolicySettingsQuery(authorized)

  return (
    <WorkspacePage
      title="Policy settings"
      description="Read-only: which institutional policy values are confirmed, which have a provisional default, and which have no configuration mechanism yet. See each row's PRD reference for what GRC still needs to decide."
      unauthorized={!authorized}
      lastUpdated={query.dataUpdatedAt}
    >
      <AsyncBoundary
        query={query}
        isEmpty={(summary) => summary.values.length === 0}
        emptyMessage="No policy values are tracked yet."
        loadingLabel="Loading policy settings…"
      >
        {(summary) => (
          <Card>
            <CardHeader>
              <CardTitle level={2}>Policy values</CardTitle>
            </CardHeader>
            <CardContent>
              <DataTable
                caption="Policy settings"
                rowKey={(row) => row.key}
                rows={summary.values}
                columns={[
                  {
                    key: "label",
                    header: "Policy value",
                    render: (row) => row.label,
                  },
                  {
                    key: "current_value",
                    header: "Current value",
                    render: (row) => row.current_value ?? "—",
                  },
                  {
                    key: "status",
                    header: "Status",
                    render: (row) => (
                      <Badge variant={statusBadgeVariant(row.status)}>
                        {row.status_label}
                      </Badge>
                    ),
                  },
                  {
                    key: "description",
                    header: "Description",
                    render: (row) => row.description,
                  },
                  {
                    key: "prd_reference",
                    header: "PRD reference",
                    render: (row) => row.prd_reference ?? "—",
                  },
                ]}
              />
            </CardContent>
          </Card>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
