#!/usr/bin/env bash
set -euo pipefail

SLUG="donatepress"
MAIN_FILE="donatepress.php"
DIST_DIR="dist"
STAGE_DIR="${DIST_DIR}/${SLUG}"

# ── 1. Clean-tree gate ───────────────────────────────────────────────
echo "── Step 1: Clean-tree gate"
if [[ -n "$(git status --porcelain)" ]]; then
    echo "ERROR: Working tree is dirty. Commit or stash changes first."
    exit 10
fi
echo "   ✓ Working tree clean"

# ── 2. Version triangulation ─────────────────────────────────────────
echo "── Step 2: Version triangulation"

VER_PLUGIN=$(grep -Po "(?<=Version:\s)[\d.]+" "${MAIN_FILE}")
VER_CONST=$(grep -Po "(?<=DONATEPRESS_VERSION', ')[\d.]+" "${MAIN_FILE}")
VER_COMPOSER=$(grep -Po '"version":\s*"\K[^"]+' composer.json 2>/dev/null || echo "")

if [[ "${VER_PLUGIN}" != "${VER_CONST}" ]]; then
    echo "ERROR: Plugin header (${VER_PLUGIN}) ≠ constant (${VER_CONST})"
    exit 11
fi

if [[ -n "${VER_COMPOSER}" && "${VER_PLUGIN}" != "${VER_COMPOSER}" ]]; then
    echo "ERROR: Plugin header (${VER_PLUGIN}) ≠ composer.json (${VER_COMPOSER})"
    exit 11
fi

VERSION="${VER_PLUGIN}"
echo "   ✓ Version ${VERSION} consistent"

# ── 3. PHP syntax lint ───────────────────────────────────────────────
echo "── Step 3: PHP syntax lint"
LINT_ERRORS=0
while IFS= read -r f; do
    if ! php -l "$f" > /dev/null 2>&1; then
        echo "   FAIL: $f"
        LINT_ERRORS=$((LINT_ERRORS + 1))
    fi
done < <(find includes/ -name "*.php" -type f)
php -l "${MAIN_FILE}" > /dev/null 2>&1 || LINT_ERRORS=$((LINT_ERRORS + 1))
php -l uninstall.php > /dev/null 2>&1 || LINT_ERRORS=$((LINT_ERRORS + 1))

if [[ ${LINT_ERRORS} -gt 0 ]]; then
    echo "ERROR: ${LINT_ERRORS} file(s) failed syntax check"
    exit 30
fi
echo "   ✓ All PHP files pass syntax check"

# ── 4. Smoke tests ──────────────────────────────────────────────────
echo "── Step 4: Smoke tests"
SMOKE_PASS=0
SMOKE_FAIL=0
for test_file in tests/smoke-*.php; do
    if [[ -f "${test_file}" ]]; then
        if php "${test_file}" > /dev/null 2>&1; then
            SMOKE_PASS=$((SMOKE_PASS + 1))
        else
            echo "   FAIL: ${test_file}"
            SMOKE_FAIL=$((SMOKE_FAIL + 1))
        fi
    fi
done

if [[ ${SMOKE_FAIL} -gt 0 ]]; then
    echo "ERROR: ${SMOKE_FAIL} smoke test(s) failed"
    exit 30
fi
echo "   ✓ ${SMOKE_PASS} smoke test(s) passed"

# ── 5. Stage into dist/ ─────────────────────────────────────────────
echo "── Step 5: Staging into ${STAGE_DIR}"
rm -rf "${DIST_DIR}"
mkdir -p "${STAGE_DIR}"

rsync -a --delete \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.editorconfig' \
    --exclude='.phpcs.xml.dist' \
    --exclude='phpstan.neon.dist' \
    --exclude='phpstan-bootstrap.php' \
    --exclude='phpunit.xml.dist' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='bin' \
    --exclude='dist' \
    --exclude='docs' \
    --exclude='CLAUDE.md' \
    --exclude='.DS_Store' \
    --exclude='*.log' \
    --exclude='test-results' \
    --exclude='playwright-report' \
    ./ "${STAGE_DIR}/"

