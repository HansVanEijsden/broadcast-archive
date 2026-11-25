#!/usr/bin/env python3
"""Maintain the plugin's gettext catalog.

Usage:
  python3 tools/i18n.py pot                  # refresh languages/broadcast-archive.pot
  python3 tools/i18n.py po nl_NL             # refresh the .po for a locale
  python3 tools/i18n.py mo nl_NL             # compile the .po to .mo
  python3 tools/i18n.py all nl_NL            # pot + po + mo

Only strings passed with the text domain `broadcast-archive` are collected.
Existing translations are preserved; new strings are added empty.
"""

from __future__ import annotations

import ast
import re
import struct
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = "broadcast-archive"
SOURCE_DIRS = [ROOT / "broadcast-archive.php", ROOT / "includes", ROOT / "uninstall.php"]
FUNCTIONS = {
    "__",
    "_e",
    "esc_html__",
    "esc_attr__",
    "esc_html_e",
    "esc_attr_e",
    "esc_html_x",
    "_x",
}

CALL_RE = re.compile(
    r"\b(?P<fn>" + "|".join(sorted(FUNCTIONS, key=len, reverse=True)) + r")\s*\(\s*"
    r"'(?P<text>(?:[^'\\]|\\.)*)'\s*,\s*'"
    r"(?P<domain>[^']+)'"
)

HEADER = """# Copyright (C) 2026 Hans van Eijsden Consultancy
# This file is distributed under the GNU General Public License v2 or later.
msgid ""
msgstr ""
"Project-Id-Version: Broadcast Archive 2.0.0\\n"
"Report-Msgid-Bugs-To: https://github.com/HansVanEijsden/broadcast-archive/issues\\n"
"POT-Creation-Date: {date}\\n"
"PO-Revision-Date: {revision}\\n"
"Last-Translator: {translator}\\n"
"Language-Team: {team}\\n"
"Language: {language}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Generator: tools/i18n.py\\n"
"X-Domain: {domain}\\n"
"""


def source_files() -> list[Path]:
    files: list[Path] = []
    for entry in SOURCE_DIRS:
        if entry.is_file():
            files.append(entry)
        elif entry.is_dir():
            files.extend(sorted(entry.rglob("*.php")))
    return files


def unquote(raw: str) -> str:
    """Turn a PHP single/double quoted body into text."""
    return ast.literal_eval("'" + raw.replace("'", "\\'") + "'") if "\\" in raw else raw


def collect() -> dict[str, list[str]]:
    strings: dict[str, list[str]] = {}

    for path in source_files():
        text = path.read_text(encoding="utf-8")
        for match in CALL_RE.finditer(text):
            if match.group("domain") != DOMAIN:
                continue
            value = unquote(match.group("text"))
            line = text.count("\n", 0, match.start()) + 1
            reference = f"{path.relative_to(ROOT)}:{line}"
            strings.setdefault(value, []).append(reference)

    return strings


def po_escape(value: str) -> str:
    return (
        value.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
    )


def write_pot(strings: dict[str, list[str]], language: str = "") -> Path:
    from datetime import datetime, timezone

    path = ROOT / "languages" / f"{DOMAIN}.pot"
    path.parent.mkdir(parents=True, exist_ok=True)

    lines = [
        HEADER.format(
            date=datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M+0000"),
            language=language,
            domain=DOMAIN,
            revision="YEAR-MO-DA HO:MI+ZONE",
            translator="FULL NAME <EMAIL@ADDRESS>",
            team="LANGUAGE <LL@li.org>",
        ).rstrip("\n")
    ]

    for msgid in sorted(strings, key=str.casefold):
        refs = strings[msgid]
        lines.append("")
        lines.append(f"#: {' '.join(refs)}")
        lines.append(f'msgid "{po_escape(msgid)}"')
        lines.append('msgstr ""')

    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return path


