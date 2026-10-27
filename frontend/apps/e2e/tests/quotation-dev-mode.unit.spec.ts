import { expect, test } from "@playwright/test";
import {
  allowsQuotationDevMode,
  configureQuotationDevMode,
  quotationDevModeEnabled,
} from "../../../packages/core-ui/src/lib/quotation-dev-mode";

test("quotation development exposure is off by default and never enabled by a production build", () => {
  expect(quotationDevModeEnabled()).toBe(false);
  for (const hostname of [
    "localhost",
    "127.0.0.1",
    "[::1]",
    "::1",
    "erp.example.test",
  ]) {
    expect(
      allowsQuotationDevMode({ development: false, flag: "1", hostname }),
    ).toBe(false);
  }
});

test("localhost development needs an explicit flag; remote and lookalike hosts fail closed", () => {
  for (const flag of [undefined, "", "true", "0"])
    expect(
      allowsQuotationDevMode({
        development: true,
        flag,
        hostname: "localhost",
      }),
    ).toBe(false);
  for (const hostname of ["localhost", "127.0.0.1", "[::1]", "::1"])
    expect(
      allowsQuotationDevMode({ development: true, flag: "1", hostname }),
    ).toBe(true);
  for (const hostname of [
    "erp.example.test",
    "192.168.1.20",
    "localhost.example.test",
    "127.0.0.1.example.test",
  ])
    expect(
      allowsQuotationDevMode({ development: true, flag: "1", hostname }),
    ).toBe(false);
});

test("reconfiguration fails closed instead of retaining an earlier enabled state", () => {
  try {
    configureQuotationDevMode({
      development: true,
      flag: "1",
      hostname: "localhost",
    });
    expect(quotationDevModeEnabled()).toBe(true);
    configureQuotationDevMode({
      development: false,
      flag: "1",
      hostname: "localhost",
    });
    expect(quotationDevModeEnabled()).toBe(false);
  } finally {
    configureQuotationDevMode({
      development: false,
      flag: undefined,
      hostname: "",
    });
  }
});

test("quotation navigation and direct workspace access require both dev exposure and a quotation duty", async () => {
  const { canAccessScreen, homeScreen } =
    await import("../../../packages/core-ui/src/routes/screen-access");
  const grants = new Set(["sales.quotation.view", "sales.quotation.prepare"]);
  try {
    expect(canAccessScreen("/quotations", grants)).toBe(false);
    configureQuotationDevMode({
      development: true,
      flag: "1",
      hostname: "localhost",
    });
    expect(canAccessScreen("/quotations", grants)).toBe(true);
    expect(homeScreen(grants)).toBe("/quotations");
    expect(
      canAccessScreen("/quotations/12", new Set(["sales.quotation.view"])),
    ).toBe(true);
    expect(
      canAccessScreen(
        "/quotations/12/review",
        new Set(["sales.quotation.view"]),
      ),
    ).toBe(false);
    expect(
      canAccessScreen(
        "/quotations/12/review",
        new Set(["sales.quotation.prepare"]),
      ),
    ).toBe(true);
    expect(
      canAccessScreen("/quotations/12", new Set(["sales.quotation.prepare"])),
    ).toBe(false);
    expect(
      canAccessScreen("/quotations", new Set(["sales.quotation.view"])),
    ).toBe(true);
    expect(
      canAccessScreen("/quotations", new Set(["sales.quotation.prepare"])),
    ).toBe(true);
    expect(
      canAccessScreen("/quotations", new Set(["sales.pipeline.view"])),
    ).toBe(false);
    configureQuotationDevMode({
      development: false,
      flag: "1",
      hostname: "localhost",
    });
    expect(canAccessScreen("/quotations", grants)).toBe(false);
  } finally {
    configureQuotationDevMode({
      development: false,
      flag: undefined,
      hostname: "",
    });
  }
});
