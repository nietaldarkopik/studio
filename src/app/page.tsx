"use client";

import {
  Activity,
  ArrowDownRight,
  ArrowRight,
  AudioLines,
  Bell,
  Blocks,
  Bot,
  Box,
  Check,
  ChevronDown,
  ChevronRight,
  CircleHelp,
  Clapperboard,
  Clock3,
  Cloud,
  Command,
  Compass,
  FileText,
  Film,
  FolderOpen,
  Gauge,
  Headphones,
  Image,
  Layers3,
  LayoutDashboard,
  ListChecks,
  Menu,
  MessageSquare,
  Monitor,
  MoreHorizontal,
  PanelLeftClose,
  PanelLeftOpen,
  Plus,
  Search,
  Settings2,
  ShieldCheck,
  Sparkles,
  Trash2,
  Upload,
  Workflow,
  X,
  type LucideIcon,
} from "lucide-react";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import ProductionWorkspace from "./production-workspace";

type StudioProject = {
  id: string;
  clientId?: string;
  title: string;
  topic: string;
  createdAt: string;
};

type StudioUser = { id: number; name: string; email: string };
type SessionStatus = "checking" | "authenticated" | "signed_out" | "unavailable";

type Integrations = {
  clipwize: "checking" | "authenticated" | "needs_login" | "offline" | "cli_unavailable";
  blender: "checking" | "detected" | "missing" | "error";
  blenderVersion?: string;
};

type NavItem = {
  label: string;
  icon: LucideIcon;
  group: string;
};

const navItems: NavItem[] = [
  { label: "Overview", icon: LayoutDashboard, group: "Workspace" },
  { label: "Production Studio", icon: Clapperboard, group: "Workspace" },
  { label: "Projects", icon: FolderOpen, group: "Workspace" },
  { label: "AI Research", icon: Compass, group: "Create" },
  { label: "Script & Storyboard", icon: FileText, group: "Create" },
  { label: "Prompt Builder", icon: Sparkles, group: "Create" },
  { label: "Scene Studio", icon: Layers3, group: "Create" },
  { label: "Blender Render", icon: Box, group: "Create" },
  { label: "Media Library", icon: Image, group: "Create" },
  { label: "Voice & Audio", icon: Headphones, group: "Create" },
  { label: "Quality Control", icon: ShieldCheck, group: "Deliver" },
  { label: "Publishing", icon: Upload, group: "Deliver" },
  { label: "Workflow Automation", icon: Workflow, group: "Operate" },
  { label: "AI Agents", icon: Bot, group: "Operate" },
  { label: "Analytics", icon: Gauge, group: "Operate" },
  { label: "Settings", icon: Settings2, group: "Operate" },
];

const pipeline = [
  { label: "Riset", icon: Compass },
  { label: "Naskah", icon: FileText },
  { label: "Storyboard", icon: Layers3 },
  { label: "Prompt", icon: Sparkles },
  { label: "Blender", icon: Box },
  { label: "Audio", icon: AudioLines },
  { label: "QC", icon: ShieldCheck },
  { label: "Publish", icon: Upload },
];

const storageKey = "ternyata-begini.studio.drafts.v1";
const modeKey = "ternyata-begini.studio.mode.v1";
const sidebarKey = "ternyata-begini.studio.sidebar.v1";

function readDrafts(): StudioProject[] {
  try {
    const value = localStorage.getItem(storageKey);
    return value ? (JSON.parse(value) as StudioProject[]) : [];
  } catch {
    return [];
  }
}

function mapApiProject(project: {
  id: number;
  client_id?: string | null;
  title: string;
  topic: string;
  created_at: string;
}): StudioProject {
  return {
    id: String(project.id),
    clientId: project.client_id ?? undefined,
    title: project.title,
    topic: project.topic,
    createdAt: project.created_at,
  };
}

