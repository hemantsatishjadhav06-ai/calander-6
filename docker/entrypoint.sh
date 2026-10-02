#!/bin/sh
set -eu

# Railway mounts new volumes and creates its console pipes as root. Prepare
# Laravel's writable paths and those console descriptors before running the
# upstream initialization and application processes as the unprivileged user.
# Normal non-root container starts pass through.
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

    # Supervisor reopens these descriptors for logging after privileges drop.
    # Keep their existing modes and leave redirected regular files untouched.
    for console_descriptor in /proc/self/fd/1 /proc/self/fd/2; do
        if [ -p "$console_descriptor" ] || [ -c "$console_descriptor" ]; then
            chown www-data:www-data "$console_descriptor"
        fi
    done

    exec setpriv --reuid=www-data --regid=www-data --init-groups --no-new-privs -- \
        docker-php-serversideup-entrypoint "$@"
fi

exec docker-php-serversideup-entrypoint "$@"
