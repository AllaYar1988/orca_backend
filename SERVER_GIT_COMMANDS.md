# Git Commands for Server Deployment

## Quick Reference (Copy-Paste Ready)

### Daily Use - Pull Latest Code (Sunday or Any Day)
```bash
cd /home/sgkk4203/public_html/orca_backend && git pull origin main
```

### Verify Everything is Uploaded
```bash
# Check all files are present
ls -la api/
ls -la models/
ls -la database/

# Verify specific new files exist
cat api/auth_middleware.php | head -5
cat api/user_logout.php | head -5
cat models/User.php | head -5

# Check git status
git status
git log --oneline -3
```

---

## Initial Setup (First Time Only)

```bash
# Navigate to your project folder
cd /home/sgkk4203/public_html/orca_backend

# Initialize git
git init

# Add remote repository
git remote add origin https://github.com/AllaYar1988/orca_backend.git

# Fetch all branches
git fetch origin

# Checkout main branch
git checkout -f main

# If files are in nested folder (orca_backend/api/ instead of api/)
cp -r orca_backend/* .
```

---

## Regular Updates

### Simple Pull (When No Local Changes)
```bash
cd /home/sgkk4203/public_html/orca_backend
git pull origin main
```

### Pull With Local Changes
```bash
cd /home/sgkk4203/public_html/orca_backend
git stash
git pull origin main
git stash pop
```

### Force Update (Overwrite All Local Files)
```bash
cd /home/sgkk4203/public_html/orca_backend
git fetch origin
git reset --hard origin/main
```

---

## Check Status Commands

### See Current State
```bash
# Current branch and changes
git status

# Recent commits
git log --oneline -5

# All tracked files
git ls-files

# Current branch
git branch

# Remote URLs
git remote -v
```

### Verify Files Are Correct
```bash
# List API files
ls -la api/

# List models
ls -la models/

# Check file content (first 10 lines)
head -10 api/auth_middleware.php

# Check if file exists
cat api/auth_middleware.php | head -1

# Compare with GitHub (shows what would change)
git diff origin/main
```

---

## Fix Common Issues

### Files Not Showing After Pull
```bash
# If files are in nested orca_backend/ folder
cp -r orca_backend/* .
```

### "Not a Git Repository" Error
```bash
git init
git remote add origin https://github.com/AllaYar1988/orca_backend.git
git fetch origin
git checkout -f main
```

### Remote Already Exists
```bash
git remote set-url origin https://github.com/AllaYar1988/orca_backend.git
```

### Merge Conflicts
```bash
# Option 1: Keep remote version (discard local)
git fetch origin
git reset --hard origin/main

# Option 2: Stash local changes
git stash
git pull origin main
git stash pop  # This might cause conflicts to resolve manually
```

### Permission Denied
```bash
# Check file permissions
ls -la api/

# Fix permissions if needed (644 for files, 755 for folders)
chmod 644 api/*.php
chmod 755 api/
```

---

## One-Liner Commands (Copy-Paste Friendly)

### Quick Sync
```bash
cd /home/sgkk4203/public_html/orca_backend && git pull origin main
```

### Force Sync (Overwrites Everything)
```bash
cd /home/sgkk4203/public_html/orca_backend && git fetch origin && git reset --hard origin/main
```

### Check Everything
```bash
cd /home/sgkk4203/public_html/orca_backend && git status && ls -la api/ && ls -la models/
```

### Full Verification
```bash
cd /home/sgkk4203/public_html/orca_backend && echo "=== Git Status ===" && git status && echo "=== API Files ===" && ls -la api/ && echo "=== Models ===" && ls -la models/ && echo "=== Recent Commits ===" && git log --oneline -3
```

---

## After Deployment Checklist

1. **Pull latest code**
   ```bash
   git pull origin main
   ```

2. **Verify API files**
   ```bash
   ls -la api/
   ```
   Should see: `auth_middleware.php`, `user_logout.php`, etc.

3. **Verify models**
   ```bash
   ls -la models/
   ```
   Should see: `User.php`

4. **Check git is in sync**
   ```bash
   git status
   ```
   Should say: "Your branch is up to date with 'origin/main'"

5. **Test an endpoint**
   ```bash
   curl -I https://your-domain.com/orca_backend/api/user_login.php
   ```
   Should return HTTP 200

---

## Useful Tips

- Always `cd` to the project folder first
- Use `git status` before and after pulls
- If something breaks, use `git reset --hard origin/main` to restore
- Keep your `.env` file backed up (it's not in git)
