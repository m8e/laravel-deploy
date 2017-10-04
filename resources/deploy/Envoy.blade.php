@setup
    # Bootstrap composer + the dotenv configurations.
    require __DIR__.'/vendor/autoload.php';
    $dotenv = new Dotenv\Dotenv(__DIR__);

    try {
        $dotenv->load();
        $dotenv->required([
            'DEPLOY_DIR_BASE',
            'DEPLOY_REPOSITORY',
            'DEPLOY_USER',
            'DEPLOY_HOST'
        ])->notEmpty();
    } catch ( Exception $e )  {
        echo $e->getMessage();
        exit;
    }

    function logMessage($message) {
        return "echo '\033[32m" .$message. "\033[0m';\n";
    }

    # Retrieve the values from the config, inform user if there are pieces missing.
    # These 4 values don't have a default value and need to be supplied in the
    # .env config.
    $baseDir    = getenv('DEPLOY_DIR_BASE');
    $repository = getenv('DEPLOY_REPOSITORY');
    $deployUser = getenv('DEPLOY_USER');
    $deployHost = getenv('DEPLOY_HOST');

    if (!strlen($repository) || !strlen($deployUser) || !strlen($deployHost)) {
        echo "Your .env config is missing one of the following values:
DEPLOY_HOST=
DEPLOY_USER=
DEPLOY_DIR_BASE=
DEPLOY_REPOSITORY=
";
        exit;
    }

    $releasesDir    = getenv('DEPLOY_DIR_RELEASES');
    $persistentDir  = getenv('DEPLOY_DIR_PERSISTENT') ?? 'persistent';
    $hostPort       = getenv('DEPLOY_CURRENT') ?? 'current';
    $currentDir     = $baseDir ."/". $hostPort;
    $deploySshPort  = getenv('DEPLOY_SSH_PORT') ?? 22;
    $branch         = getenv('DEPLOY_BRANCH') ?? 'master';
    $newReleaseName = date('Ymd-His');
    $newReleaseDir  = "{$releasesDir}/{$newReleaseName}";
    $user           = get_current_user();
@endsetup

@servers(['local' => '127.0.0.1','remote' => '-A -p '. $deploySshPort .' -l '. $deployUser .' '. $deployHost])

@macro('deploy')
    startDeployment
    cloneRepository
    runComposer
    runYarn
    generateAssets
    updateSymlinks
    optimizeInstallation
    backupDatabase
    migrateDatabase
    blessNewRelease
    cleanOldReleases
    finishDeploy
@endmacro

@macro('deploy-code')
    deployOnlyCode
@endmacro

@task('startDeployment', ['on' => 'local'])
    {{ logMessage("🏃  Starting deployment...") }}
    git checkout {{ $branch }}
    git pull origin {{ $branch }}
@endtask

@task('cloneRepository', ['on' => 'remote'])
    {{ logMessage("🌀  Cloning repository...") }}
    [ -d {{ $releasesDir }} ] || mkdir {{ $releasesDir }};
    [ -d {{ $persistentDir }} ] || mkdir {{ $persistentDir }};
    [ -d {{ $persistentDir }}/media ] || mkdir {{ $persistentDir }}/media;
    [ -d {{ $persistentDir }}/storage ] || mkdir {{ $persistentDir }}/storage;
    cd {{ $releasesDir }};

    # Create the release dir
    mkdir {{ $newReleaseDir }};

    # Clone the repo
    git clone --depth 1 -b {{ $branch }} {{ $repository }} {{ $newReleaseName }}

    # Configure sparse checkout
    cd {{ $newReleaseDir }}
    git config core.sparsecheckout true
    echo "*" > .git/info/sparse-checkout
    echo "!storage" >> .git/info/sparse-checkout
    echo "!public/build" >> .git/info/sparse-checkout
    git read-tree -mu HEAD

    # Mark release
    cd {{ $newReleaseDir }}
    echo "{{ $newReleaseName }}" > public/release-name.txt
@endtask

@task('runComposer', ['on' => 'remote'])
    {{ logMessage("🚚  Running Composer...") }}
    cd {{ $newReleaseDir }};
    composer install --prefer-dist --no-scripts --no-dev -q -o;
@endtask

@task('runYarn', ['on' => 'remote'])
    {{ logMessage("📦  Running Yarn...") }}
    cd {{ $newReleaseDir }};
    yarn config set ignore-engines true
    yarn
@endtask

@task('generateAssets', ['on' => 'remote'])
    {{ logMessage("🌅  Generating assets...") }}
    cd {{ $newReleaseDir }};
    yarn run production -- --progress false
@endtask

@task('updateSymlinks', ['on' => 'remote'])
    {{ logMessage("🔗  Updating symlinks to persistent data...") }}
    # Remove the storage directory and replace with persistent data
    rm -rf {{ $newReleaseDir }}/storage;
    cd {{ $newReleaseDir }};
    ln -nfs {{ $baseDir }}/persistent/storage storage;

    # Remove the public/media directory and replace with persistent data
    rm -rf {{ $newReleaseDir }}/public/media;
    cd {{ $newReleaseDir }};
    ln -nfs {{ $baseDir }}/persistent/media public/media;

    # Import the environment config
    cd {{ $newReleaseDir }};
    ln -nfs {{ $baseDir }}/.env .env;
@endtask

@task('optimizeInstallation', ['on' => 'remote'])
    {{ logMessage("✨  Optimizing installation...") }}
    cd {{ $newReleaseDir }};
    php artisan clear-compiled;
@endtask

@task('backupDatabase', ['on' => 'remote'])
    {{ logMessage("📀  Backing up database...") }}
    cd {{ $newReleaseDir }}
    php artisan backup:run
@endtask

@task('migrateDatabase', ['on' => 'remote'])
    {{ logMessage("🙈  Migrating database...") }}
    cd {{ $newReleaseDir }};
    php artisan migrate --force;
@endtask

@task('blessNewRelease', ['on' => 'remote'])
    {{ logMessage("🙏  Blessing new release...") }}
    ln -nfs {{ $newReleaseDir }} {{ $currentDir }};
    cd {{ $newReleaseDir }}

    php artisan horizon:terminate
    php artisan config:clear
    php artisan cache:clear
    php artisan config:cache

    ~/scripts/reload_php-fpm.sh
    php artisan queue:restart
@endtask

@task('cleanOldReleases', ['on' => 'remote'])
    {{ logMessage("🚾  Cleaning up old releases...") }}
    # Delete all but the 3 most recent.
    cd {{ $releasesDir }}
    ls -dt {{ $releasesDir }}/* | tail -n +3 | xargs -d "\n" rm -rf;
@endtask

@task('finishDeploy', ['on' => 'local'])
    {{ logMessage("🚀  Application deployed!") }}
@endtask

@task('deployOnlyCode',['on' => 'remote'])
    {{ logMessage("💻  Deploying code changes...") }}
    cd {{ $currentDir }}
    git pull origin {{ $branch }}
    php artisan config:clear
    php artisan cache:clear
    php artisan config:cache
    ~/scripts/reload_php-fpm.sh
@endtask
