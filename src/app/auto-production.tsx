"use client";

import {
  AlertTriangle,
  Ban,
  Check,
  CircleDot,
  ExternalLink,
  LoaderCircle,
  Play,
  RotateCcw,
  ShieldCheck,
  Sparkles,
  ThumbsUp,
  Upload,
  X,
} from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";

type RunStatus = "queued" | "running" | "awaiting_approval" | "needs_review" | "failed" | "completed" | "cancelled";
type StageStatus = "pending" | "running" | "completed" | "failed" | "needs_review" | "awaiting_approval" | "skipped";

type StageRecord = { key: string; label: string; status: StageStatus; message?: string | null };
type QcCheck = { name: string; ok: boolean; detail: string };

type RunRecord = {
  id: number;
  status: RunStatus;
  stage: string | null;
  error_message: string | null;
  options: { theme?: string; format?: string; auto_publish?: boolean };
  project: { id: number; title: string } | null;
  theme: string | null;
  title: string | null;
  stages: StageRecord[];
  qc: { passed: boolean; checks: QcCheck[] } | null;
  post_url: string | null;
  started_at: string | null;
  updated_at: string | null;
};

const statusLabel: Record<RunStatus, string> = {
  queued: "Dalam antrean",
  running: "Berjalan",
  awaiting_approval: "Menunggu persetujuan upload",
  needs_review: "Perlu ditinjau",
  failed: "Gagal",
  completed: "Selesai",
  cancelled: "Dibatalkan",
};

const formatOptions = [
  { value: "", label: "Otomatis (bergilir A → B → C)" },
  { value: "chain_build", label: "A · Rantai Bangun" },
  { value: "mystery_investigation", label: "B · Misteri & Investigasi" },
  { value: "flag_banter", label: "C · Debat Bendera" },
];

const activeStatuses: RunStatus[] = ["queued", "running"];

