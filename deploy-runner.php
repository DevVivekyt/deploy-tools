<?php
/**
 * One-time deploy runner. Uploaded by the shared GitHub workflow (cpanel-deploy.yml) together with the app zip,
 * called once over HTTPS with a one-time token, then it deletes itself and the zip.
 *
 *   1. full database backup  -> <app>/storage/backups/predeploy-<date>.sql.gz   (deploy STOPS if this fails)
 *   2. new files copied over the old ones. Nothing on the server is deleted. .env is never overwritten.
 *   3. the app's database commands, e.g. php database/migrate.php, php artisan migrate --force
 *
 * The last line of the output is "DEPLOY OK" when everything worked.
 */
declare(strict_types=1);

// Filled in by action.yml for every deploy:
const CFG = /*__CFG__*/[
    'token_hash' => '', 'zip' => '', 'app_dir' => '', 'php_bin' => '',
    'db_commands' => [], 'env_file' => '.env', 'backup_dir' => 'storage/backups',
];
define('APP_DIR', rtrim((string) CFG['app_dir'], '/'));
define('PHP_BIN', (string) CFG['php_bin']);
const TOKEN_HASH   = CFG['token_hash'];
const ZIP_NAME     = CFG['zip'];
const KEEP_BACKUPS = 10;
const MAX_AGE      = 1800;                 // runner older than 30 min is refused and removed

$zipPath = __DIR__ . '/' . ZIP_NAME;
$age = time() - (int) @filemtime(__FILE__);
if ($age > MAX_AGE) { @unlink($zipPath); @unlink(__FILE__); http_response_code(404); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !hash_equals(TOKEN_HASH, hash('sha256', (string) ($_POST['token'] ?? '')))) { http_response_code(404); exit; }

// from here on: authorised. Always clean up, whatever happens.
register_shutdown_function(function () use ($zipPath) {
    @unlink($zipPath);
    @unlink(__FILE__);
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\nERROR: PHP stopped: {$e['message']} ({$e['file']}:{$e['line']})\n";
    }
});
ignore_user_abort(true);
@set_time_limit(0);
@ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) @ob_end_flush();

function say(string $m): void { echo $m, "\n"; @flush(); }
function stop(string $m): never { say("ERROR: $m"); exit; }

say('server PHP ' . PHP_VERSION . ', app folder ' . APP_DIR);
if (!is_dir(APP_DIR)) stop('app folder ' . APP_DIR . ' does not exist on the server');
if (!is_file($zipPath)) stop('uploaded package not found next to the runner');

/* ------------------------------------------------------------------ */
/* 1. Database backup                                                  */
/* ------------------------------------------------------------------ */

function read_env(string $file): array
{
    $env = [];
    if (!is_file($file)) return $env;
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim((string) preg_replace('/^export\s+/', '', $k));
        $v = trim($v);
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
            $end = strpos($v, $v[0], 1);
            $v = $end === false ? substr($v, 1) : substr($v, 1, $end - 1);
        } else {
            $v = trim((string) preg_replace('/\s+#.*$/', '', $v));
        }
        $env[$k] = $v;
    }
    return $env;
}

