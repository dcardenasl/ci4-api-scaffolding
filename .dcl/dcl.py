#!/usr/bin/env python3
"""dcl: herramientas del kit de proceso DCL. Solo biblioteca estandar.

Subcomandos:
  doc-check      valida ROADMAP, TASKS, ARCHIVES, planes, ADR y verificaciones
  release-check  valida un tag contra manifiestos, CHANGELOG y rama main
  gate-report    lista gates abiertos con su edad en versiones
  workflow-check verifica que los workflows de GitHub solo corran en PR a main y en tags
  process-init   instala el kit en un proyecto (solo desde el repo dcl-os)

La configuracion vive en .dcl/config.json (generada por process-init desde un perfil)
o se pasa con --config (JSON, o YAML si PyYAML esta disponible).
No modifica nunca documentos del proyecto: solo lee y reporta (salvo process-init, que no pisa archivos).
"""
from __future__ import annotations

import argparse
import fnmatch
import glob
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

DEFAULT_DOC = {
    "roadmap": "ROADMAP.md",
    "tasks": "TASKS.md",
    "archives": "ARCHIVES.md",
    "planes": "docs/plan/*.md",
    "verificaciones": "docs/verification/*.md",
    "adrs": "docs/adr/*.md",
    "primera_version_con_plantilla": 1,
}
DEFAULT_MAIN_BRANCH = "main"
# Eventos que no consumen CI por sí solos: se disparan a mano o los llama otro workflow.
CI_SAFE_EVENTS = {"workflow_dispatch", "workflow_call"}
# Filtros de un evento que el verificador lee; el resto de las claves anidadas (inputs, etc.) se ignora.
TRIGGER_FILTERS = {"branches", "branches-ignore", "tags", "tags-ignore", "paths", "paths-ignore", "types"}
SEP = r"[:\u2014\u2013-]"
VER_RE = re.compile(r"v(\d+)\.(\d+)")
PLAN_SECTIONS = [
    "origen y alcance vigente",
    "contexto observado en el código",
    "decisiones",
    "contrato técnico",
    "matriz de aceptación",
    "tareas y dependencias",
    "riesgos y criterios de cierre",
]
VERIF_SECTIONS = ["suites", "límites y gates pendientes"]
ADR_STATUS = re.compile(
    r"^(proposed|accepted|superseded|propuesto|aceptado|reemplazado)\b", re.I
)
STATE_OK = re.compile(r"^(planificada|publicada v\d+\.\d+\.\d+)$", re.I)


# ----------------------------------------------------------------- utilidades
def load_yaml(text: str):
    """Lee YAML. PyYAML solo hace falta en dcl-os (perfiles y portfolio), nunca en un proyecto instalado."""
    try:
        import yaml  # type: ignore
    except ModuleNotFoundError:
        sys.exit("falta PyYAML. En dcl-os usa el lanzador `./dcl ...` (crea .venv con requirements.txt) "
                 "o ejecuta `scripts/bootstrap.sh`.")
    return yaml.safe_load(text)


def load_config(root: Path, config_path: str | None) -> dict:
    p = Path(config_path) if config_path else root / ".dcl" / "config.json"
    cfg: dict = {}
    if p.exists():
        text = p.read_text(encoding="utf-8")
        if p.suffix in (".yaml", ".yml"):
            cfg = load_yaml(text) or {}
        else:
            cfg = json.loads(text)
    doc = dict(DEFAULT_DOC)
    doc.update(cfg.get("doc_check", {}) or {})
    cfg["doc_check"] = doc
    return cfg


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace") if path.exists() else ""


def slug(heading: str) -> str:
    s = heading.strip().lower()
    s = re.sub(r"[^\w\s-]", "", s, flags=re.UNICODE)
    return re.sub(r"\s", "-", s)


def headings(text: str):
    """Devuelve (nivel, texto) de encabezados fuera de bloques de codigo."""
    out, fence = [], False
    for line in text.splitlines():
        if line.strip().startswith("```"):
            fence = not fence
            continue
        if fence:
            continue
        m = re.match(r"^(#{1,6})\s+(.*?)\s*$", line)
        if m:
            out.append((len(m.group(1)), m.group(2)))
    return out


def strip_comments(text: str) -> str:
    return re.sub(r"<!--.*?-->", "", text, flags=re.S)


