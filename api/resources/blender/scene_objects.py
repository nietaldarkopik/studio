"""Pustaka objek 3D prosedural untuk adegan penjelasan.

Setiap builder membuat objek di titik asal (alas di z=0) lalu root-nya dipindah ke posisi adegan.
Tipe yang didukung dicantumkan di OBJECT_TYPES (juga dipakai backend sebagai allowlist).
"""
import math

import bpy

OBJECT_TYPES = [
    "person", "school", "house", "temple", "tree", "mountain", "sun", "moon", "cloud", "book",
    "clock", "laptop", "phone", "chart", "arrow", "coin", "car", "flag", "magnifier", "lightbulb",
    "question", "brain", "water", "fire", "crowd",
]
ANIMS = ["none", "bob", "spin", "grow", "shake", "wave"]


class Ctx:
    """Pembungkus helper dari render_video agar modul ini bebas impor melingkar."""

    def __init__(self, make_material, hex_rgba, add_text):
        self.mat = make_material
        self.hex = hex_rgba
        self.text = add_text
        self.n = 0

    def name(self, base):
        self.n += 1
        return f"{base}{self.n}"


def prim(ctx, kind, loc, scale, color, parent, rot=(0, 0, 0), rough=0.45, emission=0.0):
    if kind == "cube":
        bpy.ops.mesh.primitive_cube_add(size=1, location=loc)
    elif kind == "sphere":
        bpy.ops.mesh.primitive_uv_sphere_add(radius=0.5, location=loc, segments=32, ring_count=16)
    elif kind == "cylinder":
        bpy.ops.mesh.primitive_cylinder_add(radius=0.5, depth=1, location=loc, vertices=32)
    elif kind == "cone":
        bpy.ops.mesh.primitive_cone_add(radius1=0.5, depth=1, location=loc, vertices=32)
    elif kind == "pyramid":
        bpy.ops.mesh.primitive_cone_add(radius1=0.7071, depth=1, location=loc, vertices=4)
        rot = (rot[0], rot[1], rot[2] + math.radians(45))
    elif kind == "torus":
        bpy.ops.mesh.primitive_torus_add(location=loc, major_radius=0.4, minor_radius=0.08)
    else:
        raise ValueError(kind)
    o = bpy.context.object
    o.scale = scale
    o.rotation_euler = rot
    if kind in ("sphere", "cylinder", "cone", "torus"):
        bpy.ops.object.shade_smooth()
    o.data.materials.append(ctx.mat(ctx.name("m"), color, rough=rough, emission=emission))
    o.parent = parent
    return o


def empty(name="root"):
    bpy.ops.object.empty_add(type="PLAIN_AXES", location=(0, 0, 0))
    e = bpy.context.object
    e.name = name
    return e


# ------------------------------------------------------------------ builders

def b_person(ctx, root, color, spec):
    c = ctx.hex(color)
    skin = ctx.hex(spec.get("skin", "#f1c27d"))
    dark = ctx.hex("#2b2f3a")
    hair = ctx.hex(spec.get("hair", "#2a1d17"))
    prim(ctx, "cylinder", (-0.18, 0, 0.45), (0.2, 0.2, 0.9), dark, root)
    prim(ctx, "cylinder", (0.18, 0, 0.45), (0.2, 0.2, 0.9), dark, root)
    prim(ctx, "cylinder", (0, 0, 1.35), (0.75, 0.5, 1.0), c, root)
    prim(ctx, "sphere", (0, 0, 1.95), (0.72, 0.72, 0.72), skin, root)
    prim(ctx, "sphere", (0, 0.04, 2.1), (0.78, 0.74, 0.55), hair, root)
    for side in (-1, 1):
        prim(ctx, "sphere", (side * 0.17, -0.34, 2.0), (0.14, 0.1, 0.14), ctx.hex("#ffffff"), root)
        prim(ctx, "sphere", (side * 0.17, -0.4, 2.0), (0.07, 0.05, 0.07), ctx.hex("#111111"), root)
    arms = []
    for side in (-1, 1):
        arms.append(prim(ctx, "cylinder", (side * 0.52, 0, 1.4), (0.17, 0.17, 0.85), c, root, rot=(0, side * 0.25, 0)))
    mouth = prim(ctx, "sphere", (0, -0.36, 1.82), (0.2, 0.08, 0.1), ctx.hex("#7a1f2b"), root)
    return {"mouth": mouth, "arms": arms}


