<?php

namespace App\Command;

use App\Entity\AddressBlock;
use App\Entity\Building;
use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\Host;
use App\Entity\InterfaceName;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\SnipeItCategorySubnetMap;
use App\Entity\SnipeItServer;
use App\Entity\Subnet;
use App\Entity\Vrf;
use App\Entity\SubnetRecord;
use App\Entity\Tag;
use App\Enum\BlockType;
use App\Enum\RecordType;
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
        ini_set('memory_limit', '512M');

        $io     = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry run — no changes will be written');
        }

        $pdo  = $this->connectLegacy();
        $conn = $this->em->getConnection();

        if (!$dryRun) {
            $io->section('Truncating');
            $this->truncateImportedTables($conn, $io);
            $conn->beginTransaction();
        }

        try {
            $io->section('Buildings');
            $buildings = $this->importBuildings($pdo, $io, $dryRun);

            $io->section('Domains');
            $domains = $this->importDomains($pdo, $io, $dryRun);

            $io->section('VRFs');
            $vrfs = $this->seedVrfs($io, $dryRun);

            $io->section('Subnets');
            $subnets = $this->importSubnets($pdo, $io, $dryRun, $domains, $vrfs);

            $io->section('DNS Views');
            $this->setupDnsViews($io, $dryRun, $domains, $subnets);

            $io->section('Tags');
            $tags = $this->importTags($pdo, $io, $dryRun);

            $io->section('Hosts');
            $this->importHosts($pdo, $io, $dryRun, $buildings, $domains, $subnets, $tags);

            $io->section('Seed Data');
            $this->seedLocalData($io, $dryRun, $vrfs);

            $io->section('Domain Records');
            $this->seedDomainRecords($io, $dryRun);

            $io->section('Subnet NS Records');
            $this->seedSubnetNsRecords($io, $dryRun);

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

    private function setupDnsViews(SymfonyStyle $io, bool $dryRun, array $domains, array $subnets): void
    {
        $internal = new DnsView();
        $internal->setName('internal');

        $external = new DnsView();
        $external->setName('external');

        if (!$dryRun) {
            $this->em->persist($internal);
            $this->em->persist($external);
            $this->em->flush();
        }

        foreach ($domains as $domain) {
            $domain->addView($internal);
            if ($domain->getName() !== 'printers.goshen.edu') {
                $domain->addView($external);
            }
        }

        foreach ($subnets as $subnet) {
            $cidr       = $subnet->getIpv4Cidr();
            $firstOctet = $cidr !== null ? (int) explode('.', $cidr)[0] : null;
            $subnet->addView($internal);
            if (in_array($firstOctet, [198, 199], true)) {
                $subnet->addView($external);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln('  Created views: internal, external');
    }

    private function truncateImportedTables(\Doctrine\DBAL\Connection $conn, SymfonyStyle $io): void
    {
        $tables = [
            'interface_name_dns_view',
            'interface_name',
            'ip_address',
            'ipv6_address',
            'network_interface',
            'host_tag',
            'host',
            'subnet_record_dns_view',
            'subnet_record',
            'domain_record_dns_view',
            'domain_record',
            'subnet_dns_view',
            'domain_dns_view',
            'subnet_tag',
            'address_block',
            'subnet',
            'tag',
            'domain',
            'dns_view',
            'building',
            'snipe_it_category_subnet_map',
            'snipe_it_server',
        ];

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $conn->executeStatement('TRUNCATE TABLE `' . $table . '`');
        }
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $io->writeln(sprintf('  Truncated %d tables', count($tables)));
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
            $building->setName($row['bID']);
            $building->setDescription($row['name']);
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
            $domain->setSoaNameserver('dns1.goshen.edu');
            $domain->setSoaEmail('hostmaster@goshen.edu');
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
     * Returns subnets keyed by legacy nID.
     *
     * @return array<string, Subnet>
     */
    private function importSubnets(\PDO $pdo, SymfonyStyle $io, bool $dryRun, array $domains, array $vrfs): array
    {
        $addressRanges = $this->loadAddressRanges($pdo);

        $datacenterCidrs = [
            '199.8.232.0/24', '199.8.233.0/24',
            '192.168.59.0/24', '192.168.60.0/24', '192.168.61.0/24',
        ];

        $rows = $pdo->query('SELECT nID, vID, zID, name FROM subnet ORDER BY nID')->fetchAll();
        $map  = [];

        foreach ($rows as $row) {
            $nid   = $row['nID'];
            $range = $addressRanges[$nid] ?? null;

            if ($range === null) {
                $subnet = $this->makeSubnet($row['name'], (int) $row['vID'], null);
                $subnet->setVrf($vrfs['corporate']);
                $map[$nid] = $subnet;
                if (!$dryRun) {
                    $this->em->persist($subnet);
                }
                continue;
            }

            [$cidr, $network] = $this->computeCidr($range['min_ip'], $range['max_ip']);

            $subnet = $this->makeSubnet($row['name'], (int) $row['vID'], $cidr, $network);
            $subnet->setVrf(in_array($cidr, $datacenterCidrs, true) ? $vrfs['datacenter'] : $vrfs['corporate']);
            $map[$nid] = $subnet;

            if (!$dryRun) {
                $this->em->persist($subnet);
            }

            // Reserved block: .1 – .5 of network base
            $reserved = new AddressBlock();
            $reserved->setSubnet($subnet);
            $reserved->setType(BlockType::Reserved);
            $reserved->setLabel('Infrastructure');
            $reserved->setStartIp(long2ip(ip2long($network) + 1));
            $reserved->setEndIp(long2ip(ip2long($network) + 5));
            if (!$dryRun) {
                $this->em->persist($reserved);
            }

            // Fixed block: max(min_ip, base+6) – max_ip
            $fixedStart = long2ip(max(ip2long($range['min_ip']), ip2long($network) + 6));
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

            // Dynamic block: max_ip+1 – last usable address (broadcast−1)
            $prefixLen      = (int) explode('/', $cidr)[1];
            $lastUsableLong = ip2long($network) + (1 << (32 - $prefixLen)) - 2;
            $dynamicStart   = long2ip(ip2long($fixedEnd) + 1);

            if ($lastUsableLong >= ip2long($dynamicStart)) {
                $dynamic = new AddressBlock();
                $dynamic->setSubnet($subnet);
                $dynamic->setType(BlockType::Dynamic);
                $dynamic->setStartIp($dynamicStart);
                $dynamic->setEndIp(long2ip($lastUsableLong));
                if (!$dryRun) {
                    $this->em->persist($dynamic);
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
     * Returns the smallest power-of-2 CIDR covering $minIp–$maxIp.
     *
     * @return array{string, string} [$cidr, $networkIp]
     */
    private function computeCidr(string $minIp, string $maxIp): array
    {
        $minLong  = ip2long($minIp);
        $maxLong  = ip2long($maxIp);
        $diff     = $minLong ^ $maxLong;
        $hostBits = 0;
        while ((1 << $hostBits) <= $diff) {
            $hostBits++;
        }
        $prefixLen = min(32 - $hostBits, 24);  // never a prefix longer than /24
        $hostBits  = 32 - $prefixLen;
        $mask        = $hostBits === 0 ? -1 : ~((1 << $hostBits) - 1);
        $networkLong = $minLong & $mask;
        $network     = long2ip($networkLong);

        return [$network . '/' . $prefixLen, $network];
    }

    /**
     * Groups address table by nID, returning overall min/max IP per subnet.
     *
     * @return array<string, array{min_ip: string, max_ip: string}>
     */
    private function loadAddressRanges(\PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT
                nID,
                INET_NTOA(MIN(INET_ATON(ip))) AS min_ip,
                INET_NTOA(MAX(INET_ATON(ip))) AS max_ip
             FROM address
             GROUP BY nID
             ORDER BY nID'
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['nID']] = ['min_ip' => $row['min_ip'], 'max_ip' => $row['max_ip']];
        }

        return $map;
    }

    private function makeSubnet(string $name, int $vlan, ?string $cidr, ?string $networkIp = null): Subnet
    {
        $subnet = new Subnet();
        $subnet->setName($name);
        $subnet->setVlan($vlan ?: null);
        $subnet->setIpv4Cidr($cidr);
        $subnet->setSoaNameserver('dns1.goshen.edu');
        $subnet->setSoaEmail('hostmaster@goshen.edu');

        if ($networkIp !== null && $cidr !== null) {
            $prefixLen  = (int) explode('/', $cidr)[1];
            $octets     = explode('.', $networkIp);
            $firstOctet = (int) $octets[0];

            if ($firstOctet !== 10) {
                $thirdOctet  = (int) $octets[2];
                $seventhByte = in_array($firstOctet, [198, 199], true) ? 0x01 : 0x00;
                $fourthGroup = dechex(($seventhByte << 8) | $thirdOctet);
                $subnet->setIpv6Cidr('2001:18e8:408:' . $fourthGroup . '::/64');
            }
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
            $tag->setName(substr('class:' . $row['name'], 0, 50));
            $map['c:' . $row['cID']] = $tag;
            if (!$dryRun) {
                $this->em->persist($tag);
            }
        }

        foreach ($pdo->query('SELECT dID, name FROM dept ORDER BY dID')->fetchAll() as $row) {
            $tag = new Tag();
            $tag->setName(substr('dept:' . $row['name'], 0, 50));
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
            $subnet = $subnets[$nid] ?? null;

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

            $hasIp = $iface->getIpAddress() !== null || $iface->getIpv6Address() !== null;
            $sharedViews = $this->intersectViews($domain, $subnet);

            if ($hasIp) {
                // Canonical name from host.name
                if ($this->isValidDnsLabel($row['name'])) {
                    $canonical = new InterfaceName();
                    $canonical->setName($row['name']);
                    $canonical->setDomain($domain);
                    $canonical->setIsCanonical(true);
                    foreach ($sharedViews as $view) {
                        $canonical->addView($view);
                    }
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
                    foreach ($sharedViews as $view) {
                        $alias->addView($view);
                    }
                    $iface->addName($alias);
                    if (!$dryRun) {
                        $this->em->persist($alias);
                    }
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

    /**
     * Returns the views shared by both the domain and the subnet (intersection).
     * Uses spl_object_id so it works in dry-run (where IDs are null) as well.
     *
     * @return DnsView[]
     */
    private function intersectViews(?Domain $domain, ?Subnet $subnet): array
    {
        if ($domain === null) {
            return [];
        }

        $domainViewIds = [];
        foreach ($domain->getViews() as $v) {
            $domainViewIds[spl_object_id($v)] = $v;
        }

        if (empty($domainViewIds)) {
            return [];
        }

        if ($subnet === null) {
            return array_values($domainViewIds);
        }

        $shared = [];
        foreach ($subnet->getViews() as $v) {
            if (isset($domainViewIds[spl_object_id($v)])) {
                $shared[] = $v;
            }
        }

        return $shared;
    }

    private function isValidDnsLabel(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?$/', $name);
    }

    // -------------------------------------------------------------------------
    // VRFs
    // -------------------------------------------------------------------------

    /** @return array<string, Vrf> keyed by name */
    private function seedVrfs(SymfonyStyle $io, bool $dryRun): array
    {
        $vrfNames = ['datacenter', 'corporate', 'student'];
        $vrfs = [];
        foreach ($vrfNames as $name) {
            $vrf = new Vrf();
            $vrf->setName($name);
            $vrfs[$name] = $vrf;
            if (!$dryRun) {
                $this->em->persist($vrf);
            }
        }
        if (!$dryRun) {
            $this->em->flush();
        }
        $io->writeln(sprintf('  %d VRFs', count($vrfs)));

        return $vrfs;
    }

    // -------------------------------------------------------------------------
    // Local seed data (subnets, Snipe-IT server, category maps)
    // -------------------------------------------------------------------------

    private function seedLocalData(SymfonyStyle $io, bool $dryRun, array $vrfs): void
    {
        // Additional subnets not present in the legacy database
        $subnetDefs = [
            ['name' => "10's",                    'ipv4' => '10.0.0.0/8',        'ipv6' => null,                    'vlan' => null, 'gateway' => null,            'container' => true],
            ['name' => 'vlan249 Building Special','ipv4' => '10.249.0.0/16',     'ipv6' => null,                    'vlan' => 249,  'gateway' => null,            'container' => true],
            ['name' => 'A/V Subnets',             'ipv4' => '10.251.0.0/16',     'ipv6' => null,                    'vlan' => 251,  'gateway' => null,            'container' => true],
            ['name' => 'KMC Subnets',             'ipv4' => '10.253.0.0/16',     'ipv6' => null,                    'vlan' => 253,  'gateway' => null,            'container' => true],
            ['name' => "192's",                   'ipv4' => '192.168.0.0/16',    'ipv6' => '2001:18e8:408::/56',    'vlan' => null, 'gateway' => null,            'container' => true],
            ['name' => 'vlan44',                  'ipv4' => null,                'ipv6' => null,                    'vlan' => 44,   'gateway' => null,            'container' => false],
            ['name' => 'Siemens EMS',             'ipv4' => '192.168.253.0/24',  'ipv6' => null,                    'vlan' => null, 'gateway' => null,            'container' => false],
            ['name' => 'EMP_INST',                'ipv4' => '192.168.112.0/22',  'ipv6' => '2001:18e8:408:70/64',   'vlan' => 112,  'gateway' => '192.168.112.1', 'container' => false],
            ['name' => 'EMP_JENZ',                'ipv4' => '192.168.121.0/24',  'ipv6' => '2001:18e8:408:79::/64', 'vlan' => 121,  'gateway' => '192.168.121.1', 'container' => false],
        ];

        $datacenterCidrs = [
            '199.8.232.0/24', '199.8.233.0/24',
            '192.168.59.0/24', '192.168.60.0/24', '192.168.61.0/24',
        ];

        $seedSubnets = [];
        foreach ($subnetDefs as $def) {
            $subnet = new Subnet();
            $subnet->setName($def['name']);
            $subnet->setIpv4Cidr($def['ipv4']);
            $subnet->setIpv6Cidr($def['ipv6']);
            $subnet->setVlan($def['vlan']);
            $subnet->setGateway($def['gateway']);
            $subnet->setIsContainer($def['container']);
            $vrf = in_array($def['ipv4'], $datacenterCidrs, true) ? $vrfs['datacenter'] : $vrfs['corporate'];
            $subnet->setVrf($vrf);
            $seedSubnets[$def['name']] = $subnet;
            if (!$dryRun) {
                $this->em->persist($subnet);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('  %d additional subnets', count($seedSubnets)));

        // Address blocks for subnets that need them
        foreach (['Siemens EMS', 'EMP_INST', 'EMP_JENZ'] as $subnetName) {
            $subnet = $seedSubnets[$subnetName];
            $cidr   = $subnet->getIpv4Cidr();
            [$networkIp, $prefixLen] = explode('/', $cidr);
            $base          = ip2long($networkIp);
            $lastUsableLong = $base + (1 << (32 - (int) $prefixLen)) - 2;

            $reserved = new AddressBlock();
            $reserved->setSubnet($subnet);
            $reserved->setType(BlockType::Reserved);
            $reserved->setLabel('Infrastructure');
            $reserved->setStartIp(long2ip($base + 1));
            $reserved->setEndIp(long2ip($base + 10));

            $fixed = new AddressBlock();
            $fixed->setSubnet($subnet);
            $fixed->setType(BlockType::Fixed);
            $fixed->setStartIp(long2ip($base + 11));
            $fixed->setEndIp(long2ip($base + 50));

            $dynamic = new AddressBlock();
            $dynamic->setSubnet($subnet);
            $dynamic->setType(BlockType::Dynamic);
            $dynamic->setStartIp(long2ip($base + 51));
            $dynamic->setEndIp(long2ip($lastUsableLong));

            if (!$dryRun) {
                $this->em->persist($reserved);
                $this->em->persist($fixed);
                $this->em->persist($dynamic);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        // Snipe-IT server — API key read from SNIPE_API_KEY env var
        $apiKey = $_ENV['SNIPE_API_KEY'] ?? getenv('SNIPE_API_KEY') ?? null;
        if (!$apiKey) {
            $io->warning('SNIPE_API_KEY is not set — Snipe-IT server will be created without an API key');
        }

        $server = new SnipeItServer();
        $server->setName('snipe.goshen.edu');
        $server->setApiUrl('https://snipe.goshen.edu');
        $server->setApiKey($apiKey ?: null);
        $server->setVerifyTls(false);
        $server->setMacCustomFields('MAC Address, MAC Address 2, MAC Address 3, MAC Address 4, MAC Address 5, Wireless MAC');

        if (!$dryRun) {
            $this->em->persist($server);
            $this->em->flush();
        }

        $io->writeln('  Snipe-IT server: snipe.goshen.edu');

        // Category → subnet mappings. Subnets referenced here must exist either in the
        // legacy import or in $seedSubnets above.
        $subnetByName = function (string $name) use ($seedSubnets): ?Subnet {
            return $seedSubnets[$name]
                ?? $this->em->getRepository(Subnet::class)->findOneBy(['name' => $name]);
        };

        $categoryMaps = [
            [39, '25',                            null],
            [25, 'Access Point',                  'vlan44'],
            [14, 'Analog Telephone Adapter',      'vlan44'],
            [52, 'Audio',                         null],
            [ 6, 'AudioVisual',                   null],
            [ 5, 'Desktop',                       null],
            [45, 'Digital Signage',               null],
            [38, 'Docking Monitor',               null],
            [32, 'Elemental Essence',             null],
            [33, 'Energy Management',             'Siemens EMS'],
            [35, 'Ethernet Adapter',              null],
            [ 8, 'Ethernet Adapters',             null],
            [20, 'Film Scanner',                  null],
            [29, 'Headset',                       null],
            [48, 'Keyboard',                      null],
            [42, 'KMC',                           'KMC Subnets'],
            [ 7, 'Laptop',                        null],
            [30, 'Laptop Dock',                   null],
            [21, 'Mail Scale',                    null],
            [15, 'MFP',                           'printers'],
            [12, 'Monitor',                       null],
            [46, 'Network Attached Storage (NAS)',null],
            [49, 'Network Tester',                null],
            [ 4, 'Networking',                    null],
            [50, 'Nursing',                       'vlan249 Building Special'],
            [43, 'Other',                         null],
            [13, 'Phone',                         'vlan44'],
            [18, 'Power Supply',                  null],
            [16, 'Printer',                       'printers'],
            [34, 'Radio Equipment',               null],
            [23, 'Scanner',                       null],
            [11, 'Security',                      'Access Control'],
            [27, 'Security Camera',               'Access Control'],
            [ 2, 'Servers',                       null],
            [19, 'Switch Module',                 null],
            [44, 'Tablet',                        null],
            [22, 'TV',                            null],
            [26, 'UPS',                           'vlan44'],
            [47, 'Utility',                       null],
            [41, 'Video',                         null],
            [17, 'Webcam Accessory',              null],
            [ 9, 'Zero Client',                   null],
        ];

        foreach ($categoryMaps as [$catId, $catName, $subnetName]) {
            $map = new SnipeItCategorySubnetMap();
            $map->setServer($server);
            $map->setSnipeCategoryId($catId);
            $map->setSnipeCategoryName($catName);
            if ($subnetName !== null) {
                $map->setSubnet($subnetByName($subnetName));
            }
            if (!$dryRun) {
                $this->em->persist($map);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('  %d category maps', count($categoryMaps)));
    }

    // -------------------------------------------------------------------------
    // Static domain records (goshen.edu zone file imports)
    // -------------------------------------------------------------------------

    private function seedDomainRecords(SymfonyStyle $io, bool $dryRun): void
    {
        $domainByName = [];
        $viewByName   = [];

        foreach ($this->em->getRepository(DnsView::class)->findAll() as $v) {
            $viewByName[$v->getName()] = $v;
        }

        // [domain, hostname, type, value, ttl, comment, [views]]
        $defs = [
            ["goshen.edu", "@", "A", "104.20.35.88", 3600, null, ["external", "internal"]],
            ["goshen.edu", "*.ezproxy", "A", "66.11.2.233", 3600, "ezproxy via PALNI", ["external", "internal"]],
            ["goshen.edu", "*.ezproxy-old", "A", "199.8.232.105", 600, null, ["external", "internal"]],
            ["goshen.edu", "*.openid", "A", "199.8.232.83", 600, null, ["external", "internal"]],
            ["goshen.edu", "232.ns1", "A", "208.94.148.63", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "232.ns2", "A", "208.80.124.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "232.ns3", "A", "208.80.126.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "233.ns1", "A", "208.94.148.63", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "233.ns2", "A", "208.80.124.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "233.ns3", "A", "208.80.126.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "234.ns1", "A", "208.94.148.63", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "234.ns2", "A", "208.80.124.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "234.ns3", "A", "208.80.126.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "235.ns1", "A", "208.94.148.63", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "235.ns2", "A", "208.80.124.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "235.ns3", "A", "208.80.126.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "236.ns1", "A", "208.94.148.63", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "236.ns2", "A", "208.80.124.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "236.ns3", "A", "208.80.126.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "237.ns1", "A", "208.94.148.63", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "237.ns2", "A", "208.80.124.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "237.ns3", "A", "208.80.126.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "238.ns1", "A", "208.94.148.63", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "238.ns2", "A", "208.80.124.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "238.ns3", "A", "208.80.126.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "239.ns1", "A", "208.94.148.63", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "239.ns2", "A", "208.80.124.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "239.ns3", "A", "208.80.126.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "archive", "A", "64.225.28.143", 3600, "Archive and Intranet records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "arctic.ilo", "A", "192.168.61.50", 600, null, ["internal"]],
            ["goshen.edu", "arkansas.ilo", "A", "192.168.61.25", 600, "iLO records", ["internal"]],
            ["goshen.edu", "aruba-gw01", "A", "192.168.102.21", 600, null, ["internal"]],
            ["goshen.edu", "aruba-gw02", "A", "192.168.102.22", 600, null, ["internal"]],
            ["goshen.edu", "aruba-gw03", "A", "192.168.102.23", 600, null, ["internal"]],
            ["goshen.edu", "aruba-gw04", "A", "192.168.102.24", 600, null, ["internal"]],
            ["goshen.edu", "aruba-master", "A", "192.168.102.14", 600, "Aruba controller discovery", ["internal"]],
            ["goshen.edu", "aruba-mc03", "A", "192.168.102.13", 600, "Aruba controller discovery", ["internal"]],
            ["goshen.edu", "aruba-mc04", "A", "192.168.102.14", 600, "Aruba controller discovery", ["internal"]],
            ["goshen.edu", "catalog-current", "A", "64.225.28.143", 3600, "Catalog records pointing to Asher", ["external", "internal"]],
            ["goshen.edu", "catalog-draft", "A", "64.225.28.143", 3600, "Catalog records pointing to Asher", ["external", "internal"]],
            ["goshen.edu", "core-cx", "A", "10.254.2.1", 600, "Switches", ["internal"]],
            ["goshen.edu", "core-cx-1g", "A", "10.254.2.2", 600, "Switches", ["internal"]],
            ["goshen.edu", "core-cy", "A", "10.254.1.1", 600, "Switches", ["internal"]],
            ["goshen.edu", "core-cy-1g", "A", "10.254.1.2", 600, "Switches", ["internal"]],
            ["goshen.edu", "core-sc", "A", "10.254.131.1", 600, null, ["internal"]],
            ["goshen.edu", "core-un", "A", "10.254.130.1", 600, null, ["internal"]],
            ["goshen.edu", "core-vsx-sc", "A", "10.254.131.1", 600, null, ["internal"]],
            ["goshen.edu", "core-vsx-un", "A", "10.254.130.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-ad0", "A", "10.254.2.13", 600, null, ["internal"]],
            ["goshen.edu", "cx-ad2", "A", "10.254.2.12", 600, null, ["internal"]],
            ["goshen.edu", "cx-cc-sanc", "A", "10.254.82.4", 600, null, ["internal"]],
            ["goshen.edu", "cx-cc0", "A", "10.254.82.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-cc1", "A", "10.254.82.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-cn", "A", "10.254.17.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-eh", "A", "10.254.17.4", 600, null, ["internal"]],
            ["goshen.edu", "cx-gl", "A", "10.254.80.3", 600, null, ["internal"]],
            ["goshen.edu", "cx-gl2", "A", "10.254.80.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-ho", "A", "10.254.5.12", 600, null, ["internal"]],
            ["goshen.edu", "cx-kn", "A", "10.254.5.11", 600, null, ["internal"]],
            ["goshen.edu", "cx-kr", "A", "10.254.17.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-ku0", "A", "10.254.7.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-ku1", "A", "10.254.7.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-mc170", "A", "10.254.4.4", 600, null, ["internal"]],
            ["goshen.edu", "cx-mc208", "A", "10.254.4.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-mc251", "A", "10.254.4.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-mc301a", "A", "10.254.4.3", 600, null, ["internal"]],
            ["goshen.edu", "cx-mcdante-pri-da", "A", "10.254.40.11", 600, null, ["internal"]],
            ["goshen.edu", "cx-mcdante-pri-foh", "A", "10.254.40.12", 600, null, ["internal"]],
            ["goshen.edu", "cx-mcdante-sec-da", "A", "10.254.40.21", 600, null, ["internal"]],
            ["goshen.edu", "cx-mcdante-sec-foh", "A", "10.254.40.22", 600, null, ["internal"]],
            ["goshen.edu", "cx-mi-a", "A", "10.254.16.10", 600, null, ["internal"]],
            ["goshen.edu", "cx-mi-b", "A", "10.254.16.11", 600, null, ["internal"]],
            ["goshen.edu", "cx-nc01", "A", "10.254.81.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-nc12", "A", "10.254.81.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-nc12-h", "A", "10.254.81.3", 600, null, ["internal"]],
            ["goshen.edu", "cx-nc23-h", "A", "10.254.83.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-pp", "A", "10.254.10.3", 600, null, ["internal"]],
            ["goshen.edu", "cx-rf108", "A", "10.254.21.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-rfg", "A", "10.254.21.4", 600, null, ["internal"]],
            ["goshen.edu", "cx-rfh", "A", "10.254.21.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-rft", "A", "10.254.21.3", 600, null, ["internal"]],
            ["goshen.edu", "cx-rtr-gshn", "A", "10.254.244.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-san", "A", "10.254.19.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-sas", "A", "10.254.19.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-sc", "A", "10.254.10.4", 600, null, ["internal"]],
            ["goshen.edu", "cx-sc001", "A", "10.254.10.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-tennis", "A", "10.254.21.5", 600, null, ["internal"]],
            ["goshen.edu", "cx-uc0", "A", "10.254.20.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-un003", "A", "10.254.48.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-un004", "A", "10.254.48.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-un021", "A", "10.254.48.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-un100f", "A", "10.254.48.4", 600, null, ["internal"]],
            ["goshen.edu", "cx-un112", "A", "10.254.48.5", 600, null, ["internal"]],
            ["goshen.edu", "cx-va", "A", "10.254.8.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-wl", "A", "10.254.5.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-wl-av", "A", "10.254.5.5", 600, null, ["internal"]],
            ["goshen.edu", "cx-wl1", "A", "10.254.5.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-wl2", "A", "10.254.5.3", 600, null, ["internal"]],
            ["goshen.edu", "cx-wl3", "A", "10.254.5.4", 600, null, ["internal"]],
            ["goshen.edu", "cx-wy1n", "A", "10.254.3.4", 600, null, ["internal"]],
            ["goshen.edu", "cx-wy1s", "A", "10.254.3.3", 600, null, ["internal"]],
            ["goshen.edu", "cx-wy2", "A", "10.254.3.2", 600, null, ["internal"]],
            ["goshen.edu", "cx-yo", "A", "10.254.18.1", 600, null, ["internal"]],
            ["goshen.edu", "cx-yon", "A", "10.254.17.3", 600, null, ["internal"]],
            ["goshen.edu", "delaware.ilo", "A", "192.168.61.31", 600, "iLO records", ["internal"]],
            ["goshen.edu", "dns1", "A", "208.94.148.63", 28800, "db.goshen.edu.external.footer content", ["external"]],
            ["goshen.edu", "dns2", "A", "208.80.124.63", 28800, "db.goshen.edu.external.footer content", ["external"]],
            ["goshen.edu", "dns3", "A", "208.80.126.63", 28800, "db.goshen.edu.external.footer content", ["external"]],
            ["goshen.edu", "dtn-un", "A", "198.51.244.18", 600, "DTN records", ["internal"]],
            ["goshen.edu", "dtn-un", "A", "198.51.244.18", 3600, "DTN records", ["external", "internal"]],
            ["goshen.edu", "dtn.ilo", "A", "192.168.61.33", 600, null, ["internal"]],
            ["goshen.edu", "euphrates.ipmi.valpo", "A", "192.168.59.8", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "euphrates.valpo", "A", "192.168.59.6", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "ezproxy", "A", "66.11.2.233", 3600, "ezproxy via PALNI", ["external", "internal"]],
            ["goshen.edu", "fw", "A", "10.255.0.1", 600, "Routing Loopback's", ["internal"]],
            ["goshen.edu", "fw-proxy", "A", "198.51.244.2", 3600, "fw.goshen.edu public loopback for proxy captive portal", ["external", "internal"]],
            ["goshen.edu", "illinois.ilo", "A", "192.168.61.23", 600, "iLO records", ["internal"]],
            ["goshen.edu", "iowa.ilo", "A", "192.168.61.28", 600, "iLO records", ["internal"]],
            ["goshen.edu", "ip6.ns1", "A", "208.94.148.63", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "ip6.ns2", "A", "208.80.124.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "ip6.ns3", "A", "208.80.126.63", 600, null, ["external", "internal"]],
            ["goshen.edu", "jfaclouddb", "A", "100.122.10.11", 600, "Jenzabar aaS DB record", ["internal"]],
            ["goshen.edu", "kansas.ilo", "A", "192.168.61.27", 600, "iLO records", ["internal"]],
            ["goshen.edu", "kentucky.ilo", "A", "192.168.61.29", 600, "iLO records", ["internal"]],
            ["goshen.edu", "kmc", "A", "198.51.243.52", 28800, "Fortigate proxy addresses", ["external"]],
            ["goshen.edu", "loa.ilo", "A", "192.168.61.21", 600, "iLO records", ["internal"]],
            ["goshen.edu", "merrylea", "A", "64.225.28.143", 3600, "Merry Lea records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "minnesota.ilo", "A", "192.168.61.32", 600, "iLO records", ["internal"]],
            ["goshen.edu", "missouri.ilo", "A", "192.168.61.24", 600, "iLO records", ["internal"]],
            ["goshen.edu", "mx-sc", "A", "10.255.9.2", 600, "Border router mgmt addresses", ["internal"]],
            ["goshen.edu", "mx-un", "A", "10.255.8.2", 600, "Border router mgmt addresses", ["internal"]],
            ["goshen.edu", "nile", "A", "192.168.59.5", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "nile.ipmi.valpo", "A", "192.168.59.7", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "nile.valpo", "A", "192.168.59.5", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "ohio.ilo", "A", "192.168.61.26", 600, "iLO records", ["internal"]],
            ["goshen.edu", "oncampus", "A", "64.225.28.143", 3600, "Archive and Intranet records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "paul.ilo", "A", "192.168.61.33", 600, null, ["internal"]],
            ["goshen.edu", "record", "A", "64.90.44.202", 3600, "record.goshen.edu", ["external", "internal"]],
            ["goshen.edu", "s-baseball", "A", "10.254.19.4", 600, null, ["internal"]],
            ["goshen.edu", "s-co", "A", "10.254.6.1", 600, null, ["internal"]],
            ["goshen.edu", "s-gl", "A", "10.254.80.1", 600, null, ["internal"]],
            ["goshen.edu", "s-mi", "A", "10.254.16.1", 600, null, ["internal"]],
            ["goshen.edu", "s-nc23", "A", "10.254.83.1", 600, null, ["internal"]],
            ["goshen.edu", "s-sanc-booth", "A", "10.254.82.3", 600, null, ["internal"]],
            ["goshen.edu", "s-sc", "A", "10.254.10.1", 600, null, ["internal"]],
            ["goshen.edu", "s-soccer", "A", "10.254.19.3", 600, null, ["internal"]],
            ["goshen.edu", "s-softball", "A", "10.254.19.5", 600, null, ["internal"]],
            ["goshen.edu", "s-un112", "A", "10.254.48.3", 600, null, ["internal"]],
            ["goshen.edu", "s-valpo-01.valpo", "A", "192.168.59.2", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "s-wgcs-01", "A", "10.250.48.11", 600, null, ["internal"]],
            ["goshen.edu", "s-wgcs-aoip", "A", "10.250.83.11", 600, null, ["internal"]],
            ["goshen.edu", "s-wgcs-tx", "A", "10.254.243.1", 600, null, ["internal"]],
            ["goshen.edu", "sacramento.ilo", "A", "192.168.61.22", 600, "iLO records", ["internal"]],
            ["goshen.edu", "tennessee.ilo", "A", "192.168.61.30", 600, "iLO records", ["internal"]],
            ["goshen.edu", "ups-a.ups", "A", "192.168.61.101", 600, null, ["internal"]],
            ["goshen.edu", "ups-b.ups", "A", "192.168.61.102", 600, null, ["internal"]],
            ["goshen.edu", "valt", "A", "198.51.243.51", 28800, "Fortigate proxy addresses", ["external"]],
            ["goshen.edu", "wowza.ilo", "A", "192.168.61.25", 600, "iLO records", ["internal"]],
            ["goshen.edu", "www.merrylea", "A", "64.225.28.143", 3600, "Merry Lea records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "zabbix", "A", "198.51.243.48", 28800, "Fortigate proxy addresses", ["external"]],
            ["goshen.edu", "232.ns1", "AAAA", "2600:1800:5::1:1f", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "232.ns2", "AAAA", "2600:1801:6::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "232.ns3", "AAAA", "2600:1802:7::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "233.ns1", "AAAA", "2600:1800:5::1:1f", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "233.ns2", "AAAA", "2600:1801:6::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "233.ns3", "AAAA", "2600:1802:7::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "234.ns1", "AAAA", "2600:1800:5::1:1f", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "234.ns2", "AAAA", "2600:1801:6::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "234.ns3", "AAAA", "2600:1802:7::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "235.ns1", "AAAA", "2600:1800:5::1:1f", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "235.ns2", "AAAA", "2600:1801:6::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "235.ns3", "AAAA", "2600:1802:7::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "236.ns1", "AAAA", "2600:1800:5::1:1f", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "236.ns2", "AAAA", "2600:1801:6::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "236.ns3", "AAAA", "2600:1802:7::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "237.ns1", "AAAA", "2600:1800:5::1:1f", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "237.ns2", "AAAA", "2600:1801:6::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "237.ns3", "AAAA", "2600:1802:7::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "238.ns1", "AAAA", "2600:1800:5::1:1f", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "238.ns2", "AAAA", "2600:1801:6::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "238.ns3", "AAAA", "2600:1802:7::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "239.ns1", "AAAA", "2600:1800:5::1:1f", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "239.ns2", "AAAA", "2600:1801:6::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "239.ns3", "AAAA", "2600:1802:7::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "core-cx", "AAAA", "2001:18e8:408:8002::1", 600, "Switches", ["internal"]],
            ["goshen.edu", "core-cx-1g", "AAAA", "2001:18e8:408:8002::2", 600, "Switches", ["internal"]],
            ["goshen.edu", "core-cy", "AAAA", "2001:18e8:408:8001::1", 600, "Switches", ["internal"]],
            ["goshen.edu", "core-cy-1g", "AAAA", "2001:18e8:408:8001::2", 600, "Switches", ["internal"]],
            ["goshen.edu", "core-sc", "AAAA", "2001:18e8:408:8083::1", 600, null, ["internal"]],
            ["goshen.edu", "core-un", "AAAA", "2001:18e8:408:8082::1", 600, null, ["internal"]],
            ["goshen.edu", "core-vsx-sc", "AAAA", "2001:18e8:408:8083::1", 600, null, ["internal"]],
            ["goshen.edu", "core-vsx-un", "AAAA", "2001:18e8:408:8082::1", 600, null, ["internal"]],
            ["goshen.edu", "cx-mi-a", "AAAA", "2001:18e8:408:8010::a", 600, null, ["internal"]],
            ["goshen.edu", "cx-mi-b", "AAAA", "2001:18e8:408:8010::b", 600, null, ["internal"]],
            ["goshen.edu", "cx-un003", "AAAA", "2001:18e8:408:8030::2", 600, null, ["internal"]],
            ["goshen.edu", "cx-un004", "AAAA", "2001:18e8:408:8030::2", 600, null, ["internal"]],
            ["goshen.edu", "cx-un021", "AAAA", "2001:18e8:408:8030::1", 600, null, ["internal"]],
            ["goshen.edu", "cx-un112", "AAAA", "2001:18e8:408:8030::5", 600, null, ["internal"]],
            ["goshen.edu", "cx-va", "AAAA", "2001:18e8:408:8008::1", 600, null, ["internal"]],
            ["goshen.edu", "dns1", "AAAA", "2600:1800:5::1:1f", 28800, null, ["external"]],
            ["goshen.edu", "dns2", "AAAA", "2600:1801:6::1:1f", 28800, null, ["external"]],
            ["goshen.edu", "dns3", "AAAA", "2600:1802:7::1:1f", 28800, null, ["external"]],
            ["goshen.edu", "dtn-un", "AAAA", "2001:18e8:408:110::2", 3600, "DTN records", ["internal"]],
            ["goshen.edu", "dtn-un", "AAAA", "2001:18e8:408:110::2", 600, "DTN records", ["external", "internal"]],
            ["goshen.edu", "euphrates.valpo", "AAAA", "2001:18e8:408:3b::6", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "fw", "AAAA", "2001:18e8:408:4000::1", 600, "Routing Loopback's", ["internal"]],
            ["goshen.edu", "ip6.ns1", "AAAA", "2600:1800:5::1:1f", 600, "reverse dns nameserver host records", ["external", "internal"]],
            ["goshen.edu", "ip6.ns2", "AAAA", "2600:1801:6::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "ip6.ns3", "AAAA", "2600:1802:7::1:1f", 600, null, ["external", "internal"]],
            ["goshen.edu", "ipv6", "AAAA", "2001:18e8:408:1e8::53", 3600, null, ["external", "internal"]],
            ["goshen.edu", "mx-sc", "AAAA", "2001:18e8:408:4009::2", 600, "Border router mgmt addresses", ["internal"]],
            ["goshen.edu", "mx-un", "AAAA", "2001:18e8:408:4008::2", 600, "Border router mgmt addresses", ["internal"]],
            ["goshen.edu", "nile", "AAAA", "2001:18e8:408:3b::5", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "nile.valpo", "AAAA", "2001:18e8:408:3b::5", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "s-gl", "AAAA", "2001:18e8:408:8050::1", 600, null, ["internal"]],
            ["goshen.edu", "s-mi", "AAAA", "2001:18e8:408:8010::1", 600, null, ["internal"]],
            ["goshen.edu", "s-nc23", "AAAA", "2001:18e8:408:8053::1", 600, null, ["internal"]],
            ["goshen.edu", "s-sc", "AAAA", "2001:18e8:408:800a::1", 600, null, ["internal"]],
            ["goshen.edu", "s-un112", "AAAA", "2001:18e8:408:8030::3", 600, null, ["internal"]],
            ["goshen.edu", "s-valpo-01.valpo", "AAAA", "2001:18e8:408:3b::2", 600, "Valpo backup site", ["internal"]],
            ["goshen.edu", "_acme-challenge", "CNAME", "goshen.edu.8a602f2b29d92dc2.dcv.cloudflare.com", 3600, "CloudFlare domain verification", ["external", "internal"]],
            ["goshen.edu", "_acme-challenge.www", "CNAME", "www.goshen.edu.8a602f2b29d92dc2.dcv.cloudflare.com", 3600, "CloudFlare domain verification", ["external", "internal"]],
            ["goshen.edu", "2e42gsrqr2gwup46p7taewjuaymiyy3k._domainkey", "CNAME", "2e42gsrqr2gwup46p7taewjuaymiyy3k.dkim.amazonses.com", 3600, "DKIM for amazones for AudienceView", ["external", "internal"]],
            ["goshen.edu", "5tspvfzq3myaft7eu6hftks3ruljj7v7._domainkey", "CNAME", "5tspvfzq3myaft7eu6hftks3ruljj7v7.dkim.amazonses.com", 3600, "DKIM for amazones for AudienceView", ["external", "internal"]],
            ["goshen.edu", "catalog.library", "CNAME", "palni.kohacatalog.com", 3600, "Library site", ["external", "internal"]],
            ["goshen.edu", "connect", "CNAME", "connect.goshen.edu.00D1U000000p1CRUAY.live.siteforce.com", 3600, "SF online community records", ["external", "internal"]],
            ["goshen.edu", "cxkmkj6un2ihzfjdwtosr32shjxa6rxv._domainkey", "CNAME", "cxkmkj6un2ihzfjdwtosr32shjxa6rxv.dkim.amazonses.com", 3600, "RNL records for Financial Aid", ["external", "internal"]],
            ["goshen.edu", "dtd._domainkey", "CNAME", "dtd.domainkey.u44828280.wl031.sendgrid.net", 3600, "Double the Donation records for Development", ["external", "internal"]],
            ["goshen.edu", "dtd2._domainkey", "CNAME", "dtd2.domainkey.u44828280.wl031.sendgrid.net", 3600, "Double the Donation records for Development", ["external", "internal"]],
            ["goshen.edu", "dtdsendgrid", "CNAME", "u44828280.wl031.sendgrid.net", 3600, "Double the Donation records for Development", ["external", "internal"]],
            ["goshen.edu", "e", "CNAME", "goshencollege.mktoweb.com", 3600, "Marketo records", ["external", "internal"]],
            ["goshen.edu", "em2071", "CNAME", "u1341133.wl134.sendgrid.net", 3600, "GiveCampus records", ["external", "internal"]],
            ["goshen.edu", "em4796", "CNAME", "u771094.wl095.sendgrid.net", 3600, "Sendgrid records for Jenzabar", ["external", "internal"]],
            ["goshen.edu", "em6848", "CNAME", "u18596489.wl240.sendgrid.net", 3600, "Merry Lea records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "email.give", "CNAME", "mailgun.org", 3600, "Blackbaud records for Development", ["external", "internal"]],
            ["goshen.edu", "es", "CNAME", "sis.tdn.gtranslate.net", 3600, "Goshen.edu records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "fr", "CNAME", "sis.tdn.gtranslate.net", 3600, "Goshen.edu records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "gc._domainkey", "CNAME", "gc.domainkey.u1341133.wl134.sendgrid.net", 3600, "GiveCampus records", ["external", "internal"]],
            ["goshen.edu", "gc2._domainkey", "CNAME", "gc2.domainkey.u1341133.wl134.sendgrid.net", 3600, "GiveCampus records", ["external", "internal"]],
            ["goshen.edu", "gconline", "CNAME", "gconline.goshen.edu.cdn.cloudflare.net", 28800, "GCOnline CF proxy config", ["external"]],
            ["goshen.edu", "gconline", "CNAME", "gconline2", 600, "GCOnline CNAME record (external points to CF)", ["internal"]],
            ["goshen.edu", "giving", "CNAME", "branded.givecampus.com", 3600, "GiveCampus records", ["external", "internal"]],
            ["goshen.edu", "go", "CNAME", "mkto-sj090265.com", 3600, "Marketo records", ["external", "internal"]],
            ["goshen.edu", "go2", "CNAME", "mkto-ab350177.com", 3600, "Marketo records", ["external", "internal"]],
            ["goshen.edu", "goshen-sf-sandbox._domainkey", "CNAME", "goshen-sf-sandbox.3j5nsv.custdkim.salesforce.com", 3600, "SF sandbox domain keys", ["external", "internal"]],
            ["goshen.edu", "goshen2-sf-sandbox._domainkey", "CNAME", "goshen2-sf-sandbox.siqd4k.custdkim.salesforce.com", 3600, "SF sandbox domain keys", ["external", "internal"]],
            ["goshen.edu", "goshensf._domainkey", "CNAME", "goshensf.2j3odb.custdkim.salesforce.com", 3600, "SF domain keys", ["external", "internal"]],
            ["goshen.edu", "goshensf2._domainkey", "CNAME", "goshensf2.rlbi5i.custdkim.salesforce.com", 3600, "SF domain keys", ["external", "internal"]],
            ["goshen.edu", "info", "CNAME", "goshencollege1.mktoweb.com", 3600, "Marketo records", ["external", "internal"]],
            ["goshen.edu", "jzb._domainkey", "CNAME", "jzb.domainkey.u771094.wl095.sendgrid.net", 3600, "Sendgrid records for Jenzabar", ["external", "internal"]],
            ["goshen.edu", "jzb2._domainkey", "CNAME", "jzb2.domainkey.u771094.wl095.sendgrid.net", 3600, "Sendgrid records for Jenzabar", ["external", "internal"]],
            ["goshen.edu", "k1._domainkey", "CNAME", "dkim.mcsv.net", 3600, "Mail Chimp domain key", ["external", "internal"]],
            ["goshen.edu", "libraryguides", "CNAME", "secure-us.libguides.com", 3600, "Library site", ["external", "internal"]],
            ["goshen.edu", "login", "CNAME", "goshen.customdomains.okta.com", 3600, "Okta records", ["external", "internal"]],
            ["goshen.edu", "mhl.library", "CNAME", "palni.kohacatalog.com", 3600, "Library site", ["external", "internal"]],
            ["goshen.edu", "mhlstaff.library", "CNAME", "palni.kohacatalog.com", 3600, "Library site", ["external", "internal"]],
            ["goshen.edu", "n5compqyacn4ohv6vcu7hn4ga7gwrefs._domainkey", "CNAME", "n5compqyacn4ohv6vcu7hn4ga7gwrefs.dkim.amazonses.com", 3600, "RNL records for Financial Aid", ["external", "internal"]],
            ["goshen.edu", "notifii", "CNAME", "u538675.wl176.sendgrid.net", 3600, "Notifi records", ["external", "internal"]],
            ["goshen.edu", "nqezx5tmxvzvikwvvfcawezcwpqxgmdb._domainkey", "CNAME", "nqezx5tmxvzvikwvvfcawezcwpqxgmdb.dkim.amazonses.com", 3600, "RNL records for Financial Aid", ["external", "internal"]],
            ["goshen.edu", "ntf._domainkey", "CNAME", "ntf.domainkey.u538675.wl176.sendgrid.net", 3600, "Notifi records", ["external", "internal"]],
            ["goshen.edu", "ntf2._domainkey", "CNAME", "ntf2.domainkey.u538675.wl176.sendgrid.net", 3600, "Notifi records", ["external", "internal"]],
            ["goshen.edu", "okt._domainkey", "CNAME", "okt.domainkey.u21992032.wl033.sendgrid.net", 3600, "Okta records", ["external", "internal"]],
            ["goshen.edu", "okt2._domainkey", "CNAME", "okt2.domainkey.u21992032.wl033.sendgrid.net", 3600, "Okta records", ["external", "internal"]],
            ["goshen.edu", "okta-mail", "CNAME", "u21992032.wl033.sendgrid.net", 3600, "Okta records", ["external", "internal"]],
            ["goshen.edu", "photo-dir", "CNAME", "gconline2", 600, "GCOnline CNAME record (external points to CF)", ["internal"]],
            ["goshen.edu", "photo-dir", "CNAME", "photo-dir.goshen.edu.cdn.cloudflare.net", 28800, "GCOnline CF proxy config", ["external"]],
            ["goshen.edu", "pt", "CNAME", "sis.tdn.gtranslate.net", 3600, "Goshen.edu records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "s1._domainkey", "CNAME", "s1.domainkey.u18596489.wl240.sendgrid.net", 3600, "Merry Lea records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "s2._domainkey", "CNAME", "s2.domainkey.u18596489.wl240.sendgrid.net", 3600, "Merry Lea records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "search.library", "CNAME", "webapps.goshen.edu", 3600, "Library site", ["external", "internal"]],
            ["goshen.edu", "staff.library", "CNAME", "palni.kohacatalog.com", 3600, "Library site", ["external", "internal"]],
            ["goshen.edu", "thanks", "CNAME", "pm.mtasv.net", 3600, "THANKVIEW records for Admissions", ["external", "internal"]],
            ["goshen.edu", "url1938", "CNAME", "sendgrid.net", 18596489, "Merry Lea records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "url1938", "CNAME", "sendgrid.net", 3600, "Merry Lea records to point to Asher", ["internal"]],
            ["goshen.edu", "url392", "CNAME", "sendgrid.net", 771094, "Sendgrid records for Jenzabar", ["external", "internal"]],
            ["goshen.edu", "url392", "CNAME", "sendgrid.net", 3600, "Sendgrid records for Jenzabar", ["internal"]],
            ["goshen.edu", "url6240", "CNAME", "sendgrid.net", 3600, "Archive and Intranet records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "utt2k6dm74w6tzee5imxgwutmzbtekby._domainkey", "CNAME", "utt2k6dm74w6tzee5imxgwutmzbtekby.dkim.amazonses.com", 3600, "DKIM for amazones for AudienceView", ["external", "internal"]],
            ["goshen.edu", "www", "CNAME", "goshen.edu", 3600, "Goshen.edu records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "zh-cn", "CNAME", "sis.tdn.gtranslate.net", 3600, "Goshen.edu records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "@", "MX", "10 alt4.aspmx.l.google.com", 3600, null, ["external", "internal"]],
            ["goshen.edu", "@", "MX", "1 aspmx.l.google.com", 3600, null, ["external", "internal"]],
            ["goshen.edu", "@", "MX", "10 alt3.aspmx.l.google.com", 3600, null, ["external", "internal"]],
            ["goshen.edu", "@", "MX", "5 alt1.aspmx.l.google.com", 3600, null, ["external", "internal"]],
            ["goshen.edu", "@", "MX", "5 alt2.aspmx.l.google.com", 3600, null, ["external", "internal"]],
            ["goshen.edu", "give", "MX", "10 mxa.mailgun.org", 3600, "Blackbaud records for Development", ["external", "internal"]],
            ["goshen.edu", "give", "MX", "10 mxb.mailgun.org", 3600, "Blackbaud records for Development", ["external", "internal"]],
            ["goshen.edu", "@", "NS", "dns3.goshen.edu", 3600, null, ["external", "internal"]],
            ["goshen.edu", "@", "NS", "dns2.goshen.edu", 3600, null, ["external", "internal"]],
            ["goshen.edu", "@", "NS", "dns1.goshen.edu", 3600, null, ["external", "internal"]],
            ["goshen.edu", "dyn", "NS", "dns-legacy.goshen.edu", 600, null, ["internal"]],
            ["goshen.edu", "gc", "NS", "dc4.goshen.edu", 600, null, ["internal"]],
            ["goshen.edu", "gc", "NS", "dc3.goshen.edu", 600, null, ["internal"]],
            ["goshen.edu", "gc", "NS", "dc1.goshen.edu", 600, null, ["internal"]],
            ["goshen.edu", "pc-printer-discovery", "NS", "print.goshen.edu", 600, "Print mobility records", ["internal"]],
            ["goshen.edu", "b._dns-sd._udp", "PTR", "pc-printer-discovery", 600, "Print mobility records", ["internal"]],
            ["goshen.edu", "lb._dns-sd._udp", "PTR", "pc-printer-discovery", 600, "Print mobility records", ["internal"]],
            ["goshen.edu", "_acme-challenge.giving", "TXT", "7JAqWGzFefEI6DL3ceJSs9NwBNzam6koJ3tPsdN9Rwo", 3600, "GiveCampus records", ["external", "internal"]],
            ["goshen.edu", "_acme-challenge.login", "TXT", "E3tgm4ok71CdO0l5IuPiBneppIctrCUpbmdaq0xds34", 3600, "Okta records", ["external", "internal"]],
            ["goshen.edu", "_amazonses", "TXT", "24rNK29uXti65QqBrI4tsMEGhvApR4S7HlevmKKQXvk=", 3600, "RNL records for Financial Aid", ["external", "internal"]],
            ["goshen.edu", "_dmarc", "TXT", "v=DMARC1; p=reject; pct=100; fo=1; ri=3600; rua=mailto:mx-reports@goshen.edu,mailto:321793f4@inbox.ondmarc.com; ruf=mailto:mx-reports@goshen.edu,mailto:321793f4@inbox.ondmarc.com;", 3600, null, ["external", "internal"]],
            ["goshen.edu", "_dmarc.merrylea", "TXT", "v=DMARC1; p=none;", 3600, "Merry Lea records for DoubleKnot", ["external", "internal"]],
            ["goshen.edu", "_domainkey", "TXT", "t=y; o=~;", 3600, null, ["external", "internal"]],
            ["goshen.edu", "_oktaverification", "TXT", "675a6f53bef84bdfb2cc9914998ba4e0", 3600, "Okta records", ["external", "internal"]],
            ["goshen.edu", "@", "TXT", "v=spf1 ip4:199.8.232.0/24 ip6:2001:18e8:408:1e8::/64 include:amazonses.com include:mktomail.com include:_spf.google.com  include:servers.mcsv.net include:spf1.formassembly.com include:_spf.qualtrics.com include:okta-mail.goshen.edu include:sendgrid.net -all", 3600, "SPF components:\nip4:199.8.232.0/24 is our on-campus range\ninclude:mktomail.com is Marketo mail\ninclude:_spf.google.com is GMail\ninclude:servers.mcsv.net is MailChimp\ninclude:spf.messagegears.net is RNL Demand Builder -- removed 2022-04-25 by davidwk\ninclude:amazonses.com is for Box Office emails\ninclude:spf1.formassembly.com for FormAssembly emails\ninclude:_spf.qualtrics.com for Qualtrics emails\ninclude:okta-mail.goshen.edu for Okta emails\ninclude:sendgrid.net for emails from Asher hosted sites", ["external", "internal"]],
            ["goshen.edu", "@", "TXT", "MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDLZzBd2I9Xt7vUIrCbTrucbhMEWjaANTTVsCqGuJpsJMM9eIyiXTAgPQIFbIUTJRm0r6rOZvOrlfl13BLO1UuPfIN2jGexJ4HFKtmRJ13Q5pfvmFt/9hOl29Ukmm3MuTw5aQwvIAgeANvslRxFCiymNUxHZoh5H6KZi/v1VPOsywIDAQAB", 3600, "ACTIVENET verification records for the Music Center", ["external", "internal"]],
            ["goshen.edu", "@", "TXT", "google-site-verification=irO_1KmgvJbtLHX-SjwUh-1XtCYfCeMdF6hUj5_uZPY", 3600, "Merry Lea records to point to Asher", ["external", "internal"]],
            ["goshen.edu", "@", "TXT", "stripe-verification=3E314FE614258CA6F0A8386086EAC67F742B898DCA2DE55C5C7443F5E1E4C2E0", 3600, "Stripe domain verification record", ["external", "internal"]],
            ["goshen.edu", "@", "TXT", "hpe-greenlake-domain-verification=46543669574537526f44644d376f45567146555a566b6e48626f6d5431593545", 3600, "HPE Greenlake (aka Aruba Central) domain verification record", ["external", "internal"]],
            ["goshen.edu", "@", "TXT", "rAI7T*KXn#eFJ5*IxCVwgOQ3A1CF5I*wbdYs%jBzkn^4x3fPuGMMeLui9!T5vduYap&#@f&zSJ3Lvcue*2oM8NOx@&S%Dox@oJf", 3600, "LastPass domain verification record", ["external", "internal"]],
            ["goshen.edu", "@", "TXT", "anthropic-domain-verification-axmz7t=EGifZzifDpb1Xe0LiUmvv1wRM", 3600, "Anthropic domain verification", ["external", "internal"]],
            ["goshen.edu", "16a7d521570eb812._domainkey", "TXT", "v=DKIM1; k=rsa; h=sha256; s=email; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA1l/nlO0iUYNs7BOK8OoWlS2e2M7tfzZV3yVZQvEUyopDE2MQQPWq5LpekZLv/WtkCH1SP5WbU2pj+yDA6fg2Yx5ZHhF4wNNEB5AN1J9kj9WhI8bZn3yDVQfJP/3cGGZkFezIutnLEw+/4D8jLfa6KfPfRbSknmyR8Pi5sPex5z2IkqRNQYyadAosmVn7eR+qvjNWX0rPMeDX66zyxm1fVbIWi/eUNewOb5MOyNCuuxhgP8nIMbBnyRKu8SA5q3zjopaHhP325ZQsRqkXe4/RiaGzLQ8m5F90smYqqFqdCSj+DSQeRuRscqCEcVNAinUSPDx7rTCCz/UfNBymV77QqwIDAQAB", 3600, "KnowBe4 DKIM", ["external", "internal"]],
            ["goshen.edu", "20200425173636pm._domainkey", "TXT", "k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCZe1pDeOrOnfbcRRmbANBaaXglvOvlT3kXlzsERu6Gb6EjpQ6EhWZ9kTksuMct20qa39HxlgeFLsBVLyP1WtPkxFMTSf5mGv7b6tdt/5los72IDbE8gGgg5AUGiPdEwzCzGntArfz5haLd83+3WsJzJPQTH19rvXNdeZd+STs7aQIDAQAB", 3600, "THANKVIEW records for Admissions", ["external", "internal"]],
            ["goshen.edu", "cloudflare-verify", "TXT", "166873869-1130645089", 3600, "CloudFlare domain verification", ["external", "internal"]],
            ["goshen.edu", "cm._domainkey.merrylea", "TXT", "k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDPbEBgaAydhFzq1CSWNyqnVmFskEyRC4pYSwgux3HMN0H+P8RAUjj8c+WQMpfZ5CLqa26Ntau2NMpZmv99ZosjsEgz5TAMYi177epldceWG1qfvRi3ya6v26u1XG3GDcLo4FYelopUDe9QzVlv/x/Y2XQvgLaO1Te8QkanbdHuYQIDAQAB", 3600, "Merry Lea records for DoubleKnot", ["external", "internal"]],
            ["goshen.edu", "formassembly._domainkey", "TXT", "v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA1cZ7012t22DXM8uF3T7w7cFB0+8licLPIKrPpYHG8yhgWNYyE6dyVp7ZEg5O6KXacZDGEW20PL7N9JGvQ1G91whXF8W2TS/MSntlhfZaom9M17LAvnMPEi/SKnHvsdFXqGtRXfKsAo6elWeH8Q17vaOoq9sKRHpvfAaNKhIA1DWZ7wLObAH8l46casi4bMzzAOzIdHOsKYWY6m1esTLpLs4sf8L+jH2r8xlXyzRbjlqO/M//lvhDo3RHSx4mDtaNxvQfDy15DcxflIcaCXgkfMkklOGjs2wAY+gCirYEz54X+7DE5XH6wPFQDtBMJkxS+mTp4xOusiFBSXGpeZTLcwIDAQAB", 3600, "FormAssembly domain key", ["external", "internal"]],
            ["goshen.edu", "give", "TXT", "v=spf1 include:mailgun.org ~all", 3600, "Blackbaud records for Development", ["external", "internal"]],
            ["goshen.edu", "google._domainkey", "TXT", "v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAnWOGbzguR7EDkcEO0H4OFmdq9jAgkJolIlH4SmSWmnht8z3PrllkLPpwfEolLYnibsyCk1E7BAqDBk1hm/3yiKStOb0sCg5iTiUDoODjZdfsJuC1DrGei5dpTSjrtFS3UWbIQJAxUDPgNSEPdiz5IGkpmIBpB2Mf2jR4cQOwucFQ74IiszGa5qCd33M22he0fMhdozmXYqKjICJPXmw6TzbuymWyq5JsORmyjPr+rdZfNUdLH00oL8TxNYoETq0B9Nh2vBb/SioELT21tyOrzlGctddOYaq1JeoltSeQLkmjiGZCjsZ5zPJpqZ91pQ4fgkmBnNzX9JaUnz25DhWQlQIDAQAB", 3600, "G-suite domain key", ["external", "internal"]],
            ["goshen.edu", "k1._domainkey.give", "TXT", "k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCyqtmxb5TCxgbo7HjoOI6nLX6+iXhdoQj5gBgcyhYQPFhwrN3KGaOhWPdr47NAhskj0LT+e0DL4zCxHlP2bYSx5lpSe+Yqoqf9T6LQBVAjmwvE7Ydm2zF1ezl/J38qpXGGYVeot/NwW+/aRh8ND0lQH6VqRZofqq5L+bKlBoIkIQIDAQAB", 3600, "Blackbaud records for Development", ["external", "internal"]],
            ["goshen.edu", "M1._domainkey", "TXT", "k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDFUlNZvtGDlIGDRtzyRQydM9yRInD5YMx86QpgZ3v7pT+Mx4tGbjUxY41TXbsp7UH9hTREaKKGQKNM/B3FzcFVv4zafZ09lUaXcbSdtD70iXyH0OXEGXLZI5gG0ZwjK5ptgQ18d+pUP9s8xMkJnZlubTk9MLvQnv3ZBzoL9FHFDQIDAQAB", 3600, "Marketo domain key", ["external", "internal"]],
            ["goshen.edu", "mailman", "TXT", "MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCoGaWxpxJv/BSadWRj107fkQyPNyr74XyVKMNchlF5lIOZ/a2gtQ34fJAvRmDTSTMCm+u6FV+/LXkmyvEHTg5ICM7/bbgEqVglEMCKQNvUwzesccuyux8l1aj9OD7eZfz+W2YMddqpn75LfxiCjXvs+sgaEZ8xG1sogQlVYL+u5QIDAQAB", 3600, "ACTIVENET verification records for the Music Center", ["external", "internal"]],
            ["goshen.edu", "mailman", "TXT", "v=spf1 ip4:199.8.232.0/24 -all", 3600, null, ["external", "internal"]],
            ["goshen.edu", "merrylea", "TXT", "v=spf1 include:_spf.createsend.com ~all", 3600, "Merry Lea records for DoubleKnot", ["external", "internal"]],
            ["goshen.edu", "qualtrics_8d5677._domainkey", "TXT", "v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCWihpmPBfAwrfyDPSm0eOpjqxpO38P8UWiW1PwlSO7yLalzNsGauGae2BvFdfDa1S4/qqkqO1CFVY+63PPfhSvfuDvyOtjZSFeQu3DJnUId7ra+LIXWH7zDjLUHzFWmP9s7+iV1Mfy3EdR6P6D31lsQygvz5oVRDp5SWct+DsEyQIDAQAB", 3600, "Qualtrics DKIM", ["external", "internal"]],
            ["goshen.edu", "scph0320._domainkey", "TXT", "v=DKIM1; k=rsa; h=sha256; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCglf/gYr22aDpGfoR5v3IbNADRRrclOiUTOFpvFLx4JLL36fx2qWXKiIz7U1IkSf49P2khNdnfGQABzZLJcQ5UxGgSD0i5HggTNIs6l843fmkz5uQZw3fGt2Vd1KENHj1IVhMzAi4F8RQg0beHUT0GKSHj5LyngUhdgEEQNSK2JQIDAQAB", 3600, "VENDINI record for Box Office", ["external", "internal"]],
        ];

        $count = 0;
        foreach ($defs as [$domainName, $hostname, $type, $value, $ttl, $comment, $viewNames]) {
            if (!isset($domainByName[$domainName])) {
                $domainByName[$domainName] = $this->em->getRepository(Domain::class)->findOneBy(['name' => $domainName]);
            }
            $domain = $domainByName[$domainName] ?? null;
            if (!$domain) {
                $io->warning("Domain '$domainName' not found — skipping record $hostname $type");
                continue;
            }

            $record = new DomainRecord();
            $record->setDomain($domain);
            $record->setHostname($hostname);
            $record->setType(RecordType::from($type));
            $record->setValue($value);
            $record->setTtl($ttl);
            $record->setComment($comment);
            foreach ($viewNames as $viewName) {
                if (isset($viewByName[$viewName])) {
                    $record->addView($viewByName[$viewName]);
                }
            }

            if (!$dryRun) {
                $this->em->persist($record);
            }
            $count++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('  %d domain records', $count));
    }

    // -------------------------------------------------------------------------
    // Subnet NS records (dns1/2/3 for every non-container subnet)
    // -------------------------------------------------------------------------

    private function seedSubnetNsRecords(SymfonyStyle $io, bool $dryRun): void
    {
        $viewByName = [];
        foreach ($this->em->getRepository(DnsView::class)->findAll() as $v) {
            $viewByName[$v->getName()] = $v;
        }

        $subnets   = $this->em->getRepository(Subnet::class)->findBy(['isContainer' => false]);
        $nsServers = ['dns1.goshen.edu', 'dns2.goshen.edu', 'dns3.goshen.edu'];
        $count     = 0;

        foreach ($subnets as $subnet) {
            $cidr        = $subnet->getIpv4Cidr();
            $firstOctet  = $cidr !== null ? (int) explode('.', $cidr)[0] : null;
            $addExternal = in_array($firstOctet, [198, 199], true);

            foreach ($nsServers as $ns) {
                $record = new SubnetRecord();
                $record->setSubnet($subnet);
                $record->setHostname('@');
                $record->setType(RecordType::NS);
                $record->setValue($ns);

                if (isset($viewByName['internal'])) {
                    $record->addView($viewByName['internal']);
                }
                if ($addExternal && isset($viewByName['external'])) {
                    $record->addView($viewByName['external']);
                }

                if (!$dryRun) {
                    $this->em->persist($record);
                }
                $count++;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('  %d subnet NS records', $count));
    }
}
