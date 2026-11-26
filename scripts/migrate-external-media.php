<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2026 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Copia imagens externas (https) para a pasta de mídia da árvore.
 *
 * O arquivo físico fica em INDEX_DIRECTORY + gedcom.media_folder + caminho
 * relativo. O valor guardado em multimedia_file_refn, e na linha FILE do
 * GEDCOM, é só o caminho relativo à pasta da árvore. Este script grava o
 * arquivo com o nome {m_id}.{extensão}. Não altera o banco.
 *
 * Uso, dentro do container app:
 *   php scripts/migrate-external-media.php
 *   php scripts/migrate-external-media.php --execute
 *   php scripts/migrate-external-media.php --emit-update
 */

declare(strict_types=1);

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Webtrees;

require dirname(__DIR__) . '/vendor/autoload.php';

const MEDIA_MIGRATION_MAX_BYTES = 32 * 1024 * 1024;

/**
 * Tipos aceitos por MediaFile::isImage() e o FORM canônico de MultimediaFormat.
 *
 * @var array<string, array{extension: string, form: string}>
 */
const MEDIA_MIGRATION_IMAGE_TYPES = [
    'image/jpeg' => ['extension' => 'jpg', 'form' => 'JPG'],
    'image/png'  => ['extension' => 'png', 'form' => 'PNG'],
    'image/gif'  => ['extension' => 'gif', 'form' => 'GIF'],
    'image/webp' => ['extension' => 'webp', 'form' => 'WEBP'],
];

exit(mediaMigrationMain($argv));

/**
 * @param array<int, string> $argv
 */
function mediaMigrationMain(array $argv): int
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "Este script só pode ser executado na linha de comando.\n");

        return 1;
    }

    $parsed = mediaMigrationParseArgs($argv);

    if ($parsed['help']) {
        mediaMigrationHelp();

        return 0;
    }

    if ($parsed['error'] !== null) {
        fwrite(STDERR, $parsed['error'] . "\n");
        mediaMigrationHelp();

        return 1;
    }

    try {
        mediaMigrationBootstrap();
    } catch (Throwable $exception) {
        fwrite(STDERR, "Falha ao conectar ao banco. Nenhum arquivo foi gravado.\n");

        return 1;
    }

    $schema = DB::table('site_setting')
        ->where('setting_name', '=', 'WT_SCHEMA_VERSION')
        ->value('setting_value');

    if ((string) $schema !== (string) Webtrees::SCHEMA_VERSION) {
        fwrite(STDERR, 'O esquema do banco (' . (string) $schema . ') difere de Webtrees::SCHEMA_VERSION (' . Webtrees::SCHEMA_VERSION . "). Nenhuma alteração foi feita.\n");

        return 1;
    }

    try {
        $logDirectory = mediaMigrationLogDirectory();

        if ($parsed['mode'] === 'emit-update') {
            return mediaMigrationEmitUpdate($logDirectory);
        }

        return mediaMigrationRun($parsed['mode'], $parsed['hosts'], $logDirectory);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");

        return 1;
    }
}

/**
 * @param array<int, string> $argv
 *
 * @return array{mode: string, hosts: array<int, string>, help: bool, error: string|null}
 */
function mediaMigrationParseArgs(array $argv): array
{
    $result = [
        'mode'  => 'dry-run',
        'hosts' => ['sites-cf.mhcache.com'],
        'help'  => false,
        'error' => null,
    ];

    $execute    = false;
    $emitUpdate = false;
    $dryRun     = false;
    $hosts      = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $result['help'] = true;

            return $result;
        }

        if ($argument === '--execute') {
            $execute = true;
            continue;
        }

        if ($argument === '--dry-run') {
            $dryRun = true;
            continue;
        }

        if ($argument === '--emit-update') {
            $emitUpdate = true;
            continue;
        }

        if (str_starts_with($argument, '--host=')) {
            $host = strtolower(trim(substr($argument, 7)));

            if ($host === '' || str_contains($host, '/')) {
                $result['error'] = 'Host inválido em --host.';

                return $result;
            }

            $hosts[] = $host;
            continue;
        }

        $result['error'] = 'Argumento desconhecido: ' . $argument;

        return $result;
    }

    $selected = ($execute ? 1 : 0) + ($emitUpdate ? 1 : 0) + ($dryRun ? 1 : 0);

    if ($selected > 1) {
        $result['error'] = 'Use apenas um modo: --dry-run, --execute ou --emit-update.';

        return $result;
    }

    if ($execute) {
        $result['mode'] = 'execute';
    } elseif ($emitUpdate) {
        $result['mode'] = 'emit-update';
    }

    if ($hosts !== []) {
        $result['hosts'] = array_values(array_unique($hosts));
    }

    return $result;
}

