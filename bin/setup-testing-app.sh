#!/usr/bin/env bash
#
# Creates (or refreshes) testing-laravel-project — a real, gitignored Laravel
# app used to exercise the actual `composer require` + `synapse:install` flow.
#
# The Synapse package and the local Laravel AI SDK copy are linked via Composer
# path repositories (symlinked), so edits to the package reflect immediately.
#
# Usage:  ./bin/setup-testing-app.sh
#
set -euo pipefail

PACKAGE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="$PACKAGE_DIR/testing-laravel-project"

cd "$PACKAGE_DIR"

# Build the frontend assets so `synapse:install` has something to publish.
echo "==> Building Synapse assets"
npm install
npm run build

if [ ! -d "$APP_DIR" ]; then
    echo "==> Creating fresh Laravel app"
    composer create-project laravel/laravel "$APP_DIR" --no-interaction
fi

cd "$APP_DIR"

echo "==> Wiring path repositories"
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.synapse   '{"type":"path","url":"../","options":{"symlink":true}}'
composer config repositories.laravel-ai '{"type":"path","url":"../references/laravel/ai","options":{"symlink":true}}'

echo "==> Requiring redberry/synapse"
composer require redberry/synapse:@dev --no-interaction

echo "==> Installing Synapse"
php artisan synapse:install

echo "==> Seeding sample agents"
mkdir -p app/Agents app/Tools
for f in "$PACKAGE_DIR"/workbench/app/Agents/*.php; do sed 's/Workbench\\App/App/g' "$f" > "app/Agents/$(basename "$f")"; done
for f in "$PACKAGE_DIR"/workbench/app/Tools/*.php;  do sed 's/Workbench\\App/App/g' "$f" > "app/Tools/$(basename "$f")"; done

echo
echo "Done. Start it with:"
echo "  cd testing-laravel-project && php artisan serve"
echo "Then open http://127.0.0.1:8000/synapse"
