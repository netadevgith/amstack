#!/bin/bash
set -e

echo "============================================"
echo "  ESP Mailing Platform - Init Script"
echo "============================================"

# ---- Wait for MariaDB to be ready ----
echo "[1/9] Waiting for MariaDB..."
for i in $(seq 1 60); do
    if mysql -h "${DB_HOST}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" -e "SELECT 1" > /dev/null 2>&1; then
        echo "  MariaDB is ready!"
        break
    fi
    echo "  Waiting for MariaDB... ($i/60)"
    sleep 2
done

# ---- Wait for Redis to be ready ----
echo "[2/9] Waiting for Redis..."
for i in $(seq 1 30); do
    if redis-cli -h "${REDIS_HOST}" -p "${REDIS_PORT}" ping 2>/dev/null | grep -q PONG; then
        echo "  Redis is ready!"
        break
    fi
    echo "  Waiting for Redis... ($i/30)"
    sleep 2
done

# ---- Start Redis proxy early (needed by Laravel/composer and taskrunner) ----
echo "  Starting Redis proxy (socat localhost:6379 -> ${REDIS_HOST}:${REDIS_PORT})..."
socat TCP-LISTEN:6379,fork,reuseaddr TCP:${REDIS_HOST}:${REDIS_PORT} &
sleep 1

# ---- Import database if not already imported ----
echo "[3/9] Checking database..."
TABLE_COUNT=$(mysql -h "${DB_HOST}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}';" 2>/dev/null || echo "0")
if [ "$TABLE_COUNT" -lt "5" ]; then
    echo "  Importing database from dump..."
    if [ -f /home/app/mailsendas_testdev.sql.gz ]; then
        gunzip -c /home/app/mailsendas_testdev.sql.gz | mysql -h "${DB_HOST}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}"
        echo "  Database imported successfully!"
    else
        echo "  WARNING: Database dump not found at /home/app/mailsendas_testdev.sql.gz"
    fi
else
    echo "  Database already has $TABLE_COUNT tables, skipping import."
fi

# ---- Update .env for local Docker environment ----
echo "[4/9] Configuring Laravel .env..."
cd /home/app/public_html

# Update database settings
sed -i "s|DB_HOST=.*|DB_HOST=${DB_HOST}|" .env
sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE}|" .env
sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USERNAME}|" .env
sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
sed -i "s|DB_PORT=.*|DB_PORT=${DB_PORT}|" .env

# Update Redis settings
sed -i "s|REDIS_HOST=.*|REDIS_HOST=${REDIS_HOST}|" .env
sed -i "s|REDIS_PORT=.*|REDIS_PORT=${REDIS_PORT}|" .env
sed -i "s|REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" .env

# Update app URL (detect external IP or use APP_URL env var)
if [ -n "${APP_URL}" ]; then
    sed -i "s|APP_URL=.*|APP_URL=${APP_URL}|" .env
