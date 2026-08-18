<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:oui:update',
    description: 'Refresh the bundled MAC vendor (IEEE OUI) database from the public IEEE registry',
)]
class UpdateOuiDatabaseCommand extends Command
{
    private const SOURCE_URL = 'https://standards-oui.ieee.org/oui/oui.csv';

    public function __construct(private readonly string $databasePath)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $context = stream_context_create(['http' => ['timeout' => 30]]);
        $csv = @file_get_contents(self::SOURCE_URL, false, $context);
        if ($csv === false) {
            $io->error('Could not download the IEEE OUI registry from ' . self::SOURCE_URL);
            return Command::FAILURE;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'oui');
        file_put_contents($tmp, $csv);

        $vendors = [];
        $fh = fopen($tmp, 'r');
        fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 3) {
                continue;
            }
            [, $assignment, $orgName] = $row;
            $prefix = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $assignment));
            $orgName = trim($orgName);
            if (strlen($prefix) === 6 && $orgName !== '') {
                $vendors[$prefix] = $orgName;
            }
        }
        fclose($fh);
        unlink($tmp);

        if (empty($vendors)) {
            $io->error('Downloaded registry contained no usable entries — aborting.');
            return Command::FAILURE;
        }

        ksort($vendors);

        $out = "<?php\n\n";
        $out .= "// Auto-generated from the IEEE MA-L (24-bit OUI) public registry.\n";
        $out .= "// Regenerate with: bin/console app:oui:update\n";
        $out .= "// Source: " . self::SOURCE_URL . "\n";
        $out .= "return [\n";
        foreach ($vendors as $prefix => $name) {
            $out .= "    '" . $prefix . "' => " . var_export($name, true) . ",\n";
        }
        $out .= "];\n";

        file_put_contents($this->databasePath, $out);

        $io->success(sprintf('Updated %s with %d vendor entries.', $this->databasePath, count($vendors)));
        return Command::SUCCESS;
    }
}
