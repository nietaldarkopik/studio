import { execFile } from "node:child_process";
import { resolve } from "node:path";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);

type BlenderCheck =
  | { status: "detected"; version: string }
  | { status: "missing" | "error" };

async function checkBlender(): Promise<BlenderCheck> {
  const executable = process.env.BLENDER_PATH;
  if (!executable) return { status: "missing" };

  try {
    const { stdout } = await execFileAsync(executable, ["--version"], {
      timeout: 6000,
      windowsHide: true,
      maxBuffer: 1024 * 1024,
    });
    const version = stdout.split(/\r?\n/, 1)[0]?.trim();
    if (!version || !/^Blender \d/.test(version)) return { status: "error" };
    return { status: "detected", version };
  } catch {
    return { status: "error" };
  }
}

type ClipWizeStatus = "authenticated" | "needs_login" | "offline" | "cli_unavailable";

async function runClipWize(...args: string[]) {
  const cliPath = resolve(process.cwd(), "..", "skills", "clipwize", "scripts", "clipwize.py");
  return execFileAsync(process.env.PYTHON_PATH || "python", [cliPath, ...args], {
    cwd: process.cwd(),
    timeout: 20_000,
    windowsHide: true,
    maxBuffer: 128 * 1024,
  });
}

function cliErrorText(error: unknown) {
  if (!error || typeof error !== "object") return "";
  const result = error as { stdout?: unknown; stderr?: unknown; message?: unknown };
  return [result.stdout, result.stderr, result.message]
    .filter((value): value is string => typeof value === "string")
    .join("\n");
}

async function isClipWizeReachable() {
  try {
    const { stdout } = await runClipWize("ping");
    return /HTTP 401 \(API hidup\)|HTTP 200/.test(stdout);
  } catch {
    return false;
  }
}

async function checkClipWize(): Promise<ClipWizeStatus> {
  try {
    await runClipWize("me");
    return "authenticated";
  } catch (error) {
    const detail = cliErrorText(error);
    if (/ENOENT|not recognized/i.test(detail)) return "cli_unavailable";
    if (/Token belum ada|HTTP 401|Unauthenticated/i.test(detail)) {
      return (await isClipWizeReachable()) ? "needs_login" : "offline";
    }
    return (await isClipWizeReachable()) ? "needs_login" : "offline";
  }
}

export async function GET() {
  if (process.env.NODE_ENV !== "development") {
    return Response.json({ error: "Local diagnostics are disabled outside development." }, { status: 404 });
  }

  const [clipwize, blender] = await Promise.all([checkClipWize(), checkBlender()]);

  return Response.json(
    {
      clipwize,
      blender: blender.status,
      ...(blender.status === "detected" ? { blenderVersion: blender.version } : {}),
      checkedAt: new Date().toISOString(),
    },
    {
      headers: {
        "Cache-Control": "no-store, max-age=0",
        "X-Content-Type-Options": "nosniff",
      },
    },
  );
}