def b_school(ctx, root, color, spec):
    c = ctx.hex(color)
    prim(ctx, "cube", (0, 0, 1.2), (4.2, 2.4, 2.4), c, root)
    prim(ctx, "pyramid", (0, 0, 2.95), (4.8, 2.9, 1.1), ctx.hex("#8b3a2f"), root)
    prim(ctx, "cube", (0, -1.21, 0.7), (0.8, 0.06, 1.4), ctx.hex("#5a3a22"), root)
    for x in (-1.4, 1.4):
        prim(ctx, "cube", (x, -1.21, 1.5), (0.7, 0.06, 0.7), ctx.hex("#ffe08a"), root, emission=0.8)
    prim(ctx, "cylinder", (0, 0, 3.9), (0.08, 0.08, 1.4), ctx.hex("#cccccc"), root)


def b_house(ctx, root, color, spec):
    prim(ctx, "cube", (0, 0, 0.9), (2.6, 2.2, 1.8), ctx.hex(color), root)
    prim(ctx, "pyramid", (0, 0, 2.2), (3.2, 2.7, 1.0), ctx.hex("#7a2e2e"), root)
    prim(ctx, "cube", (0, -1.11, 0.6), (0.6, 0.06, 1.2), ctx.hex("#5a3a22"), root)
    prim(ctx, "cube", (0.8, -1.11, 1.1), (0.5, 0.06, 0.5), ctx.hex("#ffe08a"), root, emission=0.8)


def b_temple(ctx, root, color, spec):
    c = ctx.hex(color)
    for i, (w, h) in enumerate([(4.2, 0.8), (3.4, 0.8), (2.6, 0.8), (1.8, 0.8)]):
        prim(ctx, "cube", (0, 0, 0.4 + i * 0.8), (w, w, h), c, root)
    prim(ctx, "cone", (0, 0, 3.7), (1.2, 1.2, 1.4), c, root)
    prim(ctx, "sphere", (0, 0, 4.5), (0.35, 0.35, 0.35), ctx.hex("#ffd166"), root, emission=0.5)


def b_tree(ctx, root, color, spec):
    prim(ctx, "cylinder", (0, 0, 0.8), (0.4, 0.4, 1.6), ctx.hex("#6b4a2b"), root)
    prim(ctx, "sphere", (0, 0, 2.1), (1.8, 1.8, 1.6), ctx.hex(color), root)
    prim(ctx, "sphere", (0.5, 0, 2.7), (1.2, 1.2, 1.1), ctx.hex(color), root)


def b_mountain(ctx, root, color, spec):
    prim(ctx, "cone", (0, 0, 1.6), (5, 5, 3.2), ctx.hex(color), root)
    prim(ctx, "cone", (0, 0, 2.9), (1.7, 1.7, 1.1), ctx.hex("#f5f7ff"), root)


def b_sun(ctx, root, color, spec):
    prim(ctx, "sphere", (0, 0, 1.2), (1.8, 1.8, 1.8), ctx.hex(color), root, emission=3.0)


def b_moon(ctx, root, color, spec):
    prim(ctx, "sphere", (0, 0, 1.0), (1.6, 1.6, 1.6), ctx.hex(color), root, emission=0.8)
    prim(ctx, "sphere", (0.5, -0.25, 1.15), (1.3, 1.3, 1.4), ctx.hex(spec.get("bg", "#0b1030")), root)


def b_cloud(ctx, root, color, spec):
    c = ctx.hex(color)
    for x, s in ((-0.9, 1.1), (0, 1.5), (0.9, 1.1)):
        prim(ctx, "sphere", (x, 0, 1.0 + (0.2 if x == 0 else 0)), (s, s * 0.8, s * 0.8), c, root, emission=0.3)


