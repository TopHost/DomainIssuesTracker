# Domain Issues Tracker — WHMCS addon

Centralised tracker for domains that need manual attention: blocked transfers
(invalid EPP, locked domain, pending client approval), failed renewals /
registrations, redemption periods and WHMCS ↔ registrar mismatches.

* Admin dashboard widget with live counters (**Problem transfers**, **Other
  domain issues**, **Urgent**) linking to the pre-filtered list.
* Full management page: filters, search, pagination, add / edit, status
  workflow, comments, change history.
* Hybrid data entry: manual cases from the admin UI, automatic cases from WHMCS
  registrar failure hooks and (Stage 2) registrar adapters re-checked by cron.

Requirements: WHMCS 8.x or 9.x, PHP 8.1+ (built and tested for PHP 8.3), MySQL/MariaDB with InnoDB.

---

## 1. Installation

1. Copy the `domainissues` directory to `<whmcs_root>/modules/addons/domainissues/`.
2. In the admin area go to **System Settings → Addon Modules**, find
   *Domain Issues Tracker* and click **Activate**. Activation creates the tables
   `mod_domainissues`, `mod_domainissues_comments`, `mod_domainissues_log`.
3. Click **Configure**, tick the admin roles under **Access Control** and
   adjust the settings (see §2). Save.
4. The widget appears on the admin home page (enable it under *Home → Widgets*
   if it was hidden). The full page lives under **Addons → Domain Issues Tracker**
   (`addonmodules.php?module=domainissues`).

### Upgrading

Overwrite the directory. If `version` in `domainissues_config()` increased,
WHMCS calls `domainissues_upgrade()` which applies schema migrations from
`lib/Installer.php`.

### Deactivation / uninstall

Deactivating removes all hooks. Tables are **kept** unless *Drop tables on
deactivation* is ticked in the settings before deactivating.

---

## 2. Settings (Configure page)

| Setting | Default | Purpose |
|---|---|---|
| Category: Transfer in / Transfer out | on | Stage 1 categories |
| Category: Renewal failed / Expired–redemption / Registration failed / Mismatch / Other | off | Stage 2 categories. Enabling any of them adds the second widget line. |
| Adapter: Enom / Nettim / MolData | off | Enable automatic collection + cron re-check per registrar (Stage 2) |
| Re-check interval (hours) | 24 | How often the cron re-checks open automatic issues |
| Deadline highlight threshold (days) | 7 | Issues with a deadline within N days are red and counted as urgent |
| Items per page | 25 | List pagination |
| Drop tables on deactivation | off | Delete all data when the addon is deactivated |

Disabling a category hides it from the *Add issue* form and the widget counters
for that category; existing issues of that type remain visible and editable.

---

## 3. Using the tracker

* **Add issue** — type the domain; matching WHMCS domains are suggested and,
  when one is picked (or *Look up* is pressed), the WHMCS domain ID, client and
  registrar are filled automatically. Free-form domains not in WHMCS are
  accepted. Subtype and registrar are free text with suggestions.
* **Statuses**: `new → in_progress → waiting_client → resolved / cancelled`
  (any transition is allowed). Moving to *resolved* stamps `resolved_at`;
  re-opening clears it. Every change of status, priority, deadline, type,
  subtype, registrar, domain or client is written to the history.
* **Comments** are chronological with author and timestamp. System comments
  (author "System") are written by automatic actions.
* **Show expired / Show redemption** checkboxes in the filter bar: by default
  the list hides the *Expired / redemption* category so that failed transfers
  and other vital cases stay in focus. Tick them to include grace-period
  domains and/or domains already in redemption; the choice is remembered for
  your admin session. Selecting the *Expired / redemption* type explicitly
  always shows all of them.
* **Urgent** rows (high priority or deadline within the threshold) are
  highlighted in the list and counted on the widget.
* **Delete** permanently removes an issue with its comments and history; prefer
  the *Cancelled* status for traceability.

### URL filters (used by the widget)

`addonmodules.php?module=domainissues&type=cat:transfers` — all transfer issues
`…&type=cat:other` — everything except transfers
`…&urgent=1` — urgent only
`…&status=open|closed|all|<status>&type=<issue_type>&registrar=<slug>&source=manual|automatic&q=<text>`

---

## 4. Automatic collection

### 4.1 Registrar failure hooks

`hooks.php` listens to the WHMCS hooks fired when a registrar call fails and
creates an automatic issue (only if the matching category is enabled):

| WHMCS hook | issue_type |
|---|---|
| `AfterRegistrarTransferFailed` | `transfer_in` |
| `AfterRegistrarRegistrationFailed` | `registration` |
| `AfterRegistrarRenewalFailed` | `renewal` |

The raw registrar error is stored in *details*; a subtype is guessed from the
message (`invalid_epp`, `domain_locked`, `pending_approval`, `payment_failed`, …).

