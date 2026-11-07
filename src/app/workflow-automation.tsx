"use client";

import {
  ArrowRight,
  Check,
  CircleDot,
  GitBranch,
  LoaderCircle,
  Pencil,
  Play,
  Plus,
  RotateCcw,
  ShieldCheck,
  ThumbsUp,
  Trash2,
  Workflow,
  X,
} from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type Role = "owner" | "editor" | "viewer";
type StepStatus = "pending" | "blocked" | "ready" | "running" | "completed" | "failed" | "skipped";
type StepAction = "start" | "approve" | "complete" | "fail" | "skip" | "reset";

type WorkflowStepRecord = {
  id: number;
  position: number;
  key: string;
  name: string;
  type: string;
  status: StepStatus;
  requires_approval: boolean;
  approved: boolean;
  depends_on: string[];
  config: Record<string, unknown> | null;
};

type WorkflowRecord = {
  id: number;
  name: string;
  status: string;
  trigger: string;
  step_count: number;
  completed_count: number;
  steps: WorkflowStepRecord[];
  updated_at: string | null;
};

const statusMeta: Record<StepStatus, { label: string; className: string }> = {
  pending: { label: "Menunggu", className: "is-pending" },
  blocked: { label: "Terblokir", className: "is-blocked" },
  ready: { label: "Siap", className: "is-ready" },
  running: { label: "Berjalan", className: "is-running" },
  completed: { label: "Selesai", className: "is-completed" },
  failed: { label: "Gagal", className: "is-failed" },
  skipped: { label: "Dilewati", className: "is-skipped" },
};

const actionMeta: Record<StepAction, { label: string; tone: "primary" | "outline" }> = {
  start: { label: "Mulai", tone: "primary" },
  approve: { label: "Setujui", tone: "outline" },
  complete: { label: "Selesai", tone: "primary" },
  fail: { label: "Gagal", tone: "outline" },
  skip: { label: "Lewati", tone: "outline" },
  reset: { label: "Reset", tone: "outline" },
};

const triggerOptions = [
  { value: "manual", label: "Manual" },
  { value: "schedule", label: "Terjadwal" },
  { value: "webhook", label: "Webhook" },
  { value: "event", label: "Event" },
];

const statusOptions = [
  { value: "draft", label: "Draft" },
  { value: "active", label: "Aktif" },
  { value: "archived", label: "Arsip" },
];

const templates: { value: string; label: string; steps: Array<Omit<WorkflowStepRecord, "id" | "position" | "type" | "status" | "approved" | "config" | "key"> & { key: string }> }[] = [
  { value: "blank", label: "Mulai kosong", steps: [] },
  {
    value: "standard",
    label: "Pipeline produksi standar",
    steps: [
      { key: "research", name: "Riset & sumber", requires_approval: false, depends_on: [] },
      { key: "script", name: "Naskah", requires_approval: false, depends_on: ["research"] },
      { key: "storyboard", name: "Storyboard", requires_approval: false, depends_on: ["script"] },
      { key: "prompt", name: "Prompt visual", requires_approval: false, depends_on: ["storyboard"] },
      { key: "render", name: "Render Blender", requires_approval: false, depends_on: ["prompt"] },
      { key: "qc", name: "Quality control", requires_approval: false, depends_on: ["render"] },
      { key: "publish", name: "Publikasi", requires_approval: true, depends_on: ["qc"] },
    ],
  },
];

function stepActions(step: WorkflowStepRecord): StepAction[] {
  const actions: StepAction[] = [];
  if (step.status === "ready") actions.push("start");
  if (step.status === "running") actions.push("complete", "fail");
  if (
    step.requires_approval
    && !step.approved
    && !["completed", "failed", "skipped"].includes(step.status)
  ) {
    actions.push("approve");
  }
  if (!["running", "completed"].includes(step.status)) actions.push("skip");
  if (["completed", "failed", "skipped"].includes(step.status)) actions.push("reset");
  return actions;
}