def read_po(path: Path) -> dict[str, str]:
    """Read msgid -> msgstr pairs, handling simple single-line entries."""
    translations: dict[str, str] = {}
    msgid = None
    msgstr = None

    def flush() -> None:
        nonlocal msgid, msgstr
        if msgid:
            translations[msgid] = msgstr or ""
        msgid, msgstr = None, None

    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line:
            flush()
            continue
        if line.startswith("#"):
            continue
        if line.startswith("msgid "):
            flush()
            msgid = ast.literal_eval(line[6:])
        elif line.startswith("msgstr "):
            msgstr = ast.literal_eval(line[7:])

    flush()
    return translations


def write_po(locale: str) -> Path:
    strings = collect()
    path = ROOT / "languages" / f"{DOMAIN}-{locale}.po"
    existing = read_po(path) if path.exists() else {}

    translations_path = ROOT / "tools" / "translations" / f"{locale}.json"
    supplied: dict[str, str] = {}

    if translations_path.exists():
        import json

        supplied = json.loads(translations_path.read_text(encoding="utf-8"))

    language = locale.replace("_", "-")
    lines = [
        HEADER.format(
            date="2026-11-25 00:00+0000",
            language=language,
            domain=DOMAIN,
            revision="2026-11-25 17:00+0100",
            translator="Hans van Eijsden <hans@hansvaneijsden.nl>",
            team="Dutch",
        ).rstrip("\n")
    ]

    missing = 0

    for msgid in sorted(strings, key=str.casefold):
        refs = strings[msgid]
        translation = supplied.get(msgid) or existing.get(msgid, "")
        if not translation:
            missing += 1
        lines.append("")
        lines.append(f"#: {' '.join(refs)}")
        lines.append(f'msgid "{po_escape(msgid)}"')
        lines.append(f'msgstr "{po_escape(translation)}"')

    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"{path.relative_to(ROOT)}: {len(strings)} strings, {missing} untranslated")
    return path


def write_mo(locale: str) -> Path:
    po_path = ROOT / "languages" / f"{DOMAIN}-{locale}.po"
    mo_path = ROOT / "languages" / f"{DOMAIN}-{locale}.mo"
    translations = read_po(po_path)

    mo_header = (
        "Project-Id-Version: Broadcast Archive 2.0.0\n"
        "MIME-Version: 1.0\n"
        "Content-Type: text/plain; charset=UTF-8\n"
        "Content-Transfer-Encoding: 8bit\n"
        "Language: " + locale.replace("_", "-") + "\n"
        "X-Domain: " + DOMAIN + "\n"
    )

    entries = [("", mo_header)] + sorted((k, v) for k, v in translations.items() if v)

    ids = b""
    strs = b""
    offsets = []
    for msgid, msgstr in entries:
        encoded_id = msgid.encode("utf-8")
        encoded_str = msgstr.encode("utf-8")
        offsets.append((len(ids), len(encoded_id), len(strs), len(encoded_str)))
        ids += encoded_id + b"\0"
        strs += encoded_str + b"\0"

    count = len(entries)
    key_start = 7 * 4 + count * 16
    value_start = key_start + len(ids)
    header_size = 7 * 4

    output = struct.pack(
        "<7I",
        0x950412DE,  # magic
        0,          # revision
        count,
        header_size,        # offset of key table
        header_size + count * 8,  # offset of value table
        0,          # hash table size
        0,          # hash table offset
    )

    key_table = b""
    value_table = b""
    for id_off, id_len, str_off, str_len in offsets:
        key_table += struct.pack("<2I", id_len, key_start + id_off)
        value_table += struct.pack("<2I", str_len, value_start + str_off)

    mo_path.write_bytes(output + key_table + value_table + ids + strs)
    print(f"{mo_path.relative_to(ROOT)}: {count} translated strings")
    return mo_path


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    command = sys.argv[1]
    locale = sys.argv[2] if len(sys.argv) > 2 else "nl_NL"

    if command in ("pot", "all"):
        path = write_pot(collect())
        print(f"{path.relative_to(ROOT)}: {len(collect())} strings")
    if command in ("po", "all"):
        write_po(locale)
    if command in ("mo", "all"):
        write_mo(locale)
    if command not in ("pot", "po", "mo", "all"):
        print(f"Unknown command: {command}")
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
