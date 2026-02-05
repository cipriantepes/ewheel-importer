#!/bin/bash
#
# Build script for ewheel-importer plugin
# Creates a production-ready zip file without dev dependencies
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory and plugin root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_NAME="ewheel-importer"

# Get version from main plugin file
VERSION=$(grep -o "Version:.*" "$PLUGIN_DIR/ewheel-importer.php" | head -1 | sed 's/Version:[[:space:]]*//' | tr -d '[:space:]')

if [ -z "$VERSION" ]; then
    echo -e "${RED}Error: Could not determine plugin version${NC}"
    exit 1
fi

BUILD_DIR="$PLUGIN_DIR/build"
DIST_DIR="$PLUGIN_DIR/dist"
ZIP_NAME="${PLUGIN_NAME}-v${VERSION}.zip"

echo -e "${GREEN}Building ${PLUGIN_NAME} v${VERSION}${NC}"
echo "================================================"

# Clean previous builds
echo -e "${YELLOW}Cleaning previous builds...${NC}"
rm -rf "$BUILD_DIR"
rm -rf "$DIST_DIR"
mkdir -p "$BUILD_DIR/$PLUGIN_NAME"
mkdir -p "$DIST_DIR"

# Copy plugin files (excluding dev/build files)
echo -e "${YELLOW}Copying plugin files...${NC}"
rsync -av --progress "$PLUGIN_DIR/" "$BUILD_DIR/$PLUGIN_NAME/" \
    --exclude 'build' \
    --exclude 'dist' \
    --exclude 'node_modules' \
    --exclude 'tests' \
    --exclude 'bin' \
    --exclude '.git' \
    --exclude '.gitignore' \
    --exclude '.phpunit.result.cache' \
    --exclude 'phpunit.xml' \
    --exclude 'phpunit.xml.dist' \
    --exclude 'composer.lock' \
    --exclude '.env*' \
    --exclude '*.log' \
    --exclude 'coverage' \
    --exclude '.idea' \
    --exclude '.vscode' \
    --exclude '.DS_Store' \
    --exclude 'Thumbs.db' \
    --exclude '*.zip' \
    --exclude '*.tar.gz'

# Install production dependencies only
echo -e "${YELLOW}Installing production dependencies...${NC}"
cd "$BUILD_DIR/$PLUGIN_NAME"
composer install --no-dev --optimize-autoloader --no-interaction

# Remove composer files not needed in production
rm -f composer.json composer.lock

# Create zip file
echo -e "${YELLOW}Creating zip archive...${NC}"
cd "$BUILD_DIR"
zip -r "$DIST_DIR/$ZIP_NAME" "$PLUGIN_NAME" -x "*.git*"

# Cleanup build directory
rm -rf "$BUILD_DIR"

# Output results
echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}Build complete!${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo "Output: $DIST_DIR/$ZIP_NAME"
echo "Size: $(du -h "$DIST_DIR/$ZIP_NAME" | cut -f1)"
echo ""
echo -e "${YELLOW}To deploy:${NC}"
echo "1. Upload $ZIP_NAME to your WordPress site"
echo "2. Deactivate and delete the old version"
echo "3. Install and activate the new version"
echo ""