export default function WorkflowAutomation({
  project,
  workspaceId,
  onToast,
}: {
  project: { id: string; title: string };
  workspaceId: number;
  onToast: (message: string) => void;
}) {
  const baseUrl = `/api/studio/workspaces/${workspaceId}/projects/${project.id}`;
  const [role, setRole] = useState<Role>("viewer");
  const [workflows, setWorkflows] = useState<WorkflowRecord[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [draft, setDraft] = useState({ name: "", trigger: "manual", template: "standard" });
  const [stepDraft, setStepDraft] = useState({ key: "", name: "" });
  const [editingStep, setEditingStep] = useState<{ id: number; name: string } | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const canEdit = role !== "viewer";
  const selected = workflows.find((workflow) => workflow.id === selectedId) ?? workflows[0] ?? null;

  const replaceWorkflow = useCallback((updated: WorkflowRecord) => {
    setWorkflows((current) => current.map((workflow) => workflow.id === updated.id ? updated : workflow));
  }, []);

  const refreshWorkflow = useCallback(async (id: number) => {
    const response = await fetch(`${baseUrl}/workflows/${id}`, { cache: "no-store" });
    if (!response.ok) return;
    const result = await response.json();
    replaceWorkflow(result.workflow as WorkflowRecord);
  }, [baseUrl, replaceWorkflow]);

  useEffect(() => {
    let active = true;

    async function load() {
      setLoading(true);
      setError("");
      try {
        const [workspaceResponse, workflowsResponse] = await Promise.all([
          fetch(`/api/studio/workspaces/${workspaceId}`, { cache: "no-store" }),
          fetch(`${baseUrl}/workflows`, { cache: "no-store" }),
        ]);
        const workspaceResult = await workspaceResponse.json();
        if (!active) return;
        if (workspaceResponse.ok) setRole(workspaceResult.workspace.role as Role);

        const workflowsResult = await workflowsResponse.json();
        if (!workflowsResponse.ok) throw new Error(workflowsResult.message || "Workflow gagal dimuat.");
        if (!active) return;

        const loaded = workflowsResult.workflows as WorkflowRecord[];
        setWorkflows(loaded);
        setSelectedId((current) => (
          current && loaded.some((workflow) => workflow.id === current) ? current : loaded[0]?.id ?? null
        ));
      } catch (loadError) {
        if (active) setError(loadError instanceof Error ? loadError.message : "Workflow gagal dimuat.");
      } finally {
        if (active) setLoading(false);
      }
    }

    void load();
    return () => {
      active = false;
    };
  }, [baseUrl, workspaceId]);

  async function createWorkflow() {
    if (!draft.name.trim()) return;
    setBusy(true);
    setError("");
    try {
      const template = templates.find((item) => item.value === draft.template) ?? templates[0];
      const response = await fetch(`${baseUrl}/workflows`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: draft.name.trim(),
          trigger: draft.trigger,
          steps: template.steps,
        }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Workflow gagal dibuat.");
      const created = result.workflow as WorkflowRecord;
      setWorkflows((current) => [...current, created]);
      setSelectedId(created.id);
      setDraft((current) => ({ ...current, name: "" }));
      onToast("Workflow dibuat.");
    } catch (createError) {
      setError(createError instanceof Error ? createError.message : "Workflow gagal dibuat.");
    } finally {
      setBusy(false);
    }
  }

  async function updateWorkflow(patch: Partial<Pick<WorkflowRecord, "name" | "status" | "trigger">>) {
    if (!selected) return;
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/workflows/${selected.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(patch),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Workflow gagal disimpan.");
      replaceWorkflow(result.workflow as WorkflowRecord);
    } catch (updateError) {
      setError(updateError instanceof Error ? updateError.message : "Workflow gagal disimpan.");
    } finally {
      setBusy(false);
    }
  }

  async function deleteWorkflow() {
    if (!selected || !window.confirm(`Hapus workflow "${selected.name}" beserta langkahnya?`)) return;
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/workflows/${selected.id}`, { method: "DELETE" });
      if (!response.ok) {
        const result = await response.json();
        throw new Error(result.message || "Workflow gagal dihapus.");
      }
      setWorkflows((current) => current.filter((workflow) => workflow.id !== selected.id));
      setSelectedId(null);
      onToast("Workflow dihapus.");
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Workflow gagal dihapus.");
    } finally {
      setBusy(false);
    }
  }

  async function addStep() {
    if (!selected || !stepDraft.key.trim() || !stepDraft.name.trim()) return;
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/workflows/${selected.id}/steps`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ key: stepDraft.key.trim(), name: stepDraft.name.trim() }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Langkah gagal ditambahkan.");
      setStepDraft({ key: "", name: "" });
      await refreshWorkflow(selected.id);
      onToast("Langkah ditambahkan.");
    } catch (stepError) {
      setError(stepError instanceof Error ? stepError.message : "Langkah gagal ditambahkan.");
    } finally {
      setBusy(false);
    }
  }

  async function saveStepName() {
    if (!selected || !editingStep) return;
    const name = editingStep.name.trim();
    if (!name) return;
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/workflows/${selected.id}/steps/${editingStep.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Langkah gagal disimpan.");
      const updated = result.step as WorkflowStepRecord;
      setWorkflows((current) => current.map((workflow) => workflow.id === selected.id
        ? { ...workflow, steps: workflow.steps.map((step) => step.id === updated.id ? updated : step) }
        : workflow));
      setEditingStep(null);
    } catch (stepError) {
      setError(stepError instanceof Error ? stepError.message : "Langkah gagal disimpan.");
    } finally {
      setBusy(false);
    }
  }

  async function deleteStep(step: WorkflowStepRecord) {
    if (!selected || !window.confirm(`Hapus langkah "${step.name}"?`)) return;
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/workflows/${selected.id}/steps/${step.id}`, { method: "DELETE" });
      if (!response.ok) {
        const result = await response.json();
        throw new Error(result.message || "Langkah gagal dihapus.");
      }
      await refreshWorkflow(selected.id);
      onToast("Langkah dihapus.");
    } catch (stepError) {
      setError(stepError instanceof Error ? stepError.message : "Langkah gagal dihapus.");
    } finally {
      setBusy(false);
    }
  }

  async function runAction(step: WorkflowStepRecord, action: StepAction) {
    if (!selected) return;
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/workflows/${selected.id}/steps/${step.id}/transition`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Aksi workflow gagal dijalankan.");
      replaceWorkflow(result.workflow as WorkflowRecord);
    } catch (actionError) {
      setError(actionError instanceof Error ? actionError.message : "Aksi workflow gagal dijalankan.");
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return <div className="production-loading"><LoaderCircle size={17} className="spin-icon" /> Memuat workflow...</div>;
  }

  return (
    <div className="module-page workflow-page">
      <div className="page-heading">
        <div>
          <div className="eyebrow">OPERATE <span className="eyebrow-separator">/</span> WORKFLOW AUTOMATION</div>
          <h1>Workflow automation<span className="heading-period">.</span></h1>
          <p>Susun alur produksi “{project.title}” — dependensi, approval, dan status setiap langkah.</p>
        </div>
        <span className="settings-role-pill"><ShieldCheck size={13} /> Peran Anda: {role}</span>
      </div>

      {error && <div className="production-error" role="alert">{error}</div>}

      <section className="section-block">
        <div className="workflow-layout">
          <aside className="workflow-list-panel">
            <div className="workflow-list-heading">
              <span>WORKFLOW</span>
              <span className="settings-count">{workflows.length} total</span>
            </div>

            {!canEdit && <div className="workflow-readonly"><ShieldCheck size={13} /> Peran viewer hanya dapat melihat.</div>}

            {canEdit && (
              <div className="workflow-create">
                <input className="production-input" maxLength={120} placeholder="Nama workflow baru" value={draft.name} onChange={(event) => setDraft((current) => ({ ...current, name: event.target.value }))} />
                <select className="production-select" value={draft.template} onChange={(event) => setDraft((current) => ({ ...current, template: event.target.value }))}>
                  {templates.map((template) => <option key={template.value} value={template.value}>{template.label}</option>)}
                </select>
                <div className="workflow-create-row">
                  <select className="production-select" value={draft.trigger} onChange={(event) => setDraft((current) => ({ ...current, trigger: event.target.value }))}>
                    {triggerOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                  </select>
                  <button className="button-primary" onClick={() => void createWorkflow()} disabled={busy || !draft.name.trim()}>
                    {busy ? <LoaderCircle size={14} className="spin-icon" /> : <Plus size={14} />} Buat
                  </button>
                </div>
              </div>
            )}

            {workflows.length === 0 ? (
              <div className="workflow-empty">Belum ada workflow. Buat pipeline pertama untuk melacak progres produksi.</div>
            ) : (
              <ul className="workflow-list">
                {workflows.map((workflow) => (
                  <li key={workflow.id}>
                    <button
                      className={`workflow-list-row ${selected?.id === workflow.id ? "selected" : ""}`}
                      onClick={() => { setSelectedId(workflow.id); setEditingStep(null); }}
                    >
                      <span className="workflow-list-icon"><Workflow size={15} /></span>
                      <span className="workflow-list-copy">
                        <strong>{workflow.name}</strong>
                        <small>{workflow.completed_count}/{workflow.step_count} langkah selesai</small>
                      </span>
                      <span className={`workflow-badge is-${workflow.status}`}>{workflow.status}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </aside>

          <div className="workflow-canvas-panel">
            {!selected ? (
              <div className="workflow-canvas-empty">
                <div><Workflow size={20} /></div>
                <h3>Belum ada workflow dipilih</h3>
                <p>Buat workflow di panel kiri, lalu kelola langkah dan persetujuannya di sini.</p>
              </div>
            ) : (
              <>
                <div className="workflow-canvas-heading">
                  <div>
                    <span className="section-kicker">PIPELINE</span>
                    <h2>{selected.name}</h2>
                  </div>
                  <div className="workflow-canvas-controls">
                    <select className="production-select" value={selected.status} disabled={!canEdit || busy} onChange={(event) => void updateWorkflow({ status: event.target.value })}>
                      {statusOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </select>
                    <select className="production-select" value={selected.trigger} disabled={!canEdit || busy} onChange={(event) => void updateWorkflow({ trigger: event.target.value })}>
                      {triggerOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </select>
                    {canEdit && <button className="icon-button settings-delete" aria-label="Hapus workflow" disabled={busy} onClick={() => void deleteWorkflow()}><Trash2 size={15} /></button>}
                  </div>
                </div>

                <div className="workflow-progress">
                  <div className="workflow-progress-bar"><span style={{ width: `${selected.step_count === 0 ? 0 : Math.round((selected.completed_count / selected.step_count) * 100)}%` }} /></div>
                  <span>{selected.completed_count}/{selected.step_count} selesai</span>
                </div>

                {selected.steps.length === 0 ? (
                  <div className="workflow-empty">Workflow ini belum memiliki langkah.</div>
                ) : (
                  <ol className="workflow-steps">
                    {selected.steps.map((step, index) => {
                      const meta = statusMeta[step.status];
                      return (
                        <li className={`workflow-step ${meta.className}`} key={step.id}>
                          <span className="workflow-step-node">{index + 1}</span>
                          <div className="workflow-step-main">
                            {editingStep?.id === step.id ? (
                              <div className="workflow-step-edit">
                                <input className="production-input" maxLength={120} value={editingStep.name} onChange={(event) => setEditingStep({ id: step.id, name: event.target.value })} />
                                <button className="button-primary" disabled={busy || !editingStep.name.trim()} onClick={() => void saveStepName()}><Check size={13} /></button>
                                <button className="icon-button" aria-label="Batal" onClick={() => setEditingStep(null)}><X size={14} /></button>
                              </div>
                            ) : (
                              <div className="workflow-step-title">
                                <strong>{step.name}</strong>
                                <span className={`workflow-status ${meta.className}`}>{meta.label}</span>
                                {step.requires_approval && <span className="workflow-tag">Approval</span>}
                                {step.approved && <span className="workflow-tag approved">Disetujui</span>}
                              </div>
                            )}
                            <div className="workflow-step-meta">
                              <span className="workflow-key">{step.key}</span>
                              {step.depends_on.length > 0 && (
                                <span className="workflow-deps"><GitBranch size={11} /> {step.depends_on.join(", ")}</span>
                              )}
                            </div>
                          </div>
                          {canEdit && (
                            <div className="workflow-step-actions">
                              {stepActions(step).map((action) => (
                                <button
                                  key={action}
                                  className={actionMeta[action].tone === "primary" ? "button-primary workflow-action" : "button-outline workflow-action"}
                                  disabled={busy}
                                  onClick={() => void runAction(step, action)}
                                >
                                  {action === "start" && <Play size={12} />}
                                  {action === "approve" && <ThumbsUp size={12} />}
                                  {action === "complete" && <Check size={12} />}
                                  {action === "fail" && <X size={12} />}
                                  {action === "skip" && <ArrowRight size={12} />}
                                  {action === "reset" && <RotateCcw size={12} />}
                                  {actionMeta[action].label}
                                </button>
                              ))}
                              <button className="icon-button" aria-label={`Ubah nama ${step.name}`} disabled={busy} onClick={() => setEditingStep({ id: step.id, name: step.name })}><Pencil size={13} /></button>
                              <button className="icon-button settings-delete" aria-label={`Hapus langkah ${step.name}`} disabled={busy} onClick={() => void deleteStep(step)}><Trash2 size={14} /></button>
                            </div>
                          )}
                        </li>
                      );
                    })}
                  </ol>
                )}

                {canEdit && (
                  <div className="workflow-add-step">
                    <input className="production-input" maxLength={60} placeholder="Key (mis. render)" value={stepDraft.key} onChange={(event) => setStepDraft((current) => ({ ...current, key: event.target.value }))} />
                    <input className="production-input" maxLength={120} placeholder="Nama langkah" value={stepDraft.name} onChange={(event) => setStepDraft((current) => ({ ...current, name: event.target.value }))} />
                    <button className="button-primary" disabled={busy || !stepDraft.key.trim() || !stepDraft.name.trim()} onClick={() => void addStep()}>
                      {busy ? <LoaderCircle size={14} className="spin-icon" /> : <Plus size={14} />} Tambah langkah
                    </button>
                  </div>
                )}
              </>
            )}
          </div>
        </div>

        <div className="settings-note"><CircleDot size={13} /> Setiap perubahan langkah dan persetujuan dicatat pada audit log workspace.</div>
      </section>
    </div>
  );
}
