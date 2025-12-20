<?php
// geography.php — Geographic Settings & Project Locations (Hardened/Upgraded)
// - Fixes "Undefined array key region_name/zone_name/woreda_name" by
//   (1) adding safe migrations to ensure columns exist on project_locations
//   (2) resolving names via LEFT JOINs to geo_* tables and using *_resolved aliases
// - Preserves dependent dropdowns + map + analytics
// - Ethiopia timezone for consistent dates

require_once __DIR__ . '/../header.php';
require_login();
$pdo = getPDO();

@date_default_timezone_set('Africa/Addis_Ababa');

// Start output buffering to avoid header issues
ob_start();

$message = '';
$error   = '';

// --------------------------------------------------------------
// 0) Helper: compute project status from start & end dates
// --------------------------------------------------------------
function computeProjectStatus(?string $startDate, ?string $endDate): string
{
    $today = new DateTimeImmutable(date('Y-m-d'));
    $status = 'ongoing';

    try {
        $start = $startDate ? new DateTimeImmutable($startDate) : null;
        $end   = $endDate ? new DateTimeImmutable($endDate) : null;

        if ($start && $today < $start) {
            $status = 'upcoming';
        } elseif ($end && $today > $end) {
            $status = 'closed';
        } else {
            $status = 'ongoing';
        }
    } catch (Exception $e) {
        $status = 'ongoing';
    }

    return $status;
}

// --------------------------------------------------------------
// 0.b) Small helpers
// --------------------------------------------------------------
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function ensure_pl_column(PDO $pdo, string $col, string $definitionSql): void {
    // Add a column to project_locations if it does not exist
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'project_locations'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$col]);
    $exists = (int)$stmt->fetchColumn() > 0;
    if (!$exists) {
        $pdo->exec("ALTER TABLE `project_locations` ADD COLUMN $definitionSql");
    }
}