def parse_roadmap(text: str):
    """Filas de la tabla de secuencia: [(major, minor, estado, titulo)]."""
    rows = []
    for line in strip_comments(text).splitlines():
        m = re.match(r"^\|\s*v(\d+)\.(\d+)\s*\|(.*)$", line)
        if not m:
            continue
        cells = [c.strip().strip("*").strip() for c in m.group(3).split("|")]
        titulo = cells[0] if cells else ""
        estado = cells[1] if len(cells) > 1 else ""
        rows.append((int(m.group(1)), int(m.group(2)), estado, titulo))
    return rows


def published(rows):
    out = []
    for ma, mi, estado, _ in rows:
        if re.match(r"^publicada\b", estado, re.I):
            out.append((ma, mi))
    return out


class Report:
    def __init__(self):
        self.errors: list[str] = []
        self.warnings: list[str] = []

    def err(self, rule, msg):
        self.errors.append(f"[{rule}] {msg}")

    def warn(self, rule, msg):
        self.warnings.append(f"[{rule}] {msg}")


# ------------------------------------------------------------------ doc-check
SNAPSHOT_SECTIONS = (
    "Dónde quedé", "Próximo paso", "Bloqueos", "Decisiones pendientes de David",
    "Verificado y no verificado", "Comandos para retomar",
)


