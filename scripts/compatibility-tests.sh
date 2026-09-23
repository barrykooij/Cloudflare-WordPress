#!/bin/bash
#
# Runs the integration suite once per third-party plugin listed in
# tests/Integration/Compatibility/Plugins.json, each time with only that plugin
# (and the plugins it requires) active next to Cloudflare.
#
# Needs the compatibility environment: npm run env:compat:start
#
# Usage:
#   scripts/compatibility-tests.sh              # every plugin in the list
#   scripts/compatibility-tests.sh woocommerce  # only the given slugs

set -uo pipefail

CONFIG=".wp-env.compat.json"
LIST="tests/Integration/Compatibility/Plugins.json"
RESULTS=()
FAILED=0

# wp-env reads stdin, which would swallow the plugin list the loop reads.
wp() {
    npx wp-env --config="$CONFIG" run cli wp "$@" 2>/dev/null < /dev/null
}

# "slug|name|space separated required slugs|skip reason" per line. The
# separator must not be whitespace: read merges adjacent whitespace separators,
# which would drop an empty field.
plugins() {
    node -e '
        const list = require(require("path").resolve(process.argv[1]));
        const only = process.argv.slice(2);
        for (const p of list) {
            if (only.length === 0 || only.includes(p.slug)) {
                console.log([p.slug, p.name, p.requires.join(" "), p.skip || ""].join("|"));
            }
        }
    ' "$LIST" "$@"
}

while IFS='|' read -r slug name requires skip; do
    echo
    echo "==> ${name} (${slug})"

    if [ -n "$skip" ]; then
        echo "Skipped: ${skip}"
        RESULTS+=("SKIP  ${name}: ${skip}")
        continue
    fi

    set -- $requires "$slug"

    for plugin in "$@"; do
        wp plugin is-installed "$plugin" || wp plugin install "$plugin" --quiet || true
    done

    if ! activation=$(wp plugin activate "$@" 2>&1); then
        if grep -qi "requires" <<< "$activation"; then
            echo "Skipped: ${activation}"
            RESULTS+=("SKIP  ${name}: requirements not met")
        else
            echo "${activation}"
            RESULTS+=("FAIL  ${name}: could not be activated")
            FAILED=1
        fi
        wp plugin deactivate "$@" --quiet || true
        continue
    fi

    # Plugins such as WooCommerce redirect the first admin request after
    # activation to their setup wizard.
    wp transient delete --all --quiet || true
    wp option update cloudflare_test_compatibility_plugin "$slug" --quiet

    if output=$(npx wp-env --config="$CONFIG" run cli --env-cwd=wp-content/plugins/cloudflare \
        php vendor/bin/phpunit -c phpunit-compatibility.xml.dist 2>&1 < /dev/null); then
        RESULTS+=("PASS  ${name}: $(grep -E '^(OK|Tests:)' <<< "$output" | tail -1)")
    else
        echo "$output" | grep -vE '^(ℹ|✔|✖)'
        RESULTS+=("FAIL  ${name}: $(grep -E '^(OK|Tests:)' <<< "$output" | tail -1)")
        FAILED=1
    fi

    wp option delete cloudflare_test_compatibility_plugin --quiet || true
    wp plugin deactivate "$slug" $requires --quiet || true
done < <(plugins "$@")

echo
echo "==> Summary"
printf '%s\n' "${RESULTS[@]}"

exit "$FAILED"
