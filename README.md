# sccpsync — FreePBX Module

FreePBX module that fixes two bugs when using [SCCP Manager](https://github.com/chan-sccp/sccp_manager) with [chan-sccp-b](https://github.com/chan-sccp/chan-sccp).

## Problems solved

### 1. Display Name shows extension number instead of label

When editing an SCCP extension in FreePBX (Extensions page), the **Display Name** field shows the extension number (e.g. `3005`) instead of the human-readable label set in SCCP Manager (e.g. `John Smith`).

**Root cause:** FreePBX Core reads Display Name from `users.name` and `userman_users.displayname`. SCCP Manager stores the name in `sccpline.label`. These fields are not linked. Additionally, the SCCP driver's `getDevice()` returns `name AS name` (the extension number, which is the primary key of `sccpline`) instead of `label AS name`, causing it to overwrite the Display Name field on page load.

**Fix:**
- On every Apply Config, syncs `sccpline.label` → `users.name` and `userman_users.displayname`
- On install and Apply Config, patches the SCCP driver file (`Sccp.class.php.v433`) to return `label AS name` instead of `name AS name` in `getDevice()`

### 2. Phone loses its line assignment after editing an extension

When you save any change to an SCCP extension in FreePBX, the phone gets detached from its line and must be manually re-assigned in SCCP Phone Manager.

This is a known bug: [chan-sccp/sccp_manager#89](https://github.com/chan-sccp/sccp_manager/issues/89)

**Root cause:** FreePBX Core uses a delete-then-recreate pattern when saving extensions (`delDevice` + `addDevice`). The SCCP driver's `delDevice()` deletes the phone-to-line binding from `sccpbuttonconfig` during this process, even though the extension is only being edited, not deleted.

**Fix:** Implements the standard FreePBX BMO `delDevice($account, $editmode)` hook. When `$editmode = true` (edit, not delete), saves a snapshot of `sccpbuttonconfig` and restores it via `register_shutdown_function` after the full HTTP request completes (at which point `addDevice` has already run and `sccpline` is recreated).

## Requirements

- FreePBX 16
- Asterisk 18+
- [SCCP Manager](https://github.com/chan-sccp/sccp_manager) installed
- chan-sccp-b v433+

## Installation

### Via Module Admin (recommended)

1. Download the latest `sccpsync.tar.gz` from [Releases](https://github.com/miervo/sccpsync/releases)
2. In FreePBX: **Admin → Module Admin → Upload Locally**
3. Upload the archive and click **Process**
4. Click **Apply Config**

### Via CLI

```bash
cd /var/www/html/admin/modules
tar -xzf sccpsync.tar.gz
fwconsole ma install sccpsync
fwconsole reload
```

## How it works

### On Apply Config (`doDialplanHook`)

1. Patches `Sccp.class.php.v433` — replaces `name AS name` with `label AS name` in `getDevice()` SQL query (idempotent)
2. Queries all SCCP extensions joining `sccpline`, `devices`, `users`, `userman_users`
3. For each extension where `sccpline.label` differs from the FreePBX name fields — updates them
4. Skips extensions where label is empty or equals the extension number

### On extension save (`delDevice` hook)

1. Receives `$editmode = true` from FreePBX Core
2. Saves current `sccpbuttonconfig` rows for the extension to memory
3. Registers a shutdown function that runs after the HTTP request ends
4. In the shutdown function: verifies `sccpline` still exists (confirming it was an edit, not a delete), then restores the saved button config

### Sync conditions

| Field | Updated when |
|---|---|
| `users.name` | `sccpline.label` ≠ current value |
| `userman_users.displayname` | `sccpline.label` ≠ current value |
| `sccpline.cid_name` | field is empty |

Skipped if `sccpline.label` is empty or equals the extension number.

## CLI commands

```bash
# Sync all SCCP extensions manually
fwconsole sccpsync

# Sync specific extension
fwconsole sccpsync --ext=3005

# Show sync status for all extensions
fwconsole sccpsync --status

# Dry run (show what would be changed)
fwconsole sccpsync --dry-run
```

## Logs

```bash
tail -f /var/log/asterisk/sccpsync.log
```

## Notes

- The patch to `Sccp.class.php.v433` is reapplied on every Apply Config. If SCCP Manager is updated and the patch is overwritten, it will be restored automatically on the next Apply Config.
- The `delDevice` hook only works in web context (HTTP request). Deleting extensions via `fwconsole` will not trigger the button config restore — but this is generally not needed in CLI context.

## Changelog

**1.0.0**
- Initial release
- Sync `sccpline.label` → `users.name` and `userman_users.displayname` on Apply Config
- Patch SCCP driver `getDevice()` to return label as Display Name
- BMO `delDevice` hook to preserve phone button assignments on extension edit
