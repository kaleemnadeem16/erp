import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { createPortal } from "react-dom";

import {
  ScreenDesignProvider,
  type ScreenDesignVariables,
} from "./screen-template";
import { cn } from "../../lib/utils";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "../ui/sheet";

export type RecordSheetSize = "md" | "lg" | "xl";

// Proportional to the screen with a floor and a ceiling, so a form doesn't
// get cramped on a small laptop or stretched across a wide monitor. The floor
// is itself capped at the screen width, so a small tablet never gets a sheet
// wider than the screen.
const SIZE_CLASS: Record<RecordSheetSize, string> = {
  md: "sm:w-[50vw] sm:min-w-[min(36rem,100vw)] sm:max-w-3xl",
  lg: "sm:w-[60vw] sm:min-w-[min(40rem,100vw)] sm:max-w-5xl",
  xl: "sm:w-[80vw] sm:min-w-[min(48rem,100vw)] sm:max-w-7xl",
};

// Matches the exit animation in themes.css (240ms) — see beginClose.
const EXIT_MS = 240;

interface AsideSlot {
  element: HTMLElement | null;
  /** Reserve the side column for as long as the caller stays mounted. */
  claim: () => () => void;
}
const AsideSlotContext = createContext<AsideSlot | null>(null);

/**
 * Puts its children in the sheet's side column from anywhere inside the sheet.
 * The point is a form that owns its own state: it can show a live summary of
 * what has been entered without lifting that state up to the screen that
 * mounts the sheet. Renders nothing outside a RecordSheet, or when the sheet is
 * too narrow for a side column, so the same form works as a full page.
 */
export function RecordSheetAside({ children }: { children: ReactNode }) {
  const slot = useContext(AsideSlotContext);
  const claim = slot?.claim;
  useLayoutEffect(() => claim?.(), [claim]);
  if (!slot?.element) return null;
  return createPortal(children, slot.element);
}

export interface RecordSheetProps {
  title: string;
  description?: string;
  /** A small glyph shown in a tinted tile beside the title. */
  icon?: ReactNode;
  /** Buttons/menus in the header, beside the title (e.g. Print, Edit, Issue). */
  actions?: ReactNode;
  /**
   * A quiet side column (e.g. a live summary of the form). Shown only when the
   * sheet is wide enough to spare the room; on a narrower sheet it is left out
   * and the main column takes the full width.
   */
  aside?: ReactNode;
  /** Called once the sheet has finished closing; typically navigates back to the list. */
  onClose: () => void;
  size?: RecordSheetSize;
  /** Extra classes for the sheet itself — an opt-in hook for a screen that scopes its own look (e.g. a palette). */
  className?: string;
  variables?: ScreenDesignVariables;
  /**
   * Whether a click on the dimmed area behind the sheet closes it. Leave on for
   * read-only views; turn off for forms, where a stray click would throw away
   * what was typed.
   */
  dismissOnOutsideClick?: boolean;
  children: ReactNode;
}

/** How many record sheets are mounted. The app shell reads it to leave the page behind a sheet where it is (see `isRecordSheetOpen`). */
let mountedSheets = 0;

/**
 * Whether a record sheet is on screen right now. A sheet is a route (so a link to it works) but it is an overlay: opening or closing one must not move the list
 * underneath, so the app shell skips its scroll-to-top when a sheet is involved.
 */
export function isRecordSheetOpen(): boolean {
  return mountedSheets > 0;
}

/**
 * A record — a create form or a detail view — as a right-hand side sheet over
 * the screen it came from. Mount it from a child route of the list, so the
 * list stays rendered (dimmed) behind it. Closing plays the exit animation and
 * only then calls `onClose` (the navigation that unmounts it) — navigating
 * straight away would remove the sheet before it could animate out. The same
 * content can instead be rendered as a full page; see
 * docs/architecture/ui-building-blocks.md.
 */
