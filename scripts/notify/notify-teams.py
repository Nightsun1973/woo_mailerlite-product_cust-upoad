#!/usr/bin/env python3
"""Post a project attention alert to Microsoft Teams (Workflows webhook).

C13: send ONLY when Lee's involvement is needed (approval, decision, failure).
Do not send FYI-only "task done" or mid-task question alerts.

Teams Workflows require an Adaptive Card or MessageCard body (POST, max 256 KB).

Config (first match wins):
  1. --webhook URL
  2. TEAMS_NOTIFY_WEBHOOK_URL already in the process environment
  3. Project .env
  4. Central secrets: USERPROFILE/.cursor/secrets/chameleon-teams-notify.env
     (shared personal chat webhook for all projects)
  5. Project teams-notify.json → webhook_url (path may vary by project)

Exit codes:
  0 — sent, or skipped because notify is disabled / no webhook (non-fatal)
  1 — send attempted and failed
  2 — bad arguments
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
CONFIG_PATH = ROOT / "config" / "portal" / "teams-notify.json"
ENV_PATH = ROOT / ".env"
CENTRAL_TEAMS_ENV = (
    Path(os.environ.get("USERPROFILE", "")) / ".cursor" / "secrets" / "chameleon-teams-notify.env"
)

KINDS = ("question", "sign_off", "complete", "failure")

KIND_LABEL = {
    "question": "Decision needed",
    "sign_off": "Sign-off needed",
    "complete": "Waiting on you",
    "failure": "Failure — attention needed",
}

KIND_COLOUR = {
    "question": "FFC107",
    "sign_off": "003399",
    "complete": "2E7D32",
    "failure": "C62828",
}

DEFAULT_PROJECT_NAME = "user-reports"
DEFAULT_SENDER_LABEL = "Cursor — user-reports"
DEFAULT_REPO_URL = "https://github.com/Nightsun1973/user-reports"


def _load_dotenv(path: Path) -> None:
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip().strip("'").strip('"')
        if key and key not in os.environ:
            os.environ[key] = value


def _load_config() -> dict:
    if not CONFIG_PATH.is_file():
        return {}
    try:
        data = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        print(f"notify-teams: invalid JSON in {CONFIG_PATH}: {exc}", file=sys.stderr)
        return {}
    return data if isinstance(data, dict) else {}


def resolve_webhook(cli_url: str | None) -> tuple[str | None, str]:
    if cli_url and cli_url.strip():
        return cli_url.strip(), "cli"
    env = os.environ.get("TEAMS_NOTIFY_WEBHOOK_URL", "").strip()
    if env:
        return env, "env"
    cfg = _load_config()
    if cfg.get("enabled") is False:
        return None, "disabled"
    url = str(cfg.get("webhook_url") or "").strip()
    if url:
        return url, "config"
    return None, "missing"


def _cursor_open_url(project_path: str) -> str:
    """Build a cursor://file/… deep link for the project folder."""
    path = Path(project_path).expanduser().resolve()
    # URI path: forward slashes; keep drive letter (C:/…)
    as_uri_path = path.as_posix()
    if not as_uri_path.startswith("/"):
        as_uri_path = "/" + as_uri_path
    return "cursor://file" + urllib.parse.quote(as_uri_path, safe="/:")


def project_branding(cfg: dict) -> dict[str, str]:
    """Sender label + links shown on the card (Teams bot name cannot be renamed)."""
    project_name = str(cfg.get("project_name") or DEFAULT_PROJECT_NAME).strip()
    sender_label = str(cfg.get("sender_label") or f"Cursor — {project_name}").strip()
    repo_url = str(cfg.get("repo_url") or DEFAULT_REPO_URL).strip()
    project_path = str(cfg.get("project_path") or str(ROOT)).strip()
    open_url = str(cfg.get("project_open_url") or "").strip()
    if not open_url:
        open_url = _cursor_open_url(project_path)
    return {
        "project_name": project_name,
        "sender_label": sender_label,
        "repo_url": repo_url,
        "open_url": open_url,
    }


def build_adaptive_payload(
    kind: str, title: str, body: str, reply_hint: str, brand: dict[str, str]
) -> dict:
    label = KIND_LABEL.get(kind, kind)
    facts = [
        {"title": "Kind", "value": label},
        {"title": "Project", "value": brand["project_name"]},
        {"title": "From", "value": brand["sender_label"]},
    ]
    if reply_hint:
        facts.append({"title": "Suggested reply", "value": reply_hint})

    card_body: list[dict[str, Any]] = [
        {
            "type": "TextBlock",
            "size": "Small",
            "weight": "Bolder",
            "color": "Accent",
            "text": brand["sender_label"],
            "wrap": True,
        },
        {
            "type": "TextBlock",
            "size": "Medium",
            "weight": "Bolder",
            "text": f"{label}: {title}",
            "wrap": True,
            "spacing": "Small",
        },
        {
            "type": "TextBlock",
            "text": body,
            "wrap": True,
        },
        {
            "type": "TextBlock",
            "size": "Small",
            "wrap": True,
            "text": f"Project: [{brand['project_name']}]({brand['open_url']})",
        },
        {
            "type": "FactSet",
            "facts": facts,
        },
    ]

    actions: list[dict[str, Any]] = [
        {
            "type": "Action.OpenUrl",
            "title": f"Open {brand['project_name']} in Cursor",
            "url": brand["open_url"],
        }
    ]
    if brand["repo_url"]:
        actions.append(
            {
                "type": "Action.OpenUrl",
                "title": "Open on GitHub",
                "url": brand["repo_url"],
            }
        )

    return {
        "type": "message",
        "attachments": [
            {
                "contentType": "application/vnd.microsoft.card.adaptive",
                "content": {
                    "$schema": "http://adaptivecards.io/schemas/adaptive-card.json",
                    "type": "AdaptiveCard",
                    "version": "1.4",
                    "body": card_body,
                    "actions": actions,
                },
            }
        ],
    }


