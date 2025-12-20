<?php
// pages/nexus_ai.php
// Nexus AI Assistant – Knowledge / Help Center for SMART Nexus Platform

// ---------------------------------------------------------------------
// 0. Smart require: safely load helpers, header, footer from root/pages
// ---------------------------------------------------------------------

$rootPath = dirname(__DIR__); // e.g. C:\xampp\htdocs\health_reporting_system

if (!function_exists('smart_require_once')) {
    /**
     * Try several candidate paths and require the first that exists.
     */
    function smart_require_once(string $label, array $candidates): void
    {
        $tried = [];
        foreach ($candidates as $path) {
            $tried[] = $path;
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }

        $msg = $label . " file not found. Tried:\n" . implode("\n", $tried);
        throw new Exception($msg);
    }
}

// Load helpers.php first (gives us all shared functions/classes/constants)
smart_require_once('helpers.php', [
    $rootPath . '/helpers.php',
    __DIR__ . '/helpers.php',
    $rootPath . '/includes/helpers.php',
]);

require_login();
require_permission('nexus_ai', 'view');

// Configure print/export header for this app
setup_app_printing(
    'Nexus AI – System Knowledge & Assistant',
    'Interactive knowledge base for SMART Nexus / Nexus Ethiopia'
);

// Current user & system status
$user         = current_user();
$systemStatus = get_system_status();

// ---------------------------------------------------------------------
// 1. Build knowledge base: system, apps, roles, guides, Nexus profile
// ---------------------------------------------------------------------

// Safeguard constants
$appName    = defined('APP_NAME')    ? APP_NAME    : 'Nexus Ethiopia – SMART Health Reporting System';
$appVersion = defined('APP_VERSION') ? APP_VERSION : '2.4';
$appEnv     = defined('APP_ENV')     ? APP_ENV     : 'production';

// System-level knowledge
$systemInfo = [
    'name'        => $appName,
    'version'     => $appVersion,
    'environment' => $appEnv,
    'developer'   => 'Nexus Ethiopia (MEALR / IT team)',
    'core_admin'  => [
        [
            'name'  => 'Amenti (Amoo)',
            'role'  => 'System developer and administrator',
            'about' => 'Primary developer and admin of the SMART Nexus project monitoring and reporting system; Program / MEALR Manager at Nexus Ethiopia.'
        ]
    ],
    'purpose'     => 'A SMART project performance and health reporting platform for Nexus Ethiopia, supporting planning, data entry, SADD aggregation, reporting, complaint and feedback management and data quality.',
    'objectives'  => [
        'Standardize monthly and quarterly reporting across Nexus Ethiopia projects.',
        'Improve data quality, SADD disaggregation and accountability.',
        'Support evidence based decision making and donor reporting (UNOCHA, EHF, UNICEF and others).',
        'Provide an integrated platform where all modules share the same projects, indicators and geography.',
        'Serve as a practical MEALR tool for field teams, project managers and senior management.'
    ],
    'significance' => [
        'Reduces manual Excel work and fragmented reporting tools.',
        'Ensures one shared source of truth for project indicators and beneficiaries.',
        'Supports MEALR functions: Monitoring, Evaluation, Accountability, Learning and Research.',
        'Improves transparency, localization and internal coordination across regions and sectors.',
        'Links reported results with complaint and feedback mechanisms where relevant.'
    ],
    'how_to_use' => [
        '1. Login with your Nexus Ethiopia user account.',
        '2. Register or select a project in the Projects app.',
        '3. Configure indicators, geography and planning (logframe and workplan).',
        '4. Use Enter data for monthly or quarterly reporting with SADD and persons with disabilities disaggregation.',
        '5. View, print and export results via View report, Custom report, Aggregation, Pivot and Progress apps.',
        '6. Record and track community feedback via the Complaint and Feedback Mechanism (CFM) app.',
        '7. Use Nexus AI (this page) to learn about modules, roles, Nexus Ethiopia profile, workflows and good practices.'
    ],
];

// --- Nexus Ethiopia profile (from organizational profile document and manual) ---

$nexusProfile = [
    'general_info' => [
        'name'    => 'Nexus Ethiopia (NEXUS)',
        'type'    => 'National non governmental, non profit humanitarian and development organization',
        'address' => [
            'city'      => 'Addis Ababa City Administration',
            'sub_city'  => 'Bole Sub City, Woreda 7, Gurd Shola Area',
            'po_box'    => 'P.O. Box 21620/1000 Addis Ababa, Ethiopia',
            'telephone' => '+251 11 666 12 19',
            'emails'    => ['nexuseth22@gmail.com', 'contact@nexuseth.org'],
            'website'   => 'www.nexuseth.org',
            'socials'   => [
                'Facebook' => 'Nexus Ethiopia',
                'Twitter'  => '@NexusEthiopia',
                'LinkedIn' => 'Nexus Ethiopia company page'
            ],
        ],
        'registrations' => [
            'CSO_registration' => '6225',
            'TIN'              => '0078940162',
            'UEI'              => 'EM5VUG3BL5X9',
            'EU_PIC'           => '885824565',
            'PADOR'            => 'ET-2023-CVF-2004140765',
            'UNGM'             => '913023',
            'UNPP'             => '25686',
            'NCAGE'            => 'SNQY2',
            'USAID_portals'    => [
                'grants.gov',
                'BHA AAMP'
            ],
        ],
        'contact_person' => [
            'name'   => 'Fiseha Mezgebu',
            'title'  => 'Executive Director',
            'mobile' => '+251 913 618 247',
            'email'  => 'fiseha.m@nexuseth.org',
        ],
        'mandate' => 'Registered as a national NGO with a mandate to operate in all regions and city administrations of Ethiopia under CSO Proclamation 1113/19.',
    ],
    'vision'  => 'To see resilience capacities of vulnerable populations increased by integrating the Humanitarian, Development and Peace nexus pillars to attain sustainable development in Ethiopia.',
    'mission' => 'To enhance the resilience capacities of vulnerable populations and deliver integrated and holistic HDP Nexus programmes to attain inclusive sustainable development.',
    'core_values' => [
        'Accountability and transparency',
        'Inclusivity and non partisanship',
        'Efficiency in use of resources',
        'Independence and neutrality',
        'Integrity and mutual trust',
        'Professionalism and respect for laws and ethics',
    ],
    'strategic_objectives' => [
        'Deliver lifesaving multisectoral humanitarian response targeting the most affected and hard to reach populations.',
        'Support sustainable development through resilience building and empowerment of targeted populations.',
        'Promote peace building, reconciliation and social cohesion.',
        'Enhance capacity and coordination among national NGOs for localized response.'
    ],
    'thematic_areas' => [
        'Health' => 'Primary health care, mobile health and nutrition teams, mental health and psychosocial support, outbreak response, WaSH in health facilities, sexual and reproductive health, harmful practices and data management.',
        'WaSH'   => 'Water access, sanitation and hygiene promotion including community led total sanitation and hygiene, waste management and value chains, WaSH non food items, facility rehabilitation, water trucking and WaSH in institutions.',
        'Education' => 'Access and quality, construction and rehabilitation of schools and early learning centres, scholastic support, school feeding, girls education, education in emergencies, capacity building, ICT and life skills, violence prevention.',
        'Nutrition' => 'Community management of acute malnutrition, targeted supplementary feeding, infant and young child feeding promotion, screening and referral and nutrition sensitive interventions.',
        'Livelihoods and Food Security' => 'Business skills, self help groups and village savings and loan associations, cooperatives, market linkages, agricultural inputs, cash support, backyard gardening, small scale irrigation and rural finance.',
        'Climate and Environment' => 'Renewable energy, environmental conservation, soil and water conservation, agroforestry, natural resource management and climate adaptation awareness.',
        'Peace Building' => 'Mental health and psychosocial support and trauma healing, local conflict resolution mechanisms, youth engagement, religious and clan leader engagement, community dialogues and cultural or sport events for peace.',
        'ES/NFI' => 'Emergency shelter and non food item kits, shelter maintenance, cash or voucher assistance, housing land and property assessments and protection mainstreaming.',
        'Protection' => 'Gender based violence prevention and response, integrated services for survivors, mental health and psychosocial support, case management, child protection, reunification and local capacity building.',
        'NNGO Capacity Development' => 'Capacity assessments, tailored training, localization task forces, coordination meetings and collaboration with donors, international NGOs and universities.'
    ],
    'regions_of_operation' => [
        'Afar',
        'Amhara',
        'Benishangul Gumuz',
        'Oromia',
        'Somali',
        'Tigray',
        'Gambela',
    ],
    'current_donors' => [
        'AmplifyChange',
        'UNOCHA / Ethiopia Humanitarian Fund via multiple partners',
        'Ethiopiaid UK',
        'Welthungerhilfe (German Agro Action)',
        'Humedica International Aid',
        'International Rescue Committee',
        'PACT Ethiopia / USAID',
        'People in Need',
        'Positive Action for Development',
        'Helvetas',
        'SOS Children’s Village Ethiopia',
    ],
    'sample_projects' => [
        'Emergency shelter and non food item responses in Adwa and Sheraro woredas in Tigray.',
        'Education in emergencies and WaSH in Abaala woreda in Afar.',
        'Integrated health support for survivors of gender based violence in Adwa in Tigray.',
        'Sexual and reproductive health response in Adwa with AmplifyChange.',
        'Food security and livelihoods support in Adwa with Ethiopiaid UK.',
        'Sustainable peace project in Shire with PACT and USAID.',
        'Mobile health and nutrition responses in Afar, Somali, Oromia and Amhara.',
        'Support to survivors of gender based violence in Benishangul Gumuz.',
        'Local civil society capacity building with IRC.',
        'Integrated WaSH response in internally displaced camps in Tigray.',
    ],
];