export function RecordSheet({
  title,
  description,
  icon,
  actions,
  aside,
  onClose,
  size = "xl",
  className,
  variables,
  dismissOnOutsideClick = true,
  children,
}: RecordSheetProps) {
  const contentRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    mountedSheets += 1;

    return () => {
      mountedSheets -= 1;
    };
  }, []);
  const [open, setOpen] = useState(true);
  const [asideElement, setAsideElement] = useState<HTMLElement | null>(null);
  const [asideClaims, setAsideClaims] = useState(0);
  const claimAside = useCallback(() => {
    setAsideClaims((count) => count + 1);
    return () => setAsideClaims((count) => count - 1);
  }, []);
  const asideSlot = useMemo(
    () => ({ element: asideElement, claim: claimAside }),
    [asideElement, claimAside],
  );
  const finished = useRef(false);
  const fallbackTimer = useRef<number | undefined>(undefined);

  const finishClose = useCallback(() => {
    if (finished.current) return;
    finished.current = true;
    window.clearTimeout(fallbackTimer.current);
    onClose();
  }, [onClose]);

  function beginClose() {
    if (!open) return;
    setOpen(false);
    // `animationend` is the normal trigger; this covers the cases where none
    // fires — reduced motion turns the animation off, a hidden tab pauses it.
    fallbackTimer.current = window.setTimeout(finishClose, EXIT_MS + 80);
  }
  useEffect(() => () => window.clearTimeout(fallbackTimer.current), []);

  const hasAside = aside != null || asideClaims > 0;

  return (
    <ScreenDesignProvider variables={variables}>
      <AsideSlotContext.Provider value={asideSlot}>
        <Sheet
          open={open}
          onOpenChange={(next) => {
            if (!next) beginClose();
          }}
        >
          <SheetContent
            ref={contentRef}
            side="right"
            className={cn(
              "erp-record @container flex w-full flex-row gap-0 p-0 outline-none",
              SIZE_CLASS[size],
              className,
            )}
            onAnimationEnd={(event) => {
              if (!open && event.target === event.currentTarget) finishClose();
            }}
            // Land focus on the sheet itself, not its first focusable child — while
            // a detail loads that's the close button, which then sits there ringed.
            // A form inside can still claim focus for its first field.
            onOpenAutoFocus={(event) => {
              event.preventDefault();
              contentRef.current?.focus();
            }}
            // Radix warns when a dialog has no description; opt out explicitly if there isn't one.
            {...(description ? {} : { "aria-describedby": undefined })}
            onInteractOutside={
              dismissOnOutsideClick
                ? undefined
                : (event) => event.preventDefault()
            }
          >
            {/* A container of its own, so what's inside sizes to this column rather
              than to the whole sheet — the side column takes a share of that. */}
            <div className="@container flex min-w-0 flex-1 flex-col p-4">
              <SheetHeader className="record-sheet-header shrink-0 border-b border-border pb-4">
                <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                  <div className="flex min-w-0 items-start gap-3">
                    {icon && (
                      <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground [&_svg]:size-5">
                        {icon}
                      </div>
                    )}
                    <div className="min-w-0">
                      <SheetTitle asChild className="record-sheet-title">
                        <h1>{title}</h1>
                      </SheetTitle>
                      {description && (
                        <SheetDescription className="record-sheet-description">
                          {description}
                        </SheetDescription>
                      )}
                    </div>
                  </div>
                  {/* Full width and left-aligned when it drops under the title on a narrow sheet; right-aligned beside it on a wide one. */}
                  {actions && (
                    <div className="flex w-full flex-wrap items-center justify-start gap-2 sm:ml-auto sm:w-auto sm:justify-end">
                      {actions}
                    </div>
                  )}
                </div>
              </SheetHeader>
              {/* The negative margin / matching padding gives focus rings and
                shadows room inside the scroll container, which would otherwise
                clip them at its edge (a focused Next button lost its right side). */}
              <div className="-mx-2 min-h-0 flex-1 overflow-y-auto px-2 pt-4">
                {children}
              </div>
            </div>
            {hasAside && (
              <aside
                ref={setAsideElement}
                className="hidden w-80 shrink-0 flex-col gap-5 overflow-y-auto border-l border-border bg-panel p-5 pt-14 text-panel-foreground @4xl:flex"
              >
                {aside}
              </aside>
            )}
          </SheetContent>
        </Sheet>
      </AsideSlotContext.Provider>
    </ScreenDesignProvider>
  );
}
