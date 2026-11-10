"use client"

import { useAuth } from "@/features/auth/use-auth"
import { FacultyAvailabilityPanel } from "@/features/components/portal/faculty-availability-panel"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { FacultySubjectPreferencePanel } from "@/features/components/portal/faculty-subject-preference-panel"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/features/components/ui/tabs"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import { useFacultyTeachingHistoryQuery } from "@/features/hooks/use-faculty-input"

function TeachingHistoryPanel() {
  const historyQuery = useFacultyTeachingHistoryQuery()

  return (
    <Card>
      <CardHeader>
        <CardTitle level={2}>Teaching history</CardTitle>
        <CardDescription>
          Read-only workbook-reference evidence is used only as an explainable
          tie-breaker after curriculum preference.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <AsyncBoundary
          query={historyQuery}
          isEmpty={(rows) => rows.length === 0}
          emptyMessage="No workbook teaching history is available yet."
          loadingLabel="Loading teaching history…"
        >
          {(rows) => (
            <div className="overflow-x-auto rounded-md border">
              <Table aria-label="Teaching history">
                <TableHeader>
                  <TableRow>
                    <TableHead>Curriculum</TableHead>
                    <TableHead>Semester</TableHead>
                    <TableHead>Subject</TableHead>
                    <TableHead>Evidence</TableHead>
                    <TableHead>Source</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell>
                        {row.curriculum_name ?? "Curriculum unavailable"}
                      </TableCell>
                      <TableCell>{row.semester}</TableCell>
                      <TableCell>
                        {row.subject_code
                          ? `${row.subject_code} — ${row.subject_title ?? ""}`
                          : "Subject unavailable"}
                      </TableCell>
                      <TableCell>{row.evidence_count}</TableCell>
                      <TableCell>{row.source_workbook}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </AsyncBoundary>
      </CardContent>
    </Card>
  )
}

export function FacultyInputWorkspace() {
  const { session } = useAuth()

  if (session?.role !== "faculty") {
    return <WorkspacePage title="Availability and preferences" unauthorized />
  }

  return (
    <WorkspacePage
      title="Availability and preferences"
      description="Maintain recurring teaching availability, subject preferences, and advisory specialization signals."
    >
      <Tabs defaultValue="availability">
        <TabsList aria-label="Faculty input areas" className="w-full sm:w-fit">
          <TabsTrigger value="availability">Availability window</TabsTrigger>
          <TabsTrigger value="preferences">Subject preferences</TabsTrigger>
          <TabsTrigger value="history">Teaching history</TabsTrigger>
        </TabsList>
        <TabsContent value="availability">
          <FacultyAvailabilityPanel />
        </TabsContent>
        <TabsContent value="preferences">
          <FacultySubjectPreferencePanel />
        </TabsContent>
        <TabsContent value="history">
          <TeachingHistoryPanel />
        </TabsContent>
      </Tabs>
    </WorkspacePage>
  )
}