// App descriptions / knowledge
$appLabels = defined('APP_KEYS') ? APP_KEYS : [];
$appKnowledge = [];

// Base descriptions (detailed, including purpose, risks and practices)
$baseAppDescriptions = [
    'dashboard' => [
        'what'          => 'A high level visual overview of project performance, SADD reach and key alerts.',
        'contains'      => 'Widgets for key indicators, summaries of beneficiaries reached, trend charts, quick statistics and shortcuts to detailed apps.',
        'how_to_use'    => 'Open the dashboard, select the project or date range if filters are available and review graphs and numbers, then use links to drill down to detailed reports.',
        'when_to_use'   => 'During coordination meetings, management briefings and weekly or monthly follow up.',
        'who_uses'      => 'Project managers, MEAL staff and senior leadership.',
        'why_necessary' => 'Helps staff and management see trends and progress quickly without reading long tables.',
        'risks'         => 'If filters are wrong, users may misinterpret charts, or miss data gaps that are hidden by high level views.',
        'tips_do'       => 'Always check filters, and use the dashboard as a starting point before reading detailed reports.',
        'tips_dont'     => 'Do not base major decisions only on one chart without checking the underlying data.'
    ],
    'projects' => [
        'what'          => 'Central project registration and metadata management.',
        'contains'      => 'Project title, code, donor, sectors, locations, time frame, budget, status, focal person and description.',
        'how_to_use'    => 'For each Nexus project create one record, fill all required metadata and link it with indicators, planning and data entry modules.',
        'when_to_use'   => 'During project design and start up and whenever core project information changes.',
        'who_uses'      => 'MEALR manager, project coordinators and system admins.',
        'why_necessary' => 'Ensures each project has a single consistent source of truth that links reporting with donor, budgets, sectors and geography.',
        'risks'         => 'Duplicate projects or wrong donor or dates lead to incorrect reporting and confusion.',
        'tips_do'       => 'Confirm project details with official project documents and update the system when parameters change.',
        'tips_dont'     => 'Do not create many test projects in production or leave critical fields empty.'
    ],
    'planning' => [
        'what'          => 'Logframe and workplan planning module.',
        'contains'      => 'Objectives, outcomes, outputs, activities, indicators, targets and timelines per project.',
        'how_to_use'    => 'Build the project results framework and link indicators and targets so reports can compare achievement against plan.',
        'when_to_use'   => 'During proposal development, project start up and at major revisions.',
        'who_uses'      => 'MEALR manager, technical coordinators and project managers.',
        'why_necessary' => 'Links activities and outputs to indicators and targets, which is required for progress versus target analysis.',
        'risks'         => 'Incomplete logframes lead to missing indicators, and wrong targets give misleading progress percentages.',
        'tips_do'       => 'Align the logframe with the signed project documents and involve technical specialists when setting targets.',
        'tips_dont'     => 'Do not change targets without documentation and donor agreement where required.'
    ],
    'budget' => [
        'what'          => 'Budget planning and overview module where enabled.',
        'contains'      => 'Budget lines, total budget per output or activity, donor allocations and simple summary comparisons.',
        'how_to_use'    => 'Capture high level budget information for internal analysis and alignment with indicators.',
        'when_to_use'   => 'During proposal development and financial review discussions.',
        'who_uses'      => 'Finance team, project managers and MEALR for linking resources to results.',
        'why_necessary' => 'Enables linking financial resources with results and simple value for money discussions.',
        'risks'         => 'Incorrect budget figures cause confusion in reports and audits.',
        'tips_do'       => 'Coordinate entries with the finance department and use the budget module as a high level overview only.',
        'tips_dont'     => 'Do not use this module as a full accounting system or enter very detailed line by line transactions if the system is not designed for that.'
    ],
    'geography' => [
        'what'          => 'Central database of regions, zones and woredas used by all modules.',
        'contains'      => 'Standardized lists of region, zone and woreda with IDs shared across the system.',
        'how_to_use'    => 'Admins keep the list up to date and all projects and reports select from these standardized locations.',
        'when_to_use'   => 'During system setup and when new areas or new administrative units are added or corrected.',
        'who_uses'      => 'System admins and MEALR staff who manage geographic data.',
        'why_necessary' => 'Prevents data fragmentation due to different spellings or codes and ensures reports can be aggregated correctly by geography.',
        'risks'         => 'Duplicate or wrongly spelled locations can cause double counting, missing data or mismatches with government or cluster maps.',
        'tips_do'       => 'Use official administrative names, coordinate changes with MEALR and program teams and test dropdowns after edits.',
        'tips_dont'     => 'Do not randomly rename or delete locations that already have data without a clear migration plan.'
    ],
    'indicators' => [
        'what'          => 'Indicator catalogue for the whole system.',
        'contains'      => 'Indicator name, level, unit, SADD rules, baseline, target and narrative description.',
        'how_to_use'    => 'Define each indicator once, then link it to projects and planning so data entry and reporting are consistent.',
        'when_to_use'   => 'During MEAL plan and logframe design and when new indicators are introduced.',
        'who_uses'      => 'MEALR manager, monitoring and evaluation officers and technical leads.',
        'why_necessary' => 'Ensures the same indicator is used consistently across projects and reports and avoids confusion in naming and definitions.',
        'risks'         => 'Creating many similar indicators for the same concept or changing indicator meaning mid project without documentation.',
        'tips_do'       => 'Align indicators with project logframes, national or cluster indicators and donor requirements and document changes.',
        'tips_dont'     => 'Do not delete indicators that already have data or change definitions silently.'
    ],
    'enter_data' => [
        'what'          => 'Main data entry module for indicators and SADD.',
        'contains'      => 'Forms per project, indicator, location and period with full age and sex disaggregation and a separate persons with disabilities table.',
        'how_to_use'    => 'Select project, period, location and indicator, enter disaggregated beneficiary numbers, review totals and save.',
        'when_to_use'   => 'Every reporting cycle and when correcting previous data with proper documentation.',
        'who_uses'      => 'Project officers, monitoring and evaluation officers and data clerks.',
        'why_necessary' => 'Provides the primary data that feeds all reports, aggregation and progress analyses, including SADD and disability data.',
        'risks'         => 'Typing errors, missing disaggregation or duplicate entries can seriously distort reporting.',
        'tips_do'       => 'Use original data sources such as registers or Kobo exports, review entries before saving and respect reporting deadlines.',
        'tips_dont'     => 'Do not guess numbers, enter unverified drafts or create duplicates for the same indicator, period and location.'
    ],
    'view_reports' => [
        'what'          => 'Standard project report views.',
        'contains'      => 'Predefined tables of indicators by period and location with totals and simple summaries.',
        'how_to_use'    => 'Filter by project, period and location and generate standard outputs for internal review and donor reports.',
        'when_to_use'   => 'For routine monthly or quarterly reporting and narrative report annexes.',
        'who_uses'      => 'MEALR manager, project managers and reporting staff.',
        'why_necessary' => 'Provides consistent tables that are easy to understand and reuse in many reports.',
        'risks'         => 'Using wrong filters or confusing cumulative and period specific values.',
        'tips_do'       => 'Always note which filters you used and confirm whether a report is cumulative or for a single period.',
        'tips_dont'     => 'Do not copy tables into donor reports without checking that filters match the narrative.'
    ],
    'custom_report' => [
        'what'          => 'Advanced report builder with flexible filters.',
        'contains'      => 'Customizable tables, SADD views and filters by project, region, zone, woreda, indicator and beneficiary type with export options.',
        'how_to_use'    => 'Select filters, generate the table and adjust until it matches donor or internal analysis needs, then export.',
        'when_to_use'   => 'When standard reports do not match donor templates or specific analysis tasks.',
        'who_uses'      => 'MEALR staff, data analysts and project managers and report writers.',
        'why_necessary' => 'Allows Nexus Ethiopia to respond to diverse donor and cluster formats without building a new report for each case.',
        'risks'         => 'Complex filters can be misunderstood and lead to under or over reporting if not documented.',
        'tips_do'       => 'Save or record the filters when sharing custom outputs and validate results against simpler reports.',
        'tips_dont'     => 'Do not share complex custom tables without explaining what they represent.'
    ],
    'aggregation' => [
        'what'          => 'Aggregation across projects, locations and periods.',
        'contains'      => 'Totals and comparisons for indicators across multiple woredas, regions or projects and time periods.',
        'how_to_use'    => 'Use filters to group results and understand total reach for example by region or by donor.',
        'when_to_use'   => 'For humanitarian cluster reporting, consolidated humanitarian figures and management summaries.',
        'who_uses'      => 'MEALR, senior management and cluster focal persons.',
        'why_necessary' => 'Provides high level totals across areas and projects to show overall reach.',
        'risks'         => 'Possible double counting if the same people are reached through multiple projects or activities.',
        'tips_do'       => 'Use aggregation for high level figures and document whether you adjusted for overlaps.',
        'tips_dont'     => 'Do not present aggregated numbers as unique individuals unless you are sure double counting is not present.'
    ],
    'pivot' => [
        'what'          => 'Pivot style analytic tables.',
        'contains'      => 'Multi dimensional tables combining indicators, time, geography and beneficiary types for deeper analysis.',
        'how_to_use'    => 'Choose dimensions for rows and columns, select indicators and summarise values, then interpret patterns.',
        'when_to_use'   => 'For learning exercises, evaluations and advanced analysis.',
        'who_uses'      => 'MEALR, statisticians and evaluation teams.',
        'why_necessary' => 'Helps explore data from many angles and identify gaps or interesting trends.',
        'risks'         => 'Complex tables can be misinterpreted if users are not trained.',
        'tips_do'       => 'Combine pivot analysis with field insights and qualitative data.',
        'tips_dont'     => 'Do not circulate complex pivot outputs without a clear explanation.'
    ],
    'progress' => [
        'what'          => 'Progress against target dashboards.',
        'contains'      => 'Graphs and tables showing baseline, target and achieved values for indicators.',
        'how_to_use'    => 'Select project and indicators, choose time range and review progress percentages and gaps.',
        'when_to_use'   => 'Quarterly reviews, mid term and end of project evaluations and steering committee meetings.',
        'who_uses'      => 'Management, MEALR and project teams.',
        'why_necessary' => 'Shows clearly which indicators are on track and which need additional support or adaptation.',
        'risks'         => 'Incorrect targets or missing baseline values give misleading percentages.',
        'tips_do'       => 'Use this module together with narrative explanations and context from the field.',
        'tips_dont'     => 'Do not judge performance only by percentages without understanding reasons for delays.'
    ],
    'cfm' => [
        'what'          => 'Complaint and Feedback Mechanism management module.',
        'contains'      => 'Records of community feedback, complaints, suggestions, appreciation and actions taken, including vulnerability and status.',
        'how_to_use'    => 'Log each case with category and channel, update status as you follow up and close when resolved.',
        'when_to_use'   => 'Whenever feedback is received by hotline, suggestion box, meetings, field visits or partners.',
        'who_uses'      => 'Accountability staff, MEALR, protection staff and project teams.',
        'why_necessary' => 'Supports accountability to affected populations and provides evidence for programme improvement.',
        'risks'         => 'Breaches of confidentiality, especially for gender based violence and child protection cases or cases that are never followed up.',
        'tips_do'       => 'Follow Nexus safeguarding and protection protocols and limit access to sensitive cases.',
        'tips_dont'     => 'Do not share identifiable details outside the authorised team or close cases without real follow up.'
    ],
    'messages' => [
        'what'          => 'Internal messaging and notifications module.',
        'contains'      => 'Short messages between users about reports, deadlines, data issues or system updates.',
        'how_to_use'    => 'Send brief, clear messages to colleagues or admins about system use and check your inbox for updates.',
        'when_to_use'   => 'For day to day coordination related to reporting and data quality.',
        'who_uses'      => 'All system users.',
        'why_necessary' => 'Provides a simple way to coordinate directly inside the system.',
        'risks'         => 'Using it for confidential or sensitive content that should use secure channels.',
        'tips_do'       => 'Keep messages professional and focused on tasks.',
        'tips_dont'     => 'Do not use messages for very sensitive or personal information.'
    ],
    'data_quality' => [
        'what'          => 'Data quality checks and alerts.',
        'contains'      => 'Rules and reports for missing data, inconsistencies, outliers and duplicates.',
        'how_to_use'    => 'Run checks after data entry, review flagged records and correct issues with field teams.',
        'when_to_use'   => 'After each reporting cycle and before major submissions.',
        'who_uses'      => 'MEALR, data officers and project coordinators.',
        'why_necessary' => 'Ensures that reported numbers are reliable before they are used for decisions or external reporting.',
        'risks'         => 'Ignoring data quality warnings leads to serious reporting errors and loss of trust.',
        'tips_do'       => 'Treat data quality checks as a routine and non negotiable step in reporting.',
        'tips_dont'     => 'Do not hide or ignore quality issues because of time pressure.'
    ],
    'users' => [
        'what'          => 'User management and roles.',
        'contains'      => 'User accounts, roles and project and app permissions.',
        'how_to_use'    => 'Create user accounts, set roles and project access and deactivate accounts when staff leave.',
        'when_to_use'   => 'Onboarding or offboarding staff and when adjusting access rights.',
        'who_uses'      => 'System administrators and MEALR manager where authorised.',
        'why_necessary' => 'Controls who can see and change which data and modules.',
        'risks'         => 'Too many admin accounts or outdated user lists increase risk of misuse or data breaches.',
        'tips_do'       => 'Review user lists regularly and give admin rights only where needed.',
        'tips_dont'     => 'Do not leave accounts active for staff who have left the organisation.'
    ],
    'nexus_ai' => [
        'what'          => 'Knowledge and help center inside the system.',
        'contains'      => 'Frequently asked questions and structured knowledge about the system, modules, Nexus Ethiopia profile, roles, workflows and good practices.',
        'how_to_use'    => 'Type your question in natural language, read the answer and print or share if needed.',
        'when_to_use'   => 'When training new staff, troubleshooting confusion or clarifying processes.',
        'who_uses'      => 'All users, especially new team members and focal persons.',
        'why_necessary' => 'Reduces training burden and helps users solve common questions without leaving the system.',
        'risks'         => 'If knowledge is not updated, answers can become outdated and confusing.',
        'tips_do'       => 'Keep key descriptions up to date and explain that Nexus AI uses internal knowledge unless expanded later.',
        'tips_dont'     => 'Do not store very sensitive personal information in the knowledge base.'
    ],
];