export default function Home() {
  const [activeNav, setActiveNav] = useState("Overview");
  const [projects, setProjects] = useState<StudioProject[]>([]);
  const [selectedProjectId, setSelectedProjectId] = useState<string | null>(null);
  const [sessionStatus, setSessionStatus] = useState<SessionStatus>("checking");
  const [sessionError, setSessionError] = useState("");
  const [currentUser, setCurrentUser] = useState<StudioUser | null>(null);
  const [workspaceId, setWorkspaceId] = useState<number | null>(null);
  const [workspaceName, setWorkspaceName] = useState("Ternyata Begini");
  const [channelName, setChannelName] = useState("Channel belum dipilih");
  const [mode, setMode] = useState<"Manual" | "Autonomous">("Manual");
  const [collapsed, setCollapsed] = useState(false);
  const [integrations, setIntegrations] = useState<Integrations>({
    clipwize: "checking",
    blender: "checking",
  });
  const [isCreateOpen, setCreateOpen] = useState(false);
  const [isSearchOpen, setSearchOpen] = useState(false);
  const [isNotificationsOpen, setNotificationsOpen] = useState(false);
  const [toast, setToast] = useState("");
  const [title, setTitle] = useState("");
  const [topic, setTopic] = useState("");
  const [dateLabel, setDateLabel] = useState("");

  const refreshSession = useCallback(async () => {
    setSessionError("");
    try {
      const sessionResponse = await fetch("/api/studio/me", { cache: "no-store" });
      if (sessionResponse.status === 401) {
        setSessionStatus("signed_out");
        setCurrentUser(null);
        setWorkspaceId(null);
        return false;
      }
      if (!sessionResponse.ok) throw new Error("Studio API belum tersedia.");

      const session = (await sessionResponse.json()) as {
        user: StudioUser;
        workspaces: Array<{ id: number; name: string; channels: Array<{ name: string }> }>;
      };
      const workspace = session.workspaces[0];
      setCurrentUser(session.user);
      setSessionStatus("authenticated");

      if (!workspace) {
        setWorkspaceId(null);
        setProjects([]);
        setSessionError("Akun ini belum memiliki workspace. Hubungi administrator Studio.");
        setToast("Akun ini belum memiliki workspace untuk membuka proyek.");
        return true;
      }

      setWorkspaceId(workspace.id);
      setWorkspaceName(workspace.name);
      setChannelName(workspace.channels[0]?.name ?? "Channel belum dipilih");
      const projectsUrl = `/api/studio/workspaces/${workspace.id}/projects`;
      const legacyDrafts = readDrafts();
      let allLegacyDraftsImported = true;

      for (const draft of legacyDrafts) {
        const clientId = draft.clientId ?? draft.id;
        const importResponse = await fetch(projectsUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            client_id: clientId,
            title: draft.title,
            topic: draft.topic,
            mode: "manual",
          }),
        });
        if (!importResponse.ok) {
          allLegacyDraftsImported = false;
          break;
        }
      }

      if (legacyDrafts.length > 0 && allLegacyDraftsImported) {
        localStorage.removeItem(storageKey);
      }

      const projectsResponse = await fetch(projectsUrl, { cache: "no-store" });
      if (!projectsResponse.ok) throw new Error("Proyek workspace gagal dimuat.");
      const result = (await projectsResponse.json()) as {
        data: Parameters<typeof mapApiProject>[0][];
      };
      const loadedProjects = result.data.map(mapApiProject);
      setProjects(loadedProjects);
      setSelectedProjectId((selected) => (
        selected && loadedProjects.some((project) => project.id === selected)
          ? selected
          : loadedProjects[0]?.id ?? null
      ));
      if (!allLegacyDraftsImported) {
        setToast("Sebagian draft lama belum tersalin. Draft lokal tetap dipertahankan.");
      }
      return true;
    } catch (error) {
      setSessionStatus("unavailable");
      setSessionError(error instanceof Error ? error.message : "Tidak dapat terhubung ke Studio API.");
      return false;
    }
  }, []);

  useEffect(() => {
    let active = true;
    const hydrationFrame = window.requestAnimationFrame(() => {
      setProjects(readDrafts());
      setMode(localStorage.getItem(modeKey) === "Autonomous" ? "Autonomous" : "Manual");
      setCollapsed(localStorage.getItem(sidebarKey) === "collapsed");
      setDateLabel(new Intl.DateTimeFormat("id-ID", {
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric",
        timeZone: "Asia/Jakarta",
      }).format(new Date()).toLocaleUpperCase("id-ID"));
    });

    const checkIntegrations = async () => {
      try {
        const response = await fetch("/api/integrations", { cache: "no-store" });
        if (!response.ok) throw new Error("Status check failed");
        const result = (await response.json()) as Integrations;
        if (active) setIntegrations(result);
      } catch {
        if (active) {
          setIntegrations({ clipwize: "offline", blender: "error" });
        }
      }
    };

    void checkIntegrations();
    const sessionFrame = window.requestAnimationFrame(() => void refreshSession());
    const interval = window.setInterval(checkIntegrations, 60_000);
    return () => {
      active = false;
      window.cancelAnimationFrame(hydrationFrame);
      window.cancelAnimationFrame(sessionFrame);
      window.clearInterval(interval);
    };
  }, [refreshSession]);

  useEffect(() => {
    if (!toast) return;
    const timeout = window.setTimeout(() => setToast(""), 3600);
    return () => window.clearTimeout(timeout);
  }, [toast]);

  useEffect(() => {
    const handleShortcut = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        setSearchOpen((open) => !open);
      }
      if (event.key === "Escape") {
        setSearchOpen(false);
        setNotificationsOpen(false);
      }
    };

    window.addEventListener("keydown", handleShortcut);
    return () => window.removeEventListener("keydown", handleShortcut);
  }, []);

  async function createDraft(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const cleanTitle = title.trim();
    const cleanTopic = topic.trim();
    if (!cleanTitle || !cleanTopic) return;
    if (!workspaceId) {
      setToast("Akun ini belum memiliki workspace untuk menyimpan proyek.");
      return;
    }

    try {
      const response = await fetch(`/api/studio/workspaces/${workspaceId}/projects`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          client_id: crypto.randomUUID(),
          title: cleanTitle,
          topic: cleanTopic,
          mode: mode.toLowerCase(),
        }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Proyek gagal disimpan.");
      const createdProject = mapApiProject(result.data);
      setProjects((current) => [createdProject, ...current]);
      setSelectedProjectId(createdProject.id);
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Proyek gagal disimpan.");
      return;
    }

    setActiveNav("Production Studio");
    setCreateOpen(false);
    setTitle("");
    setTopic("");
    setToast("Proyek berhasil disimpan ke workspace.");
  }

  function setProductionMode(nextMode: "Manual" | "Autonomous") {
    setMode(nextMode);
    localStorage.setItem(modeKey, nextMode);
    if (nextMode === "Autonomous") {
      setToast("Mode Autonomous dipilih. Eksekusi tetap terkunci sampai backend dan approval siap.");
    }
  }

  async function deleteDraft(projectId: string) {
    try {
      const response = await fetch(`/api/studio/projects/${projectId}`, { method: "DELETE" });
      if (!response.ok) throw new Error("Proyek gagal dihapus.");
      setProjects((current) => current.filter((project) => project.id !== projectId));
      setSelectedProjectId((selected) => selected === projectId ? null : selected);
      setToast("Proyek dihapus dari workspace.");
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Proyek gagal dihapus.");
    }
  }

  async function signOut() {
    await fetch("/api/studio/auth/logout", { method: "POST" }).catch(() => undefined);
    setCurrentUser(null);
    setWorkspaceId(null);
    setSelectedProjectId(null);
    setProjects(readDrafts());
    setSessionStatus("signed_out");
  }

  function toggleSidebar() {
    const next = !collapsed;
    setCollapsed(next);
    localStorage.setItem(sidebarKey, next ? "collapsed" : "open");
  }

  function navigate(label: string) {
    setActiveNav(label === "Script & Storyboard" ? "Production Studio" : label);
    setSearchOpen(false);
  }

  function openProject(projectId: string) {
    setSelectedProjectId(projectId);
    setActiveNav("Production Studio");
    setSearchOpen(false);
  }

  function runQuickAction(action: "research" | "autonomous") {
    if (action === "research") {
      setToast("Riset belum dijalankan: provider riset dan autentikasi backend belum dikonfigurasi.");
    } else {
      setToast("Produksi otonom belum dijalankan. Tidak ada job yang dikirim.");
    }
  }

  const activeProject = projects.find((project) => project.id === selectedProjectId) ?? projects[0];

  if (sessionStatus !== "authenticated") {
    return (
      <SignInScreen
        status={sessionStatus}
        error={sessionError}
        onSignedIn={refreshSession}
      />
    );
  }

  return (
    <main className={`studio-shell ${collapsed ? "sidebar-collapsed" : ""}`}>
      <aside className="sidebar">
        <div className="brand-row">
          <div className="brand-mark"><Clapperboard size={18} strokeWidth={2.2} /></div>
          {!collapsed && (
            <div className="brand-copy">
              <span>STUDIO</span>
              <small>by Ternyata Begini</small>
            </div>
          )}
          <button className="icon-button sidebar-toggle" onClick={toggleSidebar} aria-label={collapsed ? "Buka sidebar" : "Ciutkan sidebar"}>
            {collapsed ? <PanelLeftOpen size={17} /> : <PanelLeftClose size={17} />}
          </button>
        </div>

        <button className="workspace-picker" onClick={() => setToast(`Workspace aktif: ${workspaceName}`)}>
          <span className="workspace-avatar">TB</span>
          {!collapsed && <><span className="workspace-name">{workspaceName}<small>Workspace</small></span><ChevronDown size={15} /></>}
        </button>

        <div className="sidebar-scroll">
          {["Workspace", "Create", "Deliver", "Operate"].map((group) => (
            <div className="nav-group" key={group}>
              {!collapsed && <div className="nav-label">{group}</div>}
              {navItems.filter((item) => item.group === group).map(({ label, icon: Icon }) => (
                <button
                  key={label}
                  className={`nav-item ${activeNav === label ? "active" : ""}`}
                  onClick={() => navigate(label)}
                  title={collapsed ? label : undefined}
                >
                  <Icon size={17} strokeWidth={1.8} />
                  {!collapsed && <span>{label}</span>}
                  {!collapsed && label === "Projects" && projects.length > 0 && <span className="nav-count">{projects.length}</span>}
                </button>
              ))}
            </div>
          ))}
        </div>

        <div className="sidebar-bottom">
          <div className={`preview-note ${collapsed ? "preview-note-small" : ""}`}>
            <span className="preview-dot" />
            {!collapsed && <span>API terhubung<small>Data tersimpan di workspace</small></span>}
          </div>
          <button className="profile-row" onClick={signOut} title="Keluar dari Studio">
            <span className="profile-avatar">{currentUser?.name.slice(0, 2).toUpperCase() || "TB"}</span>
            {!collapsed && <span className="profile-name">{currentUser?.name || "Studio"}<small>Keluar</small></span>}
            {!collapsed && <MoreHorizontal size={17} />}
          </button>
        </div>
      </aside>

      <section className="main-column">
        <header className="topbar">
          <div className="breadcrumbs">
            <button className="mobile-menu icon-button" onClick={toggleSidebar} aria-label="Buka navigasi"><Menu size={18} /></button>
            <span className="crumb-muted">Ternyata Begini</span>
            <ChevronRight size={14} className="crumb-chevron" />
            <span>{activeNav}</span>
          </div>
          <div className="topbar-actions">
            <button className="top-chip channel-chip" onClick={() => setToast(`Channel aktif: ${channelName}`)}>
              <span className="youtube-mark">▶</span><span>{channelName}</span><ChevronDown size={13} />
            </button>
            <button className="icon-button top-action" aria-label="Cari" onClick={() => setSearchOpen(true)}><Search size={17} /></button>
            <button className={`icon-button top-action ${isNotificationsOpen ? "selected" : ""}`} aria-label="Notifikasi" onClick={() => setNotificationsOpen(!isNotificationsOpen)}>
              <Bell size={17} />
            </button>
            <div className="top-divider" />
            <button className="top-avatar" aria-label="Keluar dari Studio" onClick={signOut}>{currentUser?.name.slice(0, 2).toUpperCase() || "TB"}</button>
          </div>
          {isNotificationsOpen && (
            <div className="notification-popover">
              <div className="popover-head"><span>Notifikasi</span><span className="quiet-tag">0 baru</span></div>
              <div className="popover-empty"><Bell size={19} /><span>Belum ada aktivitas baru.</span></div>
              <button className="popover-link" onClick={() => setNotificationsOpen(false)}>Tutup</button>
            </div>
          )}
        </header>

        <div className="page-scroll">
          {activeNav === "Overview" ? (
            <Overview
              projects={projects}
              integrations={integrations}
              dateLabel={dateLabel}
              mode={mode}
              onModeChange={setProductionMode}
              onCreate={() => setCreateOpen(true)}
              onNavigate={navigate}
              onOpenProject={openProject}
              onQuickAction={runQuickAction}
              onToast={setToast}
            />
          ) : activeNav === "Production Studio" && activeProject && workspaceId !== null ? (
            <ProductionWorkspace
              key={activeProject.id}
              project={activeProject}
              workspaceId={workspaceId}
              onBack={() => navigate("Projects")}
              onToast={setToast}
            />
          ) : (
            <SectionView
              activeNav={activeNav}
              activeProject={activeProject}
              projects={projects}
              onDelete={deleteDraft}
              onCreate={() => setCreateOpen(true)}
              onNavigate={navigate}
              onOpenProject={openProject}
              onToast={setToast}
            />
          )}
        </div>
      </section>

      {isSearchOpen && <SearchDialog onClose={() => setSearchOpen(false)} onNavigate={navigate} onOpenProject={openProject} projects={projects} />}
      {isCreateOpen && (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && setCreateOpen(false)}>
          <form className="create-modal" onSubmit={createDraft}>
            <div className="modal-topline"><span className="modal-icon"><Film size={18} /></span><button type="button" className="icon-button" aria-label="Tutup" onClick={() => setCreateOpen(false)}><X size={17} /></button></div>
            <div className="eyebrow">PROYEK BARU</div>
            <h2>Mulai cerita baru.</h2>
              <p>Catat ide dan simpan sebagai proyek di workspace.</p>
            <label className="field-label" htmlFor="project-title">Nama proyek</label>
            <input id="project-title" autoFocus maxLength={80} placeholder="Contoh: Kenapa laut terlihat biru?" value={title} onChange={(event) => setTitle(event.target.value)} required />
            <label className="field-label" htmlFor="project-topic">Topik awal</label>
            <textarea id="project-topic" rows={3} maxLength={500} placeholder="Tulis pertanyaan atau fakta yang ingin ditelusuri..." value={topic} onChange={(event) => setTopic(event.target.value)} required />
            <div className="modal-foot"><span><Command size={13} /> Shorts · 9:16 · Bahasa Indonesia</span><button className="button-primary" type="submit">Buat proyek <ArrowRight size={15} /></button></div>
          </form>
        </div>
      )}
      {toast && <div className="toast-message" role="status"><span className="toast-indicator" />{toast}<button className="toast-close" onClick={() => setToast("")} aria-label="Tutup"><X size={14} /></button></div>}
    </main>
  );
}

