import { z } from 'zod';

import { checkDecimal, type DecimalRule } from './check';

/**
 * A react-hook-form/zod field for a decimal typed as text. The value stays the exact string the person typed (trimmed); this only refuses what the server
 * would refuse, with the same wording the input shows. `optional` allows an empty string, which a screen then omits or sends as null.
 */
export function decimalField(rule: DecimalRule & { optional?: boolean }) {
  return z.string().superRefine((raw, context) => {
    if (rule.optional && raw.trim() === '') return;
    const result = checkDecimal(raw, rule);
    if (!result.ok) context.addIssue({ code: 'custom', message: result.message ?? 'Enter a valid number' });
  });
}
