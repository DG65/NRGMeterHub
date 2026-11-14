[Deutsch](README.md) | English

# MeterHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module Version](https://img.shields.io/badge/Modul_Version-0.35.1-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGMeterHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGMeterHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

A Symcon module that reads energy meters from various manufacturers directly via **Modbus TCP**
— one shared driver framework instead of one module per manufacturer, analogous to
[InverterHub](https://github.com/DG65/NRGInverterHub).

**Part of the NRG-Stack** — the energy module family by DG65.

**Status: stable.** MeterHub has been in the stable channel of the Symcon Store since version
0.32.5. The register mappings are based on the manufacturers' publicly available Modbus protocol
documents (Siemens manual L1V30415167A, Janitza Modbus address list UMG 604-PRO) and have partly
been confirmed on real devices; meters that have not yet been tested on real hardware are
explicitly marked as experimental below. Feedback on wrong or missing values is still welcome —
please report it with the meter type and the register concerned.

## Thanks

A heartfelt thank-you to **Sepp Lausch** ([seppm in the Symcon forum](https://community.symcon.de/u/seppm/summary)) — as an attentive beta tester, with his in-depth expertise in KNX metering technology he contributed decisively to making MeterHubVirtual fit for practical use: from the automatic detection of whole device families, through the correct wiring of bidirectional KNX meters (Lingg&Janke P14/P23/A14/A23), to numerous findings from real-world use that made the module more robust and easier to understand overall.

## Supported meters

| Meter | Scope | Notes |
|---|---|---|
| **Siemens SENTRON PAC2200** | Total active power, average voltage/current, frequency, energy import/export (tariff 1+2), optionally U/I/P/Q/S per phase, power factor | Float32 measurements from register 1, energy meters as **64-bit double** from register 801. FC 0x03. |
| **Janitza UMG 604 / 605 / 509 / 512 / 806 / 96PA / 801** | Total active power, average voltage/current, frequency, energy import/export, optionally U/I/P/Q/S per phase, cos φ, power quality (THD, rotating field) | Shared **classic Janitza register map**: Float32 from register 19000, energy in Wh at 19068/19076, THD from 19110, rotating field 19052. Average values are calculated from the phases. FC 0x03. |
| **Janitza UMG 800** | as above | Its own, **freely configurable** Modbus map — the driver follows the delivered factory default (total P 19030, frequency 19054, import 19072, export 19080). If the assignment was changed in the device (GridVis), the addresses may not match. FC 0x03. |
| **Eastron SDM72D-M v2 / SDM630 v2** | Total active power, average U/I, frequency, energy import/export, optionally U/I/P/Q/S per phase, power factor, line-to-line voltage, neutral current | **FC 0x04** (input registers), Float32 big-endian from reg. 0, energy in kWh (reg. 72/74). SDM630 uses the same map. Modbus RTU → via an RTU/TCP gateway. |
| **Eastron SDM120 / SDM220 / SDM230** (single-phase) | Active power, voltage, current, frequency, energy import/export, optionally reactive/apparent power and power factor | **FC 0x04**, Float32 big-endian, Modbus address 1 from the factory; the three devices share the same register map (voltage 0, current 6, active power 12, apparent power 18, reactive power 24, power factor 30, frequency 70, import 72, export 74). Pure RS485 devices — via the **Symbox bridge** or an RTU/TCP gateway. Unlike the SDM630 there are no total registers; each quantity is read individually. Cross-checked against nmakel/sdm_modbus, evcc and volkszaehler/mbmd and **confirmed on real hardware by a tester**. Single-phase: no measurement mode "three-phase/per phase". |
| **Carlo Gavazzi EM24 / EM300 / ET340** | Total active power, average U/I, frequency, energy import/export, optionally U/I/P/Q/S per phase | **FC 0x04**, Int32 **word-swapped (CDAB)** with scaling (U ×0.1 · I ×0.001 · P ×0.1 · f ×0.1 · energy ×0.1 kWh). Register map after OpenEMS. |
| **WhatWatt** | Total active power (import − export), average U/I, energy import/export (+ tariff 1/2), optionally U/I/P per phase | **FC 0x04**, Float32 + 64-bit double (tariff energy), big-endian. Modbus TCP directly. Separate import/export power (501/505). |
| **Phoenix Contact EEM-EM375 / EEM-XM** | Total active power, average U/I, imported energy, optionally U/I/P per phase | **FC 0x04**, Float32. EM375 from reg. 4096 (unit ID often 255), EEM-XM from reg. 32774 (unit ID mostly 1). For the EEM-XM, use the WordSwap switch if necessary. |
| **Shelly Pro 3EM** | Total active power, average U/I, frequency, energy import/export, optionally U/I/P per phase and **energy meters per phase** | **FC 0x04**, Float32 **word-swapped (CDAB)**; wire addresses = documentation − 30000 (measurements from 1011, total energy 1162/1164, per phase 1182/1184 · 1202/1204 · 1222/1224). Verified on a real device (Gen3). **Enable Modbus TCP on the device** (Settings → Modbus, port 502). |
| **go-e Controller** | Core values from the **Grid** category (active power, energy import/export), average U/I, optionally U per phase + N, I per phase + N, **current sensors 1–6** (I/P/cos φ) and the categories **Home/Car/Relays/Solar/Battery** (power + energy in/out) | **FC 0x04**, Float32/Float64 big-endian; wire address = documentation − 30001 (voltages from 1000, sensors from 1010, category blocks in a 26-register grid from 1046). No frequency (register not implemented); unassigned registers return NaN instead of an error and are discarded. Verified on a real device. **Enable Modbus TCP on the device** (go-e app: Internet → Advanced settings → Modbus; then switch off and on once if necessary). The go-e **wallboxes** are handled by the sister module ChargerHub. |
| **Inexogy / Discovergy** (cloud) | Import/feed-in (cumulative, kWh), total power, optionally power and voltage per phase | **No Modbus** — cloud API (`api.inexogy.com`) via **OAuth 1.0a**. E-mail/password once during setup, afterwards only access tokens (no plain-text password). If a token becomes invalid (HTTP 401, possible according to the Inexogy documentation), the form says so clearly; optionally MeterHub then logs in again by itself if the password is kept in a vault (community module "SymconSecrets") — never in MeterHub itself. For the billing-grade smart meter gateway at the grid connection point; mark it as such (checkbox above). Scaling verified against the old module and checked against real meter values; the OAuth handshake is to be confirmed with your own account. |
| **Meteocontrol blue'Log SCADA – inverter** | Active power AC, total yield (cumulative), grid frequency, device type feedback, optionally U/I per phase (AC) and DC side (P/U/I) | **FC 0x03**, Float32 **word-swapped (CDAB)**, register block from 41000. Reads **one** device behind a blue'Log solar park data logger via its **SCADA address** (= the `UnitId` of this instance — found on the blue'Log itself: device list → column "SCADA address", NOT 1). Byte order and instantaneous value verified live on a real solar park against an independently configured wiring. |
| **Meteocontrol blue'Log SCADA – meter** | Total active power, import/export (cumulative), grid frequency, device type feedback, optionally U/I per phase, reactive/apparent power, power factor | **FC 0x03**, Float32 word-swapped (CDAB), register block from 43000 — same addressing principle as above. Byte order/address mechanism verified, but the concrete register addresses of this block had no test object of their own at the test installation (no individually addressed SCADA meter available) — compare with the device display before productive use. |
| **Meteocontrol blue'Log SCADA – data logger (address 97)** | Active power AC as the **sum of all inverters** (for the blue'Log XC as master including its slaves), **installed and active inverters**, device type feedback; optionally power control: available active/reactive power and effective active power setpoint (%) | **FC 0x03**, Float32 word-swapped (CDAB), registers 10000–10010 and 10100 (P_AC_INV_SUM, PPC_INV_INST/AVAIL, PPC_P/Q_AC_AVAIL, PPC_P_SET_REL; SCADA ≥ 2.25 according to "SCADA Interface Register V2.27.0") under the fixed SCADA address **97** (= `UnitId`). Read-only. The search offers address 97 automatically for every blue'Log found. Verified live on a real solar park against its own Symcon query. **No meter reading in the device** — the SCADA interface documents no energy under 97. MeterHub therefore extrapolates **"Total yield (extrapolated)"** from the park power (power × time, trapezoid between two measurements; gaps longer than 5 polling cycles or at least 5 minutes are not bridged, negative night values count as 0) and archives it like a meter reading. More accurate than the extrapolation is the sum of the inverter meters (E_TOTAL, e.g. via MeterHubVirtual). |
| **Meteocontrol blue'Log RPC** ⚠️ writing | Feed-in power (actual), effective setpoint (actual, %), grid frequency — AND continuously writes a setpoint (relative % or absolute W) to slave ID 10 | **FC 0x03 reading / FC 0x10 (Preset Multiple Registers) writing**, Float32 word-swapped (CDAB). Direct marketer / "3rd party" role according to the manufacturer — that is what the interface is intended for. The watchdog/validity time (register 5006) is written along on every cycle. **Before the first activation, make sure no other system serves the same channel** — see the warning in the form. |
| **Meteocontrol blue'Log Power Control** ⚠️ writing | Active power (actual, grid connection point), grid frequency — AND continuously writes a setpoint to slave ID 1 | **FC 0x03 reading / FC 0x10 writing**, Float32 word-swapped (CDAB). This role actually belongs to the **grid operator** — enable only if that really applies. **No watchdog/validity time mechanism** according to the manufacturer documentation (unlike RPC): a value that has been set stays until it is actively overwritten. |

For both setpoint meter types you can switch the mode (relative %/absolute W), choose the failure
behavior on deactivation (default setpoint vs. hold the last setpoint) and call
`MHUB_SetBlueLogTarget($id, $wert)` from scripts/EMS. MeterHub does not calculate the setpoint
itself — any aggregation logic (e.g. several direct marketer source values) stays outside.

> ⚠️ **go-e Controller and surplus charging — two-controller warning:** The controller is not
> just a measuring hub. Depending on its configuration it controls the go-e wallboxes **by itself**
> (PV surplus charging, load limiting — an on-device control loop). If an energy management system
> is to control the wallboxes instead, the internal control must be deliberately disabled
> — otherwise two controllers work against each other on the same wallbox. Conversely: if the
> controller regulates, the energy management system may only integrate the wallboxes concerned
> **read-only**. This module only reads and is not affected by the conflict. The control state is
> **not** visible via the controller's Modbus map — it lives on the wallboxes
> themselves (go-e charger API: `fup` = usePvSurplus, `loe` = load management, `modelStatus` with
> a plain-text reason such as *ChargingBecausePvSurplus*). The matching status variable therefore
> belongs in ChargerHub, not here.

The Janitza models with the classic map are functionally identical — the meter type in the form
only serves the correct labelling.

### Experimental (not yet tested on real hardware)

| Meter | Notes |
|---|---|
| **Socomec Countis** (E23/E24/E27/E28/E34/E44) | FC 0x03; U/I/f as UInt32, P/Q as Int32, energy UInt32. Scales derived from OpenEMS — **check the power scale on the device in particular**. |
| **MBS Professional 3-75** | M-Bus→Modbus gateway, FC 0x03. Import/export (kWh), active power, voltage, frequency. Integer type sizes derived from the Symcon templates. |

For experimental meters, compare the measured values with the device display; if values look
implausible, the **WordSwap** or **invert** switch helps.

Register addresses are in the **description field** of each variable (object manager, column
"Description") — handy for comparing with the manufacturer's manual.

## Modules in this repository

### MeterHub

The actual data-reading instance. One module, one `meter type` selection field — depending on the
meter chosen, the matching data point groups (switches) and registers are enabled. Architecture:

- **`MHUB_ModbusTcpClient`** — shared Modbus TCP basics (read holding/input registers, data type
  helpers including Float32 and 64-bit double), used by all drivers.
- **`MHUB_MeterDriverInterface`** — the contract every meter driver fulfills (base variables,
  optional groups, profiles, `readFast`/`readSlow`). Meters are read only (exception: the two
  writing blue'Log types RPC/Power Control).
- **One driver per register map** — `MHUB_Pac2200Driver` (Siemens), `MHUB_JanitzaClassicDriver`
  (classic Janitza map, covers UMG 604/605/509/512/806/96PA/801) and `MHUB_Umg800Driver`
  (UMG 800). Each driver encapsulates register addresses, data types and block layout.

All global class names deliberately carry the `MHUB_` prefix (family convention since
25.07.2026) — without a prefix, helper classes with the same name collide as soon as a consumer
(the EMS) loads several NRG-Stack modules in the same PHP process.

Setup: create the instance, choose the meter type, enter the IP address (and port/unit ID if
needed), enable the desired data point groups, apply.

**Role of the meter:** "Role of the meter" sets the sign semantics — *grid/connection-point
meter* (+ import / − feed-in, intended as an input for the EMS) or *sub-meter / consumer* (always
positive). If the direction does not match your own installation, the switch **"Invert active
power"** flips the sign of the total active power.

**Energy unit (kWh/Wh):** By default, energy values are output in kWh. The switch "Output energy
in Wh instead of kWh" changes them to the base unit Wh — consistent with power (W); the new
Symcon presentation then scales to Wh/kWh/MWh by itself. Existing instances stay at kWh without
switching (no jump in the history).

**Polling interval:** Instantaneous values (power, voltage, current, frequency) are read at the
fast interval, the energy meters at the slow interval.

**Variables, display and archive:** All variables sit directly under the instance (no
subcategories). The profile (unit, decimal places) comes as the default presentation of the
variable, not as a custom profile — that belongs to the user. MeterHub does not switch the archive
(logging) on by itself: if your own variables are not archived, the form shows the notice
"Archiving missing" at the top with the button "Enable archiving for these variables" (logging
that is already running is never switched off). Archive compaction and the Inexogy backfill only
work on archived variables; compaction is off by default for new instances. Which sections of the
form are expanded, collapsed or hidden when opened, and in which order they appear, is set by the
panel "Form: layout".

### MeterHubDiscovery

A **configurator** module that searches an IP range in the local network for meters on Modbus TCP
port 502:

1. Enter start and end IP (prefilled from your own network when created, but changeable),
   optionally a name template for the instances to be created.
2. Click "Search network" — a non-blocking, parallel search on port 502.
3. For each open IP the meter type is recognized by documented default unit IDs and a
   plausibility check (grid frequency 45–65 Hz **and** a plausible voltage): PAC2200 (frequency
   on reg. 55), the classic Janitza map (frequency on 19050) and UMG 800 (frequency on 19054)
   each sit on different registers and can thus be told apart. The classic Janitza models share
   one signature — Discovery proposes "Janitza UMG (classic map)" as their representative; the
   exact model can then be set in the instance (the register map is identical anyway).
4. Hits appear in the result table — a click on "Create" creates a `MeterHub` instance with a
   prefilled IP address, unit ID and meter type.

**Not found automatically:** meters behind RTU/TCP gateways with a freely selectable unit ID (e.g.
Socomec, MBS), meters on a ModBus gateway such as the RS485 port of the Symbox (e.g. the pure
RS485 devices Eastron SDM120/220/230 — the search only scans TCP), the Phoenix EEM-XM, Inexogy
(cloud) and the writing blue'Log types RPC/Power Control. Create the MeterHub instance by hand
there, for the Symbox with the connection path "Symbox gateway".

**Ignore IPs:** Addresses in this list are skipped completely during the search — meant for other
Modbus devices that would otherwise cost probing time. Separate several IPs with commas.

**Name template:** leave empty for the default "meter type + running number", or enter a pattern
of your own with the placeholders `{zaehler}` `{ip}` `{unitid}` `{nr}` (e.g.
`{zaehler} Keller ({ip})`). For results of the blue'Log SCADA search, `{busaddr}` (RS485 bus
address) is available as well; otherwise it stays empty.

**Second search mode — blue'Log SCADA address range:** for Meteocontrol blue'Log solar park data
loggers. Instead of an IP range (one device per IP), ONE well-known blue'Log, but many devices
connected behind it — each via its own SCADA address assigned freely on the blue'Log itself
(device list on the blue'Log → column "SCADA address"). Enter the blue'Log IP, port and SCADA
address range, click "Search blue'Log address range" — every recognized, supported address
(inverter/meter) appears in the same result table as in the normal network search. Unsupported
device types (sensor, tracker, genset, battery, plant controller, the blue'Log itself) are found
but deliberately not proposed.

You do not have to search for the blue'Log IP every time — "Take over a known host" proposes the
hosts of existing MeterHub instances. Before the actual address range search, "Check interfaces"
is worthwhile: a SCADA license is an expensive add-on module and rare in practice, the standard
blue'Log license contains neither it nor RPC — the button shows which of the three roles (Power
Control/RPC/SCADA) respond at this IP at all.

### MeterHubBridge (bridge to the ModBus gateway, Symbox)

Anyone connecting meters through the built-in RS485 port of a Symcon Symbox (or another native
"ModBus gateway") uses the connection path **Symbox gateway** instead of "Direct". The connection
runs through this small module, which is the only one attached to the gateway — the MeterHub
instances themselves stay without a parent, existing Direct instances do not change.

1. Create a native **"ModBus gateway"** for the device and set its **DeviceID** (= unit ID) to the
   device's Modbus address.
2. In the MeterHub instance, choose the connection path "Symbox gateway", select the gateway and
   click **"Create bridge and connect"**, then "Apply changes". (It also works by hand: create a
   "MeterHub bridge", connect it to the gateway via "Change gateway" and select it in the
   instance.)

**One bridge serves exactly one unit ID.** Several devices with different unit IDs on the same bus
each need a gateway of their own and a bridge of their own. The bridge passes requests through
unchanged (reading and writing) and returns the response base64-encoded. The path is verified for
reading against the raw code of Symcon's reference module (SymconBC, EM24-DIN), but not yet tried
on many devices; writing (blue'Log RPC/Power Control) is an untested derivation and does not
belong on this path.

## Function assignment (which consumer is this?)

The panel **"Function assignment"** lets you set *what* a meter actually measures. The starting
point is the **measurement mode** — it decides how assignment works:

| Measurement mode | Meaning | Assignment |
|---|---|---|
| **Three-phase** | one consumer across all 3 phases (grid connection, heat pump, …) | **one** function for the whole meter |
| **Single-phase separately** | 3 independent single-phase consumers | **one function per phase** |

The choices, grouped by area:

- **System:** Grid connection, House consumption, PV generation, Battery
- **Heat/climate:** Heat pump, Heating/heating element, Hot water, Air conditioning, Ventilation
- **Mobility:** Wallbox 1–5, Garage
- **Household appliances:** Washing machine, Dryer, Dishwasher, Oven, Stove,
  Refrigerator/freezer, Kitchen (total)
- **Other areas:** Pool, Sauna, Lighting, Server/network, Workshop,
  Other consumer

Each with an optional **custom label** (e.g. "Garage rear").

The assignment does three things:

1. **Naming + icon** — the variables concerned are then named e.g. "Heat pump — Active energy
   import" and get a matching icon.
2. **Machine-readable** — `MHUB_GetFunctions($id)` returns the mode, assignments and the
   variable IDs (power/import/feed-in) as JSON, so that the EMS or tiles can adopt the
   assignment automatically.
3. **Optional collective variables** per function (directly under the instance) that mirror the
   assigned channel — handy for charts and automations.

For separate energy meters per phase, additionally enable the group **"Energy per phase"** in the
panel "Data points" (available on the Shelly Pro 3EM).

## Sign convention

Module-wide: **+ = import** from the grid, **− = feed-in**. The PAC2200 and UMG604 already report
their total active power with a sign; if the direction does not match because of mounting or
wiring, the invert switch helps.

### Sign and "measured" in the contract `MHUB_GetFunctions` / `MHUBV_GetFunctions`

For consumers (forecast, dashboard, EMS, power flow tile) this is binding, so nobody has to guess:

- **`powerID`** always points to the power in **W**, after the invert switch has been applied. The
  direction follows the meter convention **+ = power flows into the measured branch**:
  - **`grid` (grid connection): + = import, − = feed-in.**
  - **`house` (house consumption): + = consumption.** In normal operation never permanently
    negative. A permanently negative house consumption is a wrong direction at the meter (invert
    switch) or in the formula of the virtual meter, not a measured value — consumers should not
    silently flip it.
  - **Consumer functions** (heat pump, wallbox, other consumer …): + = consumption.
  - **`pv` and `battery`: no fixed sign.** A generator sub-meter measures with + or − depending
    on installation; for `pv`, consumers use the absolute value (this is also how the module's own
    direction check works).
- **Checked** is the direction only at the grid connection (diagnostics `MHUB_GetDiagnostics`,
  direction check). MeterHub does not check it for `house`; with the **virtual meter**, the user
  bears the responsibility through the shares (+/−) of the formula: result = Σ (share × value),
  "House consumption" means that positive consumption results.
- **`measured` and `energyMeasured` are two separate questions.** `measured` = is the *power*
  measured (contract 1.0); `energyMeasured` = is the *meter reading* measured or extrapolated from
  the power (contract 1.3, `false` only for the blue'Log data logger address 97). The **real
  MeterHub delivers only `energyMeasured`** — a locally read power is always measured, a field of
  its own for it would be ballast; if `measured` is missing, `true` applies. Since **0.31.5** it
  additionally reports `calculatedEnergyIDs` (contract 1.4, instance level): the variable IDs whose
  meter reading is extrapolated — also for meters without a function. The **virtual meter delivers
  `measured: true`** (calculation result of measured meters) and since **0.31.5** `energyMeasured`
  (contract 1.5, on the instance **and** on the assignment): `false` as soon as an incoming term
  (share ≠ 0, not suspended) has an extrapolated meter reading — an "Energy extrapolated" variable
  of its own, a variable from the `calculatedEnergyIDs` of a MeterHub, or a chained virtual meter
  that itself reports `false`. Foreign variables without a known extrapolation count as measured
  (contract default if the field is missing).

### MeterHubVirtual (virtual meters)

Builds **virtual meters from a formula**. This instance is the top level, each member is a term
with a **share in percent**: result = Σ (share × value), separately for power, import and
feed-in. 100 % adds fully, −100 % subtracts fully, any value in between is a partial share (up to
two decimal places, e.g. exactly a third).

*Example:* "House connection" (+100 %), "Heat pump" (−100 %) and "Wallbox" (−100 %) give the
unknown remainder of the house — everything that neither the heat pump nor the wallbox consumes.
Further patterns: *collecting* (refrigerator + well pump, each +100 %), *passing through* (just one
row) and *splitting* (the same variable with 60 % in one instance and 40 % in a second one, e.g.
for two tenants).

**Members:** New instances take their members from the **object tree**: everything that hangs as
a link or directly under the instance is a member, in the order of its position there. A link to
another virtual meter instance nests it (e.g. several wallbox meters under "Vehicle charging");
circular references are detected and blocked, a link with a deleted target appears as "target
missing". Instances with the earlier row table keep calculating unchanged and can be converted into
object tree members with a button (the old table is backed up). Instances from the time of the
abbreviation/"hangs behind" model (before 0.24.0) are not taken over blindly: a migration panel
shows the previous rows as a proposal, nothing is taken over automatically.

**Checking instead of guessing:** What the formula does not prevent is reported by the check: the
same data point in two rows of the same instance (it would be counted twice), a member that
points to the instance itself, circular references and **mixed units** (W next to kW would
silently give wrong values). Across several instances the same variable may deliberately occur
proportionally (*splitting*). If the same device is a member twice (e.g. a wallbox via ChargerHub
**and** OCPPHub), the check names both with the reason; until you decide, only the first
connection counts. For blocking errors the module deliberately **does not calculate** — better no
value than a wrong one.

The instance can be given a **function** (grid connection, house consumption, heat pump …), once
for the whole instance. Via `MHUBV_GetFunctions($id)` — the same contract as the main module —
virtual meters thus appear automatically in the InverterHub power flow tile and in the Sankey
diagram.

**Members to the outside (`members`, contract 1.3):** Every assignment — and, independent of the
function, the instance itself — delivers the list of its terms (`name`, `factor` in percent,
`powerID`, `energyImportID`, `energyExportID`). This lets a consumer such as the NRG dashboard
"unfold" a collective node: if a term is itself a virtual meter again, its `MHUBV_GetFunctions()`
delivers the next level — MeterHubVirtual deliberately delivers only its own level, the recursion
is done by the consumer. Negative shares are **not** left out ("House consumption without
wallboxes" consists of house connection +100 % and wallboxes −100 %); the consumer recognizes
"subtracted" by the sign of `factor`. The instance level is the reason why a pure intermediate
node (e.g. "Light upper floor") needs no function: `assignments` stays empty there, `members`
does not.

**Switch group (`switchID`/`switchStateID`, contract 1.4):** Many members are actuators that
switch AND measure (e.g. Z-Wave switching actuators with power measurement) — a virtual meter such
as "Light upper floor" is thus technically not just a sum but also a switch group. In the row table
the column "Switch" carries the Boolean variable of the respective member (`SwitchID` in `Nodes`,
`switchID` in the contract) — proposed automatically when a device is taken over (a unique Boolean
variable with an action on the same device); with several switch channels deliberately NOT
guessed but reported as a notice; the button "🔀 Find switches for existing rows" fills this in for
older rows. If there is at least one member with a **positive** share and a switch, two further
variables are created on the instance: "Switch group" (Boolean, switchable) and "Group state" (0
off / 1 partial / 2 on). **Only positive shares are switched along** — an "off" on "House
consumption without wallboxes" must not drop any wallbox (−100 %); individually a subtracted
member can still be switched via its own `switchID`. Also intended for chained virtual meters: the
group switch of a subgroup is a perfectly normal switchable member for the parent group.
`MHUBV_SwitchGroup($id, $on)` is also open for scripts.

**Finding meters automatically:** The button "🔎 Search for meters in the system" searches the
installation for data points with a W/kW or kWh profile — that is, for the measured values of
sockets, light and blind switches, intermediate meters and so on. The results are **grouped by
device** (power and energy of the same device land in one row), and the **label comes from the
device name**.

The search checks: data points without a usable unit are skipped, ones already entered are not
proposed twice, and **outputs of virtual meters stay excluded** — otherwise a calculated value
would flow back in as a source. Likewise **variables from known NRG-Stack modules** stay out
(EMS, InverterHub, ChargerHub, Forecast, Tibber Grid Rewards, StromGedacht, HeishaMon, Tessie,
Device swap (formerly MigrationsHub), Moving average, SteuerboxHub, GoodweET) — they are already
correctly integrated there, and a calculated value (e.g. a house load determined by the EMS)
could otherwise accidentally flow back into a calculation it originates from. In addition it is
reported if an energy meter is not archived or a power has not been updated for more than a week.

In a grown installation the search quickly finds three-digit numbers of data points. Four
**filters** narrow it down — search area (only below an object), name component, "only devices
with an energy meter" (hides switches that merely report the instantaneous power) and "only
updated in the last 7 days" (hides dead entries). They take effect **immediately on click**, even
without applying first. The result names the search area used and lists what failed on what —
if everything was filtered out, it says so explicitly.

The results are only a **proposal**. They are taken over via "Select result", via "Meter instance
or device" (power, import and feed-in are found automatically at the device) or via "Detect
device family" (several identical devices of a category at once, e.g. MDT AZI or bidirectional KNX
meters). In object tree mode, "Link as member" creates a link immediately; in table mode it is
saved only with "Apply". Which shares apply and what is subtracted is decided by the installation
— the module does not guess.

The outputs delivered are `power` (W), `energy_import` and `energy_export` (kWh), as far as the
members have corresponding data points. If a row lacks the import (e.g. pure active-power
actuators), "Extrapolate missing energy values from the power" extrapolates power × calculation
interval into a variable of its own, marked as extrapolated — not an estimate, but less precise
than a real meter. The module does not switch archiving on by itself: if it is missing, the form
offers the button "Enable archiving for these variables" at the top.

**Creating one from a meter instance:** Usually a virtual meter arises from two or three real
meters that you happen to have in front of you anyway. That is why every MeterHub instance has
the panel "🧮 Virtual meter": select the other meters involved, set the role —
*superordinate* (the own meter counts +100 %, the selected ones −100 % — gives the remainder) or
*equal rank* (all +100 % — gives the total) — and create. The formula comes prefilled, you do not
have to collect the variable IDs by hand.

The generator deliberately does two things *not*: it creates **no second** virtual meter if this
device is already part of one — then the right place is the existing instance, otherwise double
bookkeeping begins. And it transfers **no function assignment**, because if the virtual instance
took the same function as the real meter, the consumer would appear twice in the tile and the
Sankey diagram. Typically you then give the superordinate instance "House consumption": the
remainder is then everything not accounted for by the subordinate meters.

Conversely, for a meter that is already integrated, the panel shows **which virtual meter it is
part of**.

## Related project: InverterHub

MeterHub has a closely related sister repository:

- **MeterHub** (this repo): energy meters via Modbus TCP
- **[InverterHub](https://github.com/DG65/NRGInverterHub)**: inverters via Modbus TCP —
  same driver framework, same operating logic

Both modules **run standalone**; the coupling is **optional on both sides**. If the respective
other module is missing, only the additional functions are omitted — nothing breaks.

| Touchpoint | What happens |
|---|---|
| **Combined device search** | If MeterHub is installed, the network search of `InverterHubDiscovery` offers **energy meters** found directly as a MeterHub instance to create — one search finds inverters *and* meters. Conversely, `MeterHubDiscovery` still searches independently for meters only. |
| **Consumer circuits of the power flow tile** | The `InverterHubTile` takes over the **function assignment** of this module automatically as consumer circuits (kind, label, power variable). In addition, a meter with the function **"Grid connection"** feeds the grid power and one with **"House consumption"** the measured house load — the tile thus also runs entirely without an InverterHub instance. |

The basis for this is the query function `MHUB_GetFunctions($id)` (see
[Function assignment](#function-assignment-which-consumer-is-this)), which returns the mode,
assignments and variable IDs as JSON.

## Installation

Via the Symcon module management "Add" with the URL of this repository:

```
https://github.com/DG65/NRGMeterHub
```

MeterHub has been in the stable channel since version 0.32.5. In the module management, use the
default branch; pre-release builds appear first on the `beta` branch.

## Contributing / reporting problems

Feedback on wrong register values, missing data points or new supported meters is welcome as an
issue on GitHub. Particularly helpful: meter type, register/ident concerned, and observed vs.
expected value.

## License

PolyForm Noncommercial 1.0.0 — private/non-commercial use is free, commercial use requires a
separate license from the rights holder (DG65). Full text:
[LICENSE](LICENSE). Donations are welcome: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
