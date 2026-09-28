#!/usr/bin/env bash
# Full NBK check suite: static checks once, then php -l and the runtime smoke
# run on every PHP the VPS fleet uses. A missing interpreter is reported as
# SKIP, never silently ignored.
#
# Usage:  tests/run.sh               # full matrix 7.4 8.1 8.2 8.3 8.4 8.5
#         tests/run.sh 7.4 8.5       # chosen versions only
#         NBK_REQUIRE_ALL=1 tests/run.sh   # treat SKIP as FAIL (CI)
#
# Exit code: 0 = all executed checks passed, 1 = something failed.
# Written for macOS bash 3.2: no associative arrays, no ${var,,}.

set -u
cd "$(dirname "$0")/.." || exit 1

VERSIONS="${*:-7.4 8.1 8.2 8.3 8.4 8.5}"
BOUNDARY="7.4 8.5"
failed=""
skipped=""

# Echo the path of an interpreter whose major.minor is exactly $1.
find_php() {
	local bin
	for bin in "/opt/homebrew/opt/php@$1/bin/php" "/usr/local/opt/php@$1/bin/php" \
		"$(command -v "php$1" 2>/dev/null)" "$(command -v php 2>/dev/null)"; do
		[ -n "$bin" ] && [ -x "$bin" ] || continue
		if [ "$("$bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)" = "$1" ]; then
			echo "$bin"
			return 0
		fi
	done
	return 1
}

PHP_DEFAULT="$(command -v php 2>/dev/null)"
if [ -z "$PHP_DEFAULT" ]; then
	echo "FAIL no php in PATH"
	exit 1
fi

echo "== static ($("$PHP_DEFAULT" -r 'echo PHP_VERSION;'))"
"$PHP_DEFAULT" -d error_reporting=-1 -d display_errors=1 tests/static.php || failed="$failed static"

# List the files once. find runs apart from sort so its exit status is not
# lost in a pipe; the files are counted so an empty list or a heredoc that
# was never created cannot pass as a clean lint.
php_files="$(find . -name '*.php' -not -path './.git/*' -not -path './graphify-out/*')"
list_status=$?
php_files="$(printf '%s\n' "$php_files" | sort)"
php_total="$(printf '%s\n' "$php_files" | grep -c .)"

for v in $VERSIONS; do
	echo
	if ! bin="$(find_php "$v")"; then
		echo "== PHP $v: SKIP (not installed; brew install shivammathur/php/php@$v)"
		skipped="$skipped $v"
		continue
	fi

	echo "== PHP $v lint ($bin)"
	lint_ok=1
	checked=0
	while IFS= read -r f; do
		# An empty list still yields one blank line from the heredoc.
		[ -n "$f" ] || continue
		checked=$((checked + 1))
		out="$("$bin" -d error_reporting=-1 -d display_errors=1 -l "$f" 2>&1)"
		# Anything besides the single success line (e.g. compile-time
		# deprecations such as implicit nullable types on 8.4+) is a failure.
		if [ "$out" != "No syntax errors detected in $f" ]; then
			echo "FAIL $f"
			echo "$out" | sed 's/^/     /'
			lint_ok=0
		fi
	done <<EOF
$php_files
EOF
	# Checked after the loop so a loop that never ran still counts as a failure.
	if [ "$list_status" != 0 ]; then
		echo "FAIL could not list PHP files (find exit $list_status)"
		lint_ok=0
	fi
	if [ "$checked" = 0 ]; then
		echo "FAIL no PHP files were linted"
		lint_ok=0
	elif [ "$checked" != "$php_total" ]; then
		echo "FAIL linted $checked of $php_total PHP files"
		lint_ok=0
	fi
	if [ "$lint_ok" = 1 ]; then
		echo "ok   all $checked PHP files lint clean"
	else
		failed="$failed lint-$v"
	fi

	echo "== PHP $v smoke"
	"$bin" -d error_reporting=-1 -d display_errors=1 tests/smoke.php || failed="$failed smoke-$v"
done

echo
for v in $BOUNDARY; do
	case " $skipped " in
		*" $v "*) echo "WARNING boundary version $v was not tested" ;;
	esac
done

if [ -n "${NBK_REQUIRE_ALL:-}" ] && [ -n "$skipped" ]; then
	failed="$failed skipped:$(echo $skipped | tr ' ' ',')"
fi

echo "SKIPPED:${skipped:- none}"
if [ -n "$failed" ]; then
	echo "RESULT: FAIL ($(echo $failed))"
	exit 1
fi
echo "RESULT: PASS"
