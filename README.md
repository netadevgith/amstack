# Acelle ESP Stack — Deployment Specification

## Architecture Overview

Two-server setup:
- **Platform Server** (1 IP) — Acelle ESP web app, taskrunner, gosender, MariaDB, Redis (in Docker)
- **Proxy/Mailer Server** (10+ IPs) — Multi-instance Postfix for SMTP delivery

---

## 1. Prerequisites

### Platform Server
- Debian 12 / Ubuntu 22.04 or 24.04
- 4 GB RAM minimum (8 GB recommended)
- 40 GB disk minimum (200 GB recommended for campaign data)
- 1 public IPv4
- Ports open: 80 (HTTP), 443 (HTTPS), 22 (SSH)

### Proxy Server
- Debian 11+ / Ubuntu 20.04+
- 2 GB RAM minimum
- 20 GB disk
- Multiple public IPv4 addresses (1 per Postfix instance, e.g., 10 IPs)
- Ports open: 22 (SSH), 25 (SMTP outbound), 2525 (SMTP inbound from platform)
- Reverse DNS (PTR) records configurable per IP

### External Services
- **Cloudflare account** with at least one domain hosted (e.g., `emstr6.nl`)
- Cloudflare API key (Global API Key from My Profile → API Tokens)
- Domain for tracking/rDNS records

---

## 2. Platform Server Setup

### 2.1 Install Docker
```bash
apt-get update
apt-get install -y ca-certificates curl gnupg git
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
```

### 2.2 Configure Docker storage (if separate data disk)
If the system disk is small and there's a larger data disk:
```bash
# Mount data disk to /data, then:
mkdir -p /data/docker
echo '{"data-root": "/data/docker"}' > /etc/docker/daemon.json
systemctl restart docker
```

### 2.3 Clone repository
```bash
mkdir -p /data/litva
cd /data/litva
git clone git@github.com:netadevgith/amstack.git .
```

### 2.4 Configure environment
Edit `docker-compose.yml`:
```yaml
environment:
  CLOUDFLARE_EMAIL: "your@email.com"
  CLOUDFLARE_APIKEY: "your-cloudflare-global-api-key"
  ESP_WORKER: "true"
```

### 2.5 Import database (optional, for migration)
If migrating from existing instance, place SQL dump at:
```
/data/litva/docker/02-mailsendas-data.sql
```
And update `docker-compose.yml`:
```yaml
mariadb:
  volumes:
    - ./docker/mariadb-init.sql:/docker-entrypoint-initdb.d/01-init.sql
    - ./docker/02-mailsendas-data.sql:/docker-entrypoint-initdb.d/02-data.sql
```

### 2.6 Build and start
```bash
cd /data/litva
docker compose build app
docker compose up -d
```

Build takes ~5–10 minutes (Ubuntu 18.04 base + PHP 7.2 + Go + Perl + Composer).

### 2.7 Verify
```bash
docker ps                                 # all 4 containers Up
docker logs esp_app | tail -30            # init.sh completed
curl -s -o /dev/null -w '%{http_code}' http://localhost/login   # → 200
```

### 2.8 Set admin password
```bash
docker exec esp_app php -r '
require "/home/app/public_html/bootstrap/autoload.php";
$app = require_once "/home/app/public_html/bootstrap/app.php";
$kernel = $app->make("Illuminate\Contracts\Console\Kernel");
$kernel->bootstrap();
$user = \Acelle\Model\User::where("email","admin@yourdomain.com")->first();
$user->password = bcrypt("YourStrongPassword");
$user->activated = 1;
$user->save();
echo "Done\n";
'
```

### 2.9 Required container fixes
After first run, apply these post-init fixes inside `esp_app`:

```bash
# 1. Symlinks for www-data HOME
docker exec esp_app bash -c '
ln -sfn /home/app/public_html /var/www/public_html
ln -sfn /home/app/gosender /var/www/gosender
'

# 2. Tools must be executable by "others" (www-data)
docker exec esp_app chmod -R o+rx /home/app/public_html/tools/

# 3. nginx real_ip.conf writable by www-data (for proxy registration)
docker exec esp_app chown www-data:www-data /etc/nginx/real_ip.conf

# 4. sudo for nginx reload
docker exec esp_app bash -c '
apt-get install -y sudo
echo "www-data ALL=(ALL) NOPASSWD: /usr/sbin/service" > /etc/sudoers.d/www-data
chmod 440 /etc/sudoers.d/www-data
'

# 5. Generate SSH keys (used by server creator tools)
docker exec esp_app bash -c '
ssh-keygen -t rsa -b 4096 -N "" -f /root/.ssh/id_rsa
cp /root/.ssh/id_rsa /var/www/.ssh/id_rsa
cp /root/.ssh/id_rsa.pub /var/www/.ssh/id_rsa.pub
chown -R www-data:www-data /var/www/.ssh
chmod 700 /var/www/.ssh
chmod 600 /var/www/.ssh/id_rsa
'

# 6. Register platform as appliance (enables "Add to" buttons in UI)
docker exec esp_app redis-cli -h 172.28.0.3 HSET appliances 'Platform' 'http://YOUR_PLATFORM_IP/api/v1/'
```

### 2.10 Critical rule: NEVER enable Postfix on platform
The Dockerfile and `init.sh` are configured to NOT install or run Postfix on the platform. If you re-add it:
- Platform IP gets blacklisted faster (concentrated sending)
- Conflicts with the proxy multi-instance design
- Defeats the IP rotation purpose

---

## 3. Proxy/Mailer Server Setup

### 3.1 Verify multiple IPs are configured
```bash
ip -4 addr show | grep inet
# Should show multiple public IPs (e.g., 10 IPs on eth0)
```

### 3.2 Set up via Platform UI (recommended)

1. **Add proxy public key to mailer server** (one-time):
   ```bash
   # On platform:
   docker exec esp_app cat /root/.ssh/id_rsa.pub
   # Copy output, then on mailer server:
   echo "ssh-rsa AAAA... root@..." >> /root/.ssh/authorized_keys
   ```

2. **Create Cloudflare zone** for the domain you'll use for rDNS records (e.g., `emstr6.nl`).

3. **In Acelle UI** at `/settings/servers`:
   - Enter: `root:password@MAILER_IP:22` (or `root:key@MAILER_IP:22` if SSH key set up)
   - Click **Check** → wait for "Server has been verified" (detects all IPs and OS)
   - **For multi-IP mailer:** Click **"I will go with multi"** (NOT "Initialize DNS")
   - Enter your Cloudflare-managed domains (space-separated): `emstr6.nl`
   - Click **"Start MultiPostfix setup..."** → wait ~2 min
   - Click **"Add to <Platform>"** to inject all 10 IPs into `/sending_servers`

### 3.3 What the script does automatically
- Installs Postfix + Perl modules
- Initializes `postmulti`, restricts default Postfix to 127.0.0.1
- Creates a Postfix instance per public IP, listening on port 2525
- Creates random subdomain DNS A records (e.g., `ab12.emstr6.nl → IP`) via Cloudflare API
- Sets each instance's `myhostname` to its random subdomain (proper rDNS-aligned EHLO)
- Adds platform IP to `mynetworks` (so platform can relay)

### 3.4 Configure reverse DNS at hosting provider
For each public IP, set the PTR record at your hosting provider's panel to match the random subdomain Cloudflare created:
```
188.132.141.131 → ct83.emstr6.nl
188.132.141.132 → tq66.emstr6.nl
...
```
Without correct rDNS, Gmail/Outlook will reject mail.

### 3.5 Configure SPF on sending domain
Add TXT record on the domain you send `From:` addresses from (e.g., `emstr6.nl`):
```
v=spf1 ip4:PLATFORM_IP ip4:MAILER_IP_1 ... ip4:MAILER_IP_10 ~all
```

### 3.6 Configure DKIM (optional but strongly recommended)
Generate DKIM keypair, add public key as TXT record (`default._domainkey.emstr6.nl`), configure OpenDKIM on mailer server, integrate with Postfix via `milter_default_action = accept`.

---

## 4. Frontend Proxy (optional but recommended)

For brand isolation, run a separate "frontend" Nginx that exposes the report/abuse page and proxies the Acelle UI:

### 4.1 In Acelle UI at `/settings/proxies`:
- Enter: `root:key@FRONTEND_IP:22`
- Click **"Proxy setup..."**

