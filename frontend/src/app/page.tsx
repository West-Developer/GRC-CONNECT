import { redirect } from "next/navigation"

import { LandingPage } from "@/features/components/pages/landing-page"

interface PageProps {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>
}

export default async function Page({ searchParams }: PageProps) {
  const params = await searchParams
  const code = params.code || params.token
  if (typeof code === "string" && code.trim() !== "") {
    const email = typeof params.email === "string" ? params.email : ""
    const query = new URLSearchParams()
    if (email) query.set("email", email)
    query.set("code", code)
    redirect(`/account-setup?${query.toString()}`)
  }

  return <LandingPage />
}
