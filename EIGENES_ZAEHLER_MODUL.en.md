[Deutsch](EIGENES_ZAEHLER_MODUL.md) | English

# Connecting your own meter module to the NRG-Stack

This guide is for developers who have their **own Symcon module for a meter** (electricity, energy or power measurement)
and want to make it visible to the EMS, the dashboard and the forecast of the NRG-Stack. Your module stays an independent
module. It does not need MeterHub or any other NRG-Stack module to be installed, and MeterHub does not need your module.

The guide describes the **contract `meter`, version 1.6**. It is offered by MeterHub (`MHUB_GetFunctions`), by the virtual
meter (`MHUBV_GetFunctions`) and by the estimated meter (`MHUBE_GetFunctions`).

> **Status 20 Nov 2026:** The contract is fixed. The EMS already finds third-party wall boxes in general; the search for
> third-party meters follows. Until then the dashboard and other consumers partly still recognise meters by module GUID.

## The principle in three sentences

1. Your module offers a function `<PREFIX>_GetFunctions($id)`. It returns a **JSON text** with a list of assignments
   (`assignments`), one per measuring point ("grid connection", "house consumption", "heat pump" …).
2. Values are read through **variable IDs**. There is no query function for measured values.
3. A meter **only measures**. The contract has no control commands.

## The function `<PREFIX>_GetFunctions($id)`

- The prefix is set in the `module.json` of your module (`"prefix": "MZ"`). A public method `GetFunctions()` in the module
  class is provided by Symcon automatically as `MZ_GetFunctions($id)`.
- Return value: a **JSON text** (object) as below.
- The function must be **fast and free of side effects**: only look up variable IDs and read properties, never query the
  device. While the instance reloads, return `{"instanceID": <id>, "ready": false, "assignments": []}` instead of causing
  warnings.

## Fields at instance level

| Field | Required | Meaning |
|---|---|---|
| `contractVersion` | yes | `'1.6'`. Consumers check the major version (before the dot). If the field is missing, the lowest known version applies. |
| `contractType` | yes | always `'meter'` |
| `instanceID` | yes | ID of your instance |
| `assignments` | yes | list of assignments, see below (empty if no function is assigned to the meter) |
| `latency` | recommended | `'realtime'` (default if missing) or `'delayed'` (e.g. a cloud meter with a delayed archive) |
| `pollInterval` | recommended | polling interval in seconds |
| `authority` | recommended | `'billing'` for a calibrated, billing-relevant meter at the grid connection point, otherwise `'auxiliary'` (auxiliary meter). If missing, `'auxiliary'` applies. |
| `meter` | no | meter type or driver as free text (e.g. `'siemens_pac2200'`). **This is not the contract type.** The type is always in `contractType`. |

## Fields of an assignment (`assignments[]`)

| Field | Required | Meaning |
|---|---|---|
| `contractType` | yes | always `'meter'` |
| `function` | yes | the measuring point, see "Functions and signs" |
| `label` | yes | display name (usually the instance name) |
| `powerID` | yes | variable ID of the power in **watts** (float), `0` if not available |
| `energyImportID` | yes | variable ID of a **cumulative** meter reading in kWh (import or consumption), `0` if not available |
| `energyExportID` | no | variable ID of the cumulative feed-in reading in kWh, `0` if not available |
| `energyKind` | recommended | `'counter'` for a cumulative meter reading |
| `measured` | no | `true` (default if missing) if the **power** is measured. `false` if it is estimated or calculated. |
| `energyMeasured` | no | `true` (default if missing) if the **meter reading** is measured. `false` if it is extrapolated from the power or estimated. |
| `latency`, `authority`, `pollInterval` | recommended | as at instance level, mirrored into every assignment |
| `deviceSerial` | no | serial number of the device as text, **only if the device knows it**. It lets the EMS recognise the same hardware when it is connected through two modules. Otherwise leave it out, never invent it. |
| `phaseCurrentIDs` | no | `[L1, L2, L3]`: variable IDs of the **measured** phase currents in A, at the grid connection only. Never estimated from power and voltage. |
| `slot` | no | tells assignments of one meter apart: `'total'` (all phases together) or `'L1'`, `'L2'`, `'L3'` for per-phase measurement |

### Functions and signs

`function` names the measuring point. Usual values are `grid` (grid connection), `house` (house consumption), `pv`,
`battery` and consumers such as `heatpump`, `wallbox1`, `washer`. Which functions a consumer evaluates is up to the consumer.

The direction of the power is fixed, so nobody has to guess:

- **`grid`:** **+ = import from the grid, − = feed-in.** The EMS converts this to its own direction when reading.
- **`house`:** **+ = consumption.** A permanently negative value is a direction error at the meter, not a measurement.
  Consumers do not silently flip it.
- **Consumers** (heat pump, wall box, washing machine …): **+ = consumption.**
- **`pv` and `battery`:** no fixed sign. For `pv` consumers use the absolute value.

`powerID` always points to the power **after** any invert switch of your module.

### Cumulative meter readings

`energyImportID` and `energyExportID` must be **cumulative** readings that never go down. A value that jumps to 0 every day
or every session is not a meter reading. Archive the variable with the aggregation type "Counter".

### Estimated or measured

If your module estimates or calculates values (estimated meter, extrapolation from power), **report `measured: false`
and/or `energyMeasured: false`.** Such entries serve display and remainder calculation, but **never control**: the EMS does
not use them as a grid-connection or house-consumption measurement for decisions.

### Freshness

A separate "last seen" field is not needed for meters. Consumers check the update time of the power variable
(`VariableUpdated`). So write the variable only when the device really delivered a new value. If the device reports a value
as "not available", **do not write 0**; let the variable go stale.

## Example

```php
public function GetFunctions(): string
{
    $id = fn(string $ident) => (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    return json_encode([
        'contractVersion' => '1.6',
        'contractType'    => 'meter',
        'instanceID'      => $this->InstanceID,
        'meter'           => 'my_meter',
        'latency'         => 'realtime',
        'authority'       => 'auxiliary',
        'pollInterval'    => 10,
        'assignments'     => [[
            'contractType'   => 'meter',
            'slot'           => 'total',
            'function'       => 'grid',
            'label'          => IPS_GetName($this->InstanceID),
            'powerID'        => $id('power'),            // W, + = import
            'energyImportID' => $id('energy_import'),    // kWh, cumulative
            'energyExportID' => $id('energy_export'),    // kWh, cumulative
            'energyKind'     => 'counter',
            'measured'       => true,
            'energyMeasured' => true,
            'latency'        => 'realtime',
            'authority'      => 'auxiliary',
            'pollInterval'   => 10,
        ]],
    ]);
}
```

## Checklist

- [ ] `contractType: 'meter'` at instance level **and** in every assignment, `contractVersion: '1.6'`
- [ ] `powerID` in watts, sign as in the table above
- [ ] Meter readings cumulative and archived (aggregation type "Counter")
- [ ] Estimated or extrapolated values marked `measured: false` / `energyMeasured: false`
- [ ] Never write 0 for "not available"
- [ ] `GetFunctions()` fast, no device access, `ready: false` while reloading
- [ ] Your module runs without MeterHub and without the EMS

## Further reading

The rules for all contract types and for third-party connection (freshness, duplicate connection, control only with
approval) are in the suite manifest `SUITE.md`, section "Fremdanbindung: Module anderer Autoren" (German). A model for the
structure of such a guide is the one for wall box modules (`EIGENES_WALLBOX_MODUL.md` in the ChargerHub repository).
