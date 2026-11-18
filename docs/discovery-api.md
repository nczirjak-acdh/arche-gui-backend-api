# ARCHE Discovery API

Use the Symfony application's base URL for every request. Discovery searches public ARCHE metadata live. A local English/German vocabulary can expand a query with labels for the same RDF concept and terms linked by `skos:exactMatch`.

| Method | Endpoint | Use in React |
| --- | --- | --- |
| GET | `/api/discovery/status` | Load supported languages, scopes, date types, record types, page limits and vocabulary statistics. |
| GET | `/api/discovery/examples` | Render the 15 example queries. Each item contains `label`, `q` and `lang`. |
| GET | `/api/discovery/search` | Submit search parameters in the URL and load subsequent pages. |
| POST | `/api/discovery/search` | Submit the same parameters in a JSON request body. |
| GET | `/api/discovery/records/{id}` | Load record metadata. Use the string `id` returned by search. An optional `lang` selects the preferred display language. |
| GET | `/api/discovery/vocabulary` | Read vocabulary statistics or inspect a query with the optional `q` parameter. |

## Search request

Use `GET /api/discovery/search?q=historische%20Karten&lang=de&date_from=1800&date_to=1900` or send the following body to `POST /api/discovery/search` with `Content-Type: application/json`.

```json
{
  "q": "historische Karten",
  "lang": "de",
  "mode": "expanded",
  "scope": "main",
  "date_type": "coverage",
  "date_from": "1800",
  "date_to": "1900",
  "page": 1
}
```

| Parameter | Accepted values | Default |
| --- | --- | --- |
| `q` | UTF-8 text of 2–500 characters | Required when both date bounds are omitted |
| `lang` | `en`, `de`. This sets the preference for titles, descriptions and linked labels. Search can match metadata in any language. | `en` |
| `mode` | `expanded` combines query words and vocabulary aliases with OR. `original` passes the query as entered to ARCHE after trimming whitespace. | `expanded` |
| `scope` | `main`, `title`, `description`, `subject` | `main` |
| `kind` | `TopCollection`, `Collection`, `Resource`, `Person`, `Organisation`, `Place`, `Publication`, `Project`, `Concept` | All types |
| `date_from` | Inclusive lower date bound. Use YYYY-MM-DD or YYYY. | No lower bound |
| `date_to` | Inclusive upper date bound. Use YYYY-MM-DD or YYYY. | No upper bound |
| `date_type` | `coverage`, `created`, `original` | `coverage` |
| `page` | Integer from 1 to 1000 | `1` |

The `main` scope covers `hasTitle`, `hasAlternativeTitle`, `hasDescription`, `hasSubject` and `hasKeyword`. The `title` scope covers both title properties, `description` covers `hasDescription`, and `subject` covers `hasSubject` and `hasKeyword`. ARCHE orders results by title.

## Date filter

The date filter matches records whose recorded time span overlaps the requested interval. A single recorded endpoint counts as a single date. Either search bound can be omitted. Set both bounds to the same date to search for a single day. A year used as a lower bound starts on January 1 and a year used as an upper bound ends on December 31. Prefix a four-digit year with a minus sign for BCE, for example `-0200`. Records need at least one date of the selected type to match.

| Date type | ARCHE properties |
| --- | --- |
| `coverage` | `hasCoverageStartDate`, `hasCoverageEndDate` |
| `created` | `hasCreatedStartDate`, `hasCreatedEndDate` |
| `original` | `hasCreatedStartDateOriginal`, `hasCreatedEndDateOriginal` |

For a date-only search use `GET /api/discovery/search?date_type=coverage&date_from=1800&date_to=1900`. ARCHE applies the date conditions before counting and paginating results. The status endpoint exposes `date_types` and `default_date_type` for the filter control.

## Search response

