// Renders the PWA icons (CHW-35) from public/icons/*.svg with the Playwright already installed:
// node scripts/render-app-icons.cjs. Run it again after changing an SVG, and commit the PNGs.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('@playwright/test');

const root = path.resolve(__dirname, '..');
const icons = [
    {
        svg: 'public/icons/app-icon.svg',
        png: 'public/icons/icon-192.png',
        size: 192,
    },
    {
        svg: 'public/icons/app-icon.svg',
        png: 'public/icons/icon-512.png',
        size: 512,
    },
    {
        svg: 'public/icons/app-icon-maskable.svg',
        png: 'public/icons/icon-maskable-512.png',
        size: 512,
    },
    // iOS rounds the corners itself: the full-bleed variant.
    {
        svg: 'public/icons/app-icon-maskable.svg',
        png: 'public/apple-touch-icon.png',
        size: 180,
    },
    {
        svg: 'public/icons/app-icon.svg',
        png: 'public/icons/favicon-32.png',
        size: 32,
        ico: 'public/favicon.ico',
    },
];

/** An .ico holding one PNG image (allowed since Windows Vista; every browser reads it). */
function icoOf(png, size) {
    const header = Buffer.alloc(6);
    header.writeUInt16LE(1, 2);
    header.writeUInt16LE(1, 4);
    const entry = Buffer.alloc(16);
    entry.writeUInt8(size % 256, 0);
    entry.writeUInt8(size % 256, 1);
    entry.writeUInt16LE(1, 4);
    entry.writeUInt16LE(32, 6);
    entry.writeUInt32LE(png.length, 8);
    entry.writeUInt32LE(6 + 16, 12);

    return Buffer.concat([header, entry, png]);
}

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage();

    for (const icon of icons) {
        const svg = fs.readFileSync(path.join(root, icon.svg), 'utf8');
        await page.setViewportSize({ width: icon.size, height: icon.size });
        await page.setContent(
            `<html><body style="margin:0;background:transparent">${svg.replace('<svg ', `<svg width="${icon.size}" height="${icon.size}" `)}</body></html>`,
        );
        await page.locator('svg').screenshot({
            path: path.join(root, icon.png),
            omitBackground: true,
        });
        console.log(`${icon.png} (${icon.size}px)`);

        if (icon.ico) {
            fs.writeFileSync(
                path.join(root, icon.ico),
                icoOf(fs.readFileSync(path.join(root, icon.png)), icon.size),
            );
            fs.unlinkSync(path.join(root, icon.png));
            console.log(`${icon.ico} (${icon.size}px)`);
        }
    }

    await browser.close();
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
