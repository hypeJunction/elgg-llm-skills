# Project structure and Docker environments

## Docker Environments

| Version | PHP | MySQL | Port | Status |
|---------|-----|-------|------|--------|
| 3.x | 7.4 | 5.7 | 8380 | Working |
| 4.x | 7.4 | 5.7 | 8480 | Working |
| 5.x | 8.2 | 5.7 | 8580 | TODO |
| 6.x | 8.2 | 8.0 | 8680 | TODO |

## Project Structure

```
elgg-migrate/
├── docker-compose.yml               # Root: migrate service (AST tool)
├── docker/
│   ├── migrate/Dockerfile           # PHP 8.1 + php-parser for AST rules
│   ├── elgg3/                       # Elgg 3.x: elgg + db + node services
│   │   ├── docker-compose.yml
│   │   ├── docker-compose.override.yml
│   │   └── Dockerfile
│   └── elgg4/                       # Elgg 4.x: elgg + db + node services
│       ├── docker-compose.yml
│       ├── docker-compose.override.yml
│       └── Dockerfile
├── skills/
│   ├── elgg-migrate/SKILL.md        # This file
│   ├── elgg-test-writer/SKILL.md    # PHPUnit + Playwright test writing
│   ├── elgg-js-test-writer/SKILL.md # Vitest JS test writing
│   ├── elgg-site-upgrade/SKILL.md   # Full site upgrade workflow
│   └── elgg-plugin-fleet/SKILL.md   # Batch plugin migration
├── bin/migrate.php                   # CLI runner (runs in migrate container)
├── src/Rules/V2ToV3/                 # 18 automated rules
├── src/Rules/V3ToV4/                 # 12 automated rules
├── rules/2x-to-3x/                  # 28+ rules (18 auto + LLM)
├── rules/3x-to-4x/                  # 30 rules (13 auto + 17 LLM)
├── tests/                            # 217 tests, 1022 assertions
└── tmp/                              # Guinea pig plugins (gitignored)
```
