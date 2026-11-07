"use client";

import {
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  ArrowUp,
  Check,
  Clock3,
  FileText,
  History,
  LoaderCircle,
  Plus,
  RotateCcw,
  Save,
  Search,
  ShieldCheck,
  Sparkles,
  Trash2,
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";

type WorkspaceProject = {
  id: string;
  title: string;
  topic: string;
};

type ScriptDraft = {
  format: string;
  narration: string;
  word_count: number;
  version: number;
  status: string;
};

type ScriptGenerationState = {
  id: number;
  status: "queued" | "running" | "completed" | "failed";
  format: string;
  model: string | null;
  narration: string | null;
  scenes: DraftScene[] | null;
  scene_count: number;
  storyboard_imported: boolean;
  word_count: number;
  error_message: string | null;
  updated_at: string | null;
};

type DraftScene = {
  title: string;
  narration: string | null;
  visual_description: string | null;
  camera_direction: string | null;
  duration_seconds: number;
};

type ResearchSource = {
  id: number;
  title: string;
  url: string;
  publisher: string | null;
  published_at: string | null;
  key_findings: string | null;
  reliability_note: string | null;
  verified: boolean;
};

type ResearchSummary = {
  sources: ResearchSource[];
  source_count: number;
  verified_count: number;
  independent_domain_count: number;
  verified: boolean;
};

type SourceDraft = {
  title: string;
  url: string;
  publisher: string;
  published_at: string;
  key_findings: string;
  reliability_note: string;
};

type SourceCandidate = {
  title: string;
  url: string;
  publisher: string | null;
  published_at: string | null;
  snippet: string;
  key_findings: string;
  reliability_note: string | null;
  reason: string | null;
};

type SceneRecord = {
  id: number;
  position: number;
  title: string;
  narration: string | null;
  visual_description: string | null;
  camera_direction: string | null;
  duration_seconds: number;
  status: string;
};

type SceneDraft = Omit<SceneRecord, "id" | "position" | "status"> & {
  id: number | null;
};

type PromptRecord = {
  id: number;
  scene_id: number | null;
  scene_title: string | null;
  title: string | null;
  positive: string;
  negative: string | null;
  style: string | null;
  camera: string | null;
  lighting: string | null;
  mood: string | null;
  notes: string | null;
  status: "draft" | "approved";
  version: number;
  revision_count: number;
  updated_at: string | null;
};

type PromptDraft = {
  title: string;
  positive: string;
  negative: string;
  style: string;
  camera: string;
  lighting: string;
  mood: string;
  notes: string;
};

type PromptRevisionRecord = {
  version: number;
  snapshot: Record<string, string | null>;
  created_at: string | null;
};

type AiGenerationState = {
  id: number;
  task: "prompt" | "prompt_all" | "research" | "research_search";
  status: "queued" | "running" | "completed" | "failed";
  model: string | null;
  input: Record<string, unknown>;
  result: Record<string, unknown>;
  error_message: string | null;
};

function draftText(result: Record<string, unknown>, key: string): string {
  const value = result[key];
  return typeof value === "string" ? value : "";
}

function stringList(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === "string") : [];
}

function parseSourceCandidates(value: unknown): SourceCandidate[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((entry) => {
    if (typeof entry !== "object" || entry === null) return [];
    const record = entry as Record<string, unknown>;
    if (typeof record.url !== "string" || record.url.trim() === "") return [];
    const text = (key: string) => (typeof record[key] === "string" ? (record[key] as string) : null);
    return [{
      title: text("title") ?? sourceDomain(record.url),
      url: record.url,
      publisher: text("publisher"),
      published_at: text("published_at"),
      snippet: text("snippet") ?? "",
      key_findings: text("key_findings") ?? "",
      reliability_note: text("reliability_note"),
      reason: text("reason"),
    }];
  });
}

const sourceFieldLabels: Record<string, string> = {
  title: "judul",
  publisher: "penerbit",
  published_at: "tanggal terbit",
  key_findings: "temuan kunci",
  reliability_note: "catatan reliabilitas",
};

const emptyScript: ScriptDraft = {
  format: "mystery_investigation",
  narration: "",
  word_count: 0,
  version: 0,
  status: "draft",
};

const emptyScene: SceneDraft = {
  id: null,
  title: "",
  narration: "",
  visual_description: "",
  camera_direction: "",
  duration_seconds: 5,
};

const emptyResearch: ResearchSummary = {
  sources: [],
  source_count: 0,
  verified_count: 0,
  independent_domain_count: 0,
  verified: false,
};

const emptySource: SourceDraft = {
  title: "",
  url: "",
  publisher: "",
  published_at: "",
  key_findings: "",
  reliability_note: "",
};

const emptyPrompt: PromptDraft = {
  title: "",
  positive: "",
  negative: "",
  style: "",
  camera: "",
  lighting: "",
  mood: "",
  notes: "",
};

const formatOptions = [
  { value: "chain_build", label: "A · Rantai Bangun" },
  { value: "mystery_investigation", label: "B · Misteri & Investigasi" },
  { value: "flag_banter", label: "C · Debat Bendera" },
];

function toSceneDraft(scene: SceneRecord): SceneDraft {
  return {
    id: scene.id,
    title: scene.title,
    narration: scene.narration ?? "",
    visual_description: scene.visual_description ?? "",
    camera_direction: scene.camera_direction ?? "",
    duration_seconds: scene.duration_seconds,
  };
}

function countWords(text: string) {
  return text.trim() ? text.trim().split(/\s+/u).length : 0;
}

function sourceDomain(url: string) {
  try {
    return new URL(url).hostname.replace(/^www\./, "");
  } catch {
    return url;
  }
}

function toPromptDraft(prompt: PromptRecord): PromptDraft {
  return {
    title: prompt.title ?? "",
    positive: prompt.positive,
    negative: prompt.negative ?? "",
    style: prompt.style ?? "",
    camera: prompt.camera ?? "",
    lighting: prompt.lighting ?? "",
    mood: prompt.mood ?? "",
    notes: prompt.notes ?? "",
  };
}

const promptFields = Object.keys(emptyPrompt) as (keyof PromptDraft)[];

function formatTimestamp(value: string | null) {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "—"
    : date.toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });
}

