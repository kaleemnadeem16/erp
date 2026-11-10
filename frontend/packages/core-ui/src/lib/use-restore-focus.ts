import * as React from "react";

/**
 * Radix returns focus on close only to a `Dialog.Trigger`. Most dialogs and sheets here are opened by a button that sets state
 * (no Trigger), so focus fell to the page body and a keyboard user lost their place. This remembers what had focus when the
 * dialog opened and puts it back when it closes, if that control is still on the page. Pass the result to the content's
 * `onOpenAutoFocus` / `onCloseAutoFocus`; the caller's own handlers still run first.
 */
export function useRestoreFocus(handlers: {
  onOpenAutoFocus?: (event: Event) => void;
  onCloseAutoFocus?: (event: Event) => void;
}) {
  const opener = React.useRef<HTMLElement | null>(null);
  return {
    onOpenAutoFocus: (event: Event) => {
      const active = document.activeElement;
      opener.current =
        active instanceof HTMLElement && active !== document.body
          ? active
          : null;
      handlers.onOpenAutoFocus?.(event);
    },
    onCloseAutoFocus: (event: Event) => {
      handlers.onCloseAutoFocus?.(event);
      const target = opener.current;
      opener.current = null;
      if (target && target.isConnected && !event.defaultPrevented) {
        target.focus({ preventScroll: true });
      }
    },
  };
}
