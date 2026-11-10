import { PortalModulePage } from "@/features/components/pages/portal-module-page"
import { redirect } from "next/navigation"

const legacyModuleRedirects: Record<string, string> = {
  "student-accounts": "student-records",
  "admission-status": "student-records",
  "credential-issuance": "student-records",
  "demand-forecast": "program-chair-enrollment",
  "faculty-assignment": "schedule",
  "sections-schedules": "schedule",
}

export default async function Page({
  params,
}: {
  params: Promise<{ moduleId: string }>
}) {
  const { moduleId } = await params

  const redirectedModule = legacyModuleRedirects[moduleId]
  if (redirectedModule) {
    redirect(`/portal/${redirectedModule}`)
  }

  return <PortalModulePage moduleId={moduleId} />
}
