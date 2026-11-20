import Encore from '@symfony/webpack-encore';

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore.setOutputPath('public/build/')
    .setPublicPath('/build')
    // one script entry and one style entry
    .addEntry('scripts', './assets/js/app.ts')
    .addStyleEntry('styles', './assets/css/app.scss')
    .splitEntryChunks()
    .enableSingleRuntimeChunk()
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())
    // hashed filenames (e.g. app.abc123.css) in production
    .enableVersioning(Encore.isProduction())
    .enableSassLoader()
    .enableTypeScriptLoader();

export default await Encore.getWebpackConfig();