function mediaMigrationHelp(): void
{
    $help = <<<'HELP'
Migração de imagens externas para a pasta de mídia do webtrees.

  php scripts/migrate-external-media.php [--dry-run]
  php scripts/migrate-external-media.php --execute
  php scripts/migrate-external-media.php --emit-update
  php scripts/migrate-external-media.php --host=sites-cf.mhcache.com

O modo padrão é --dry-run: consulta o banco, calcula os caminhos e grava
somente o relatório em data/media-migration/. Não baixa arquivos e não
altera o banco.

--execute baixa as imagens e grava os arquivos que ainda não existem.
--emit-update gera o SQL de atualização a partir do manifesto, sem executá-lo.

HELP;

    fwrite(STDOUT, $help);
}

function mediaMigrationBootstrap(): void
{
    Webtrees::new()->bootstrap();

    $config = parse_ini_file(Webtrees::CONFIG_FILE);

    if ($config === false) {
        throw new RuntimeException('config.ini.php ilegível');
    }

    DB::connect(
        driver: (string) ($config['dbtype'] ?? DB::MYSQL),
        host: (string) ($config['dbhost'] ?? ''),
        port: (string) ($config['dbport'] ?? ''),
        database: (string) ($config['dbname'] ?? ''),
        username: (string) ($config['dbuser'] ?? ''),
        password: (string) ($config['dbpass'] ?? ''),
        prefix: (string) ($config['tblpfx'] ?? ''),
        key: (string) ($config['dbkey'] ?? ''),
        certificate: (string) ($config['dbcert'] ?? ''),
        ca: (string) ($config['dbca'] ?? ''),
        verify_certificate: (bool) ($config['dbverify'] ?? false),
    );
}

function mediaMigrationLogDirectory(): string
{
    $root = realpath(Webtrees::DATA_DIR);

    if ($root === false) {
        throw new RuntimeException('A pasta data do webtrees não está acessível.');
    }

    return $root . '/media-migration';
}

/**
 * @param array<int, string> $hosts
 */
function mediaMigrationRun(string $mode, array $hosts, string $logDirectory): int
{
    $execute   = $mode === 'execute';
    $dataRoot  = mediaMigrationDataRoot();
    $records   = mediaMigrationRecords($hosts);
    $stamp     = gmdate('Ymd\THis\Z');
    $logPath   = mediaMigrationUniquePath($logDirectory, 'run-' . $stamp, '.jsonl');
    $reportPath = mediaMigrationUniquePath($logDirectory, 'report-' . $stamp, '.json');

    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
        fwrite(STDERR, "Não foi possível criar o diretório de log.\n");

        return 1;
    }

    $log = fopen($logPath, 'xb');

    if ($log === false) {
        fwrite(STDERR, "Não foi possível criar o log.\n");

        return 1;
    }

    $counts = [
        'found'            => count($records),
        'planned'          => 0,
        'downloaded'       => 0,
        'already_present'  => 0,
        'invalid'          => 0,
        'inaccessible'     => 0,
    ];

    $reservedPaths = [];

    fwrite(STDOUT, ($execute ? 'Execução' : 'Simulação') . '. Registros encontrados: ' . $counts['found'] . "\n");

    foreach ($records as $record) {
        $entry = mediaMigrationProcessRecord($record, $dataRoot, $execute, $reservedPaths);
        $status = $entry['status'];

        if (!isset($counts[$status])) {
            $counts['invalid']++;
        } else {
            $counts[$status]++;
        }

        fwrite($log, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        fwrite(STDOUT, $entry['m_id'] . "\t" . $status . "\t" . $entry['relative_path'] . "\n");
    }

    fclose($log);

    $report = [
        'generated_at'        => gmdate('c'),
        'mode'                => $mode,
        'webtrees'            => Webtrees::VERSION,
        'schema_version'      => Webtrees::SCHEMA_VERSION,
        'index_directory'     => $dataRoot,
        'host_filter'         => $hosts,
        'found'               => $counts['found'],
        'planned'             => $counts['planned'],
        'downloaded'          => $counts['downloaded'],
        'already_present'     => $counts['already_present'],
        'invalid'             => $counts['invalid'],
        'inaccessible'        => $counts['inaccessible'],
        'http_validation'     => $execute ? 'performed' : 'not_performed',
        'database_updates'    => 0,
        'files_written'       => $counts['downloaded'],
        'individuals_linked_to_these_media' => mediaMigrationLinkedIndividuals($records),
        'log'                 => $logPath,
    ];

    file_put_contents(
        $reportPath,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX,
    );

    fwrite(STDOUT, sprintf(
        "Encontradas: %d. Planejadas: %d. Baixadas: %d. Já existentes: %d. Inválidas: %d. Inacessíveis: %d.\n",
        $counts['found'],
        $counts['planned'],
        $counts['downloaded'],
        $counts['already_present'],
        $counts['invalid'],
        $counts['inaccessible'],
    ));
    fwrite(STDOUT, 'Relatório: ' . $reportPath . "\n");
    fwrite(STDOUT, 'Log: ' . $logPath . "\n");

    if (!$execute) {
        fwrite(STDOUT, "Nenhum arquivo de mídia foi gravado e o banco não foi alterado.\n");
    }

    return ($counts['invalid'] + $counts['inaccessible']) > 0 ? 2 : 0;
}

