---
name: dev-log-day
description: Scaffold today's dev_log directory with done/reports/sprints subdirs and an empty status.md. Use when the user says "new dev log", "dev log for today", "/dev-log-day", or asks to set up today's dev log entry.
---

# dev-log-day

Create today's dev_log scaffold for the Mollie payment module.

## Base path

```
/home/dtkachev/osc/strpwt7-nov26/source/extensions/mollie-payment/docs/dev_day_log
```

## What to do

Run this in a single Bash call. It creates the day directory (named `YYYYMMDD` in the user's local timezone), the three subdirectories, and an empty `status.md`. It refuses to clobber an existing `status.md`.

```bash
BASE="/home/dtkachev/osc/strpwt7-nov26/source/extensions/mollie-payment/docs/dev_day_log"
DAY="$(date +%Y%m%d)"
DIR="$BASE/$DAY"
mkdir -p "$DIR/done" "$DIR/reports" "$DIR/sprints"
[ -e "$DIR/status.md" ] || : > "$DIR/status.md"
echo "Created: $DIR"
ls -la "$DIR"
```

## After running

Report the created path in one line. Do not write any content into `status.md` unless the user asks for it.

## Notes

- Date format matches existing entries (`20260629`). Do not switch to `YYYY-MM-DD`.
- If the day directory already exists, the command is idempotent — it only adds missing subdirs and never overwrites `status.md`.
- The path is hardcoded to this repo on purpose; this skill is not portable.
