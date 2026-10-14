# Laratesto agent skills

These skills ship with `ichinya/laratesto` and describe its Laravel integration.
Each directory is independently copyable; references and scripts stay inside it.

| Skill | Use when |
| --- | --- |
| [laratesto-configure](laratesto-configure/SKILL.md) | Registering LaravelPlugin, choosing an application root or diagnosing configuration. |
| [laratesto-write-tests](laratesto-write-tests/SKILL.md) | Writing Laravel HTTP, database, fake or Artisan tests under Testo. |
| [laratesto-migrate-from-phpunit](laratesto-migrate-from-phpunit/SKILL.md) | Migrating Laravel PHPUnit tests with the optional Rector package and verifying preservation. |

Use the installed version's source when an API differs. Testo supplies the general
testing skills; these skills add Laravel behavior and support boundaries. See the
[Testo skills index](https://github.com/php-testo/testo/tree/0.10.55/skills).

Install through the optional [llm/skills](https://github.com/roxblnfk/skills) Composer
plugin, or copy selected directories into a skills root your agent reads. The
consumer chooses its target, aliases and trusted donors. Package metadata only
declares `extra.skills.source`; it does not configure the consumer's agent.