function mediaMigrationDataRoot(): string
{
    $index = Site::getPreference('INDEX_DIRECTORY');
    $real  = realpath($index);

    if ($real === false || !is_dir($real)) {
        throw new RuntimeException('INDEX_DIRECTORY indisponível');
    }

    return $real . DIRECTORY_SEPARATOR;
}

/**
 * @param array<int, string> $hosts
 *
 * @return array<int, array<string, string>>
 */
function mediaMigrationRecords(array $hosts): array
{
    $rows = DB::table('media_file')
        ->join('media', static function ($join): void {
            $join->on('media.m_file', '=', 'media_file.m_file')
                ->on('media.m_id', '=', 'media_file.m_id');
        })
        ->join('gedcom', 'gedcom.gedcom_id', '=', 'media_file.m_file')
        ->where('gedcom.gedcom_id', '>', 0)
        ->where('media_file.multimedia_file_refn', 'like', 'https://%')
        ->orderBy('media_file.m_file')
        ->orderBy('media_file.m_id')
        ->get([
            'media_file.m_id',
            'media_file.m_file',
            'media_file.multimedia_file_refn',
            'media_file.multimedia_format',
            'media.m_gedcom',
            'gedcom.gedcom_name',
            'gedcom.media_folder',
        ]);

    $records = [];

    foreach ($rows as $row) {
        $url  = (string) $row->multimedia_file_refn;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (!in_array($host, $hosts, true)) {
            continue;
        }

        $records[] = [
            'm_id'         => (string) $row->m_id,
            'm_file'       => (string) $row->m_file,
            'url'          => $url,
            'format'       => (string) $row->multimedia_format,
            'gedcom'       => (string) $row->m_gedcom,
            'tree'         => (string) $row->gedcom_name,
            'media_folder' => (string) $row->media_folder,
        ];
    }

    return $records;
}

/**
 * @param array<string, string> $record
 * @param array<string, true>   $reservedPaths
 *
 * @return array<string, int|string>
 */