export default function AutoProduction({
  workspaceId,
  onToast,
}: {
  workspaceId: number;
  onToast: (message: string) => void;
}) {
  const baseUrl = `/api/studio/workspaces/${workspaceId}/production-runs`;
  const [canEdit, setCanEdit] = useState(false);
  const [runs, setRuns] = useState<RunRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [form, setForm] = useState({ theme: "", format: "", autoPublish: false, requireResearch: false });
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const current = runs[0] ?? null;
  const isActive = current !== null && activeStatuses.includes(current.status);

  const load = useCallback(async () => {
    try {
      const response = await fetch(baseUrl, { cache: "no-store" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Riwayat produksi gagal dimuat.");
      setRuns(result.runs as RunRecord[]);
      setError("");
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Riwayat produksi gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, [baseUrl]);

  useEffect(() => {
    let active = true;

    async function init() {
      try {
        const [runsResponse, workspaceResponse] = await Promise.all([
          fetch(baseUrl, { cache: "no-store" }),
          fetch(`/api/studio/workspaces/${workspaceId}`, { cache: "no-store" }),
        ]);
        const runsResult = await runsResponse.json();
        const workspaceResult = await workspaceResponse.json();
        if (!active) return;
        if (!runsResponse.ok) throw new Error(runsResult.message || "Riwayat produksi gagal dimuat.");
        setRuns(runsResult.runs as RunRecord[]);
        setCanEdit(workspaceResult.workspace?.role !== "viewer");
      } catch (loadError) {
        if (active) setError(loadError instanceof Error ? loadError.message : "Riwayat produksi gagal dimuat.");
      } finally {
        if (active) setLoading(false);
      }
    }

    void init();
    return () => {
      active = false;
    };
  }, [baseUrl, workspaceId]);

  // Polling selama ada produksi yang berjalan.
  useEffect(() => {
    if (!isActive) return;
    timer.current = setTimeout(() => void load(), 4000);
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
  }, [isActive, runs, load]);

  async function call(url: string, init: RequestInit, successMessage: string) {
    setBusy(true);
    setError("");
    try {
      const response = await fetch(url, init);
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Permintaan gagal.");
      onToast(successMessage);
      await load();
    } catch (callError) {
      setError(callError instanceof Error ? callError.message : "Permintaan gagal.");
    } finally {
      setBusy(false);
    }
  }

  function start() {
    if (form.autoPublish && !window.confirm(
      "Video akan DIUNGGAH PUBLIK ke YouTube secara otomatis bila QC lolos, dan tidak dapat dibatalkan dari sini. Lanjutkan?",
    )) {
      return;
    }

    void call(baseUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        theme: form.theme.trim() || null,
        format: form.format || null,
        auto_publish: form.autoPublish,
        require_research: form.requireResearch,
      }),
    }, "Produksi otomatis dimulai.");
  }

  function approve(run: RunRecord) {
    if (!window.confirm("Unggah video ini ke YouTube sekarang? Tindakan ini publik dan tidak dapat dibatalkan dari sini.")) return;
    void call(`${baseUrl}/${run.id}/approve-publish`, { method: "POST" }, "Upload disetujui dan dikirim ke antrean.");
  }

  if (loading) {
    return <div className="production-loading"><LoaderCircle size={17} className="spin-icon" /> Memuat produksi otomatis...</div>;
  }

  return (
    <div className="module-page autoprod-page">
      <div className="page-heading">
        <div>
          <div className="eyebrow">OPERATE <span className="eyebrow-separator">/</span> AUTO PRODUCTION</div>
          <h1>Auto production<span className="heading-period">.</span></h1>
          <p>Satu klik dari tema dan judul, riset, naskah, render Blender, narasi, QC, sampai upload YouTube Shorts.</p>
        </div>
        <span className="settings-role-pill"><ShieldCheck size={13} /> {canEdit ? "Dapat menjalankan" : "Hanya lihat"}</span>
      </div>

      {error && <div className="production-error" role="alert">{error}</div>}

      <section className="section-block autoprod-launch">
        <div className="autoprod-form">
          <label className="field-label" htmlFor="ap-theme">Arahan tema (opsional)</label>
          <input id="ap-theme" className="production-input" maxLength={200} placeholder="Kosongkan agar AI memilih tema unik sendiri" value={form.theme} onChange={(event) => setForm((c) => ({ ...c, theme: event.target.value }))} disabled={!canEdit || isActive} />
          <label className="field-label" htmlFor="ap-format">Format naskah</label>
          <select id="ap-format" className="production-select" value={form.format} onChange={(event) => setForm((c) => ({ ...c, format: event.target.value }))} disabled={!canEdit || isActive}>
            {formatOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
          <label className="autoprod-check autoprod-check-plain">
            <input type="checkbox" checked={form.requireResearch} onChange={(event) => setForm((c) => ({ ...c, requireResearch: event.target.checked }))} disabled={!canEdit || isActive} />
            <span>
              <strong>Wajib riset &amp; verifikasi sumber</strong>
              <small>Mati (default): AI tetap mencoba riset, tapi produksi lanjut walau sumber kurang. Aktif: berhenti bila belum ada ≥2 sumber independen yang lolos fact-check.</small>
            </span>
          </label>
          <label className="autoprod-check">
            <input type="checkbox" checked={form.autoPublish} onChange={(event) => setForm((c) => ({ ...c, autoPublish: event.target.checked }))} disabled={!canEdit || isActive} />
            <span>
              <strong>Upload YouTube otomatis</strong>
              <small>Bila QC otomatis lolos, video langsung publik. Tanpa ini, produksi berhenti dan menunggu persetujuan Anda.</small>
            </span>
          </label>
          <button className="button-primary" onClick={start} disabled={!canEdit || isActive || busy}>
            {busy ? <LoaderCircle size={14} className="spin-icon" /> : <Play size={14} />} Mulai produksi otomatis
          </button>
          <p className="autoprod-note">Render Blender berjalan di PC ini dan dapat memakan puluhan menit. Pastikan <code>php artisan queue:work --timeout=0</code> aktif.</p>
        </div>

        <div className="autoprod-current">
          {current === null ? (
            <div className="workflow-canvas-empty">
              <div><Sparkles size={19} /></div>
              <h3>Belum ada produksi</h3>
              <p>Mulai produksi pertama. Setiap tahap tersimpan sehingga dapat dilanjutkan bila terhenti.</p>
            </div>
          ) : (
            <RunCard run={current} busy={busy} canEdit={canEdit}
              onResume={() => void call(`${baseUrl}/${current.id}/resume`, { method: "POST" }, "Produksi dilanjutkan.")}
              onCancel={() => void call(`${baseUrl}/${current.id}/cancel`, { method: "POST" }, "Produksi dibatalkan.")}
              onApprove={() => approve(current)} />
          )}
        </div>
      </section>

      {runs.length > 1 && (
        <section className="section-block">
          <div className="section-kicker">RIWAYAT</div>
          <ul className="autoprod-history">
            {runs.slice(1).map((run) => (
              <li key={run.id}>
                <span className={`workflow-badge is-${run.status}`}>{statusLabel[run.status]}</span>
                <strong>{run.title ?? `Run #${run.id}`}</strong>
                {run.post_url && <a href={run.post_url} target="_blank" rel="noreferrer noopener"><ExternalLink size={12} /> Tonton</a>}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

function stageIcon(status: StageStatus) {
  if (status === "completed") return <Check size={13} />;
  if (status === "running") return <LoaderCircle size={13} className="spin-icon" />;
  if (status === "failed") return <X size={13} />;
  if (status === "needs_review" || status === "awaiting_approval") return <AlertTriangle size={13} />;
  return <CircleDot size={13} />;
}

function RunCard({
  run,
  busy,
  canEdit,
  onResume,
  onCancel,
  onApprove,
}: {
  run: RunRecord;
  busy: boolean;
  canEdit: boolean;
  onResume: () => void;
  onCancel: () => void;
  onApprove: () => void;
}) {
  const active = activeStatuses.includes(run.status);

  return (
    <div className="autoprod-run">
      <div className="autoprod-run-head">
        <div>
          <div className="section-kicker">PRODUKSI #{run.id}{run.theme ? ` · ${run.theme.toUpperCase()}` : ""}</div>
          <h2>{run.title ?? "Menentukan tema & judul..."}</h2>
        </div>
        <span className={`workflow-badge is-${run.status}`}>{statusLabel[run.status]}</span>
      </div>

      {run.error_message && <div className="production-error" role="alert">{run.error_message}</div>}

      <ol className="autoprod-stages">
        {run.stages.map((stage) => (
          <li key={stage.key} className={`is-${stage.status}`}>
            <span className="autoprod-stage-icon">{stageIcon(stage.status)}</span>
            <span className="autoprod-stage-copy">
              <strong>{stage.label}</strong>
              {stage.message && <small>{stage.message}</small>}
            </span>
          </li>
        ))}
      </ol>

      {run.qc && (
        <details className="autoprod-qc" open={!run.qc.passed}>
          <summary>QC otomatis: {run.qc.passed ? "lolos" : "ada yang gagal"}</summary>
          <ul>
            {run.qc.checks.map((check) => (
              <li key={check.name} className={check.ok ? "ok" : "bad"}>
                {check.ok ? <Check size={12} /> : <X size={12} />} {check.name} <small>{check.detail}</small>
              </li>
            ))}
          </ul>
        </details>
      )}

      <div className="autoprod-actions">
        {run.status === "awaiting_approval" && canEdit && (
          <button className="button-primary" onClick={onApprove} disabled={busy}><ThumbsUp size={13} /> <Upload size={13} /> Setujui &amp; upload ke YouTube</button>
        )}
        {(run.status === "failed" || run.status === "needs_review") && canEdit && (
          <button className="button-outline" onClick={onResume} disabled={busy}><RotateCcw size={13} /> Lanjutkan dari tahap ini</button>
        )}
        {run.status !== "completed" && run.status !== "cancelled" && canEdit && (
          <button className="button-outline" onClick={onCancel} disabled={busy && !active}><Ban size={13} /> Batalkan</button>
        )}
        {run.post_url && (
          <a className="button-outline" href={run.post_url} target="_blank" rel="noreferrer noopener"><ExternalLink size={13} /> Lihat di YouTube</a>
        )}
      </div>
    </div>
  );
}
