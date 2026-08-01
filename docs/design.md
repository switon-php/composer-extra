# Design Rationale {#design-rationale}
`switon/composer-extra` turns Composer `extra` metadata into one runtime cache so framework components share one discovery surface instead of inspecting Composer internals on every request.

## See also {#see-also}

- [Composer Extra Package](README.md) — usage, cache shape, and runtime surface
- [FAQ](faq.md#composer-extra-faq) — common questions and troubleshooting

---

## Core Problem {#core-problem}

Frameworks built on Composer often need package-published metadata at runtime: providers, commands, listeners, scheduled tasks, queue jobs. Without a shared layer, each component drifts:

- one package re-implements installed-package scanning;
- another reads `composer.json` on every request;
- a third caches metadata in its own location with its own format.

The framework needs one cache and one reader so discovery costs are paid once at install/update time.

## Primary Goal {#primary-goal}

The package writes one JSON cache during Composer hooks and exposes one reader at runtime.

- **Benefit:** runtime startup stays simple and predictable.
- **Benefit:** every framework component reads the same shape with the same ordering rules.
- **Cost:** metadata freshness follows Composer runs, not live package inspection.

## Core Choice {#core-choice}

Cache writes happen at install/update time; runtime reads are lazy and validated.

- the cache file lives at `vendor/switon/composer-extra.json`;
- `health()` reports cache state without throwing, so callers can decide whether to fail loud or fall back;
- `all()` and `collect()` throw on missing cache because runtime code that needs discovery cannot continue silently;
- path-installed packages overlay live `composer.json` data during local development so monorepo edits show up without a second Composer run.

## Trade-Off {#trade-off}

Cache refresh is best-effort: failures are reported but do not abort Composer.

- **Benefit:** dependency operations stay resilient when one package has malformed metadata.
- **Cost:** the cache may be missing, stale, or invalid until Composer runs again successfully; consumers should call `health()` to detect that state explicitly.