**De-duplication**: an automatic report for a domain that already has an open
issue of the same type only refreshes *details*, *subtype*, *deadline* and
`updated_at` — no duplicate rows.

### 4.2 Registrar adapters + cron (Stage 2)

`AfterCronJob` runs `lib/Cron/Recheck.php` once per configured interval, in two phases:

**Phase 1 — proactive scan.** Domains at a registrar with an enabled adapter
whose WHMCS status is *Pending Transfer*, *Pending*, *Pending Registration*, *Expired*,
*Grace* or *Redemption* (only for enabled categories, max 200 domains per run)
are inspected with `checkDomain()`. Every detected problem becomes an automatic
issue, or refreshes the existing open one.

**Phase 2 — re-check.** Every open automatic issue is re-inspected (API results
from phase 1 are reused):

* problem still reported → issue refreshed (dedup path);
* problem no longer reported **and** the adapter lists that issue type in
  `supportedIssueTypes()` → issue auto-resolved with a system comment;
  for failed operations (transfer in, registration, renewal) only while the
  WHMCS domain status confirms the operation — transfer: Active / Pending
  Transfer, registration: Active / Pending / Pending Registration, renewal:
  Active. If the domain went Expired, Grace, Redemption, Cancelled, Transferred
  Away or is no longer in WHMCS, the case stays open with one explanatory system
  comment per status (the adapter then only checks the lifecycle, so "not
  reported" does not mean "fixed");
* otherwise → untouched. An API/transport error never resolves anything.

Manual issues are never closed automatically. Adapter errors and every API
call go to **Utilities → Logs → Module Log** (`domainissues:<registrar>`),
with the API password masked. No registrar API is called during page requests.

Current adapter status:

| Adapter | slug (`tbldomains.registrar`) | Status |
|---|---|---|
| Enom | `enom` | implemented, validated on live responses |
| Netim | `netim` | implemented, field names need calibration on first run (see below) |
| MoldData (nic.md) | `molddata` | implemented, based on the registrar module protocol |

An adapter that cannot reach its registrar **throws**; the cron logs the error
and leaves the domain's issues untouched (a network error never resolves a case).

#### Enom adapter

Credentials come from the WHMCS *enom* registrar configuration
(`Username`, `Password`, `TestMode` → `resellertest.enom.com`). API commands used:

| Command | Purpose |
|---|---|
| `TP_GetDetailsByDomain` | transfer orders for the domain (`<TransferOrder>`: orderid, orderdate, statusid, statusdesc); the most recent order is evaluated |
| `GetDomainInfo` | `registrationstatus` (Registered / Expired / RGP / …) and expiration |

Only called for domains WHMCS considers in flight or expired (never for healthy
active domains).

**Transfer in** (WHMCS status *Pending Transfer* / *Pending*), from the latest order's `statusdesc`:

| `statusdesc` contains | issue_type / subtype | priority |
|---|---|---|
| auth, epp, password | `transfer_in` / `invalid_epp` | normal (high if cancelled) |
| lock, "60 days" (e.g. status 22 *Canceled - Domain is locked at current registrar, or is not yet 60 days old*) | `transfer_in` / `domain_locked` | high |
| cancel (e.g. status 27 *Canceled by customer*) | `transfer_in` / `transfer_cancelled` | high |
| reject, denied, declined, not responded, expired, failed, timed out | `transfer_in` / `approval_expired` | high |
| await, pending, approval, verification, confirm, fax | `transfer_in` / `pending_approval` (an "awaiting registry" status younger than 7 days is ignored) | normal |
| complete, transferred, success | `sync_mismatch` / `transfer_completed` (done at Enom, WHMCS not updated) | normal |
| *no order at Enom* ("There are no transfer order details") | `transfer_in` / `registrar_error` | normal |

Once WHMCS sets the domain *Active*, the open transfer issue is auto-resolved
on the next run.

**Lifecycle** (WHMCS status *Expired* / *Grace* / *Redemption*, or *Active* past expiry), from `registrationstatus`:

| `registrationstatus` | issue_type / subtype | deadline (estimate) | priority |
|---|---|---|---|
| Expired | `redemption` / `expired_grace` | expiration + 40 d | normal |
| RGP, *redemption* | `redemption` / `redemption_period` | expiration + 70 d | high |
| *delete* | `redemption` / `pending_delete` | expiration + 75 d | high |
| Registered | *(no issue)* | | |
| "Domain name not found" | `sync_mismatch` / `not_at_registrar` (deleted or transferred away; WHMCS status stale) | — | normal (high if WHMCS says Active) |

Deadlines use typical Enom lifecycle lengths (40-day grace, 30-day RGP, 5-day
pending delete); adjust manually for TLDs that differ.

**Priority policy** (applied by all automatic sources; an admin's manual
change of the priority is never overwritten):

| Situation | priority |
|---|---|
| Expired, still in the renewal grace period | low |
| In redemption (restorable with a fee) | normal |
| Pending delete (no longer restorable, client must be told) | high |
| Blocked transfer: invalid EPP, locked, cancelled, no order, approval overdue | high |
| Synchronous registrar failure (transfer / registration / renewal hook) | high |
| WHMCS ↔ registrar mismatch: transfer/registration done at the registrar, WHMCS not updated | normal |
| WHMCS ↔ registrar mismatch: domain gone from the registrar while WHMCS shows it Expired/Grace (dead domain, just tidy WHMCS) | low, hidden by default (*Show expired*) |
| WHMCS ↔ registrar mismatch: domain gone from the registrar while WHMCS shows it **Active** | high |
| WHMCS ↔ registrar mismatch: a registrar has an open operation for a domain WHMCS assigns to another registrar (transfer between registrars) | high |

**Scan scope.** The proactive scan inspects at most *Cron scan limit* domains
per run (default 200), transfers first, then the most recently expired. Domains
expired for longer than *Ignore domains expired for more than* days (default
120) are skipped, because installations usually keep hundreds of long-gone
expired domains.

#### Netim adapter

Uses the SOAP client shipped with the WHMCS *netim* registrar module
(`modules/registrars/netim/api/APISoap.php`) and its credentials
(`Username` / `Password` / `sandbox`). The registrar module must therefore be
installed and working (its core is ionCube-encoded; only the API client is used).

| Call | Purpose |
|---|---|
| `mod_netim_async` (module table: ID_OPE, DOMAINE, CODE_OPE, DATE_OPE) | open asynchronous operations; the adapter also adds every domain with an operation older than 1 day to the proactive scan, whatever its WHMCS status |
| admin notes (`domainTransferIn failed with error : E18-M1807 : ...`, `domainCreate (#id) failed with error : E12-M1215 : ...`) | synchronous failures recorded by the module |
| `queryOpe(id)` | status + message of an open operation |
| `domainInfo(domain)` | status / expiry / max-restore date; an error means "not in account" |

Mapping: operation *done* → `sync_mismatch/transfer_completed`; *cancelled* →
`transfer_in/transfer_cancelled`; *failed* → `transfer_in` with the subtype
guessed from the message (auth code → `invalid_epp`, lock → `domain_locked`);
*pending* for more than 7 days → `transfer_in/pending_approval`; no operation
and not in account → `transfer_in/registrar_error`. Domain status containing
*quarantine/redemption* → `redemption_period`, *expire* → `expired_grace`,
*delete* → `pending_delete`.

Field names validated on live responses: `queryOpe` → `ID_OPE`, `STATUS`
(Done / Pending / Failed / Cancelled), `MESSAGE`, `TYPE`, `DATE`; `domainInfo` →
`dateExpiration`, `dateMaxRenew` (end of normal renewal, used as the grace
deadline), `dateMaxRestore` (end of redemption), `status` (e.g. *EXPIRED, HOLD,
NOT DELEGATED*). Raw responses are logged under `domainissues:netim` with auth
codes masked.

A domain that WHMCS assigns to another registrar but for which Netim has an open
or completed operation (transfer between registrars) is reported as
`sync_mismatch / registrar_mismatch`, high priority, under registrar `netim`.

#### MoldData (nic.md) adapter

Re-implements the read-only `<info>` command of the *molddata* registrar module
(TLS socket, 4-byte length prefix, account credentials inside the command) with
the module's settings (`EPPHost`, `EPPPort`, `EPPUser`, `EPPPassword`, `Login`,
`Password`). Only `.md` domains are inspected.