def doc_check(root: Path, cfg: dict) -> Report:
    d = cfg["doc_check"]
    first = int(d.get("primera_version_con_plantilla", 1))
    rep = Report()

    rm_path = root / d["roadmap"]
    if not rm_path.exists():
        rep.err("R0", f"falta {d['roadmap']}")
        return rep
    rows = parse_roadmap(read(rm_path))
    if not rows:
        rep.err("R1", f"{d['roadmap']}: no se encontró la tabla de secuencia (filas '| vX.Y | ...')")
        return rep

    # R1: tabla consecutiva, estados permitidos, orden publicada/planificada
    seen = set()
    prev = None
    for ma, mi, estado, titulo in rows:
        key = (ma, mi)
        if key in seen:
            rep.err("R1", f"versión repetida en el roadmap: v{ma}.{mi}")
        seen.add(key)
        if not STATE_OK.match(estado):
            rep.err("R1", f"v{ma}.{mi}: estado no permitido '{estado}' (usa Planificada o Publicada vX.Y.Z)")
        if prev and prev[0] == ma and mi != prev[1] + 1 and key not in (prev,):
            rep.err("R1", f"salto en la secuencia: v{prev[0]}.{prev[1]} seguido de v{ma}.{mi}")
        prev = key
    seen_planned = False
    for ma, mi, estado, _ in rows:
        if re.match(r"^planificada", estado, re.I):
            seen_planned = True
        elif re.match(r"^publicada", estado, re.I) and seen_planned:
            rep.err("R1", f"v{ma}.{mi} está Publicada después de una Planificada")

    pub = published(rows)
    pub_set = set(pub)

    # R2: TASKS sin cola de versiones publicadas; IDs unicos
    tasks_text = strip_comments(read(root / d["tasks"]))
    if (root / d["tasks"]).exists():
        for lvl, h in headings(tasks_text):
            m = re.match(r"^v(\d+)\.(\d+)\b", h)
            if m and (int(m.group(1)), int(m.group(2))) in pub_set:
                rep.err("R2", f"{d['tasks']}: la cola de v{m.group(1)}.{m.group(2)} sigue ahí pero está Publicada (debe ir a {d['archives']})")
        ids: dict[str, int] = {}
        id_re = re.compile(r"^\s*- \[[ xX]\] \*\*([A-Za-z][A-Za-z0-9._-]*?)(?:: |\s+[\u2014\u2013-]\s+|\*\*)")
        for line in tasks_text.splitlines():
            m = id_re.match(line)
            if m:
                ids[m.group(1)] = ids.get(m.group(1), 0) + 1
        for i, n in ids.items():
            if n > 1:
                rep.err("R2", f"{d['tasks']}: ID de tarea repetido '{i}' ({n} veces)")
    else:
        rep.warn("R2", f"no existe {d['tasks']}")

    # R3: ARCHIVES con sección por versión publicada e índice válido
    ar_path = root / d["archives"]
    if ar_path.exists():
        ar = strip_comments(read(ar_path))
        hs = headings(ar)
        slugs = {slug(h) for _, h in hs}
        for ma, mi in pub:
            if mi < first and ma == 0:
                continue
            if not any(re.match(rf"^v?{ma}\.{mi}\b", h) for _, h in hs):
                rep.err("R3", f"{d['archives']}: falta la sección de v{ma}.{mi}")
        in_code = False
        for line in ar.splitlines():
            if line.strip().startswith("```"):
                in_code = not in_code
            if in_code:
                continue
            for m in re.finditer(r"\[[^\]]+\]\(#([^)]+)\)", line):
                if m.group(1) not in slugs:
                    rep.err("R3", f"{d['archives']}: el índice enlaza a '#{m.group(1)}' que no existe")
    elif pub:
        rep.err("R3", f"falta {d['archives']} pero hay versiones publicadas")

    # R4: planes y verificaciones
    def version_of(name: str):
        m = VER_RE.search(name)
        return (int(m.group(1)), int(m.group(2))) if m else None

    for f in sorted(glob.glob(str(root / d["planes"]))):
        v = version_of(Path(f).name)
        if not v or (v[0] == 0 and v[1] < first):
            continue
        hs = [h.lower() for _, h in headings(strip_comments(read(Path(f))))]
        for sec in PLAN_SECTIONS:
            if not any(h.startswith(sec) for h in hs):
                rep.err("R4", f"{Path(f).name}: falta la sección '{sec}'")
    for f in sorted(glob.glob(str(root / d["verificaciones"]), recursive=True)):
        # Las verificaciones por versión viven en subdirectorios vX.Y/ y pueden
        # tener nombres descriptivos sin repetir la versión en el archivo.
        v = version_of(str(Path(f).relative_to(root)))
        if not v or (v[0] == 0 and v[1] < first):
            continue
        hs = [h.lower() for _, h in headings(strip_comments(read(Path(f))))]
        for sec in VERIF_SECTIONS:
            if not any(h.startswith(sec) for h in hs):
                rep.err("R4", f"{Path(f).name}: falta la sección '{sec}'")

    # R5: ADR con estado permitido
    for f in sorted(glob.glob(str(root / d["adrs"]))):
        name = Path(f).name
        if not re.match(r"^\d{4}", name):
            continue
        lines = strip_comments(read(Path(f))).splitlines()
        status = None
        for i, line in enumerate(lines):
            if re.match(r"^#{1,3}\s+(status|estado)\s*$", line, re.I):
                for nxt in lines[i + 1:]:
                    if nxt.strip():
                        status = nxt.strip().lstrip("*-_ ").strip()
                        break
                break
        if status is None:  # forma en línea: "- Status: accepted" (cabecera del ADR, primeras 20 líneas)
            for line in lines[:20]:
                m = re.match(r"^\s*(?:[-*]\s*)?\**(?:status|estado)\**\s*:\s*\**\s*(.+?)\s*$", line, re.I)
                if m:
                    status = m.group(1).lstrip("*-_ ").strip()
                    break
        if status is None:
            rep.err("R5", f"{name}: no tiene sección Status/Estado")
        elif not ADR_STATUS.match(status):
            rep.err("R5", f"{name}: estado no permitido '{status[:60]}'")

    # R6 (opcional): README con una sola línea 'Latest'
    if cfg.get("readme_latest") and (root / "README.md").exists():
        n = len(re.findall(r"^##\s+Latest\b", read(root / "README.md"), re.M))
        if n != 1:
            rep.err("R6", f"README.md: se esperaba una sola sección '## Latest', hay {n}")

    # R7: SNAPSHOT.md, si existe, con las secciones del contrato (K2-D17)
    if (root / "SNAPSHOT.md").exists():
        titulos = {m.group(1).strip().lower() for m in re.finditer(r"^#{1,4}\s+(.+)$", read(root / "SNAPSHOT.md"), re.M)}
        for sec in SNAPSHOT_SECTIONS:
            if sec.lower() not in titulos:
                rep.err("R7", f"SNAPSHOT.md: falta la sección '{sec}'")

    return rep