function mediaMigrationProcessRecord(array $record, string $dataRoot, bool $execute, array &$reservedPaths): array
{
    $base = [
        'm_id'           => $record['m_id'],
        'm_file'         => $record['m_file'],
        'tree'           => $record['tree'],
        'media_folder'   => $record['media_folder'],
        'source_url'     => $record['url'],
        'previous_form'  => strtoupper($record['format']),
        'relative_path'  => '',
        'absolute_path'  => '',
        'form'           => '',
        'http_status'    => 0,
        'content_type'   => '',
        'bytes'          => 0,
        'sha256'         => '',
        'width'          => 0,
        'height'         => 0,
        'status'         => 'invalid',
        'message'        => '',
    ];

    $problem = mediaMigrationRecordProblem($record);

    if ($problem !== null) {
        $base['message'] = $problem;

        return $base;
    }

    $extension = mediaMigrationExtensionFromForm($record['format']);
    $relative  = $record['m_id'] . '.' . $extension;
    $absolute  = mediaMigrationAbsolutePath($dataRoot, $record['media_folder'], $relative);
    $pathKey   = $record['m_file'] . "\0" . $record['media_folder'] . "\0" . $relative;

    $base['relative_path'] = $relative;
    $base['form']          = strtoupper($record['format']) === 'JPEG' ? 'JPG' : strtoupper($record['format']);
    $base['absolute_path'] = $absolute;

    if ($absolute === null || isset($reservedPaths[$pathKey])) {
        $base['message'] = 'O caminho local colide com outro registro ou sai da pasta de dados.';

        return $base;
    }

    $reservedPaths[$pathKey] = true;

    if (mediaMigrationFileExists($record, $relative)) {
        $existing = mediaMigrationDescribeFile($absolute);

        if ($existing === null) {
            $base['status']  = 'invalid';
            $base['message'] = 'Já existe um arquivo nesse caminho e ele não é uma imagem aceita. Não foi substituído.';

            return $base;
        }

        return array_merge($base, $existing, [
            'status'  => 'already_present',
            'message' => 'Arquivo já presente. Não foi substituído.',
        ]);
    }

    if (!$execute) {
        $base['status']  = 'planned';
        $base['message'] = 'Simulação. O arquivo seria gravado nesse caminho depois de validar o HTTP.';

        return $base;
    }

    $download = mediaMigrationDownload($record['url']);
    $base['http_status']  = $download['http_status'];
    $base['content_type'] = $download['content_type'];

    if ($download['status'] === 'inaccessible') {
        $base['status']  = 'inaccessible';
        $base['message'] = $download['message'];

        return $base;
    }

    $image = mediaMigrationInspectImage($download['body'], $download['content_type']);

    if ($image === null) {
        $base['status']  = 'invalid';
        $base['bytes']   = strlen($download['body']);
        $base['message'] = 'A resposta não é uma imagem GIF, JPEG, PNG ou WebP íntegra.';

        return $base;
    }

    $relative = $record['m_id'] . '.' . $image['extension'];
    $absolute = mediaMigrationAbsolutePath($dataRoot, $record['media_folder'], $relative);
    $pathKey  = $record['m_file'] . "\0" . $record['media_folder'] . "\0" . $relative;

    if ($absolute === null || ($relative !== $base['relative_path'] && isset($reservedPaths[$pathKey]))) {
        $base['message'] = 'O caminho local calculado é inválido ou colide com outro registro.';

        return $base;
    }

    $reservedPaths[$pathKey] = true;

    $base['relative_path'] = $relative;
    $base['absolute_path'] = $absolute;
    $base['form']          = $image['form'];
    $base['bytes']         = $image['bytes'];
    $base['sha256']        = $image['sha256'];
    $base['width']         = $image['width'];
    $base['height']        = $image['height'];

    if (mediaMigrationFileExists($record, $relative)) {
        $base['status']  = 'already_present';
        $base['message'] = 'Arquivo já presente. Não foi substituído.';

        return $base;
    }

    $written = mediaMigrationWriteExclusive($absolute, $download['body']);

    if ($written !== true) {
        if (is_file($absolute)) {
            $base['status']  = 'already_present';
            $base['message'] = 'Arquivo já presente. Não foi substituído.';

            return $base;
        }

        $base['status']  = 'invalid';
        $base['message'] = 'Não foi possível gravar o arquivo.';

        return $base;
    }

    if (!mediaMigrationFileExists($record, $relative)) {
        $base['status']  = 'invalid';
        $base['message'] = 'O arquivo foi gravado, mas a pasta de mídia do webtrees não o enxerga.';

        return $base;
    }

    $base['status']  = 'downloaded';
    $base['message'] = 'Imagem gravada.';

    return $base;
}

/**
 * @param array<string, string> $record
 */
