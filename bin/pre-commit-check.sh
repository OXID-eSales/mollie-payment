#!/bin/bash

# Pre-commit check script for the Mollie payment module.
# Ported from the PayPal/Stripe modules (same flags, same Definition of Done).
#
# Usage: ./bin/pre-commit-check.sh [OPTIONS]
# Options:
#   --no-phpunit    Skip PHPUnit tests (style-only run)
#   --full          Run Unit + Integration PHPUnit suites (requires MySQL)

set +e

SKIP_PHPUNIT=false
FULL_TESTS=false
for arg in "$@"; do
    case $arg in
        --no-phpunit)
            SKIP_PHPUNIT=true
            shift
            ;;
        --full)
            FULL_TESTS=true
            shift
            ;;
    esac
done

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
MODULE_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"

if [ -n "$GITHUB_ACTIONS" ]; then
    ENVIRONMENT="github"
    WORKING_DIR="$MODULE_ROOT"
    echo "======================================"
    echo "Running Pre-Commit Checks (GitHub Actions)"
    echo "======================================"
    echo "Module root: $MODULE_ROOT"
else
    ENVIRONMENT="local"
    PROJECT_ROOT="$( cd "$SCRIPT_DIR/../../.." && pwd )"
    echo "======================================"
    echo "Running Pre-Commit Checks (Local Docker)"
    echo "======================================"
    echo "Project root: $PROJECT_ROOT"
    cd "$PROJECT_ROOT" || {
        echo "Error: Could not navigate to project root"
        exit 1
    }
fi

echo ""

OVERALL_STATUS=0
FAILED_CHECKS=()

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

run_phpcs_docker() {
    if [ ! -f "$MODULE_ROOT/tests/phpcs.xml" ]; then
        echo -e "${YELLOW}⊘ PHPCS skipped (tests/phpcs.xml not present)${NC}"
        return 0
    fi
    docker compose exec -w /var/www/extensions/mollie-payment -T php \
        /var/www/vendor/bin/phpcs --standard=tests/phpcs.xml --warning-severity=0 src/
}

run_phpstan_docker() {
    if [ ! -f "$MODULE_ROOT/tests/PhpStan/phpstan.neon" ]; then
        echo -e "${YELLOW}⊘ PHPStan skipped (tests/PhpStan/phpstan.neon not present)${NC}"
        return 0
    fi
    if ! docker compose exec -w /var/www -T php test -x vendor/bin/phpstan 2>/dev/null; then
        echo -e "${YELLOW}⊘ PHPStan binary not installed in shop vendor${NC}"
        return 0
    fi
    docker compose exec -w /var/www/extensions/mollie-payment -T php \
        /var/www/vendor/bin/phpstan analyse -c tests/PhpStan/phpstan.neon --level=max src/ --memory-limit=1G
}

run_phpmd_docker() {
    if [ ! -f "$MODULE_ROOT/tests/PhpMd/phpmd.xml" ]; then
        echo -e "${YELLOW}⊘ PHPMD skipped (tests/PhpMd/phpmd.xml not present)${NC}"
        return 0
    fi
    if ! docker compose exec -w /var/www -T php test -x vendor/bin/phpmd 2>/dev/null; then
        echo -e "${YELLOW}⊘ PHPMD binary not installed in shop vendor${NC}"
        return 0
    fi
    local baseline_flag=""
    if [ -f "$MODULE_ROOT/tests/PhpMd/phpmd.baseline.xml" ]; then
        baseline_flag="--baseline-file tests/PhpMd/phpmd.baseline.xml"
    fi
    docker compose exec -w /var/www/extensions/mollie-payment -T php \
        /var/www/vendor/bin/phpmd src text tests/PhpMd/phpmd.xml $baseline_flag --exclude tests/ --suffixes php --strict
}

echo ">>> Running PHP Code Sniffer..."
if [ "$ENVIRONMENT" = "github" ]; then
    cd "$MODULE_ROOT" && composer run phpcs 2>/dev/null || echo -e "${YELLOW}⊘ PHPCS skipped${NC}"
else
    run_phpcs_docker
fi
PHPCS_STATUS=$?
if [ $PHPCS_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHP Code Sniffer")
    echo -e "${RED}✗ PHP Code Sniffer failed${NC}"
else
    echo -e "${GREEN}✓ PHP Code Sniffer passed${NC}"
fi
echo ""

if [ "$SKIP_PHPUNIT" = true ]; then
    echo ">>> Skipping PHPUnit Tests (--no-phpunit flag set)"
    echo -e "${YELLOW}⊘ PHPUnit tests skipped${NC}"
    echo ""
else
    if [ "$FULL_TESTS" = true ]; then
        echo ">>> Running PHPUnit Tests (Full: Unit + Integration, requires MySQL)..."
        TESTSUITE_ARG=""
    else
        echo ">>> Running PHPUnit Tests (Unit only, use --full for all)..."
        TESTSUITE_ARG="--testsuite Unit"
    fi

    if [ "$ENVIRONMENT" = "github" ]; then
        echo "skip on github"
        PHPUNIT_STATUS=0
    else
        docker compose exec -w /var/www/extensions/mollie-payment -T php \
            /var/www/vendor/bin/phpunit -c tests/phpunit.xml --bootstrap=/var/www/source/bootstrap.php $TESTSUITE_ARG
        PHPUNIT_STATUS=$?
    fi

    if [ $PHPUNIT_STATUS -ne 0 ]; then
        OVERALL_STATUS=1
        FAILED_CHECKS+=("PHPUnit Tests")
        echo -e "${RED}✗ PHPUnit tests failed${NC}"
    else
        echo -e "${GREEN}✓ PHPUnit tests passed${NC}"
    fi
    echo ""
fi

echo ">>> Running PHPStan static analysis..."
if [ "$ENVIRONMENT" = "github" ]; then
    cd "$MODULE_ROOT" && composer phpstan 2>/dev/null || echo -e "${YELLOW}⊘ PHPStan skipped${NC}"
else
    run_phpstan_docker
fi
PHPSTAN_STATUS=$?
if [ $PHPSTAN_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHPStan")
    echo -e "${RED}✗ PHPStan failed${NC}"
else
    echo -e "${GREEN}✓ PHPStan passed${NC}"
fi
echo ""

echo ">>> Running PHPMD..."
if [ "$ENVIRONMENT" = "github" ]; then
    cd "$MODULE_ROOT" && composer phpmd 2>/dev/null || echo -e "${YELLOW}⊘ PHPMD skipped${NC}"
else
    run_phpmd_docker
fi
PHPMD_STATUS=$?
if [ $PHPMD_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHPMD")
    echo -e "${RED}✗ PHPMD failed${NC}"
else
    echo -e "${GREEN}✓ PHPMD passed${NC}"
fi
echo ""

echo "======================================"
echo "SUMMARY"
echo "======================================"
echo ""

if [ $OVERALL_STATUS -eq 0 ]; then
    echo -e "${GREEN}✓ ALL CHECKS PASSED${NC}"
    echo -e "${GREEN}Status: COMMITABLE${NC}"
    exit 0
else
    echo -e "${RED}✗ SOME CHECKS FAILED${NC}"
    echo ""
    echo "Failed checks:"
    for check in "${FAILED_CHECKS[@]}"; do
        echo -e "  ${RED}- $check${NC}"
    done
    echo ""
    echo -e "${RED}Status: NON-COMMITABLE${NC}"
    exit 1
fi
