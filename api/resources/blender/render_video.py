"""Render data-driven 3D explainer scenes headlessly.

Usage:  blender -b -P render_video.py -- spec.json

spec.json:
{
  "out_dir": "abs/path",            # frames go to out_dir/scene_NN/0001.png
  "width": 540, "height": 960, "fps": 12,
  "scenes": [{
      "index": 1, "frames": 60,
      "bg": ["#0b1030", "#2a1b5e"],
      "accent": "#ffb74d",
      "title": "text shown as 3D title",
      "camera": "push_in|pull_out|orbit|pan",
      "characters": [{"shape": "sphere|cube|capsule|cone", "color": "#hex",
                      "stripes": ["#hex", ...], "stripe_dir": "h|v",
                      "label": "text", "speaks": true}],
      "props": [{"shape": "...", "color": "#hex", "x": 0, "y": 0, "z": 0, "scale": 1}]
  }]
}

Re-running skips frames that already exist (resumable).
"""
import json
import math
import os
import sys

import bpy
from mathutils import Vector

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import scene_objects  # noqa: E402


def hex_rgba(value, default=(0.8, 0.8, 0.8, 1.0)):
    try:
        v = str(value).lstrip("#")
        if len(v) != 6:
            return default
        r, g, b = (int(v[i:i + 2], 16) / 255.0 for i in (0, 2, 4))
        # sRGB -> linear
        lin = lambda c: c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4
        return (lin(r), lin(g), lin(b), 1.0)
    except Exception:
        return default


def reset_scene():
    bpy.ops.wm.read_factory_settings(use_empty=True)
    scene = bpy.context.scene
    return scene


def set_engine(scene):
    for name in ("BLENDER_EEVEE", "BLENDER_EEVEE_NEXT", "BLENDER_WORKBENCH"):
        try:
            scene.render.engine = name
            return name
        except TypeError:
            continue
    return scene.render.engine


def make_material(name, color, rough=0.45, emission=0.0):
    mat = bpy.data.materials.new(name)
    mat.use_nodes = True
    bsdf = mat.node_tree.nodes.get("Principled BSDF")
    bsdf.inputs["Base Color"].default_value = color
    bsdf.inputs["Roughness"].default_value = rough
    if emission > 0:
        for key in ("Emission Color", "Emission"):
            if key in bsdf.inputs:
                bsdf.inputs[key].default_value = color
                break
        if "Emission Strength" in bsdf.inputs:
            bsdf.inputs["Emission Strength"].default_value = emission
    return mat


def stripe_material(name, colors, direction):
    mat = bpy.data.materials.new(name)
    mat.use_nodes = True
    nt = mat.node_tree
    bsdf = nt.nodes.get("Principled BSDF")
    bsdf.inputs["Roughness"].default_value = 0.5
    coord = nt.nodes.new("ShaderNodeTexCoord")
    sep = nt.nodes.new("ShaderNodeSeparateXYZ")
    ramp = nt.nodes.new("ShaderNodeValToRGB")
    ramp.color_ramp.interpolation = "CONSTANT"
    n = len(colors)
    while len(ramp.color_ramp.elements) < n:
        ramp.color_ramp.elements.new(0.5)
    for i, el in enumerate(ramp.color_ramp.elements):
        el.position = i / n if i else 0.0
        el.color = hex_rgba(colors[min(i, n - 1)])
    nt.links.new(coord.outputs["Generated"], sep.inputs["Vector"])
    # top-to-bottom stripes use Z (up axis of a rotated plane), vertical use X
    nt.links.new(sep.outputs["Z" if direction == "h" else "X"], ramp.inputs["Fac"])
    nt.links.new(ramp.outputs["Color"], bsdf.inputs["Base Color"])
    return mat


def add_shape(shape, location, scale=1.0):
    if shape == "cube":
        bpy.ops.mesh.primitive_cube_add(size=1.6 * scale, location=location)
        bpy.ops.object.shade_smooth()
        bevel = bpy.context.object.modifiers.new("bevel", "BEVEL")
        bevel.width = 0.18 * scale
        bevel.segments = 4
    elif shape == "cone":
        bpy.ops.mesh.primitive_cone_add(radius1=0.95 * scale, depth=1.9 * scale, location=location)
        bpy.ops.object.shade_smooth()
    elif shape == "capsule":
        bpy.ops.mesh.primitive_uv_sphere_add(radius=0.85 * scale, location=location)
        bpy.context.object.scale = (1, 1, 1.45)
        bpy.ops.object.shade_smooth()
    else:
        bpy.ops.mesh.primitive_uv_sphere_add(radius=1.0 * scale, location=location, segments=48, ring_count=24)
        bpy.ops.object.shade_smooth()
    return bpy.context.object


