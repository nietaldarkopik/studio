"use client";

import { Check, ExternalLink, LoaderCircle, RefreshCw, X } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

export type HubSection =
  | "research" | "scenes" | "renders" | "audio" | "qc" | "publishing" | "media" | "agents" | "analytics";

export const hubNav: Record<string, { section: HubSection; eyebrow: string; title: string; blurb: string }> = {
  "AI Research": { section: "research", eyebrow: "CREATE", title: "AI Research", blurb: "Seluruh sumber riset dari semua proyek, dengan status verifikasi dan situs asalnya." },
  "Scene Studio": { section: "scenes", eyebrow: "CREATE", title: "Scene Studio", blurb: "Adegan storyboard setiap proyek beserta prompt visualnya." },
  "Blender Render": { section: "renders", eyebrow: "CREATE", title: "Blender Render", blurb: "Status render Blender tiap produksi, jumlah frame, dan pratinjau adegan." },
  "Media Library": { section: "media", eyebrow: "CREATE", title: "Media Library", blurb: "Video, narasi, contact sheet, dan pratinjau hasil produksi." },
  "Voice & Audio": { section: "audio", eyebrow: "CREATE", title: "Voice & Audio", blurb: "Narasi TTS per adegan dari ClipWize; putar langsung di sini." },
  "Quality Control": { section: "qc", eyebrow: "DELIVER", title: "Quality Control", blurb: "Hasil QC otomatis dan contact sheet sebelum video boleh diunggah." },
  Publishing: { section: "publishing", eyebrow: "DELIVER", title: "Publishing", blurb: "Status upload YouTube Shorts dan tautan video yang sudah terbit." },
  "AI Agents": { section: "agents", eyebrow: "OPERATE", title: "AI Agents", blurb: "Peran otomatis dalam pipeline dan riwayat aktivitas AI." },
  Analytics: { section: "analytics", eyebrow: "OPERATE", title: "Analytics", blurb: "Ringkasan produksi lokal dan metrik kanal dari ClipWize (30 hari)." },
};

type Json = Record<string, unknown>;
type Row = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

const fileUrl = (workspaceId: number, runId: number, kind: string, n?: number | null) =>
  `/api/studio/workspaces/${workspaceId}/production-runs/${runId}/files/${kind}${n ? `/${n}` : ""}`;

function Pill({ ok, children }: { ok: boolean; children: React.ReactNode }) {
  return <span className={`workflow-badge ${ok ? "is-completed" : "is-failed"}`}>{children}</span>;
}

function Empty({ text }: { text: string }) {
  return <div className="workflow-empty">{text}</div>;
}

export default function StudioHub({ workspaceId, label }: { workspaceId: number; label: string }) {
  const meta = hubNav[label];
  const [data, setData] = useState<Json | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [tick, setTick] = useState(0);

  const refresh = useCallback(() => setTick((value) => value + 1), []);

  useEffect(() => {
    let active = true;
    async function load() {
      try {
        const response = await fetch(`/api/studio/workspaces/${workspaceId}/hub/${meta.section}`, { cache: "no-store" });
        const result = await response.json();
        if (!active) return;
        if (!response.ok) throw new Error(result.message || "Data gagal dimuat.");
        setData(result as Json);
        setError("");
      } catch (loadError) {
        if (active) setError(loadError instanceof Error ? loadError.message : "Data gagal dimuat.");
      } finally {
        if (active) setLoading(false);
      }
    }
    void load();
    return () => {
      active = false;
    };
  }, [workspaceId, meta.section, tick]);

  return (
    <div className="module-page">
      <div className="page-heading">
        <div>
          <div className="eyebrow">{meta.eyebrow} <span className="eyebrow-separator">/</span> {meta.title.toUpperCase()}</div>
          <h1>{meta.title}<span className="heading-period">.</span></h1>
          <p>{meta.blurb}</p>
        </div>
        <button className="button-outline" onClick={refresh}><RefreshCw size={13} /> Muat ulang</button>
      </div>
      {error && <div className="production-error" role="alert">{error}</div>}
      {loading ? (
        <div className="production-loading"><LoaderCircle size={17} className="spin-icon" /> Memuat...</div>
      ) : data && (
        <section className="section-block hub-body">
          <Body section={meta.section} data={data} workspaceId={workspaceId} />
        </section>
      )}
    </div>
  );
}