// Build full app knowledge from APP_KEYS + base descriptions
foreach ($appLabels as $key => $label) {
    $base = $baseAppDescriptions[$key] ?? [
        'what'        => "Module: {$label}.",
        'contains'    => 'Functions and screens specific to this module.',
        'how_to_use'  => 'Open the module from the header and follow on screen instructions.',
        'when_to_use' => 'Whenever you need this specific function.',
        'who_uses'    => 'Users who have permissions for this module.',
    ];

    $appKnowledge[$key] = array_merge($base, [
        'key'   => $key,
        'label' => $label,
    ]);
}

// Roles / users knowledge (enriched with admin and data protection points)
$rolesInfo = [
    'admin' => [
        'name'  => 'Admin',
        'what'  => 'Full system administrator.',
        'responsibilities' => [
            'Create and manage user accounts and roles.',
            'Configure projects, indicators, geography and core settings.',
            'Manage permissions for apps and projects, including who can view and edit.',
            'Monitor system status and data quality, including Nexus AI usage.',
            'Use admin access responsibly and do not change critical data without documentation.',
            'Maintain updated lists of active users and revoke access when staff leave.',
            'Coordinate regular backups of the database and configuration.',
        ],
    ],
    'user' => [
        'name'  => 'User',
        'what'  => 'Standard system user, usually project or monitoring and evaluation staff.',
        'responsibilities' => [
            'Enter data accurately and on time.',
            'Review reports relevant to their projects.',
            'Support data quality by checking and correcting entries.',
            'Use CFM, messages and other tools as needed.',
            'Use reports and dashboards to check the quality of their own data.',
        ],
    ],
    'guest' => [
        'name'  => 'Guest',
        'what'  => 'Read only or very limited user.',
        'responsibilities' => [
            'View selected dashboards and reports.',
            'Use information for oversight and coordination.',
            'Provide feedback to admins or MEALR when they see potential issues.',
            'No editing or data entry responsibilities.',
        ],
    ],
];

