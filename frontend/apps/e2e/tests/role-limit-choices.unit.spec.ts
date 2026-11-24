import { expect, test } from "@playwright/test";
import { ssrBundle } from "./fixtures/ssr-bundle";

const bundle = ssrBundle<{
  render: (roles: unknown[], limits: Record<number, string>, disabled: boolean) => string;
  limitChoicesFor: (role: unknown, current: string, units: boolean) => { value: string; label: string }[];
}>(
  "role-limit-choices",
  `import { createElement } from "react";
   import { renderToStaticMarkup } from "react-dom/server";
   import { RoleLimitChoices, limitChoicesFor } from "../../../packages/core-ui/src/features/access-administration/role-limit-choices";
   export { limitChoicesFor };
   export const render = (roles, limits, disabled) => renderToStaticMarkup(createElement(RoleLimitChoices, { roles, limits, onChange: () => undefined, companyHasUnits: true, disabled }));`,
  new URL(".", import.meta.url).pathname,
);
const { render, limitChoicesFor } = bundle.loaded;
test.afterAll(() => bundle.cleanup());

/**
 * ADR 0192: the administration screen offers each given role only the limits the server says it supports (own / assigned need every duty of the role to support them),
 * keeps a limit it has no wording for, and never offers "only my units" to a company with no units unless it is already chosen.
 */
const buyers = { id: 1, name: "Buyers", limit_modes: ["MY_UNITS", "OWN", "ASSIGNED"] };
const mixed = { id: 2, name: "Mixed", limit_modes: ["MY_UNITS"] };
const legacy = { id: 3, name: "Legacy" }; // a role payload without the field offers only the old choice
const values = (role: { id: number; name: string; limit_modes?: string[] }, current = "", units = true) => limitChoicesFor(role, current, units).map((choice: { value: string }) => choice.value);

test("a role built from supported duties offers units, own and assigned; a mixed role only units", () => {
  expect(values(buyers)).toEqual(["MY_UNITS", "OWN", "ASSIGNED"]);
  expect(values(mixed)).toEqual(["MY_UNITS"]);
  expect(values(legacy)).toEqual(["MY_UNITS"]);
});

test("a company with no units is not offered 'only my units', but own and assigned need no units", () => {
  expect(values(buyers, "", false)).toEqual(["OWN", "ASSIGNED"]);
  expect(values(mixed, "", false)).toEqual([]);
  expect(values(mixed, "MY_UNITS", false)).toEqual(["MY_UNITS"]); // already chosen: stays visible, so it can be seen and removed
});

test("the selector shows the current limit, keeps one it has no wording for, and is disabled without the right", () => {
  const html = (limits: Record<number, string>, disabled = false) => render([buyers, mixed], limits, disabled);

  const assigned = html({ 1: "ASSIGNED" });
  expect(assigned).toContain('aria-label="Where Buyers applies"');
  expect(assigned).toMatch(/<option value="ASSIGNED" selected="">Only records assigned to me<\/option>/);
  expect(assigned).toContain("Company-wide");
  expect(assigned).not.toContain("Only records I created</option></select></label><label"); // the mixed role (second) offers neither own nor assigned
  const second = assigned.split("<label")[2];
  expect(second).not.toContain("Only records assigned to me");
  expect(second).not.toContain("Only records I created");
  expect(html({ 2: "OWN_OR_DELEGATED" }).split("<label")[2]).toContain("Limited (own or delegated)");
  expect(html({}, true)).toContain("disabled");
});
