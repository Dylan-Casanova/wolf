# WOLF-XXX · Add SSH deploy key on server before making repo private

| Field | Value |
|---|---|
| **Type** | Task / Ops |
| **Priority** | High — blocks repo visibility flip |
| **Status** | To Do |
| **Component** | Deployment / Infrastructure |
| **Estimate** | 15 min |
| **Reporter** | Dylan |

## Summary

Replace the server's anonymous HTTPS `git pull` with an SSH deploy-key pull so `deploy.sh` keeps working after the GitHub repo is flipped from public to private.

## Background

The deploy flow runs `git pull` from `/opt/wolf` on the server (`deploy.sh:7`). The repo was originally cloned over HTTPS per `docs/deployment.md:78`:

```
git clone https://github.com/YOUR_USERNAME/wolf.git
```

Anonymous HTTPS pulls work while the repo is public. Once the repo is private, the next `git pull` fails with `403 Forbidden` and the rest of `deploy.sh` (docker rebuild, vite build, cache clear) never runs.

## Acceptance criteria

- [ ] A dedicated read-only deploy key (`~/.ssh/wolf_deploy`) exists on the production server
- [ ] The matching public key is registered in **GitHub → Repo Settings → Deploy keys** with write access **disabled**
- [ ] `~/.ssh/config` on the server routes `github.com` to that key (`IdentitiesOnly yes`)
- [ ] `/opt/wolf` remote is switched from `https://github.com/.../wolf.git` to `git@github.com:.../wolf.git`
- [ ] `git pull` from `/opt/wolf` succeeds **after** the repo is flipped to private
- [ ] `bash deploy.sh` runs end-to-end successfully on a clean tree once private
- [ ] `docs/deployment.md` is updated so the clone step (line ~78) and the "Updating" section (line ~170) document the SSH-key path, not anonymous HTTPS

## Runbook

On the production server:

```bash
# 1. Generate the deploy key (no passphrase so deploy.sh stays non-interactive)
ssh-keygen -t ed25519 -f ~/.ssh/wolf_deploy -N ""

# 2. Print the public key and copy it
cat ~/.ssh/wolf_deploy.pub
```

In GitHub:
- Repo → **Settings → Deploy keys → Add deploy key**
- Paste the public key
- Leave **"Allow write access"** unchecked
- Title it `wolf-prod-server`

Back on the server:

```bash
# 3. Route github.com through the deploy key
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/wolf_deploy
  IdentitiesOnly yes
EOF

# 4. Switch the remote from HTTPS to SSH
cd /opt/wolf
git remote set-url origin git@github.com:YOUR_USERNAME/wolf.git

# 5. Verify
git pull   # should succeed (still public at this point)
```

Then flip the repo to private in GitHub. Run `git pull` once more on the server to confirm it still works under the new visibility.

## Side-effects to be aware of (informational, not blocking)

- **GitHub Actions minutes:** `.github/workflows/ci.yml` runs on every push to `main` and on PRs. Public repos get unlimited Actions minutes; private repos consume from the personal plan quota (2,000 min/mo on Free). Not expected to be a bottleneck at current commit volume, but worth knowing.
- **Public links:** Any externally shared link to the repo/README/issues will 404 for non-collaborators after the flip.

## Notes

- The deploy key is **read-only** by design — `git pull` only needs read. Avoiding write access limits blast radius if the server is ever compromised.
- A single key per server is cleaner than reusing a personal SSH key — easy to revoke without affecting Dylan's own access.
