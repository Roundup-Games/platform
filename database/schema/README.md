# Database Schema

This directory contains the squashed schema baseline for the platform.

## How it works

`pgsql-schema.sql` is a `pg_dump --schema-only` snapshot of the complete
database structure (tables, indexes, constraints, triggers, sequences) plus
the `migrations` ledger rows marking all 199 original migrations as applied.

On a **fresh install**, `php artisan migrate` detects an empty migrations
table and loads this file via `psql` before running any migration files. This
reconstructs the full schema in one step instead of replaying 199 migrations.

On **existing environments** (production, any DB that already has migrations
recorded), the schema dump is never consulted — `migrate` runs only new
migration files as normal.

## Making schema changes

Create new migrations exactly as before:

```bash
php artisan make:migration add_foo_to_bars_table
```

New migrations stack on top of the baseline at `batch 2+`. They are never
appended to this file.

## What lives here vs. in models

- **Models** (`app/Models/*.php`) are the authoritative, readable source for
  understanding entity structure — columns, casts, relationships.
- **`db:table <name>`** returns the live DB structure for any table.
- **This dump** is a load artifact, not a reference document. Agents should
  read models and run `db:table`, not ingest this file wholesale.

## DB-level constructs not expressed in models

Three schema elements exist only here (not visible in Eloquent models):

1. **`locations` geohash trigger** — auto-computes `geohash_4` from
   `latitude`/`longitude` on INSERT/UPDATE. See the `CREATE FUNCTION` and
   `CREATE TRIGGER` statements.
2. **`pg_trgm` GIN indexes** — fuzzy text search indexes on several
   varchar/text columns (searchable via `WHERE col % 'term'`).
3. **`game_systems` GIN index** — on the `name` jsonb column for fast
   JSONB key lookups.

To find them: `grep -E 'CREATE TRIGGER|USING gin' pgsql-schema.sql`
