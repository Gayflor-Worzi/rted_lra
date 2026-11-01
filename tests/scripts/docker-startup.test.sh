#!/bin/sh
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
startup_script="$repo_root/deploy/start-api.sh"
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT
mkdir "$temp_dir/bin"

cat > "$temp_dir/bin/php" <<'STUB'
#!/bin/sh
printf 'php %s\n' "$*" >> "$STARTUP_LOG"
case "$*" in
    *migrate*)
        if [ "${FAIL_MIGRATION:-0}" = 1 ]; then
            printf '%s\n' 'simulated migration failure' >&2
            exit 42
        fi
        ;;
esac
STUB

cat > "$temp_dir/bin/apache2-foreground" <<'STUB'
#!/bin/sh
printf '%s\n' apache2-foreground >> "$STARTUP_LOG"
STUB

chmod +x "$temp_dir/bin/php" "$temp_dir/bin/apache2-foreground"

expected="$temp_dir/expected"
printf '%s\n' \
    'php artisan migrate --force --no-interaction' \
    'php artisan storage:link --force' \
    'apache2-foreground' > "$expected"

STARTUP_LOG="$temp_dir/success.log" PATH="$temp_dir/bin:$PATH" sh "$startup_script"
cmp "$expected" "$temp_dir/success.log"

set +e
failure_output=$(STARTUP_LOG="$temp_dir/failure.log" FAIL_MIGRATION=1 PATH="$temp_dir/bin:$PATH" sh "$startup_script" 2>&1)
failure_status=$?
set -e

if [ "$failure_status" -ne 42 ]; then
    printf 'Expected migration failure exit 42; got %s\n' "$failure_status" >&2
    exit 1
fi

case "$failure_output" in
    *'simulated migration failure'*) ;;
    *)
        printf '%s\n' 'Migration error was not visible in startup output.' >&2
        exit 1
        ;;
esac

if grep -Fq 'apache2-foreground' "$temp_dir/failure.log"; then
    printf '%s\n' 'Apache started after migration failure.' >&2
    exit 1
fi

printf '%s\n' 'Docker startup migration tests passed.'
