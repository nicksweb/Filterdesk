# Contributing

Thank you for helping. Filterdesk is built for schools, so changes should keep it simple to run, safe by default and free of school-specific details.

## Getting started

```bash
git clone https://github.com/nicksweb/Filterdesk.git && cd Filterdesk
docker run --rm -v "$PWD":/app -w /app composer:2 install
docker run --rm -v "$PWD":/app -w /app php:8.5-cli php vendor/bin/phpunit
```

The **simulation provider** (Settings → Network provider → Simulation) stands in for UniFi, so most work needs no controller.

## Before opening a pull request

- Add or update tests in `tests/Feature` for the behaviour you change. The whole suite must pass.
- Run Pint: `docker run --rm -v "$PWD":/app -w /app php:8.5-cli php vendor/bin/pint`.
- Keep pages working with the controller, probes and category service offline (`tests/Feature/OfflineResilienceTest.php` loads every page that way; add new pages to it).
- Never commit `.env`, override compose files, database dumps, screenshots of real schools, or any school name, domain, address or credential.
- Update `CHANGELOG.md` under the next version, and the docs a user would read.
- Writing: short sentences, plain words, and say what the user sees.

## Versions

Calendar versions `YYYY.MM.N`: the year and month of the release, then the release number in that month.

## Reporting a security problem

Do not open a public issue. Email the maintainer listed on the repository, with the steps to reproduce.