else
    EXTERNAL_IP=$(curl -s --connect-timeout 5 http://ifconfig.me 2>/dev/null || echo "localhost")
    sed -i "s|APP_URL=.*|APP_URL=http://${EXTERNAL_IP}|" .env
fi

# Update Cloudflare settings (if provided via Docker environment)
if [ -n "${CLOUDFLARE_ENABLED}" ]; then
    sed -i "s|APP_CLOUDFLARE_ENABLED=.*|APP_CLOUDFLARE_ENABLED=${CLOUDFLARE_ENABLED}|" .env
fi
if [ -n "${CLOUDFLARE_EMAIL}" ]; then
    sed -i "s|APP_CLOUDFLARE_EMAIL=.*|APP_CLOUDFLARE_EMAIL=${CLOUDFLARE_EMAIL}|" .env
fi
if [ -n "${CLOUDFLARE_APIKEY}" ]; then
    sed -i "s|APP_CLOUDFLARE_APIKEY=.*|APP_CLOUDFLARE_APIKEY=${CLOUDFLARE_APIKEY}|" .env
fi

# Update storage URL to local (storage listens on port 8082 inside container)
sed -i "s|APP_STORURL=.*|APP_STORURL=http://172.28.0.10:8082/|" .env

echo "  .env configured for local Docker environment."

# ---- Set directory permissions ----
echo "[5/9] Setting permissions..."
chown -R www-data:www-data /home/app/public_html/storage /home/app/public_html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /home/app/public_html/storage /home/app/public_html/bootstrap/cache 2>/dev/null || true
mkdir -p /home/app/public_html/storage/logs
mkdir -p /home/app/public_html/storage/framework/{sessions,views,cache}
mkdir -p /home/app/public_html/public/source
# Mark app as installed (prevents redirect to /install/ wizard)
touch /home/app/public_html/storage/app/installed
chown -R www-data:www-data /home/app/public_html/storage
chown -R www-data:www-data /home/app/public_html/public/source

# Fix www-data SSH access (needed by debian_proxy for proxy setup via UI)
mkdir -p /var/www/.ssh
# Copy root SSH keys to www-data so PHP-FPM (running as www-data) can SSH to proxy servers
if [ -f /root/.ssh/id_rsa ]; then
    cp /root/.ssh/id_rsa /var/www/.ssh/id_rsa
    cp /root/.ssh/id_rsa.pub /var/www/.ssh/id_rsa.pub 2>/dev/null || true
    cp /root/.ssh/known_hosts /var/www/.ssh/known_hosts 2>/dev/null || true
fi
chown -R www-data:www-data /var/www /var/www/.ssh
chmod 700 /var/www/.ssh
chmod 600 /var/www/.ssh/id_rsa 2>/dev/null || true

# Fix nginx real_ip.conf - pre-populate with Docker gateway IPs so nginx trusts
# X-Real-IP / X-Forwarded-For headers from the host reverse proxy.
# SettingController may append additional proxy IPs at runtime.
cat > /etc/nginx/real_ip.conf << 'REALIP'
set_real_ip_from 172.28.0.0/16;
set_real_ip_from 172.29.0.0/16;
set_real_ip_from 127.0.0.1;
set_real_ip_from 10.0.0.0/8;
REALIP
chmod 666 /etc/nginx/real_ip.conf

# Symlink tools dir for www-data ($HOME=/var/www for php-fpm)
mkdir -p /var/www/public_html
ln -sf /home/app/public_html/tools /var/www/public_html/tools

# Download GeoLite2 database for IP geolocation (if not present)
if [ ! -f /usr/share/GeoIP/GeoLite2-City.mmdb ]; then
    echo "  Downloading GeoLite2-City.mmdb..."
    mkdir -p /usr/share/GeoIP
    wget -q https://raw.githubusercontent.com/P3TERX/GeoLite.mmdb/download/GeoLite2-City.mmdb -O /usr/share/GeoIP/GeoLite2-City.mmdb 2>/dev/null || echo "  Warning: Could not download GeoLite2-City.mmdb"
fi

echo "  Permissions set."

# ---- Restore incomplete vendor packages ----
echo "[6/10] Restoring vendor packages..."
cd /home/app/public_html

# Disable Composer platform check (PHP 7.2 vs required 7.3 in platform_check.php)
if [ -f vendor/composer/platform_check.php ]; then
    echo '<?php // platform check disabled for PHP 7.2 compatibility' > vendor/composer/platform_check.php
fi

# lsxiao/user-agent-for-laravel5 GitHub repo is deleted — remove from lock, install rest, restore
if [ ! -f vendor/doctrine/inflector/lib/Doctrine/Inflector/Inflector.php ]; then
    echo "  Vendor packages incomplete, running composer install..."
    cp composer.json composer.json.bak
    cp composer.lock composer.lock.bak

    # Remove lsxiao from json and lock (GitHub repo deleted, but files already in vendor)
    python3 -c "
import json, codecs
with open('composer.json','r') as f: d=json.load(f)
d.get('require',{}).pop('lsxiao/user-agent-for-laravel5',None)
with open('composer.json','w') as f: json.dump(d,f,indent=4)
with codecs.open('composer.lock','r','utf-8') as f: l=json.load(f)
l['packages']=[p for p in l['packages'] if p['name']!='lsxiao/user-agent-for-laravel5']
l['packages-dev']=[p for p in l.get('packages-dev',[]) if p['name']!='lsxiao/user-agent-for-laravel5']
l['content-hash']=''
with codecs.open('composer.lock','w','utf-8') as f: json.dump(l,f,indent=4,ensure_ascii=False)
"
    rm -f vendor/composer/installed.json
    composer install --ignore-platform-reqs --no-scripts --prefer-dist --no-interaction 2>/dev/null

    # Restore original composer files and regenerate autoload (includes lsxiao)
    cp composer.json.bak composer.json
    cp composer.lock.bak composer.lock
    rm -f composer.json.bak composer.lock.bak
    composer dump-autoload --ignore-platform-reqs --no-interaction 2>/dev/null
    echo "  Vendor packages restored."
else
    echo "  Vendor packages OK."
fi

# Always regenerate autoload to prevent 'Class not found' errors in queue workers
composer dump-autoload --ignore-platform-reqs --no-interaction 2>/dev/null
echo "  Autoload regenerated."

# Re-disable platform_check.php AFTER composer dump-autoload (it regenerates the file)
if [ -f vendor/composer/platform_check.php ]; then
    echo '<?php // platform check disabled for PHP 7.2 compatibility' > vendor/composer/platform_check.php
fi

# ---- Laravel cache config ----
echo "[7/10] Caching Laravel config..."
cd /home/app/public_html

# Remove old cached config so artisan regenerates it with Docker IPs from .env
rm -f bootstrap/cache/config.php

# Generate APP_KEY only if not already set (avoid regenerating on every restart,
# which invalidates existing sessions and CSRF tokens)
CURRENT_KEY=$(grep '^APP_KEY=' .env | cut -d= -f2-)
if [ -z "$CURRENT_KEY" ] || [ "$CURRENT_KEY" = "" ]; then
    echo "  Generating new APP_KEY..."
    php artisan key:generate --force 2>/dev/null || true
else
    echo "  APP_KEY already set, keeping existing key."
fi

# Cache config AFTER key:generate so the cached config has the correct APP_KEY
php artisan config:cache || echo "  Warning: config:cache had issues"
echo "  Laravel config cached."

# ---- Configure gosender.json for local ----
echo "[7/9] Configuring gosender.json..."
cd /home/app
if [ -f gosender.json ]; then
    # Update gosender.json with local DB settings using python (available on Ubuntu)
    python3 -c "
import json
with open('gosender.json', 'r') as f:
    cfg = json.load(f)
cfg['MySQLHost'] = '${DB_HOST}'
cfg['MySQLPort'] = '${DB_PORT}'
cfg['MySQLUser'] = '${DB_USERNAME}'
cfg['MySQLPassword'] = '${DB_PASSWORD}'
cfg['MySQLDb'] = '${DB_DATABASE}'
cfg['StorageUrl'] = 'http://172.28.0.10:8082/'
with open('gosender.json', 'w') as f:
    json.dump(cfg, f, indent=4)
" 2>/dev/null || echo "  Warning: Could not update gosender.json (python3 not available)"
    echo "  gosender.json configured."
fi

# ---- Build Go binaries ----
echo "[8/9] Building Go binaries..."

# Build mailsend
if [ -d /home/app/mailsend ] && [ -f /home/app/mailsend/configure ]; then
    echo "  Building mailsend..."
    cd /home/app/mailsend
    ./configure 2>/dev/null && make 2>/dev/null && make install 2>/dev/null || echo "  Warning: mailsend build had issues"
fi

# Build taskrunner (with licensing disabled for local Docker env)
echo "  Building taskrunner..."
cd /home/app/taskrunner
if [ -f Makefile ]; then
    # go.mod is already provided, just tidy and build
    go mod tidy 2>/dev/null || true
    go build -ldflags "-w -s -X main.LICENSING=no -X main.Build=$(date +%F-%T)" -o taskrunner || echo "  Warning: taskrunner build had issues"
fi

# Build gosender
echo "  Building gosender..."
cd /home/app
go mod tidy 2>/dev/null || true
go build -ldflags "-w -s -X main.LICENSING=no" -o gosender ./gosender-src/ || echo "  Warning: gosender build had issues"
# Wrapper scripts for gosender — it needs CWD=/home/app for names.txt and .env.
# PHP exec() calls ./gosender from /home/app/public_html (start),
# $HOME/gosender from the queue worker (RestartBackroundProcesses, HOME=/root),
# and $HOME/gosender from php-fpm / test-email buttons (HOME=/var/www).
# Without the /var/www wrapper, the www-data path resolves to the raw binary
# with CWD=/home/app/public_html and HOME=/var/www, so gosender panics on
# "open /var/www/names.txt: no such file" and no test email is ever sent.
mkdir -p /var/www
for WRAPPER_PATH in /root/gosender /home/app/public_html/gosender /var/www/gosender; do
    rm -f "$WRAPPER_PATH"
    cat > "$WRAPPER_PATH" << 'GWRAPPER'
#!/bin/bash
cd /home/app
exec /home/app/gosender "$@"
GWRAPPER
    chmod +x "$WRAPPER_PATH"
done

# Build storage
echo "  Building storage..."
cd /opt/storage
if [ -f Makefile ]; then
    # go.mod is at /opt/storage/, just tidy and build
    go mod tidy 2>/dev/null || true
    make build || echo "  Warning: storage build had issues"
fi

# Configure storage.json for local
if [ -f /opt/storage/storage.json ]; then
    python3 -c "
import json
with open('/opt/storage/storage.json', 'r') as f:
    cfg = json.load(f)
cfg['SqlHost'] = '${DB_HOST}'
cfg['SqlUser'] = 'stor_user'
cfg['SqlPass'] = 'stor_password_local'
cfg['SqlDba'] = 'storage'
with open('/opt/storage/storage.json', 'w') as f:
    json.dump(cfg, f, indent=4)
" 2>/dev/null || echo "  Warning: Could not update storage.json"
fi

# ---- Setup cron jobs ----
echo "[9/9] Setting up cron jobs..."
if [ "${ESP_WORKER}" != "false" ]; then
    cat > /etc/cron.d/esp-crons << 'CRONEOF'
# Taskrunner check every minute
* * * * * root /home/app/taskrunner/cron_check >> /home/app/taskrunner/taskrunner.log 2>&1

# NOTE: queue:work and schedule:run are managed by supervisord (supervisord.conf)
# Do NOT add them here — they are long-running daemons, not cron tasks.
# Adding queue:work to cron spawns a new daemon every minute, causing OOM kill.
CRONEOF
    chmod 644 /etc/cron.d/esp-crons
else
    # Non-worker instance: disable taskrunner cron
    echo "# Taskrunner disabled - runs only on primary worker instance" > /etc/cron.d/esp-crons
    chmod 644 /etc/cron.d/esp-crons
fi
service cron start 2>/dev/null || true
echo "  Cron jobs configured."

# ---- Configure and start Postfix ----
echo "  Configuring Postfix..."
# Derive a real FQDN for HELO from APP_URL so it matches the host's PTR/rDNS.
# A localhost HELO is rejected by Gmail/Outlook and tanks deliverability.
MAIL_HOSTNAME=$(echo "${APP_URL:-http://localhost}" | sed -E 's|https?://||; s|/.*||; s|:.*||')
[ -z "$MAIL_HOSTNAME" ] && MAIL_HOSTNAME=localhost
# Parent domain = strip the leftmost label only when there are 3+ labels (sub.domain.tld)
if [ "$(echo "$MAIL_HOSTNAME" | tr -cd '.' | wc -c)" -ge 2 ]; then
    MAIL_DOMAIN=$(echo "$MAIL_HOSTNAME" | sed -E 's|^[^.]+\.||')
else
    MAIL_DOMAIN="$MAIL_HOSTNAME"
fi
postconf -e "myhostname = $MAIL_HOSTNAME"
postconf -e "mydomain = $MAIL_DOMAIN"
postconf -e "myorigin = localhost"
postconf -e "inet_interfaces = all"
postconf -e "inet_protocols = ipv4"
postconf -e "mydestination = localhost"
postconf -e "mynetworks = 127.0.0.0/8 172.28.0.0/16"
postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination"
postconf -e "smtp_helo_name = $MAIL_HOSTNAME"
# Opportunistic TLS both directions (snakeoil cert is fine; gosender uses InsecureSkipVerify).
# Without this, all mail is plaintext, which hurts deliverability and reputation.
postconf -e "smtp_tls_security_level = may"
postconf -e "smtpd_tls_security_level = may"
postconf -e "smtp_tls_loglevel = 1"
postconf -e "smtp_tls_note_starttls_offer = yes"
# Listen on port 2525 (gosender default) in addition to 25
if ! grep -q "^2525" /etc/postfix/master.cf 2>/dev/null; then
    echo "2525      inet  n       -       y       -       -       smtpd" >> /etc/postfix/master.cf
fi
# Start rsyslog for mail.log
service rsyslog start 2>/dev/null || true
service postfix start 2>/dev/null || postfix start 2>/dev/null || true
echo "  Postfix started (ports 25, 2525)."

# ---- OpenDKIM signing + emstr6.nl bounce-sink ----
# /etc/opendkim and /etc/postfix are NOT on a Docker volume, so a full container
# recreate would otherwise lose the DKIM keys (emstr6.nl, parkagency.org) and the
# bounce-sink. We keep a backup in the app_storage volume and restore it on boot.
# To refresh the backup after changing keys/config, on the live container run:
#   tar czf storage/mailconfig/opendkim.tar.gz -C / etc/opendkim etc/opendkim.conf
echo "  Configuring OpenDKIM signing..."
MAILCFG=/home/app/public_html/storage/mailconfig
if [ -f "$MAILCFG/opendkim.tar.gz" ]; then
    tar xzf "$MAILCFG/opendkim.tar.gz" -C / 2>/dev/null
    chown -R opendkim:opendkim /etc/opendkim/keys 2>/dev/null
    find /etc/opendkim/keys -name '*.private' -exec chmod 600 {} \; 2>/dev/null
    echo "    DKIM keys/tables restored from storage volume."
fi
# Trust localhost + the Docker subnet so gosender's outbound mail is SIGNED (not just verified).
for H in 127.0.0.1 ::1 localhost 172.28.0.0/16; do
    grep -qxF "$H" /etc/opendkim/TrustedHosts 2>/dev/null || echo "$H" >> /etc/opendkim/TrustedHosts
done
postconf -e "milter_default_action = accept"
postconf -e "smtpd_milters = inet:localhost:8891"
postconf -e "non_smtpd_milters = inet:localhost:8891"
pkill -9 opendkim 2>/dev/null; sleep 1
rm -f /var/run/opendkim/opendkim.pid 2>/dev/null
service opendkim start 2>/dev/null || true

# emstr6.nl is a sending-only domain with no real mailbox; accept + discard its
# bounces so undeliverable MAILER-DAEMON DSNs don't pile up timing out for days.
if [ -f "$MAILCFG/transport" ]; then
    cp -a "$MAILCFG/transport" /etc/postfix/transport
else
    grep -q "^emstr6.nl" /etc/postfix/transport 2>/dev/null || echo "emstr6.nl  discard:bounce-sink" >> /etc/postfix/transport
fi
postmap /etc/postfix/transport 2>/dev/null
postconf -e "transport_maps = hash:/etc/postfix/transport"
postconf -e "relay_domains = emstr6.nl"
postfix reload 2>/dev/null || true
echo "  OpenDKIM + bounce-sink configured."

# Redis proxy already started earlier (after Redis readiness check)

# ---- Start background services (worker instances only) ----
if [ "${ESP_WORKER}" != "false" ]; then
    if [ -f /opt/storage/storage ]; then
        echo "  Starting storage service..."
        /opt/storage/storage --daemonize &
    fi

    if [ -f /home/app/taskrunner/taskrunner ]; then
        echo "  Starting taskrunner..."
        cd /home/app/taskrunner
        ./taskrunner >> /home/app/taskrunner/taskrunner.log 2>&1 &
    fi
else
    echo "  Skipping background services (ESP_WORKER=false)"
fi

# ---- Restart any campaigns that were sending before container restart ----
echo "  Checking for campaigns that need restarting..."
cd /home/app/public_html
SENDING_UIDS=$(mysql -h "${DB_HOST}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" -N -e "SELECT uid FROM campaigns WHERE status='sending';" 2>/dev/null | tr '\n' ',' | sed 's/,$//')
if [ -n "$SENDING_UIDS" ]; then
    echo "  Found sending campaigns: $SENDING_UIDS"
    echo "  Restoring Redis campaign data and restarting gosender processes..."
    HOME=/home/app php7.2 -r "
        require '/home/app/public_html/vendor/autoload.php';
        \$app = require '/home/app/public_html/bootstrap/app.php';
        \$kernel = \$app->make('Illuminate\Contracts\Console\Kernel');
        \$kernel->bootstrap();
        \$campaigns = \Acelle\Model\Campaign::where('status', 'sending')->get();
        foreach (\$campaigns as \$c) {
            if (!\Illuminate\Support\Facades\Redis::exists(\$c->uid)) {
                echo '  Restoring Redis key for campaign ' . \$c->uid . PHP_EOL;
                \Illuminate\Support\Facades\Redis::set(\$c->uid, json_encode(\$c));
            }
        }
        \$uids = '$SENDING_UIDS';
        echo '  Calling RestartBackroundProcesses for: ' . \$uids . PHP_EOL;
        \Acelle\Model\Campaign::RestartBackroundProcesses(\$uids);
        echo '  Campaign restart done.' . PHP_EOL;
    " 2>&1 || echo "  Warning: Campaign restart had issues (this is OK on first run)"
else
    echo "  No campaigns need restarting."
fi

echo ""
echo "============================================"
echo "  ESP Platform is starting!"
echo "============================================"
echo ""
echo "  Web UI:      http://localhost:8090"
echo "  Storage API: http://localhost:8091"
echo "  MariaDB:     localhost:3307"
echo "  Redis:       localhost:6380"
echo ""
echo "============================================"

# Create required dirs for PHP-FPM
mkdir -p /var/run/php
mkdir -p /var/log/supervisor

# Fix PHP-FPM: disable clear_env so workers inherit environment variables
# (needed for CLOUDFLARE_EMAIL, CLOUDFLARE_APIKEY, HOME used by Perl tools)
PHP_FPM_CONF="/etc/php/7.2/fpm/pool.d/www.conf"
if [ -f "$PHP_FPM_CONF" ]; then
    if grep -q "^clear_env" "$PHP_FPM_CONF"; then
        sed -i 's/^clear_env.*/clear_env = no/' "$PHP_FPM_CONF"
    elif grep -q "^;clear_env" "$PHP_FPM_CONF"; then
        sed -i 's/^;clear_env.*/clear_env = no/' "$PHP_FPM_CONF"
    else
        echo "clear_env = no" >> "$PHP_FPM_CONF"
    fi
fi

# Increase nginx fastcgi_read_timeout for long-running scripts (multipostfix setup etc.)
NGINX_CONF="/etc/nginx/sites-enabled/default"
if [ -f "$NGINX_CONF" ]; then
    sed -i 's/fastcgi_read_timeout [0-9]*/fastcgi_read_timeout 600/' "$NGINX_CONF"
fi

# Final permission fix (catches files created by init PHP commands)
chown -R www-data:www-data /home/app/public_html/storage 2>/dev/null || true

# Auto-register self as appliance for "Add to ..." UI button in /settings/servers.
# Path is /api/ (NOT /api/v1/) — matches Route::group prefix in app/Http/routes.php.
# Trailing slash required (controller concatenates: $deployment_url . "postserver").
# Display name = ESP_APPLIANCE_NAME or hostname of APP_URL.
APP_URL_FOR_API="${APP_URL:-http://localhost}"
APPLIANCE_NAME="${ESP_APPLIANCE_NAME:-$(echo "$APP_URL_FOR_API" | sed -E 's|https?://||; s|/.*||')}"
if [ -n "$APPLIANCE_NAME" ]; then
    APPLIANCE_API_URL="${APP_URL_FOR_API%/}/api/"
    redis-cli -h "${REDIS_HOST:-172.28.0.3}" -p "${REDIS_PORT:-6379}" \
        HSET appliances "$APPLIANCE_NAME" "$APPLIANCE_API_URL" >/dev/null 2>&1 \
        && echo "  Registered appliance '$APPLIANCE_NAME' -> $APPLIANCE_API_URL"
fi

# Start supervisor (nginx + php-fpm + queue workers)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