function mediaMigrationRecordProblem(array $record): string|null
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $record['m_id']) !== 1) {
        return 'O identificador da mídia contém caracteres que o webtrees não aceita em nome de arquivo.';
    }

    $parts = parse_url($record['url']);

    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
        return 'A URL não é HTTPS simples.';
    }

    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return 'A URL usa uma porta diferente de 443.';
    }

    if (preg_match_all('/^\d FILE (.+)$/m', $record['gedcom'], $files) !== 1) {
        return 'O registro GEDCOM não tem exatamente uma linha FILE.';
    }

    if ($files[1][0] !== $record['url']) {
        return 'A linha FILE do GEDCOM difere de multimedia_file_refn.';
    }

    if (preg_match_all('/^\d FORM (.+)$/m', $record['gedcom'], $forms) !== 1) {
        return 'O registro GEDCOM não tem exatamente uma linha FORM.';
    }

    $gedcomForm = strtoupper($forms[1][0]);
    $indexForm  = strtoupper($record['format']);

    if ($gedcomForm !== $indexForm && !($indexForm === 'JPEG' && $gedcomForm === 'JPG')) {
        return 'A linha FORM do GEDCOM difere de multimedia_format.';
    }

    if (strlen($record['url']) > 248) {
        return 'A referência passa de 248 caracteres, limite da coluna multimedia_file_refn.';
    }

    if (mediaMigrationExtensionFromForm($record['format']) === null) {
        return 'O FORM atual não é GIF, JPEG, PNG nem WebP.';
    }

    return null;
}

function mediaMigrationExtensionFromForm(string $format): string|null
{
    return match (strtoupper($format)) {
        'JPG', 'JPEG' => 'jpg',
        'PNG'         => 'png',
        'GIF'         => 'gif',
        'WEBP'        => 'webp',
        default       => null,
    };
}

function mediaMigrationAbsolutePath(string $dataRoot, string $mediaFolder, string $relative): string|null
{
    $folder = trim(str_replace('\\', '/', $mediaFolder), '/');
    $name   = ltrim(str_replace('\\', '/', $relative), '/');

    if ($name === '' || str_contains($name, '..') || str_contains($folder, '..') || str_contains($name, ':')) {
        return null;
    }

    $path = rtrim($dataRoot, '/') . '/';

    if ($folder !== '') {
        $path .= $folder . '/';
    }

    $path .= $name;
    $root = rtrim($dataRoot, '/') . '/';

    if (!str_starts_with($path, $root)) {
        return null;
    }

    return $path;
}

/**
 * @param array<string, string> $record
 */
