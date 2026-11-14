#!/usr/bin/env bash
set -euo pipefail

root="$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel)"
target=1.0.0-rc.2
sha="$(git -C "${root}" rev-parse --verify "${1:-HEAD}^{commit}")"
work="$(mktemp -d)"
finish() {
    local status=$?
    rm -rf "${work}"
    printf '[release-artifact] target=%s sha=%s exit=%s\n' "${target}" "${sha}" "${status}"
}
trap finish EXIT

git init --quiet "${work}/source"
git -C "${work}/source" fetch --quiet --no-tags "${root}" "${sha}"
git -C "${work}/source" checkout --quiet --detach "${sha}"
git -C "${work}/source" archive --format=tar "${sha}" > "${work}/git.tar"
composer --no-plugins --no-scripts --working-dir="${work}/source" archive --format=tar --dir="${work}" --file=composer

for format in git composer; do
    artifact="${work}/${format}"
    mkdir "${artifact}"
    tar -xf "${work}/${format}.tar" -C "${artifact}"

    for required in composer.json README.md CHANGELOG.md SECURITY.md LICENSE llms.txt \
        I18N_PACKAGE_REFERENCE.md docs/guides/USAGE_GUIDE.md examples/07-translation-key-preview.php \
        schema/schema.i18n.sql schema/translation-key-preview.sql src/Management/Service/TranslationKeyPreviewService.php; do
        test -f "${artifact}/${required}" || { echo "${format}: missing ${required}" >&2; exit 1; }
    done
    for excluded in .git vendor composer.lock dcos docs/decisions schema/migrations/2026-10-02-translation-type.sql \
        tests/Fixtures/Schema/schema.pre-s1.i18n.sql tests/Integration/TranslationTypeMigrationTest.php; do
        test ! -e "${artifact}/${excluded}" || { echo "${format}: unexpected ${excluded}" >&2; exit 1; }
    done
    unexpected="$(find "${artifact}" -name '.env' -o -name '.env.*' -o -name auth.json -o -name '*.pem' -o -name '*.key')"
    test -z "${unexpected}" || { printf '%s: unexpected files\n%s\n' "${format}" "${unexpected}" >&2; exit 1; }
    cmp "${work}/source/composer.json" "${artifact}/composer.json"
    for document in README.md CHANGELOG.md SECURITY.md llms.txt I18N_PACKAGE_REFERENCE.md docs/guides/USAGE_GUIDE.md; do
        cmp "${work}/source/${document}" "${artifact}/${document}"
    done
    printf '[release-artifact] %s archive structure and tracked content passed\n' "${format}"
done

bash "${work}/source/scripts/ci/check-docs.sh"