This installs Nginx on the frontend server with:
- `/` → 301 to `/report`
- `/report`, `/css`, `/images`, `/source`, `/campaigns` → reverse-proxy to platform
- Blocks `/login`, `/index.php`, `/main.php`

---

## 5. Domain & SSL Setup

### 5.1 Point domain to platform
Create A record at your DNS provider:
```
app.yourdomain.com → PLATFORM_IP
```

### 5.2 Update Acelle URL
```bash
docker exec esp_app sed -i 's|APP_URL=.*|APP_URL=https://app.yourdomain.com|' /home/app/public_html/.env
docker exec esp_app php /home/app/public_html/artisan config:clear
```

### 5.3 SSL via Let's Encrypt
Run a Caddy or Nginx reverse proxy in front of the container with automatic SSL, or terminate SSL at Cloudflare (Full mode with origin certificate).

---

## 6. Verification Checklist

```bash
# Platform health
docker ps                                              # 4 containers Up
docker exec esp_app pgrep -a taskrunner               # 1 process only
docker exec esp_app supervisorctl status              # all RUNNING
curl -s http://localhost/login -o /dev/null -w '%{http_code}'   # 200

# Mailer health (from platform)
for ip in MAILER_IPS; do
  python3 -c "import smtplib; s=smtplib.SMTP('$ip', 2525, timeout=5); print('$ip:', s.ehlo()[1].decode().split('\n')[0]); s.quit()"
done

# DB sending servers configured
docker exec esp_mariadb mysql -u root -proot_password_esp mailsendas_testdev \
  -e "SELECT id, host, smtp_port, status FROM sending_servers;"

# Test send via taskrunner
docker exec esp_app php -r '
require "/home/app/public_html/bootstrap/autoload.php";
$app = require_once "/home/app/public_html/bootstrap/app.php";
$kernel = $app->make("Illuminate\Contracts\Console\Kernel");
$kernel->bootstrap();
$tr = new \Acelle\Library\TaskRunner();
$tr->send_queue($tr::MESSAGE_TYPE_SMTP_SEND, 1, $tr::PRIORITY_HIGH, json_encode([
  "server_ip" => "MAILER_IP",
  "port" => 2525,
  "from_email" => "info@emstr6.nl",
  "to_email" => "yourtest@gmail.com",
  "subject" => "Deployment test",
  "body" => "<p>Working</p>"
]));
'
sleep 5
docker exec esp_app tail -5 /home/app/taskrunner/taskrunner.log
# Expect: "mail to: ... sent ok"
```

---

## 7. Common Pitfalls

| Symptom | Cause | Fix |
|---------|-------|-----|
| Login "credentials don't match" | Stale bcrypt hash from imported dump | Reset via `bcrypt()` in PHP, clear sessions |
| `Permission denied: debian_proxy` | Tools not executable for "others" | `chmod -R o+rx /home/app/public_html/tools/` |
| `fopen real_ip.conf: Permission denied` | www-data can't write nginx config | `chown www-data /etc/nginx/real_ip.conf` |
| Test email hangs 2 minutes | Duplicate taskrunner processes consuming pubsub | Fix `cron_check` to use `pgrep`, kill duplicates |
| Empty email body received | Plain-text campaign, gosender ignored `plain` field | Use `GetCampaignBody()` which falls back to `Plain` |
| Server creator: "I will go with multi" missing | Single-IP server detected | Use single Postfix flow instead |
| 502 Bad Gateway on proxy | `proxy_pass DEPLOYMENT:80` (HTTPS to port 80) | Remove `:80` from template |
| Gmail rejects mail (SPF fail) | Sending IP not in SPF | Add all mailer IPs to TXT record |

---

## 8. Backups

### Database
```bash
docker exec esp_mariadb mysqldump -u root -proot_password_esp mailsendas_testdev \
  | gzip > /data/backups/mailsendas_$(date +%F).sql.gz
```

### Redis (campaign state)
```bash
docker exec esp_redis redis-cli BGSAVE
docker cp esp_redis:/data/dump.rdb /data/backups/redis_$(date +%F).rdb
```

### Full project
```bash
tar czf /data/backups/litva_$(date +%F).tar.gz -C /data litva --exclude=litva/.git
```

Schedule daily via cron.

---

## 9. Repository

- **Source:** https://github.com/netadevgith/amstack
- **Branch:** `main`
