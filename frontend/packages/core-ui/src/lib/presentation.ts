/**
 * How a record screen (a create form, a detail view) is shown: on a page of its
 * own, or as a side sheet over the list it came from. Screens that support both
 * take a `presentation` prop; which one each route uses is chosen in one place,
 * `apps/main/src/presentation.ts`, so trying the other is a one-word change.
 */
export type Presentation = 'page' | 'sheet';

/**
 * How a form puts its rarely-changed fields away: `"disclosure"` — one "More options" show/hide toggle, the fields keep their
 * defaults and are saved either way — or `"menu"` — an "Advanced options" checkbox menu that switches each group on. Both are
 * implemented for the purchase order form; the choice is made in `apps/main/src/presentation.ts`.
 */
export type OptionsStyle = 'disclosure' | 'menu';
