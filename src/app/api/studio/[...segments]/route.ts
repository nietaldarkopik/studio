import { cookies } from "next/headers";
import { NextRequest, NextResponse } from "next/server";

const sessionCookie = "studio_session";
const sessionMaxAge = 60 * 60 * 24 * 30;
const backendUrl = (process.env.STUDIO_API_URL || "http://127.0.0.1:8001").replace(/\/+$/, "");
const maxRequestBodyBytes = 5_000_000;

type RouteContext = { params: Promise<{ segments: string[] }> };
type HttpMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

function jsonResponse(payload: Record<string, unknown>, status: number) {
  return NextResponse.json(payload, {
    status,
    headers: { "Cache-Control": "no-store" },
  });
}

function resolveEndpoint(method: HttpMethod, segments: string[]) {
  const path = segments.join("/");

  if (method === "POST" && path === "auth/login") return { path: "/api/auth/login", login: true };
  if (method === "POST" && path === "auth/logout") return { path: "/api/auth/logout" };
  if (method === "GET" && path === "me") return { path: "/api/me" };

  const productionMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/production$/);
  if (productionMatch && method === "GET") {
    return { path: `/api/workspaces/${productionMatch[1]}/projects/${productionMatch[2]}/production` };
  }

  const scriptGenerationMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/script\/generate$/);
  if (scriptGenerationMatch && (method === "POST" || method === "GET")) {
    return { path: `/api/workspaces/${scriptGenerationMatch[1]}/projects/${scriptGenerationMatch[2]}/script/generate` };
  }

  const aiGenerationMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/ai\/(prompt|prompt_all|research_search|research_auto|research_verify|research)$/);
  if (aiGenerationMatch && (method === "POST" || method === "GET")) {
    return { path: `/api/workspaces/${aiGenerationMatch[1]}/projects/${aiGenerationMatch[2]}/ai/${aiGenerationMatch[3]}` };
  }

  const scriptMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/script$/);
  if (scriptMatch && method === "PUT") {
    return { path: `/api/workspaces/${scriptMatch[1]}/projects/${scriptMatch[2]}/script` };
  }

  const sceneOrderMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/scenes\/order$/);
  if (sceneOrderMatch && method === "PUT") {
    return { path: `/api/workspaces/${sceneOrderMatch[1]}/projects/${sceneOrderMatch[2]}/scenes/order` };
  }

  const scenesMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/scenes$/);
  if (scenesMatch && method === "POST") {
    return { path: `/api/workspaces/${scenesMatch[1]}/projects/${scenesMatch[2]}/scenes` };
  }

  const sceneMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/scenes\/(\d+)$/);
  if (sceneMatch && (method === "PATCH" || method === "DELETE")) {
    return { path: `/api/workspaces/${sceneMatch[1]}/projects/${sceneMatch[2]}/scenes/${sceneMatch[3]}` };
  }

  const storyboardImportMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/storyboard\/import$/);
  if (storyboardImportMatch && method === "POST") {
    return { path: `/api/workspaces/${storyboardImportMatch[1]}/projects/${storyboardImportMatch[2]}/storyboard/import` };
  }

  const researchSourcesMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/research-sources$/);
  if (researchSourcesMatch && (method === "GET" || method === "POST")) {
    return { path: `/api/workspaces/${researchSourcesMatch[1]}/projects/${researchSourcesMatch[2]}/research-sources` };
  }

  const researchSourceMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/research-sources\/(\d+)$/);
  if (researchSourceMatch && (method === "PATCH" || method === "DELETE")) {
    return { path: `/api/workspaces/${researchSourceMatch[1]}/projects/${researchSourceMatch[2]}/research-sources/${researchSourceMatch[3]}` };
  }

  const promptsMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/prompts$/);
  if (promptsMatch && (method === "GET" || method === "POST")) {
    return { path: `/api/workspaces/${promptsMatch[1]}/projects/${promptsMatch[2]}/prompts` };
  }

  const promptRevisionsMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/prompts\/(\d+)\/revisions$/);
  if (promptRevisionsMatch && method === "GET") {
    return { path: `/api/workspaces/${promptRevisionsMatch[1]}/projects/${promptRevisionsMatch[2]}/prompts/${promptRevisionsMatch[3]}/revisions` };
  }

  const promptMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/prompts\/(\d+)$/);
  if (promptMatch && (method === "PATCH" || method === "DELETE")) {
    return { path: `/api/workspaces/${promptMatch[1]}/projects/${promptMatch[2]}/prompts/${promptMatch[3]}` };
  }

  const workflowStepTransitionMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/workflows\/(\d+)\/steps\/(\d+)\/transition$/);
  if (workflowStepTransitionMatch && method === "POST") {
    return { path: `/api/workspaces/${workflowStepTransitionMatch[1]}/projects/${workflowStepTransitionMatch[2]}/workflows/${workflowStepTransitionMatch[3]}/steps/${workflowStepTransitionMatch[4]}/transition` };
  }

  const workflowStepsMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/workflows\/(\d+)\/steps$/);
  if (workflowStepsMatch && method === "POST") {
    return { path: `/api/workspaces/${workflowStepsMatch[1]}/projects/${workflowStepsMatch[2]}/workflows/${workflowStepsMatch[3]}/steps` };
  }

  const workflowStepMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/workflows\/(\d+)\/steps\/(\d+)$/);
  if (workflowStepMatch && (method === "PATCH" || method === "DELETE")) {
    return { path: `/api/workspaces/${workflowStepMatch[1]}/projects/${workflowStepMatch[2]}/workflows/${workflowStepMatch[3]}/steps/${workflowStepMatch[4]}` };
  }

  const workflowsMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/workflows$/);
  if (workflowsMatch && (method === "GET" || method === "POST")) {
    return { path: `/api/workspaces/${workflowsMatch[1]}/projects/${workflowsMatch[2]}/workflows` };
  }

  const workflowMatch = path.match(/^workspaces\/(\d+)\/projects\/(\d+)\/workflows\/(\d+)$/);
  if (workflowMatch && (method === "GET" || method === "PATCH" || method === "DELETE")) {
    return { path: `/api/workspaces/${workflowMatch[1]}/projects/${workflowMatch[2]}/workflows/${workflowMatch[3]}` };
  }

  const hubMatch = path.match(/^workspaces\/(\d+)\/hub\/(research|scenes|renders|audio|qc|publishing|media|agents|analytics)$/);
  if (hubMatch && method === "GET") {
    return { path: `/api/workspaces/${hubMatch[1]}/hub/${hubMatch[2]}` };
  }

  const runFileMatch = path.match(/^workspaces\/(\d+)\/production-runs\/(\d+)\/files\/(video|contact|audio|frame)(?:\/(\d+))?$/);
  if (runFileMatch && method === "GET") {
    return {
      path: `/api/workspaces/${runFileMatch[1]}/production-runs/${runFileMatch[2]}/files/${runFileMatch[3]}${runFileMatch[4] ? `/${runFileMatch[4]}` : ""}`,
      binary: true,
    };
  }

  const productionRunsMatch = path.match(/^workspaces\/(\d+)\/production-runs$/);
  if (productionRunsMatch && (method === "GET" || method === "POST")) {
    return { path: `/api/workspaces/${productionRunsMatch[1]}/production-runs` };
  }

  const productionRunMatch = path.match(/^workspaces\/(\d+)\/production-runs\/(\d+)$/);
  if (productionRunMatch && method === "GET") {
    return { path: `/api/workspaces/${productionRunMatch[1]}/production-runs/${productionRunMatch[2]}` };
  }

  const productionRunActionMatch = path.match(/^workspaces\/(\d+)\/production-runs\/(\d+)\/(resume|approve-publish|cancel)$/);
  if (productionRunActionMatch && method === "POST") {
    return { path: `/api/workspaces/${productionRunActionMatch[1]}/production-runs/${productionRunActionMatch[2]}/${productionRunActionMatch[3]}` };
  }

  const projectsMatch = path.match(/^workspaces\/(\d+)\/projects$/);
  if (projectsMatch && (method === "GET" || method === "POST")) {
    return { path: `/api/workspaces/${projectsMatch[1]}/projects` };
  }

  const channelsMatch = path.match(/^workspaces\/(\d+)\/channels$/);
  if (channelsMatch && (method === "GET" || method === "POST")) {
    return { path: `/api/workspaces/${channelsMatch[1]}/channels` };
  }

  const channelMatch = path.match(/^workspaces\/(\d+)\/channels\/(\d+)$/);
  if (channelMatch && (method === "PATCH" || method === "DELETE")) {
    return { path: `/api/workspaces/${channelMatch[1]}/channels/${channelMatch[2]}` };
  }

  const membersMatch = path.match(/^workspaces\/(\d+)\/members$/);
  if (membersMatch && (method === "GET" || method === "POST")) {
    return { path: `/api/workspaces/${membersMatch[1]}/members` };
  }

  const memberMatch = path.match(/^workspaces\/(\d+)\/members\/(\d+)$/);
  if (memberMatch && (method === "PATCH" || method === "DELETE")) {
    return { path: `/api/workspaces/${memberMatch[1]}/members/${memberMatch[2]}` };
  }

  const workspaceMatch = path.match(/^workspaces\/(\d+)$/);
  if (workspaceMatch && (method === "GET" || method === "PATCH")) {
    return { path: `/api/workspaces/${workspaceMatch[1]}` };
  }

  const projectMatch = path.match(/^projects\/(\d+)$/);
  if (projectMatch && method === "DELETE") {
    return { path: `/api/projects/${projectMatch[1]}` };
  }

  return null;
}