def b_book(ctx, root, color, spec):
    prim(ctx, "cube", (0, 0, 0.18), (1.8, 1.3, 0.36), ctx.hex(color), root)
    prim(ctx, "cube", (0, -0.03, 0.2), (1.7, 1.25, 0.3), ctx.hex("#f8f4e8"), root)
    prim(ctx, "cube", (0, 0, 0.4), (1.8, 1.3, 0.06), ctx.hex(color), root)


def b_clock(ctx, root, color, spec):
    prim(ctx, "cylinder", (0, 0, 1.2), (1.8, 0.2, 1.8), ctx.hex("#f8f8f8"), root, rot=(math.radians(90), 0, 0))
    prim(ctx, "torus", (0, -0.1, 1.2), (2.2, 2.2, 2.2), ctx.hex(color), root, rot=(math.radians(90), 0, 0))
    prim(ctx, "cube", (0, -0.14, 1.45), (0.07, 0.04, 0.55), ctx.hex("#222222"), root)
    prim(ctx, "cube", (0.2, -0.14, 1.2), (0.45, 0.04, 0.06), ctx.hex("#222222"), root)


def b_laptop(ctx, root, color, spec):
    prim(ctx, "cube", (0, 0, 0.06), (2.0, 1.4, 0.12), ctx.hex(color), root)
    prim(ctx, "cube", (0, 0.65, 0.75), (2.0, 0.08, 1.3), ctx.hex(color), root, rot=(math.radians(-12), 0, 0))
    prim(ctx, "cube", (0, 0.6, 0.76), (1.8, 0.05, 1.12), ctx.hex("#6ee7ff"), root, rot=(math.radians(-12), 0, 0), emission=1.5)


def b_phone(ctx, root, color, spec):
    prim(ctx, "cube", (0, 0, 1.0), (0.8, 0.1, 1.6), ctx.hex(color), root)
    prim(ctx, "cube", (0, -0.06, 1.0), (0.68, 0.03, 1.4), ctx.hex("#9bf6ff"), root, emission=1.5)


def b_chart(ctx, root, color, spec):
    for i, h in enumerate((0.8, 1.4, 2.1, 3.0)):
        prim(ctx, "cube", ((i - 1.5) * 0.9, 0, h / 2), (0.7, 0.7, h), ctx.hex(color if i < 3 else "#ffb74d"), root)


def b_arrow(ctx, root, color, spec):
    c = ctx.hex(color)
    prim(ctx, "cylinder", (0, 0, 0.9), (0.3, 0.3, 1.8), c, root, emission=0.6)
    prim(ctx, "cone", (0, 0, 2.2), (0.9, 0.9, 0.9), c, root, emission=0.6)


def b_coin(ctx, root, color, spec):
    prim(ctx, "cylinder", (0, 0, 1.0), (1.5, 0.2, 1.5), ctx.hex(color), root, rot=(math.radians(90), 0, 0), rough=0.2, emission=0.3)


def b_car(ctx, root, color, spec):
    prim(ctx, "cube", (0, 0, 0.7), (3.4, 1.6, 0.8), ctx.hex(color), root)
    prim(ctx, "cube", (0.2, 0, 1.3), (1.9, 1.4, 0.7), ctx.hex(color), root)
    prim(ctx, "cube", (0.2, 0, 1.32), (1.7, 1.42, 0.45), ctx.hex("#9bf6ff"), root, emission=0.4)
    for x in (-1.1, 1.1):
        for y in (-0.8, 0.8):
            prim(ctx, "cylinder", (x, y, 0.4), (0.8, 0.3, 0.8), ctx.hex("#111111"), root, rot=(math.radians(90), 0, 0))


def b_flag(ctx, root, color, spec):
    prim(ctx, "cylinder", (-0.9, 0, 1.6), (0.08, 0.08, 3.2), ctx.hex("#dddddd"), root)
    stripes = [s for s in (spec.get("stripes") or []) if isinstance(s, str)] or [color, "#ffffff"]
    n = len(stripes)
    for i, s in enumerate(stripes):
        h = 1.4 / n
        if spec.get("stripe_dir", "h") == "v":
            prim(ctx, "cube", (-0.9 + 0.1 + (i + 0.5) * (1.8 / n), 0, 2.6), (1.8 / n, 0.05, 1.4), ctx.hex(s), root)
        else:
            prim(ctx, "cube", (0.1, 0, 3.2 - (i + 0.5) * h), (1.8, 0.05, h), ctx.hex(s), root)