def build_background(scene_spec, scene):
    c1 = hex_rgba(scene_spec.get("bg", ["#0b1030", "#2a1b5e"])[0])
    c2 = hex_rgba(scene_spec.get("bg", ["#0b1030", "#2a1b5e"])[-1])
    world = bpy.data.worlds.new("World")
    world.use_nodes = True
    bg = world.node_tree.nodes.get("Background")
    bg.inputs["Color"].default_value = c1
    bg.inputs["Strength"].default_value = 0.6
    scene.world = world

    # gradient backdrop wall + floor
    bpy.ops.mesh.primitive_plane_add(size=40, location=(0, 6, 4))
    wall = bpy.context.object
    wall.rotation_euler = (math.radians(90), 0, 0)
    wmat = bpy.data.materials.new("wall")
    wmat.use_nodes = True
    nt = wmat.node_tree
    bsdf = nt.nodes.get("Principled BSDF")
    bsdf.inputs["Roughness"].default_value = 1.0
    coord = nt.nodes.new("ShaderNodeTexCoord")
    sep = nt.nodes.new("ShaderNodeSeparateXYZ")
    ramp = nt.nodes.new("ShaderNodeValToRGB")
    ramp.color_ramp.elements[0].color = c1
    ramp.color_ramp.elements[1].color = c2
    nt.links.new(coord.outputs["Generated"], sep.inputs["Vector"])
    nt.links.new(sep.outputs["Y"], ramp.inputs["Fac"])
    nt.links.new(ramp.outputs["Color"], bsdf.inputs["Base Color"])
    wall.data.materials.append(wmat)

    bpy.ops.mesh.primitive_plane_add(size=40, location=(0, 0, -1.4))
    floor = bpy.context.object
    floor.data.materials.append(make_material("floor", c1, rough=0.95 if scene_spec.get("objects") else 0.25))


def build_lights(scene_spec):
    accent = hex_rgba(scene_spec.get("accent", "#ffb74d"))
    bpy.ops.object.light_add(type="AREA", location=(3, -4, 5))
    key = bpy.context.object
    key.data.energy = 900
    key.data.size = 5
    key.rotation_euler = (math.radians(55), 0, math.radians(25))
    bpy.ops.object.light_add(type="AREA", location=(-4, 2, 3))
    rim = bpy.context.object
    rim.data.energy = 600
    rim.data.size = 4
    rim.data.color = accent[:3]
    rim.rotation_euler = (math.radians(70), 0, math.radians(-110))