# ---------------------------------------------------------------- gate-report
def gate_report(root: Path, cfg: dict, max_age: int = 2):
    d = cfg["doc_check"]
    rows = parse_roadmap(read(root / d["roadmap"]))
    pub = published(rows)
    latest = max((mi for ma, mi in pub), default=None)
    text = strip_comments(read(root / d["tasks"]))
    blocks, cur = [], []
    for line in text.splitlines():
        if re.match(r"^\s*- \[[ xX]\] ", line):
            if cur:
                blocks.append(cur)
            cur = [line]
        elif cur and (line.startswith(" ") or not line.strip()):
            cur.append(line)
        else:
            if cur:
                blocks.append(cur)
            cur = []
    if cur:
        blocks.append(cur)
    gates = []
    for b in blocks:
        body = "\n".join(b)
        if "Pendiente, sin evidencia" not in body:
            continue
        m = re.match(r"^\s*- \[[ xX]\] \*\*([A-Za-z][A-Za-z0-9._-]*)", b[0])
        gid = m.group(1) if m else "?"
        ver = None
        mv = re.match(r"^V(\d+)-VERIFY$", gid)
        if mv:
            ver = int(mv.group(1))
        else:
            vs = [int(x.group(2)) for x in VER_RE.finditer(body)]
            if vs:
                ver = min(vs)
        age = (latest - ver) if (latest is not None and ver is not None) else None
        gates.append({"id": gid, "version_minor": ver, "age": age,
                      "vencido": age is not None and age > max_age})
    return {"ultima_publicada_minor": latest, "max_age": max_age, "gates": gates}


# -------------------------------------------------------------- release-check
def _read_manifest_value(path: Path, tipo: str, clave: str):
    text = read(path)
    if not text:
        return None
    if tipo == "json":
        data = json.loads(text)
        for part in clave.split("."):
            data = data.get(part) if isinstance(data, dict) else None
        return data
    # toml
    try:
        import tomllib  # type: ignore

        data = tomllib.loads(text)
        for part in clave.split("."):
            data = data.get(part) if isinstance(data, dict) else None
        return data
    except ImportError:
        pass
    *sec, key = clave.split(".")
    header = "[" + ".".join(sec) + "]" if sec else None
    in_sec = header is None
    for line in text.splitlines():
        s = line.strip()
        if s.startswith("["):
            in_sec = (s == header) if header else False
            continue
        if in_sec:
            m = re.match(rf'^{re.escape(key)}\s*=\s*"([^"]+)"', s)
            if m:
                return m.group(1)
    return None


def _release_check_base_version(root: Path, version: str, rep: Report) -> None:
    """Versionado de proyectos derivados: BASE_VERSION es una dependencia (tag y SHA completo de la base),
    nunca la versión del proyecto. Solo avisa: los proyectos existentes se normalizan por tarea aparte."""
    bv = root / "BASE_VERSION"
    if not bv.is_file():
        return
    claves = {}
    for linea in read(bv).splitlines():
        linea = linea.strip()
        if linea and not linea.startswith(("#", ";")) and "=" in linea:
            k, v = linea.split("=", 1)
            claves[k.strip()] = v.strip()
    # `tag=` solo si la copia es exactamente ese tag. Un snapshot posterior declara `after_tag=` (el último tag
    # publicado del que desciende) y `commits_after=`. Las copias antiguas (schema=1) guardan el tag en `version=`.
    estable = r"v\d+\.\d+\.\d+"
    version_legada = claves.get("version", "") if re.fullmatch(estable, claves.get("version", "")) else ""
    tag_base = claves.get("tag") or claves.get("after_tag") or version_legada
    if not re.fullmatch(estable, tag_base):
        rep.warn("RC4", f"BASE_VERSION no registra un tag estable vX.Y.Z de la base (tag={claves.get('tag') or 'vacío'}, "
                        f"after_tag={claves.get('after_tag') or 'vacío'}); un derivado declara la base como dependencia con tag y SHA completo")
    if not re.fullmatch(r"[0-9a-f]{40}", claves.get("commit", "")):
        rep.warn("RC4", "BASE_VERSION no registra el SHA completo de la base (commit=<40 hex>)")
    if re.fullmatch(estable, tag_base) and tag_base.lstrip("v") == version:
        rep.warn("RC5", f"la versión del proyecto ({version}) es la misma que el tag de su base ({tag_base}): "
                        "son ejes distintos y comparten número; el proyecto lleva su propio SemVer")