def b_magnifier(ctx, root, color, spec):
    prim(ctx, "torus", (0, 0, 1.6), (2.6, 2.6, 2.6), ctx.hex(color), root, rot=(math.radians(90), 0, 0))
    prim(ctx, "sphere", (0, 0.02, 1.6), (0.95, 0.04, 0.95), ctx.hex("#9bf6ff"), root, emission=0.6)
    prim(ctx, "cylinder", (0.7, 0, 0.8), (0.14, 0.14, 1.2), ctx.hex("#5a3a22"), root, rot=(0, math.radians(35), 0))


def b_lightbulb(ctx, root, color, spec):
    prim(ctx, "sphere", (0, 0, 1.6), (1.2, 1.2, 1.3), ctx.hex(color), root, emission=4.0)
    prim(ctx, "cylinder", (0, 0, 0.85), (0.55, 0.55, 0.45), ctx.hex("#888888"), root)


def b_question(ctx, root, color, spec):
    ctx.text("?", (0, 0, 0.2), size=3.0, color=ctx.hex(color), extrude=0.3, parent=root)


def b_brain(ctx, root, color, spec):
    c = ctx.hex(color)
    prim(ctx, "sphere", (-0.45, 0, 1.2), (1.1, 1.0, 0.9), c, root)
    prim(ctx, "sphere", (0.45, 0, 1.2), (1.1, 1.0, 0.9), c, root)
    prim(ctx, "sphere", (0, 0.05, 1.5), (0.9, 0.8, 0.5), c, root)


def b_water(ctx, root, color, spec):
    prim(ctx, "cube", (0, 0, 0.1), (5.5, 3.0, 0.3), ctx.hex(color), root, rough=0.1, emission=0.2)


def b_fire(ctx, root, color, spec):
    prim(ctx, "cone", (0, 0, 0.9), (1.1, 1.1, 1.8), ctx.hex(color), root, emission=3.0)
    prim(ctx, "cone", (0.3, 0, 0.6), (0.7, 0.7, 1.2), ctx.hex("#ffd166"), root, emission=3.5)


def b_crowd(ctx, root, color, spec):
    tones = [color, "#ff8fab", "#ffd166", "#9bf6ff", "#b8f2a1"]
    for i, x in enumerate((-1.6, -0.8, 0, 0.8, 1.6)):
        sub = empty("crowd")
        sub.parent = root
        sub.location = (x, (i % 2) * 0.5, 0)
        sub.scale = (0.55, 0.55, 0.55)
        b_person(ctx, sub, tones[i % len(tones)], spec)


BUILDERS = {
    "person": b_person, "school": b_school, "house": b_house, "temple": b_temple, "tree": b_tree,
    "mountain": b_mountain, "sun": b_sun, "moon": b_moon, "cloud": b_cloud, "book": b_book,
    "clock": b_clock, "laptop": b_laptop, "phone": b_phone, "chart": b_chart, "arrow": b_arrow,
    "coin": b_coin, "car": b_car, "flag": b_flag, "magnifier": b_magnifier, "lightbulb": b_lightbulb,
    "question": b_question, "brain": b_brain, "water": b_water, "fire": b_fire, "crowd": b_crowd,
}


