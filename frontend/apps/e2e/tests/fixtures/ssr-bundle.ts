import { buildSync } from "esbuild";
import { createRequire } from "node:module";
import { mkdirSync, unlinkSync } from "node:fs";

/** Bundles a TSX entry for server-side rendering in an isolated unit spec (no browser, no backend). */
export function ssrBundle<T>(
  name: string,
  contents: string,
  resolveDir: string,
) {
  const file = new URL(
    `../../../../node_modules/.cache/${name}-${process.pid}.cjs`,
    import.meta.url,
  );
  mkdirSync(new URL(".", file), { recursive: true });
  buildSync({
    stdin: { contents, resolveDir, loader: "tsx" },
    bundle: true,
    platform: "node",
    format: "cjs",
    jsx: "automatic",
    outfile: file.pathname,
    external: [
      "react",
      "react-dom",
      "react-router",
      "zod",
      "zustand",
      "lucide-react",
      "@radix-ui/*",
      "clsx",
      "tailwind-merge",
      "class-variance-authority",
    ],
  });
  const loaded = createRequire(import.meta.url)(file.pathname) as T;
  return { loaded, cleanup: () => unlinkSync(file) };
}