function Body({ section, data, workspaceId }: { section: HubSection; data: Json; workspaceId: number }) {
  const list = (key: string) => (data[key] as Row[] | undefined) ?? [];

  if (section === "research") {
    const sources = list("sources");
    const totals = data.totals as Row;
    if (!sources.length) return <Empty text="Belum ada sumber riset. Jalankan Auto Production atau tambahkan sumber di proyek." />;
    return (
      <>
        <p className="hub-summary">{totals.sources} sumber · {totals.verified} terverifikasi</p>
        <ul className="hub-list">
          {sources.map((s) => (
            <li key={s.id}>
              <div><strong>{s.title}</strong><small>{s.project} · {s.host}</small>{s.key_findings && <p>{s.key_findings}</p>}</div>
              <Pill ok={s.verified}>{s.verified ? "Terverifikasi" : "Belum"}</Pill>
              <a href={s.url} target="_blank" rel="noreferrer noopener" aria-label="Buka sumber"><ExternalLink size={13} /></a>
            </li>
          ))}
        </ul>
      </>
    );
  }

  if (section === "scenes") {
    const projects = list("projects");
    if (!projects.length) return <Empty text="Belum ada adegan. Buat storyboard di proyek atau jalankan Auto Production." />;
    return (
      <>
        {projects.map((p) => (
          <div key={p.id} className="hub-group">
            <h3>{p.title}</h3>
            <ul className="hub-list">
              {(p.scenes as Row[]).map((s) => (
                <li key={s.id}>
                  <div>
                    <strong>{s.position}. {s.title} <small>{s.duration_seconds} dtk</small></strong>
                    {s.narration && <p>{s.narration}</p>}
                    <small>{s.prompt ? `Prompt: ${s.prompt}` : "Belum ada prompt"}</small>
                  </div>
                  <Pill ok={Boolean(s.prompt)}>{s.prompt ? "Prompt siap" : "Tanpa prompt"}</Pill>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </>
    );
  }

  if (section === "renders") {
    const renders = list("renders");
    if (!renders.length) return <Empty text="Belum ada render. Mulai produksi di menu Auto Production." />;
    return (
      <>
        {renders.map((r) => (
          <div key={r.run_id} className="hub-group">
            <h3>{r.title} <Pill ok={r.status === "completed"}>{r.status}</Pill></h3>
            <p className="hub-summary">{r.total_frames} frame · {r.duration} dtk{r.message ? ` · ${r.message}` : ""}</p>
            <div className="hub-grid">
              {(r.scenes as Row[]).map((s) => (
                <figure key={s.position}>
                  {s.preview
                    // eslint-disable-next-line @next/next/no-img-element
                    ? <img src={fileUrl(workspaceId, r.run_id, "frame", s.position)} alt={`Adegan ${s.position}`} loading="lazy" />
                    : <div className="hub-noimg">Belum dirender</div>}
                  <figcaption>Adegan {s.position} · {s.frames} frame</figcaption>
                </figure>
              ))}
            </div>
          </div>
        ))}
      </>
    );
  }

  if (section === "audio") {
    const tracks = list("tracks");
    if (!tracks.length) return <Empty text="Belum ada narasi. Narasi dibuat pada tahap TTS di Auto Production." />;
    return (
      <ul className="hub-list">
        {tracks.map((t) => (
          <li key={`${t.run_id}-${t.position}`}>
            <div>
              <strong>{t.project} · adegan {t.position}{t.scene ? ` (${t.scene})` : ""} <small>{t.duration} dtk</small></strong>
              <p>{t.text}</p>
              {t.available && <audio controls preload="none" src={fileUrl(workspaceId, t.run_id, "audio", t.position)} />}
            </div>
          </li>
        ))}
      </ul>
    );
  }

  if (section === "qc") {
    const reports = list("reports");
    if (!reports.length) return <Empty text="Belum ada laporan QC. QC berjalan otomatis sebelum upload." />;
    return (
      <>
        {reports.map((r) => (
          <div key={r.run_id} className="hub-group">
            <h3>{r.title} <Pill ok={r.passed}>{r.passed ? "Lolos" : "Gagal"}</Pill></h3>
            <ul className="autoprod-qc-list">
              {(r.checks as Row[]).map((c) => (
                <li key={c.name} className={c.ok ? "ok" : "bad"}>{c.ok ? <Check size={12} /> : <X size={12} />} {c.name} <small>{c.detail}</small></li>
              ))}
            </ul>
            {r.contact_sheet && (
              // eslint-disable-next-line @next/next/no-img-element
              <img className="hub-contact" src={fileUrl(workspaceId, r.run_id, "contact")} alt="Contact sheet" loading="lazy" />
            )}
          </div>
        ))}
      </>
    );
  }

  if (section === "publishing") {
    const posts = list("posts");
    if (!posts.length) return <Empty text="Belum ada produksi. Video terbit akan tampil di sini." />;
    return (
      <ul className="hub-list">
        {posts.map((p) => (
          <li key={p.run_id}>
            <div>
              <strong>{p.title}</strong>
              <small>Run #{p.run_id} · tahap publish: {p.publish_stage}{p.message ? ` — ${p.message}` : ""}</small>
            </div>
            <Pill ok={Boolean(p.post_url)}>{p.post_url ? "Terbit" : p.can_approve ? "Menunggu persetujuan" : p.status}</Pill>
            {p.post_url && <a href={p.post_url} target="_blank" rel="noreferrer noopener" aria-label="Buka di YouTube"><ExternalLink size={13} /></a>}
          </li>
        ))}
      </ul>
    );
  }

  if (section === "media") {
    const items = list("items");
    if (!items.length) return <Empty text="Belum ada media. Hasil produksi akan tampil di sini." />;
    return (
      <div className="hub-grid">
        {items.map((m) => {
          const src = fileUrl(workspaceId, m.run_id, m.kind, m.n);
          return (
            <figure key={`${m.run_id}-${m.kind}-${m.n}`}>
              {m.type === "video" && <video controls preload="none" src={src} />}
              {m.type === "audio" && <audio controls preload="none" src={src} />}
              {m.type === "image" && (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={src} alt={m.label} loading="lazy" />
              )}
              <figcaption>{m.label} · {m.project}</figcaption>
            </figure>
          );
        })}
      </div>
    );
  }

  if (section === "agents") {
    const agents = list("agents");
    const activity = list("activity");
    return (
      <>
        <ul className="hub-list">
          {agents.map((a) => (
            <li key={a.name}><div><strong>{a.name}</strong><small>{a.role}</small></div><span className="workflow-badge">{a.runs}× dijalankan</span></li>
          ))}
        </ul>
        <h3 className="hub-sub">Aktivitas AI terbaru</h3>
        {activity.length === 0 ? <Empty text="Belum ada aktivitas." /> : (
          <ul className="hub-list">
            {activity.map((a, i) => (
              <li key={i}>
                <div><strong>{a.agent}</strong><small>{a.model ?? "—"}{a.at ? ` · ${new Date(a.at).toLocaleString("id-ID")}` : ""}</small>{a.error && <p>{a.error}</p>}</div>
                <Pill ok={a.status === "completed"}>{a.status}</Pill>
              </li>
            ))}
          </ul>
        )}
      </>
    );
  }

  // analytics
  const local = data.local as Row;
  const cw = data.clipwize as Row | null;
  const totals = (cw?.totals ?? null) as Row | null;
  return (
    <>
      <div className="hub-stats">
        {[["Produksi", local.runs], ["Selesai", local.completed], ["Terbit", local.published], ["Perlu ditinjau", local.needs_review], ["Gagal", local.failed]].map(([k, v]) => (
          <div key={String(k)}><strong>{String(v)}</strong><small>{String(k)}</small></div>
        ))}
      </div>
      <h3 className="hub-sub">Kanal (ClipWize, 30 hari)</h3>
      {totals ? (
        <div className="hub-stats">
          {Object.entries(totals).map(([k, v]) => <div key={k}><strong>{String(v)}</strong><small>{k.replace(/_/g, " ")}</small></div>)}
        </div>
      ) : <Empty text={String(data.note ?? "Analitik ClipWize belum tersedia.")} />}
    </>
  );
}