def build_objects(ctx, scene_spec, frames, fps):
    """Bangun semua objek adegan dan animasinya. Mengembalikan jumlah objek."""
    objects = (scene_spec.get("objects") or [])[:12]
    for idx, spec in enumerate(objects):
        kind = spec.get("type")
        if kind not in BUILDERS and kind != "model":
            continue
        root = empty(f"obj_{kind}_{idx}")
        scale = float(spec.get("scale", 1.0))
        extras = {}
        if kind == "model":
            ok = False
            try:
                ok = bool(spec.get("model_path")) and import_model(ctx, root, spec["model_path"], float(spec.get("size", 3.0)))
            except Exception as exc:  # model rusak: pakai objek cadangan
                print("MODEL_IMPORT_FAILED", exc, flush=True)
            if not ok:
                fb = spec.get("fallback") if spec.get("fallback") in BUILDERS else "lightbulb"
                extras = BUILDERS[fb](ctx, root, spec.get("color", "#6ee7ff"), spec) or {}
        else:
            extras = BUILDERS[kind](ctx, root, spec.get("color", "#6ee7ff"), spec) or {}
        x, y = float(spec.get("x", 0)), float(spec.get("y", 0))
        root.location = (x, y, float(spec.get("z", 0)))
        root.scale = (scale, scale, scale)

        label = str(spec.get("label") or "").strip()
        if label:
            ctx.text(label[:18], (x, y - 1.3 * scale, -0.9), size=0.5, color=(1, 1, 1, 1), extrude=0.04)

        anim = spec.get("anim", "none")
        phase = idx * 0.8
        speaks = bool(spec.get("speaks", False)) and "mouth" in extras
        for f in range(1, frames + 1, 2):
            t = f / fps
            progress = min(1.0, f / max(1.0, frames * 0.2))
            if anim == "grow":
                k = max(0.02, progress)
                root.scale = (scale * k, scale * k, scale * k)
            elif anim == "bob":
                root.location.z = float(spec.get("z", 0)) + 0.18 * math.sin(t * 3 + phase)
            elif anim == "spin":
                root.rotation_euler[2] = t * 1.2
            elif anim == "shake":
                root.rotation_euler[2] = 0.18 * math.sin(t * 14 + phase)
                root.location.z = float(spec.get("z", 0)) + 0.1 * abs(math.sin(t * 10 + phase))
            elif anim == "wave" and extras.get("arms"):
                extras["arms"][1].rotation_euler[1] = 0.25 + 1.4 * abs(math.sin(t * 4 + phase))
                extras["arms"][1].keyframe_insert("rotation_euler", frame=f)
            root.keyframe_insert("location", frame=f)
            root.keyframe_insert("rotation_euler", frame=f)
            root.keyframe_insert("scale", frame=f)
            if speaks:
                m = extras["mouth"]
                m.scale = (0.2, 0.08, 0.06 + 0.1 * abs(math.sin(t * 9 + phase)))
                m.keyframe_insert("scale", frame=f)
    return len(objects)


# ------------------------------------------------------------ objek tambahan

def b_earth(ctx, root, color, spec):
    prim(ctx, "sphere", (0, 0, 1.4), (2.4, 2.4, 2.4), ctx.hex("#2f6fd6"), root, rough=0.35)
    land = ctx.hex("#3f9b4e")
    for dx, dy, dz, sx, sy in ((0.9, -0.9, 0.9, 1.0, 0.8), (-0.8, -1.0, 0.2, 0.9, 0.7), (0.2, -1.1, -0.7, 0.8, 0.9), (-0.2, -0.6, 1.0, 0.6, 0.5)):
        n = (dx * dx + dy * dy + dz * dz) ** 0.5
        px, py, pz = dx / n * 1.2, dy / n * 1.2, 1.4 + dz / n * 1.2
        prim(ctx, "sphere", (px, py, pz), (sx, 0.35, sy), land, root)


def b_planet(ctx, root, color, spec):
    prim(ctx, "sphere", (0, 0, 1.4), (2.0, 2.0, 2.0), ctx.hex(color), root)
    prim(ctx, "torus", (0, 0, 1.4), (5.0, 5.0, 2.0), ctx.hex("#f4d58d"), root, rot=(math.radians(18), 0, 0))


def b_rocket(ctx, root, color, spec):
    c = ctx.hex(color)
    prim(ctx, "cylinder", (0, 0, 1.6), (0.9, 0.9, 2.4), ctx.hex("#f2f2f2"), root)
    prim(ctx, "cone", (0, 0, 3.3), (0.9, 0.9, 1.0), c, root)
    for side in (-1, 1):
        prim(ctx, "cube", (side * 0.6, 0, 0.7), (0.15, 0.5, 0.9), c, root)
    prim(ctx, "cone", (0, 0, 0.1), (0.7, 0.7, 1.0), ctx.hex("#ffb74d"), root, rot=(math.radians(180), 0, 0), emission=4.0)
    prim(ctx, "sphere", (0, -0.42, 2.0), (0.38, 0.1, 0.38), ctx.hex("#9bf6ff"), root, emission=0.8)


