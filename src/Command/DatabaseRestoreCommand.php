<?php

namespace App\Command;

use App\Repository\BackupSettingRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:database:restore',
    description: 'Restore a database backup (local file or CIFS share).',
)]
class DatabaseRestoreCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BackupSettingRepository $settingRepo,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to backup file (local) or filename on CIFS share')
            ->addOption('backup-password', null, InputOption::VALUE_REQUIRED, 'Password to decrypt an encrypted backup')
            ->addOption('cifs-server', null, InputOption::VALUE_REQUIRED, 'CIFS server and share to download backup from')
            ->addOption('cifs-user', null, InputOption::VALUE_REQUIRED, 'CIFS username')
            ->addOption('cifs-password', null, InputOption::VALUE_REQUIRED, 'CIFS password')
            ->addOption('skip-migrations', null, InputOption::VALUE_NONE, 'Skip running doctrine migrations after restore')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $settings = $this->settingRepo->getInstance();
        $filePath = $input->getArgument('file');

        $backupPassword = $input->getOption('backup-password') ?? '';
        $cifsServer     = $input->getOption('cifs-server')     ?? $settings->getCifsServer()   ?? '';
        $cifsUser       = $input->getOption('cifs-user')       ?? $settings->getCifsUsername() ?? '';
        $cifsPassword   = $input->getOption('cifs-password')   ?? $settings->getCifsPassword() ?? '';
        $skipMigrations = (bool) $input->getOption('skip-migrations');

        // -- Confirm destructive operation -------------------------------------
        if ($input->isInteractive()) {
            $io->warning([
                'This will OVERWRITE the current database with the contents of the backup.',
                'All existing data will be replaced.',
            ]);
            if (!$io->confirm('Are you sure you want to continue?', false)) {
                $io->note('Restore cancelled.');
                return Command::SUCCESS;
            }
        }

        // -- Download from CIFS if requested -----------------------------------
        $tmpDownload = null;
        if ($cifsServer !== '') {
            $io->writeln('Downloading backup from CIFS…');
            $tmpDownload = tempnam('/tmp', 'dashddi_restore_');
            $getCmd      = 'get "' . $filePath . '" "' . $tmpDownload . '"';

            $process = new Process(['smbclient', $cifsServer, '-U', $cifsUser, '-c', $getCmd]);
            $process->setEnv(['PASSWD' => $cifsPassword]);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                $io->error('CIFS download failed: ' . $process->getErrorOutput());
                return Command::FAILURE;
            }

            $filePath = $tmpDownload;
        }

        if (!file_exists($filePath)) {
            $io->error("Backup file not found: {$filePath}");
            $this->cleanup($tmpDownload);
            return Command::FAILURE;
        }

        // -- Decrypt if needed -------------------------------------------------
        $isEncrypted = str_ends_with($filePath, '.enc') || str_ends_with(basename($filePath), '.sql.enc');
        $sqlFile     = $filePath;
        $tmpDecrypt  = null;

        if ($isEncrypted) {
            if ($backupPassword === '') {
                $io->error('This backup is encrypted. Provide --backup-password to decrypt it.');
                $this->cleanup($tmpDownload);
                return Command::FAILURE;
            }

            $io->writeln('Decrypting backup…');
            $decrypted = $this->decryptContent(file_get_contents($filePath), $backupPassword);

            if ($decrypted === null) {
                $io->error('Decryption failed. Wrong password or corrupted file.');
                $this->cleanup($tmpDownload);
                return Command::FAILURE;
            }

            $tmpDecrypt = tempnam('/tmp', 'dashddi_sql_');
            file_put_contents($tmpDecrypt, $decrypted);
            $sqlFile = $tmpDecrypt;
        }

        // -- Import SQL via DBAL -----------------------------------------------
        $io->writeln('Importing SQL…');

        try {
            $sql = file_get_contents($sqlFile);

            // Drop all existing tables first so FK ordering never blocks a DROP.
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
            $existingTables = $this->connection->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            );
            foreach ($existingTables as $tbl) {
                $this->connection->executeStatement("DROP TABLE IF EXISTS `{$tbl}`");
            }

            // Execute backup statements; skip DROP TABLE lines (tables already gone).
            $statements = $this->splitSqlStatements($sql);
            $count      = 0;

            foreach ($statements as $stmt) {
                $upper = strtoupper(ltrim($stmt));
                if ($stmt === '' || str_starts_with($stmt, '--')) {
                    continue;
                }
                // Skip DROP TABLE — we already wiped the schema above.
                if (str_starts_with($upper, 'DROP TABLE')) {
                    continue;
                }
                $this->connection->executeStatement($stmt);
                $count++;
            }

            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        } catch (\Throwable $e) {
            $this->cleanup($tmpDownload, $tmpDecrypt);
            $io->error('SQL import failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->cleanup($tmpDownload, $tmpDecrypt);
        $io->success("Database imported ({$count} statements executed).");

        // -- Run migrations ----------------------------------------------------
        if (!$skipMigrations) {
            $io->writeln('Running pending migrations…');

            $migrate = new Process([
                'php', $this->projectDir . '/bin/console',
                'doctrine:migrations:migrate',
                '--no-interaction',
                '--allow-no-migration',
            ]);
            $migrate->setTimeout(120);
            $migrate->run();

            if (!$migrate->isSuccessful()) {
                $io->warning('Migrations may have failed: ' . $migrate->getErrorOutput());
            } else {
                $io->writeln(trim($migrate->getOutput()));
                $io->success('Migrations applied.');
            }
        }

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------------------

    /**
     * State-machine SQL splitter: handles quoted strings, `backtick` identifiers,
     * -- line comments, and /* block comments *\/. Safe for mysqldump output.
     *
     * @return string[]
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $len        = strlen($sql);
        $i          = 0;

        while ($i < $len) {
            $c = $sql[$i];

            // -- line comment
            if ($c === '-' && ($i + 1 < $len) && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // /* block comment */
            if ($c === '/' && ($i + 1 < $len) && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }

            // Quoted string or backtick identifier
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote    = $c;
                $current .= $c;
                $i++;
                while ($i < $len) {
                    $ch       = $sql[$i];
                    $current .= $ch;
                    if ($ch === '\\') {          // backslash escape
                        $i++;
                        if ($i < $len) {
                            $current .= $sql[$i];
                            $i++;
                        }
                        continue;
                    }
                    if ($ch === $quote) {        // closing quote
                        // doubled-quote escape: '' or ``
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $current .= $sql[$i + 1];
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // Statement terminator
            if ($c === ';') {
                $stmt = trim($current);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $c;
            $i++;
        }

        $remaining = trim($current);
        if ($remaining !== '') {
            $statements[] = $remaining;
        }

        return $statements;
    }

    private function decryptContent(string $data, string $password): ?string
    {
        // Format: magic(8) + salt(16) + iv(16) + ciphertext
        if (strlen($data) < 40 || substr($data, 0, 8) !== 'DASHDDI1') {
            return null;
        }

        $salt      = substr($data, 8, 16);
        $iv        = substr($data, 24, 16);
        $encrypted = substr($data, 40);

        $key    = openssl_pbkdf2($password, $salt, 32, 100000, 'sha256');
        $result = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $result === false ? null : $result;
    }

    private function cleanup(?string ...$files): void
    {
        foreach ($files as $file) {
            if ($file !== null && file_exists($file)) {
                unlink($file);
            }
        }
    }
}