// General guides and manual content
$guides = [
    'getting_started' => 'To start, log in, review your profile, navigate using the top menu and use Enter data for reporting. Use View report or Custom report for outputs and Nexus AI for quick explanations.',
    'about_nexus_ethiopia' => 'Nexus Ethiopia is a national, non governmental, non profit humanitarian and development organisation working across the Humanitarian, Development and Peace nexus to support vulnerable and marginalised populations affected by conflict, displacement, disaster and poverty.',
    'support' => 'For support, contact the MEALR manager or system admin (Amenti) or use the Messages app for internal notifications about issues or new features.',
    'reporting_workflows' => 'Typical monthly workflow: field teams collect data via registers or Kobo and project or monitoring staff check completeness; users enter data in Enter data; MEALR and admins run data quality checks; corrections are made; View reports and Custom report are used to prepare monthly internal reports; management reviews reports and agrees follow up actions.',
    'quarterly_reporting' => 'For quarterly and donor reporting: follow the monthly workflow, then use Custom report, Aggregation and Progress modules to match donor formats, cross check figures with previous submissions, prepare narrative explanations and attach exports from the system as annexes where needed.',
    'data_protection' => 'Handle sensitive and personal data carefully: limit collection of personally identifiable information, use codes instead of names when possible, store sensitive data only in secure parts of the system with restricted access, do not export sensitive data to unsecured devices and follow Nexus Ethiopia data protection and safeguarding guidelines.',
    'dos_donts' => 'Do: enter data accurately and on time, respect confidentiality, ask for help when unsure and use system outputs to support learning and improvement. Do not: share your login or confidential data, guess or manipulate data, ignore data quality warnings or complaint and feedback cases.',
    'faqs' => [
        [
            'q' => 'I cannot log in. What should I do?',
            'a' => 'Check your username and password and make sure Caps Lock is off. If it still fails, contact the system admin to reset your password or check your account.'
        ],
        [
            'q' => 'I cannot see my project in the dropdowns.',
            'a' => 'The project may not be registered or you may not have permission. Ask the admin or MEALR to confirm project setup and your access rights.'
        ],
        [
            'q' => 'The indicator I need is not visible in Enter data.',
            'a' => 'The indicator may not exist in the catalogue or may not be linked to your project. Contact the admin or MEALR to add or link it.'
        ],
        [
            'q' => 'My report shows strange numbers.',
            'a' => 'Check your filters and confirm whether the report is cumulative or period specific. Compare with data in Enter data and consult MEALR if it still looks wrong.'
        ],
        [
            'q' => 'Nexus AI gave an answer that seems incomplete.',
            'a' => 'Rephrase your question more clearly and remember that Nexus AI uses internal knowledge. If needed, ask a human colleague or MEALR for further support.'
        ],
    ],
    'training_plan' => 'Suggested training: Session one covers introduction and navigation including login, profiles, dashboard and Nexus AI. Session two covers data entry and complaint and feedback management with practical exercises. Session three covers reporting and analysis using View reports, Custom report, Aggregation, Pivot and Progress modules and how to interpret and use results. Trainers should ensure accounts are created, sample data is prepared and feedback from participants is collected.',
];

// Build AI knowledge structure for JS
$aiKnowledge = [
    'system'        => $systemInfo,
    'apps'          => $appKnowledge,
    'roles'         => $rolesInfo,
    'guides'        => $guides,
    'nexus_profile' => $nexusProfile,
    'version'       => $appVersion,
];

// Simple projects snapshot (optional)
$projects = [];
try {
    foreach (get_projects() as $p) {
        $projects[] = [
            'id'         => $p['id'] ?? null,
            'title'      => $p['title'] ?? '',
            'code'       => $p['code'] ?? '',
            'location'   => $p['location'] ?? '',
            'start_date' => $p['start_date'] ?? '',
            'end_date'   => $p['end_date'] ?? '',
            'budget'     => $p['budget'] ?? '',
            'status'     => $p['status'] ?? '',
        ];
    }
} catch (Throwable $e) {
    $projects = [];
}

// ---------------------------------------------------------------------
// 2. Render page (header)
// ---------------------------------------------------------------------

smart_require_once('header.php', [
    __DIR__ . '/header.php',
    $rootPath . '/header.php',
    $rootPath . '/includes/header.php',
]);