| nic.md answer | WHMCS status | issue |
|---|---|---|
| `res="3"` *not in account* (`2` in the module source; live answers use `3`) | Pending Transfer | `transfer_in/pending_approval` — transfer not completed |
| `res="0"` (does not exist) | Pending Transfer / Pending Registration | `transfer_in` or `registration` / `registrar_error` |
| in our account | Pending Transfer | `sync_mismatch/transfer_completed` |
| `res="0"` or `res="3"` | Expired / Grace / Active | `sync_mismatch/not_at_registrar` |
| `exp_date` in the past | Expired / Grace / Active | `redemption/expired_grace` (low) until expiry + 30 d, then `redemption_period` (normal) |

nic.md exposes no transfer-status query; a transfer that fails because of a
wrong transfer code is only visible as "still not in our account".

### 4.3 Adding a new adapter

1. Create `lib/Adapters/<Name>Adapter.php`:

   ```php
   final class FooAdapter extends AbstractAdapter
   {
       public function getRegistrarSlug(): string { return 'foo'; }          // = tbldomains.registrar
       public function supportedIssueTypes(): array { return [Types::TYPE_TRANSFER_IN]; }
       public function checkDomain(array $row): array
       {
           $config = $this->registrarConfig();               // WHMCS registrar settings
           [$sld, $tld] = $this->splitDomain($row['domain']);
           // ... call the API, $this->log('GetDomainInfo', $request, $response);
           $subtypes = $this->mapEppStatuses($eppStatuses);  // §6.4 mapping
           return $subtypes
               ? [$this->issue($row, Types::TYPE_TRANSFER_IN, $subtypes[0], $rawMessage)]
               : [];
       }
   }
   ```
