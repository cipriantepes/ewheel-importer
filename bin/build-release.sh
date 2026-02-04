#!/bin/bash

# Define plugin name
PLUGIN_SLUG="ewheel-importer"
MAIN_FILE="${PLUGIN_SLUG}.php"

# Extract version from main plugin file (Single Source of Truth)
VERSION=$(grep "Version:" "$MAIN_FILE" | awk '{print $NF}' | tr -d '\r')

if [ -z "$VERSION" ]; then
    echo "Error: Could not detect version from $MAIN_FILE"
    exit 1
fi

echo "Detected version: $VERSION"
ZIP_NAME="${PLUGIN_SLUG}.${VERSION}.zip"

# Ensure clean state
echo "Cleaning up..."
rm -f "$ZIP_NAME"
rm -rf "build"

# Create build directory
mkdir -p "build/$PLUGIN_SLUG"

# Export files to build directory (mocking git archive for local folder)
echo "Copying files..."
rsync -av --exclude='build' --exclude='.git' --exclude='.gitignore' --exclude='tests' --exclude='bin' --exclude='phpunit.xml' --exclude='composer.lock' --exclude='vendor' --exclude='*.zip' . "build/$PLUGIN_SLUG"

# Install production dependencies
echo "Installing production dependencies..."
cd "build/$PLUGIN_SLUG"
composer install --no-dev --optimize-autoloader

# Remove composer files from build
rm composer.json composer.lock

# Zip it up
cd ..
echo "Creating ZIP..."
zip -r "../$ZIP_NAME" "$PLUGIN_SLUG"

# Cleanup
cd ..
rm -rf "build"

echo "Done! Release package created: $ZIP_NAME"
