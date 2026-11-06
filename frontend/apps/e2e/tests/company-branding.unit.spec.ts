import { expect, test } from '@playwright/test';

import { asBrandingView, logoProblem } from '../../../packages/core-ui/src/features/company/company-branding-section';

const MAX = 2_097_152;

test.describe('Company branding rules', () => {
  test('only PNG and JPEG logos up to the limit are offered to the server', () => {
    expect(logoProblem({ size: 1000, type: 'image/png' }, MAX)).toBeNull();
    expect(logoProblem({ size: MAX, type: 'image/jpeg' }, MAX)).toBeNull();
    expect(logoProblem({ size: MAX + 1, type: 'image/png' }, MAX)).toMatch(/larger than 2 MB/);
    for (const type of ['image/svg+xml', 'image/gif', 'application/pdf', '']) {
      expect(logoProblem({ size: 10, type }, MAX)).toMatch(/PNG or a JPEG/);
    }
  });

  test('the branding view is read defensively', () => {
    expect(asBrandingView(undefined)).toBeUndefined();
    expect(asBrandingView(null)).toBeUndefined();
    expect(asBrandingView({ version: null, logo: null, enabled: false, can_manage: false })?.version).toBeNull();
  });
});