function mediaMigrationFileExists(array $record, string $relative): bool
{
    try {
        return Registry::filesystem()->data($record['media_folder'])->fileExists($relative);
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array{bytes: int, sha256: string, width: int, height: int, content_type: string}|null
 */
function mediaMigrationDescribeFile(string $absolute): array|null
{
    if (!is_file($absolute)) {
        return null;
    }

    $bytes = file_get_contents($absolute);

    if ($bytes === false) {
        return null;
    }

    $image = mediaMigrationInspectImage($bytes, '');

    if ($image === null) {
        return null;
    }

    return [
        'bytes'        => $image['bytes'],
        'sha256'       => $image['sha256'],
        'width'        => $image['width'],
        'height'       => $image['height'],
        'content_type' => $image['mime'],
    ];
}

/**
 * @return array{status: string, http_status: int, content_type: string, body: string, message: string}
 */
function mediaMigrationDownload(string $url): array
{
    $failure = [
        'status'       => 'inaccessible',
        'http_status'  => 0,
        'content_type' => '',
        'body'         => '',
        'message'      => 'Falha de rede.',
    ];

    if (!function_exists('curl_init')) {
        $failure['message'] = 'A extensão curl não está disponível.';

        return $failure;
    }

    $handle = curl_init($url);

    if ($handle === false) {
        return $failure;
    }

    $body = '';

    curl_setopt_array($handle, [
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_MAXREDIRS        => 3,
        CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT   => 15,
        CURLOPT_TIMEOUT          => 60,
        CURLOPT_USERAGENT        => 'webtrees-media-migration/1.0',
        CURLOPT_HTTPHEADER       => ['Accept: image/jpeg, image/png, image/gif, image/webp'],
        CURLOPT_RETURNTRANSFER   => false,
        CURLOPT_HEADER           => false,
        CURLOPT_WRITEFUNCTION    => static function ($curl, string $chunk) use (&$body): int {
            $body .= $chunk;

            if (strlen($body) > MEDIA_MIGRATION_MAX_BYTES) {
                return 0;
            }

            return strlen($chunk);
        },
    ]);

    $ok       = curl_exec($handle);
    $errno    = curl_errno($handle);
    $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $type     = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    $final    = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    $redirects = (int) curl_getinfo($handle, CURLINFO_REDIRECT_COUNT);
    curl_close($handle);

    $mediaType = strtolower(trim(explode(';', $type, 2)[0]));
    $finalHost = strtolower((string) parse_url($final, PHP_URL_HOST));
    $startHost = strtolower((string) parse_url($url, PHP_URL_HOST));

    if ($ok === false || $errno !== 0) {
        $failure['http_status']  = $status;
        $failure['content_type'] = $mediaType;
        $failure['message']      = strlen($body) > MEDIA_MIGRATION_MAX_BYTES
            ? 'A resposta passou do limite de 32 MB.'
            : 'A URL não pôde ser lida.';

        return $failure;
    }

    if ($redirects > 0 && $finalHost !== $startHost) {
        $failure['http_status']  = $status;
        $failure['content_type'] = $mediaType;
        $failure['message']      = 'O redirecionamento saiu do host original.';

        return $failure;
    }

    if ($status !== 200) {
        $failure['http_status']  = $status;
        $failure['content_type'] = $mediaType;
        $failure['message']      = 'HTTP ' . $status . '.';

        return $failure;
    }

    return [
        'status'       => 'ok',
        'http_status'  => $status,
        'content_type' => $mediaType,
        'body'         => $body,
        'message'      => '',
    ];
}

/**
 * @return array{mime: string, extension: string, form: string, bytes: int, sha256: string, width: int, height: int}|null
 */
function mediaMigrationInspectImage(string $bytes, string $declaredMime): array|null
{
    if ($bytes === '' || strlen($bytes) > MEDIA_MIGRATION_MAX_BYTES) {
        return null;
    }

    if (preg_match('/^\s*</', $bytes) === 1) {
        return null;
    }

    $info = @getimagesizefromstring($bytes);

    if (!is_array($info) || !isset($info['mime'], $info[0], $info[1])) {
        return null;
    }

    $mime = strtolower((string) $info['mime']);

    if (!isset(MEDIA_MIGRATION_IMAGE_TYPES[$mime])) {
        return null;
    }

    if ($declaredMime !== '' && $declaredMime !== $mime) {
        return null;
    }

    if (!mediaMigrationMagicMatches($bytes, $mime)) {
        return null;
    }

    $width  = (int) $info[0];
    $height = (int) $info[1];

    if ($width < 1 || $height < 1) {
        return null;
    }

    $type = MEDIA_MIGRATION_IMAGE_TYPES[$mime];

    return [
        'mime'      => $mime,
        'extension' => $type['extension'],
        'form'      => $type['form'],
        'bytes'     => strlen($bytes),
        'sha256'    => hash('sha256', $bytes),
        'width'     => $width,
        'height'    => $height,
    ];
}

function mediaMigrationMagicMatches(string $bytes, string $mime): bool
{
    return match ($mime) {
        'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
        'image/png'  => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
        'image/gif'  => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
        'image/webp' => strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
        default      => false,
    };
}

/**
 * @param array<int, array<string, string>> $records
 */
function mediaMigrationLinkedIndividuals(array $records): int
{
    $byTree = [];

    foreach ($records as $record) {
        $byTree[$record['m_file']][] = $record['m_id'];
    }

    $count = 0;

    foreach ($byTree as $treeId => $xrefs) {
        $count += (int) DB::table('link')
            ->join('individuals', static function ($join): void {
                $join->on('individuals.i_id', '=', 'link.l_from')
                    ->on('individuals.i_file', '=', 'link.l_file');
            })
            ->where('link.l_file', '=', $treeId)
            ->where('link.l_type', '=', 'OBJE')
            ->whereIn('link.l_to', $xrefs)
            ->distinct()
            ->count('individuals.i_id');
    }

    return $count;
}

function mediaMigrationWriteExclusive(string $absolute, string $bytes): bool
{
    $parent = dirname($absolute);
    $root   = realpath(rtrim(Webtrees::DATA_DIR, '/'));

    if ($root === false) {
        return false;
    }

    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        return false;
    }

    $parentReal = realpath($parent);

    if ($parentReal === false || !str_starts_with($parentReal . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
        return false;
    }

    $temporary = $parent . '/.' . basename($absolute) . '.' . bin2hex(random_bytes(6)) . '.partial';
    $handle    = fopen($temporary, 'xb');

    if ($handle === false) {
        return false;
    }

    $written = fwrite($handle, $bytes);
    fflush($handle);
    fclose($handle);

    if ($written !== strlen($bytes)) {
        unlink($temporary);

        return false;
    }

    if (is_file($absolute)) {
        unlink($temporary);

        return false;
    }

    if (!rename($temporary, $absolute)) {
        if (is_file($temporary)) {
            unlink($temporary);
        }

        return false;
    }

    return true;
}

function mediaMigrationEmitUpdate(string $logDirectory): int
{
    $entries = mediaMigrationSuccessfulEntries($logDirectory);
    $stamp   = gmdate('Ymd\THis\Z');

    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
        fwrite(STDERR, "Não foi possível criar o diretório de log.\n");

        return 1;
    }

    $updatePath   = mediaMigrationUniquePath($logDirectory, 'update-media-paths-' . $stamp, '.sql');
    $rollbackPath = mediaMigrationUniquePath($logDirectory, 'rollback-media-paths-' . $stamp, '.sql');
    $verified     = [];

    foreach ($entries as $entry) {
        $absolute = (string) ($entry['absolute_path'] ?? '');
        $sha      = (string) ($entry['sha256'] ?? '');

        if ($absolute === '' || $sha === '' || !is_file($absolute)) {
            continue;
        }

        $contents = file_get_contents($absolute);

        if ($contents === false || hash('sha256', $contents) !== $sha) {
            continue;
        }

        if (mediaMigrationInspectImage($contents, '') === null) {
            continue;
        }

        $verified[] = $entry;
    }

    file_put_contents($updatePath, mediaMigrationSql($verified, false), LOCK_EX);
    file_put_contents($rollbackPath, mediaMigrationSql($verified, true), LOCK_EX);

    fwrite(STDOUT, 'Arquivos verificados para atualização: ' . count($verified) . "\n");
    fwrite(STDOUT, 'SQL de atualização, não executado: ' . $updatePath . "\n");
    fwrite(STDOUT, 'SQL de rollback, não executado: ' . $rollbackPath . "\n");

    return 0;
}

/**
 * @return array<string, array<string, mixed>>
 */
function mediaMigrationSuccessfulEntries(string $logDirectory): array
{
    $entries = [];

    if (!is_dir($logDirectory)) {
        return $entries;
    }

    $paths = glob($logDirectory . '/run-*.jsonl');

    if ($paths === false) {
        return $entries;
    }

    sort($paths, SORT_STRING);

    foreach ($paths as $path) {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            continue;
        }

        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode($line, true);

            if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'downloaded') {
                continue;
            }

            $key = (string) ($decoded['m_file'] ?? '') . "\0" . (string) ($decoded['m_id'] ?? '');
            $entries[$key] = $decoded;
        }

        fclose($handle);
    }

    return $entries;
}

