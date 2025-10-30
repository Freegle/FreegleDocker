# PhpStorm Git Configuration for FreegleDocker

This document explains how to configure PhpStorm to work properly with FreegleDocker's git hooks and submodules on Windows.

## Problem 1: Git Commit Hook Failing on Windows

### Symptoms
- Git hooks fail when committing or pushing from PhpStorm on Windows
- Error messages about bash scripts or line endings
- Hook scripts don't execute properly

### Solution

1. **Run the setup script** from Git Bash (not Windows Command Prompt):
   ```bash
   cd /path/to/FreegleDockerWSL
   bash setup-hooks.sh
   ```

   Or on Windows Command Prompt:
   ```cmd
   cd C:\path\to\FreegleDockerWSL
   setup-hooks.cmd
   ```

2. **Configure PhpStorm to use Git Bash**:
   - Go to `File` → `Settings` (or `Ctrl+Alt+S`)
   - Navigate to `Version Control` → `Git`
   - Set `Path to Git executable` to: `C:\Program Files\Git\bin\git.exe`
   - **Important**: Use the `bin` version, not `cmd` version
   - Click `Test` to verify it works

3. **Verify line endings are correct**:
   - In PhpStorm, go to `File` → `Settings` → `Editor` → `Code Style`
   - Under `Line Separator`, ensure it's set to `Unix and macOS (\n)` for `.git/hooks/*` files
   - You can configure this per-directory pattern

### Testing the Hook

Test the pre-push hook from Git Bash:
```bash
cd /path/to/FreegleDockerWSL
git push --dry-run
```

If you see the message "🔍 Checking submodule commits are pushed to remote...", the hook is working!

## Problem 2: PhpStorm Pushing Parent Before Submodules

### Background

FreegleDocker uses submodules (iznik-nuxt3, iznik-server, etc.). The pre-push hook enforces that submodule commits must be pushed **before** the parent repository is pushed. This prevents CircleCI build failures.

### Solution Options

#### Option A: Use Git Command Line for Pushing (Recommended)

The simplest solution is to push from Git Bash using this command:

```bash
git submodule foreach 'git push' && git push
```

This ensures submodules are pushed first, then the parent repo.

#### Option B: Configure PhpStorm Custom Push Action

1. **Create a push script** in the repository root (`push-with-submodules.sh`):
   ```bash
   #!/bin/bash
   echo "Pushing submodules first..."
   git submodule foreach 'git push'
   if [ $? -eq 0 ]; then
       echo "Pushing parent repository..."
       git push
   else
       echo "Failed to push submodules"
       exit 1
   fi
   ```

2. **Make it executable**:
   ```bash
   chmod +x push-with-submodules.sh
   ```

3. **Configure PhpStorm External Tool**:
   - Go to `File` → `Settings` → `Tools` → `External Tools`
   - Click `+` to add a new tool
   - Name: `Push with Submodules`
   - Program: `bash` (or full path: `C:\Program Files\Git\bin\bash.exe`)
   - Arguments: `push-with-submodules.sh`
   - Working directory: `$ProjectFileDir$`
   - Click `OK`

4. **Use the External Tool**:
   - Right-click on project root in PhpStorm
   - Select `External Tools` → `Push with Submodules`

#### Option C: Configure Git Alias

Create a git alias that PhpStorm can use:

```bash
git config --global alias.push-all '!git submodule foreach "git push" && git push'
```

Then in PhpStorm, you can push using:
- Open the Terminal in PhpStorm (`Alt+F12`)
- Run: `git push-all`

#### Option D: Manual Workflow

Before pushing in PhpStorm:

1. Open Terminal in PhpStorm (`Alt+F12`)
2. Push submodules first:
   ```bash
   git submodule foreach 'git push'
   ```
3. Then use PhpStorm's normal push (`Ctrl+Shift+K`)

### Submodule Push Order

The typical workflow is:

1. **Make changes** in a submodule (e.g., `iznik-nuxt3`)
2. **Commit** the changes in the submodule
3. **Push** the submodule changes first
4. **Commit** the updated submodule reference in FreegleDocker
5. **Push** FreegleDocker (the pre-push hook will verify submodules are pushed)

## Additional PhpStorm Configuration

### Enable Git Bash Terminal in PhpStorm

To use Git Bash as the default terminal in PhpStorm:

1. Go to `File` → `Settings` → `Tools` → `Terminal`
2. Set `Shell path` to: `C:\Program Files\Git\bin\bash.exe`
3. Click `OK`
4. Restart PhpStorm for changes to take effect

Now when you open the terminal (`Alt+F12`), it will be Git Bash, making it easier to run git commands.

### Configure PhpStorm Git Settings

Recommended Git settings for PhpStorm on Windows:

1. Go to `File` → `Settings` → `Version Control` → `Git`
2. Enable: ☑ `Auto-update if push of the current branch was rejected`
3. Enable: ☑ `Update method: Merge`
4. Disable: ☐ `Use credential helper` (if you have issues with authentication)

### Submodule Configuration

1. Go to `File` → `Settings` → `Version Control` → `Git`
2. Enable: ☑ `Update submodules when switching branches`
3. This ensures submodules are automatically updated when you switch branches

## Troubleshooting

### Hook Still Fails

If the hook still fails after setup:

1. **Check Git Bash is accessible**:
   ```cmd
   "C:\Program Files\Git\bin\bash.exe" --version
   ```

2. **Verify hook has Unix line endings**:
   ```bash
   file .git/hooks/pre-push
   ```
   Should show: `POSIX shell script, ASCII text executable, with LF line terminators`

3. **Test the hook directly**:
   ```bash
   bash .git/hooks/pre-push
   ```

4. **Check PhpStorm is using the correct Git**:
   - In PhpStorm: `VCS` → `Git` → `Show Git Log`
   - Check the console output for errors

### Submodule Push Fails

If pushing submodules fails:

1. **Check submodule has a remote**:
   ```bash
   cd iznik-nuxt3
   git remote -v
   ```

2. **Ensure you're on the correct branch**:
   ```bash
   cd iznik-nuxt3
   git status
   ```

3. **Push manually**:
   ```bash
   cd iznik-nuxt3
   git push origin master
   ```

### PhpStorm Can't Find Git

If PhpStorm can't find Git:

1. **Reinstall Git for Windows** from: https://git-scm.com/download/win
2. During installation, ensure you select: "Use Git from Git Bash only" or "Use Git from the Windows Command Prompt"
3. After installation, configure PhpStorm's Git path as described above

## Summary

**For Windows users**:
1. Run `setup-hooks.cmd` or `bash setup-hooks.sh`
2. Configure PhpStorm to use Git Bash (`C:\Program Files\Git\bin\git.exe`)
3. Use `git submodule foreach 'git push' && git push` to push with submodules
4. Consider setting up an External Tool or git alias for convenience

**Key Points**:
- Always push submodules before pushing the parent repository
- Use Git Bash for git operations on Windows
- The pre-push hook will prevent you from pushing if submodules aren't pushed yet
- This is intentional to prevent CircleCI build failures
