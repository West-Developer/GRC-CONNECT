import path from "node:path";

import { expect, test } from "@playwright/test";

import { authenticateViaApi } from "../fixtures/auth";

test("the selected COR is isolated as two full print pages", async ({
  page,
  request,
}) => {
  await page.route("**/api/v1/enrollment-documents**", async (route) => {
    const document = {
      type: "certificate_of_registration",
      id: 1,
      enrollment_id: 9,
      document_number: "COR000009",
      generated_at: "2026-08-31T09:51:40Z",
      content_hash: "print-layout-test",
      snapshot: {
        document_title: "Certificate of Registration",
        institution: {
          name: "Global Reciprocal Colleges",
          address: "East Grace Park, Caloocan, Metro Manila",
        },
        student: {
          student_number: "2026-0001",
          name: "Test Student",
          address: "123 Test Drive, Caloocan City",
          course: "BS Information Technology",
          level: "Year 4",
          platform: "Not provided",
        },
        term: { school_year: "2026-2027", semester: "1st" },
        subjects: [
          {
            code: "IT401",
            title: "Business Analytics",
            units: "3.00",
            section: "IV-BLOCK",
            schedule_id: "40882",
            schedule: "10:30 AM - 01:30 PM Mon",
            room: "Hybrid Flexible Learning (HyFlex)",
          },
        ],
        total_units: "3.00",
        admission_certification:
          "This is to certify that Test Student is cleared and enrolled.",
        fees: {
          currency: "PHP",
          tuition: [
            {
              label: "Tuition fee",
              quantity: "3.00",
              unit_amount: "900.00",
              amount: "2700.00",
            },
          ],
          other_fees: [],
          total_tuition: "2700.00",
          total_other_fees: "0.00",
          grand_total: "2700.00",
          payment_amount: "2700.00",
        },
        signatories: { cashier: "Cashier Test", registrar: "Registrar" },
        withdrawal_terms: [
          "Withdrawal may be validly effected only within the approved period.",
        ],
      },
    };

    await route.fulfill({
      contentType: "application/json",
      body: JSON.stringify(
        route.request().url().endsWith("/1")
          ? { data: document }
          : {
              data: [
                {
                  id: document.id,
                  type: "enrollment_document",
                  enrollment_id: document.enrollment_id,
                  document_type: "cor",
                  document_number: document.document_number,
                  document_type_label: "Certificate of Registration",
                  generated_at: document.generated_at,
                  student_number: document.snapshot.student.student_number,
                  student_name: document.snapshot.student.name,
                },
              ],
              links: {
                first:
                  "http://localhost:3000/api/v1/enrollment-documents?page=1",
                last: "http://localhost:3000/api/v1/enrollment-documents?page=1",
                prev: null,
                next: null,
              },
              meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
            },
      ),
    });
  });

  await authenticateViaApi(page, request, "student");
  await page.goto("/portal/digital-com");

  await page
    .getByRole("button", { name: "View printable COR" })
    .first()
    .click();
  await expect(
    page.getByRole("heading", { name: "CERTIFICATE OF REGISTRATION" }).first(),
  ).toBeVisible();

  await page.evaluate(() => {
    document.body.dataset.printing = "document";
  });
  await page.emulateMedia({ media: "print" });

  const printLayout = await page
    .locator("[data-print-region]")
    .evaluate((region) => {
      const pages = Array.from(region.querySelectorAll(".cor-document__page"));

      return {
        position: window.getComputedStyle(region).position,
        breakAfter: pages.map(
          (page) => window.getComputedStyle(page).breakAfter,
        ),
      };
    });

  expect(printLayout.position).toBe("absolute");
  expect(printLayout.breakAfter).toEqual(["page", "auto"]);

  const pdf = await page.pdf({
    path: path.join(process.cwd(), "test-results", "cor-print-layout.pdf"),
    format: "A4",
    printBackground: true,
  });

  expect(
    Buffer.from(pdf)
      .toString("latin1")
      .match(/\/Type\s*\/Page(?!s)\b/g) ?? [],
  ).toHaveLength(2);
});
