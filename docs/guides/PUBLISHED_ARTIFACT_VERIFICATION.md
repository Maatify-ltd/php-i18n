# Published Artifact Verification

Run this procedure with a supported PHP runtime, Composer, Git, curl, tar, diff, and jq. Use a checkout containing the exact release tag and a trusted executable search path. For another version, verify its expected tag, reference, and exact Composer constraint before running.

```bash
set -eu
package=maatify/php-i18n
tag=v1.0.0-rc.1
reference=1f19ffa012b8d967c9717f7c1269aed5a1340081
checkout=$(git rev-parse --show-toplevel)
test "$(git rev-parse "$tag^{commit}")" = "$reference"

work=$(mktemp -d)
mkdir "$work/expected" "$work/consumer" "$work/home" "$work/composer-home" "$work/cache"
git -C "$checkout" archive "$reference" > "$work/expected.tar"
tar -xf "$work/expected.tar" -C "$work/expected"
curl -qfsS "https://repo.packagist.org/p2/$package.json" > "$work/registry.json"
jq -e --arg package "$package" --arg tag "$tag" --arg ref "$reference" '
  .packages[$package][] | select(.version == $tag)
  | select(.source.reference == $ref and .dist.reference == $ref)
  | {name, version, source, dist}
' "$work/registry.json" > "$work/resolved-release.json"

isolated_composer() {
  env -i "PATH=$PATH" "HOME=$work/home" "COMPOSER_HOME=$work/composer-home" \
    "COMPOSER_CACHE_DIR=$work/cache" composer --no-plugins --no-scripts "$@"
}

cd "$work/consumer"
printf '%s\n' '{}' > composer.json
isolated_composer --version > "$work/composer-version.txt"
isolated_composer config --list --source > "$work/composer-config.txt"
printf '%s\n' 'Effective install override: --prefer-install=dist' >> "$work/composer-config.txt"
isolated_composer require "$package:${tag#v}@RC" --prefer-install=dist --no-interaction \
  > "$work/composer-install.log" 2>&1 || { cat "$work/composer-install.log"; exit 1; }
jq -e --arg package "$package" --arg tag "$tag" --slurpfile registry "$work/resolved-release.json" '
  .packages[] | select(.name == $package and .version == $tag)
  | select(."installation-source" == "dist" and ."install-path" == "../maatify/php-i18n")
  | select(.source.reference == $registry[0].source.reference
      and .dist.reference == $registry[0].dist.reference
      and .dist.type == $registry[0].dist.type and .dist.url == $registry[0].dist.url)
  | {name, version, "installation-source", "install-path", source, dist}
' vendor/composer/installed.json > "$work/installed-release.json"

installed=vendor/maatify/php-i18n
diff -qr "$work/expected" "$installed"
for file in composer.json README.md LICENSE CHANGELOG.md SECURITY.md \
  I18N_PACKAGE_REFERENCE.md docs/guides/USAGE_GUIDE.md llms.txt \
  schema/schema.i18n.sql examples/01-core-wiring-and-first-translation.php; do
  test -f "$installed/$file"
done
test ! -e "$installed/composer.lock"
test ! -e "$installed/vendor"
php -r 'require "vendor/autoload.php"; exit(class_exists(Maatify\I18n\Consumer\Service\TranslationReadService::class) ? 0 : 1);'
printf 'Installed package and verification evidence: %s\n' "$work"
```

Inspect the installed README, CHANGELOG, SECURITY policy, and consumer navigation for the exact release identity, installation instructions, and time scope of publication claims. Record the presentation result alongside the installation and content comparison. Both must pass for Published Artifact Verification to pass. The Consumer Verification Harness covers runtime behavior separately.

## Recorded result for v1.0.0-rc.1

- **Installation and identity — PASS.** On 2026-11-14, Composer 2.10.2 resolved the exact version through Packagist in a clean project with an isolated home and cache. The effective CLI preference was `--prefer-install=dist` and installed metadata confirmed `installation-source=dist` at `vendor/maatify/php-i18n`.
- **Archive and installed content — PASS.** Source and dist both referenced `1f19ffa012b8d967c9717f7c1269aed5a1340081`. The resolved dist was a ZIP from [the exact GitHub zipball](https://api.github.com/repos/Maatify-ltd/php-i18n/zipball/1f19ffa012b8d967c9717f7c1269aed5a1340081). All 209 installed files matched the tagged Git archive. Required consumer assets were present and the public translation service loaded through production autoload.
- **Release presentation — NOT PASS.** The installed README, SECURITY policy, CHANGELOG, and `llms.txt` describe the RC as unpublished without limiting those claims to qualification time. Correct the distributed documentation in the next authorized release. Preserve the existing tag and distributed artifact.

**Overall Published Artifact Verification — NOT PASS.** The exact RC remains published and installable. It resolves `maatify/persistence:v1.4.0`, which Composer reports as abandoned.
