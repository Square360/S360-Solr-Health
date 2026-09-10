# S360 Solr Health

Read-only Solr diagnostics for Search API sites. Answers "what does this
environment's Solr core actually hold" without a drush session, and puts the
answer on the status report where a deploy check will see it.

Born on 2026-09-10, when a LIVE→DEV database clone left a site's Search API
tracker reading "9,556 of 9,556 indexed" while DEV's own Solr core was empty.
Nothing in Drupal said so until a search page came back blank.

## What it gives you

1. **Status report rows** (`/admin/reports/status`): one per Solr-backed
   index, showing tracked / indexed / in-Solr counts. A warning when Solr is
   empty while the tracker has items (the post-clone shape) or holds fewer
   documents than the tracker claims; an error when the server is unreachable.
2. **`drush s360:solr:health`**: the same table in the terminal, for release
   checklists and post-clone steps. `--format=json` for scripts.
3. **`/admin/reports/solr-health`** (permission *Use the Solr health console*,
   restricted): pick an index, type `q`, filter queries, sort, field list,
   facets, `debugQuery`, and see counts, facets, documents, and the raw JSON.

## How it reaches Solr

Through the Search API server's connector, so it works on Pantheon Search
and on any other `search_api_solr` backend, and each environment sees its
own core. Only the `select` handler is ever called; there is no write path.
Rows are capped at 50 per request: this is for looking, not exporting.

## Install

```
composer require square360/s360_solr_health
drush en s360_solr_health
```

Requires `drupal/search_api` and `drupal/search_api_solr`. Grant *Use the
Solr console* to the roles that should see the page; the status report and
the drush command need nothing extra.

## After a database clone

The tracker travels with the database; the Solr core does not. After any
LIVE→DEV or LIVE→multidev clone, run `drush s360:solr:health`, expect the
"empty" state, and reindex:

```
drush search-api:reset-tracker
drush search-api:index
```
