<?php

namespace App\Command;

use App\Entity\AddressBlock;
use App\Entity\Building;
use App\Entity\Domain;
use App\Entity\Host;
use App\Entity\InterfaceName;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\Tag;
use App\Enum\BlockType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:legacy',
    description: 'Import data from the legacy IPAM MySQL database',
)]
class ImportLegacyCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate import without writing to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry run — no changes will be written');
        }

        $pdo  = $this->connectLegacy();
        $conn = $this->em->getConnection();

        if (!$dryRun) {
            $conn->beginTransaction();
        }

        try {
            $io->section('Buildings');
            $buildings = $this->importBuildings($pdo, $io, $dryRun);

            $io->section('Domains');
            $domains = $this->importDomains($pdo, $io, $dryRun);

            $io->section('Subnets');
            $subnets = $this->importSubnets($pdo, $io, $dryRun, $domains);

            $io->section('Tags');
            $tags = $this->importTags($pdo, $io, $dryRun);

            $io->section('Hosts');
            $this->importHosts($pdo, $io, $dryRun, $buildings, $domains, $subnets, $tags);

            if (!$dryRun) {
                $conn->commit();
            }
        } catch (\Throwable $e) {
            if (!$dryRun) {
                $conn->rollBack();
            }
            throw $e;
        }

        $io->success($dryRun ? 'Dry run complete — no changes written' : 'Import complete');

        return Command::SUCCESS;
    }

    private function connectLegacy(): \PDO
    {
        $url = $_ENV['LEGACY_DATABASE_URL'] ?? getenv('LEGACY_DATABASE_URL') ?? '';
        if (!$url) {
            throw new \RuntimeException('LEGACY_DATABASE_URL is not set');
        }

        // The URL uses a non-standard user@password@host form (@ instead of : between user and pass).
        // parse_url treats the last @ as the userinfo/host separator, so $parsed['user']
        // ends up as "user@password" — split on that to recover both parts.
        $parsed = parse_url($url);
        $host   = $parsed['host'] ?? 'localhost';
        $port   = $parsed['port'] ?? 3306;
        $dbname = ltrim($parsed['path'] ?? '/dns', '/');
        // strip query string params from dbname if any bled through
        $dbname = explode('?', $dbname)[0];

        $userinfo = $parsed['user'] ?? '';
        if (str_contains($userinfo, '@')) {
            [$user, $pass] = explode('@', $userinfo, 2);
        } else {
            $user = $userinfo;
            $pass = urldecode($parsed['pass'] ?? '');
        }

        return new \PDO(
            "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            $user,
            $pass,
            [
                \PDO::ATTR_ERRMODE                      => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE           => \PDO::FETCH_ASSOC,
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Buildings
    // -------------------------------------------------------------------------

    /** @return array<string, Building> keyed by legacy bID */
    private function importBuildings(\PDO $pdo, SymfonyStyle $io, bool $dryRun): array
    {
        $map = [];

        foreach ($pdo->query('SELECT bID, name FROM building ORDER BY bID')->fetchAll() as $row) {
            $building = new Building();
            $building->setName($row['name']);
            $building->setDescription('Legacy code: ' . $row['bID']);
            $map[$row['bID']] = $building;
            if (!$dryRun) {
                $this->em->persist($building);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('  %d buildings', count($map)));

        return $map;
    }

    // -------------------------------------------------------------------------
    // Domains (zones)
    // -------------------------------------------------------------------------

    /** @return array<int, Domain> keyed by legacy zID */
    private function importDomains(\PDO $pdo, SymfonyStyle $io, bool $dryRun): array
    {
        $map = [];

        foreach ($pdo->query('SELECT zID, name FROM zone ORDER BY zID')->fetchAll() as $row) {
            $domain = new Domain();
            $domain->setName(preg_replace('/^db\./', '', $row['name']));
            $map[(int) $row['zID']] = $domain;
            if (!$dryRun) {
                $this->em->persist($domain);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('  %d domains', count($map)));

        return $map;
    }

    // -------------------------------------------------------------------------
    // Subnets + address blocks
    // -------------------------------------------------------------------------

    /**
     * Returns subnets keyed by legacy nID for single-/24 subnets, or by
     * "{nID}_{thirdOctet}" for subnets that span more than one /24 (e.g. O_234, O_235).
     *
     * @return array<string, Subnet>
     */
    private function importSubnets(\PDO $pdo, SymfonyStyle $io, bool $dryRun, array $domains): array
    {
        $addressRanges = $this->loadAddressRanges($pdo);

        $rows = $pdo->query('SELECT nID, vID, zID, name FROM subnet ORDER BY nID')->fetchAll();
        $map  = [];

        foreach ($rows as $row) {
            $nid    = $row['nID'];
            $ranges = $addressRanges[$nid] ?? [];
            $domain = $domains[(int) $row['zID']] ?? null;
            $multi  = count($ranges) > 1;

            if (empty($ranges)) {
                $subnet      = $this->makeSubnet($row['name'], (int) $row['vID'], null);
                $map[$nid]   = $subnet;
                if (!$dryRun) {
                    $this->em->persist($subnet);
                }
                continue;
            }

            foreach ($ranges as $range) {
                $network   = $range['network'];
                $thirdOctet = explode('.', $network)[2];
                $cidr      = $network . '.0/24';
                $mapKey    = $multi ? $nid . '_' . $thirdOctet : $nid;
                $name      = $multi ? $row['name'] . ' (' . $cidr . ')' : $row['name'];

                $subnet       = $this->makeSubnet($name, (int) $row['vID'], $cidr, $network);
                $map[$mapKey] = $subnet;

                if (!$dryRun) {
                    $this->em->persist($subnet);
                }

                // Reserved block: .1 – .5
                $reserved = new AddressBlock();
                $reserved->setSubnet($subnet);
                $reserved->setType(BlockType::Reserved);
                $reserved->setLabel('Infrastructure');
                $reserved->setStartIp($network . '.1');
                $reserved->setEndIp($network . '.5');
                if (!$dryRun) {
                    $this->em->persist($reserved);
                }

                // Fixed block: max(min_ip, .6) – max_ip
                $fixedStart = long2ip(max(ip2long($range['min_ip']), ip2long($network . '.6')));
                $fixedEnd   = $range['max_ip'];

                if (ip2long($fixedEnd) >= ip2long($fixedStart)) {
                    $fixed = new AddressBlock();
                    $fixed->setSubnet($subnet);
                    $fixed->setType(BlockType::Fixed);
                    $fixed->setStartIp($fixedStart);
                    $fixed->setEndIp($fixedEnd);
                    if (!$dryRun) {
                        $this->em->persist($fixed);
                    }
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('  %d subnets', count($map)));

        return $map;
    }

    /**
     * Groups address table by nID and /24 network prefix.
     *
     * @return array<string, list<array{network: string, min_ip: string, max_ip: string}>>
     */
    private function loadAddressRanges(\PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT
                nID,
                SUBSTRING_INDEX(ip, ".", 3)              AS network,
                INET_NTOA(MIN(INET_ATON(ip)))            AS min_ip,
                INET_NTOA(MAX(INET_ATON(ip)))            AS max_ip
             FROM address
             GROUP BY nID, SUBSTRING_INDEX(ip, ".", 3)
             ORDER BY nID, INET_ATON(INET_NTOA(MIN(INET_ATON(ip))))'
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['nID']][] = $row;
        }

        return $map;
    }

    private function makeSubnet(string $name, int $vlan, ?string $cidr, ?string $network = null): Subnet
    {
        $subnet = new Subnet();
        $subnet->setName($name);
        $subnet->setVlan($vlan ?: null);
        $subnet->setIpv4Cidr($cidr);

        if ($network !== null) {
            $octets     = explode('.', $network);
            $firstOctet = (int) $octets[0];
            $thirdOctet = (int) $octets[2];
            $seventhByte = in_array($firstOctet, [198, 199], true) ? 0x01 : 0x00;
            $fourthGroup = dechex(($seventhByte << 8) | $thirdOctet);
            $subnet->setIpv6Cidr('2001:18e8:408:' . $fourthGroup . '::/64');
        }

        return $subnet;
    }

    // -------------------------------------------------------------------------
    // Tags (device classes + departments)
    // -------------------------------------------------------------------------

    /**
     * Returns tags keyed by "c:{cID}" for classes and "d:{dID}" for departments,
     * allowing host rows to look up their tags by the legacy code columns.
     *
     * @return array<string, Tag>
     */
    private function importTags(\PDO $pdo, SymfonyStyle $io, bool $dryRun): array
    {
        $map = [];

        foreach ($pdo->query('SELECT cID, name FROM class ORDER BY cID')->fetchAll() as $row) {
            $tag = new Tag();
            $tag->setName(substr($row['name'], 0, 50));
            $map['c:' . $row['cID']] = $tag;
            if (!$dryRun) {
                $this->em->persist($tag);
            }
        }

        foreach ($pdo->query('SELECT dID, name FROM dept ORDER BY dID')->fetchAll() as $row) {
            $tag = new Tag();
            $tag->setName(substr($row['name'], 0, 50));
            $map['d:' . $row['dID']] = $tag;
            if (!$dryRun) {
                $this->em->persist($tag);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('  %d tags', count($map)));

        return $map;
    }

    // -------------------------------------------------------------------------
    // Hosts
    // -------------------------------------------------------------------------

    private function importHosts(
        \PDO $pdo,
        SymfonyStyle $io,
        bool $dryRun,
        array $buildings,
        array $domains,
        array $subnets,
        array $tags,
    ): void {
        // nID → zID for domain resolution
        $subnetZones = [];
        foreach ($pdo->query('SELECT nID, zID FROM subnet')->fetchAll() as $row) {
            $subnetZones[$row['nID']] = (int) $row['zID'];
        }

        // hID → list of alias names
        $aliasMap = [];
        foreach ($pdo->query('SELECT hID, name FROM alias ORDER BY aID')->fetchAll() as $row) {
            $aliasMap[(int) $row['hID']][] = $row['name'];
        }

        // Track used IPs to skip duplicates
        $usedIps = [];
        $count   = 0;
        $skipped = 0;

        $stmt = $pdo->query(
            'SELECT hID, name, ip, hw, bID, room, nID, cID, dID, ipv6 FROM host ORDER BY hID'
        );

        while ($row = $stmt->fetch()) {
            $hid    = (int) $row['hID'];
            $nid    = $row['nID'];
            $zid    = $subnetZones[$nid] ?? 1;
            $domain = $domains[$zid] ?? null;
            $subnet = $this->resolveSubnet($nid, $row['ip'], $subnets);

            $host = new Host();
            $host->setName($row['name']);
            $host->setRoom($row['room'] ?: null);

            if ($row['bID'] && isset($buildings[$row['bID']])) {
                $host->setBuilding($buildings[$row['bID']]);
            }

            if ($row['cID'] && isset($tags['c:' . $row['cID']])) {
                $host->addTag($tags['c:' . $row['cID']]);
            }

            if ($row['dID'] && isset($tags['d:' . $row['dID']])) {
                $host->addTag($tags['d:' . $row['dID']]);
            }

            $iface = new NetworkInterface();
            $iface->setMacAddress($row['hw']);
            $iface->setSubnet($subnet);

            // IPv4 address — skip if duplicate
            $ip = trim($row['ip'] ?? '');
            if ($ip !== '' && $subnet !== null) {
                if (!isset($usedIps[$ip])) {
                    $usedIps[$ip] = true;
                    $ipEntity     = new IpAddress();
                    $ipEntity->setAddress($ip);
                    $ipEntity->setSubnet($subnet);
                    $iface->setIpAddress($ipEntity);
                    if (!$dryRun) {
                        $this->em->persist($ipEntity);
                    }
                } else {
                    $io->writeln(sprintf('  <comment>Skipping duplicate IP %s on host %s</comment>', $ip, $row['name']));
                    $skipped++;
                }
            }

            // IPv6 address derived from IPv4 last octet
            if ($row['ipv6'] && $ip !== '' && $subnet?->getIpv6Cidr()) {
                $ipv6Str = $this->deriveIpv6FromIpv4($ip, $subnet);
                if ($ipv6Str !== null && !isset($usedIps['v6:' . $ipv6Str])) {
                    $usedIps['v6:' . $ipv6Str] = true;
                    $ipv6Entity = new Ipv6Address();
                    $ipv6Entity->setAddress($ipv6Str);
                    $ipv6Entity->setSubnet($subnet);
                    $iface->setIpv6Address($ipv6Entity);
                    if (!$dryRun) {
                        $this->em->persist($ipv6Entity);
                    }
                }
            }

            // Canonical name from host.name
            if ($this->isValidDnsLabel($row['name'])) {
                $canonical = new InterfaceName();
                $canonical->setName($row['name']);
                $canonical->setDomain($domain);
                $canonical->setIsCanonical(true);
                $iface->addName($canonical);
                if (!$dryRun) {
                    $this->em->persist($canonical);
                }
            }

            // Non-canonical names from alias table
            foreach ($aliasMap[$hid] ?? [] as $aliasName) {
                if (!$this->isValidDnsLabel($aliasName)) {
                    continue;
                }
                $alias = new InterfaceName();
                $alias->setName($aliasName);
                $alias->setDomain($domain);
                $alias->setIsCanonical(false);
                $iface->addName($alias);
                if (!$dryRun) {
                    $this->em->persist($alias);
                }
            }

            $host->addInterface($iface);

            if (!$dryRun) {
                $this->em->persist($iface);
                $this->em->persist($host);
            }

            $count++;

            if (!$dryRun && $count % 200 === 0) {
                $this->em->flush();
                $io->writeln(sprintf('  ...%d hosts', $count));
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('  %d hosts imported', $count));

        if ($skipped > 0) {
            $io->writeln(sprintf('  <comment>%d duplicate IPs skipped</comment>', $skipped));
        }
    }

    private function deriveIpv6FromIpv4(string $ipv4, Subnet $subnet): ?string
    {
        $octets = explode('.', $ipv4);
        if (count($octets) !== 4) {
            return null;
        }

        [$prefix] = explode('/', $subnet->getIpv6Cidr());
        $raw = inet_pton($prefix);
        if ($raw === false || strlen($raw) !== 16) {
            return null;
        }

        $raw[15] = chr((int) $octets[3]);
        $result  = inet_ntop($raw);

        return $result !== false ? $result : null;
    }

    private function resolveSubnet(string $nid, ?string $ip, array $subnets): ?Subnet
    {
        // Multi-/24 subnets are keyed as "{nid}_{thirdOctet}" (e.g. O_234)
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $thirdOctet = explode('.', $ip)[2];
            $multiKey   = $nid . '_' . $thirdOctet;
            if (isset($subnets[$multiKey])) {
                return $subnets[$multiKey];
            }
        }

        return $subnets[$nid] ?? null;
    }

    private function isValidDnsLabel(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?$/', $name);
    }
}