export default function ProductionWorkspace({
  project,
  workspaceId,
  initialTab = "script",
  onBack,
  onToast,
}: {
  project: WorkspaceProject;
  workspaceId: number;
  initialTab?: "script" | "storyboard" | "research" | "prompt";
  onBack: () => void;
  onToast: (message: string) => void;
}) {
  const [activeTab, setActiveTab] = useState<"script" | "storyboard" | "research" | "prompt">(initialTab);
  const [script, setScript] = useState<ScriptDraft>(emptyScript);
  const [savedScript, setSavedScript] = useState<ScriptDraft>(emptyScript);
  const [scenes, setScenes] = useState<SceneRecord[]>([]);
  const [selectedSceneId, setSelectedSceneId] = useState<number | null>(null);
  const [sceneDraft, setSceneDraft] = useState<SceneDraft>(emptyScene);
  const [research, setResearch] = useState<ResearchSummary>(emptyResearch);
  const [sourceDraft, setSourceDraft] = useState<SourceDraft>(emptySource);
  const [prompts, setPrompts] = useState<PromptRecord[]>([]);
  const [selectedPromptId, setSelectedPromptId] = useState<number | null>(null);
  const [promptSceneId, setPromptSceneId] = useState<number | null>(null);
  const [promptDraft, setPromptDraft] = useState<PromptDraft>(emptyPrompt);
  const [promptRevisions, setPromptRevisions] = useState<PromptRevisionRecord[]>([]);
  const [revisionsOpen, setRevisionsOpen] = useState(false);
  const [storyboardDraft, setStoryboardDraft] = useState<DraftScene[]>([]);
  const [draftGenerationId, setDraftGenerationId] = useState<number | null>(null);
  const [draftImported, setDraftImported] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [sceneBusy, setSceneBusy] = useState(false);
  const [researchBusy, setResearchBusy] = useState(false);
  const [promptBusy, setPromptBusy] = useState(false);
  const [promptAiBusy, setPromptAiBusy] = useState(false);
  const [promptAllAiBusy, setPromptAllAiBusy] = useState(false);
  const [researchAiBusy, setResearchAiBusy] = useState(false);
  const [researchSearchBusy, setResearchSearchBusy] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [sourceCandidates, setSourceCandidates] = useState<SourceCandidate[]>([]);
  const [inferredFields, setInferredFields] = useState<string[]>([]);
  const [revisionsBusy, setRevisionsBusy] = useState(false);
  const [error, setError] = useState("");

  const baseUrl = `/api/studio/workspaces/${workspaceId}/projects/${project.id}`;
  const totalSeconds = useMemo(
    () => scenes.reduce((total, scene) => total + scene.duration_seconds, 0),
    [scenes],
  );
  const words = countWords(script.narration);
  const scriptDirty = script.format !== savedScript.format || script.narration !== savedScript.narration;
  const selectedScene = scenes.find((scene) => scene.id === selectedSceneId);
  const sceneDirty = selectedScene !== undefined && (
    sceneDraft.title !== selectedScene.title
    || sceneDraft.narration !== (selectedScene.narration ?? "")
    || sceneDraft.visual_description !== (selectedScene.visual_description ?? "")
    || sceneDraft.camera_direction !== (selectedScene.camera_direction ?? "")
    || sceneDraft.duration_seconds !== selectedScene.duration_seconds
  );
  const selectedPrompt = prompts.find((prompt) => prompt.id === selectedPromptId) ?? null;
  const promptScene = scenes.find((scene) => scene.id === promptSceneId) ?? null;
  const promptBaseline = selectedPrompt ? toPromptDraft(selectedPrompt) : emptyPrompt;
  const promptDirty = promptFields.some((field) => promptDraft[field] !== promptBaseline[field]);

  function applyGenerationDraft(generation: ScriptGenerationState, loadedScript: ScriptDraft) {
    setStoryboardDraft(generation.scenes ?? []);
    setDraftGenerationId(generation.id);
    setDraftImported(generation.storyboard_imported);

    if (generation.status === "failed") {
      if (generation.error_message) setError(generation.error_message);
      return;
    }

    if (generation.status === "completed" && !loadedScript.narration.trim() && generation.narration) {
      setScript((current) => ({ ...current, narration: generation.narration ?? "" }));
    }
  }

  useEffect(() => {
    let active = true;

    async function loadProduction() {
      setLoading(true);
      setError("");
      try {
        const [productionResponse, generationResponse, promptsResponse] = await Promise.all([
          fetch(`${baseUrl}/production`, { cache: "no-store" }),
          fetch(`${baseUrl}/script/generate`, { cache: "no-store" }),
          fetch(`${baseUrl}/prompts`, { cache: "no-store" }),
        ]);
        const result = await productionResponse.json();
        if (!productionResponse.ok) throw new Error(result.message || "Ruang produksi gagal dimuat.");
        if (!active) return;

        const loadedScript = result.script
          ? { ...emptyScript, ...result.script }
          : emptyScript;
        const loadedScenes = result.scenes as SceneRecord[];
        const loadedPrompts = promptsResponse.ok
          ? ((await promptsResponse.json()).prompts as PromptRecord[])
          : [];
        setScript(loadedScript);
        setSavedScript(loadedScript);
        setScenes(loadedScenes);
        setSelectedSceneId(loadedScenes[0]?.id ?? null);
        setSceneDraft(loadedScenes[0] ? toSceneDraft(loadedScenes[0]) : emptyScene);
        setResearch((result.research as ResearchSummary) ?? emptyResearch);
        setPrompts(loadedPrompts);
        const firstSceneWithPrompt = loadedScenes.find((scene) =>
          loadedPrompts.some((prompt) => prompt.scene_id === scene.id));
        if (firstSceneWithPrompt) {
          const existing = loadedPrompts.find((prompt) => prompt.scene_id === firstSceneWithPrompt.id) ?? null;
          setPromptSceneId(firstSceneWithPrompt.id);
          setSelectedPromptId(existing?.id ?? null);
          setPromptDraft(existing ? toPromptDraft(existing) : emptyPrompt);
        } else {
          setPromptSceneId(loadedScenes[0]?.id ?? null);
          setSelectedPromptId(null);
          setPromptDraft(emptyPrompt);
        }

        const generationResult = generationResponse.ok ? await generationResponse.json() : null;
        const generation = (generationResult?.generation ?? null) as ScriptGenerationState | null;
        if (!active || generation === null) return;

        if (generation.status === "queued" || generation.status === "running") {
          setGenerating(true);
        } else {
          applyGenerationDraft(generation, loadedScript);
        }
      } catch (loadError) {
        if (active) setError(loadError instanceof Error ? loadError.message : "Ruang produksi gagal dimuat.");
      } finally {
        if (active) setLoading(false);
      }
    }

    void loadProduction();
    return () => {
      active = false;
    };
  }, [baseUrl]);

  useEffect(() => {
    if (!generating) return;
    let active = true;

    const timer = window.setInterval(async () => {
      try {
        const response = await fetch(`${baseUrl}/script/generate`, { cache: "no-store" });
        if (!response.ok) return;
        const result = await response.json();
        const generation = (result?.generation ?? null) as ScriptGenerationState | null;
        if (!active || generation === null) return;

        if (generation.status === "completed") {
          setScript((current) => ({ ...current, narration: generation.narration ?? "" }));
          setStoryboardDraft(generation.scenes ?? []);
          setDraftGenerationId(generation.id);
          setDraftImported(generation.storyboard_imported);
          setGenerating(false);
          const sceneNote = generation.scenes?.length
            ? ` Beserta ${generation.scenes.length} adegan untuk storyboard.`
            : " Provider tidak menyertakan breakdown adegan.";
          onToast(`Draf ${generation.model} siap ditinjau.${sceneNote} Belum disimpan; verifikasi fakta sebelum produksi.`);
        } else if (generation.status === "failed") {
          setError(generation.error_message || "Draf AI gagal dibuat.");
          setGenerating(false);
        }
      } catch {
        // Job tetap berjalan di backend; polling dilanjutkan sampai ada status akhir.
      }
    }, 2500);

    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [generating, baseUrl, onToast]);

  useEffect(() => {
    let active = true;

    async function resumeAiDrafts() {
      try {
        const [promptResponse, promptAllResponse, researchResponse, searchResponse] = await Promise.all([
          fetch(`${baseUrl}/ai/prompt`, { cache: "no-store" }),
          fetch(`${baseUrl}/ai/prompt_all`, { cache: "no-store" }),
          fetch(`${baseUrl}/ai/research`, { cache: "no-store" }),
          fetch(`${baseUrl}/ai/research_search`, { cache: "no-store" }),
        ]);
        if (!active) return;
        const promptGeneration = promptResponse.ok
          ? ((await promptResponse.json()).generation as AiGenerationState | null)
          : null;
        const promptAllGeneration = promptAllResponse.ok
          ? ((await promptAllResponse.json()).generation as AiGenerationState | null)
          : null;
        const researchGeneration = researchResponse.ok
          ? ((await researchResponse.json()).generation as AiGenerationState | null)
          : null;
        const searchGeneration = searchResponse.ok
          ? ((await searchResponse.json()).generation as AiGenerationState | null)
          : null;
        if (promptGeneration?.status === "queued" || promptGeneration?.status === "running") setPromptAiBusy(true);
        if (promptAllGeneration?.status === "queued" || promptAllGeneration?.status === "running") setPromptAllAiBusy(true);
        if (researchGeneration?.status === "queued" || researchGeneration?.status === "running") setResearchAiBusy(true);
        if (searchGeneration?.status === "queued" || searchGeneration?.status === "running") setResearchSearchBusy(true);
      } catch {
        // Status draf AI bersifat opsional; kegagalan di sini tidak boleh menggagalkan pemuatan ruang produksi.
      }
    }

    void resumeAiDrafts();
    return () => {
      active = false;
    };
  }, [baseUrl]);

  useEffect(() => {
    if (!promptAiBusy && !promptAllAiBusy && !researchAiBusy && !researchSearchBusy) return;
    let active = true;

    async function syncPrompts() {
      const response = await fetch(`${baseUrl}/prompts`, { cache: "no-store" });
      if (!response.ok || !active) return;
      const list = (((await response.json()).prompts ?? []) as PromptRecord[]);
      const existing = list.find((prompt) => prompt.scene_id === promptSceneId) ?? null;
      setPrompts(list);
      setSelectedPromptId(existing?.id ?? null);
      setPromptDraft(existing ? toPromptDraft(existing) : emptyPrompt);
      setPromptRevisions([]);
      setRevisionsOpen(false);
    }

    const timer = window.setInterval(async () => {
      const tasks: Array<AiGenerationState["task"]> = [];
      if (promptAiBusy) tasks.push("prompt");
      if (promptAllAiBusy) tasks.push("prompt_all");
      if (researchAiBusy) tasks.push("research");
      if (researchSearchBusy) tasks.push("research_search");

      for (const task of tasks) {
        try {
          const response = await fetch(`${baseUrl}/ai/${task}`, { cache: "no-store" });
          if (!response.ok) continue;
          const generation = ((await response.json()).generation ?? null) as AiGenerationState | null;
          if (!active || generation === null) continue;

          if (generation.status === "completed") {
            if (task === "prompt") {
              setPromptDraft({
                title: draftText(generation.result, "title"),
                positive: draftText(generation.result, "positive"),
                negative: draftText(generation.result, "negative"),
                style: draftText(generation.result, "style"),
                camera: draftText(generation.result, "camera"),
                lighting: draftText(generation.result, "lighting"),
                mood: draftText(generation.result, "mood"),
                notes: draftText(generation.result, "notes"),
              });
              setPromptAiBusy(false);
              onToast(`Draf prompt ${generation.model ?? "AI"} siap. Periksa dan edit sebelum disimpan.`);
            } else if (task === "prompt_all") {
              const created = typeof generation.result.created === "number" ? generation.result.created : 0;
              setPromptAllAiBusy(false);
              await syncPrompts();
              onToast(`${created} prompt draf dibuat untuk adegan yang belum terisi. Periksa dan setujui sebelum produksi.`);
            } else if (task === "research_search") {
              const candidates = parseSourceCandidates(generation.result.candidates);
              setSourceCandidates(candidates);
              setResearchSearchBusy(false);
              onToast(`${candidates.length} kandidat sumber ditemukan. Pilih salah satu untuk mengisi form, lalu verifikasi sebelum disimpan.`);
            } else {
              setSourceDraft((current) => ({
                ...current,
                title: draftText(generation.result, "title") || current.title,
                publisher: draftText(generation.result, "publisher") || current.publisher,
                published_at: draftText(generation.result, "published_at") || current.published_at,
                key_findings: draftText(generation.result, "key_findings"),
                reliability_note: draftText(generation.result, "reliability_note"),
              }));
              setInferredFields(stringList(generation.result.inferred_fields));
              setResearchAiBusy(false);
              onToast(`Draf sumber ${generation.model ?? "AI"} siap. Field yang diinferensi ditandai; verifikasi ke sumber aslinya sebelum disimpan.`);
            }
          } else if (generation.status === "failed") {
            setError(generation.error_message || "Draf AI gagal dibuat.");
            if (task === "prompt") {
              setPromptAiBusy(false);
            } else if (task === "prompt_all") {
              setPromptAllAiBusy(false);
            } else if (task === "research_search") {
              setResearchSearchBusy(false);
            } else {
              setResearchAiBusy(false);
            }
          }
        } catch {
          // Job tetap berjalan di backend; polling dilanjutkan sampai ada status akhir.
        }
      }
    }, 2500);

    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [promptAiBusy, promptAllAiBusy, researchAiBusy, researchSearchBusy, baseUrl, onToast, promptSceneId]);

  async function saveScript() {
    setSaving(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/script`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ format: script.format, narration: script.narration }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Naskah gagal disimpan.");
      setScript(result.script);
      setSavedScript(result.script);
      onToast("Naskah tersimpan di workspace.");
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Naskah gagal disimpan.");
    } finally {
      setSaving(false);
    }
  }

  async function generateScript() {
    if (scriptDirty && script.narration.trim()
      && !window.confirm("Draf AI akan mengganti teks naskah yang belum disimpan. Lanjutkan?")) {
      return;
    }

    setError("");
    try {
      const response = await fetch(`${baseUrl}/script/generate`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ format: script.format }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Draf AI gagal dibuat.");

      setGenerating(true);
      onToast("Draf AI berjalan di latar belakang. Anda boleh menutup atau me-refresh halaman ini.");
    } catch (generationError) {
      setError(generationError instanceof Error ? generationError.message : "Draf AI gagal dibuat.");
    }
  }

  async function generatePromptWithAi() {
    if (promptSceneId === null) return;
    if (promptDraft.positive.trim()
      && !window.confirm("Draf AI akan mengganti isi prompt yang belum disimpan. Lanjutkan?")) {
      return;
    }

    setError("");
    try {
      const response = await fetch(`${baseUrl}/ai/prompt`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ scene_id: promptSceneId }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Draf prompt AI gagal dibuat.");

      setPromptAiBusy(true);
      onToast("Draf prompt AI diproses di latar belakang. Isi form akan terisi otomatis saat selesai.");
    } catch (aiError) {
      setError(aiError instanceof Error ? aiError.message : "Draf prompt AI gagal dibuat.");
    }
  }

  async function generateAllPromptsWithAi() {
    const pending = scenes.filter((scene) => !prompts.some((prompt) => prompt.scene_id === scene.id));
    if (pending.length === 0) {
      setError("Semua adegan sudah memiliki prompt.");
      return;
    }
    if (!window.confirm(`${pending.length} adegan akan diisi draf prompt dari AI sekaligus.${promptDirty ? " Perubahan prompt yang belum disimpan akan tergantikan." : ""} Lanjutkan?`)) {
      return;
    }

    setError("");
    try {
      const response = await fetch(`${baseUrl}/ai/prompt_all`, { method: "POST" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Draf prompt AI gagal dibuat.");

      setPromptAllAiBusy(true);
      onToast("AI sedang menyusun prompt untuk semua adegan. Daftar prompt akan diperbarui otomatis saat selesai.");
    } catch (aiError) {
      setError(aiError instanceof Error ? aiError.message : "Draf prompt AI gagal dibuat.");
    }
  }

  async function generateSourceWithAi() {
    if (!sourceDraft.url.trim()) {
      setError("Isi URL sumber terlebih dahulu agar AI dapat menyusun draf temuan.");
      return;
    }
    if (sourceDraft.key_findings.trim()
      && !window.confirm("Draf AI akan mengganti temuan kunci yang sudah diisi. Lanjutkan?")) {
      return;
    }

    setError("");
    try {
      const response = await fetch(`${baseUrl}/ai/research`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          url: sourceDraft.url.trim(),
          title: sourceDraft.title.trim() || null,
          publisher: sourceDraft.publisher.trim() || null,
          published_at: sourceDraft.published_at.trim() || null,
        }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Draf temuan AI gagal dibuat.");

      setResearchAiBusy(true);
      onToast("Draf riset AI diproses di latar belakang. Temuan kunci akan terisi otomatis saat selesai.");
    } catch (aiError) {
      setError(aiError instanceof Error ? aiError.message : "Draf temuan AI gagal dibuat.");
    }
  }

  async function searchSourcesWithAi() {
    const query = searchQuery.trim();
    if (!query) {
      setError("Isi kata kunci pencarian terlebih dahulu.");
      return;
    }

    setError("");
    try {
      const response = await fetch(`${baseUrl}/ai/research_search`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ query }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Pencarian sumber gagal dijalankan.");

      setSourceCandidates([]);
      setResearchSearchBusy(true);
      onToast("Pencarian sumber berjalan di latar belakang. Hasil akan muncul otomatis saat selesai.");
    } catch (searchError) {
      setError(searchError instanceof Error ? searchError.message : "Pencarian sumber gagal dijalankan.");
    }
  }

  function applyCandidate(candidate: SourceCandidate) {
    setSourceDraft({
      title: candidate.title,
      url: candidate.url,
      publisher: candidate.publisher ?? "",
      published_at: candidate.published_at ?? "",
      key_findings: candidate.key_findings,
      reliability_note: candidate.reliability_note ?? "",
    });
    setInferredFields(["title", "publisher", "published_at", "key_findings", "reliability_note"]);
    onToast("Form sumber terisi dari hasil pencarian. Periksa dan sunting sebelum disimpan.");
  }

  async function createScene() {
    setSceneBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/scenes`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ title: `Adegan ${scenes.length + 1}` }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Adegan gagal dibuat.");
      const created = result.scene as SceneRecord;
      setScenes((current) => [...current, created]);
      setSelectedSceneId(created.id);
      setSceneDraft(toSceneDraft(created));
      setActiveTab("storyboard");
    } catch (createError) {
      setError(createError instanceof Error ? createError.message : "Adegan gagal dibuat.");
    } finally {
      setSceneBusy(false);
    }
  }

  async function saveScene() {
    if (!sceneDraft.id) return;
    setSceneBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/scenes/${sceneDraft.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          title: sceneDraft.title,
          narration: sceneDraft.narration,
          visual_description: sceneDraft.visual_description,
          camera_direction: sceneDraft.camera_direction,
          duration_seconds: sceneDraft.duration_seconds,
        }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Adegan gagal disimpan.");
      const updated = result.scene as SceneRecord;
      setScenes((current) => current.map((scene) => scene.id === updated.id ? updated : scene));
      setSceneDraft(toSceneDraft(updated));
      onToast("Adegan tersimpan di storyboard.");
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Adegan gagal disimpan.");
    } finally {
      setSceneBusy(false);
    }
  }

  async function reorderScene(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (target < 0 || target >= scenes.length) return;
    const reordered = [...scenes];
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
    setSceneBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/scenes/order`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ scene_ids: reordered.map((scene) => scene.id) }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Urutan adegan gagal disimpan.");
      setScenes(result.scenes as SceneRecord[]);
    } catch (reorderError) {
      setError(reorderError instanceof Error ? reorderError.message : "Urutan adegan gagal disimpan.");
    } finally {
      setSceneBusy(false);
    }
  }

  async function deleteScene() {
    if (!sceneDraft.id || !window.confirm(`Hapus "${sceneDraft.title}" dari storyboard?`)) return;
    setSceneBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/scenes/${sceneDraft.id}`, { method: "DELETE" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Adegan gagal dihapus.");
      const remaining = scenes
        .filter((scene) => scene.id !== sceneDraft.id)
        .map((scene, position) => ({ ...scene, position }));
      setScenes(remaining);
      setSelectedSceneId(remaining[0]?.id ?? null);
      setSceneDraft(remaining[0] ? toSceneDraft(remaining[0]) : emptyScene);
      onToast("Adegan dihapus dari storyboard.");
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Adegan gagal dihapus.");
    } finally {
      setSceneBusy(false);
    }
  }

  async function importStoryboard() {
    if (draftGenerationId === null || storyboardDraft.length === 0) return;
    setSceneBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/storyboard/import`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ generation_id: draftGenerationId }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Breakdown adegan gagal disimpan.");
      const imported = result.scenes as SceneRecord[];
      setScenes(imported);
      setSelectedSceneId(imported[0]?.id ?? null);
      setSceneDraft(imported[0] ? toSceneDraft(imported[0]) : emptyScene);
      setDraftImported(true);
      onToast(`${storyboardDraft.length} adegan draf AI disimpan ke storyboard.`);
    } catch (importError) {
      setError(importError instanceof Error ? importError.message : "Breakdown adegan gagal disimpan.");
    } finally {
      setSceneBusy(false);
    }
  }

  async function addSource() {
    if (!sourceDraft.title.trim() || !sourceDraft.url.trim()) {
      setError("Judul dan URL sumber riset wajib diisi.");
      return;
    }

    setResearchBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/research-sources`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          title: sourceDraft.title.trim(),
          url: sourceDraft.url.trim(),
          publisher: sourceDraft.publisher.trim() || null,
          published_at: sourceDraft.published_at.trim() || null,
          key_findings: sourceDraft.key_findings.trim() || null,
          reliability_note: sourceDraft.reliability_note.trim() || null,
        }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Sumber riset gagal disimpan.");
      setResearch(result.research as ResearchSummary);
      setSourceDraft(emptySource);
      setInferredFields([]);
      onToast("Sumber riset ditambahkan. Tandai terverifikasi setelah Anda memeriksa isinya.");
    } catch (sourceError) {
      setError(sourceError instanceof Error ? sourceError.message : "Sumber riset gagal disimpan.");
    } finally {
      setResearchBusy(false);
    }
  }

  async function toggleSourceVerified(source: ResearchSource) {
    setResearchBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/research-sources/${source.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ verified: !source.verified }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Status verifikasi sumber gagal diubah.");
      setResearch(result.research as ResearchSummary);
      onToast(source.verified ? "Verifikasi sumber dibatalkan." : "Sumber ditandai terverifikasi.");
    } catch (verifyError) {
      setError(verifyError instanceof Error ? verifyError.message : "Status verifikasi sumber gagal diubah.");
    } finally {
      setResearchBusy(false);
    }
  }

  async function deleteSource(source: ResearchSource) {
    if (!window.confirm(`Hapus sumber "${source.title}" dari daftar riset?`)) return;
    setResearchBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/research-sources/${source.id}`, { method: "DELETE" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Sumber riset gagal dihapus.");
      setResearch(result.research as ResearchSummary);
      onToast("Sumber riset dihapus.");
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Sumber riset gagal dihapus.");
    } finally {
      setResearchBusy(false);
    }
  }

  async function refreshPrompts(keepSceneId: number | null) {
    const response = await fetch(`${baseUrl}/prompts`, { cache: "no-store" });
    if (!response.ok) return;
    const result = await response.json();
    const list = (result.prompts ?? []) as PromptRecord[];
    setPrompts(list);
    const existing = list.find((prompt) => prompt.scene_id === keepSceneId) ?? null;
    setSelectedPromptId(existing?.id ?? null);
    setPromptDraft(existing ? toPromptDraft(existing) : emptyPrompt);
    setPromptRevisions([]);
    setRevisionsOpen(false);
  }

  function selectPromptScene(sceneId: number) {
    if (promptDirty && !window.confirm("Perubahan prompt belum disimpan. Ganti adegan dan buang perubahan?")) return;
    const existing = prompts.find((prompt) => prompt.scene_id === sceneId) ?? null;
    setPromptSceneId(sceneId);
    setSelectedPromptId(existing?.id ?? null);
    setPromptDraft(existing ? toPromptDraft(existing) : emptyPrompt);
    setPromptRevisions([]);
    setRevisionsOpen(false);
  }

  async function savePrompt() {
    if (promptSceneId === null) return;
    setPromptBusy(true);
    setError("");
    try {
      const payload = {
        title: promptDraft.title.trim() || null,
        positive: promptDraft.positive.trim(),
        negative: promptDraft.negative.trim() || null,
        style: promptDraft.style.trim() || null,
        camera: promptDraft.camera.trim() || null,
        lighting: promptDraft.lighting.trim() || null,
        mood: promptDraft.mood.trim() || null,
        notes: promptDraft.notes.trim() || null,
      };
      const response = await fetch(
        selectedPrompt ? `${baseUrl}/prompts/${selectedPrompt.id}` : `${baseUrl}/prompts`,
        {
          method: selectedPrompt ? "PATCH" : "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(selectedPrompt ? payload : { ...payload, scene_id: promptSceneId }),
        },
      );
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Prompt gagal disimpan.");
      await refreshPrompts(promptSceneId);
      onToast(selectedPrompt ? "Prompt tersimpan. Revisi baru dibuat bila isi berubah." : "Prompt terstruktur tersimpan.");
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Prompt gagal disimpan.");
    } finally {
      setPromptBusy(false);
    }
  }

  async function togglePromptApproved() {
    if (!selectedPrompt) return;
    const nextStatus = selectedPrompt.status === "approved" ? "draft" : "approved";
    setPromptBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/prompts/${selectedPrompt.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status: nextStatus }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Status prompt gagal diubah.");
      const updated = result.prompt as PromptRecord;
      setPrompts((current) => current.map((prompt) => prompt.id === updated.id ? updated : prompt));
      onToast(nextStatus === "approved" ? "Prompt disetujui." : "Persetujuan prompt dibatalkan.");
    } catch (statusError) {
      setError(statusError instanceof Error ? statusError.message : "Status prompt gagal diubah.");
    } finally {
      setPromptBusy(false);
    }
  }

  async function deletePrompt() {
    if (!selectedPrompt || !window.confirm(`Hapus prompt untuk "${selectedPrompt.scene_title ?? promptScene?.title ?? "adegan ini"}"?`)) return;
    setPromptBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/prompts/${selectedPrompt.id}`, { method: "DELETE" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Prompt gagal dihapus.");
      await refreshPrompts(promptSceneId);
      onToast("Prompt dihapus dari adegan.");
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Prompt gagal dihapus.");
    } finally {
      setPromptBusy(false);
    }
  }

  async function toggleRevisions() {
    if (!selectedPrompt) return;
    if (revisionsOpen) {
      setRevisionsOpen(false);
      return;
    }
    setRevisionsBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/prompts/${selectedPrompt.id}/revisions`, { cache: "no-store" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Riwayat revisi gagal dimuat.");
      setPromptRevisions((result.revisions ?? []) as PromptRevisionRecord[]);
      setRevisionsOpen(true);
    } catch (historyError) {
      setError(historyError instanceof Error ? historyError.message : "Riwayat revisi gagal dimuat.");
    } finally {
      setRevisionsBusy(false);
    }
  }

  function selectScene(scene: SceneRecord) {
    if (sceneDirty && !window.confirm("Perubahan adegan belum disimpan. Ganti adegan dan buang perubahan?")) return;
    setSelectedSceneId(scene.id);
    setSceneDraft(toSceneDraft(scene));
  }

  function updateSceneDraft(field: keyof SceneDraft, value: string | number) {
    setSceneDraft((current) => ({ ...current, [field]: value }));
  }

  return (
    <div className="production-workspace">
      <div className="production-heading">
        <button className="production-back" onClick={onBack}><ArrowLeft size={15} /> Semua proyek</button>
        <div className="production-title-row">
          <div>
            <div className="eyebrow">PRODUCTION WORKSPACE <span className="eyebrow-separator">/</span> DRAFT</div>
            <h1>{project.title}<span className="heading-period">.</span></h1>
            <p>{project.topic}</p>
          </div>
          <span className="production-stage"><span />Draft · belum diverifikasi</span>
        </div>
      </div>

      <div className="production-tabs" role="tablist" aria-label="Tahap produksi">
        <button className={activeTab === "script" ? "active" : ""} role="tab" aria-selected={activeTab === "script"} onClick={() => setActiveTab("script")}><FileText size={15} /> Naskah</button>
        <button className={activeTab === "storyboard" ? "active" : ""} role="tab" aria-selected={activeTab === "storyboard"} onClick={() => setActiveTab("storyboard")}><Clock3 size={15} /> Storyboard <span>{scenes.length}</span></button>
        <button className={activeTab === "research" ? "active" : ""} role="tab" aria-selected={activeTab === "research"} onClick={() => setActiveTab("research")}><ShieldCheck size={15} /> Riset {research.source_count > 0 && <span>{research.source_count}</span>}</button>
        <button className={activeTab === "prompt" ? "active" : ""} role="tab" aria-selected={activeTab === "prompt"} onClick={() => setActiveTab("prompt")}><Sparkles size={15} /> Prompt {prompts.length > 0 && <span>{prompts.length}</span>}</button>
      </div>

      {error && <div className="production-error" role="alert">{error}</div>}
      {loading ? (
        <div className="production-loading"><LoaderCircle size={17} className="spin-icon" /> Memuat data produksi...</div>
      ) : activeTab === "script" ? (
        <section className="script-editor-panel">
          <div className="production-panel-heading">
            <div><span className="section-kicker">SCRIPT EDITOR</span><h2>Naskah narasi</h2></div>
            <div className="script-header-actions">
              <span className="production-version">{savedScript.version > 0 ? `Versi ${savedScript.version}` : "Belum disimpan"}</span>
              <button className="button-outline ai-draft-button" onClick={generateScript} disabled={generating || saving || !project.topic.trim()}>
                {generating ? <LoaderCircle size={13} className="spin-icon" /> : <Sparkles size={13} />}
                {generating ? "Draf AI diproses di latar belakang..." : "Buat draf dengan AI"}
              </button>
            </div>
          </div>

          <div className="script-guidance">
            <span className={words >= 95 && words <= 160 ? "guidance-pass" : "guidance-pending"}>
              {words >= 95 && words <= 160 ? <Check size={13} /> : <Clock3 size={13} />}
              {words} kata <span>/ target 95–160</span>
            </span>
            <span className={research.verified ? "guidance-pass" : "guidance-warning"}>
              {research.verified ? <ShieldCheck size={13} /> : <Clock3 size={13} />}
              {research.verified
                ? `Riset terverifikasi · ${research.verified_count} sumber / ${research.independent_domain_count} domain`
                : `Riset belum diverifikasi · ${research.verified_count}/2 sumber · ${research.independent_domain_count}/2 domain`}
            </span>
          </div>

          <label className="production-label" htmlFor="script-format">Format cerita</label>
          <select id="script-format" className="production-select" disabled={generating} value={script.format} onChange={(event) => setScript((current) => ({ ...current, format: event.target.value }))}>
            {formatOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>

          <label className="production-label" htmlFor="script-narration">Narasi Bahasa Indonesia</label>
          <textarea id="script-narration" className="production-textarea script-textarea" disabled={generating} placeholder="Tulis hook, bangun rasa ingin tahu, lalu tutup dengan payoff yang kuat..." value={script.narration} onChange={(event) => setScript((current) => ({ ...current, narration: event.target.value }))} />

          <div className="production-save-row">
            <span>{scriptDirty ? "Ada perubahan yang belum disimpan" : "Perubahan tersimpan"}</span>
            <button className="button-primary" onClick={saveScript} disabled={saving || generating || !scriptDirty}>
              {saving ? <LoaderCircle size={14} className="spin-icon" /> : <Save size={14} />}
              {saving ? "Menyimpan..." : "Simpan naskah"}
            </button>
          </div>
        </section>
      ) : activeTab === "storyboard" ? (
        <section className="storyboard-panel">
          <div className="production-panel-heading storyboard-heading">
            <div><span className="section-kicker">SCENE BREAKDOWN</span><h2>Storyboard</h2></div>
            <span className="production-version">{scenes.length} adegan · {totalSeconds} dtk</span>
          </div>
          {storyboardDraft.length > 0 && (
            <div className="storyboard-draft">
              <div className="storyboard-draft-heading">
                <div>
                  <span className="section-kicker">DRAF AI · {storyboardDraft.length} ADEGAN</span>
                  <strong>{draftImported ? "Breakdown draf ini sudah tersimpan di storyboard." : "Breakdown adegan dari draf AI — belum disimpan."}</strong>
                </div>
                <button className="button-outline" onClick={importStoryboard} disabled={sceneBusy || draftImported}>
                  {sceneBusy ? <LoaderCircle size={13} className="spin-icon" /> : <Save size={13} />}
                  {draftImported ? "Sudah disimpan" : "Simpan ke storyboard"}
                </button>
              </div>
              <ol className="storyboard-draft-list">
                {storyboardDraft.map((scene, index) => (
                  <li key={`${index}-${scene.title}`}>
                    <span>{String(index + 1).padStart(2, "0")}</span>
                    <div>
                      <strong>{scene.title}</strong>
                      {scene.visual_description && <small>{scene.visual_description}</small>}
                    </div>
                    <small>{scene.duration_seconds} dtk</small>
                  </li>
                ))}
              </ol>
            </div>
          )}
          <div className="storyboard-layout">
            <aside className="scene-list-panel">
              <div className="scene-list-heading"><span>URUTAN ADEGAN</span><button className="icon-button" aria-label="Tambah adegan" disabled={sceneBusy} onClick={createScene}><Plus size={16} /></button></div>
              {scenes.length === 0 ? (
                <div className="scene-list-empty"><span>Storyboard masih kosong.</span><button className="button-outline" onClick={createScene} disabled={sceneBusy}><Plus size={13} /> Tambah adegan</button></div>
              ) : scenes.map((scene, index) => (
                <div className={`scene-list-row ${scene.id === selectedSceneId ? "selected" : ""}`} key={scene.id}>
                  <button className="scene-select" onClick={() => selectScene(scene)}><span>{String(index + 1).padStart(2, "0")}</span><strong>{scene.title}</strong><small>{scene.duration_seconds} dtk</small></button>
                  <div className="scene-order-actions">
                    <button className="icon-button" aria-label={`Pindahkan adegan ${index + 1} ke atas`} disabled={sceneBusy || index === 0} onClick={() => void reorderScene(index, -1)}><ArrowUp size={12} /></button>
                    <button className="icon-button" aria-label={`Pindahkan adegan ${index + 1} ke bawah`} disabled={sceneBusy || index === scenes.length - 1} onClick={() => void reorderScene(index, 1)}><ArrowDown size={12} /></button>
                  </div>
                </div>
              ))}
              {scenes.length > 0 && <button className="add-scene-button" onClick={createScene} disabled={sceneBusy}><Plus size={14} /> Tambah adegan</button>}
            </aside>

            <div className="scene-editor-panel">
              {!sceneDraft.id ? (
                <div className="scene-editor-empty"><div><Clock3 size={20} /></div><h3>Mulai dari adegan pertama.</h3><p>Storyboard menyimpan narasi per adegan, arahan visual, kamera, dan durasi.</p><button className="button-primary" onClick={createScene} disabled={sceneBusy}><Plus size={14} /> Tambah adegan</button></div>
              ) : (
                <>
                  <div className="scene-editor-heading"><div><span className="section-kicker">ADEGAN {String((selectedScene?.position ?? 0) + 1).padStart(2, "0")}</span><span className="scene-draft-pill">Draft · preview belum dibuat</span></div><button className="icon-button" aria-label="Hapus adegan" disabled={sceneBusy} onClick={deleteScene}><Trash2 size={15} /></button></div>
                  <label className="production-label" htmlFor="scene-title">Judul adegan</label>
                  <input id="scene-title" className="production-input" maxLength={120} value={sceneDraft.title} onChange={(event) => updateSceneDraft("title", event.target.value)} />
                  <label className="production-label" htmlFor="scene-narration">Potongan narasi</label>
                  <textarea id="scene-narration" className="production-textarea" rows={3} value={sceneDraft.narration ?? ""} onChange={(event) => updateSceneDraft("narration", event.target.value)} placeholder="Hubungkan bagian naskah dengan adegan ini..." />
                  <label className="production-label" htmlFor="scene-visual">Deskripsi visual</label>
                  <textarea id="scene-visual" className="production-textarea" rows={4} value={sceneDraft.visual_description ?? ""} onChange={(event) => updateSceneDraft("visual_description", event.target.value)} placeholder="Apa yang terlihat? Catatan ini belum menjadi prompt Blender." />
                  <div className="scene-camera-row">
                    <div className="scene-camera-field"><label className="production-label" htmlFor="scene-camera">Arahan kamera</label><input id="scene-camera" className="production-input" value={sceneDraft.camera_direction ?? ""} onChange={(event) => updateSceneDraft("camera_direction", event.target.value)} placeholder="Contoh: slow push-in" /></div>
                    <div className="scene-duration-field"><label className="production-label" htmlFor="scene-duration">Durasi (dtk)</label><input id="scene-duration" className="production-input" type="number" min={1} max={60} value={sceneDraft.duration_seconds} onChange={(event) => updateSceneDraft("duration_seconds", Number(event.target.value))} /></div>
                  </div>
                  <div className="production-save-row">
                    <span>{sceneDirty ? "Perubahan belum disimpan" : "Status: draft"}</span>
                    <button className="button-primary" onClick={saveScene} disabled={sceneBusy || !sceneDirty || !sceneDraft.title.trim()}>
                      {sceneBusy ? <LoaderCircle size={14} className="spin-icon" /> : <Save size={14} />}
                      {sceneBusy ? "Menyimpan..." : "Simpan adegan"}
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
          <div className="storyboard-footnote"><ArrowRight size={13} /> Preview visual, prompt Blender terstruktur, dan persetujuan adegan akan ditambahkan pada tahap berikutnya.</div>
        </section>
      ) : activeTab === "research" ? (
        <section className="research-panel">
          <div className="production-panel-heading">
            <div><span className="section-kicker">RESEARCH SOURCES</span><h2>Riset &amp; verifikasi</h2></div>
            <span className="production-version">{research.verified_count} terverifikasi · {research.independent_domain_count} domain</span>
          </div>

          <div className={research.verified ? "research-status verified" : "research-status"}>
            {research.verified ? <ShieldCheck size={14} /> : <Clock3 size={14} />}
            {research.verified
              ? "Riset terverifikasi: minimal 2 sumber dari 2 domain independen telah ditandai."
              : `Belum terverifikasi — butuh minimal 2 sumber terverifikasi dari 2 domain berbeda (${research.verified_count}/2 sumber · ${research.independent_domain_count}/2 domain).`}
          </div>

          <div className="research-search">
            <label className="production-label" htmlFor="research-query">Cari sumber di mesin pencari</label>
            <div className="research-search-row">
              <input
                id="research-query"
                className="production-input"
                maxLength={300}
                value={searchQuery}
                onChange={(event) => setSearchQuery(event.target.value)}
                placeholder="Contoh: laporan resmi suhu ekstrem kota 2024"
              />
              <button
                className="button-outline ai-draft-button"
                onClick={() => void searchSourcesWithAi()}
                disabled={researchSearchBusy || researchBusy || !searchQuery.trim()}
              >
                {researchSearchBusy ? <LoaderCircle size={13} className="spin-icon" /> : <Search size={13} />}
                {researchSearchBusy ? "Mencari..." : "Cari sumber"}
              </button>
            </div>
            <small className="research-search-hint">
              Hasil berasal dari mesin pencari sungguhan; AI hanya memilih dan meringkas. Verifikasi setiap sumber sebelum dipakai.
            </small>
            {sourceCandidates.length > 0 && (
              <ul className="research-candidates">
                {sourceCandidates.map((candidate) => (
                  <li key={candidate.url}>
                    <div className="research-candidate-main">
                      <strong>{candidate.title}</strong>
                      <a className="research-link" href={candidate.url} target="_blank" rel="noreferrer">{sourceDomain(candidate.url)}</a>
                      {(candidate.publisher || candidate.published_at) && (
                        <small>{[candidate.publisher, candidate.published_at].filter(Boolean).join(" · ")}</small>
                      )}
                      {candidate.reason && <p className="research-note">{candidate.reason}</p>}
                      {candidate.key_findings && <p>{candidate.key_findings}</p>}
                    </div>
                    <button className="button-outline" onClick={() => applyCandidate(candidate)} disabled={researchBusy}>
                      <Plus size={13} /> Pakai
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="research-form">
            <div className="research-form-grid">
              <div>
                <label className="production-label" htmlFor="source-title">Judul sumber</label>
                <input id="source-title" className="production-input" maxLength={200} value={sourceDraft.title} onChange={(event) => setSourceDraft((current) => ({ ...current, title: event.target.value }))} placeholder="Contoh: Laporan resmi BMKG 2024" />
              </div>
              <div>
                <label className="production-label" htmlFor="source-url">URL sumber</label>
                <input id="source-url" className="production-input" value={sourceDraft.url} onChange={(event) => setSourceDraft((current) => ({ ...current, url: event.target.value }))} placeholder="https://..." />
              </div>
              <div>
                <label className="production-label" htmlFor="source-publisher">Penerbit</label>
                <input id="source-publisher" className="production-input" maxLength={120} value={sourceDraft.publisher} onChange={(event) => setSourceDraft((current) => ({ ...current, publisher: event.target.value }))} placeholder="Institusi atau media" />
              </div>
              <div>
                <label className="production-label" htmlFor="source-date">Tanggal terbit</label>
                <input id="source-date" className="production-input" type="date" value={sourceDraft.published_at} onChange={(event) => setSourceDraft((current) => ({ ...current, published_at: event.target.value }))} />
              </div>
            </div>
            <label className="production-label" htmlFor="source-findings">Temuan kunci</label>
            <textarea id="source-findings" className="production-textarea" rows={3} value={sourceDraft.key_findings} onChange={(event) => setSourceDraft((current) => ({ ...current, key_findings: event.target.value }))} placeholder="Fakta atau angka yang akan dipakai di naskah..." />
            <label className="production-label" htmlFor="source-note">Catatan reliabilitas</label>
            <input id="source-note" className="production-input" value={sourceDraft.reliability_note} onChange={(event) => setSourceDraft((current) => ({ ...current, reliability_note: event.target.value }))} placeholder="Primer, sekunder, atau perlu kehati-hatian" />
            {inferredFields.length > 0 && (
              <div className="research-inferred">
                <Clock3 size={13} />
                Field berikut diisi AI dan perlu diverifikasi: {inferredFields.map((field) => sourceFieldLabels[field] ?? field).join(", ")}.
              </div>
            )}
            <div className="production-save-row">
              <span>{researchAiBusy ? "AI sedang mengisi draf sumber ini dari isi halaman." : "Sumber baru tersimpan sebagai belum terverifikasi."}</span>
              <div className="prompt-actions">
                <button className="button-outline ai-draft-button" onClick={() => void generateSourceWithAi()} disabled={researchAiBusy || researchBusy || !sourceDraft.url.trim()}>
                  {researchAiBusy ? <LoaderCircle size={13} className="spin-icon" /> : <Sparkles size={13} />}
                  {researchAiBusy ? "Draf AI diproses..." : "Isi otomatis dengan AI"}
                </button>
                <button className="button-primary" onClick={addSource} disabled={researchBusy || !sourceDraft.title.trim() || !sourceDraft.url.trim()}>
                  {researchBusy ? <LoaderCircle size={14} className="spin-icon" /> : <Plus size={14} />}
                  {researchBusy ? "Menyimpan..." : "Tambah sumber"}
                </button>
              </div>
            </div>
          </div>

          {research.sources.length === 0 ? (
            <div className="research-empty">Belum ada sumber. Tambahkan minimal dua sumber independen, lalu tandai terverifikasi setelah Anda memeriksa isinya.</div>
          ) : (
            <ul className="research-list">
              {research.sources.map((source) => (
                <li className={source.verified ? "research-item verified" : "research-item"} key={source.id}>
                  <div className="research-item-main">
                    <div className="research-item-title">
                      <strong>{source.title}</strong>
                      <span className={source.verified ? "research-badge verified" : "research-badge"}>{source.verified ? "Terverifikasi" : "Belum diverifikasi"}</span>
                    </div>
                    <a className="research-link" href={source.url} target="_blank" rel="noreferrer">{sourceDomain(source.url)}</a>
                    {(source.publisher || source.published_at) && (
                      <small>{[source.publisher, source.published_at].filter(Boolean).join(" · ")}</small>
                    )}
                    {source.key_findings && <p>{source.key_findings}</p>}
                    {source.reliability_note && <p className="research-note">{source.reliability_note}</p>}
                  </div>
                  <div className="research-item-actions">
                    <button className="button-outline" onClick={() => void toggleSourceVerified(source)} disabled={researchBusy}>
                      {source.verified ? <RotateCcw size={13} /> : <ShieldCheck size={13} />}
                      {source.verified ? "Batalkan verifikasi" : "Tandai terverifikasi"}
                    </button>
                    <button className="icon-button" aria-label={`Hapus sumber ${source.title}`} disabled={researchBusy} onClick={() => void deleteSource(source)}><Trash2 size={15} /></button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>
      ) : (
        <section className="prompt-panel">
          <div className="production-panel-heading">
            <div><span className="section-kicker">PROMPT BUILDER</span><h2>Prompt terstruktur</h2></div>
            <div className="prompt-actions">
              <span className="production-version">{prompts.length} prompt · {scenes.length} adegan</span>
              <button
                className="button-outline ai-draft-button"
                onClick={() => void generateAllPromptsWithAi()}
                disabled={promptAllAiBusy || promptAiBusy || promptBusy || scenes.length === 0}
              >
                {promptAllAiBusy ? <LoaderCircle size={13} className="spin-icon" /> : <Sparkles size={13} />}
                {promptAllAiBusy ? "Menyusun semua adegan..." : "Isi semua adegan"}
              </button>
            </div>
          </div>

          {scenes.length === 0 ? (
            <div className="prompt-empty">Storyboard belum memiliki adegan. Tambahkan adegan terlebih dahulu di tab Storyboard, lalu susun prompt untuk setiap adegan.</div>
          ) : (
            <div className="prompt-layout">
              <aside className="prompt-scene-list">
                <div className="scene-list-heading"><span>ADEGAN</span></div>
                {scenes.map((scene, index) => {
                  const prompt = prompts.find((entry) => entry.scene_id === scene.id);
                  const state = prompt?.status === "approved" ? "approved" : prompt ? "draft" : "empty";
                  return (
                    <button
                      key={scene.id}
                      className={`prompt-scene-row ${scene.id === promptSceneId ? "selected" : ""}`}
                      onClick={() => selectPromptScene(scene.id)}
                    >
                      <span>{String(index + 1).padStart(2, "0")}</span>
                      <strong>{scene.title}</strong>
                      <small className={`prompt-tag ${state}`}>
                        {prompt ? (prompt.status === "approved" ? "Disetujui" : `Draf v${prompt.version}`) : "Belum ada"}
                      </small>
                    </button>
                  );
                })}
              </aside>

              <div className="prompt-editor-panel">
                {promptScene === null ? (
                  <div className="scene-editor-empty"><div><Sparkles size={20} /></div><h3>Pilih adegan untuk menyusun prompt.</h3><p>Setiap adegan memiliki satu prompt terstruktur dengan riwayat revisi tersimpan.</p></div>
                ) : (
                  <>
                    <div className="scene-editor-heading">
                      <div>
                        <span className="section-kicker">PROMPT · {promptScene.title}</span>
                        <span className="scene-draft-pill">
                          {selectedPrompt ? `Versi ${selectedPrompt.version} · ${selectedPrompt.revision_count} revisi` : "Prompt baru"}
                        </span>
                      </div>
                      {selectedPrompt && (
                        <button className="icon-button" aria-label="Hapus prompt" disabled={promptBusy} onClick={() => void deletePrompt()}><Trash2 size={15} /></button>
                      )}
                    </div>

                    <label className="production-label" htmlFor="prompt-title">Judul prompt</label>
                    <input id="prompt-title" className="production-input" maxLength={120} value={promptDraft.title} onChange={(event) => setPromptDraft((current) => ({ ...current, title: event.target.value }))} placeholder="Contoh: Wide establishing — kota di malam hari" />

                    <label className="production-label" htmlFor="prompt-positive">Positive prompt <span className="prompt-required">wajib</span></label>
                    <textarea id="prompt-positive" className="production-textarea" rows={4} value={promptDraft.positive} onChange={(event) => setPromptDraft((current) => ({ ...current, positive: event.target.value }))} placeholder="Deskripsi utama subjek, aksi, komposisi, dan gaya..." />

                    <label className="production-label" htmlFor="prompt-negative">Negative prompt</label>
                    <textarea id="prompt-negative" className="production-textarea" rows={2} value={promptDraft.negative} onChange={(event) => setPromptDraft((current) => ({ ...current, negative: event.target.value }))} placeholder="Elemen yang harus dihindari, mis. blur, teks, distorsi..." />

                    <div className="prompt-field-grid">
                      <div>
                        <label className="production-label" htmlFor="prompt-style">Gaya</label>
                        <input id="prompt-style" className="production-input" maxLength={160} value={promptDraft.style} onChange={(event) => setPromptDraft((current) => ({ ...current, style: event.target.value }))} placeholder="Cinematic, dokumenter..." />
                      </div>
                      <div>
                        <label className="production-label" htmlFor="prompt-camera">Kamera</label>
                        <input id="prompt-camera" className="production-input" maxLength={160} value={promptDraft.camera} onChange={(event) => setPromptDraft((current) => ({ ...current, camera: event.target.value }))} placeholder="35mm, low angle..." />
                      </div>
                      <div>
                        <label className="production-label" htmlFor="prompt-lighting">Pencahayaan</label>
                        <input id="prompt-lighting" className="production-input" maxLength={160} value={promptDraft.lighting} onChange={(event) => setPromptDraft((current) => ({ ...current, lighting: event.target.value }))} placeholder="Golden hour, soft key..." />
                      </div>
                      <div>
                        <label className="production-label" htmlFor="prompt-mood">Mood</label>
                        <input id="prompt-mood" className="production-input" maxLength={120} value={promptDraft.mood} onChange={(event) => setPromptDraft((current) => ({ ...current, mood: event.target.value }))} placeholder="Tegang, hangat..." />
                      </div>
                    </div>

                    <label className="production-label" htmlFor="prompt-notes">Catatan internal</label>
                    <textarea id="prompt-notes" className="production-textarea" rows={2} value={promptDraft.notes} onChange={(event) => setPromptDraft((current) => ({ ...current, notes: event.target.value }))} placeholder="Catatan untuk operator atau render berikutnya..." />

                    <div className="production-save-row">
                      <span>
                        {promptDirty
                          ? "Perubahan belum disimpan"
                          : selectedPrompt
                            ? `Tersimpan · ${selectedPrompt.status === "approved" ? "disetujui" : "draf"}`
                            : "Prompt belum dibuat"}
                      </span>
                      <div className="prompt-actions">
                        <button className="button-outline ai-draft-button" onClick={() => void generatePromptWithAi()} disabled={promptAiBusy || promptAllAiBusy || promptBusy}>
                          {promptAiBusy ? <LoaderCircle size={13} className="spin-icon" /> : <Sparkles size={13} />}
                          {promptAiBusy ? "Draf AI diproses..." : "Isi dengan AI"}
                        </button>
                        {selectedPrompt && (
                          <>
                            <button className="button-outline" onClick={() => void togglePromptApproved()} disabled={promptBusy}>
                              {selectedPrompt.status === "approved" ? <RotateCcw size={13} /> : <ShieldCheck size={13} />}
                              {selectedPrompt.status === "approved" ? "Batalkan" : "Setujui"}
                            </button>
                            <button className="button-outline" onClick={() => void toggleRevisions()} disabled={revisionsBusy}>
                              {revisionsBusy ? <LoaderCircle size={13} className="spin-icon" /> : <History size={13} />}
                              Riwayat
                            </button>
                          </>
                        )}
                        <button className="button-primary" onClick={() => void savePrompt()} disabled={promptBusy || !promptDraft.positive.trim() || (selectedPrompt !== null && !promptDirty)}>
                          {promptBusy ? <LoaderCircle size={14} className="spin-icon" /> : <Save size={14} />}
                          {promptBusy ? "Menyimpan..." : selectedPrompt ? "Simpan perubahan" : "Buat prompt"}
                        </button>
                      </div>
                    </div>

                    {revisionsOpen && (
                      <div className="prompt-revisions">
                        <span className="section-kicker">RIWAYAT REVISI</span>
                        {promptRevisions.length === 0 ? (
                          <p className="prompt-revision-empty">Belum ada revisi tersimpan.</p>
                        ) : (
                          <ol className="prompt-revision-list">
                            {promptRevisions.map((revision) => (
                              <li key={revision.version}>
                                <div className="prompt-revision-heading">
                                  <strong>Versi {revision.version}</strong>
                                  <small>{formatTimestamp(revision.created_at)}</small>
                                </div>
                                <p>{revision.snapshot.positive || "—"}</p>
                              </li>
                            ))}
                          </ol>
                        )}
                      </div>
                    )}
                  </>
                )}
              </div>
            </div>
          )}
          <div className="storyboard-footnote"><ArrowRight size={13} /> Prompt ini menjadi sumber untuk mesin workflow dan render Blender pada tahap berikutnya.</div>
        </section>
      )}
    </div>
  );
}
