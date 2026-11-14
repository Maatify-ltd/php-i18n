#!/usr/bin/env bash
#
# Documentation consistency gate (links, anchors, identity, release and
# Host-name claims, llms.txt shape). See check-docs.php.
#
# Entry point: `composer check:docs`.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${I18N_PHP_BIN:-php}"
"${PHP_BIN}" "${ROOT}/scripts/ci/check-docs.php"

probe_dir="$(mktemp -d)"
trap 'rm -rf "${probe_dir}"' EXIT
git -C "${ROOT}" clone --quiet --local --no-hardlinks . "${probe_dir}"
cp "${ROOT}/scripts/ci/check-docs.php" "${probe_dir}/scripts/ci/check-docs.php"
"${PHP_BIN}" "${probe_dir}/scripts/ci/check-docs.php" >"${probe_dir}/baseline.log" 2>&1 || {
    cat "${probe_dir}/baseline.log" >&2
    exit 1
}

# Use octal bytes for the consumer name so the fixture stays outside package docs.
readme="${probe_dir}/README.md"
cp "${readme}" "${probe_dir}/clean-readme"
for reference in '\101\164\150\141\162' '\141\164\150\141\162' 'LanguageCore' 'AdminKernel'; do
    cp "${probe_dir}/clean-readme" "${readme}"
    printf '\n%b\n' "${reference}" >>"${readme}"
    if "${PHP_BIN}" "${probe_dir}/scripts/ci/check-docs.php" >"${probe_dir}/negative.log" 2>&1; then
        echo '[i18n-docs] ERROR: the gate accepted a Host reference' >&2
        exit 1
    fi
    if ! grep -Eq '^  README\.md: .*Host name inside Package documentation$' "${probe_dir}/negative.log"; then
        cat "${probe_dir}/negative.log" >&2
        exit 1
    fi
done
echo '[i18n-docs] Host reference regression checks passed'