function backup_database(): void
{
    $env = read_env(APP_DIR . '/' . CFG['env_file']);
    $name = $env['DB_NAME'] ?? ($env['DB_DATABASE'] ?? '');
    $user = $env['DB_USER'] ?? ($env['DB_USERNAME'] ?? '');
    if ($name === '' || $user === '') {
        if (CFG['db_commands']) stop('backup: no DB name / user found in ' . CFG['env_file'] . ' on the server (nothing was changed)');
        say('1. backup: app has no database settings, skipped'); return;
    }
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = (int) ($env['DB_PORT'] ?? 3306);
    $sock = $env['DB_SOCKET'] ?? '';
    $dsn = $sock !== '' ? "mysql:unix_socket=$sock;dbname=$name;charset=utf8mb4" : "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $user, $env['DB_PASSWORD'] ?? ($env['DB_PASS'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        stop('backup: cannot connect to the database: ' . $e->getMessage() . ' (nothing was changed)');
    }

    $dir = APP_DIR . '/' . trim((string) CFG['backup_dir'], '/');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) stop("backup: cannot create $dir (nothing was changed)");
    if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
    if (!is_file("$dir/index.html")) @file_put_contents("$dir/index.html", '');
    $gz = function_exists('gzopen');
    $file = "$dir/predeploy-" . date('Y-m-d-His') . ($gz ? '.sql.gz' : '.sql');
    $fh = $gz ? gzopen($file, 'wb6') : fopen($file, 'wb');
    if (!$fh) stop("backup: cannot write $file (nothing was changed)");
    $w = $gz ? fn(string $s) => gzwrite($fh, $s) : fn(string $s) => fwrite($fh, $s);

    try {
        $w("-- Full backup of database `$name` made before a deploy, " . date('c') . "\n"
         . "-- Restore: cPanel -> phpMyAdmin -> database -> Import this file.\n"
         . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $views = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $t) {
            if (strtoupper($t[1]) === 'VIEW') $views[] = $t[0]; else $tables[] = $t[0];
        }
        $rows = 0;
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            $w("DROP TABLE IF EXISTS `$t`;\n$create;\n");
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);   // big tables: row by row
            $q = $pdo->query("SELECT * FROM `$t`");
            $batch = [];
            $cols = null;
            while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $cols ??= '(`' . implode('`, `', array_keys($r)) . '`)';
                $vals = [];
                foreach ($r as $v) {
                    if ($v === null) $vals[] = 'NULL';
                    elseif (!mb_check_encoding((string) $v, 'UTF-8')) $vals[] = '0x' . bin2hex((string) $v);
                    else $vals[] = $pdo->quote((string) $v);
                }
                $batch[] = '(' . implode(', ', $vals) . ')';
                $rows++;
                if (count($batch) >= 200) { $w("INSERT INTO `$t` $cols VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; }
            }
            $q->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            if ($batch) $w("INSERT INTO `$t` $cols VALUES\n" . implode(",\n", $batch) . ";\n");
            $w("\n");
        }
        foreach ($views as $v) {
            $create = $pdo->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_NUM)[1];
            $create = (string) preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $create);
            $w("DROP VIEW IF EXISTS `$v`;\n$create;\n");
        }
        $w("SET FOREIGN_KEY_CHECKS = 1;\n-- end of backup\n");
    } catch (Throwable $e) {
        $gz ? gzclose($fh) : fclose($fh);
        @unlink($file);
        stop('backup failed: ' . $e->getMessage() . ' (nothing was changed)');
    }
    $gz ? gzclose($fh) : fclose($fh);
    say(sprintf('1. backup: %d tables, %d rows -> ' . CFG['backup_dir'] . '/%s (%s KB)', count($tables), $rows, basename($file), number_format(filesize($file) / 1024)));

    $old = glob("$dir/predeploy-*.sql*") ?: [];
    rsort($old);
    foreach (array_slice($old, KEEP_BACKUPS) as $f) @unlink($f);
}

/* ------------------------------------------------------------------ */
/* 2. Files                                                            */
/* ------------------------------------------------------------------ */

