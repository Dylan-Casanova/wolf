# SSH into Wolf Server — Quick Reference

## Connect

```bash
ssh root@xxxxx
```

You'll be prompted for your SSH key passphrase. Enter it to log in.

## First Time Connection

If this is a new machine that hasn't connected before, you'll see a fingerprint prompt:

```
The authenticity of host 'xxxx' can't be established.
Are you sure you want to continue connecting (yes/no)?
```

Type `yes` and press Enter. This only happens once per machine.

## Server Details

| Field | Value |
|-------|-------|
| IP | xx.xxx.xx.xx.x |
| User | root |
| OS | Ubuntu 24.04 LTS |
| Auth | SSH key only (password login disabled) |
| SSH Key | `xxxx` |
| Domain | iopen.it.com |
| Provider | Hetzner Cloud (CX22, Nuremberg) |

## Project Location on Server

```bash
cd /opt/wolf
```

## Common Commands Once Connected

```bash
# Check running services
docker compose ps

# View logs (all services)
docker compose logs -f

# View logs (specific service)
docker compose logs -f app
docker compose logs -f caddy
docker compose logs -f reverb
docker compose logs -f mqtt-listener
docker compose logs -f mosquitto

# Deploy latest code
cd /opt/wolf
git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# Run migrations
docker compose exec app php artisan migrate --force

# Open Laravel tinker
docker compose exec app php artisan tinker

# Check firewall status
ufw status

# Check disk/memory usage
df -h
free -h
docker stats
```

## Disconnect

Press `Ctrl+D` or type `exit`.
