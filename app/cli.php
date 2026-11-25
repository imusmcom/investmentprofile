<?php
declare(strict_types=1);

/**
 * Argument handling for the scripts in bin/, shared so all three behave the same way.
 *
 * Two rules matter: an unrecognised argument stops the script instead of being skipped, and the
 * data directory / timezone are chosen before bootstrap runs. Both exist for the same reason —
 * a mistyped `--data-dir` used to be ignored, and the script then rewrote data/portfolio.sqlite,
 * the one file on this machine that holds real holdings.
 */

/**
 * Parse argv and apply the environment switches, before app/bootstrap.php is required.
 *
 * @param array<int,string> $argv     the script's $argv, program name included
 * @param array<int,string> $flags    options that take no value, e.g. ['help', 'force']
 * @param array<int,string> $options  options that need `--name=value`
 * @param string $usage               how the script is invoked, quoted in the error message
 * @return array<string,string|true>  name => value (true for a flag)
 */
function cli_setup(array $argv, array $flags, array $options, string $usage): array
{
    $opts = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z][a-z-]*)(?:=(.*))?$/', $arg, $m) !== 1) {
            cli_bail($arg, $usage);
        }

        $name = $m[1];
        $value = $m[2] ?? '';
        $gaveValue = strpos($arg, '=') !== false;

        if (in_array($name, $flags, true)) {
            if ($gaveValue) {
                cli_bail("--{$name} 是開關，不能帶值", $usage);
            }
            $opts[$name] = true;
        } elseif (in_array($name, $options, true)) {
            if (!$gaveValue) {
                cli_bail("--{$name} 需要寫成 --{$name}=值", $usage);
            }
            if ($value === '' && in_array($name, ['data-dir', 'tz'], true)) {
                cli_bail("--{$name} 不能是空值", $usage);
            }
            if ($name === 'data-dir') {
                // app/config.php 會把相對路徑接到專案根，驗證時也要用同一個基準，不能改看目前工作目錄。
                $resolved = $value;
                if (preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $resolved) !== 1) {
                    $resolved = dirname(__DIR__) . '/' . $resolved;
                }
                if (!is_dir($resolved)) {
                    cli_bail("--data-dir 必須是既有的目錄：{$value}", $usage);
                }
            }
            $opts[$name] = $value;
        } else {
            cli_bail($arg, $usage);
        }
    }

    if (isset($opts['data-dir'])) {
        putenv('PORTFOLIO_DATA_DIR=' . $opts['data-dir']);
    }
    if (isset($opts['tz'])) {
        putenv('PORTFOLIO_TZ=' . $opts['tz']);
    }

    return $opts;
}

function cli_bail(string $what, string $usage): void
{
    fwrite(STDERR, "看不懂這個參數：{$what}\n用法：{$usage}\n");
    exit(2);
}