// --------------------------------------------------------------
// 1) Ensure reference tables exist (regions / zones / woredas)
// --------------------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS geo_regions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(191) NOT NULL,
        code VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_region_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS geo_zones (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        region_id INT UNSIGNED NOT NULL,
        name VARCHAR(191) NOT NULL,
        code VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_region_id (region_id),
        INDEX idx_zone_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS geo_woredas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        zone_id INT UNSIGNED NOT NULL,
        name VARCHAR(191) NOT NULL,
        code VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_zone_id (zone_id),
        INDEX idx_woreda_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// --------------------------------------------------------------
// 1.a) Seed basic Ethiopia regions/zones/woredas if tables empty
// --------------------------------------------------------------
try {
    $regionCount = (int)$pdo->query("SELECT COUNT(*) FROM geo_regions")->fetchColumn();
    if ($regionCount === 0) {
        $regionSeeds = [
            ['Afar',                 'AF'],
            ['Amhara',               'AM'],
            ['Benishangul-Gumuz',    'BG'],
            ['Dire Dawa',            'DD'],
            ['Gambella',             'GA'],
            ['Harari',               'HA'],
            ['Oromia',               'OR'],
            ['Sidama',               'SD'],
            ['Somali',               'SO'],
            ['South West Ethiopia',  'SWE'],
            ['SNNP',                 'SN'],
            ['Tigray',               'TG'],
            ['Addis Ababa',          'AA'],
            ['Sheger City',          'SH'],
            ['Central Ethiopia',     'CE']
        ];
        $stmt = $pdo->prepare("INSERT INTO geo_regions (name, code) VALUES (?, ?)");
        foreach ($regionSeeds as $r) {
            $stmt->execute($r);
        }
    }

    $zoneCount = (int)$pdo->query("SELECT COUNT(*) FROM geo_zones")->fetchColumn();
    if ($zoneCount === 0) {
        $zoneSeeds = [
            ['Oromia', 'East Wollega',        'EWO'],
            ['Oromia', 'West Wollega',        'WWO'],
            ['Oromia', 'Horo Guduru Wollega', 'HGW'],
            ['Oromia', 'West Arsi',           'WAS'],
            ['Oromia', 'East Shoa',           'ESH'],
            ['Amhara', 'North Wollo',         'NWO'],
            ['Amhara', 'South Wollo',         'SWO'],
            ['Tigray', 'Central Zone',        'CTZ'],
            ['Tigray', 'South Eastern Zone',  'SEZ'],
            ['Gambella', 'Nuer Zone',         'NUZ'],
        ];
        $stmtZ = $pdo->prepare("
            INSERT INTO geo_zones (region_id, name, code)
            SELECT id, ?, ? FROM geo_regions WHERE name = ? LIMIT 1
        ");
        foreach ($zoneSeeds as $z) {
            // [region_name, zone_name, code]
            $stmtZ->execute([$z[1], $z[2], $z[0]]);
        }
    }

    $woredaCount = (int)$pdo->query("SELECT COUNT(*) FROM geo_woredas")->fetchColumn();
    if ($woredaCount === 0) {
        $woredaSeeds = [
            ['East Wollega',        'Sibu Sire',     'SBU'],
            ['East Wollega',        'Gobu Seyo',     'GBS'],
            ['West Wollega',        'Kondala',       'KON'],
            ['Horo Guduru Wollega', 'Jardega Jarte', 'JRJ'],
            ['West Arsi',           'Siraro',        'SIR'],
            ['Nuer Zone',           'Lare',          'LAR'],
            ['Nuer Zone',           'Makuey',        'MAK'],
            ['Nuer Zone',           'Wantawo',       'WAN'],
            ['Central Zone',        'Axum',          'AXU'],
            ['Central Zone',        'Adwa',          'ADW'],
            ['Central Zone',        'Abyi Adi',      'ABD'],
            ['South Eastern Zone',  'Seharti',       'SEH'],
            ['North Wollo',         'Dawunt',        'DAW'],
            ['East Shoa',           'Akaki',         'AKA'],
        ];
        $stmtW = $pdo->prepare("
            INSERT INTO geo_woredas (zone_id, name, code)
            SELECT z.id, ?, ?
            FROM geo_zones z
            WHERE z.name = ? LIMIT 1
        ");
        foreach ($woredaSeeds as $w) {
            // [zone_name, woreda_name, code]
            $stmtW->execute([$w[1], $w[2], $w[0]]);
        }
    }
} catch (Exception $e) {
    // Fail silently, user can still add via "Other / Add new"
}

// --------------------------------------------------------------
// 2) Ensure project_locations table exists + MIGRATIONS
// --------------------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS project_locations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        region_id INT UNSIGNED NULL,
        zone_id INT UNSIGNED NULL,
        woreda_id INT UNSIGNED NULL,
        region_name VARCHAR(191) DEFAULT NULL,
        zone_name   VARCHAR(191) DEFAULT NULL,
        woreda_name VARCHAR(191) DEFAULT NULL,
        latitude  DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        sectors_json   TEXT NULL,
        specifics_json TEXT NULL,
        status ENUM('upcoming','ongoing','closed') DEFAULT 'ongoing',
        start_date DATE NULL,
        end_date   DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_project_id (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Add missing columns for older installs (prevents undefined array key warnings)
ensure_pl_column($pdo, 'region_name',   "`region_name` VARCHAR(191) NULL DEFAULT NULL");
ensure_pl_column($pdo, 'zone_name',     "`zone_name`   VARCHAR(191) NULL DEFAULT NULL");
ensure_pl_column($pdo, 'woreda_name',   "`woreda_name` VARCHAR(191) NULL DEFAULT NULL");
ensure_pl_column($pdo, 'sectors_json',  "`sectors_json` TEXT NULL");
ensure_pl_column($pdo, 'specifics_json',"`specifics_json` TEXT NULL");
ensure_pl_column($pdo, 'status',        "`status` ENUM('upcoming','ongoing','closed') NOT NULL DEFAULT 'ongoing'");
ensure_pl_column($pdo, 'start_date',    "`start_date` DATE NULL");
ensure_pl_column($pdo, 'end_date',      "`end_date` DATE NULL");
ensure_pl_column($pdo, 'latitude',      "`latitude` DECIMAL(10,7) NULL");
ensure_pl_column($pdo, 'longitude',     "`longitude` DECIMAL(10,7) NULL");

// --------------------------------------------------------------
// 3) Detect projects table & its key columns
// --------------------------------------------------------------
$hasProjectsTable = false;
$projectColumns   = [];

try {
    $tblStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'projects'
    ");
    $tblStmt->execute();
    $hasProjectsTable = $tblStmt->fetchColumn() > 0;

    if ($hasProjectsTable) {
        $colStmt = $pdo->prepare("
            SELECT COLUMN_NAME 
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'projects'
        ");
        $colStmt->execute();
        $projectColumns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    $hasProjectsTable = false;
    $projectColumns   = [];
}

$projectTitleCol = null;
$projectCodeCol  = null;
$projectStartCol = null;
$projectEndCol   = null;

if ($hasProjectsTable) {
    if (in_array('title', $projectColumns, true)) {
        $projectTitleCol = 'title';
    } elseif (in_array('project_title', $projectColumns, true)) {
        $projectTitleCol = 'project_title';
    } elseif (in_array('name', $projectColumns, true)) {
        $projectTitleCol = 'name';
    }

    if (in_array('code', $projectColumns, true)) {
        $projectCodeCol = 'code';
    } elseif (in_array('project_code', $projectColumns, true)) {
        $projectCodeCol = 'project_code';
    }

    if (in_array('start_date', $projectColumns, true)) {
        $projectStartCol = 'start_date';
    } elseif (in_array('project_start_date', $projectColumns, true)) {
        $projectStartCol = 'project_start_date';
    }

    if (in_array('end_date', $projectColumns, true)) {
        $projectEndCol = 'end_date';
    } elseif (in_array('project_end_date', $projectColumns, true)) {
        $projectEndCol = 'project_end_date';
    }
}

// --------------------------------------------------------------
// 4) Load projects list for dropdown & map/status
// --------------------------------------------------------------
$projects      = [];
$projectsById  = [];

if ($hasProjectsTable && $projectTitleCol) {
    $selectCols = ['id', "`{$projectTitleCol}` AS project_title"];

    if ($projectCodeCol) {
        $selectCols[] = "`{$projectCodeCol}` AS project_code";
    }
    if ($projectStartCol) {
        $selectCols[] = "`{$projectStartCol}` AS project_start_date";
    }
    if ($projectEndCol) {
        $selectCols[] = "`{$projectEndCol}` AS project_end_date";
    }

    $orderCol = $projectStartCol ? "`{$projectStartCol}` DESC" : "id DESC";
    $sql      = "SELECT " . implode(', ', $selectCols) . " FROM projects ORDER BY {$orderCol}";

    try {
        $projects = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($projects as $p) {
            $projectsById[$p['id']] = $p;
        }
    } catch (Exception $e) {
        $projects     = [];
        $projectsById = [];
    }
}

// --------------------------------------------------------------
// 5) Load geo reference data for dependent dropdowns
// --------------------------------------------------------------
$regions  = $pdo->query("SELECT id, name, code FROM geo_regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$zones    = $pdo->query("SELECT id, region_id, name, code FROM geo_zones ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$woredas  = $pdo->query("SELECT id, zone_id, name, code FROM geo_woredas ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------------------
// 6) Define standard sector / specific area lists
// --------------------------------------------------------------
$sectorsList = [
    'Health',
    'Nutrition',
    'WASH',
    'Protection',
    'MHPSS',
    'ES/NFI & Shelter',
    'Education',
    'Livelihoods & Food Security',
    'Peacebuilding & Social Cohesion',
    'Agriculture & Livestock',
    'Multi-purpose Cash',
    'Governance & Capacity Building',
];

$projectSpecificList = [
    'Mobile Health & Nutrition Team (MHNT)',
    'Primary Health Care (PHC)',
    'Cholera / Outbreak Response',
    'CMAM / OTP / SC',
    'IYCF-E',
    'SRH / FP / ANC / PNC',
    'Safe Water Supply',
    'Sanitation & Hygiene Promotion',
    'WASH NFIs & HH Water Treatment',
    'GBV Prevention & Response',
    'Child Protection',
    'Protection Monitoring',
    'Emergency Shelter Kits',
    'Shelter Repair / Reconstruction',
    'Cash for Shelter',
    'Multi-purpose Cash Assistance',
    'Psycho-social Support',
    'Peace Committees / Community Dialogues',
    'Self-Help Groups / SHGs',
    'TVET & Livelihood Grants',
    'School Feeding / EiE',
    'Agriculture Inputs / Seeds',
    'Livestock Support',
];

// --------------------------------------------------------------
// 7) Handle POST: save / delete project locations
// --------------------------------------------------------------
$editLocation = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Save or update location
    if (isset($_POST['save_location'])) {
        $locationId = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

        $region_raw  = $_POST['region_id']  ?? '';
        $zone_raw    = $_POST['zone_id']    ?? '';
        $woreda_raw  = $_POST['woreda_id']  ?? '';

        $region_other  = trim($_POST['region_other']  ?? '');
        $zone_other    = trim($_POST['zone_other']    ?? '');
        $woreda_other  = trim($_POST['woreda_other']  ?? '');

        $lat  = strlen($_POST['latitude']  ?? '') ? (float)$_POST['latitude']  : null;
        $lng  = strlen($_POST['longitude'] ?? '') ? (float)$_POST['longitude'] : null;

        // Sectors & specifics with "Other / free-text"
        $sectors       = $_POST['sectors']   ?? [];
        $sectorsOther  = trim($_POST['sectors_other'] ?? '');
        $specifics     = $_POST['specifics'] ?? [];
        $specificsOther = trim($_POST['specifics_other'] ?? '');

        // Clean sectors
        $cleanSectors = [];
        foreach ($sectors as $s) {
            if ($s === '__other__' || $s === '') continue;
            $cleanSectors[] = $s;
        }
        if ($sectorsOther !== '') {
            $extra = array_map('trim', explode(',', $sectorsOther));
            foreach ($extra as $e) if ($e !== '') $cleanSectors[] = $e;
        }
        $cleanSectors = array_values(array_unique($cleanSectors));
        $sectorsJson  = json_encode($cleanSectors, JSON_UNESCAPED_UNICODE);

        // Clean specifics
        $cleanSpecifics = [];
        foreach ($specifics as $s) {
            if ($s === '__other__' || $s === '') continue;
            $cleanSpecifics[] = $s;
        }
        if ($specificsOther !== '') {
            $extra = array_map('trim', explode(',', $specificsOther));
            foreach ($extra as $e) if ($e !== '') $cleanSpecifics[] = $e;
        }
        $cleanSpecifics = array_values(array_unique($cleanSpecifics));
        $specificsJson  = json_encode($cleanSpecifics, JSON_UNESCAPED_UNICODE);

        if ($project_id <= 0) {
            $error = 'Please select a project before saving the location.';
        } else {
            // --- Region handling (existing or create) ---
            $region_id   = null;
            $region_name = '';

            if ($region_raw && $region_raw !== '__other__') {
                $region_id = (int)$region_raw;
                $stmt = $pdo->prepare("SELECT name FROM geo_regions WHERE id = ?");
                $stmt->execute([$region_id]);
                $region_name = (string)$stmt->fetchColumn();
            } elseif ($region_other !== '') {
                // upsert-ish by case-insensitive name
                $stmt = $pdo->prepare("SELECT id, name FROM geo_regions WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                $stmt->execute([$region_other]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $region_id = (int)$row['id'];
                    $region_name = $row['name'];
                } else {
                    $ins = $pdo->prepare("INSERT INTO geo_regions (name) VALUES (?)");
                    $ins->execute([$region_other]);
                    $region_id = (int)$pdo->lastInsertId();
                    $region_name = $region_other;
                }
            }

            // --- Zone handling (validate parent or create) ---
            $zone_id   = null;
            $zone_name = '';

            if ($zone_raw && $zone_raw !== '__other__') {
                $zone_id = (int)$zone_raw;
                $stmt = $pdo->prepare("SELECT name FROM geo_zones WHERE id = ?");
                $stmt->execute([$zone_id]);
                $zone_name = (string)$stmt->fetchColumn();
            } elseif ($zone_other !== '' && $region_id) {
                // create zone under region if not exists
                $stmt = $pdo->prepare("
                    SELECT id, name FROM geo_zones 
                    WHERE region_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1
                ");
                $stmt->execute([$region_id, $zone_other]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $zone_id = (int)$row['id'];
                    $zone_name = $row['name'];
                } else {
                    $ins = $pdo->prepare("INSERT INTO geo_zones (region_id, name) VALUES (?, ?)");
                    $ins->execute([$region_id, $zone_other]);
                    $zone_id = (int)$pdo->lastInsertId();
                    $zone_name = $zone_other;
                }
            }

            // --- Woreda handling (validate parent or create) ---
            $woreda_id   = null;
            $woreda_name = '';

            if ($woreda_raw && $woreda_raw !== '__other__') {
                $woreda_id = (int)$woreda_raw;
                $stmt = $pdo->prepare("SELECT name FROM geo_woredas WHERE id = ?");
                $stmt->execute([$woreda_id]);
                $woreda_name = (string)$stmt->fetchColumn();
            } elseif ($woreda_other !== '' && $zone_id) {
                $stmt = $pdo->prepare("
                    SELECT id, name FROM geo_woredas
                    WHERE zone_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1
                ");
                $stmt->execute([$zone_id, $woreda_other]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $woreda_id = (int)$row['id'];
                    $woreda_name = $row['name'];
                } else {
                    $ins = $pdo->prepare("INSERT INTO geo_woredas (zone_id, name) VALUES (?, ?)");
                    $ins->execute([$zone_id, $woreda_other]);
                    $woreda_id = (int)$pdo->lastInsertId();
                    $woreda_name = $woreda_other;
                }
            }

            // --- Project dates & status ---
            $start_date = null;
            $end_date   = null;
            $status     = 'ongoing';

            if (isset($projectsById[$project_id])) {
                $proj = $projectsById[$project_id];
                $start_date = $proj['project_start_date'] ?? null;
                $end_date   = $proj['project_end_date']   ?? null;
                $status     = computeProjectStatus($start_date, $end_date);
            }

            if (!$error) {
                if ($locationId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE project_locations
                        SET project_id   = ?,
                            region_id    = ?,
                            zone_id      = ?,
                            woreda_id    = ?,
                            region_name  = ?,
                            zone_name    = ?,
                            woreda_name  = ?,
                            latitude     = ?,
                            longitude    = ?,
                            sectors_json   = ?,
                            specifics_json = ?,
                            status       = ?,
                            start_date   = ?,
                            end_date     = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $project_id,
                        $region_id ?: null,
                        $zone_id   ?: null,
                        $woreda_id ?: null,
                        $region_name ?: null,
                        $zone_name   ?: null,
                        $woreda_name ?: null,
                        $lat,
                        $lng,
                        $sectorsJson,
                        $specificsJson,
                        $status,
                        $start_date,
                        $end_date,
                        $locationId
                    ]);
                    $message = 'Project location updated and map refreshed ✔️';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO project_locations
                            (project_id, region_id, zone_id, woreda_id,
                             region_name, zone_name, woreda_name,
                             latitude, longitude, sectors_json, specifics_json,
                             status, start_date, end_date)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([
                        $project_id,
                        $region_id ?: null,
                        $zone_id   ?: null,
                        $woreda_id ?: null,
                        $region_name ?: null,
                        $zone_name   ?: null,
                        $woreda_name ?: null,
                        $lat,
                        $lng,
                        $sectorsJson,
                        $specificsJson,
                        $status,
                        $start_date,
                        $end_date
                    ]);
                    $message = '✅ New project location registered & added to the map.';
                }
            }
        }
    }

    // Delete location
    if (isset($_POST['delete_location'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM project_locations WHERE id = ?");
            $stmt->execute([$id]);
            $message = '🗑️ Project location removed.';
        }
    }
}

// --------------------------------------------------------------
// 8) Load one location for editing if requested
// --------------------------------------------------------------
$editLocation = null;
if (isset($_GET['edit_location_id'])) {
    $id = (int)$_GET['edit_location_id'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM project_locations WHERE id = ?");
        $stmt->execute([$id]);
        $editLocation = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// --------------------------------------------------------------
// 9) Load locations for table + analytics + map
//     IMPORTANT: Use *_resolved aliases to avoid undefined keys
// --------------------------------------------------------------
$select = "pl.*,
           COALESCE(pl.region_name, gr.name)  AS region_name_resolved,
           COALESCE(pl.zone_name,   gz.name)  AS zone_name_resolved,
           COALESCE(pl.woreda_name, gw.name)  AS woreda_name_resolved";
$join   = "LEFT JOIN geo_regions gr  ON gr.id  = pl.region_id
          LEFT JOIN geo_zones   gz  ON gz.id  = pl.zone_id
          LEFT JOIN geo_woredas gw  ON gw.id  = pl.woreda_id";

if ($hasProjectsTable) {
    $join = "LEFT JOIN projects p ON p.id = pl.project_id
             LEFT JOIN geo_regions gr  ON gr.id  = pl.region_id
             LEFT JOIN geo_zones   gz  ON gz.id  = pl.zone_id
             LEFT JOIN geo_woredas gw  ON gw.id  = pl.woreda_id";
    if ($projectTitleCol) {
        $select .= ", p.`{$projectTitleCol}` AS project_title";
    }
    if ($projectCodeCol) {
        $select .= ", p.`{$projectCodeCol}` AS project_code";
    }
    if ($projectStartCol) {
        $select .= ", p.`{$projectStartCol}` AS project_start_date_raw";
    }
    if ($projectEndCol) {
        $select .= ", p.`{$projectEndCol}` AS project_end_date_raw";
    }
}

$sqlLocations = "
    SELECT {$select}
    FROM project_locations pl
    {$join}
    ORDER BY pl.id DESC
";
$projectLocations = $pdo->query($sqlLocations)->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------------------
// 10) Build analytics arrays (use *_resolved safely)
// --------------------------------------------------------------
$statsRegion = [];
$statsZone   = [];
$statsWoreda = [];
$statsSector = [];
$statsStatus = [
    'upcoming' => 0,
    'ongoing'  => 0,
    'closed'   => 0
];

$mapLocations = [];
$totalProjects = 0;

foreach ($projectLocations as $loc) {
    $totalProjects++;

    $regionKey = ($loc['region_name_resolved'] ?? '') !== '' ? $loc['region_name_resolved'] : 'Unknown region';
    $zoneKey   = ($loc['zone_name_resolved']   ?? '') !== '' ? $loc['zone_name_resolved']   : 'Unknown zone';
    $woredaKey = ($loc['woreda_name_resolved'] ?? '') !== '' ? $loc['woreda_name_resolved'] : 'Unknown woreda';

    $statsRegion[$regionKey] = ($statsRegion[$regionKey] ?? 0) + 1;
    $statsZone[$zoneKey]     = ($statsZone[$zoneKey]     ?? 0) + 1;
    $statsWoreda[$woredaKey] = ($statsWoreda[$woredaKey] ?? 0) + 1;

    $startDate = $loc['start_date'] ?? null;
    $endDate   = $loc['end_date']   ?? null;

    $status = computeProjectStatus($startDate, $endDate);
    $statsStatus[$status] = ($statsStatus[$status] ?? 0) + 1;

    // Decode sectors for sector analytics
    $sectors = json_decode($loc['sectors_json'] ?? '[]', true);
    if (is_array($sectors)) {
        foreach ($sectors as $s) {
            $sKey = $s ?: 'Unspecified';
            $statsSector[$sKey] = ($statsSector[$sKey] ?? 0) + 1;
        }
    }

    // Map markers
    if (!empty($loc['latitude']) && !empty($loc['longitude'])) {
        $mapLocations[] = [
            'id'            => (int)$loc['id'],
            'project_id'    => (int)$loc['project_id'],
            'project_title' => $loc['project_title'] ?? ('Project #' . $loc['project_id']),
            'project_code'  => $loc['project_code'] ?? '',
            'region_name'   => $loc['region_name_resolved'] ?? '',
            'zone_name'     => $loc['zone_name_resolved'] ?? '',
            'woreda_name'   => $loc['woreda_name_resolved'] ?? '',
            'lat'           => (float)$loc['latitude'],
            'lng'           => (float)$loc['longitude'],
            'status'        => $status
        ];
    }
}

// --------------------------------------------------------------
// 11) Project info for header bar
// --------------------------------------------------------------
$projectSettings = [
    'organization' => 'Nexus Ethiopia',
    'module_title' => 'Geographic Settings & Project Locations'
];

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Geography Settings & Project Locations - <?php echo h($projectSettings['organization']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Chart.js for analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- jsPDF & html2canvas for PDF / image exports -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <!-- XLSX for Excel export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <!-- Leaflet for Ethiopia map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --accent-color: #e74c3c;
            --warning-color: #f39c12;
            --bg-color: #f8f9fa;
            --card-bg: #ffffff;
            --text-color: #2c3e50;
            --border-color: #dee2e6;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0 10px 40px 10px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.06);
            border: 1px solid var(--border-color);
        }

        .project-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .project-info h2 {
            margin: 0 0 8px 0;
            font-size: 1.5em;
        }

        .project-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 8px;
            font-size: 0.9em;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .dashboard-header h1,
        .dashboard-header h2 {
            margin: 0;
            font-size: 1.4em;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 9px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-success { background: var(--secondary-color); }
        .btn-warning { background: var(--warning-color); }
        .btn-danger  { background: var(--accent-color); }
        .btn-light   { background: #ecf0f1; color: var(--text-color); }

        .btn-sm {
            padding: 6px 10px;
            font-size: 12px;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        .stat-card {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            padding: 16px;
            border-radius: 10px;
        }

        .stat-card h3 {
            margin: 0 0 6px 0;
            font-size: 13px;
            opacity: 0.9;
        }

        .stat-card .value {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 12px;
            margin-bottom: 10px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        label {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 13px;
        }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        select,
        textarea {
            padding: 8px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            font-size: 13px;
            background: white;
            color: var(--text-color);
        }

        select[multiple] {
            min-height: 60px;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 4px;
        }

        .badge-success { background: var(--secondary-color); color: white; }
        .badge-danger  { background: var(--accent-color);  color: white; }
        .badge-info    { background: var(--primary-color); color: white; }
        .badge-warning { background: var(--warning-color); color: white; }

        .status-badge-upcoming { background: #8e44ad; color: #fff; }
        .status-badge-ongoing  { background: #27ae60; color: #fff; }
        .status-badge-closed   { background: #7f8c8d; color: #fff; }

        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th, td {
            padding: 8px 10px;
            border: 1px solid var(--border-color);
            text-align: left;
            color: #000; /* readable black text */
        }

        th {
            background: #e9ecef;
            font-weight: 700;
        }

        tr:nth-child(even) {
            background: #fdfdfd;
        }

        tr:hover {
            background: #f5fbff;
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .chip {
            padding: 2px 6px;
            border-radius: 999px;
            background: #ecf0f1;
            font-size: 11px;
        }

        #mapContainer {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 10px;
        }

        #mapTitle {
            font-weight: 600;
            font-size: 14px;
            margin: 0 0 6px 0;
            text-align: center;
        }

        #map {
            height: 430px;
            border-radius: 8px;
        }

        .export-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
            }
            body {
                padding: 0 6px 30px 6px;
            }
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                padding: 0;
            }
            .card {
                box-shadow: none;
                border: 1px solid #000;
            }
            #map {
                height: 300px;
            }
        }
    </style>
</head>
<body>

<div class="card">
    <div class="project-info">
        <h2>🗺️ <?php echo h($projectSettings['module_title']); ?></h2>
        <div class="project-details">
            <div><strong>Organization:</strong> <?php echo h($projectSettings['organization']); ?></div>
            <div><strong>Total Projects with Locations:</strong> <?php echo (int)$totalProjects; ?></div>
            <div><strong>Regions Covered:</strong> <?php echo max(0, count($statsRegion)); ?></div>
            <div><strong>Last Update:</strong> <?php echo date('Y-m-d H:i'); ?></div>
        </div>
    </div>

    <div class="dashboard-header">
        <h1>Geographic Settings & Project Location Registry</h1>
        <div class="toolbar no-print">
            <button class="btn btn-light" onclick="window.print()">🖨️ Print Page</button>
            <button class="btn btn-info" onclick="scrollToSection('analyticsCard')">📊 Location Analytics</button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="badge badge-success no-print">✔ <?php echo h($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="badge badge-danger no-print">⚠ <?php echo h($error); ?></div>
    <?php endif; ?>

    <div class="stats-cards">
        <div class="stat-card">
            <h3>Total Projects with Location</h3>
            <div class="value"><?php echo (int)$totalProjects; ?></div>
            <div>Mapped in the system</div>
        </div>
        <div class="stat-card" style="background: linear-gradient(135deg,#27ae60,#2ecc71);">
            <h3>Regions Covered</h3>
            <div class="value"><?php echo max(0, count($statsRegion)); ?></div>
            <div>Geographic coverage</div>
        </div>
        <div class="stat-card" style="background: linear-gradient(135deg,#e67e22,#f39c12);">
            <h3>Ongoing Projects</h3>
            <div class="value"><?php echo (int)($statsStatus['ongoing'] ?? 0); ?></div>
            <div>Currently active</div>
        </div>
        <div class="stat-card" style="background: linear-gradient(135deg,#8e44ad,#9b59b6);">
            <h3>Closed / Past Projects</h3>
            <div class="value"><?php echo (int)($statsStatus['closed'] ?? 0); ?></div>
            <div>Completed interventions</div>
        </div>
    </div>
</div>

<!-- LOCATION FORM -->
<div class="card no-print" id="locationFormCard">
    <h2><?php echo $editLocation ? '✏️ Edit Project Location' : '➕ Register New Project Location'; ?></h2>
    <form method="post">
        <input type="hidden" name="location_id" value="<?php echo $editLocation ? (int)$editLocation['id'] : 0; ?>">

        <div class="form-row">
            <div class="form-group">
                <label>Project Title <span style="color:red">*</span></label>
                <select name="project_id" required>
                    <option value="">-- Select Project --</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?php echo (int)$proj['id']; ?>"
                            <?php
                            $selProj = $editLocation ? (int)$editLocation['project_id'] : 0;
                            echo $selProj === (int)$proj['id'] ? 'selected' : '';
                            ?>>
                            <?php
                            $code  = $proj['project_code'] ?? '';
                            $title = $proj['project_title'] ?? ('Project #'.$proj['id']);
                            echo h(trim(($code ? $code.' - ' : '').$title));
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>If the project is not listed, first register it in the Projects module.</small>
            </div>

            <?php
            // Prepare selected sectors & "other" text for editing
            $selectedSectors = [];
            $sectorsOtherVal = '';
            if ($editLocation && !empty($editLocation['sectors_json'])) {
                $savedSectors = json_decode($editLocation['sectors_json'], true) ?: [];
                $custom = [];
                foreach ($savedSectors as $s) {
                    if (in_array($s, $sectorsList, true)) {
                        $selectedSectors[] = $s;
                    } else {
                        $custom[] = $s;
                    }
                }
                if ($custom) {
                    $sectorsOtherVal = implode(', ', $custom);
                }
            }

            // Prepare selected specifics & "other" text for editing
            $selectedSpecifics = [];
            $specificsOtherVal = '';
            if ($editLocation && !empty($editLocation['specifics_json'])) {
                $savedSpecifics = json_decode($editLocation['specifics_json'], true) ?: [];
                $customS = [];
                foreach ($savedSpecifics as $s) {
                    if (in_array($s, $projectSpecificList, true)) {
                        $selectedSpecifics[] = $s;
                    } else {
                        $customS[] = $s;
                    }
                }
                if ($customS) {
                    $specificsOtherVal = implode(', ', $customS);
                }
            }
            ?>

            <div class="form-group">
                <label>Main Programme Sectors (Multi-select)</label>
                <select name="sectors[]" multiple id="sectorsSelect">
                    <?php foreach ($sectorsList as $s): ?>
                        <option value="<?php echo h($s); ?>"
                            <?php echo in_array($s, $selectedSectors, true) ? 'selected' : ''; ?>>
                            <?php echo h($s); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__other__" <?php echo $sectorsOtherVal ? 'selected' : ''; ?>>
                        + Other / Add new Sector
                    </option>
                </select>
                <input type="text"
                       name="sectors_other"
                       id="sectorsOther"
                       placeholder="Type additional sectors or project-specific titles, separated by comma"
                       style="margin-top:4px; <?php echo $sectorsOtherVal ? '' : 'display:none;'; ?>"
                       value="<?php echo h($sectorsOtherVal); ?>">
            </div>

            <div class="form-group">
                <label>Project Specific Areas (Multi-select)</label>
                <select name="specifics[]" multiple id="specificsSelect">
                    <?php foreach ($projectSpecificList as $ps): ?>
                        <option value="<?php echo h($ps); ?>"
                            <?php echo in_array($ps, $selectedSpecifics, true) ? 'selected' : ''; ?>>
                            <?php echo h($ps); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__other__" <?php echo $specificsOtherVal ? 'selected' : ''; ?>>
                        + Other / Add new Specific Area
                    </option>
                </select>
                <input type="text"
                       name="specifics_other"
                       id="specificsOther"
                       placeholder="Type additional specific areas or project titles, separated by comma"
                       style="margin-top:4px; <?php echo $specificsOtherVal ? '' : 'display:none;'; ?>"
                       value="<?php echo h($specificsOtherVal); ?>">
            </div>
        </div>

        <h3>🧭 Administrative Location (Region → Zone → Woreda)</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Region</label>
                <select name="region_id" id="regionSelect" class="geo-region region-select"
                        data-selected="<?php echo $editLocation ? (int)$editLocation['region_id'] : ''; ?>">
                    <!-- Populated by JS -->
                </select>
                <input type="text" name="region_other" id="regionOther"
                       placeholder="If region not in the list, type here"
                       style="margin-top:4px; display:none;"
                       value="<?php echo $editLocation ? h($editLocation['region_name'] ?? '') : ''; ?>">
            </div>

            <div class="form-group">
                <label>Zone</label>
                <select name="zone_id" id="zoneSelect" class="geo-zone zone-select"
                        data-selected="<?php echo $editLocation ? (int)$editLocation['zone_id'] : ''; ?>">
                    <!-- Populated by JS -->
                </select>
                <input type="text" name="zone_other" id="zoneOther"
                       placeholder="If zone not in the list, type here"
                       style="margin-top:4px; display:none;"
                       value="<?php echo $editLocation ? h($editLocation['zone_name'] ?? '') : ''; ?>">
            </div>

            <div class="form-group">
                <label>Woreda</label>
                <select name="woreda_id" id="woredaSelect" class="geo-woreda woreda-select"
                        data-selected="<?php echo $editLocation ? (int)$editLocation['woreda_id'] : ''; ?>">
                    <!-- Populated by JS -->
                </select>
                <input type="text" name="woreda_other" id="woredaOther"
                       placeholder="If woreda not in the list, type here"
                       style="margin-top:4px; display:none;"
                       value="<?php echo $editLocation ? h($editLocation['woreda_name'] ?? '') : ''; ?>">
            </div>
        </div>

        <h3>📍 GPS Coordinates (Ethiopia Map)</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Latitude (decimal)</label>
                <input type="number" step="0.000001" name="latitude" id="latitude"
                       value="<?php echo $editLocation && $editLocation['latitude'] !== null ? h($editLocation['latitude']) : ''; ?>"
                       placeholder="Click on map or enter manually">
            </div>
            <div class="form-group">
                <label>Longitude (decimal)</label>
                <input type="number" step="0.000001" name="longitude" id="longitude"
                       value="<?php echo $editLocation && $editLocation['longitude'] !== null ? h($editLocation['longitude']) : ''; ?>"
                       placeholder="Click on map or enter manually">
            </div>
        </div>

        <div class="toolbar" style="margin-top:10px;">
            <button type="submit" name="save_location" class="btn btn-success">
                <?php echo $editLocation ? '💾 Update Location' : '💾 Save Location'; ?>
            </button>
            <?php if ($editLocation): ?>
                <a href="geography.php" class="btn btn-warning">➕ Add Another Location</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- MAP CARD -->
<div class="card" id="mapCard">
    <div id="mapContainer">
        <h3 id="mapTitle">Project Locations in Ethiopia (Region / Zone / Woreda & GPS)</h3>
        <div id="map"></div>
        <div class="export-group no-print">
            <button class="btn btn-light btn-sm" onclick="downloadMapImage()">🖼️ Download Map as Image (PNG)</button>
            <button class="btn btn-light btn-sm" onclick="downloadMapPDF()">📄 Download Map as PDF</button>
            <a id="externalMapLink" href="#" target="_blank" class="btn btn-light btn-sm">
                🌐 Open in Google Maps
            </a>
            <a id="osmMapLink" href="#" target="_blank" class="btn btn-light btn-sm">
                🗺️ Open in OpenStreetMap
            </a>
        </div>
    </div>
</div>

<!-- PROJECT LOCATION TABLE -->
<div class="card" id="locationTableCard">
    <div class="dashboard-header">
        <h2>📋 Project Location Registry (All Projects)</h2>
        <div class="export-group no-print">
            <button class="btn btn-success btn-sm" onclick="exportLocationsToExcel()">📊 Excel</button>
            <button class="btn btn-warning btn-sm" onclick="exportLocationsToPDF()">📄 PDF</button>
            <button class="btn btn-light btn-sm" onclick="exportLocationsToWord()">📝 Word</button>
            <button class="btn btn-light btn-sm" onclick="printLocationTable()">🖨️ Print</button>
        </div>
    </div>

    <div class="table-container" id="locationTableContainer">
        <table id="locationTable">
            <thead>
            <tr>
                <th>ID</th>
                <th>Project</th>
                <th>Region</th>
                <th>Zone</th>
                <th>Woreda</th>
                <th>Latitude</th>
                <th>Longitude</th>
                <th>Sector(s)</th>
                <th>Specific Areas</th>
                <th>Status</th>
                <th class="no-print">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($projectLocations): ?>
                <?php foreach ($projectLocations as $loc): ?>
                    <?php
                    $status = computeProjectStatus($loc['start_date'] ?? null, $loc['end_date'] ?? null);
                    $statusClass = $status === 'upcoming' ? 'status-badge-upcoming'
                        : ($status === 'closed' ? 'status-badge-closed' : 'status-badge-ongoing');

                    $sectors   = json_decode($loc['sectors_json'] ?? '[]', true) ?: [];
                    $specifics = json_decode($loc['specifics_json'] ?? '[]', true) ?: [];

                    $title = $loc['project_title'] ?? ('Project #'.$loc['project_id']);
                    $code  = $loc['project_code'] ?? '';

                    $rName = $loc['region_name_resolved'] ?? '';
                    $zName = $loc['zone_name_resolved'] ?? '';
                    $wName = $loc['woreda_name_resolved'] ?? '';
                    ?>
                    <tr>
                        <td><?php echo (int)$loc['id']; ?></td>
                        <td>
                            <strong><?php echo h($title); ?></strong><br>
                            <?php if ($code): ?>
                                <small>Code: <?php echo h($code); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h($rName); ?></td>
                        <td><?php echo h($zName); ?></td>
                        <td><?php echo h($wName); ?></td>
                        <td><?php echo $loc['latitude'] !== null ? h($loc['latitude']) : ''; ?></td>
                        <td><?php echo $loc['longitude'] !== null ? h($loc['longitude']) : ''; ?></td>
                        <td>
                            <div class="chips">
                                <?php foreach ($sectors as $s): ?>
                                    <span class="chip"><?php echo h($s); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <div class="chips">
                                <?php foreach ($specifics as $s): ?>
                                    <span class="chip"><?php echo h($s); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?php echo $statusClass; ?>">
                                <?php echo ucfirst($status); ?>
                            </span>
                        </td>
                        <td class="no-print">
                            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                <a href="geography.php?edit_location_id=<?php echo (int)$loc['id']; ?>"
                                   class="btn btn-sm">✏️ Edit</a>
                                <form method="post"
                                      onsubmit="return confirm('Delete this project location from the map?');">
                                    <input type="hidden" name="id" value="<?php echo (int)$loc['id']; ?>">
                                    <button type="submit" name="delete_location" class="btn btn-danger btn-sm">🗑️</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11">📭 No project locations registered yet. Use the form above to add the first one.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ANALYTICS -->
<div class="card" id="analyticsCard">
    <div class="dashboard-header">
        <h2>📊 Geographic Location Analytics</h2>
        <div class="export-group no-print">
            <button class="btn btn-success btn-sm" onclick="exportAnalyticsToExcel()">📊 Excel</button>
            <button class="btn btn-warning btn-sm" onclick="exportAnalyticsToPDF()">📄 PDF</button>
            <button class="btn btn-light btn-sm" onclick="exportAnalyticsToWord()">📝 Word</button>
            <button class="btn btn-light btn-sm" onclick="downloadAnalyticsImage()">🖼️ Image (PNG)</button>
            <button class="btn btn-light btn-sm" onclick="printAnalytics()">🖨️ Print</button>
        </div>
    </div>

    <div class="stats-cards">
        <div class="stat-card" style="background:linear-gradient(135deg,#1abc9c,#16a085);">
            <h3>Unique Zones Covered</h3>
            <div class="value"><?php echo max(0, count($statsZone)); ?></div>
            <div>Administrative zones reached</div>
        </div>
        <div class="stat-card" style="background:linear-gradient(135deg,#c0392b,#e74c3c);">
            <h3>Unique Woredas Covered</h3>
            <div class="value"><?php echo max(0, count($statsWoreda)); ?></div>
            <div>Operational woredas</div>
        </div>
        <div class="stat-card" style="background:linear-gradient(135deg,#34495e,#2c3e50);">
            <h3>Programme Sectors</h3>
            <div class="value"><?php echo max(0, count($statsSector)); ?></div>
            <div>Sectors engaged</div>
        </div>
        <div class="stat-card" style="background:linear-gradient(135deg,#f1c40f,#f39c12);">
            <h3>Upcoming Projects</h3>
            <div class="value"><?php echo (int)($statsStatus['upcoming'] ?? 0); ?></div>
            <div>Planned but not yet started</div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-top:20px;">
        <div>
            <h3>Projects by Region</h3>
            <canvas id="chartProjectsRegion"></canvas>
        </div>
        <div>
            <h3>Projects by Sector</h3>
            <canvas id="chartProjectsSector"></canvas>
        </div>
        <div>
            <h3>Project Status (Upcoming / Ongoing / Closed)</h3>
            <canvas id="chartProjectsStatus"></canvas>
        </div>
    </div>
</div>

<script>
// --------- DATA FROM PHP FOR JS -------------
const geoData = {
    regions: <?php echo json_encode($regions, JSON_UNESCAPED_UNICODE); ?>,
    zones:   <?php echo json_encode($zones,   JSON_UNESCAPED_UNICODE); ?>,
    woredas: <?php echo json_encode($woredas, JSON_UNESCAPED_UNICODE); ?>
};

const mapLocations = <?php echo json_encode($mapLocations, JSON_UNESCAPED_UNICODE); ?>;
const statsRegion  = <?php echo json_encode($statsRegion, JSON_UNESCAPED_UNICODE); ?>;
const statsSector  = <?php echo json_encode($statsSector, JSON_UNESCAPED_UNICODE); ?>;
const statsStatus  = <?php echo json_encode($statsStatus, JSON_UNESCAPED_UNICODE); ?>;

// --------- HELPERS -------------

function scrollToSection(id) {
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({behavior: 'smooth'});
}

// Populate dependent dropdowns
function populateRegions() {
    const select = document.getElementById('regionSelect');
    if (!select) return;
    const selected = select.getAttribute('data-selected') || '';

    select.innerHTML = '';
    let opt = document.createElement('option');
    opt.value = '';
    opt.textContent = '-- Select Region --';
    select.appendChild(opt);

    geoData.regions.forEach(r => {
        const o = document.createElement('option');
        o.value = r.id;
        o.textContent = r.name + (r.code ? ' (' + r.code + ')' : '');
        if (selected && String(selected) === String(r.id)) {
            o.selected = true;
        }
        select.appendChild(o);
    });

    const oOther = document.createElement('option');
    oOther.value = '__other__';
    oOther.textContent = '+ Other / Add new Region';
    select.appendChild(oOther);
}

function populateZones() {
    const regionSelect = document.getElementById('regionSelect');
    const zoneSelect   = document.getElementById('zoneSelect');
    if (!regionSelect || !zoneSelect) return;

    const selectedZone = zoneSelect.getAttribute('data-selected') || '';
    const regionId     = regionSelect.value;

    zoneSelect.innerHTML = '';
    let opt = document.createElement('option');
    opt.value = '';
    opt.textContent = regionId && regionId !== '__other__'
        ? '-- Select Zone --'
        : '-- Select Region first or add new --';
    zoneSelect.appendChild(opt);

    if (regionId && regionId !== '__other__') {
        geoData.zones
            .filter(z => String(z.region_id) === String(regionId))
            .forEach(z => {
                const o = document.createElement('option');
                o.value = z.id;
                o.textContent = z.name + (z.code ? ' (' + z.code + ')' : '');
                if (selectedZone && String(selectedZone) === String(z.id)) {
                    o.selected = true;
                }
                zoneSelect.appendChild(o);
            });
    }

    const oOther = document.createElement('option');
    oOther.value = '__other__';
    oOther.textContent = '+ Other / Add new Zone';
    zoneSelect.appendChild(oOther);
}

function populateWoredas() {
    const zoneSelect   = document.getElementById('zoneSelect');
    const woredaSelect = document.getElementById('woredaSelect');
    if (!zoneSelect || !woredaSelect) return;

    const selectedWoreda = woredaSelect.getAttribute('data-selected') || '';
    const zoneId         = zoneSelect.value;

    woredaSelect.innerHTML = '';
    let opt = document.createElement('option');
    opt.value = '';
    opt.textContent = zoneId && zoneId !== '__other__'
        ? '-- Select Woreda --'
        : '-- Select Zone first or add new --';
    woredaSelect.appendChild(opt);

    if (zoneId && zoneId !== '__other__') {
        geoData.woredas
            .filter(w => String(w.zone_id) === String(zoneId))
            .forEach(w => {
                const o = document.createElement('option');
                o.value = w.id;
                o.textContent = w.name + (w.code ? ' (' + w.code + ')' : '');
                if (selectedWoreda && String(selectedWoreda) === String(w.id)) {
                    o.selected = true;
                }
                woredaSelect.appendChild(o);
            });
    }

    const oOther = document.createElement('option');
    oOther.value = '__other__';
    oOther.textContent = '+ Other / Add new Woreda';
    woredaSelect.appendChild(oOther);
}

function toggleRegionOther() {
    const regionSelect = document.getElementById('regionSelect');
    const regionOther  = document.getElementById('regionOther');
    if (!regionSelect || !regionOther) return;
    regionOther.style.display = regionSelect.value === '__other__' ? 'block' : 'none';
}

function toggleZoneOther() {
    const zoneSelect = document.getElementById('zoneSelect');
    const zoneOther  = document.getElementById('zoneOther');
    if (!zoneSelect || !zoneOther) return;
    zoneOther.style.display = zoneSelect.value === '__other__' ? 'block' : 'none';
}

function toggleWoredaOther() {
    const woredaSelect = document.getElementById('woredaSelect');
    const woredaOther  = document.getElementById('woredaOther');
    if (!woredaSelect || !woredaOther) return;
    woredaOther.style.display = woredaSelect.value === '__other__' ? 'block' : 'none';
}

// Sectors / specifics "Other" toggles
function toggleSectorOther() {
    const sel = document.getElementById('sectorsSelect');
    const other = document.getElementById('sectorsOther');
    if (!sel || !other) return;
    const values = Array.from(sel.selectedOptions).map(o => o.value);
    other.style.display = values.includes('__other__') ? 'block' : 'none';
}

function toggleSpecificOther() {
    const sel = document.getElementById('specificsSelect');
    const other = document.getElementById('specificsOther');
    if (!sel || !other) return;
    const values = Array.from(sel.selectedOptions).map(o => o.value);
    other.style.display = values.includes('__other__') ? 'block' : 'none';
}

// --------- MAP INITIALISATION -------------

let map, marker;

function initMap() {
    // Ethiopia approximate center
    map = L.map('map').setView([9.145, 40.4897], 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    // Existing project markers
    const bounds = [];
    mapLocations.forEach(loc => {
        const color = loc.status === 'closed'
            ? 'gray'
            : (loc.status === 'upcoming' ? 'purple' : 'green');

        const icon = L.divIcon({
            className: 'custom-marker',
            html: `<div style="background:${color};width:14px;height:14px;border-radius:50%;border:2px solid white;box-shadow:0 0 4px rgba(0,0,0,0.4);"></div>`,
            iconSize: [14, 14]
        });

        const m = L.marker([loc.lat, loc.lng], {icon}).addTo(map);
        m.bindPopup(`
            <strong>${loc.project_title}</strong><br/>
            ${loc.project_code ? 'Code: ' + loc.project_code + '<br/>' : ''}
            Region: ${loc.region_name || ''}<br/>
            Zone: ${loc.zone_name || ''}<br/>
            Woreda: ${loc.woreda_name || ''}<br/>
            Status: ${loc.status.charAt(0).toUpperCase() + loc.status.slice(1)}
        `);
        bounds.push([loc.lat, loc.lng]);
    });

    if (bounds.length) {
        map.fitBounds(bounds, {padding: [30, 30]});
    }

    // Allow clicking on map to set coordinates
    map.on('click', function (e) {
        if (marker) {
            map.removeLayer(marker);
        }
        marker = L.marker(e.latlng).addTo(map);
        document.getElementById('latitude').value = e.latlng.lat.toFixed(6);
        document.getElementById('longitude').value = e.latlng.lng.toFixed(6);
        updateExternalMapLinks(e.latlng.lat, e.latlng.lng);
    });

    // If editing a location with coordinates, center on it
    const latInput = document.getElementById('latitude').value;
    const lngInput = document.getElementById('longitude').value;
    if (latInput && lngInput) {
        const lat = parseFloat(latInput);
        const lng = parseFloat(lngInput);
        marker = L.marker([lat, lng]).addTo(map);
        map.setView([lat, lng], 9);
        updateExternalMapLinks(lat, lng);
    } else {
        updateExternalMapLinks(9.145, 40.4897);
    }
}

function updateExternalMapLinks(lat, lng) {
    const gLink = document.getElementById('externalMapLink');
    const oLink = document.getElementById('osmMapLink');
    const coordsStr = lat.toFixed(6) + ',' + lng.toFixed(6);

    if (gLink) {
        gLink.href = 'https://www.google.com/maps?q=' + encodeURIComponent(coordsStr);
    }
    if (oLink) {
        oLink.href = 'https://www.openstreetmap.org/?mlat=' + lat.toFixed(6) +
            '&mlon=' + lng.toFixed(6) + '#map=12/' + lat.toFixed(6) + '/' + lng.toFixed(6);
    }
}

// --------- MAP EXPORT (PNG/PDF) -------------

function downloadMapImage() {
    const mapContainer = document.getElementById('mapContainer');
    if (!mapContainer) return;

    html2canvas(mapContainer).then(canvas => {
        const link = document.createElement('a');
        link.download = 'project_map.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
}

function downloadMapPDF() {
    const mapContainer = document.getElementById('mapContainer');
    if (!mapContainer) return;

    html2canvas(mapContainer).then(canvas => {
        const imgData = canvas.toDataURL('image/png');
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('landscape', 'mm', 'a4');
        const pdfWidth = pdf.internal.pageSize.getWidth();
        const pdfHeight = canvas.height * pdfWidth / canvas.width;
        pdf.addImage(imgData, 'PNG', 0, 10, pdfWidth, pdfHeight);
        pdf.save('project_map.pdf');
    });
}

// --------- TABLE EXPORTS / PRINT -------------

function exportLocationsToExcel() {
    const table = document.getElementById('locationTable');
    if (!table) return;
    const wb = XLSX.utils.table_to_book(table, {sheet: 'Project Locations'});
    XLSX.writeFile(wb, 'project_locations.xlsx');
}

function exportLocationsToPDF() {
    const tableContainer = document.getElementById('locationTableContainer');
    if (!tableContainer) return;
    html2canvas(tableContainer).then(canvas => {
        const imgData = canvas.toDataURL('image/png');
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('landscape', 'mm', 'a4');
        const pdfWidth = pdf.internal.pageSize.getWidth();
        const pdfHeight = canvas.height * pdfWidth / canvas.width;
        pdf.addImage(imgData, 'PNG', 0, 10, pdfWidth, pdfHeight);
        pdf.save('project_locations.pdf');
    });
}

function exportLocationsToWord() {
    const tableContainer = document.getElementById('locationTableContainer');
    if (!tableContainer) return;

    const html = `
        <html>
        <head><meta charset="utf-8"></head>
        <body>
            <h2>Project Location Registry - Nexus Ethiopia</h2>
            ${tableContainer.innerHTML}
        </body>
        </html>
    `;
    const blob = new Blob(['\\ufeff', html], {type: 'application/msword'});
    const url  = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'project_locations.doc';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function printLocationTable() {
    const tableCard = document.getElementById('locationTableCard');
    if (!tableCard) return;
    const original = document.body.innerHTML;
    document.body.innerHTML = tableCard.outerHTML;
    window.print();
    document.body.innerHTML = original;
    window.location.reload();
}

// --------- ANALYTICS (CHARTS) -------------

function initCharts() {
    // Region chart
    const regionLabels = Object.keys(statsRegion);
    const regionValues = Object.values(statsRegion);
    if (regionLabels.length && document.getElementById('chartProjectsRegion')) {
        const ctxRegion = document.getElementById('chartProjectsRegion').getContext('2d');
        new Chart(ctxRegion, {
            type: 'bar',
            data: {
                labels: regionLabels,
                datasets: [{
                    label: 'Projects per Region',
                    data: regionValues
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {display: false}
                },
                scales: {
                    x: {ticks: {autoSkip: false}}
                }
            }
        });
    }

    // Sector chart
    const sectorLabels = Object.keys(statsSector);
    const sectorValues = Object.values(statsSector);
    if (sectorLabels.length && document.getElementById('chartProjectsSector')) {
        const ctxSector = document.getElementById('chartProjectsSector').getContext('2d');
        new Chart(ctxSector, {
            type: 'doughnut',
            data: {
                labels: sectorLabels,
                datasets: [{
                    label: 'Projects per Sector',
                    data: sectorValues
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {position: 'bottom'}
                }
            }
        });
    }

    // Status chart
    if (document.getElementById('chartProjectsStatus')) {
        const ctxStatus = document.getElementById('chartProjectsStatus').getContext('2d');
        const labels = ['Upcoming', 'Ongoing', 'Closed'];
        const values = [
            statsStatus.upcoming || 0,
            statsStatus.ongoing  || 0,
            statsStatus.closed   || 0
        ];
        new Chart(ctxStatus, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Projects by Status',
                    data: values
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: {
                    legend: {display: false}
                }
            }
        });
    }
}

// --------- ANALYTICS EXPORTS / PRINT -------------

function downloadAnalyticsImage() {
    const card = document.getElementById('analyticsCard');
    if (!card) return;
    html2canvas(card).then(canvas => {
        const link = document.createElement('a');
        link.download = 'location_analytics.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
}

function exportAnalyticsToPDF() {
    const card = document.getElementById('analyticsCard');
    if (!card) return;
    html2canvas(card).then(canvas => {
        const imgData = canvas.toDataURL('image/png');
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('landscape', 'mm', 'a4');
        const pdfWidth = pdf.internal.pageSize.getWidth();
        const pdfHeight = canvas.height * pdfWidth / canvas.width;
        pdf.addImage(imgData, 'PNG', 0, 10, pdfWidth, pdfHeight);
        pdf.save('location_analytics.pdf');
    });
}

function exportAnalyticsToWord() {
    const card = document.getElementById('analyticsCard');
    if (!card) return;
    const html = `
        <html>
        <head><meta charset="utf-8"></head>
        <body>
            <h2>Geographic Location Analytics - Nexus Ethiopia</h2>
            ${card.innerHTML}
        </body>
        </html>
    `;
    const blob = new Blob(['\\ufeff', html], {type: 'application/msword'});
    const url  = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'location_analytics.doc';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function exportAnalyticsToExcel() {
    // Build simple sheets from statsRegion, statsSector, statsStatus
    const wb = XLSX.utils.book_new();

    const regionData = [['Region', 'Projects']];
    Object.entries(statsRegion).forEach(([k, v]) => regionData.push([k, v]));
    const wsRegion = XLSX.utils.aoa_to_sheet(regionData);
    XLSX.utils.book_append_sheet(wb, wsRegion, 'ByRegion');

    const sectorData = [['Sector', 'Projects']];
    Object.entries(statsSector).forEach(([k, v]) => sectorData.push([k, v]));
    const wsSector = XLSX.utils.aoa_to_sheet(sectorData);
    XLSX.utils.book_append_sheet(wb, wsSector, 'BySector');

    const statusData = [['Status', 'Projects']];
    statusData.push(['Upcoming', statsStatus.upcoming || 0]);
    statusData.push(['Ongoing',  statsStatus.ongoing  || 0]);
    statusData.push(['Closed',   statsStatus.closed   || 0]);
    const wsStatus = XLSX.utils.aoa_to_sheet(statusData);
    XLSX.utils.book_append_sheet(wb, wsStatus, 'ByStatus');

    XLSX.writeFile(wb, 'location_analytics.xlsx');
}

function printAnalytics() {
    const card = document.getElementById('analyticsCard');
    if (!card) return;
    const original = document.body.innerHTML;
    document.body.innerHTML = card.outerHTML;
    window.print();
    document.body.innerHTML = original;
    window.location.reload();
}

// --------- INIT ON LOAD -------------

document.addEventListener('DOMContentLoaded', () => {
    populateRegions();
    populateZones();
    populateWoredas();
    toggleRegionOther();
    toggleZoneOther();
    toggleWoredaOther();
    toggleSectorOther();
    toggleSpecificOther();

    const regionSelect = document.getElementById('regionSelect');
    const zoneSelect   = document.getElementById('zoneSelect');
    const woredaSelect = document.getElementById('woredaSelect');
    const sectorsSelect = document.getElementById('sectorsSelect');
    const specificsSelect = document.getElementById('specificsSelect');

    if (regionSelect) {
        regionSelect.addEventListener('change', () => {
            toggleRegionOther();
            populateZones();
            populateWoredas();
        });
    }
    if (zoneSelect) {
        zoneSelect.addEventListener('change', () => {
            toggleZoneOther();
            populateWoredas();
        });
    }
    if (woredaSelect) {
        woredaSelect.addEventListener('change', () => {
            toggleWoredaOther();
        });
    }
    if (sectorsSelect) {
        sectorsSelect.addEventListener('change', toggleSectorOther);
    }
    if (specificsSelect) {
        specificsSelect.addEventListener('change', toggleSpecificOther);
    }

    initMap();
    initCharts();
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
</body>
</html>