function rrmdir(string $d): void
{
    if (!is_dir($d)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($d);
}

function deploy_files(string $zipPath): void
{
    $tmp = APP_DIR . '/.deploy-tmp-' . bin2hex(random_bytes(6));
    if (!@mkdir($tmp, 0700)) stop("files: cannot create $tmp");
    try {
        if (class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($zipPath) !== true) stop('files: the uploaded package is not a valid zip');
            for ($i = 0; $i < $z->numFiles; $i++) {
                $n = (string) $z->getNameIndex($i);
                if ($n === '' || $n[0] === '/' || str_contains($n, '..') || str_contains($n, '\\')) stop("files: unsafe name in package: $n");
            }
            if (!$z->extractTo($tmp)) stop('files: could not unpack the package');
            $z->close();
        } else {
            (new PharData($zipPath))->extractTo($tmp, null, true);
        }

        $n = $skipped = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($tmp) + 1);
            $dest = APP_DIR . '/' . $rel;
            if ($f->isDir()) {
                if (!is_dir($dest) && !@mkdir($dest, 0755, true)) stop("files: cannot create folder $rel");
                continue;
            }
            if (basename($rel) === '.env') { $skipped++; continue; }   // server settings are never replaced
            $part = $dest . '.deploy-part';
            if (!@copy($f->getPathname(), $part)) stop("files: cannot write $rel");
            @chmod($part, 0644);
            if (!@rename($part, $dest)) { @unlink($part); stop("files: cannot replace $rel"); }
            $n++;
        }
        say("2. files: $n files updated" . ($skipped ? ", $skipped kept as they are on the server (.env)" : '') . ', nothing deleted');
    } finally {
        rrmdir($tmp);
    }
}

/* ------------------------------------------------------------------ */
/* 3. Database scripts                                                 */
/* ------------------------------------------------------------------ */

function disabled(string $fn): bool
{
    $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !function_exists($fn) || in_array($fn, $off, true);
}

function php_cli(): ?string
{
    $v = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    $list = array_filter([PHP_BIN, "/opt/cpanel/ea-php$v/root/usr/bin/php", "/usr/local/bin/ea-php$v", '/usr/local/bin/php', '/usr/bin/php']);
    foreach ($list as $p) if (@is_file($p) && @is_executable($p)) return $p;
    return PHP_BIN !== '' ? PHP_BIN : null;
}

function run_db_scripts(): void
{
    $cmds = CFG['db_commands'];
    if (!$cmds) { say('3. database: no database commands for this app'); return; }
    $php = php_cli();
    $canRun = $php !== null && !disabled('proc_open');
    if (!$canRun) say('3. database: this hosting cannot start the PHP command line from PHP (proc_open off or no php found). Set the PHP_BIN variable if your host gives a path.');

    foreach ($cmds as $line) {
        $args = preg_split('/\s+/', trim($line));
        say("3. php $line");
        if (!is_file(APP_DIR . '/' . $args[0])) stop("$args[0] not found in the app folder");
        if ($canRun) {
            $p = proc_open(array_merge([$php], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_DIR);
            if (!is_resource($p)) stop("could not start $php");
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($p);
            foreach (preg_split('/\R/', trim($out)) as $l) if ($l !== '') say("   $l");
            if ($code !== 0) stop("'php $line' ended with exit code $code. Files are deployed; the database backup from step 1 is in " . CFG['backup_dir']);
            continue;
        }
        // fallback: a plain PHP script without arguments that also works in a web request
        $src = (string) @file_get_contents(APP_DIR . '/' . $args[0]);
        if (count($args) > 1 || str_contains($src, 'PHP_SAPI') || str_contains($src, 'php_sapi_name') || str_contains($src, 'STDOUT')) {
            stop("'php $line' needs the PHP command line. Files are deployed; set the PHP_BIN variable (e.g. /usr/local/bin/ea-php83) or ask the host to allow proc_open.");
        }
        chdir(APP_DIR);
        ob_start();
        try {
            (static function (string $f) { require $f; })(APP_DIR . '/' . $args[0]);
        } catch (Throwable $e) {
            say((string) ob_get_clean());
            stop("'php $line' failed: " . $e->getMessage());
        }
        foreach (preg_split('/\R/', trim((string) ob_get_clean())) as $l) if ($l !== '') say("   $l");
    }
}

backup_database();
deploy_files($zipPath);
run_db_scripts();
say('DEPLOY OK');