echo "   ✓ Files staged"

# ── 6. Required files check ─────────────────────────────────────────
echo "── Step 6: Required files check"
REQUIRED_FILES=(
    "${STAGE_DIR}/${MAIN_FILE}"
    "${STAGE_DIR}/uninstall.php"
    "${STAGE_DIR}/README.md"
    "${STAGE_DIR}/includes/Core/Activator.php"
    "${STAGE_DIR}/includes/Core/Plugin.php"
    "${STAGE_DIR}/includes/API/RestController.php"
    "${STAGE_DIR}/includes/Gateways/GatewayInterface.php"
    "${STAGE_DIR}/assets/frontend/form.js"
    "${STAGE_DIR}/assets/frontend/form.css"
    "${STAGE_DIR}/assets/admin/settings.js"
    "${STAGE_DIR}/assets/admin/settings.css"
)

MISSING=0
for req in "${REQUIRED_FILES[@]}"; do
    if [[ ! -f "${req}" ]]; then
        echo "   MISSING: ${req}"
        MISSING=$((MISSING + 1))
    fi
done

if [[ ${MISSING} -gt 0 ]]; then
    echo "ERROR: ${MISSING} required file(s) missing from staging"
    exit 40
fi
echo "   ✓ All required files present"

# ── 7. Staged PHP lint ──────────────────────────────────────────────
echo "── Step 7: Staged PHP lint"
STAGE_ERRORS=0
while IFS= read -r f; do
    if ! php -l "$f" > /dev/null 2>&1; then
        echo "   FAIL: $f"
        STAGE_ERRORS=$((STAGE_ERRORS + 1))
    fi
done < <(find "${STAGE_DIR}" -name "*.php" -type f)

if [[ ${STAGE_ERRORS} -gt 0 ]]; then
    echo "ERROR: ${STAGE_ERRORS} staged file(s) failed syntax check"
    exit 30
fi
echo "   ✓ Staged PHP files pass lint"

# ── 8. Create zip ───────────────────────────────────────────────────
echo "── Step 8: Create zip"
ZIP_NAME="${SLUG}-${VERSION}.zip"
(cd "${DIST_DIR}" && zip -rq "${ZIP_NAME}" "${SLUG}/")
ZIP_SIZE=$(du -h "${DIST_DIR}/${ZIP_NAME}" | cut -f1)
echo "   ✓ ${DIST_DIR}/${ZIP_NAME} (${ZIP_SIZE})"

# ── 9. Verify zip ──────────────────────────────────────────────────
echo "── Step 9: Verify zip"
VERIFY_DIR=$(mktemp -d)
unzip -qo "${DIST_DIR}/${ZIP_NAME}" -d "${VERIFY_DIR}"

if [[ ! -f "${VERIFY_DIR}/${SLUG}/${MAIN_FILE}" ]]; then
    echo "ERROR: Main plugin file missing from zip"
    rm -rf "${VERIFY_DIR}"
    exit 30
fi

VERIFY_VER=$(grep -Po "(?<=Version:\s)[\d.]+" "${VERIFY_DIR}/${SLUG}/${MAIN_FILE}")
if [[ "${VERIFY_VER}" != "${VERSION}" ]]; then
    echo "ERROR: Version in zip (${VERIFY_VER}) ≠ expected (${VERSION})"
    rm -rf "${VERIFY_DIR}"
    exit 11
fi

rm -rf "${VERIFY_DIR}"
echo "   ✓ Zip verified"

echo ""
echo "═══════════════════════════════════════════"
echo "  ${SLUG} ${VERSION} ready for release"
echo "  ${DIST_DIR}/${ZIP_NAME}"
echo "═══════════════════════════════════════════"