def build_character(spec, x, frames, fps, idx):
    shape = spec.get("shape", "sphere")
    body = add_shape(shape, (x, 0, 0.0))
    body.name = f"char{idx}"
    stripes = spec.get("stripes") or []
    if len(stripes) >= 2 and shape in ("cube", "capsule", "sphere"):
        mat = stripe_material(f"flag{idx}", stripes, spec.get("stripe_dir", "h"))
    else:
        mat = make_material(f"body{idx}", hex_rgba(spec.get("color", "#6ee7ff")), rough=0.35)
    body.data.materials.append(mat)

    # eyes
    eye_mat = make_material(f"eye{idx}", (1, 1, 1, 1), rough=0.2)
    pupil_mat = make_material(f"pupil{idx}", (0.02, 0.02, 0.03, 1), rough=0.1)
    for side in (-1, 1):
        bpy.ops.mesh.primitive_uv_sphere_add(radius=0.2, location=(x + side * 0.38, -0.82, 0.38))
        eye = bpy.context.object
        eye.data.materials.append(eye_mat)
        eye.parent = body
        eye.matrix_parent_inverse = body.matrix_world.inverted()
        bpy.ops.mesh.primitive_uv_sphere_add(radius=0.1, location=(x + side * 0.38, -0.99, 0.4))
        pupil = bpy.context.object
        pupil.data.materials.append(pupil_mat)
        pupil.parent = body
        pupil.matrix_parent_inverse = body.matrix_world.inverted()

    # mouth (animated open/close while speaking)
    bpy.ops.mesh.primitive_uv_sphere_add(radius=0.2, location=(x, -0.9, -0.2))
    mouth = bpy.context.object
    mouth.scale = (1.3, 0.35, 0.5)
    mouth.data.materials.append(make_material(f"mouth{idx}", (0.25, 0.02, 0.05, 1), rough=0.6))
    mouth.parent = body
    mouth.matrix_parent_inverse = body.matrix_world.inverted()

    speaks = bool(spec.get("speaks", True))
    phase = idx * 0.9
    for f in range(1, frames + 1, 2):
        t = f / fps
        body.location.z = 0.12 * math.sin(t * 3.0 + phase)
        body.rotation_euler[2] = 0.12 * math.sin(t * 1.7 + phase)
        body.keyframe_insert("location", index=2, frame=f)
        body.keyframe_insert("rotation_euler", index=2, frame=f)
        open_amt = 0.35 + (0.9 * abs(math.sin(t * 9.0 + phase)) if speaks else 0.05)
        mouth.scale = (1.3, 0.35, open_amt * 0.7)
        mouth.keyframe_insert("scale", frame=f)

    label = str(spec.get("label") or "").strip()
    if label:
        add_text(label, (x, -1.2, -1.15), size=0.42, color=(1, 1, 1, 1), extrude=0.04)
    return body


def add_text(text, location, size=0.6, color=(1, 1, 1, 1), extrude=0.06, parent=None):
    bpy.ops.object.text_add(location=location)
    obj = bpy.context.object
    obj.data.body = text
    obj.data.size = size
    obj.data.extrude = extrude
    obj.data.align_x = "CENTER"
    obj.rotation_euler = (math.radians(90), 0, 0)
    obj.data.materials.append(make_material("text", color, rough=0.3, emission=0.4))
    if parent is not None:
        obj.parent = parent
    return obj


def build_camera(scene, scene_spec, frames, count):
    bpy.ops.object.camera_add(location=(0, -7.6, 1.2))
    cam = bpy.context.object
    cam.data.sensor_fit = "HORIZONTAL"
    cam.data.lens = 30
    cam.rotation_euler = (math.radians(86), 0, 0)
    scene.camera = cam
    move = scene_spec.get("camera", "push_in")
    base = Vector((0, -(6.8 if count == 1 else 7.6) - max(0, count - 2) * 1.8, 1.2))
    for f, k in ((1, 0.0), (frames, 1.0)):
        if move == "pull_out":
            pos = base + Vector((0, -2.0 * k + 1.0, 0.1 * k))
        elif move == "orbit":
            ang = math.radians(-18 + 36 * k)
            radius = abs(base.y)
            pos = Vector((math.sin(ang) * radius, -math.cos(ang) * radius, 1.2))
            cam.rotation_euler = (math.radians(86), 0, ang)
            cam.keyframe_insert("rotation_euler", frame=f)
        elif move == "pan":
            pos = base + Vector((-1.5 + 3.0 * k, 0, 0))
        else:  # push_in
            pos = base + Vector((0, 1.2 * k, 0))
        cam.location = pos
        cam.keyframe_insert("location", frame=f)
    return cam


def build_camera_wide(scene, scene_spec, frames):
    bpy.ops.object.camera_add(location=(0, -10.5, 2.6))
    cam = bpy.context.object
    cam.data.sensor_fit = "HORIZONTAL"
    cam.data.lens = 30
    cam.rotation_euler = (math.radians(88), 0, 0)
    scene.camera = cam
    move = scene_spec.get("camera", "push_in")
    for f, k in ((1, 0.0), (frames, 1.0)):
        if move == "pull_out":
            pos = Vector((0, -9.5 - 1.8 * k, 2.6))
        elif move == "pan":
            pos = Vector((-1.5 + 3.0 * k, -10.5, 2.6))
        else:
            pos = Vector((0, -11.5 + 1.8 * k, 2.6))
        cam.location = pos
        cam.keyframe_insert("location", frame=f)