def release_check(root: Path, cfg: dict, tag: str, skip_git: bool = False) -> Report:
    rep = Report()
    version = tag[1:] if tag.startswith("v") else tag
    for man in cfg.get("manifiestos", []) or []:
        p = root / man["archivo"]
        if not p.exists():
            if man.get("opcional"):
                continue
            rep.err("RC1", f"falta el manifiesto {man['archivo']}")
            continue
        val = _read_manifest_value(p, man["tipo"], man["clave"])
        if val is None:
            if not man.get("opcional"):
                rep.err("RC1", f"{man['archivo']}: no se encontró {man['clave']}")
        elif val != version:
            rep.err("RC1", f"{man['archivo']}: {man['clave']} es {val}, el tag pide {version}")
    cl = read(root / cfg.get("changelog", "CHANGELOG.md"))
    m = re.search(rf"^## \[{re.escape(version)}\][^\n]*\n(.*?)(?=^## \[|\Z)", cl, re.M | re.S)
    if not m:
        rep.err("RC2", f"CHANGELOG: no existe la sección ## [{version}]")
    elif not m.group(1).strip():
        rep.err("RC2", f"CHANGELOG: la sección [{version}] está vacía")
    _release_check_base_version(root, version, rep)
    if not skip_git:
        try:
            r = subprocess.run(["git", "-C", str(root), "merge-base", "--is-ancestor", tag, "main"],
                               capture_output=True, text=True)
            if r.returncode == 1:
                rep.err("RC3", f"el tag {tag} no es ancestro de main")
            elif r.returncode > 1:
                rep.warn("RC3", f"no se pudo comprobar el tag contra main: {r.stderr.strip()[:80]}")
        except FileNotFoundError:
            rep.warn("RC3", "git no disponible")
    return rep


# --------------------------------------------------------------- process-init
def process_init(kit: Path, profile_name: str, target: Path, project: str, today: str, hooks: bool = True, protocols: bool = True, lite: bool = False):
    if lite:  # adopción-lite: solo documentos de estado y configuración; sin hooks, protocolos, CI ni CLAUDE.md
        hooks = protocols = False
    prof_file = kit / "perfiles" / f"{profile_name}.yaml"
    if not prof_file.exists():
        sys.exit(f"perfil no encontrado: {prof_file}")
    prof = load_yaml(prof_file.read_text(encoding="utf-8"))
    created, skipped = [], []

    def put(rel: str, content: str, mode: int | None = None):
        p = target / rel
        if p.exists():
            skipped.append(rel)
            return
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(content, encoding="utf-8")
        if mode:
            p.chmod(mode)
        created.append(rel)

    subs = {"{{PROYECTO}}": project, "{{FECHA}}": today, "{{ULTIMA_PUBLICADA}}": "ninguna",
            "{{DONDE_QUEDE}}": "Proyecto recién incorporado al kit; sin trabajo de sesión todavía.",
            "{{PROXIMO_PASO}}": "Definir la primera entrega con David (ROADMAP.md).",
            "{{PROXIMA}}": "v0.1", "{{PRIMERA_ENTREGA}}": "(por definir)",
            "{{DESCRIPCION_UNA_LINEA}}": "(describe el proyecto en una línea)",
            "{{COMANDOS_DEL_PERFIL}}": "\n".join(f"- {k}: `{v}`" for k, v in (prof.get("comandos") or {}).items() if v)}

    def render(text: str) -> str:
        for k, v in subs.items():
            text = text.replace(k, v)
        return text

    docs = ["ROADMAP.md", "TASKS.md", "ARCHIVES.md"] if lite else ["ROADMAP.md", "TASKS.md", "ARCHIVES.md", "CHANGELOG.md", "CLAUDE.md"]
    for name in docs:
        put(name, render(read(kit / "plantillas" / name)))
    put("SNAPSHOT.md", render(read(kit / "plantillas" / "SNAPSHOT.md")))  # K2-D17
    if protocols:  # --no-protocols: el proyecto ya tiene sus propios protocolos de trabajo
        for pf in sorted((kit / "protocolos").glob("*.md")):
            put(f"docs/protocols/{pf.name}", read(pf))
    for sub in (() if lite else ("plan", "adr", "verification")):
        put(f"docs/{sub}/.gitkeep", "")
    if hooks:  # --no-hooks: el proyecto ya gestiona sus propios hooks y no se instalan los del kit
        for h in prof.get("hooks", []):
            put(f".dcl/hooks/{h}", read(kit / "hooks" / h), 0o755)
        put(".dcl/install-git-hooks.sh", read(kit / "hooks" / "install-git-hooks.sh"), 0o755)
    put(".dcl/dcl.py", read(kit / "dcl.py"), 0o755)
    cfg = {k: prof[k] for k in ("nombre", "doc_check", "manifiestos", "comandos", "changelog", "readme_latest") if k in prof}
    cfg["kit_version"] = (kit / "VERSION").read_text(encoding="utf-8").strip()  # K2-D18
    put(".dcl/config.json", json.dumps(cfg, indent=2, ensure_ascii=False) + "\n")
    for wf in ([] if lite else prof.get("workflows", [])):
        src = kit / "workflows" / profile_name / wf
        if src.exists():
            put(f".github/workflows/{wf}", read(src))
    return created, skipped