def b_volcano(ctx, root, color, spec):
    prim(ctx, "cone", (0, 0, 1.3), (4.0, 4.0, 2.6), ctx.hex(color), root)
    prim(ctx, "cone", (0, 0, 2.7), (1.1, 1.1, 0.7), ctx.hex("#ff6a2b"), root, emission=3.5)
    prim(ctx, "sphere", (0.2, 0, 3.4), (0.6, 0.6, 0.6), ctx.hex("#ffb74d"), root, emission=3.0)


def b_ship(ctx, root, color, spec):
    prim(ctx, "cube", (0, 0, 0.45), (3.4, 1.3, 0.8), ctx.hex(color), root)
    prim(ctx, "cube", (-0.6, 0, 1.1), (1.1, 0.9, 0.6), ctx.hex("#f2f2f2"), root)
    prim(ctx, "cylinder", (0.4, 0, 1.9), (0.08, 0.08, 2.2), ctx.hex("#cccccc"), root)
    prim(ctx, "cube", (0.9, 0, 2.2), (1.0, 0.04, 1.4), ctx.hex("#ffffff"), root)


def b_plane(ctx, root, color, spec):
    c = ctx.hex(color)
    prim(ctx, "cylinder", (0, 0, 1.2), (0.7, 0.7, 3.4), c, root, rot=(0, math.radians(90), 0))
    prim(ctx, "cube", (0, 0, 1.2), (0.6, 3.6, 0.1), c, root)
    prim(ctx, "cube", (-1.4, 0, 1.55), (0.4, 0.1, 0.6), c, root)
    prim(ctx, "cone", (1.85, 0, 1.2), (0.7, 0.7, 0.6), ctx.hex("#dddddd"), root, rot=(0, math.radians(90), 0))


def b_bridge(ctx, root, color, spec):
    c = ctx.hex(color)
    prim(ctx, "cube", (0, 0, 1.2), (5.0, 0.9, 0.2), ctx.hex("#555555"), root)
    for x in (-1.8, 1.8):
        prim(ctx, "cube", (x, 0, 1.6), (0.25, 0.5, 3.2), c, root)
    for x in (-3.4, -2.4, 0, 2.4, 3.4):
        prim(ctx, "cylinder", (x, 0, 0.55), (0.12, 0.12, 1.1), c, root)


BUILDERS.update({
    "earth": b_earth, "planet": b_planet, "rocket": b_rocket, "volcano": b_volcano,
    "ship": b_ship, "plane": b_plane, "bridge": b_bridge,
})
OBJECT_TYPES.extend(["earth", "planet", "rocket", "volcano", "ship", "plane", "bridge"])


# ------------------------------------------------------------ model Sketchfab (glTF)

def import_model(ctx, root, path, target_size):
    """Impor berkas glTF/GLB ke root: pusatkan, taruh alasnya di z=0, skala sisi terpanjang = target_size."""
    before = set(bpy.data.objects)
    bpy.ops.import_scene.gltf(filepath=path)
    new = [o for o in bpy.data.objects if o not in before]
    meshes = [o for o in new if o.type == "MESH"]
    if not meshes:
        return False

    from mathutils import Vector
    mins = Vector((1e9, 1e9, 1e9))
    maxs = Vector((-1e9, -1e9, -1e9))
    for o in meshes:
        for corner in o.bound_box:
            w = o.matrix_world @ Vector(corner)
            mins = Vector((min(mins.x, w.x), min(mins.y, w.y), min(mins.z, w.z)))
            maxs = Vector((max(maxs.x, w.x), max(maxs.y, w.y), max(maxs.z, w.z)))
    size = max(maxs.x - mins.x, maxs.y - mins.y, maxs.z - mins.z) or 1.0
    k = float(target_size) / size
    center = Vector(((mins.x + maxs.x) / 2, (mins.y + maxs.y) / 2, mins.z))

    for o in new:
        if o.parent is None:
            o.parent = root
            o.location = (o.location - center) * k
            o.scale = o.scale * k
    return True