def build_messagecard_payload(
    kind: str, title: str, body: str, reply_hint: str, brand: dict[str, str]
) -> dict:
    label = KIND_LABEL.get(kind, kind)
    facts = [
        {"name": "From", "value": brand["sender_label"]},
        {"name": "Kind", "value": label},
        {
            "name": "Project",
            "value": f"[{brand['project_name']}]({brand['open_url']})",
        },
    ]
    if reply_hint:
        facts.append({"name": "Suggested reply", "value": f"`{reply_hint}`"})

    return {
        "@type": "MessageCard",
        "@context": "https://schema.org/extensions",
        "themeColor": KIND_COLOUR.get(kind, "003399"),
        "summary": f"[{brand['sender_label']}] {label}: {title}",
        "sections": [
            {
                "activityTitle": f"{label}: {title}",
                "activitySubtitle": brand["sender_label"],
                "text": body,
                "facts": facts,
                "markdown": True,
            }
        ],
        "potentialAction": [
            {
                "@type": "OpenUri",
                "name": f"Open {brand['project_name']} in Cursor",
                "targets": [{"os": "default", "uri": brand["open_url"]}],
            },
            {
                "@type": "OpenUri",
                "name": "Open on GitHub",
                "targets": [{"os": "default", "uri": brand["repo_url"]}],
            },
        ],
    }


def build_payload(
    kind: str, title: str, body: str, reply_hint: str, fmt: str, brand: dict[str, str]
) -> dict:
    if fmt == "messagecard":
        return build_messagecard_payload(kind, title, body, reply_hint, brand)
    return build_adaptive_payload(kind, title, body, reply_hint, brand)


def post_webhook(url: str, payload: dict, timeout: float = 20.0) -> str:
    data = json.dumps(payload).encode("utf-8")
    if len(data) > 256 * 1024:
        raise RuntimeError(f"payload too large ({len(data)} bytes; max 256 KB)")
    req = urllib.request.Request(
        url,
        data=data,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8", errors="replace")
        if resp.status >= 400:
            raise RuntimeError(f"HTTP {resp.status}: {raw[:500]}")
        return raw.strip() or f"HTTP {resp.status}"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Send a Teams attention alert (C13: only after task finish or failure)."
    )
    parser.add_argument("--kind", required=True, choices=KINDS)
    parser.add_argument("--title", required=True, help="Short headline")
    parser.add_argument("--body", required=True, help="What Lee needs to know or decide")
    parser.add_argument("--reply", default="", help="Optional suggested chat reply")
    parser.add_argument("--webhook", default="", help="Override webhook URL")
    parser.add_argument(
        "--format",
        choices=("adaptive", "messagecard"),
        default="adaptive",
        help="Card format (default: adaptive)",
    )
    parser.add_argument("--dry-run", action="store_true", help="Print payload JSON; do not POST")
    args = parser.parse_args(argv)

    if args.kind == "question":
        print(
            "notify-teams: WARNING — kind=question is discouraged (C13). "
            "Teams only when Lee's involvement is needed after the agent stopped "
            "(sign_off, failure, or actionable complete). Mid-task chat stays in Cursor.",
            file=sys.stderr,
        )

    _load_dotenv(ENV_PATH)
    _load_dotenv(CENTRAL_TEAMS_ENV)
    webhook, source = resolve_webhook(args.webhook or None)
    cfg = _load_config()
    brand = project_branding(cfg)

    fmt = str(cfg.get("format") or args.format).strip().lower()
    if fmt not in ("adaptive", "messagecard"):
        fmt = "adaptive"
    if argv is None:
        argv = sys.argv[1:]
    if any(a == "--format" or a.startswith("--format=") for a in argv):
        fmt = args.format

    payload = build_payload(
        args.kind, args.title.strip(), args.body.strip(), args.reply.strip(), fmt, brand
    )

    if args.dry_run:
        print(
            json.dumps(
                {"webhook_source": source, "format": fmt, "brand": brand, "payload": payload},
                indent=2,
            )
        )
        return 0

    if not webhook:
        print(
            f"notify-teams: skipped ({source}) — set config/portal/teams-notify.json "
            "or TEAMS_NOTIFY_WEBHOOK_URL",
            file=sys.stderr,
        )
        return 0

    try:
        detail = post_webhook(webhook, payload)
    except urllib.error.HTTPError as exc:
        err_body = exc.read().decode("utf-8", errors="replace")[:500]
        print(f"notify-teams: send failed ({source}): HTTP {exc.code}: {err_body}", file=sys.stderr)
        return 1
    except (urllib.error.URLError, TimeoutError, RuntimeError, OSError) as exc:
        print(f"notify-teams: send failed ({source}): {exc}", file=sys.stderr)
        return 1

    print(f"notify-teams: sent ({args.kind}, {fmt}) via {source} — {detail}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