function SignInScreen({
  status,
  error,
  onSignedIn,
}: {
  status: SessionStatus;
  error: string;
  onSignedIn: () => Promise<boolean>;
}) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  async function signIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setMessage("");

    try {
      const response = await fetch("/api/studio/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      });
      const result = await response.json();
      if (!response.ok) {
        const validationMessage = Object.values(result.errors ?? {})[0];
        throw new Error(Array.isArray(validationMessage) ? validationMessage[0] : result.message || "Login gagal.");
      }

      setPassword("");
      const restored = await onSignedIn();
      if (!restored) setMessage("Login berhasil, tetapi data workspace belum dapat dimuat.");
    } catch (signInError) {
      setMessage(signInError instanceof Error ? signInError.message : "Login gagal.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="auth-screen">
      <section className="auth-panel">
        <div className="auth-brand">
          <span className="brand-mark"><Clapperboard size={18} strokeWidth={2.2} /></span>
          <span><strong>TERNYATA BEGINI</strong><small>CREATIVE PRODUCTION STUDIO</small></span>
        </div>
        <div className="auth-eyebrow">WORKSPACE SIGN IN</div>
        <h1>Selamat datang<br /><em>kembali.</em></h1>
        <p>Masuk untuk mengelola proyek dan workspace produksi Anda.</p>
        {status === "checking" ? (
          <div className="auth-message">Memeriksa sesi Studio...</div>
        ) : (
          <form className="auth-form" onSubmit={signIn}>
            <label htmlFor="studio-email">Email</label>
            <input id="studio-email" type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} required />
            <label htmlFor="studio-password">Password</label>
            <input id="studio-password" type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} required />
            {(message || error || status === "unavailable") && (
              <div className="auth-message" role="alert">
                {message || error || "Studio API belum tersedia. Pastikan Laravel sedang berjalan."}
              </div>
            )}
            <button className="button-primary auth-submit" type="submit" disabled={busy}>
              {busy ? "Memeriksa..." : "Masuk ke workspace"} <ArrowRight size={15} />
            </button>
          </form>
        )}
        <div className="auth-setup">
          <strong>Belum ada akun owner?</strong>
          <span>Buat akun pertama dari terminal API dengan <code>php artisan studio:user-create</code>. Pendaftaran publik dinonaktifkan.</span>
        </div>
        <div className="auth-security"><ShieldCheck size={14} /> Sesi disimpan dalam cookie HttpOnly. Token tidak dibagikan ke JavaScript.</div>
      </section>
      <div className="auth-art" aria-hidden="true"><div className="art-orbit orbit-one" /><div className="art-orbit orbit-two" /><div className="art-glow" /><div className="art-object"><div className="art-object-ring" /><div className="art-object-core" /></div><div className="art-grid" /><span className="art-coordinate coordinate-bottom">IDE → IMAGE → STORY</span></div>
    </main>
  );
}

