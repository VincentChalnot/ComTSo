#!/bin/bash
# Tag of the published base image ghcr.io/vincentchalnot/comtso-base: a hash of the Dockerfile block between the `base-image` markers.
#   docker/base-hash.sh            print the hash
#   docker/base-hash.sh --check    fail unless compose.yaml pins the current hash (CI)
#   docker/base-hash.sh --write    pin the current hash in compose.yaml (run after editing the base block)
set -euo pipefail

cd "$(dirname "$0")/.."
hash=$(sed -n '/^###> base-image ###$/,/^###< base-image ###$/p' Dockerfile | sha256sum | cut -c1-16)
[ -n "$hash" ] || { echo "base-image markers not found in Dockerfile" >&2; exit 1; }
pinned=$(sed -n 's|.*comtso-base:\([0-9a-f]\{16\}\)}.*|\1|p' compose.yaml)

case "${1:-}" in
	'') echo "$hash" ;;
	--check)
		if [ "$pinned" != "$hash" ]; then
			echo "compose.yaml pins comtso-base:${pinned:-<none>} but the Dockerfile base block hashes to $hash: run docker/base-hash.sh --write" >&2
			exit 1
		fi
		;;
	--write)
		sed -i "s|\(comtso-base:\)[0-9a-f]\{16\}\(}\)|\1$hash\2|" compose.yaml
		echo "compose.yaml now pins comtso-base:$hash"
		;;
	*) echo "usage: $0 [--check|--write]" >&2; exit 2 ;;
esac