def render_scene(spec, scene_spec):
    scene = reset_scene()
    engine = set_engine(scene)
    fps = int(spec.get("fps", 12))
    frames = max(2, int(scene_spec["frames"]))
    scene.render.resolution_x = int(spec.get("width", 540))
    scene.render.resolution_y = int(spec.get("height", 960))
    scene.render.resolution_percentage = 100
    scene.render.fps = fps
    scene.frame_start, scene.frame_end = 1, frames
    scene.render.image_settings.file_format = "PNG"
    scene.render.use_overwrite = False
    scene.render.use_placeholder = True
    try:
        scene.view_settings.view_transform = "Standard"
    except Exception:
        pass
    if engine.startswith("BLENDER_EEVEE"):
        try:
            scene.eevee.taa_render_samples = int(spec.get("samples", 16))
        except Exception:
            pass

    build_background(scene_spec, scene)
    build_lights(scene_spec)

    if scene_spec.get("objects"):
        ctx = scene_objects.Ctx(make_material, hex_rgba, add_text)
        scene_objects.build_objects(ctx, scene_spec, frames, fps)
        keyword = str(scene_spec.get("title") or "").strip()[:30]
        if keyword:
            size = min(1.1, 7.5 / (max(1, len(keyword)) * 0.55))
            add_text(keyword, (0, -0.5, 6.3), size=size, color=hex_rgba(scene_spec.get("accent", "#ffb74d")), extrude=0.12)
        build_camera_wide(scene, scene_spec, frames)
        out = os.path.join(spec["out_dir"], f"scene_{int(scene_spec['index']):02d}")
        os.makedirs(out, exist_ok=True)
        scene.render.filepath = os.path.join(out, "")
        bpy.ops.render.render(animation=True)
        print(f"RENDERED scene {scene_spec['index']} frames={frames} engine={engine} objects", flush=True)
        return

    characters = (scene_spec.get("characters") or [{"shape": "sphere", "color": "#6ee7ff"}])[:4]
    count = len(characters)
    spacing = 3.2 if count > 1 else 0
    for i, ch in enumerate(characters):
        x = (i - (count - 1) / 2.0) * spacing
        body = build_character(ch, x, frames, fps, i)
        if count == 1:
            body.scale = (1.5, 1.5, 1.5)

    for j, prop in enumerate((scene_spec.get("props") or [])[:6]):
        obj = add_shape(
            prop.get("shape", "sphere"),
            (float(prop.get("x", 0)), float(prop.get("y", 2)), float(prop.get("z", 1.5))),
            float(prop.get("scale", 0.5)),
        )
        obj.data.materials.append(make_material(f"prop{j}", hex_rgba(prop.get("color", "#ffb74d")), rough=0.3, emission=0.15))
        obj.rotation_euler = (0, 0, 0)
        obj.keyframe_insert("rotation_euler", frame=1)
        obj.rotation_euler = (0.5, 0.8, 2.4)
        obj.keyframe_insert("rotation_euler", frame=frames)

    title = str(scene_spec.get("title") or "").strip()
    if title:
        t = title[:40]
        size = min(0.8, 5.2 / (max(1, len(t)) * 0.55))  # lebar teks harus muat di bidang pandang saat kamera mendekat
        add_text(t, (0, -0.5, 3.9), size=size, color=hex_rgba(scene_spec.get("accent", "#ffb74d")))

    build_camera(scene, scene_spec, frames, count)

    out = os.path.join(spec["out_dir"], f"scene_{int(scene_spec['index']):02d}")
    os.makedirs(out, exist_ok=True)
    scene.render.filepath = os.path.join(out, "")
    bpy.ops.render.render(animation=True)
    print(f"RENDERED scene {scene_spec['index']} frames={frames} engine={engine}", flush=True)


def main():
    argv = sys.argv[sys.argv.index("--") + 1:] if "--" in sys.argv else []
    if not argv:
        raise SystemExit("spec.json path required after --")
    with open(argv[0], encoding="utf-8") as fh:
        spec = json.load(fh)
    os.makedirs(spec["out_dir"], exist_ok=True)
    for scene_spec in spec["scenes"]:
        render_scene(spec, scene_spec)
    print("RENDER_DONE", flush=True)


main()