function Overview({
  projects,
  integrations,
  dateLabel,
  mode,
  onModeChange,
  onCreate,
  onNavigate,
  onOpenProject,
  onQuickAction,
  onToast,
}: {
  projects: StudioProject[];
  integrations: Integrations;
  dateLabel: string;
  mode: "Manual" | "Autonomous";
  onModeChange: (mode: "Manual" | "Autonomous") => void;
  onCreate: () => void;
  onNavigate: (label: string) => void;
  onOpenProject: (id: string) => void;
  onQuickAction: (action: "research" | "autonomous") => void;
  onToast: (message: string) => void;
}) {
  return (
    <div className="overview-page">
      <div className="page-heading">
        <div>
          <div className="eyebrow">{dateLabel || "CONTROL ROOM"} <span className="eyebrow-separator">/</span> CONTROL ROOM</div>
          <h1>Studio overview<span className="heading-period">.</span></h1>
          <p>Ruang kendali untuk setiap cerita yang sedang kita bangun.</p>
        </div>
        <button className="button-primary create-button" onClick={onCreate}><Plus size={16} /> Buat video baru</button>
      </div>

      <section className="welcome-panel">
        <div className="welcome-copy">
          <div className="welcome-kicker"><span className="live-spark"><Sparkles size={13} /></span> YOUR CREATIVE CONTROL ROOM</div>
          <h2>Dari rasa ingin tahu,<br /><em>jadi cerita yang berkesan.</em></h2>
          <p>Riset, rancang adegan, lalu hidupkan dengan Blender. Semua proses kreatif dalam satu ruang.</p>
          <div className="welcome-actions">
            <button className="button-light" onClick={onCreate}>Mulai proyek pertama <ArrowRight size={15} /></button>
            <button className="text-action" onClick={() => onNavigate("Production Studio")}>Jelajahi studio <ChevronRight size={15} /></button>
          </div>
          <div className="welcome-foot"><span><span className="tiny-status" /> Workspace siap</span><span className="welcome-foot-divider" /><span>Format Shorts 9:16</span><span className="welcome-foot-divider" /><span>Bahasa Indonesia</span></div>
        </div>
        <div className="cinematic-art" aria-hidden="true">
          <div className="art-orbit orbit-one" /><div className="art-orbit orbit-two" />
          <div className="art-glow" /><div className="art-object"><div className="art-object-ring" /><div className="art-object-core" /></div>
          <div className="art-grid" />
          <span className="art-coordinate coordinate-top">TB / STUDIO 01</span>
          <span className="art-coordinate coordinate-bottom">IDE → IMAGE → STORY</span>
          <span className="art-index">9:16</span>
        </div>
      </section>

      <div className="overview-grid">
        <div className="overview-main">
          <section className="section-block">
            <div className="section-heading">
              <div><span className="section-kicker">PRODUCTION</span><h2>Hari ini di studio</h2></div>
              <button className="subtle-link" onClick={() => onNavigate("Projects")}>Semua proyek <ArrowRight size={14} /></button>
            </div>
            <div className="metrics-grid">
              <Metric icon={Clapperboard} label="Produksi hari ini" value="—" note="Belum ada eksekusi" />
              <Metric icon={Check} label="Video selesai" value="—" note="Belum ada data" tone="green" />
              <Metric icon={Activity} label="Job berjalan" value="—" note="Belum ada data" tone="blue" />
              <Metric icon={Clock3} label="Dalam antrean" value="—" note="Belum ada data" tone="amber" />
            </div>
            <div className="data-note"><span className="data-note-icon"><ShieldCheck size={14} /></span><span>Belum ada eksekusi produksi yang tercatat. Tidak ada statistik contoh yang ditampilkan.</span></div>
          </section>

          <section className="section-block pipeline-section">
            <div className="section-heading">
              <div><span className="section-kicker">END-TO-END FLOW</span><h2>Alur produksi</h2></div>
              <button className="icon-button small-action" aria-label="Detail workflow" onClick={() => onNavigate("Workflow Automation")}><ArrowDownRight size={16} /></button>
            </div>
            <div className="pipeline-rail">
              {pipeline.map(({ label, icon: Icon }, index) => (
                <div className="pipeline-stage" key={label}>
                  <div className="pipeline-node"><Icon size={16} strokeWidth={1.8} /></div>
                  <span className="pipeline-label">{label}</span>
                  <span className="pipeline-state">Menunggu</span>
                  {index < pipeline.length - 1 && <span className="pipeline-connector" />}
                </div>
              ))}
            </div>
            <div className="pipeline-caption"><span><span className="status-dot muted-dot" /> Belum ada eksekusi aktif</span><button onClick={() => onNavigate("Workflow Automation")}>Lihat workflow <ChevronRight size={13} /></button></div>
          </section>

          <section className="section-block projects-section">
            <div className="section-heading">
              <div><span className="section-kicker">RECENT WORK</span><h2>Proyek terbaru</h2></div>
              <button className="subtle-link" onClick={() => onNavigate("Projects")}>Lihat semua <ArrowRight size={14} /></button>
            </div>
            {projects.length === 0 ? (
              <div className="empty-projects">
                <div className="empty-project-icon"><FolderOpen size={20} /></div>
                <div><strong>Belum ada draft proyek</strong><span>Mulai dari pertanyaan kecil yang bikin penasaran.</span></div>
                <button className="button-outline" onClick={onCreate}><Plus size={14} /> Buat draft</button>
              </div>
            ) : (
              <div className="project-list">
                {projects.slice(0, 3).map((project, index) => (
                  <button className="project-row" key={project.id} onClick={() => onOpenProject(project.id)}>
                    <div className={`project-thumb thumb-${index % 3}`}><Clapperboard size={17} /></div>
                    <span className="project-row-copy"><strong>{project.title}</strong><small>{project.topic}</small></span>
                    <span className="local-draft-tag"><span />Tersimpan</span><ChevronRight size={15} className="project-arrow" />
                  </button>
                ))}
              </div>
            )}
          </section>
        </div>

        <aside className="overview-aside">
          <section className="side-card mode-card">
            <div className="side-card-heading"><span className="section-kicker">WORKFLOW MODE</span><span className="mode-orb"><Blocks size={15} /></span></div>
            <h3>How do you want to create?</h3>
            <p>Pilih cara kerja. Progress proyek tetap aman saat mode diganti.</p>
            <div className="mode-switch" role="group" aria-label="Mode produksi">
              <button className={mode === "Manual" ? "selected" : ""} onClick={() => onModeChange("Manual")}><Settings2 size={14} /> Manual</button>
              <button className={mode === "Autonomous" ? "selected" : ""} onClick={() => onModeChange("Autonomous")}><Sparkles size={14} /> Autonomous</button>
            </div>
            <div className="mode-foot"><span className={`mode-status ${mode === "Manual" ? "status-manual" : "status-autonomous"}`} />{mode === "Manual" ? "Kendali penuh di tangan Anda" : "Menunggu integrasi eksekusi"}</div>
          </section>

          <section className="side-card action-card">
            <div className="side-card-heading"><span className="section-kicker">QUICK ACTIONS</span><span className="quiet-tag">2 tersedia</span></div>
            <button className="quick-action" onClick={() => onQuickAction("research")}><span className="quick-icon research-icon"><Compass size={16} /></span><span><strong>Temukan topik</strong><small>Mulai sesi riset baru</small></span><ArrowRight size={15} /></button>
            <button className="quick-action" onClick={() => onQuickAction("autonomous")}><span className="quick-icon auto-icon"><Sparkles size={16} /></span><span><strong>Produksi otonom</strong><small>Perlu workflow aktif</small></span><ArrowRight size={15} /></button>
          </section>

          <section className="side-card connections-card">
            <div className="side-card-heading"><span className="section-kicker">CONNECTIONS</span><button className="icon-button tiny-action" aria-label="Pengaturan koneksi" onClick={() => onNavigate("Settings")}><Settings2 size={14} /></button></div>
            <IntegrationRow
              icon={Monitor}
              label="Local Agent"
              value="Belum terpasang"
              status="disconnected"
              detail="Workstation belum terhubung"
            />
            <IntegrationRow
              icon={Box}
              label="Blender"
              value={integrations.blender === "detected" ? integrations.blenderVersion ?? "Terdeteksi" : integrations.blender === "checking" ? "Memeriksa..." : "Tidak ditemukan"}
              status={integrations.blender === "detected" ? "available" : integrations.blender === "checking" ? "checking" : "disconnected"}
              detail="Di host studio · render nonaktif"
            />
            <IntegrationRow
              icon={Cloud}
              label="ClipWize"
              value={
                integrations.clipwize === "authenticated" ? "Terautentikasi"
                  : integrations.clipwize === "needs_login" ? "API aktif · login perlu"
                    : integrations.clipwize === "cli_unavailable" ? "CLI belum tersedia"
                      : integrations.clipwize === "checking" ? "Memeriksa..."
                        : "Tidak terjangkau"
              }
              status={
                integrations.clipwize === "authenticated" ? "available"
                  : integrations.clipwize === "needs_login" ? "needs_auth"
                    : integrations.clipwize === "checking" ? "checking"
                      : "disconnected"
              }
              detail="Token tetap di CLI · TTS/publish tidak dipanggil"
            />
            <button className="connection-link" onClick={() => onToast("Status berasal dari pemeriksaan baca-saja. Render dan publikasi belum diaktifkan.")}>Detail koneksi <ArrowRight size={13} /></button>
          </section>

          <div className="help-card"><span className="help-icon"><CircleHelp size={16} /></span><span><strong>Baru di Studio?</strong><small>Kenali alur kerja produksi video.</small></span><button aria-label="Buka panduan" onClick={() => onToast("Panduan akan tersedia setelah alur produksi dibangun.")}><ChevronRight size={15} /></button></div>
        </aside>
      </div>

      <footer className="page-footer"><span><span className="footer-mark">TB</span> Ternyata Begini Studio <span className="footer-separator">·</span> Workspace tersimpan</span><span>Phase 1 <span className="footer-separator">/</span> Foundation <button className="footer-help" onClick={() => onToast("Akun dan proyek tersimpan di API Studio. Workflow produksi masih dalam pengembangan.")}><MessageSquare size={13} /> Bantuan</button></span></footer>
    </div>
  );
}

