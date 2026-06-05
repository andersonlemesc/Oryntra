#!/bin/sh
# Docker/Swarm secrets -> env. For any VAR_FILE pointing at a readable file,
# export VAR with the file contents (e.g. DB_PASSWORD_FILE=/run/secrets/...
# sets DB_PASSWORD). Lets the app read managed secrets without baking them in.
set -eu

for var in $(env | sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)_FILE=.*/\1/p'); do
    eval "file=\${${var}_FILE}"
    if [ -f "${file}" ]; then
        eval "export ${var}=\"\$(cat \"\${file}\")\""
    fi
done

exec "$@"