/**
 * @param array<int, array<string, mixed>> $entries
 */
function mediaMigrationSql(array $entries, bool $rollback): string
{
    $media     = '`' . str_replace('`', '``', DB::prefix('media')) . '`';
    $mediaFile = '`' . str_replace('`', '``', DB::prefix('media_file')) . '`';
    $change    = '`' . str_replace('`', '``', DB::prefix('change')) . '`';
    $verb      = $rollback ? 'rollback' : 'atualização';

    $sql = "-- Rotina de {$verb} dos caminhos de mídia.\n"
        . "-- Gerada em " . gmdate('c') . " para o webtrees " . Webtrees::VERSION . ".\n"
        . "-- Não foi executada por este script.\n"
        . "-- A linha FILE em {$media}.m_gedcom é a origem lida por MediaFile.\n"
        . "-- {$mediaFile}.multimedia_file_refn é o índice de busca e precisa mudar junto.\n"
        . "-- O caminho gravado é relativo a gedcom.media_folder.\n"
        . "-- Revise e aplique manualmente. Cada UPDATE só ocorre se o valor atual ainda for o esperado.\n\n"
        . "START TRANSACTION;\n\n";

    if ($entries === []) {
        $sql .= "-- Nenhum arquivo baixado e conferido. Nenhuma declaração UPDATE foi gerada.\n\n";
    }

    foreach ($entries as $entry) {
        $sql .= mediaMigrationStatement($entry, $rollback, $media, $mediaFile, $change) . "\n";
    }

    $sql .= "COMMIT;\n";

    return $sql;
}