function sameOrigin(request: NextRequest) {
  return request.headers.get("origin") === request.nextUrl.origin;
}

async function handle(request: NextRequest, context: RouteContext, method: HttpMethod) {
  const { segments } = await context.params;
  const endpoint = resolveEndpoint(method, segments);
  if (!endpoint) return jsonResponse({ message: "Not found." }, 404);

  if (method !== "GET" && !sameOrigin(request)) {
    return jsonResponse({ message: "Invalid request origin." }, 403);
  }

  const cookieStore = await cookies();
  const token = cookieStore.get(sessionCookie)?.value;
  let body: string | undefined;

  if (["POST", "PUT", "PATCH"].includes(method) && endpoint.path !== "/api/auth/logout") {
    const declaredLength = Number(request.headers.get("content-length") || 0);
    if (declaredLength > maxRequestBodyBytes) {
      return jsonResponse({ message: "Request body is too large." }, 413);
    }
    const rawBody = await request.text();
    if (new TextEncoder().encode(rawBody).byteLength > maxRequestBodyBytes) {
      return jsonResponse({ message: "Request body is too large." }, 413);
    }

    // Aksi tanpa data (mis. resume, cancel, generate semua) boleh tanpa body.
    if (rawBody.trim() !== "") {
      if (!request.headers.get("content-type")?.toLowerCase().startsWith("application/json")) {
        return jsonResponse({ message: "JSON request body required." }, 415);
      }
      body = rawBody;
    }
  }

  let upstream: Response;
  const isScriptGeneration = method === "POST" && endpoint.path.endsWith("/script/generate");
  const timeoutMs = "binary" in endpoint && endpoint.binary ? 120_000 : 10_000;
  try {
    upstream = await fetch(`${backendUrl}${endpoint.path}`, {
      method,
      headers: {
        Accept: "application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(body ? { "Content-Type": "application/json" } : {}),
      },
      ...(body ? { body } : {}),
      cache: "no-store",
      signal: AbortSignal.timeout(timeoutMs),
    });
  } catch (error) {
    if (error instanceof Error && error.name === "TimeoutError") {
      return jsonResponse({ message: "Studio API request timed out." }, 504);
    }
    return jsonResponse({ message: "Studio API is unavailable." }, 503);
  }

  if ("binary" in endpoint && endpoint.binary) {
    if (!upstream.ok || !upstream.body) return jsonResponse({ message: "File not found." }, upstream.status === 404 ? 404 : 502);
    const allowed = ["video/mp4", "audio/mpeg", "audio/wav", "image/png"];
    const type = upstream.headers.get("content-type") ?? "";
    if (!allowed.includes(type)) return jsonResponse({ message: "Unsupported file." }, 502);
    return new Response(upstream.body, {
      status: 200,
      headers: { "Content-Type": type, "Cache-Control": "private, max-age=60", "X-Content-Type-Options": "nosniff" },
    });
  }

  let payload: Record<string, unknown>;
  try {
    payload = await upstream.json() as Record<string, unknown>;
  } catch {
    payload = { message: "Invalid response from Studio API." };
  }

  const issuedToken = endpoint.login && typeof payload.token === "string" ? payload.token : null;
  delete payload.token;
  const isAiProviderError = isScriptGeneration && [502, 503].includes(upstream.status);
  const providerMessage = typeof payload.message === "string" && payload.message.startsWith("9Router ")
    ? payload.message
    : upstream.status === 503
      ? "9Router tidak merespons dalam batas waktu."
      : "9Router gagal membuat draf.";
  const safePayload = upstream.status >= 500
    ? {
      message: isAiProviderError
        ? providerMessage
        : "Studio API error.",
    }
    : payload;
  const response = jsonResponse(
    safePayload,
    endpoint.login && upstream.ok && !issuedToken ? 502 : upstream.status,
  );

  if (endpoint.login && upstream.ok && issuedToken) {
    response.cookies.set(sessionCookie, issuedToken, {
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "strict",
      path: "/api/studio",
      maxAge: sessionMaxAge,
    });
  }

  if (endpoint.path === "/api/auth/logout") {
    response.cookies.set(sessionCookie, "", {
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "strict",
      path: "/api/studio",
      maxAge: 0,
    });
  }

  return response;
}

export function GET(request: NextRequest, context: RouteContext) {
  return handle(request, context, "GET");
}

export function POST(request: NextRequest, context: RouteContext) {
  return handle(request, context, "POST");
}

export function PUT(request: NextRequest, context: RouteContext) {
  return handle(request, context, "PUT");
}

export function PATCH(request: NextRequest, context: RouteContext) {
  return handle(request, context, "PATCH");
}

export function DELETE(request: NextRequest, context: RouteContext) {
  return handle(request, context, "DELETE");
}
