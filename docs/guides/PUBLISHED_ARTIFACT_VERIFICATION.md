# Published Artifact Verification

Use a supported PHP runtime and Composer, Git, tar, diff, and jq. Run the following from a repository checkout containing the exact release tag. Use a new Composer root with no local repository overrides. For a future release, set the tag and expected reference to the exact published identity.

```bash
set -eu
tag=v1.0.0-rc.1
reference=1f19ffa012b8d967c9717f7c1269aed5a1340081
checkout=$(git rev-parse --show-toplevel)
test "$(git rev-parse "$tag^{commit}")" = "$reference"
work=$(mktemp -d)
mkdir "$work/expected" "$work/consumer"
git -C "$checkout" archive "$reference" > "$work/expected.tar"
tar -xf "$work/expected.tar" -C "$work/expected"

cd "$work/consumer"
composer require "maatify/php-i18n:${tag#v}@RC" --prefer-dist --no-interaction
jq -e --arg tag "$tag" --arg ref "$reference" '
  .packages[] | select(.name == "maatify/php-i18n")
  | select(.version == $tag and ."installation-source" == "dist")
  | select(.source.reference == $ref and .dist.reference == $ref)
  | select(."install-path" == "../maatify/php-i18n")
' vendor/composer/installed.json >/dev/null

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
```

## Recorded result

On 2026-11-14, a clean Composer project installed Packagist `maatify/php-i18n:v1.0.0-rc.1` in `dist` mode at `vendor/maatify/php-i18n`. The source and dist references were both `1f19ffa012b8d967c9717f7c1269aed5a1340081`. All 209 installed files matched the tagged Git archive. Required consumer assets were present, and the public translation service loaded through production autoload.

The published RC resolved `maatify/persistence:v1.4.0`, which Composer reports as abandoned. This record verifies the published artifact identity and contents. The repository Consumer Verification Harness verifies runtime behavior separately against the checkout.