| Fields | Use |
| --- | --- |
| `results` | Array of record objects for this page. An empty search response has `results: []`. |
| `total`, `page`, `page_size`, `pages`, `has_previous`, `has_next` | Use the flags to enable pagination buttons. The page size is 20. `pages` reflects the total reported by ARCHE and is zero for an empty search. |
| `topics`, `expansions`, `expression` | Recognized labels, aliases actually used, and the full search expression sent to ARCHE. |
| `vocabulary` | Query analysis with `available`, `topics`, `expansions`, `evidence`, and `related_suggestions`. Related suggestions come from co-occurrence and can be offered as separate searches. |
| `mode`, `lang`, `searched_properties`, `sort` | Effective settings and the searched RDF property URIs. |
| `date_filter` | Applied date filter with `type` and normalized `from` and `to` bounds, or `null`. |
| `source`, `api_url`, `fetched_at`, `milliseconds` | Source and diagnostic metadata. The React app can send its requests to the Symfony endpoints above. |

Each item in `results` and each record detail response includes the fields below. The detail endpoint also includes `fetched_at`.

| Fields | Use |
| --- | --- |
| `id`, `uri` | Numeric identifier encoded as a string, plus the ARCHE resource URI. |
| `title`, `kind`, `excerpt`, `description` | Display fields. Use `excerpt` for result cards and `description` for details. |
| `languages` | Language tags present in the returned metadata literals. |
| `matches` | Up to four ARCHE snippets with `property`, `field_label` and `value`. Render snippets as text. |
| `facts` | Metadata rows with `property`, `field_label`, `value`, `lang`, `datatype`, `is_uri` and `is_blank`. Linked resources also have a `label`. |
| `order` | ARCHE result order, or `null` if no order is present. |

The vocabulary endpoint returns `stats` and `analysis`. With no local snapshot, `stats` is `null`, `analysis.available` is `false`, and ARCHE search remains available. The examples endpoint returns `{ "examples": [...] }`. To run an example, submit its `q` and `lang` to search.

## Errors

Discovery validation and upstream errors return a JSON object with an `error` message.

| HTTP status | Meaning |
| --- | --- |
| 400 | Invalid search parameters, record identifier or JSON body |
| 404 | The ARCHE record is missing or has been removed |
| 415 | The search POST requires an application/json content type |
| 502 | ARCHE could not be reached or returned unusable data |
| 503 | ARCHE is unavailable or rate limiting requests |
| 504 | The ARCHE response exceeded the read deadline |

## Backend setup

Discovery builds the ARCHE API URL from `rest.urlBase` and `rest.pathBase` in `src/arche-config/config-gui.yaml`. The Symfony application's existing CORS configuration applies to these endpoints. Configure `CORS_ALLOW_ORIGIN` with the React app's origin in cross-origin deployments.

Rebuild Symfony's production cache after deploying these services if the deployment reuses the previous cache.

### Build the vocabulary on the server

From the backend project directory, run the Symfony command after deploying the ARCHE configuration.

```sh
APP_ENV=prod php bin/console app:discovery:build-vocabulary --from-postgres
```

The command uses the backend’s `ArcheContext` PDO connection and its `src/arche-config/config-gui.yaml` settings. The resource URL uses `rest.urlBase` and `rest.pathBase`. The public read grant uses `schema.aclRead` and `publicRole` or their equivalents under `accessControl`.

Use `--config=/path/to/config-gui.yaml` to connect using an alternate configuration. Its PostgreSQL DSN comes from `dbConnStr` or `dbConn.guest`. PHP CLI needs `pdo_pgsql`, `pdo_sqlite` and `mbstring`. The import needs read access to `resources`, `metadata` and `relations`.

If the connection fails, run the command in the PHP backend container or host, using the backend service account. The command reports a connection failure category without printing the connection details.

The PostgreSQL import uses a read-only transaction and includes active resources with an explicit public read grant. It writes the English/German vocabulary to a temporary SQLite file, checks it and replaces the current snapshot on success. A failed import leaves the current snapshot available.

To build from a local N-Triples export instead, run:

```sh
php bin/console app:discovery:build-vocabulary /path/to/arche.nt
```

Both modes publish to `smsdb/topics.sqlite` under the Symfony project root by default. Git ignores the `smsdb/` directory. Set `ARCHE_DISCOVERY_VOCABULARY` in the CLI and web process environments to use the same alternate location, such as a persistent directory shared across releases. The application accepts absolute paths and paths relative to the project root. The command user needs write and traverse access to the output directory to create the temporary snapshot and replace the published file.

Run the discovery integration checks with `php tests/discovery.php`.
