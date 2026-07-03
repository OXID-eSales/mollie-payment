#!/usr/bin/env node

/**
 * Esbuild build script for the Mollie module. Mirrors Stripe's `resources/build.js`.
 *
 * Usage:
 *   node resources/build.js                # production build
 *   node resources/build.js development    # development build
 *   node resources/build.js watch          # watch mode
 */

const esbuild = require('esbuild');
const fs = require('fs');
const path = require('path');
const { productionConfig, developmentConfig, PATHS } = require('./esbuild/config');

const mode = process.argv[2] || 'production';

function ensureOutputDir() {
    const dir = path.join(PATHS.assets, 'js');
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
    }
}

async function build(config, label) {
    const result = await esbuild.build(config);
    if (result.errors.length > 0) {
        console.error(`Build failed for ${label}`);
        process.exit(1);
    }
    console.log(`Built ${label} -> ${config.outfile}`);
}

async function main() {
    ensureOutputDir();

    if (mode === 'watch') {
        const ctx = await esbuild.context(developmentConfig);
        await ctx.watch();
        console.log('Watching resources/js for changes...');
        return;
    }

    const config = mode === 'development' || mode === 'dev' ? developmentConfig : productionConfig;
    await build(config, mode);
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
