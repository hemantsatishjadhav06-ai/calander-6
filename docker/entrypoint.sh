#!/bin/sh
set -eu

# Railway mounts new volumes as root. Prepare only Laravel's writable paths
# before running the upstream initialization and every application process as
# the image's unprivileged user. Normal non-root container starts pass through.
if [ "$(id -u)" = "0" ]; then
    app_directory="${APP_BASE_DIR:-/var/www/html}"

    case "$app_directory" in
        /|*[![:print:]]*|"")
            echo "[init] APP_BASE_DIR must identify the application directory" >&2
            exit 1
            ;;
        /*) ;;
        *)
            echo "[init] APP_BASE_DIR must be an absolute path" >&2
            exit 1
            ;;
    esac

    for writable_directory in "$app_directory/storage" "$app_directory/bootstrap/cache"; do
        if [ -L "$writable_directory" ]; then
            echo "[init] writable application directories must not be symlinks" >&2
            exit 1
        fi

        mkdir -p "$writable_directory"
        chown -R --no-dereference www-data:www-data "$writable_directory"
    done

    exec setpriv --reuid=www-data --regid=www-data --init-groups --no-new-privs -- \
        docker-php-serversideup-entrypoint "$@"
fi

exec docker-php-serversideup-entrypoint "$@"
