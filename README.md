# RegionalEU

A [qflows](https://example.com) plugin that adds steps and a trigger for the European Union's public services:

| Step / trigger | Service | What it does |
|---|---|---|
| **VIES VAT Check** | [VIES](https://ec.europa.eu/taxation_customs/vies/) (European Commission) | Checks EU VAT numbers in the member states' registers: whether each one is valid, plus the trader's name and address, a consultation number and trader matching. Also shows which registers are available right now. |
| **ECB Exchange Rates** | [ECB euro reference rates](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/) | Gets a day's rates, converts an amount, or returns the rates between two days (history back to 1999). |
| **TED Tenders** | [TED](https://ted.europa.eu) Search API v3 | Searches public procurement notices, gets one notice, or saves a notice's PDF / XML (eForms) in the project's Files. |
| **TED notice** (trigger) | TED Search API v3 | Starts the workflow for each new notice the filters find. It polls on a schedule (hourly by default). |

All of these services are public, so **you don't need a credential**. The steps appear in the designer's **Regional** group for every account on the installation.

## Installation

The plugin lives in its own repository and is cloned into the core's `app/plugins/` folder (which the core's `.gitignore` ignores):

```sh
cd app/plugins
git clone git@github.com:toolmk/regional-eu.git RegionalEU
```

Turn it on in the installation's `plugins.json`, which sits next to `app.env` (or in `NFN_CONFIG_DIR`). The entry is the same as `plugins.json.example`:

```json
{
    "RegionalEU": {
        "enabled": true
    }
}
```

Restart the daemon so that it loads the steps.

## Steps

### VIES VAT Check

- **Check VAT numbers**: checks one or more numbers, given with their country prefix or with a default **Country**. You can separate them with commas or new lines, or pass a list of strings or of objects with `vat` / `vatNumber`. Numbers with the wrong format are never sent; they're listed under `invalid`. A member state whose register doesn't answer isn't treated as an error: that item gets an `error` (e.g. `MS_UNAVAILABLE`) and is listed under `unavailable`.
  - **Your VAT number** (optional): if you give it, VIES returns a consultation number as proof of the check.
  - **Compare name / street / postal code / city / company type** (advanced): the states that support it report whether each field matches.
  - Result: `{ items, count, valid, first, invalid, unavailable }`
- **Member states available**: `{ available, countries { AT: "Available", ... }, unavailable }`

Greece is `EL` (`GR` is also accepted) and Northern Ireland is `XI`.

### ECB Exchange Rates

All rates are units of the currency for 1 EUR. About 30 currencies are published each TARGET day, at around 16:00 CET.

- **Get exchange rates**: the rates for a day, or the latest if no day is given. Days without rates (weekends, holidays) use the last earlier day that has them. Result: `{ date, requested, base, rates }`
- **Convert an amount**: converts from one currency to another. A conversion between two non-euro currencies goes through the euro. Result: `{ amount, from, to, rate, result, date, requested }`
- **Rates between two days**: `{ items: [ { date, rates } ], count, total, truncated, base, from, to }`

Days accept `YYYY-MM-DD` or relative values such as `-30 days`. The files the plugin reads are cached in the process.

### TED Tenders

- **Search notices**: newest first. Filters:
  - keywords (all must match)
  - buyers' countries (`RO` or `ROU`)
  - CPV codes (sub-codes match too)
  - kinds
  - published from / to
  - deadline on or after a day
  - an **Expert query** in TED's own syntax, combined with the other filters by AND
  - **Notices**: active, latest version, or all
- **Get a notice**: by publication number (`787856-2026` or `2026/S 219-787856`). Returns `null` when TED has no such notice.
- **Download**: saves the notice's PDF (in the step's language) or XML to the project's Files. Result: `{ id, filename, size, contentType, publicationNumber }`

Each notice includes `publicationNumber, title, buyer, buyerCountry, publicationDate, noticeType, formType, procedureType, contractNature, cpv, deadline` (the earliest of its lots), `estimatedValue` (the sum of its lots), `currency, placeOfPerformance, url, pdf, xml`. You can request any other TED fields with **More fields**; they come back in `fields`. Titles and buyers' names are given in the step's **Language**, falling back to English, then to the first language available.

### TED notice (trigger)

The trigger uses the same filters as **Search notices**. **Runs** is either *One run per notice* (the variable holds the notice) or *One run per check with notices* (the variable holds `{ notices, count }`).

The first check only records the notices already published that day, so it starts no runs. After that, each check starts runs for the notices it hasn't seen yet, oldest first. Each check starts a limited number of runs, and any remaining notices are picked up by the next check. TED publishes once each working day.

## AI steps

The steps can also be used as tools by AI steps. These tool families are public, so no credential is needed:

| Family | Tools |
|---|---|
| `euVies` | `eu_vies_check`, `eu_vies_status` |
| `euEcb` | `eu_ecb_rates`, `eu_ecb_convert`, `eu_ecb_history` |
| `euTed` | `eu_ted_search`, `eu_ted_get`, `eu_ted_download` (the only one that writes) |

## Layout

```
Plugin.php              registers the steps, the AI families (hook agent:families) and the check suite (hook check:suites)
models/node/            NodeAppEu (base), NodeAppEuVies, NodeAppEuEcb, NodeAppEuTed, NodeTriggerEuTed
util/                   Vies, Ecb, Ted: the clients for each service
checks.php              the `regionaleu` check suite
tests/EuTestServer.php  a fake VIES / ECB / TED server used by the checks
```

## Checks

```sh
app/v check::run --only=regionaleu
```

The suite runs against `tests/EuTestServer.php` (PHP's built-in server), not against the real services.

## License

GNU Affero General Public License v3.0. See [LICENSE](LICENSE).
