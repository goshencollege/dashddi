<?php

namespace App\Command;

use App\Repository\BackupSettingRepository;
use App\Service\EncryptionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:database:backup',
    description: 'Create a database backup (local path or CIFS share).',
)]
class DatabaseBackupCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BackupSettingRepository $settingRepo,
        private readonly EncryptionService $encryption,
        private readonly string $projectDir,
        private readonly string $encryptionKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('destination', null, InputOption::VALUE_REQUIRED, 'Local path for backup files (overrides settings)')
            ->addOption('cifs-server', null, InputOption::VALUE_REQUIRED, 'CIFS server and share, e.g. //192.168.1.1/share (overrides settings)')
            ->addOption('cifs-user', null, InputOption::VALUE_REQUIRED, 'CIFS username (overrides settings)')
            ->addOption('cifs-password', null, InputOption::VALUE_REQUIRED, 'CIFS password (overrides settings)')
            ->addOption('cifs-subdir', null, InputOption::VALUE_REQUIRED, 'Subdirectory on CIFS share (overrides settings)')
            ->addOption('decrypt-fields', null, InputOption::VALUE_NONE, 'Decrypt encrypted DB fields in the backup SQL')
            ->addOption('include-key', null, InputOption::VALUE_NONE, 'Embed APP_ENCRYPTION_KEY in a SQL comment header')
            ->addOption('encrypt-backup', null, InputOption::VALUE_NONE, 'Encrypt the backup file with AES-256-CBC')
            ->addOption('backup-password', null, InputOption::VALUE_REQUIRED, 'Password for backup file encryption/decryption')
            ->addOption('retention', null, InputOption::VALUE_REQUIRED, 'Number of backups to keep (0 = unlimited, overrides settings)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $settings = $this->settingRepo->getInstance();

        // Resolve destination type
        $cliCifsServer = $input->getOption('cifs-server');
        $cliLocalPath  = $input->getOption('destination');

        if ($cliCifsServer !== null) {
            $destinationType = 'cifs';
        } elseif ($cliLocalPath !== null) {
            $destinationType = 'local';
        } else {
            $destinationType = $settings->getDestinationType();
        }

        $localPath    = $cliLocalPath  ?? $settings->getLocalPath()    ?? $this->projectDir . '/var/backups';
        $cifsServer   = $cliCifsServer ?? $settings->getCifsServer()   ?? '';
        $cifsUser     = $input->getOption('cifs-user')     ?? $settings->getCifsUsername() ?? '';
        $cifsPassword = $input->getOption('cifs-password') ?? $settings->getCifsPassword() ?? '';
        $cifsSubdir   = $input->getOption('cifs-subdir')   ?? $settings->getCifsSubdir()   ?? '';

        $decryptFields  = $input->getOption('decrypt-fields')  || $settings->isDecryptFields();
        $includeKey     = $input->getOption('include-key')     || $settings->isIncludeEncryptionKey();
        $encryptBackup  = $input->getOption('encrypt-backup')  || $settings->isEncryptBackup();
        $backupPassword = $input->getOption('backup-password') ?? $settings->getBackupPassword() ?? '';
        $retention      = $input->getOption('retention') !== null
            ? (int) $input->getOption('retention')
            : $settings->getRetentionCount();

        if ($encryptBackup && $backupPassword === '') {
            $io->error('Backup encryption requires a password. Set --backup-password or configure one in Backup Settings.');
            return Command::FAILURE;
        }

        if ($destinationType === 'cifs' && $cifsServer === '') {
            $io->error('CIFS destination requires a server. Set --cifs-server or configure one in Backup Settings.');
            return Command::FAILURE;
        }

        // -- Dump database via DBAL (no external mysqldump needed) ---------------
        $io->writeln('Dumping database…');

        try {
            $sql = $this->dumpDatabase();
        } catch (\Throwable $e) {
            $io->error('Database dump failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // -- Decrypt fields ------------------------------------------------------
        if ($decryptFields) {
            $io->writeln('Decrypting encrypted fields…');
            $sql = $this->decryptFieldsInSql($sql);
        }

        // -- Build header --------------------------------------------------------
        $now    = new \DateTimeImmutable();
        $header = "-- DashDDI Backup\n-- Generated: " . $now->format('Y-m-d H:i:s') . "\n";

        if ($includeKey && !$decryptFields) {
            $header .= "-- APP_ENCRYPTION_KEY: {$this->encryptionKey}\n";
        }

        $sql = $header . "\n" . $sql;

        // -- Filename & content --------------------------------------------------
        $timestamp = $now->format('Y-m-d_H-i-s');
        $extension = $encryptBackup ? 'sql.enc' : 'sql';
        $filename  = "dashddi_backup_{$timestamp}.{$extension}";

        $content = $encryptBackup ? $this->encryptContent($sql, $backupPassword) : $sql;

        // -- Save ----------------------------------------------------------------
        if ($destinationType === 'cifs') {
            $tmpFile = tempnam('/tmp', 'dashddi_bak_');
            file_put_contents($tmpFile, $content);

            $remotePath = $cifsSubdir !== '' ? rtrim($cifsSubdir, '/') . '/' . $filename : $filename;
            $ok = $this->uploadToCifs($tmpFile, $cifsServer, $cifsUser, $cifsPassword, $remotePath, $cifsSubdir, $io);
            unlink($tmpFile);

            if (!$ok) {
                return Command::FAILURE;
            }

            if ($retention > 0) {
                $this->applyRetentionCifs($cifsServer, $cifsUser, $cifsPassword, $cifsSubdir, $retention, $io);
            }
        } else {
            if (!is_dir($localPath) && !mkdir($localPath, 0755, true) && !is_dir($localPath)) {
                $io->error("Cannot create backup directory: {$localPath}");
                return Command::FAILURE;
            }

            $filePath = rtrim($localPath, '/') . '/' . $filename;
            if (file_put_contents($filePath, $content) === false) {
                $io->error("Cannot write backup file: {$filePath}");
                return Command::FAILURE;
            }

            $io->success("Backup saved: {$filePath}");

            if ($retention > 0) {
                $this->applyRetentionLocal($localPath, $retention, $io);
            }
        }

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------------------

    private function dumpDatabase(): string
    {
        $lines = [];

        $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $lines[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
        $lines[] = 'SET NAMES utf8mb4;';
        $lines[] = '';

        $tables = $this->connection->fetchFirstColumn(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        );

        foreach ($tables as $table) {
            $lines[] = '';
            $lines[] = '-- --------------------------------------------------------';
            $lines[] = "-- Table: `{$table}`";
            $lines[] = '';

            $lines[] = "DROP TABLE IF EXISTS `{$table}`;";
            $create   = $this->connection->fetchAllAssociative("SHOW CREATE TABLE `{$table}`");
            $lines[]  = $create[0]['Create Table'] . ';';
            $lines[]  = '';

            $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `{$table}`");
            if ($total === 0) {
                continue;
            }

            $cols    = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM `{$table}`");
            $colList = implode(', ', array_map(fn($c) => '`' . $c['Field'] . '`', $cols));

            $lines[] = "-- Data for `{$table}`";
            $lines[] = '';

            $batchSize = 500;
            $offset    = 0;

            while ($offset < $total) {
                $rows = $this->connection->fetchAllAssociative(
                    sprintf("SELECT * FROM `%s` LIMIT %d OFFSET %d", $table, $batchSize, $offset)
                );
                if (empty($rows)) {
                    break;
                }

                $valueRows = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $vals[] = 'NULL';
                        } elseif (is_int($value) || is_float($value)) {
                            $vals[] = (string) $value;
                        } else {
                            $vals[] = $this->connection->quote((string) $value);
                        }
                    }
                    $valueRows[] = '(' . implode(', ', $vals) . ')';
                }

                $lines[] = "INSERT INTO `{$table}` ({$colList}) VALUES";
                $lines[] = implode(",\n", $valueRows) . ';';
                $lines[] = '';

                $offset += count($rows);
            }
        }

        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

        return implode("\n", $lines);
    }

    private function decryptFieldsInSql(string $sql): string
    {
        return preg_replace_callback(
            "/'(enc:[A-Za-z0-9+\\/=]+)'/",
            function (array $matches): string {
                try {
                    $plain = $this->encryption->decrypt($matches[1]);
                    return "'" . $this->mysqlEscape($plain) . "'";
                } catch (\Throwable) {
                    return $matches[0];
                }
            },
            $sql
        ) ?? $sql;
    }

    private function mysqlEscape(string $value): string
    {
        return strtr($value, [
            "\x00" => '\\0',
            "\n"   => '\\n',
            "\r"   => '\\r',
            '\\'   => '\\\\',
            "'"    => "\\'",
            '"'    => '\\"',
            "\x1a" => '\\Z',
        ]);
    }

    private function encryptContent(string $plaintext, string $password): string
    {
        $salt      = random_bytes(16);
        $key       = openssl_pbkdf2($password, $salt, 32, 100000, 'sha256');
        $iv        = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return 'DASHDDI1' . $salt . $iv . $encrypted;
    }

    private function uploadToCifs(
        string $localFile,
        string $server,
        string $user,
        string $password,
        string $remotePath,
        string $subdir,
        SymfonyStyle $io,
    ): bool {
        $mkdirCmd = '';
        if ($subdir !== '') {
            $mkdirCmd = 'mkdir "' . addslashes($subdir) . '"; ';
        }

        $putCmd  = $mkdirCmd . 'put "' . $localFile . '" "' . $remotePath . '"';
        $process = new Process(['smbclient', $server, '-U', $user, '-c', $putCmd]);
        $process->setEnv(['PASSWD' => $password]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error('CIFS upload failed: ' . $process->getErrorOutput());
            return false;
        }

        $io->success("Backup uploaded to CIFS: {$server}/{$remotePath}");
        return true;
    }

    private function applyRetentionLocal(string $dir, int $keep, SymfonyStyle $io): void
    {
        $files = glob(rtrim($dir, '/') . '/dashddi_backup_*.sql*') ?: [];
        if (count($files) <= $keep) {
            return;
        }
        sort($files);
        foreach (array_slice($files, 0, count($files) - $keep) as $file) {
            unlink($file);
            $io->writeln('Deleted old backup: ' . basename($file));
        }
    }

    private function applyRetentionCifs(
        string $server,
        string $user,
        string $password,
        string $subdir,
        int $keep,
        SymfonyStyle $io,
    ): void {
        $prefix  = $subdir !== '' ? rtrim($subdir, '/') . '/' : '';
        $process = new Process(['smbclient', $server, '-U', $user, '-c', 'ls "' . $prefix . 'dashddi_backup_*"']);
        $process->setEnv(['PASSWD' => $password]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return;
        }

        $files = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (preg_match('/^\s+(dashddi_backup_\S+)\s/', $line, $m)) {
                $files[] = $m[1];
            }
        }

        if (count($files) <= $keep) {
            return;
        }

        sort($files);
        foreach (array_slice($files, 0, count($files) - $keep) as $file) {
            $delProcess = new Process(['smbclient', $server, '-U', $user, '-c', 'del "' . $prefix . $file . '"']);
            $delProcess->setEnv(['PASSWD' => $password]);
            $delProcess->setTimeout(30);
            $delProcess->run();
            $io->writeln('Deleted old CIFS backup: ' . $file);
        }
    }
}
