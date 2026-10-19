---
last_reviewed: 2026-10-19
owner: Freegle dev team
covers:
  - paf-extractor/server.mjs
  - paf-extractor/extract.sh
  - paf-extractor/Dockerfile
  - iznik-batch/app/Console/Commands/Paf/ImportRunCommand.php
  - iznik-batch/app/Services/PafService.php
  - iznik-batch/app/Models/PafImport.php
  - iznik-server-go/paf/paf.go
  - iznik-nuxt3/modtools/components/ModSysAdminPaf.vue
  - iznik-server-go/test/paf_test.go
---

# Royal Mail address data (PAF)

The address picker (`paf_addresses` and its ten `paf_*` lookup tables) comes from Royal
Mail's Postcode Address File. We get it from AFD, who publish a new release each month.

## Applying a new release

1. AFD email the board (as "PAF address data <month>") when a release is ready. The email
   has an unlock key, written as eight groups of three, e.g. `7E7 / 3W7 / RRJ / ...`.
2. In ModTools, open SysAdmin, then **Addresses** (Admins only, `?tab=addresses`).
3. Paste the key and press **Download and apply**. Leave **Release** empty for the latest.

It then runs on the server with nobody watching. The tab shows each step and its progress:

| Step | What happens | Time |
|---|---|---|
| Download | `SetupRM.exe` (~420 MB) from AFD's SFTP server | ~2 minutes |
| Unlock | The extractor is run with the key and writes `CSV PAF.csv` (~2.8 GB, 32M lines) | ~6 minutes |
| Compare | Sorted, and compared with the last release applied | ~2 minutes |
| Fetch | The new or changed lines are copied to batch | minutes |
| Apply | Those lines are inserted or updated in `paf_addresses`, 1,000 at a time | about an hour per 10M lines |

A month's changes are a small share of the file. The **first** run has nothing to compare
with, so it applies all 32M lines and takes several hours.

Each key unlocks one release only. A key for an older release needs **Release** set to its
folder on the AFD site, such as `Y26M08` for August 2026; the latest is at the top level and
earlier ones under `Archive/`. A key that does not match fails at the Unlock step with "The
key was not accepted for this release".

## How it works

- **Go** (`iznik-server-go/paf`): `POST /sysadmin/paf` stores the request in `paf_imports`
  with the key. `GET /sysadmin/paf` lists the latest ten, without the key. Only one runs at a
  time; one that has not written progress for 30 minutes is taken as dead and replaced.
- **Batch** (`paf:import-run`, every minute): picks up the request and drives the sidecar,
  copying its progress into the row. It then fetches the changed lines and applies them with
  `PafService::applyLines`, which matches on UDPRN. A run stopped part way, by a deploy or
  a restart, carries on from `lines_applied` the next minute.
- **Sidecar** (`paf-extractor/`, its own container): AFD ship the data only inside a
  Windows GUI program, so this runs it under Wine on a virtual display and types the key into
  it (`extract.sh`). It keeps the last applied release on its `paf-data` volume (~3 GB) and
  hands batch only the lines that differ. The key is cleared from the row once unlocked.

Lines whose postcode is not yet in `locations` are not applied. They show as **Waiting**,
and are offered again with the next release, by which time the postcode refresh has usually
added them.

Addresses that have left PAF are not deleted: user addresses point at `paf_addresses` rows.

## Setup on the live server

- `.env.background` holds `PAF_SFTP_HOST`, `PAF_SFTP_USER` and `PAF_SFTP_PASSWORD`, AFD's SFTP
  login. They go to the sidecar only.
- The sidecar is in the `production` profile. The first time, and after a change under
  `paf-extractor/`, build and start it:
  `docker compose build paf-extractor && docker compose up -d paf-extractor`.
- AFD's SFTP host key is pinned in `paf-extractor/known_hosts`. If AFD change it, the
  download fails; check the new key with them before replacing it.
- Optional batch settings: `PAF_BATCH_SIZE` (lines per batch, default 1000) and
  `PAF_PAUSE_MS` (pause between batches, default 0) if the database needs room.

## When it goes wrong

- The failed row's error is shown on the tab. A screenshot of the extractor's screen at the
  moment of failure is left at `/paf-data/work/out/failure.png` in the sidecar.
- `docker exec freegledocker-paf-extractor curl -s localhost:8080/jobs/current` shows the
  sidecar's own view.
- The batch side logs to `storage/logs/cron/paf_import-run.log`.
- If AFD redesign the extractor, the screen positions at the top of `extract.sh` are what
  change. Work them out by running it by hand on a virtual display and taking screenshots.

## Testing locally

The sidecar is not in a dev profile, as the image is ~1 GB of Wine. Put the SFTP login in
the checkout's `.env.background` (git-ignored) and start it by name:
`docker-compose --profile paf up -d paf-extractor`.