# ------------------------------------------------------------- workflow-check
def _flow_list(text: str) -> list[str]:
    inner = text.strip()
    if inner.startswith("["):
        inner = inner[1:inner.rindex("]")] if "]" in inner else inner[1:]
    return [x.strip().strip("'\"") for x in inner.split(",") if x.strip()]


def workflow_triggers(text: str):
    """Eventos de `on:` de un workflow como {evento: {filtro: [valores]}}, o None si la forma no se puede leer.

    Solo biblioteca estandar: cubre las formas habituales (escalar, lista, mapa en bloque y filtros
    en linea o en bloque). Una forma que no entiende devuelve None y el verificador la reporta."""
    lines = []
    for raw in text.splitlines():
        code = re.sub(r"(^|\s)#.*$", "", raw).rstrip()
        if code.strip():
            lines.append(code)
    start = next((i for i, ln in enumerate(lines) if re.match(r"""^(on|"on"|'on')\s*:""", ln)), None)
    if start is None:
        return None
    head = re.sub(r"""^(on|"on"|'on')\s*:\s*""", "", lines[start])
    if head:
        if head.startswith("{"):
            return None
        return {e: {} for e in _flow_list(head)}
    block = []
    for ln in lines[start + 1:]:
        if not ln.startswith((" ", "\t")):
            break
        block.append(ln)
    if not block:
        return None
    indent = len(block[0]) - len(block[0].lstrip())
    events: dict[str, dict] = {}
    current = None
    key = None
    for ln in block:
        pad = len(ln) - len(ln.lstrip())
        body = ln.strip()
        if pad == indent:
            if body.startswith("- "):
                events[body[2:].strip()] = {}
                current = None
                continue
            m = re.match(r"^([\w-]+)\s*:\s*(.*)$", body)
            if not m:
                return None
            current, key = m.group(1), None
            events[current] = {}
            if m.group(2) and m.group(2).startswith("{"):
                return None
            continue
        if current is None:
            return None
        if pad > indent and body.startswith("- "):
            if key is None:
                continue
            events[current].setdefault(key, []).append(body[2:].strip().strip("'\""))
            continue
        m = re.match(r"^([\w-]+)\s*:\s*(.*)$", body)
        if m and pad > indent:
            key = m.group(1) if m.group(1) in TRIGGER_FILTERS else None
            if key is None:
                continue
            if m.group(2):
                events[current][key] = _flow_list(m.group(2))
            else:
                events[current].setdefault(key, [])
    return events


