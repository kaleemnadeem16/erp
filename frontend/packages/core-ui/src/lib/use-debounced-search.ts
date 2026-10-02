import { useEffect, useRef, useState, type ChangeEvent, type KeyboardEvent } from 'react';

/**
 * Free-text search that waits for a pause in typing before it asks the server, instead of asking on every keystroke.
 *
 * The box stays instantly responsive (it shows what is typed at once); only the *commit* — writing the filter to the URL
 * and so firing the request — is delayed. Three refinements make it feel quick rather than laggy:
 *  - **Enter commits at once**, for someone who has finished and does not want to wait out the delay.
 *  - **Emptying the box commits at once**, so deleting a search brings the full list back without a pause.
 *  - **A change made elsewhere** (a reset, back navigation) replaces what the box shows.
 *
 * 300 ms is the settled default (see ADR 0078): long enough that a normal typing burst is one request, short enough to
 * stay inside the ~1 s "uninterrupted flow" limit once the request itself is added.
 *
 * Spread `searchProps` onto the `<Input>`.
 */
export function useDebouncedSearch(
  committedValue: string | undefined,
  onCommit: (value: string | undefined) => void,
  delayMs = 300
) {
  const [input, setInput] = useState(committedValue ?? '');
  const committed = useRef(committedValue ?? '');
  const latestCommit = useRef(onCommit);
  latestCommit.current = onCommit;

  // A change from outside (e.g. "clear all", the browser's back button) replaces what the box shows.
  useEffect(() => {
    const next = committedValue ?? '';
    if (next !== committed.current) {
      committed.current = next;
      setInput(next);
    }
  }, [committedValue]);

  function commit(value: string) {
    if (value === committed.current) return;
    committed.current = value;
    latestCommit.current(value || undefined);
  }

  useEffect(() => {
    if (input === committed.current) return;
    const handle = window.setTimeout(() => commit(input), input === '' ? 0 : delayMs);
    return () => window.clearTimeout(handle);
    // `commit` only reads refs, so it is stable in effect.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [input, delayMs]);

  return {
    /** What the box shows right now. */
    input,
    searchProps: {
      value: input,
      onChange: (event: ChangeEvent<HTMLInputElement>) => setInput(event.target.value),
      onKeyDown: (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') commit(event.currentTarget.value);
      },
    },
  };
}
