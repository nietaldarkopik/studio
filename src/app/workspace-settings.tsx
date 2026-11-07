"use client";

import {
  Building2,
  Check,
  LoaderCircle,
  Plus,
  Radio,
  Save,
  ShieldCheck,
  Trash2,
  Users,
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";

type Role = "owner" | "editor" | "viewer";

type ChannelRecord = {
  id: number;
  name: string;
  platform: string;
  external_id: string | null;
  is_active: boolean;
  project_count: number;
};

type MemberRecord = {
  user_id: number;
  name: string;
  email: string;
  role: Role;
  joined_at: string | null;
};

type Profile = { name: string; timezone: string };

const roleOptions: { value: Role; label: string }[] = [
  { value: "owner", label: "Owner" },
  { value: "editor", label: "Editor" },
  { value: "viewer", label: "Viewer" },
];

const platformOptions = [
  { value: "youtube", label: "YouTube" },
  { value: "tiktok", label: "TikTok" },
  { value: "instagram", label: "Instagram" },
  { value: "facebook", label: "Facebook" },
];

const timezoneOptions = [
  "Asia/Jakarta",
  "Asia/Makassar",
  "Asia/Jayapura",
  "Asia/Singapore",
  "UTC",
];

export default function WorkspaceSettings({
  workspaceId,
  onToast,
  onWorkspaceRenamed,
}: {
  workspaceId: number;
  onToast: (message: string) => void;
  onWorkspaceRenamed: (name: string) => void;
}) {
  const baseUrl = `/api/studio/workspaces/${workspaceId}`;
  const [role, setRole] = useState<Role>("viewer");
  const [profile, setProfile] = useState<Profile>({ name: "", timezone: "Asia/Jakarta" });
  const [savedProfile, setSavedProfile] = useState<Profile>({ name: "", timezone: "Asia/Jakarta" });
  const [channels, setChannels] = useState<ChannelRecord[]>([]);
  const [members, setMembers] = useState<MemberRecord[]>([]);
  const [channelDraft, setChannelDraft] = useState({ name: "", platform: "youtube", external_id: "" });
  const [memberDraft, setMemberDraft] = useState<{ email: string; role: Role }>({ email: "", role: "editor" });
  const [loading, setLoading] = useState(true);
  const [profileBusy, setProfileBusy] = useState(false);
  const [channelBusy, setChannelBusy] = useState(false);
  const [memberBusy, setMemberBusy] = useState(false);
  const [error, setError] = useState("");

  const isOwner = role === "owner";
  const canEditChannels = role !== "viewer";
  const profileDirty = profile.name !== savedProfile.name || profile.timezone !== savedProfile.timezone;
  const activeChannelCount = useMemo(
    () => channels.filter((channel) => channel.is_active).length,
    [channels],
  );

  useEffect(() => {
    let active = true;

    async function loadSettings() {
      setLoading(true);
      setError("");
      try {
        const [workspaceResponse, channelsResponse, membersResponse] = await Promise.all([
          fetch(baseUrl, { cache: "no-store" }),
          fetch(`${baseUrl}/channels`, { cache: "no-store" }),
          fetch(`${baseUrl}/members`, { cache: "no-store" }),
        ]);
        const workspaceResult = await workspaceResponse.json();
        if (!workspaceResponse.ok) throw new Error(workspaceResult.message || "Pengaturan workspace gagal dimuat.");
        if (!active) return;

        setRole(workspaceResult.workspace.role as Role);
        const loaded: Profile = {
          name: workspaceResult.workspace.name,
          timezone: workspaceResult.workspace.timezone,
        };
        setProfile(loaded);
        setSavedProfile(loaded);

        if (channelsResponse.ok) {
          setChannels((await channelsResponse.json()).channels as ChannelRecord[]);
        }
        if (membersResponse.ok) {
          setMembers((await membersResponse.json()).members as MemberRecord[]);
        }
      } catch (loadError) {
        if (active) setError(loadError instanceof Error ? loadError.message : "Pengaturan workspace gagal dimuat.");
      } finally {
        if (active) setLoading(false);
      }
    }

    void loadSettings();
    return () => {
      active = false;
    };
  }, [baseUrl]);

  async function saveProfile() {
    setProfileBusy(true);
    setError("");
    try {
      const response = await fetch(baseUrl, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name: profile.name.trim(), timezone: profile.timezone }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Profil workspace gagal disimpan.");
      const updated: Profile = { name: result.workspace.name, timezone: result.workspace.timezone };
      setProfile(updated);
      setSavedProfile(updated);
      onWorkspaceRenamed(updated.name);
      onToast("Profil workspace tersimpan.");
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Profil workspace gagal disimpan.");
    } finally {
      setProfileBusy(false);
    }
  }

  async function addChannel() {
    if (!channelDraft.name.trim()) return;
    setChannelBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/channels`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: channelDraft.name.trim(),
          platform: channelDraft.platform,
          external_id: channelDraft.external_id.trim() || null,
        }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Channel gagal ditambahkan.");
      setChannels((current) => [...current, result.channel as ChannelRecord]);
      setChannelDraft({ name: "", platform: "youtube", external_id: "" });
      onToast("Channel baru ditambahkan.");
    } catch (channelError) {
      setError(channelError instanceof Error ? channelError.message : "Channel gagal ditambahkan.");
    } finally {
      setChannelBusy(false);
    }
  }

  function updateChannelDraftField(id: number, patch: Partial<ChannelRecord>) {
    setChannels((current) => current.map((channel) => channel.id === id ? { ...channel, ...patch } : channel));
  }

  async function saveChannel(channel: ChannelRecord) {
    setChannelBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/channels/${channel.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: channel.name.trim(),
          platform: channel.platform,
          external_id: channel.external_id?.trim() || null,
          is_active: channel.is_active,
        }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Channel gagal disimpan.");
      const updated = result.channel as ChannelRecord;
      setChannels((current) => current.map((entry) => entry.id === updated.id ? updated : entry));
      onToast("Channel tersimpan.");
    } catch (channelError) {
      setError(channelError instanceof Error ? channelError.message : "Channel gagal disimpan.");
    } finally {
      setChannelBusy(false);
    }
  }

  async function deleteChannel(channel: ChannelRecord) {
    if (!window.confirm(`Hapus channel "${channel.name}"? Proyek terkait tidak dihapus.`)) return;
    setChannelBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/channels/${channel.id}`, { method: "DELETE" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Channel gagal dihapus.");
      setChannels((current) => current.filter((entry) => entry.id !== channel.id));
      onToast("Channel dihapus.");
    } catch (channelError) {
      setError(channelError instanceof Error ? channelError.message : "Channel gagal dihapus.");
    } finally {
      setChannelBusy(false);
    }
  }

  async function addMember() {
    if (!memberDraft.email.trim()) return;
    setMemberBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/members`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: memberDraft.email.trim(), role: memberDraft.role }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Anggota gagal ditambahkan.");
      setMembers((current) => [...current, result.member as MemberRecord]);
      setMemberDraft({ email: "", role: "editor" });
      onToast("Anggota ditambahkan ke workspace.");
    } catch (memberError) {
      setError(memberError instanceof Error ? memberError.message : "Anggota gagal ditambahkan.");
    } finally {
      setMemberBusy(false);
    }
  }

  async function updateMemberRole(member: MemberRecord, nextRole: Role) {
    setMemberBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/members/${member.user_id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ role: nextRole }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Peran anggota gagal diubah.");
      const updated = result.member as MemberRecord;
      setMembers((current) => current.map((entry) => entry.user_id === updated.user_id ? updated : entry));
      onToast("Peran anggota diperbarui.");
    } catch (memberError) {
      setError(memberError instanceof Error ? memberError.message : "Peran anggota gagal diubah.");
    } finally {
      setMemberBusy(false);
    }
  }

  async function removeMember(member: MemberRecord) {
    if (!window.confirm(`Keluarkan "${member.name}" dari workspace?`)) return;
    setMemberBusy(true);
    setError("");
    try {
      const response = await fetch(`${baseUrl}/members/${member.user_id}`, { method: "DELETE" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Anggota gagal dikeluarkan.");
      setMembers((current) => current.filter((entry) => entry.user_id !== member.user_id));
      onToast("Anggota dikeluarkan dari workspace.");
    } catch (memberError) {
      setError(memberError instanceof Error ? memberError.message : "Anggota gagal dikeluarkan.");
    } finally {
      setMemberBusy(false);
    }
  }

  if (loading) {
    return <div className="production-loading"><LoaderCircle size={17} className="spin-icon" /> Memuat pengaturan workspace...</div>;
  }

  return (
    <div className="module-page settings-page">
      <div className="page-heading">
        <div>
          <div className="eyebrow">WORKSPACE <span className="eyebrow-separator">/</span> SETTINGS</div>
          <h1>Pengaturan workspace<span className="heading-period">.</span></h1>
          <p>Kelola profil, channel distribusi, dan anggota workspace.</p>
        </div>
        <span className="settings-role-pill"><ShieldCheck size={13} /> Peran Anda: {role}</span>
      </div>

      {error && <div className="production-error" role="alert">{error}</div>}

      <section className="section-block settings-block">
        <div className="production-panel-heading">
          <div><span className="section-kicker">PROFIL</span><h2><Building2 size={15} /> Identitas workspace</h2></div>
          {!isOwner && <span className="production-version">Hanya owner dapat mengubah</span>}
        </div>
        <div className="settings-form-grid">
          <div>
            <label className="production-label" htmlFor="workspace-name">Nama workspace</label>
            <input id="workspace-name" className="production-input" maxLength={120} disabled={!isOwner} value={profile.name} onChange={(event) => setProfile((current) => ({ ...current, name: event.target.value }))} />
          </div>
          <div>
            <label className="production-label" htmlFor="workspace-timezone">Zona waktu</label>
            <select id="workspace-timezone" className="production-select" disabled={!isOwner} value={profile.timezone} onChange={(event) => setProfile((current) => ({ ...current, timezone: event.target.value }))}>
              {timezoneOptions.map((zone) => <option key={zone} value={zone}>{zone}</option>)}
            </select>
          </div>
        </div>
        <div className="production-save-row">
          <span>{profileDirty ? "Ada perubahan belum disimpan" : "Profil tersimpan"}</span>
          <button className="button-primary" onClick={() => void saveProfile()} disabled={!isOwner || profileBusy || !profileDirty || !profile.name.trim()}>
            {profileBusy ? <LoaderCircle size={14} className="spin-icon" /> : <Save size={14} />}
            {profileBusy ? "Menyimpan..." : "Simpan profil"}
          </button>
        </div>
      </section>

      <section className="section-block settings-block">
        <div className="production-panel-heading">
          <div><span className="section-kicker">CHANNEL</span><h2><Radio size={15} /> Channel distribusi</h2></div>
          <span className="production-version">{channels.length} channel · {activeChannelCount} aktif</span>
        </div>

        {canEditChannels && (
          <div className="settings-inline-form">
            <input className="production-input" maxLength={120} placeholder="Nama channel" value={channelDraft.name} onChange={(event) => setChannelDraft((current) => ({ ...current, name: event.target.value }))} />
            <select className="production-select" value={channelDraft.platform} onChange={(event) => setChannelDraft((current) => ({ ...current, platform: event.target.value }))}>
              {platformOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
            <input className="production-input" maxLength={191} placeholder="External ID (opsional)" value={channelDraft.external_id} onChange={(event) => setChannelDraft((current) => ({ ...current, external_id: event.target.value }))} />
            <button className="button-primary" onClick={() => void addChannel()} disabled={channelBusy || !channelDraft.name.trim()}>
              {channelBusy ? <LoaderCircle size={14} className="spin-icon" /> : <Plus size={14} />} Tambah
            </button>
          </div>
        )}

        {channels.length === 0 ? (
          <div className="settings-empty">Belum ada channel. Tambahkan channel untuk menandai tujuan distribusi proyek.</div>
        ) : (
          <ul className="settings-list">
            {channels.map((channel) => (
              <li className="settings-row" key={channel.id}>
                <input className="production-input" maxLength={120} disabled={!canEditChannels} value={channel.name} onChange={(event) => updateChannelDraftField(channel.id, { name: event.target.value })} />
                <select className="production-select" disabled={!canEditChannels} value={channel.platform} onChange={(event) => updateChannelDraftField(channel.id, { platform: event.target.value })}>
                  {platformOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
                <input className="production-input" maxLength={191} disabled={!canEditChannels} placeholder="External ID" value={channel.external_id ?? ""} onChange={(event) => updateChannelDraftField(channel.id, { external_id: event.target.value })} />
                <button className={`settings-status-toggle ${channel.is_active ? "active" : ""}`} disabled={!canEditChannels || channelBusy} onClick={() => updateChannelDraftField(channel.id, { is_active: !channel.is_active })}>
                  <span />{channel.is_active ? "Aktif" : "Nonaktif"}
                </button>
                <span className="settings-count">{channel.project_count} proyek</span>
                {canEditChannels && (
                  <>
                    <button className="button-outline" onClick={() => void saveChannel(channel)} disabled={channelBusy || !channel.name.trim()}>{channelBusy ? <LoaderCircle size={13} className="spin-icon" /> : <Save size={13} />} Simpan</button>
                    {isOwner && <button className="icon-button settings-delete" aria-label={`Hapus channel ${channel.name}`} disabled={channelBusy} onClick={() => void deleteChannel(channel)}><Trash2 size={15} /></button>}
                  </>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="section-block settings-block">
        <div className="production-panel-heading">
          <div><span className="section-kicker">ANGGOTA</span><h2><Users size={15} /> Anggota workspace</h2></div>
          <span className="production-version">{members.length} anggota</span>
        </div>

        {isOwner && (
          <div className="settings-inline-form">
            <input className="production-input" type="email" placeholder="Email anggota terdaftar" value={memberDraft.email} onChange={(event) => setMemberDraft((current) => ({ ...current, email: event.target.value }))} />
            <select className="production-select" value={memberDraft.role} onChange={(event) => setMemberDraft((current) => ({ ...current, role: event.target.value as Role }))}>
              {roleOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
            <button className="button-primary" onClick={() => void addMember()} disabled={memberBusy || !memberDraft.email.trim()}>
              {memberBusy ? <LoaderCircle size={14} className="spin-icon" /> : <Plus size={14} />} Tambah anggota
            </button>
          </div>
        )}

        <ul className="settings-list">
          {members.map((member) => (
            <li className="settings-row settings-member-row" key={member.user_id}>
              <span className="settings-avatar">{member.name.slice(0, 2).toUpperCase()}</span>
              <div className="settings-member-copy">
                <strong>{member.name}</strong>
                <small>{member.email}</small>
              </div>
              {isOwner ? (
                <select className="production-select" value={member.role} disabled={memberBusy} onChange={(event) => void updateMemberRole(member, event.target.value as Role)}>
                  {roleOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
              ) : (
                <span className="settings-role-pill">{member.role}</span>
              )}
              {isOwner && <button className="icon-button settings-delete" aria-label={`Keluarkan ${member.name}`} disabled={memberBusy} onClick={() => void removeMember(member)}><Trash2 size={15} /></button>}
            </li>
          ))}
        </ul>

        <div className="settings-note"><Check size={13} /> Perubahan tersimpan langsung ke workspace dan dicatat pada audit log.</div>
      </section>
    </div>
  );
}
