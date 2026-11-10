import { expect, test } from "@playwright/test";

import { authenticateViaApi } from "../fixtures/auth";

function relativeLuminance([red, green, blue]: [
  number,
  number,
  number,
]): number {
  const channel = (value: number) => {
    const normalized = value / 255;

    return normalized <= 0.04045
      ? normalized / 12.92
      : ((normalized + 0.055) / 1.055) ** 2.4;
  };

  return (
    0.2126 * channel(red) + 0.7152 * channel(green) + 0.0722 * channel(blue)
  );
}

function parseRgb(value: string): [number, number, number] {
  const match = value.match(/\d+(?:\.\d+)?/g);

  if (!match || match.length < 3) {
    throw new Error(`Expected an RGB color but received ${value}.`);
  }

  return [Number(match[0]), Number(match[1]), Number(match[2])];
}

test("student mobile navigation keeps its destinations readable", async ({
  page,
  request,
}) => {
  await authenticateViaApi(page, request, "student");
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto("/portal");

  await page.getByRole("button", { name: "Open portal navigation" }).click();

  const navigation = page.getByRole("dialog", {
    name: "GRC Connect navigation",
  });
  const enrollment = navigation.getByRole("link", { name: "Enrollment" });

  await expect(enrollment).toBeVisible();

  const colors = (await page.evaluate(`
    (() => {
      const sheet = document.querySelector("[data-slot='sheet-content']")
      const link = sheet?.querySelector("a[href='/portal/enrollment']")
      if (!sheet || !link) {
        throw new Error("The mobile Enrollment link must be rendered inside its Sheet.")
      }

      return {
        background: getComputedStyle(sheet).backgroundColor,
        foreground: getComputedStyle(link).color,
      }
    })()
  `)) as { background: string; foreground: string };
  const foreground = relativeLuminance(parseRgb(colors.foreground));
  const background = relativeLuminance(parseRgb(colors.background));
  const contrast =
    (Math.max(foreground, background) + 0.05) /
    (Math.min(foreground, background) + 0.05);

  expect(contrast).toBeGreaterThanOrEqual(4.5);
});