?>
<style>
    .nexus-ai-page {
        padding-top: 10px;
        padding-bottom: 20px;
    }
    .ai-layout {
        display: grid;
        grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr);
        gap: 14px;
    }
    @media (max-width: 900px) {
        .ai-layout {
            grid-template-columns: 1fr;
        }
    }
    .ai-panel {
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        padding: 12px 14px;
        box-shadow: 0 2px 6px rgba(15,23,42,0.05);
    }
    .ai-input-wrapper {
        display: flex;
        gap: 8px;
        align-items: stretch;
        margin-bottom: 8px;
    }
    .ai-input {
        flex: 1;
        padding: 8px 10px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        font-size: 12px;
        outline: none;
    }
    .ai-input:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 1px rgba(79,70,229,0.25);
    }
    .ai-ask-btn {
        border-radius: 999px;
        border: none;
        padding: 0 14px;
        font-size: 12px;
        background: linear-gradient(135deg,#4f46e5,#06b6d4);
        color: #fff;
        cursor: pointer;
        white-space: nowrap;
    }
    .ai-ask-btn:hover {
        opacity: 0.95;
    }
    .ai-answer {
        border-radius: 10px;
        padding: 10px 12px;
        background: #f8fafc;
        font-size: 12px;
        color: #0f172a;
        max-height: 420px;
        overflow-y: auto;
    }
    .ai-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 8px;
    }
    .ai-chip {
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 999px;
        border: 1px solid #e2e8f0;
        background: #f9fafb;
        cursor: pointer;
    }
    .ai-chip:hover {
        border-color: #4f46e5;
        background: #eef2ff;
    }
    .ai-side-section-title {
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }
    .ai-side-list {
        font-size: 11px;
        color: #475569;
        list-style: none;
        padding-left: 0;
        margin: 0;
    }
    .ai-side-list li {
        padding: 3px 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .ai-tag {
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 999px;
        background: #eff6ff;
        color: #1d4ed8;
    }
    .status-pill-small {
        font-size: 10px;
        padding: 3px 8px;
        border-radius: 999px;
        background: #ecfdf5;
        color: #047857;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .ai-message {
        border-bottom: 1px solid #e5e7eb;
        padding: 6px 0;
    }
    .ai-message:last-child {
        border-bottom: none;
    }
    .ai-q {
        font-weight: 600;
        margin-bottom: 3px;
        color: #111827;
    }
    .ai-a {
        font-size: 12px;
        color: #0f172a;
    }
    .ai-time {
        font-size: 10px;
        color: #9ca3af;
        margin-top: 2px;
    }
</style>

<div class="container nexus-ai-page">

    <?php
    echo render_block_header(
        'Nexus AI – System Knowledge & Assistant',
        'Ask questions about the system, apps, roles, Nexus Ethiopia and how to use SMART Nexus.',
        'robot'
    );

    echo render_ai_hint('default');

    // Context actions (view only; handled via JS below)
    echo generate_action_bar('view_only');
    ?>

    <div class="ai-layout mt-2">

        <!-- LEFT: AI Question & Answer -->
        <div class="ai-panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <div style="font-size:12px;font-weight:600;color:#111827;">
                    Ask Nexus AI about this system
                    <span style="font-size:10px;color:#6b7280;">
                        (for example: "What is Custom report?", "Explain roles", "How do I log in?", "Monthly reporting workflow")
                    </span>
                </div>
                <span class="status-pill-small">
                    <i class="fas fa-bolt"></i>
                    v<?php echo h($appVersion); ?>
                </span>
            </div>

            <div class="ai-input-wrapper">
                <input
                    type="text"
                    id="ai-question"
                    class="ai-input"
                    placeholder="Type your question here..."
                    onkeypress="if(event.key==='Enter'){event.preventDefault(); askNexusAI();}"
                />
                <button type="button" class="ai-ask-btn" onclick="askNexusAI()">
                    <i class="fas fa-paper-plane"></i> Ask
                </button>
            </div>

            <div class="ai-chips">
                <div class="ai-chip" onclick="quickQuestion('What is this system and its objectives?')">
                    What is this system?
                </div>
                <div class="ai-chip" onclick="quickQuestion('Tell me about Nexus Ethiopia.')">
                    About Nexus Ethiopia
                </div>
                <div class="ai-chip" onclick="quickQuestion('Explain the Enter data app.')">
                    About Enter data
                </div>
                <div class="ai-chip" onclick="quickQuestion('What is the Custom report app?')">
                    Custom report
                </div>
                <div class="ai-chip" onclick="quickQuestion('What is the role of an admin user?')">
                    Admin role
                </div>
                <div class="ai-chip" onclick="quickQuestion('Explain the monthly reporting workflow.')">
                    Monthly workflow
                </div>
                <div class="ai-chip" onclick="quickQuestion('What are the data protection rules?')">
                    Data protection
                </div>
                <div class="ai-chip" onclick="quickQuestion('Troubleshooting and FAQs')">
                    Troubleshooting
                </div>
            </div>

            <div id="ai-answer" class="ai-answer">
                <div class="ai-message">
                    <div class="ai-q">Nexus AI assistant ready</div>
                    <div class="ai-a">
                        <strong>Welcome.</strong> Ask a question above, or click one of the quick topics to learn about
                        the SMART Nexus system, Nexus Ethiopia, modules, workflows, roles and good practices.
                        <br><br>
                        Each answer focuses on the part you ask about, using an internal knowledge base that includes
                        the organisational profile, the SMART Nexus user guide and training manual and module by module
                        explanations.
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: System status & quick knowledge -->
        <div class="ai-panel">
            <div class="ai-side-section-title">
                System snapshot
            </div>
            <ul class="ai-side-list mb-2">
                <li>
                    <span>System name</span>
                    <span class="ai-tag"><?php echo h($appName); ?></span>
                </li>
                <li>
                    <span>Version</span>
                    <span><?php echo h($appVersion); ?></span>
                </li>
                <li>
                    <span>Environment</span>
                    <span><?php echo h($appEnv); ?></span>
                </li>
                <li>
                    <span>Cross app communication</span>
                    <span><?php echo h($systemStatus['cross_app_communication'] ?? 'unknown'); ?></span>
                </li>
                <li>
                    <span>Print and export</span>
                    <span><?php echo h($systemStatus['print_system'] ?? 'ready'); ?></span>
                </li>
                <li>
                    <span>Approx active users</span>
                    <span><?php echo (int)($systemStatus['active_users'] ?? 1); ?></span>
                </li>
                <li>
                    <span>Pending messages</span>
                    <span><?php echo (int)($systemStatus['pending_messages'] ?? 0); ?></span>
                </li>
            </ul>

            <hr style="margin:6px 0;">

            <div class="ai-side-section-title">
                Main modules
            </div>
            <ul class="ai-side-list mb-2">
                <?php foreach (['dashboard','projects','planning','enter_data','view_reports','custom_report','cfm','data_quality'] as $k):
                    if (!isset($appKnowledge[$k])) continue;
                    $label = $appKnowledge[$k]['label'];
                ?>
                    <li>
                        <span><?php echo h($label); ?></span>
                        <a href="javascript:void(0)" style="font-size:10px;" onclick="quickQuestion('Explain the <?php echo h($label); ?> app.')">
                            Learn
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <hr style="margin:6px 0;">

            <div class="ai-side-section-title">
                Quick guidance
            </div>
            <p style="font-size:11px;color:#475569;margin-bottom:4px;">
                <?php echo h($guides['getting_started']); ?>
            </p>
            <p style="font-size:11px;color:#475569;margin-bottom:2px;">
                <?php echo h($guides['about_nexus_ethiopia']); ?>
            </p>
            <p style="font-size:11px;color:#64748b;">
                <?php echo h($guides['support']); ?>
            </p>
        </div>

    </div>

</div>

<script>
// Pass PHP knowledge base & user/project snapshot to JS
const NEXUS_AI_KB = <?php echo json_encode($aiKnowledge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const NEXUS_AI_USER = <?php echo json_encode([
    'id'   => $user['id'] ?? null,
    'name' => $user['full_name'] ?? ($user['name'] ?? ''),
    'role' => $user['role'] ?? 'user'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const NEXUS_AI_PROJECTS = <?php echo json_encode($projects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

// Simple in memory conversation history
let nexusAiHistory = [];

// Helper: set question and ask
function quickQuestion(text) {
    const input = document.getElementById('ai-question');
    input.value = text;
    askNexusAI();
}

// Main answer function (one focused answer per question)
function askNexusAI() {
    const input = document.getElementById('ai-question');
    const qRaw = (input.value || '').trim();

    if (!qRaw) {
        alert('Please type a question first.');
        return;
    }

    const q = qRaw.toLowerCase();

    const isRolesQ   = q.includes('role') || q.includes('responsibilit');
    const isNexusQ   = q.includes('nexus ethiopia') || q.includes('about nexus') || q.includes('who we are');
    const isSystemQ  = q.includes('this system') || q.includes('smart nexus') || q.includes('project performance system') || q.includes('health reporting system');
    const isAdminQ   = q.includes('admin') || q.includes('amenti') || q.includes('administrator');
    const isProjectQ = q.includes('project') && !q.includes('project performance report');

    let html = '';

    // 1) Guide and manual topics (login, workflows, data protection, FAQs, training)
    const guideHtml = renderGuideAnswer(qRaw);
    if (guideHtml) {
        html = guideHtml;
    }

    // 2) Roles / Nexus / Admin / Projects / System, only if not already answered
    if (!html && isRolesQ) {
        html = renderRolesAnswer(qRaw);
    } else if (!html && isNexusQ) {
        html = renderNexusEthiopiaAnswer(qRaw);
    } else if (!html && isAdminQ) {
        html = renderAdminAnswer();
    } else if (!html && isProjectQ) {
        html = renderProjectsAnswer(qRaw);
    } else if (!html && isSystemQ) {
        html = renderSystemAnswer(qRaw);
    }

    // 3) App or module explanation as fallback
    if (!html) {
        const appHtml = renderAppsAnswer(qRaw, true);
        if (appHtml) {
            html = appHtml;
        }
    }

    // 4) Final fallback generic help
    if (!html || !html.trim()) {
        html = `
            <p>I could not match your question to a specific topic.</p>
            <p>Try asking for example:</p>
            <ul>
                <li>What is the Enter data app?</li>
                <li>Explain roles</li>
                <li>Tell me about Nexus Ethiopia</li>
                <li>What is the monthly reporting workflow?</li>
                <li>What are the data protection rules?</li>
            </ul>
        `;
    }

    const turn = {
        question: qRaw,
        answerHtml: html,
        time: new Date()
    };
    nexusAiHistory.push(turn);
    renderConversation();

    input.value = '';
}

function renderConversation() {
    const box = document.getElementById('ai-answer');
    if (!box) return;
    if (!nexusAiHistory.length) return;

    let html = '';
    nexusAiHistory.forEach(turn => {
        const t = turn.time instanceof Date ? turn.time : new Date(turn.time);
        const timeLabel = t.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        html += `
            <div class="ai-message">
                <div class="ai-q">${escapeHtml(turn.question)}</div>
                <div class="ai-a">${turn.answerHtml}</div>
                <div class="ai-time">Answered at ${timeLabel}</div>
            </div>
        `;
    });

    box.innerHTML = html;
}

// Guide and manual content: login, navigation, workflows, data protection, FAQs, training
function renderGuideAnswer(originalQuestion) {
    const g = NEXUS_AI_KB.guides || {};
    const q = (originalQuestion || '').toLowerCase();
    let html = '';

    // Logging in
    if (q.includes('log in') || q.includes('login') || q.includes('sign in')) {
        html = '<h4>Logging in</h4>' +
            '<p>Open the system URL in a web browser such as Google Chrome, enter your username and password and click Login. ' +
            'If you forget your password, contact the system admin to reset it. Always log out on shared computers and never share your credentials.</p>';
        return html;
    }

    // Password and profile
    if (q.includes('password') || q.includes('change my password') || q.includes('changing my password') || q.includes('profile')) {
        html = '<h4>Changing your password and profile</h4>' +
            '<p>Once logged in, go to your profile or settings, usually in the top right corner. ' +
            'Change your password regularly, keep your name and contact details up to date and upload a clear profile photo when appropriate.</p>';
        return html;
    }

    // Navigation and interface
    if (q.includes('navigate') || q.includes('navigation') || q.includes('interface') || q.includes('menu')) {
        html = '<h4>Navigation and interface</h4>' +
            '<p>The system layout normally includes a top header bar with the system name and module menu, ' +
            'filters and search bars at the top of many pages, a main content area for forms and tables and an action bar with View, Print, Export and Share buttons where available.</p>';
        return html;
    }

    // Filters, printing, exporting and sharing
    if (q.includes('filter') || q.includes('search') || q.includes('printing') || q.includes('print') || q.includes('export') || q.includes('share')) {
        html = '<h4>Filters, printing, exporting and sharing</h4>' +
            '<p>Many pages include filters such as project, region, zone, woreda, indicator, reporting period and beneficiary type. ' +
            'Always confirm filters before interpreting a report. Use Print to print the report content without the full system header, ' +
            'Export to download in formats such as Excel, Word or PDF and Share in Nexus AI to open options for Telegram, WhatsApp or email. ' +
            'Protect exported files, especially when they contain sensitive data.</p>';
        return html;
    }

    // Monthly and routine reporting workflow
    if (q.includes('workflow') || q.includes('monthly reporting') || q.includes('routine reporting')) {
        html = '<h4>Monthly and routine reporting workflow</h4>' +
            '<p>' + escapeHtml(g.reporting_workflows || '') + '</p>';
        return html;
    }

    // Quarterly and donor reporting
    if (q.includes('quarterly') || q.includes('donor reporting')) {
        html = '<h4>Quarterly and donor reporting</h4>' +
            '<p>' + escapeHtml(g.quarterly_reporting || '') + '</p>';
        return html;
    }

    // Data protection and confidentiality
    if (q.includes('data protection') || q.includes('confidential') || q.includes('confidentiality') || q.includes('sensitive data')) {
        html = '<h4>Data protection, ethics and confidentiality</h4>' +
            '<p>' + escapeHtml(g.data_protection || '') + '</p>';
        return html;
    }

    // Good practices (do and do not)
    if (q.includes('do\'s') || q.includes('dos and donts') || q.includes('do and don') || q.includes('good practices') || q.includes('best practices') || q.includes('do and do not') || q.includes('dos and don ts')) {
        html = '<h4>Good practices for all users</h4>' +
            '<p>' + escapeHtml(g.dos_donts || '') + '</p>';
        return html;
    }

    // Troubleshooting and FAQs
    if (q.includes('trouble') || q.includes('problem') || q.includes('faq') || q.includes('cannot') || q.includes('can\'t') || q.includes('error')) {
        const faqs = Array.isArray(g.faqs) ? g.faqs : [];
        if (!faqs.length) return '';
        html = '<h4>Troubleshooting and frequently asked questions</h4><ul>';
        faqs.forEach(item => {
            html += '<li><strong>' + escapeHtml(item.q) + ':</strong> ' + escapeHtml(item.a) + '</li>';
        });
        html += '</ul>';
        return html;
    }

    // Training plan
    if (q.includes('training') || q.includes('training plan') || q.includes('train new staff') || q.includes('onboarding')) {
        html = '<h4>Training plan and checklist for new staff</h4>' +
            '<p>' + escapeHtml(g.training_plan || '') + '</p>';
        return html;
    }

    return '';
}

// Render system information
function renderSystemAnswer(originalQuestion) {
    const s = NEXUS_AI_KB.system || {};
    let html = '<h4>About this system</h4>';
    html += '<p><strong>Name:</strong> ' + escapeHtml(s.name || '') + '</p>';
    html += '<p><strong>Version:</strong> ' + escapeHtml(s.version || '') +
        ' · <strong>Environment:</strong> ' + escapeHtml(s.environment || '') + '</p>';
    if (s.developer) {
        html += '<p><strong>Developed by:</strong> ' + escapeHtml(s.developer) + '</p>';
    }
    if (Array.isArray(s.core_admin)) {
        html += '<p><strong>Core system admin(s):</strong></p><ul>';
        s.core_admin.forEach(a => {
            html += '<li><strong>' + escapeHtml(a.name) + ':</strong> ' + escapeHtml(a.about || '') + '</li>';
        });
        html += '</ul>';
    }
    if (Array.isArray(s.objectives)) {
        html += '<p><strong>Objectives:</strong></p><ul>';
        s.objectives.forEach(o => { html += '<li>' + escapeHtml(o) + '</li>'; });
        html += '</ul>';
    }
    if (Array.isArray(s.significance)) {
        html += '<p><strong>Significance:</strong></p><ul>';
        s.significance.forEach(o => { html += '<li>' + escapeHtml(o) + '</li>'; });
        html += '</ul>';
    }
    if (Array.isArray(s.how_to_use)) {
        html += '<p><strong>How to use the system:</strong></p><ul>';
        s.how_to_use.forEach(o => { html += '<li>' + escapeHtml(o) + '</li>'; });
        html += '</ul>';
    }
    return html;
}

// Render Nexus Ethiopia info with sub sections
function renderNexusEthiopiaAnswer(originalQuestion) {
    const p = NEXUS_AI_KB.nexus_profile || {};
    const q = (originalQuestion || '').toLowerCase();
    let html = '<h4>About Nexus Ethiopia</h4>';

    const ginfo = p.general_info || {};
    const addr = ginfo.address || {};

    // Contact details
    if (q.includes('address') || q.includes('contact') || q.includes('email') || q.includes('phone') || q.includes('website')) {
        html += '<p><strong>General contact information:</strong></p><ul>';
        if (addr.city)      html += '<li>' + escapeHtml(addr.city) + ', ' + escapeHtml(addr.sub_city || '') + '</li>';
        if (addr.po_box)    html += '<li>P.O. Box: ' + escapeHtml(addr.po_box) + '</li>';
        if (addr.telephone) html += '<li>Telephone: ' + escapeHtml(addr.telephone) + '</li>';
        if (Array.isArray(addr.emails)) html += '<li>E mail: ' + addr.emails.map(escapeHtml).join(', ') + '</li>';
        if (addr.website)   html += '<li>Website: ' + escapeHtml(addr.website) + '</li>';
        html += '</ul>';
        return html;
    }

    // Vision / mission / values
    if (q.includes('vision') || q.includes('mission') || q.includes('value')) {
        if (p.vision) {
            html += '<p><strong>Vision:</strong> ' + escapeHtml(p.vision) + '</p>';
        }
        if (p.mission) {
            html += '<p><strong>Mission:</strong> ' + escapeHtml(p.mission) + '</p>';
        }
        if (Array.isArray(p.core_values)) {
            html += '<p><strong>Core values:</strong></p><ul>';
            p.core_values.forEach(v => html += '<li>' + escapeHtml(v) + '</li>');
            html += '</ul>';
        }
        return html;
    }

    // Thematic areas
    if (q.includes('thematic') || q.includes('sector') || q.includes('health') || q.includes('wash') || q.includes('education')) {
        const t = p.thematic_areas || {};
        html += '<p><strong>Main thematic areas:</strong></p><ul>';
        Object.keys(t).forEach(k => {
            html += '<li><strong>' + escapeHtml(k) + ':</strong> ' + escapeHtml(t[k]) + '</li>';
        });
        html += '</ul>';
        return html;
    }

    // Regions of operation
    if (q.includes('region') || q.includes('where do you work') || q.includes('areas of operation')) {
        if (Array.isArray(p.regions_of_operation)) {
            html += '<p><strong>Current regions of operation:</strong></p><ul>';
            p.regions_of_operation.forEach(r => html += '<li>' + escapeHtml(r) + '</li>');
            html += '</ul>';
        }
        return html;
    }

    // Donors / partners
    if (q.includes('donor') || q.includes('partner')) {
        if (Array.isArray(p.current_donors)) {
            html += '<p><strong>Selected active donors and partners:</strong></p><ul>';
            p.current_donors.forEach(d => html += '<li>' + escapeHtml(d) + '</li>');
            html += '</ul>';
        }
        return html;
    }

    // Projects
    if (q.includes('project')) {
        if (Array.isArray(p.sample_projects)) {
            html += '<p><strong>Examples of ongoing projects:</strong></p><ul>';
            p.sample_projects.forEach(d => html += '<li>' + escapeHtml(d) + '</li>');
            html += '</ul>';
        }
        return html;
    }

    // Default overview
    html += '<p><strong>Name:</strong> ' + escapeHtml(ginfo.name || 'Nexus Ethiopia') + '</p>';
    if (ginfo.mandate) {
        html += '<p>' + escapeHtml(ginfo.mandate) + '</p>';
    }
    if (p.vision) {
        html += '<p><strong>Vision:</strong> ' + escapeHtml(p.vision) + '</p>';
    }
    if (p.mission) {
        html += '<p><strong>Mission:</strong> ' + escapeHtml(p.mission) + '</p>';
    }
    if (Array.isArray(p.strategic_objectives)) {
        html += '<p><strong>Strategic objectives:</strong></p><ul>';
        p.strategic_objectives.forEach(o => html += '<li>' + escapeHtml(o) + '</li>');
        html += '</ul>';
    }
    return html;
}

// Render info about admin / Amenti
function renderAdminAnswer() {
    const s = NEXUS_AI_KB.system || {};
    const admins = Array.isArray(s.core_admin) ? s.core_admin : [];
    let html = '<h4>System admin and developer</h4>';
    if (admins.length) {
        html += '<ul>';
        admins.forEach(a => {
            html += '<li><strong>' + escapeHtml(a.name) + ':</strong> ' + escapeHtml(a.about || '') + '</li>';
        });
        html += '</ul>';
    }
    if (NEXUS_AI_USER && NEXUS_AI_USER.role) {
        html += '<p>You are currently logged in as: <strong>' +
            escapeHtml(NEXUS_AI_USER.name || '') + '</strong> (' +
            escapeHtml(NEXUS_AI_USER.role) + ').</p>';
    }
    return html;
}

// Render roles and responsibilities (plus data protection note)
function renderRolesAnswer(originalQuestion) {
    const roles = NEXUS_AI_KB.roles || {};
    const guides = NEXUS_AI_KB.guides || {};
    let html = '<h4>User roles and responsibilities</h4><ul>';
    Object.keys(roles).forEach(key => {
        const r = roles[key];
        html += '<li style="margin-bottom:4px;">';
        html += '<strong>' + escapeHtml(r.name || key) + ':</strong> ' + escapeHtml(r.what || '');
        if (Array.isArray(r.responsibilities)) {
            html += '<br><span>Key responsibilities:</span><ul>';
            r.responsibilities.forEach(item => {
                html += '<li>' + escapeHtml(item) + '</li>';
            });
            html += '</ul>';
        }
        html += '</li>';
    });
    html += '</ul>';

    if (NEXUS_AI_USER && NEXUS_AI_USER.role && roles[NEXUS_AI_USER.role]) {
        html += '<p><strong>Your role in the system:</strong> You are logged in as <em>' +
            escapeHtml(NEXUS_AI_USER.role) +
            '</em>. See the responsibilities above for what this role usually does.</p>';
    }

    if (guides.data_protection) {
        html += '<p><strong>Data protection and confidentiality for all roles:</strong> ' +
            escapeHtml(guides.data_protection) + '</p>';
    }

    return html;
}

// Render basic project info (from DB snapshot)
function renderProjectsAnswer(originalQuestion) {
    const q = (originalQuestion || '').toLowerCase();
    const list = Array.isArray(NEXUS_AI_PROJECTS) ? NEXUS_AI_PROJECTS : [];
    if (!list.length) return '';

    let matches = [];
    list.forEach(p => {
        const hay = ((p.title || '') + ' ' + (p.code || '') + ' ' + (p.location || '')).toLowerCase();
        let score = 0;
        q.split(/\s+/).forEach(w => {
            if (w.length > 2 && hay.includes(w)) score++;
        });
        if (score > 0) matches.push({p, score});
    });

    matches.sort((a,b) => b.score - a.score);

    let html = '<h4>Project information</h4>';
    if (!matches.length) {
        html += '<p>No specific project mentioned. Here is a short list of registered projects:</p><ul>';
        list.slice(0, 5).forEach(p => {
            html += '<li><strong>' + escapeHtml(p.title || '') + '</strong>' +
                (p.code ? ' (' + escapeHtml(p.code) + ')' : '') +
                ' – ' + escapeHtml(p.location || '') + '</li>';
        });
        html += '</ul>';
        return html;
    }

    const p = matches[0].p;
    html += '<ul>';
    html += '<li><strong>Title:</strong> ' + escapeHtml(p.title || '') + '</li>';
    if (p.code)       html += '<li><strong>Code:</strong> ' + escapeHtml(p.code) + '</li>';
    if (p.location)   html += '<li><strong>Location:</strong> ' + escapeHtml(p.location) + '</li>';
    if (p.start_date) html += '<li><strong>Start date:</strong> ' + escapeHtml(p.start_date) + '</li>';
    if (p.end_date)   html += '<li><strong>End date:</strong> ' + escapeHtml(p.end_date) + '</li>';
    if (p.budget)     html += '<li><strong>Budget:</strong> ' + escapeHtml(p.budget) + '</li>';
    if (p.status)     html += '<li><strong>Status:</strong> ' + escapeHtml(p.status) + '</li>';
    html += '</ul>';

    return html;
}

// Render apps information (focus on best match with extended details)
function renderAppsAnswer(originalQuestion, loose = false) {
    const apps = NEXUS_AI_KB.apps || {};
    const q = (originalQuestion || '').toLowerCase();
    const words = q.split(/\s+/).filter(w => w.length > 2);

    const matched = [];
    Object.keys(apps).forEach(key => {
        const app = apps[key];
        const haystack = (
            key + ' ' +
            (app.label || '') + ' ' +
            (app.what || '') + ' ' +
            (app.contains || '')
        ).toLowerCase();

        let score = 0;
        words.forEach(w => {
            if (haystack.includes(w)) score++;
        });

        if (q.includes(key) || q.includes((app.label || '').toLowerCase())) {
            score += 3;
        }
        if (key === 'custom_report' && (q.includes('custom report') || q.includes('custom data') || q.includes('advanced report'))) {
            score += 3;
        }

        if (score > (loose ? 0 : 1)) {
            matched.push({ key, app, score });
        }
    });

    matched.sort((a,b) => b.score - a.score);

    if (!matched.length && !loose) {
        return '';
    }
    if (!matched.length && loose) {
        return '';
    }

    const a = matched[0].app;
    let html = '<h4>About ' + escapeHtml(a.label || a.key) + ' app</h4>';
    if (a.what) {
        html += '<p><strong>What is it?</strong> ' + escapeHtml(a.what) + '</p>';
    }
    if (a.contains) {
        html += '<p><strong>What does it contain?</strong> ' + escapeHtml(a.contains) + '</p>';
    }
    if (a.how_to_use) {
        html += '<p><strong>How to use it?</strong> ' + escapeHtml(a.how_to_use) + '</p>';
    }
    if (a.when_to_use) {
        html += '<p><strong>When to use it?</strong> ' + escapeHtml(a.when_to_use) + '</p>';
    }
    if (a.who_uses) {
        html += '<p><strong>Who uses it?</strong> ' + escapeHtml(a.who_uses) + '</p>';
    }
    if (a.why_necessary) {
        html += '<p><strong>Why is it necessary?</strong> ' + escapeHtml(a.why_necessary) + '</p>';
    }
    if (a.risks) {
        html += '<p><strong>Risks if misused:</strong> ' + escapeHtml(a.risks) + '</p>';
    }
    if (a.tips_do || a.tips_dont) {
        html += '<p><strong>Good practices:</strong></p><ul>';
        if (a.tips_do) {
            html += '<li><em>Do:</em> ' + escapeHtml(a.tips_do) + '</li>';
        }
        if (a.tips_dont) {
            html += '<li><em>Do not:</em> ' + escapeHtml(a.tips_dont) + '</li>';
        }
        html += '</ul>';
    }

    return html;
}

// Basic HTML escape (for safety)
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/* -----------------------------------------------------------
 * Custom handlers for View / Print / Share / Export buttons
 * These affect only the AI conversation area
 * ---------------------------------------------------------*/

function getConversationHtml() {
    const box = document.getElementById('ai-answer');
    return box ? box.innerHTML : '';
}

function getConversationText() {
    const box = document.getElementById('ai-answer');
    return box ? box.innerText : '';
}

function handleViewAction() {
    const html = getConversationHtml();
    const win = window.open('', '_blank');
    if (!win) return;
    win.document.write(`
        <html>
        <head>
            <title>Nexus AI Conversation</title>
            <meta charset="utf-8">
            <style>
                body{font-family:Arial, sans-serif;font-size:12px;padding:20px;background:#f9fafb;}
                .ai-message{border-bottom:1px solid #e5e7eb;padding:8px 0;}
                .ai-q{font-weight:600;margin-bottom:4px;}
                .ai-a{margin-left:4px;}
            </style>
        </head>
        <body>
            <h3>Nexus AI – Conversation</h3>
            ${html}
        </body>
        </html>
    `);
    win.document.close();
}

function handlePrintAction() {
    const html = getConversationHtml();
    const w = window.open('', '_blank');
    if (!w) return;
    w.document.write(`
        <html>
        <head>
            <title>Print – Nexus AI Conversation</title>
            <meta charset="utf-8">
            <style>
                body{font-family:Arial, sans-serif;font-size:12px;padding:20px;}
                .ai-message{border-bottom:1px solid #e5e7eb;padding:8px 0;}
                .ai-q{font-weight:600;margin-bottom:4px;}
                .ai-a{margin-left:4px;}
                .ai-time{font-size:10px;color:#9ca3af;}
            </style>
        </head>
        <body>
            <h3>Nexus AI – Question and Answer</h3>
            ${html}
        </body>
        </html>
    `);
    w.document.close();
    w.focus();
    w.print();
}

function handleShareAction() {
    const text = getConversationText();
    const url = window.location.href;
    const full = 'Nexus AI conversation:\n\n' + text + '\n\nLink: ' + url;
    const encodedText = encodeURIComponent(full);
    const encodedUrl = encodeURIComponent(url);

    const telegramUrl = 'https://t.me/share/url?url=' + encodedUrl + '&text=' + encodedText;
    const whatsappUrl = 'https://wa.me/?text=' + encodedText + '%20' + encodedUrl;
    const mailUrl = 'mailto:?subject=' + encodeURIComponent('Nexus AI conversation') + '&body=' + encodedText + '%0A%0A' + encodedUrl;

    const w = window.open('', '_blank');
    if (!w) return;
    w.document.write(`
        <html>
        <head>
            <title>Share Nexus AI Conversation</title>
            <meta charset="utf-8">
            <style>
                body{font-family:Arial, sans-serif;font-size:13px;padding:20px;}
                a{display:block;margin-bottom:8px;}
            </style>
        </head>
        <body>
            <h3>Share Nexus AI conversation</h3>
            <p>Select where you want to share:</p>
            <a href="${telegramUrl}" target="_blank">Share via Telegram</a>
            <a href="${whatsappUrl}" target="_blank">Share via WhatsApp</a>
            <a href="${mailUrl}" target="_blank">Share via Email</a>
        </body>
        </html>
    `);
    w.document.close();
}

function handleExportAction() {
    const text = getConversationText();
    const blob = new Blob([text], {type: 'text/plain'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'nexus_ai_conversation.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php
smart_require_once('footer.php', [
    __DIR__ . '/footer.php',
    $rootPath . '/footer.php',
    $rootPath . '/includes/footer.php',
]);
?>
