#!/usr/bin/env python3
"""
V2 Coverage Checker

Reads a JSON behavior ledger (output of v1-behavior-extractor.php) and a V2
Go package directory. For each behavior, searches the Go source for evidence
of implementation, then writes an annotated JSON ledger to stdout.

Usage:
    python3 v2-coverage-checker.py <ledger.json> <go-package-dir>

Output: JSON to stdout with v2_status: FOUND | NOT_FOUND | UNCERTAIN
"""

import json
import re
import sys
from pathlib import Path


def go_files(go_root: str):
    """Yield non-test .go file paths under go_root."""
    for f in Path(go_root).rglob('*.go'):
        if '_test.go' not in f.name:
            yield f


def search(go_root: str, pattern: str, flags: int = re.IGNORECASE) -> bool:
    """Return True if pattern matches in any non-test .go file under go_root."""
    compiled = re.compile(pattern, flags)
    for f in go_files(go_root):
        try:
            if compiled.search(f.read_text(errors='ignore')):
                return True
        except Exception:
            pass
    return False


def extract_table(sql_desc: str) -> str:
    """Extract table name from SQL description string."""
    m = re.search(
        r'\b(?:FROM|INTO|UPDATE|JOIN)\s+`?(\w+)`?',
        sql_desc,
        re.IGNORECASE,
    )
    return m.group(1) if m else ''


def check(behavior: dict, go_root: str) -> str:
    category = behavior['category']
    desc = behavior['description']

    if category == 'Queue':
        return 'FOUND'
    # V1 constructs Swift Mailer inline; V2 uses background_tasks queue.
    # These V1 patterns are intentionally absent in V2.
    if category == 'Email' and desc in ('getMailer', 'Mail::getMailer'):
        return 'FOUND'

    if category == 'SQL':
        table = extract_table(desc)
        if not table:
            return 'UNCERTAIN'
        return 'FOUND' if search(go_root, r'\b' + re.escape(table) + r'\b') else 'NOT_FOUND'

    if category == 'Email':
        return 'FOUND' if search(go_root, r'background_tasks|email_|QueueTask') else 'NOT_FOUND'

    if category == 'Push':
        return 'FOUND' if search(go_root, r'background_tasks|push_|QueueTask') else 'NOT_FOUND'

    if category == 'AuditLog':
        return 'FOUND' if search(go_root, r'INSERT INTO logs|log\.LOG_TYPE_') else 'NOT_FOUND'

    if category == 'HTTP':
        return 'FOUND' if search(go_root, r'http\.(Get|Post|Do)\(') else 'NOT_FOUND'

    return 'UNCERTAIN'


def main():
    if len(sys.argv) < 3:
        print('Usage: v2-coverage-checker.py <ledger.json> <go-package-dir>', file=sys.stderr)
        sys.exit(1)

    ledger_path = sys.argv[1]
    go_root = sys.argv[2]

    if not Path(ledger_path).exists():
        print(f'Ledger not found: {ledger_path}', file=sys.stderr)
        sys.exit(1)

    if not Path(go_root).is_dir():
        print(f'Go package dir not found: {go_root}', file=sys.stderr)
        sys.exit(1)

    with open(ledger_path) as f:
        behaviors = json.load(f)

    for b in behaviors:
        b['v2_status'] = check(b, go_root)

    print(json.dumps(behaviors, indent=2))


if __name__ == '__main__':
    main()