function Metric({ icon: Icon, label, value, note, tone = "violet" }: { icon: LucideIcon; label: string; value: string; note: string; tone?: string }) {
  return (
    <div className="metric-card">
      <div className={`metric-icon ${tone}`}><Icon size={15} /></div>
      <div className="metric-content"><span>{label}</span><strong>{value}</strong><small>{note}</small></div>
      <span className="metric-skeleton" />
    </div>
  );
}

function IntegrationRow({ icon: Icon, label, value, status, detail }: { icon: LucideIcon; label: string; value: string; status: "available" | "needs_auth" | "checking" | "disconnected"; detail: string }) {
  return (
    <div className="integration-row">
      <span className="integration-icon"><Icon size={15} /></span>
      <span className="integration-copy"><strong>{label}</strong><small>{detail}</small></span>
      <span className="integration-value"><span className={`status-dot ${status === "available" ? "green-dot" : status === "needs_auth" ? "needs-auth-dot" : status === "checking" ? "checking-dot" : "muted-dot"}`} />{value}</span>
    </div>
  );
}

function SectionView({
  activeNav,
  activeProject,
  projects,
  onDelete,
  onCreate,
  onNavigate,
  onOpenProject,
  onToast,
}: {
  activeNav: string;
  activeProject?: StudioProject;
  projects: StudioProject[];
  onDelete: (id: string) => void;
  onCreate: () => void;
  onNavigate: (label: string) => void;
  onOpenProject: (id: string) => void;
  onToast: (message: string) => void;
}) {
  const isProjectList = activeNav === "Projects" || activeNav === "Production Studio";
  const [eyebrow, description] = [isProjectList ? "YOUR WORKSPACE" : "STUDIO MODULE", getSectionDescription(activeNav)];
  return (
    <div className="module-page">
      <div className="page-heading">
        <div><div className="eyebrow">{eyebrow} <span className="eyebrow-separator">/</span> {isProjectList ? "PHASE 2" : "PHASE 1"}</div><h1>{activeNav}<span className="heading-period">.</span></h1><p>{description}</p></div>
        {isProjectList && <button className="button-primary create-button" onClick={onCreate}><Plus size={16} /> Buat video baru</button>}
      </div>
      {isProjectList ? (
        projects.length === 0 ? (
          <div className="module-empty"><div className="module-empty-icon"><FolderOpen size={23} /></div><h2>Ruang proyek masih kosong.</h2><p>Buat proyek pertama. Data akan tersimpan pada workspace Anda di database.</p><button className="button-primary" onClick={onCreate}><Plus size={15} /> Buat proyek pertama</button></div>
        ) : (
          <div className="projects-workspace">
            <div className="projects-summary"><div><span className="section-kicker">WORKSPACE PROJECTS</span><h2>{projects.length} proyek</h2></div><span className="local-only-pill"><span />Tersimpan di database</span></div>
            {projects.map((project, index) => (
              <div className={`workspace-project ${activeProject?.id === project.id ? "workspace-project-active" : ""}`} key={project.id}>
                <button className="workspace-project-main" onClick={() => onOpenProject(project.id)} aria-label={`Buka ruang produksi proyek ${project.title}`}>
                  <div className={`project-thumb thumb-${index % 3}`}><Clapperboard size={17} /></div><div className="workspace-project-copy"><strong>{project.title}</strong><span>{project.topic}</span><small>{new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(project.createdAt))}</small></div>
                </button>
                <span className="local-draft-tag"><span />Draft</span>
                <button className="delete-draft icon-button" aria-label={`Hapus proyek ${project.title}`} onClick={() => window.confirm(`Hapus proyek “${project.title}” dari workspace?`) && onDelete(project.id)}><Trash2 size={14} /></button>
                <ChevronRight size={15} className="workspace-project-chevron" />
              </div>
            ))}
          </div>
        )
      ) : (
        <div className="module-empty">
          <div className="module-empty-icon"><ModuleIcon name={activeNav} /></div>
          <span className="coming-label"><span />INTEGRASI BERIKUTNYA</span>
          <h2>Semua progres tetap satu tempat.</h2>
          <p>Modul {activeNav} memerlukan layanan produksi khusus yang belum diaktifkan. Tidak ada proses yang dijalankan diam-diam.</p>
          <div className="module-actions"><button className="button-primary" onClick={() => onNavigate("Overview")}>Kembali ke overview <ArrowRight size={15} /></button><button className="button-outline" onClick={() => onToast("Proyek dan workspace tersimpan di API. Integrasi produksi masih belum diaktifkan.")}>Lihat status fondasi <ListChecks size={14} /></button></div>
        </div>
      )}
      <div className="module-bottom"><span><span className="status-dot green-dot" /> Data workspace terhubung</span><span>Detail produksi akan aktif di fase berikutnya</span></div>
    </div>
  );
}

