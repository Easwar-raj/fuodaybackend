const mix = require('laravel-mix');
const SvgSpritemapPlugin = require('svg-spritemap-webpack-plugin');

mix.webpackConfig({
    plugins: [
        new SvgSpritemapPlugin('resources/svg/**/*.svg', {
            output: {
                filename: 'images/sprite.svg', // Output file
                svgo: true, // Enable SVGO optimization
            },
            sprite: {
                prefix: false, // Remove symbol prefix
            },
        }),
    ],
});

mix.js('resources/js/fuoday/app.js', 'public/js/fuoday')
    .sass('resources/sass/6pages/homepage.scss', 'public/css/fuoday/')
   .setPublicPath('public');