/**
 * @param array<string, mixed> $entry
 */
function mediaMigrationStatement(array $entry, bool $rollback, string $media, string $mediaFile, string $change): string
{
    $mId        = mediaMigrationSqlString((string) $entry['m_id']);
    $mFile      = (int) $entry['m_file'];
    $source     = (string) $entry['source_url'];
    $relative   = (string) $entry['relative_path'];
    $oldForm    = strtoupper((string) ($entry['previous_form'] ?? ''));
    $newForm    = strtoupper((string) ($entry['form'] ?? ''));
    $fromFile   = $rollback ? $relative : $source;
    $toFile     = $rollback ? $source : $relative;
    $fromForm   = $rollback ? $newForm : $oldForm;
    $toForm     = $rollback ? $oldForm : $newForm;
    $fromQuoted = mediaMigrationSqlString('1 FILE ' . $fromFile);
    $toQuoted   = mediaMigrationSqlString('1 FILE ' . $toFile);
    $pending    = "NOT EXISTS (SELECT 1 FROM {$change} WHERE status = 'pending' AND gedcom_id = {$mFile} AND xref = {$mId})";

    $fileSql = "UPDATE {$mediaFile}\n"
        . "SET multimedia_file_refn = " . mediaMigrationSqlString($toFile) . ",\n"
        . "    multimedia_format = " . mediaMigrationSqlString($toForm) . "\n"
        . "WHERE m_file = {$mFile}\n"
        . "  AND m_id = {$mId}\n"
        . "  AND multimedia_file_refn = " . mediaMigrationSqlString($fromFile) . "\n"
        . "  AND {$pending};\n";

    $gedcomExpression = "REPLACE(m_gedcom, {$fromQuoted}, {$toQuoted})";

    if ($fromForm !== '' && $toForm !== '' && $fromForm !== $toForm) {
        $gedcomExpression = 'REPLACE(' . $gedcomExpression . ', '
            . mediaMigrationSqlString("\n2 FORM " . $fromForm) . ', '
            . mediaMigrationSqlString("\n2 FORM " . $toForm) . ')';
    }

    $gedcomSql = "UPDATE {$media}\n"
        . "SET m_gedcom = {$gedcomExpression}\n"
        . "WHERE m_file = {$mFile}\n"
        . "  AND m_id = {$mId}\n"
        . "  AND (CHAR_LENGTH(m_gedcom) - CHAR_LENGTH(REPLACE(m_gedcom, {$fromQuoted}, ''))) = CHAR_LENGTH({$fromQuoted})\n"
        . "  AND {$pending};\n";

    return "-- {$entry['m_id']} {$entry['tree']}\n" . $fileSql . $gedcomSql;
}

function mediaMigrationSqlString(string $value): string
{
    $escaped = str_replace(
        ["\\", "'", "\0", "\n", "\r", "\x1a"],
        ["\\\\", "\\'", "\\0", "\\n", "\\r", "\\Z"],
        $value,
    );

    return "'" . $escaped . "'";
}

function mediaMigrationUniquePath(string $directory, string $stem, string $extension): string
{
    $path = $directory . '/' . $stem . $extension;

    if (!is_file($path)) {
        return $path;
    }

    for ($suffix = 2; $suffix < 1000; $suffix++) {
        $candidate = $directory . '/' . $stem . '-' . $suffix . $extension;

        if (!is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Não há nome livre para o log.');
}