function SearchDialog({ onClose, onNavigate, onOpenProject, projects }: {
  onClose: () => void;
  onNavigate: (label: string) => void;
  onOpenProject: (id: string) => void;
  projects: StudioProject[];
}) {
  const [query, setQuery] = useState("");
  const matches = navItems.filter((item) => item.label.toLowerCase().includes(query.toLowerCase()));
  const matchingProjects = projects.filter((project) => `${project.title} ${project.topic}`.toLowerCase().includes(query.toLowerCase()));
  return (
    <div className="modal-backdrop search-backdrop" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div className="search-dialog">
        <div className="search-input-row"><Search size={18} /><input autoFocus placeholder="Cari halaman atau proyek..." value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={(event) => event.key === "Escape" && onClose()} /><kbd>ESC</kbd><button className="icon-button" onClick={onClose} aria-label="Tutup pencarian"><X size={16} /></button></div>
        <div className="search-results">
          {matches.length > 0 && (
            <>
              <div className="search-group-label">NAVIGASI</div>
              {matches.slice(0, 7).map(({ label, icon: Icon }) => (
                <button className="search-result" key={label} onClick={() => onNavigate(label)}>
                  <Icon size={16} /><span>{label}</span><ArrowRight size={14} />
                </button>
              ))}
            </>
          )}
          {matchingProjects.length > 0 && (
            <>
              <div className="search-group-label">PROYEK</div>
              {matchingProjects.slice(0, 4).map((project) => (
                <button className="search-result" key={project.id} onClick={() => onOpenProject(project.id)}>
                  <Film size={16} /><span>{project.title}</span><ArrowRight size={14} />
                </button>
              ))}
            </>
          )}
          {matches.length === 0 && matchingProjects.length === 0 && <div className="search-no-results">Tidak ada hasil untuk “{query}”.</div>}
        </div>
        <div className="search-footer"><span><kbd>ESC</kbd> untuk menutup</span></div>
      </div>
    </div>
  );
}

