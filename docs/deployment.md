# Wolf Deployment Guide

## Prerequisites

- A Hetzner Cloud account ([signup](https://hetzner.cloud))
- A domain name pointed to your server's IP
- Your SSH public key (`cat ~/.ssh/id_ed25519.pub` — if you don't have one, run `ssh-keygen -t ed25519`)

## 1. Create the Server

1. Log in to [Hetzner Cloud Console](https://console.hetzner.cloud)
2. Create a new project called "Wolf"
3. Add your SSH public key: **Security → SSH Keys → Add SSH Key** — paste the output of `cat ~/.ssh/id_ed25519.pub`
4. Create a server:
   - **Location:** Ashburn (or closest to you)
   - **Image:** Apps → Docker CE (Ubuntu 24.04)
   - **Type:** Shared vCPU → CX22 ($4.51/mo)
   - **SSH Key:** Select the key you just added
   - **Name:** `wolf`
5. Note the server's **IP address** after creation

## 2. Set Up DNS

Go to your domain registrar and create an A record:

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | wolf (or @) | YOUR_SERVER_IP | 300 |

Wait a few minutes for DNS to propagate. Verify:

```bash
dig wolf.yourdomain.com +short
# Should return your server IP
```

## 3. Secure the Server

SSH into the server:

```bash
ssh root@YOUR_SERVER_IP
```

Set up the firewall:

```bash
ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP (Caddy redirect)
ufw allow 443/tcp   # HTTPS
ufw allow 1883/tcp  # MQTT
ufw enable
ufw status
```

Disable password authentication:

```bash
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh
```

Enable automatic security updates:

```bash
apt update && apt install -y unattended-upgrades
dpkg-reconfigure -plow unattended-upgrades
# Select "Yes" when prompted
```

## 4. Clone and Configure

Still on the server:

```bash
cd /opt
git clone https://github.com/YOUR_USERNAME/wolf.git
cd wolf
```

Create the environment file:

```bash
cp .env.production.example .env
```

Now edit `.env` and fill in your real values:

```bash
nano .env
```

**Values you need to change** (all marked `CHANGE_ME`):

1. **APP_DOMAIN** — your domain (e.g. `wolf.yourdomain.com`)
2. **APP_URL** — `https://wolf.yourdomain.com`
3. **DB_PASSWORD** — generate one:
   ```bash
   openssl rand -base64 24
   ```
4. **REVERB_APP_KEY** — generate one:
   ```bash
   openssl rand -hex 16
   ```
5. **REVERB_APP_SECRET** — generate another:
   ```bash
   openssl rand -hex 16
   ```
6. **VITE_REVERB_HOST** — same as your domain (e.g. `wolf.yourdomain.com`)
7. **MAIL_FROM_ADDRESS** — your email or `noreply@yourdomain.com`

Save and exit (`Ctrl+X`, `Y`, `Enter` in nano).

## 5. Deploy

```bash
cd /opt/wolf

# Build and start all services
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# Wait for services to be healthy
docker compose ps

# Generate the app key
docker compose exec app php artisan key:generate

# Run database migrations
docker compose exec app php artisan migrate --force

# Create your admin user
docker compose exec app php artisan tinker
```

In tinker, create your admin user:

```php
\App\Models\User::create([
    'name' => 'Your Name',
    'email' => 'you@email.com',
    'password' => bcrypt('your-password'),
    'is_admin' => true,
]);
exit
```

## 6. Verify

**Web app:**
Open `https://wolf.yourdomain.com` in your browser. You should see the Wolf login page with a valid HTTPS certificate (lock icon).

**WebSockets:**
Log in and go to the dashboard. Open browser DevTools → Network → WS tab. You should see a WebSocket connection to `wss://wolf.yourdomain.com/app/...`.

**MQTT:**
From your local machine (with `mosquitto-clients` installed):

```bash
mosquitto_sub -h wolf.yourdomain.com -t "wolf/#" -v
```

You should be able to subscribe without errors.

## Updating

When you push new code to GitHub:

```bash
ssh root@YOUR_SERVER_IP
cd /opt/wolf
git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
docker compose exec app php artisan migrate --force  # if there are new migrations
```

## Troubleshooting

**Check logs:**

```bash
docker compose logs -f app           # Laravel app
docker compose logs -f caddy         # HTTPS / reverse proxy
docker compose logs -f reverb        # WebSockets
docker compose logs -f mqtt-listener # MQTT listener
docker compose logs -f mosquitto     # MQTT broker
docker compose logs -f nginx         # Internal web server
docker compose logs -f queue         # Queue worker
```

**Caddy not getting SSL certificate:**
- Make sure DNS is pointing to the server IP: `dig wolf.yourdomain.com +short`
- Make sure ports 80 and 443 are open: `ufw status`
- Check Caddy logs: `docker compose logs caddy`
- Caddy needs to respond on port 80 for the Let's Encrypt HTTP challenge

**ESP device can't connect to MQTT:**
- Make sure port 1883 is open: `ufw status`
- Test from your local machine: `mosquitto_pub -h wolf.yourdomain.com -t test -m hello`
- Check Mosquitto logs: `docker compose logs mosquitto`

**WebSockets not connecting:**
- Check that `VITE_REVERB_HOST` in `.env` matches your domain
- Check that `VITE_REVERB_SCHEME=https` and `VITE_REVERB_PORT=443`
- These values are baked into the frontend build — if you change them, you need to rebuild:
  ```bash
  docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
  ```
- Check Reverb logs: `docker compose logs reverb`

**Database issues:**
- Check MySQL is healthy: `docker compose ps`
- Connect to MySQL: `docker compose exec mysql mysql -u wolf -p wolf`
- Check Laravel can connect: `docker compose exec app php artisan tinker --execute "DB::connection()->getPdo(); echo 'ok';"`

**Container keeps restarting:**
- Check logs for the specific service: `docker compose logs <service-name>`
- Check if it's a memory issue: `docker stats` (CX22 has 4GB RAM)
