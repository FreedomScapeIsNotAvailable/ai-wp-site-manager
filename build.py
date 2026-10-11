#!/usr/bin/env python3
"""
Build dist/ai-wp-site-manager.zip and dist/manifest.json from ai-wp-site-manager.php.

Release procedure:
  1. Edit ai-wp-site-manager.php: bump `Version:` in the header AND the CSM_VERSION constant.
  2. Add a line under the new version in CHANGELOG.md.
  3. python build.py
  4. git add -A && git commit -m "Release x.y.z" && git push
WordPress sites pick the new version up within six hours (or immediately via
Dashboard -> Updates -> "Check again", or the connector's check_updates tool).
"""
import json
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PLUGIN = ROOT / "ai-wp-site-manager.php"
DIST = ROOT / "dist"
RAW_BASE = "https://raw.githubusercontent.com/FreedomScapeIsNotAvailable/ai-wp-site-manager/main/dist/"

src = PLUGIN.read_text(encoding="utf-8")


def header(name: str, default: str = "") -> str:
    m = re.search(rf"^\s*\*\s*{re.escape(name)}:\s*(.+?)\s*$", src, re.M)
    return m.group(1) if m else default


version = header("Version")
const = re.search(r"define\(\s*'CSM_VERSION',\s*'([^']+)'", src).group(1)
if version != const:
    raise SystemExit(f"Version mismatch: header says {version}, CSM_VERSION says {const}")

changelog = ""
cl = ROOT / "CHANGELOG.md"
if cl.exists():
    m = re.search(rf"^## {re.escape(version)}.*?$(.*?)(?=^## |\Z)", cl.read_text(encoding="utf-8"), re.M | re.S)
    if m:
        items = [ln.strip()[2:] for ln in m.group(1).splitlines() if ln.strip().startswith("- ")]
        changelog = f"<h4>{version}</h4><ul>" + "".join(f"<li>{i}</li>" for i in items) + "</ul>"

DIST.mkdir(exist_ok=True)
zip_path = DIST / "ai-wp-site-manager.zip"
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
    z.write(PLUGIN, "ai-wp-site-manager/ai-wp-site-manager.php")

manifest = {
    "name": "AI WP Site Manager",
    "slug": "ai-wp-site-manager",
    "version": version,
    "package": RAW_BASE + "ai-wp-site-manager.zip",
    "requires": header("Requires at least", "5.8"),
    "requires_php": header("Requires PHP", "7.4"),
    "tested": header("Tested up to", ""),
    "changelog": changelog,
}
(DIST / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
print(f"built {zip_path.name} and manifest.json for version {version}")