function ModuleIcon({ name }: { name: string }) {
  const item = navItems.find((navItem) => navItem.label === name);
  const Icon = item?.icon ?? Blocks;
  return <Icon size={23} />;
}

function getSectionDescription(name: string) {
  const descriptions: Record<string, string> = {
    "Production Studio": "Pilih proyek untuk mengedit naskah dan menyusun storyboard.",
    Projects: "Semua proyek dan progres produksi dalam satu ruang kerja.",
    "AI Research": "Temukan topik unik, kumpulkan sumber, dan verifikasi fakta.",
    "Script & Storyboard": "Bangun narasi dan susun adegan menjadi cerita.",
    "Prompt Builder": "Rancang brief visual yang konsisten untuk setiap scene.",
    "Scene Studio": "Tinjau, revisi, dan setujui visual sebelum render final.",
    "Blender Render": "Pantau workstation dan render yang berjalan secara lokal.",
    "Media Library": "Aset visual dan audio yang tertata per proyek.",
    "Voice & Audio": "Kelola narasi, musik, dan sinkronisasi audio.",
    "Quality Control": "Periksa hasil secara visual dan teknis sebelum tayang.",
    Publishing: "Siapkan detail publikasi dan persetujuan manusia.",
    "Workflow Automation": "Atur alur produksi, dependensi, dan approval.",
    "AI Agents": "Pantau tugas dan izin setiap AI agent.",
    Analytics: "Pahami performa video dari data channel yang terhubung.",
    Settings: "Kelola koneksi, channel, preferensi, dan keamanan workspace.",
  };
  return descriptions[name] ?? "Ruang kerja produksi video Ternyata Begini.";
}