def workflow_check(root: Path, cfg: dict) -> Report:
    """Regla 12: el CI de GitHub corre solo en PR a la rama principal y en tags de release."""
    rep = Report()
    main_branch = cfg.get("rama_principal", DEFAULT_MAIN_BRANCH)
    files = sorted(glob.glob(str(root / ".github" / "workflows" / "*.y*ml")))
    for f in files:
        rel = Path(f).relative_to(root).as_posix()
        events = workflow_triggers(read(Path(f)))
        if events is None:
            rep.err("CI", f"{rel}: no pude leer el bloque `on:`; usa una forma simple o revísalo a mano")
            continue
        for event, flt in events.items():
            if event in CI_SAFE_EVENTS:
                continue
            if event == "push":
                if flt.get("branches") or flt.get("branches-ignore") or not flt.get("tags"):
                    rep.err("CI", f"{rel}: `push` a ramas dispara CI (incluida dev y {main_branch}); "
                              "solo se permite `push` con `tags` de release")
            elif event in ("pull_request", "pull_request_target"):
                if flt.get("branches") != [main_branch]:
                    rep.err("CI", f"{rel}: `{event}` debe filtrar `branches: [{main_branch}]` y nada más; "
                              "un PR hacia dev o sin filtro gasta minutos")
            elif event == "schedule":
                rep.warn("CI", f"{rel}: `schedule` consume minutos de forma periódica; requiere decisión de David")
            else:
                rep.warn("CI", f"{rel}: el evento `{event}` no está cubierto por la política de CI")
    return rep


# ----------------------------------------------------------------------- main
def print_report(rep: Report, ok_msg: str) -> int:
    for w in rep.warnings:
        print("aviso", w)
    for e in rep.errors:
        print("error", e)
    if rep.errors:
        print(f"\n{len(rep.errors)} error(es)")
        return 1
    print(ok_msg)
    return 0


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(prog="dcl", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)

    def common(p):
        p.add_argument("--root", default=".")
        p.add_argument("--config", default=None)

    p = sub.add_parser("doc-check"); common(p)
    p = sub.add_parser("release-check"); common(p)
    p.add_argument("tag"); p.add_argument("--skip-git", action="store_true")
    p = sub.add_parser("workflow-check"); common(p)
    p = sub.add_parser("gate-report"); common(p)
    p.add_argument("--max-age", type=int, default=2); p.add_argument("--json", action="store_true")
    p = sub.add_parser("process-init")
    p.add_argument("--profile", required=True); p.add_argument("--target", required=True)
    p.add_argument("--name", required=True); p.add_argument("--kit", default=str(Path(__file__).parent))
    p.add_argument("--date", default=None)
    p.add_argument("--no-protocols", action="store_true", help="no copia los protocolos del kit (el proyecto tiene los suyos)")
    p.add_argument("--no-hooks", action="store_true", help="no instala los hooks del kit (el proyecto gestiona los suyos)")
    p.add_argument("--lite", action="store_true", help="adopción-lite: solo ROADMAP, TASKS, ARCHIVES, SNAPSHOT y .dcl/; sin hooks, protocolos ni CI")

    a = ap.parse_args(argv)
    if a.cmd == "process-init":
        import datetime
        today = a.date or datetime.date.today().isoformat()
        created, skipped = process_init(Path(a.kit), a.profile, Path(a.target), a.name, today, hooks=not a.no_hooks, protocols=not a.no_protocols, lite=a.lite)
        print(f"creados: {len(created)}")
        for c in created:
            print("  +", c)
        for s in skipped:
            print("  = ya existía, no se tocó:", s)
        return 0

    root = Path(a.root).resolve()
    cfg = load_config(root, a.config)
    if a.cmd == "doc-check":
        return print_report(doc_check(root, cfg), "doc-check: todo en orden")
    if a.cmd == "workflow-check":
        return print_report(workflow_check(root, cfg), "workflow-check: el CI solo corre en PR a main y en tags")
    if a.cmd == "release-check":
        return print_report(release_check(root, cfg, a.tag, a.skip_git), f"release-check {a.tag}: todo en orden")
    if a.cmd == "gate-report":
        rpt = gate_report(root, cfg, a.max_age)
        if a.json:
            print(json.dumps(rpt, indent=2, ensure_ascii=False))
            return 0
        ultima = "ninguna" if rpt["ultima_publicada_minor"] is None else f"0.{rpt['ultima_publicada_minor']}"
        print(f"Última versión publicada: {ultima}  (umbral: {rpt['max_age']} versiones)")
        for g in rpt["gates"]:
            edad = "edad desconocida" if g["age"] is None else f"{g['age']} versiones"
            flag = "  VENCIDO: decisión de David pendiente" if g["vencido"] else ""
            print(f"- {g['id']}: {edad}{flag}")
        if not rpt["gates"]:
            print("Sin gates abiertos.")
        return 0
    return 2


if __name__ == "__main__":
    sys.exit(main())
