# Domain Issues Tracker (WHMCS addon)

WHMCS 8 / 9 addon used by TopHost.MD to track domains that need manual attention:
blocked transfers (invalid / missing transfer code, locked, pending approval), failed
renewals and registrations, expired / redemption domains and WHMCS ↔ registrar mismatches.
Since 1.5.0 it can also e-mail the client daily while a transfer waits for a valid transfer code.

| Path | What |
|---|---|
| `modules/addons/domainissues/` | the addon: copy this folder into the WHMCS `modules/addons/` directory |
| `modules/addons/domainissues/README.md` | installation, settings, adapters (Enom, Netim, MoldData), changelog, database |

History: the first commit is version 1.3.5 exactly as it ran in production, so every later
change (1.3.6, 1.3.7 design; 1.4.0; 1.5.0) is visible as a diff.

## Install / upgrade

1. Upload `modules/addons/domainissues/` over the existing folder.
2. Open WHMCS admin > Addons (or Setup > Addon Modules): WHMCS runs the upgrade automatically.
3. Review the settings (Configure). New in 1.5.0: *Transfer code reminders* (off by default) and the
   e-mail template *Domain Transfer Code Reminder* in Setup > Email Templates > Domain Messages.

Works together with the MoldData (nic.md) registrar module (repository `TopHost/molddata-new`),
which reports its failures here.
