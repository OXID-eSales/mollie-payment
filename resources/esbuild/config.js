/**
 * Esbuild configuration for the Mollie module frontend bundle.
 * Mirrors Stripe's `resources/esbuild/config.js` structure.
 */

const path = require('path');

const PATHS = {
    src: path.resolve(__dirname, '../js'),
    assets: path.resolve(__dirname, '../../assets'),
};

/**
 * Phase-5-style console-stripping (see resources/js/debug.js): these console methods are marked
 * side-effect-free so esbuild's minifier removes any stray literal `console.log(...)` a developer
 * left in controller source. `console.error` is deliberately absent — failure paths always log.
 * Intentional `debug(...)` calls route through an aliased `consoleRef.log(...)` reference, which
 * this static list cannot match, so they survive the strip and stay gated by the runtime flag.
 */
const PRODUCTION_PURE_CONSOLE = ['console.log', 'console.info', 'console.debug', 'console.warn', 'console.trace'];

const productionConfig = {
    entryPoints: [path.join(PATHS.src, 'app.js')],
    bundle: true,
    minify: true,
    sourcemap: true,
    outfile: path.join(PATHS.assets, 'js/mollie-frontend.min.js'),
    format: 'iife',
    platform: 'browser',
    target: ['es2017'],
    define: { 'process.env.NODE_ENV': '"production"' },
    pure: PRODUCTION_PURE_CONSOLE,
};

const developmentConfig = {
    entryPoints: [path.join(PATHS.src, 'app.js')],
    bundle: true,
    minify: false,
    sourcemap: 'inline',
    outfile: path.join(PATHS.assets, 'js/mollie-frontend.js'),
    format: 'iife',
    platform: 'browser',
    target: ['es2017'],
    define: { 'process.env.NODE_ENV': '"development"' },
};

module.exports = { PATHS, productionConfig, developmentConfig };
