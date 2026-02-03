#!/bin/bash

# Define plugin name and version
PLUGIN_SLUG="ewheel-importer"
VERSION="1.0.0"
ZIP_NAME="${PLUGIN_SLUG}.${VERSION}.zip"

# Ensure clean state
echo "Cleaning up..."
rm -f "$ZIP_NAME"
rm -rf "build"

# Create build directory
mkdir -p "build/$PLUGIN_SLUG"

# Export files to build directory (mocking git archive for local folder)
echo "Copying files..."
rsync -av --exclude='build' --exclude='.git' --exclude='.gitignore' --exclude='tests' --exclude='bin' --exclude='phpunit.xml' --exclude='composer.lock' --exclude='vendor' . "build/$PLUGIN_SLUG"

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
