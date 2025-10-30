@echo off
REM Windows batch script for setting up Git hooks
echo Installing Git hooks...

REM Store current directory
set PROJECT_ROOT=%CD%

REM Check if we're in a git repository
git rev-parse --show-toplevel >nul 2>&1
if errorlevel 1 (
    echo ERROR: Not in a git repository
    exit /b 1
)

REM Check if hooks exist
if not exist .git\hooks\pre-push (
    echo WARNING: pre-push hook not found - this is unexpected!
    echo The hook should already exist in .git\hooks\
    exit /b 1
)

echo Found pre-push hook

REM Configure Git to ignore file mode changes on Windows
git config core.fileMode false
echo Configured Git core.fileMode to false

REM Ensure Unix line endings (LF) for the hooks
REM This is important for bash scripts to work properly
echo Ensuring Unix line endings for hooks...
git add --renormalize .git\hooks\pre-push 2>nul
git add --renormalize .git\hooks\post-checkout 2>nul

echo.
echo Git hooks are ready!
echo.
echo The pre-push hook will ensure submodule commits are pushed before the parent repo.
echo If you use PhpStorm, see the PhpStorm configuration instructions in the documentation.