2. Register it in `AdapterRegistry::ADAPTERS` (`'foo' => FooAdapter::class`).
3. Add `adapter_foo` to `Config::DEFAULTS` and a yes/no field in `domainissues_config()`.

---

## 5. Architecture

```
modules/addons/domainissues/
├── domainissues.php          config / activate / deactivate / upgrade / output
├── hooks.php                 AdminHomeWidgets, AfterRegistrar*Failed, AfterCronJob
├── autoload.php              loader for lib/ (namespace WHMCS\Module\Addon\DomainIssues)
├── lib/
│   ├── Types.php             enums (types, statuses, priorities, EPP map)
│   ├── Config.php            settings reader (tbladdonmodules)
│   ├── Lang.php              lang helper
│   ├── Installer.php         schema (Capsule schema builder)
│   ├── IssueDTO.php          value object produced by adapters/hooks
│   ├── IssueRepository.php   all Capsule queries
│   ├── IssueService.php      validation, create/update + history, dedup, auto-resolve
│   ├── Admin/AdminController.php  routing, CSRF, flash, AJAX JSON
│   ├── Admin/View.php        template renderer + escaping/URL/badge helpers
│   ├── Adapters/             AdapterInterface, AbstractAdapter, AdapterRegistry, Enom/Nettim/Moldata
│   ├── Cron/Recheck.php      periodic re-check
│   ├── Hooks/RegistrarFailureHandler.php
│   └── Widget/DashboardWidget.php
├── templates/list.php, detail.php, _topbar.php
├── assets/dit.css            admin styles (TopHost design, prefix dit-)
└── lang/english.php
```

### Design

Since 1.3.6 the admin pages use the design shared by the other TopHost modules
(Bank Reconciliation, eFactura SFS Moldova): gradient top bar with pill tabs,
cards, pill badges and the common palette (warm colours = needs action, cool
colours = information). All styles live in `assets/dit.css` under the `dit-`
prefix, so nothing leaks into the WHMCS theme. The stylesheet is loaded from
`<SystemURL>/modules/addons/domainissues/assets/`; if the pages look unstyled,
check *System Settings → General → System URL*.

The top bar tabs are plain links to existing list filters (`type=cat:transfers`,
`type=cat:other`) and to the add form. Settings stay on the WHMCS
*Addon Modules → Configure* page.

Security: all DB access through `WHMCS\Database\Capsule` (prepared statements);
every POST is protected by the WHMCS admin CSRF token (`check_token`);
all template output is escaped; the addon honours WHMCS admin role
*Access Control*. Note that the dashboard widget is shown to every admin;
admins without access to the addon get WHMCS's permission error when they
follow its links.

---

## 6. Changelog

* **1.4.0** — cron: failed operations no longer disappear. An automatic
  *Transfer in*, *Registration failed* or *Renewal failed* case is auto-resolved
  only while the WHMCS domain status confirms the operation (transfer: Active or
  still Pending Transfer; registration: Active or Pending / Pending Registration;
  renewal: Active). If the domain meanwhile went Expired, Grace, Redemption,
  Cancelled, Transferred Away or was removed from WHMCS, the case stays open and
  gets one system comment per status ("Not closed automatically: …"). Close it
  manually once handled. Manual cases are still never closed automatically.
* **1.3.7** — design only: on the issue page the History card sits right under
  Comments (above Audit), the two-column layout is kept down to 900 px wide
  screens, and "Comments (N)" / "History (N)" buttons jump to those sections.
* **1.3.6** — design only: admin pages and dashboard widget restyled in the
  TopHost design (`assets/dit.css`, `templates/_topbar.php`). No change to
  queries, filters, validation, hooks, cron or adapters; form fields, URLs and
  JavaScript IDs are identical to 1.3.5.

---

## 7. Database

`mod_domainissues` — one row per case (`domain_name`, `issue_type`,
`issue_subtype`, `source`, `status`, `priority`, `deadline`, `details`,
`registrar`, `domain_id`, `client_id`, audit timestamps). Indexes on
`domain_name`, `status`, `issue_type`, `(status, issue_type)`, `domain_id`,
`client_id`, `deadline`.

`mod_domainissues_comments` and `mod_domainissues_log` reference the issue with
`ON DELETE CASCADE`.
