<?php
/**
 * Enhanced Database Helper for SMART Nexus / Health Reporting System
 *
 * - Single shared PDO connection for ALL apps (dashboard, projects, planning,
 *   enter_data, custom_report, aggregation, pivot, progress, CFM, messages, etc.).
 * - Backward compatible with your existing code (getPDO()).
 * - New standard accessors: get_db(), db(), db_connect().
 * - Generic helpers for queries, inserts, updates, transactions & bulk import.
 * - Schema creation + auto-update for cross-app compatibility.
 *
 * IMPORTANT:
 * - Printing / download / export headers (per-app titles like
 *   "Nexus Ethiopia Project Performance Report – Custom Report") are handled in
 *   helpers.php and each page. This file only manages DATABASE access and structure.
 */

 // -------------------------------------------------------------------------
 // 0. BOOTSTRAP GUARD – prevent redeclaration if db.php is included many times
 // -------------------------------------------------------------------------
if (defined('DB_BOOTSTRAPPED')) {
    return;
}
define('DB_BOOTSTRAPPED', true);

// -------------------------------------------------------------------------
// 1. DB CONFIG
// -------------------------------------------------------------------------
if (!defined('DB_HOST')) {
    define('DB_HOST', '127.0.0.1'); // Use 127.0.0.1 to avoid socket issues
}
if (!defined('DB_PORT')) {
    define('DB_PORT', 3306);       // Change to 3307 if your MySQL uses 3307
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'health_system_reporting');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}

if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}
if (!defined('DB_APP_TAG')) {
    define('DB_APP_TAG', 'SMART_NEXUS');
}

// -------------------------------------------------------------------------
// 1b. MASTER ETHIOPIA LOCATION ARRAY (Regions → Zones → Woredas)
//     Provided by you; used for seeding regions/zones/woredas and
//     shared across all apps for consistency.
// -------------------------------------------------------------------------
$NEXUS_LOCATIONS = [
    "Tigray" => [
        "Central Zone" => [
            "Abrgele yechla",
            "Abyiadi 2",
            "Adet 3",
            "Adwa town",
            "adwa zuria",
            "Ahferom",
            "Ahsea",
            "Axum town",
            "Chlla",
            "Egella",
            "Embasenyti",
            "Edabatsahma",
            "Enticho",
            "Hahaylle",
            "Keyhteklli",
            "Kollatemben",
            "Laelaymachew",
            "Maiknetal",
            "Naeder",
            "Rama",
            "Tahtaymachew",
            "Tankamlash",
        ],
        "Eastern Zone" => [
            "Adigrat",
            "E/selase Atsbi",
            "Atsbi",
            "Edaga Hamus",
            "Freweyni",
            "Kilteawlaelo",
            "Ketema Hawzen",
            "Tsaeda Emba",
            "Tsiraewemberta",
            "Erob",
            "Bizet",
            "Hawzen",
            "Wukro",
            "Zalambesa",
            "Subhasaesie",
            "Ganta Afeshum",
            "Gerealta",
            "Gulomekeda",
        ],
        "N/West Zone" => [
            "Asgede",
            "L/Koraro",
            "Shire Town",
            "T/Koraro",
            "Zana",
            "L/Tselemti",
            "Tselemti",
            "M/Tsebri",
            "Tsinbla",
            "E/Guna",
            "T/Adiabo",
            "Shraro Town",
            "L/Adiabo",
            "Adi Daero",
            "M/Adiabo",
        ],
        "South Zone" => [
            "Mokoni",
            "Raya azebo",
            "C|hercher",
            "Maychew",
            "Endamokoni",
            "Nekesege",
            "ofla",
            "korem Twon",
            "zata",
            "Alamata Town",
            "Raya Alamata",
            "E/Alaje",
            "Bora",
            "Selewa",
        ],
        "West Zone" => [
            "welkayt",
            "Tsegede",
            "Setit Humera",
            "May kadira",
            "May Gaba",
            "Korarit",
            "Kafta Humera",
            "Dansha",
            "Awera",
        ],
        "South East Zone" => [
            "Seharti",
            "Adigudom",
            "Degua",
            "Hagereselam",
            "Hintallo",
            "Wejerat",
            "Enderta",
            "Samre",
        ],
        "Mekele" => [
            "Adihaki",
            "Ayder",
            "Hadnet",
            "Hawelti",
            "K/ Weyane",
            "Quiha",
            "Semen",
        ],
    ],

    "Afar" => [
        "Zone 1" => [
            "Afambo",
            "Adear",
            "Ayssaita Town",
            "Ayssaita woreda",
            "Chifra",
            "Dubti town",
            "Dubti woreda",
            "Elidear",
            "Gereni",
            "Kori",
            "Mille",
            "Semere-Logia",
        ],
        "Zone 2" => [
            "Abe'ala town",
            "Abe'ala Woreda",
            "Afdera",
            "Berahile",
            "Bidu",
            "Dallol",
            "Erebti",
            "Koneba",
            "Megale",
        ],
        "Zone 3" => [
            "Amibara",
            "Aregoba",
            "Awash Fentale",
            "Awash town",
            "Dulecha",
            "Gele'alo",
            "Gewane",
            "Hanorika",
            "Bure Medaitu",
        ],
        "Zone 4" => [
            "Awura",
            "Ewa",
            "Gulina",
            "Teru",
            "Yallo",
        ],
        "Zone 5" => [
            "Dalifaghe",
            "Dewe",
            "Hadele'ela",
            "Semurobi",
            "Telalak",
        ],
    ],

    "Amhara" => [
        "North Shoa" => [
            "Angolela",
            "Ankober",
            "Antsokia",
            "Asagirete",
            "Baso",
            "Berhete",
            "D/Birhan",
            "Eferata",
            "H/Mariam",
            "Ensaro",
            "Kewote",
            "Menze Keya",
            "Menze Lalo",
            "Menze Mama",
            "Menze Gera",
            "Merhabete",
            "Mida",
            "Minjar",
            "Moja",
            "Morete",
            "Shewarobit",
            "Siadbere",
            "Tarmaber",
            "Gishe",
        ],
        "Awi" => [
            "Ankesha",
            "Ayehu Guagusa",
            "Banja",
            "Chagni Town",
            "Dangila Town",
            "Dangila Zuria",
            "Fagita Lekoma",
            "Guagusa shikudad",
            "Guangua",
            "Injibara Town",
            "Jawi",
            "Zigem",
        ],
        "North Gondar" => [
            "Adiarkay",
            "Beyeda",
            "Dabat",
            "Debark Town",
            "Debark Zuria",
            "Janamora",
            "Telemt",
        ],
        "East Gojam" => [
            "Aneded",
            "Awabel",
            "Basoliben",
            "Bibugn",
            "Debaytilayit Gin",
            "Debre Elias",
            "Debre Markos",
            "Dejen",
            "Enarj Enawuga",
            "Enemay",
            "Enesiesarmidir",
            "Goncha",
            "Gozamn",
            "Hulteeju enesie",
            "Machakel",
            "Motta",
            "Sedie",
            "Shebel",
            "Sinan",
        ],
        "Central Gondar" => [
            "Alefa",
            "Wogera",
            "Chilga-01",
            "Chilga-02",
            "G/Zurai",
            "kinfaz",
            "Lay Armachiho",
            "Central Armachiho",
            "West belesa",
            "West Dembia",
            "East belesa",
            "East Dembia",
            "Tach Armachiho",
            "Takusa",
            "Tegedie",
            "Gondar Town",
        ],
        "South Gondar" => [
            "Ebinat",
            "Lay Gayint",
            "Farta",
            "Debretabor Town",
            "Meketewa",
            "Andabet",
            "Woreta",
            "Estie",
            "Guna begemidr",
            "Dera",
            "Fogera",
            "Libo kemkem",
            "Simada",
            "T/gaynet",
            "Sedie mujja",
        ],
        "W/Gondar" => [
            "Gendawuha",
            "Metema",
            "West  arimachiho",
            "Quara",
            "Adagnager",
        ],
        "S/Wollo" => [
            "Albuko",
            "Ambassel",
            "Argoba",
            "Borena",
            "Delanta",
            "Dessiezuria",
            "Jamma",
            "Kallu",
            "Kelala",
            "Kombolcha",
            "Kutaber",
            "Legambo",
            "Legehida",
            "Mehalsayint",
            "Mekdela",
            "Sayint",
            "Tehuledere",
            "Tenta",
            "Woreilu",
            "Wogedi",
            "Worebabo",
            "Dessie",
        ],
        "W/Gojam" => [
            "Bahir Dar Zuria",
            "Bure Zuria",
            "Bure Town",
            "Debub Achefer",
            "Debub Mecha",
            "Dega Damot",
            "Dembecha",
            "Finot Selam",
            "Gonji kolela",
            "Jabi Tehinan",
            "Quarit",
            "Sekela",
            "Semen Achefer",
            "Semen Mecha",
            "Womberma",
            "Yilimana Diensa",
            "Bahirdar",
        ],
        "Wag Himra" => [
            "Abrigelie",
            "Dehana",
            "Gazgibla",
            "Sehala",
            "Sekota Town",
            "Sekota Zuriya",
            "Tsagbijie",
            "Ziquala",
        ],
        "N/Wollo" => [
            "Angot",
            "Bugna",
            "Dawunt",
            "gazo",
            "Gidan",
            "Gubalafto",
            "Habru",
            "Kobo Town",
            "Lalibela Town",
            "Lasta",
            "Meket",
            "Raya Kobo",
            "Wadla",
            "Woldia Town",
        ],
        "Oromia S/Zone" => [
            "Artuma Fursi",
            "Bati Town",
            "Bati  Woreda",
            "Dewa chefa",
            "Dewe Harewa",
            "Jile Timuga",
            "Kemisse town",
        ],
        "West Gondare" => [
            "Dansha",
            "Awura",
            "Kafita Humera",
            "Setit Humera",
            "Tegedie",
            "Welekit",
        ],
    ],

    "Oromia" => [
        "Arsi Zone" => [
            "AMIGNA",
            "ASEKO",
            "BELE GASGAR",
            "BOKOJI-TOWN",
            "CHOLE",
            "DEKSIS",
            "DIGELUNA TIJO",
            "DODOTA",
            "ENKELO WABE",
            "GOLOLCHA",
            "GUNA",
            "HITOSA",
            "JEJU",
            "LIMUNA BILBILO",
            "LODE HETOSA",
            "MERTI",
            "MUNESA",
            "ROBE",
            "SERU",
            "SHANAN KOLU",
            "SHIRKA",
            "SIRE",
            "SUDE",
            "TENA",
            "TIYO",
            "ZEWAY DUGDA",
            "Asela Town",
            "Inkolo Wabe",
        ],
        "Bale Zone" => [
            "Agarfa",
            "Berbere",
            "Dellomennaa",
            "Gasara",
            "Goba",
            "Goba Town",
            "Guradamole",
            "Haranna Bulluq",
            "Goro",
            "Madawalabu",
            "sinana",
            "Dinsho",
            "Robe Town",
        ],
        "Borena Zone" => [
            "Arero",
            "Dhas",
            "Dilo",
            "Dire",
            "Dubluk",
            "Elwaye",
            "Gomole",
            "Guchi",
            "Miyo",
            "Moyale",
            "Teltele",
            "Wachile",
            "Yabelo T.",
            "Yabelo",
            "Moyale Town",
        ],
        "Buno Bedele zone" => [
            "Bedele Town",
            "Bedele Rural",
            "Boracha",
            "Chawaqa",
            "Chora",
            "Dabo Hana",
            "Dega",
            "Didesa",
            "Gachi",
            "Makoo",
        ],
        "East Bale" => [
            "Ginnir Rural",
            "Gololcha",
            "Ginnir Town",
            "Sewena",
            "L/Hidha",
            "Raitu",
            "D/Kechan",
            "D/Serer",
        ],
        "East Harrergie" => [
            "Awaday Tawon",
            "Babilee",
            "Babile Tawon",
            "Bedano",
            "Dadar",
            "Dadar  Tawon",
            "Chinaksan",
            "Fadis",
            "Gurawa",
            "Goro Muxi",
            "Goro  Gutu",
            "Gola Oda",
            "Gursum",
            "Haromaya",
            "Haromya .T",
            "Jarso",
            "Kersa",
            "Kombolchaa",
            "Kumbi",
            "Kurfa chale",
            "Melka Belo",
            "Metaa",
            "Meyu Muluke",
            "Midhega Tola",
        ],
        "East Shoa zone" => [
            "Adama",
            "Ada'a",
            "Boset",
            "Bora",
            "Dugda",
            "Fentale",
            "Adami Tulu Jido Kombolcha",
            "Gimbichu",
            "Metehara Town",
            "Lume",
            "Liben",
            "Adama Town",
            "Batu Town",
            "Bishoftu Town",
            "Modjo Town",
            "Meki Town",
            "Akaki",
        ],
        "East Welega Zone" => [
            "Boneya Boshe",
            "Diga",
            "Ebantu",
            "Gida Ayana",
            "Gobu Seyo",
            "Gudeya Bila",
            "Guto Gidda",
            "Haro  Limu",
            "Jimma Arjo",
            "Kiremu",
            "Leka Dullecha",
            "Limmu",
            "Nunu Kumba",
            "Sasiga",
            "Sibu Sire",
            "Wama Hagelo",
            "Wayu Tuqa",
            "Nekemte Town",
            "Anger Gute",
        ],
        "Guji Zone" => [
            "Adola Rede",
            "Adola Town",
            "Aga Woyu",
            "Arda Jila Mie Boko",
            "Ana Sora",
            "Bore",
            "Girja",
            "Dama",
            "Gumi Eldalo",
            "Goro Dola",
            "Haro Walabu",
            "Liban",
            "Negele Town",
            "Odo Shakiso",
            "Saba  Boru",
            "Shakiso Town",
            "Uraga",
            "Wadara",
        ],
        "Horo Gudru  Zone" => [
            "Abay Chomman",
            "Abe Dongoro",
            "Amuru",
            "Choman Guduru",
            "Guduru",
            "Hababo Guduru",
            "Horo",
            "Horo Buluq",
            "Jardega Jarte",
            "Jima Geneti",
            "Jima Rare",
            "Shambu Tow",
            "Sulula Fincha",
        ],
        "Jimma Zone" => [
            "Aggaro",
            "Botor Xolay",
            "Chora Botor",
            "Dedo",
            "Gera",
            "Gomma",
            "Gummay",
            "Limmu Kossa",
            "Limmu seka",
            "Mencho",
            "Mana",
            "Nono Benja",
            "Omo Beyam",
            "Omo Nada",
            "Kersa",
            "Sekachekorsa",
            "Sentema",
            "Shabe sombo",
            "Sigimo",
            "Sokoru",
            "Tiro Afeta",
            "Jimma Town",
            "Limu Genet Twon",
        ],
        "Ilu Aba Bora Zone" => [
            "Alle",
            "Alge",
            "Becho",
            "B/Nophaa",
            "Bure",
            "Darimu",
            "Didu",
            "Doreni",
            "Halu",
            "Hurumu",
            "Mettu Town",
            "Mettu Woreda",
            "Nonno",
            "Yayo",
        ],
        "Kelem Wellega zone" => [
            "Anfilo",
            "Dale Sadi",
            "Dale Wabera",
            "Dambi dolo",
            "Gawo Kebe",
            "Gidami",
            "Hawa Galan",
            "Jima Horo",
            "Lalo Kile",
            "Sadi Chanka",
            "Sayo",
            "Yemalogi walal",
        ],
        "North Shoa Zone" => [
            "Abichu Gina",
            "Aleltu",
            "Debre Libanose",
            "Degem",
            "Dera",
            "Berek",
            "Mulo",
            "Sendafa",
            "Sululta",
            "Fiche Town",
            "Girar Jarso",
            "Hidabu Abote",
            "Jida",
            "Kimbibit",
            "Kuyu",
            "Wore Jarso",
            "Wuchale",
            "Yaya Gulale",
            "Chanco Town",
            "Gerbe Guracha Town",
            "Sheno Town",
        ],
        "West Arsi" => [
            "Adaba",
            "Dodola R",
            "Shashemene",
            "Kokossa",
            "Wondo",
            "Dodola T",
            "Shalla",
            "H/Arsi",
            "N/Arsi T",
            "N/Arsi R",
            "Siraro",
            "G/Hasasa",
            "Kofele",
            "Kore",
            "Nansabo",
            "Shashamene Town",
            "Bishan Guracha Town",
            "Kofel Twon",
        ],
        "West Guji" => [
            "Abaya",
            "Birbirsa Kojowa",
            "Bule Hora Town",
            "Bule Hora Woreda",
            "Dugda Dawa",
            "Galana",
            "Hambala wamana",
            "Kerchaa Woreda",
            "Malka Soda",
            "Suro Barguda",
            "Korecha Town",
        ],
        "West Harregie" => [
            "Anchar WorHO",
            "Bedesa Town",
            "Boke WorHO",
            "Burka Dhintu WorHO",
            "Chiro WorHO",
            "Chiro Zurya WorHO",
            "Daro Lebu WorHO",
            "Doba WorHO",
            "Gemechis WorHO",
            "Guba Qoricha WorHO",
            "Gumbi Bordode WorHO",
            "Habro WorHO",
            "Hawi Gudina WorHO",
            "Mesela WorHO",
            "Mieso WorHO",
            "Oda Bultum WorHO",
            "Tulo WorHO",
            "Gelelmso Town",
            "Herna Twon",
            "Mechare Town",
        ],
        "West shoa Zone" => [
            "Meta Robi",
            "Toke Kutaye",
            "Jibat",
            "Jeldu",
            "Abuna Gindeberet",
            "Bako Tibe",
            "Chelia",
            "Chobi",
            "Dandi",
            "Dano",
            "Ejere",
            "Ilfeta",
            "Gindeberet",
            "Elu Gelan",
            "Ejersa Lafo",
            "Liban Jawi",
            "Dire Inchini",
            "Ambo Rural",
            "Nono",
            "Mida Kegn",
            "Meta wolkite",
            "A/Bargaa",
            "Ambo Town",
            "Welmera",
            "Holeta Twon",
        ],
        "West Wellega Zone" => [
            "Ayira",
            "Babo Gambel",
            "Begi",
            "Bodji Chekorsa",
            "Bodji Dirmaji",
            "Genji",
            "Gimbi Rural",
            "Gimbi Town",
            "Guliso",
            "Haru",
            "Homa",
            "Jarso",
            "Kiltu Kara",
            "Lalo Asabi",
            "Leta Sibu",
            "Mendi Town",
            "Mene Sibu",
            "Nedjo Rural",
            "Nedjo Town",
            "Nole Kaba",
            "Qondala",
            "Seyo Nole",
            "Yubdo",
        ],
        "OSSF Zone/Sheger" => [
            "Galana Guda",
            "Sebeta Town",
            "Furi",
            "Gefersa Guji",
            "Burayu Town",
            "Melka Nonno",
            "Koye Fecha",
            "Gelan",
            "Kura Jida",
            "Eka Tafo",
            "Sululta",
            "Mana Abuchu",
        ],
        "S/W/Shoa Zone" => [
            "Ameya",
            "Bacho",
            "Dawo",
            "Goro",
            "Ilu",
            "Saden Sodo",
            "Tole",
            "Wonchi",
            "Waliso Rural",
            "Kersa Malima",
            "Sodo Dachi",
            "Woliso Town",
            "Sebeta Hawas",
        ],
    ],

    "Somali" => [
        "Afdar" => [
            "Barey",
            "Charati",
            "Dolo-bay",
            "Elkari",
            "God-God",
            "Hargele",
            "Qooxle",
            "Weast-Imay",
        ],
        "Dawa" => [
            "Hudhet",
            "Kadadumo",
            "Moyale",
            "Mubarak",
        ],
        "Dollo" => [
            "Bokh",
            "Danot",
            "Daratole",
            "Galadi",
            "Galhamer",
            "Lahel-yu'ub",
            "Warder",
        ],
        "Erer" => [
            "Fik",
            "Hamaro",
            "Lagahida",
            "Mayumuluqo",
            "Kubbi",
            "Salahad",
            "Wangay",
            "Yahob",
        ],
        "Fafan" => [
            "Awbare",
            "babili",
            "Goljano",
            "Gursum",
            "Harawo",
            "Harorays",
            "Harshin",
            "Jigjiga town",
            "Koran Mula",
            "Qabribayah town",
            "Qabribayah",
            "Jigjiga Zuria (Shabeley)",
            "Togwajale town",
            "Tuliguled",
        ],
        "Jarar" => [
            "Ararso",
            "Aware",
            "Bilelbour",
            "Birqot",
            "Dagehbour City",
            "Dagehbour",
            "Dagahmadow",
            "Darror",
            "Dig",
            "Gashamo",
            "Gunagado",
            "Yoalle",
        ],
        "Korahey" => [
            "Bodalay",
            "Dhoboweyn",
            "El-ogaden",
            "Goglo",
            "Higloley",
            "Kebridahar Town",
            "Kebridahar",
            "Lasdhankeyre",
            "Marsin",
            "Shaykosh",
            "Shilabo",
        ],
        "Liben" => [
            "Bokolmayo",
            "Dekasuftu",
            "Dolo-Ado",
            "Filtu",
            "Gorabakaksa",
            "Guradamole",
            "Karsadule",
        ],
        "Nogob" => [
            "Ayun",
            "Duhun",
            "Elwayne",
            "Garbo",
            "Hararey",
            "Horashagah",
            "Sagag",
        ],
        "Shabele" => [
            "Abaqaraw",
            "Adadle",
            "Bercano",
            "Danen",
            "East imay",
            "Elele",
            "Ferfer",
            "Godey City",
            "Godey",
            "Mustahil",
            "Qalafo",
        ],
        "Sitti" => [
            "Afdem",
            "Aysha'a",
            "Danbal",
            "Erer",
            "Gablalu",
            "Gotta Biki",
            "Hadagalle",
            "Maiso",
            "Shinile",
        ],
    ],

    "B/Gumuz" => [
        "Mao Komo special Woreda" => [
            "Mao Komo",
        ],
        "Kamashi Zone" => [
            "Kamashi Town",
            "Dambe",
            "Kamashi",
            "Sedal",
            "Zay",
            "Mizhiga",
        ],
        "Assosa Zone" => [
            "Asossa Twon",
            "Bambasi",
            "Oda Buldiglu",
            "Homosha",
            "Kurmuk",
            "Menge",
            "Ondulu",
            "Oura Woreda",
            "Sherkole",
            "Abrahamo",
        ],
        "Metekel zone" => [
            "Gelgel Beles Twon",
            "Bullen",
            "Dangur",
            "Guba",
            "Dibati",
            "Mandura",
            "Pawi",
            "Wonbera",
        ],
    ],

    "South Ethiopia" => [
        "Alle" => [
            "Alle",
            "KELLE",
            "AMARO",
        ],
        "Ari Zone" => [
            "Baka Dawula",
            "Gelila",
            "Jinka",
            "Norh Ari",
            "South Ari",
            "Woba Ari",
        ],
        "Baskato" => [
            "Basketo special",
            "Laska Town",
        ],
        "Burji" => [
            "Burji",
        ],
        "Derashe" => [
            "Gidole Town",
            "Derashe",
        ],
        "Gamo" => [
            "Arbaminch Town",
            "Arbaminch Zuria",
            "Birbir",
            "Bonke",
            "Boreda",
            "Chencha Town",
            "Chencha Zuria",
            "Deramalo",
            "Dita",
            "Gacho Baba",
            "Garda Marta",
            "Gersese Town",
            "Gerse Zuria",
            "Kemba Town",
            "Kemba Zuria",
            "Kucha",
            "Kucha Alfa",
            "Kogota",
            "Mirab Abaya",
            "Selamber Town",
        ],
        "Gedeo" => [
            "Bulle",
            "Chelelektu Town",
            "Chorso",
            "Dilla Town",
            "Dilla Zuria",
            "Gedeb Town",
            "Gedeb Woreda",
            "Kochore",
            "Rappe",
            "Wenago Town",
            "Wenago Woreda",
            "Yirgachefe Town",
            "Yirgachefe Woreda",
        ],
        "Gofa" => [
            "Beto Town",
            "Bulki Town",
            "Denba Gofa",
            "Melo Gada",
            "Geze Gofa",
            "Melo Koza",
            "Oyda",
            "Sawla Town",
            "UDT",
            "Zala",
            "Laha Town",
        ],
        "Konso" => [
            "Karat Zuria",
            "Segen Zuria",
            "Kenna",
            "Karat Town",
        ],
        "South Omo" => [
            "Benatsemay",
            "Dasenech",
            "Hamer",
            "Malie",
            "Ngangatom",
            "Salamago",
            "Turmi",
        ],
        "Wolayta" => [
            "Hobicha",
            "Kindo Didaye",
            "Kawo Koysha",
            "Gununo Town",
            "Sodo Zuriya",
            "Areka Town",
            "Damot Sore",
            "Abala Abaya",
            "Gesuba Town",
            "Bayra koysha",
            "Boloso Bombe",
            "Sodo Town",
            "Bodit Town",
            "Damot Pulasa",
            "Damote Woyde",
            "Damote Gale",
            "Duguna Fango",
            "Tabal",
            "Bale town",
            "Boloso Sore",
            "Ofa",
            "kindo Koysha",
            "Humbo",
        ],
    ],

    "Sidama" => [
        // No zones given – treat all as one "Sidama" zone
        "Sidama" => [
            "Aleta Chuko town",
            "Aleta Chuko Woreda",
            "Aleta Wondo town",
            "Aleta Wondo Woreda",
            "Arbegona Woreda",
            "Aroresa Woreda",
            "Bensa Woreda",
            "Bilate Zuria Woreda",
            "Bona Zuria Woreda",
            "Boricha Woreda",
            "Bura Woreda",
            "Bursa Woreda",
            "Chabe Gambeltu Woreda",
            "Chire Woreda",
            "Chirone Woreda",
            "Daela Woreda",
            "Dale Woreda",
            "Dara Otilcho Woreda",
            "Dara Woreda",
            "Darara Woreda",
            "Daye Town",
            "Gorche Woreda",
            "Hawassa Zuria Woreda",
            "Hawella Woreda",
            "Hoko Woreda",
            "Hula Woreda",
            "Leku town",
            "Loka Abaya Woreda",
            "Melga Woreda",
            "Shafamo Woreda",
            "Shebedino Woreda",
            "Teticha Woreda",
            "Wondo Genet Woreda",
            "Wondo Genet Town",
            "Wonsho Woreda",
            "Yirgalem Town",
            "Tula Woreda office",
            "Hawassa Town",
        ],
    ],

    "South West Ethiopia" => [
        "Bench" => [
            "Siz Town",
            "Gidi Bench",
            "Mizan Aman",
            "North Bench",
            "Sheko",
            "Sheybench",
            "South Bench",
            "Guraferda",
        ],
        "Dawro" => [
            "mareka",
            "Gessa citt Adiminstaratin",
            "Lomma",
            "Dissa",
            "Issera",
            "Gena",
            "Kechi",
            "Tarcha city  Adimin",
            "Tarcha Zuriya",
            "Zaba Gazo",
            "Mari manssa",
            "Tocha",
        ],
        "Keffa" => [
            "Adiyo",
            "Bitta",
            "Bonga",
            "Chena",
            "Cheta",
            "Decha",
            "Awurada",
            "Gesha",
            "Deka",
            "Gewata",
            "Gimbo",
            "Goba",
            "Sayilem",
            "Shishoende",
            "Shishinda",
            "Tello",
            "Wacha",
        ],
        "Konta" => [
            "Ela Anchano Woreda",
            "Konta Koysha Woreda",
            "Ameya Zuriya Woreda",
            "Chida City Adiministration",
            "Ameya City Adiministration",
        ],
        "Sheka" => [
            "Andracha Woreda",
            "Masha Town",
            "Masha Woreda",
            "Tepi Town",
            "Yeki Worda",
        ],
        "West Omo" => [
            "Bachuma TA",
            "Bero WorHO",
            "Gachit WorHO",
            "Gorigesha WorHO",
            "Jamu TA",
            "Maji WorHO",
            "Maji -Tumi Town addministration",
            "Menit goldia WorHO",
            "Menit shasha WorHO",
            "Surima WorHO",
        ],
    ],

    "Central Ethiopia" => [
        "East Gurage" => [
            "Buee",
            "Butajra tawon",
            "Enseno",
            "Mesikan",
            "Misrak mesikan",
            "Sodo",
            "South sodo",
        ],
        "Gurage" => [
            "Abeshge",
            "Agena",
            "Arekit",
            "Cheha",
            "Emidibr",
            "Enidegagn",
            "Enor",
            "Enor enor meger",
            "Ezea",
            "Geta",
            "GGW",
            "Gumer",
            "Gunichre",
            "M/akili",
            "wolkitte",
        ],
        "Hadiya" => [
            "Ameka",
            "Analemo",
            "Bonosha",
            "Duna",
            "East Badewacho",
            "Fonko",
            "Gibe",
            "Gimbichu",
            "Gombora",
            "Homocho",
            "Hossana",
            "Jajura",
            "Lemo",
            "Mirab soro",
            "Misha",
            "Shashego",
            "Shone",
            "Siraro Badewacho",
            "Soro",
            "West Badewacho",
        ],
        "Halaba" => [
            "Weradijo",
            "Wera woreda",
            "Kulito",
            "Atotiullo",
        ],
        "Kebena" => [
            "Kebena",
        ],
        "Kebmbata" => [
            "Adilo Zu",
            "Angecha WHOs",
            "Angecha Town",
            "Damboya WHOs",
            "Damboya Town",
            "Doyogena WHOs",
            "Doyogena Town",
            "Durame Town",
            "Hadero Tunto Zu",
            "HaderoTown",
            "Kacha Birra WHOs",
            "Kedida Gamela WHOs",
            "Shinshicho Town",
        ],
        "Mareko" => [
            "Mareko",
        ],
        "Silta" => [
            "Alemgebeya T.",
            "Alicho weriro",
            "Dalocha town",
            "Dalocha woreda",
            "East Azernet",
            "East silti",
            "Hulbareg",
            "kibet town",
            "Lanfuro",
            "Mitto",
            "Sankura",
            "Silti",
            "Tora town",
            "West azernet",
            "Worabe town",
        ],
        "yem" => [
            "yem",
        ],
        "Tembaro" => [
            "Mudula Town",
            "Tembaro WHOs",
        ],
    ],

    "Gambela" => [
        "Agniwa" => [
            "Abobo",
            "Dimma",
            "Gambella Zuria",
            "Gog",
            "Jor",
        ],
        "Nuer" => [
            "Akobo",
            "Jikawo",
            "Lare",
            "Makuey",
            "Wanthoa",
        ],
        "Majang" => [
            "Godere",
            "Mengeshi",
            "Itang Sp.Woreda",
            "Gambella town",
        ],
    ],

    "Harari" => [
        // No zones given – treat all as one "Harari" zone
        "Harari" => [
            "Abadir",
            "Aboker",
            "Amirnur",
            "Direteyara",
            "Erer",
            "Hakim",
            "Jenela",
            "Shenkor",
            "Sofi",
        ],
    ],

    "Dire Dawa" => [
        // Single zone for the city
        "Dire Dawa" => [
            "Adis Ketema Operational Woreda",
            "Legehare Operational Woreda",
            "Gende Kore Operational Woreda",
            "Dire Dawa Operational Woreda",
            "Goro Operational Woreda",
            "Melkajebdu Operational Woreda",
            "Biyoawale Operational Woreda",
            "Wahil Operational Woreda",
            "Jeldessa Operational Woreda",
        ],
    ],

    "Addis Ababa" => [
        // Treat sub-cities as "woredas" under one zone
        "Addis Ababa" => [
            "Addis Ketema",
            "Akaki Kality",
            "Arada",
            "Bole",
            "Gulele",
            "Kirkos",
            "Kolfe",
            "Lemmi Kura",
            "Lideta",
            "Nefas silk Lafto",
            "Yeka",
        ],
    ],
];

// Backwards compatible alias: some pages may refer to $locations directly
$locations = &$NEXUS_LOCATIONS;

// -------------------------------------------------------------------------
// 2. NAMESPACE PROTECTION – wrap all functions to avoid conflicts
// -------------------------------------------------------------------------
if (!function_exists('health_system_db_functions_loaded')) {

    /**
     * Marker so this block is not redefined.
     */
    function health_system_db_functions_loaded(): bool {
        return true;
    }

    // -----------------------------------------------------------------
    // 3. LOW-LEVEL SCHEMA & INTEGRITY HELPERS
    // -----------------------------------------------------------------

    /**
     * Check if a table has a specific column.
     */
    function table_has_column(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        } catch (Throwable $e) {
            error_log("[" . DB_APP_TAG . "] Error checking column $column in $table: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ensure a column exists in a table; if not, add it.
     */
    function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
        if (!table_has_column($pdo, $table, $column)) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                error_log("[" . DB_APP_TAG . "] Added column $column to $table");
            } catch (Throwable $e) {
                error_log("[" . DB_APP_TAG . "] Failed to add column $column to $table: " . $e->getMessage());
            }
        }
    }

    /**
     * Basic cross-app data integrity checks (non-blocking).
     */
    function check_data_integrity(PDO $pdo): void {
        try {
            $issues = [];

            // Check for reports without projects
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM reports r LEFT JOIN projects p ON r.project_id = p.id WHERE p.id IS NULL");
            if ($stmt && $stmt->fetch()['count'] > 0) {
                $issues[] = "Found reports without linked projects";
            }

            // Check for report_values without reports
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM report_values rv LEFT JOIN reports r ON rv.report_id = r.id WHERE r.id IS NULL");
            if ($stmt && $stmt->fetch()['count'] > 0) {
                $issues[] = "Found report values without linked reports";
            }

            // Check for activities without projects
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM activities a LEFT JOIN projects p ON a.project_id = p.id WHERE p.id IS NULL");
            if ($stmt && $stmt->fetch()['count'] > 0) {
                $issues[] = "Found activities without linked projects";
            }

            // Check for CFM reports without regions
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM cfm_reports WHERE region_id IS NULL");
            if ($stmt && $stmt->fetch()['count'] > 0) {
                $issues[] = "Found CFM reports without regions";
            }

            if (!empty($issues)) {
                error_log("[" . DB_APP_TAG . "] Data integrity issues: " . implode('; ', $issues));
            }

        } catch (Throwable $e) {
            error_log("[" . DB_APP_TAG . "] Data integrity check failed: " . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // 4. GEOGRAPHY FUNCTIONS (REGIONS / ZONES / WOREDAS)
    // -----------------------------------------------------------------

    // Optional helper to access the master locations array from other apps
    function get_location_master_array(): array {
        global $NEXUS_LOCATIONS;
        return $NEXUS_LOCATIONS ?? [];
    }

    function db_get_regions(?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM regions ORDER BY name");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching regions: " . $e->getMessage());
            return [];
        }
    }

    function db_get_zones_by_region(int $region_id, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM zones WHERE region_id = ? ORDER BY name");
            $stmt->execute([$region_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching zones: " . $e->getMessage());
            return [];
        }
    }

    function db_get_woredas_by_zone(int $zone_id, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM woredas WHERE zone_id = ? ORDER BY name");
            $stmt->execute([$zone_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching woredas: " . $e->getMessage());
            return [];
        }
    }

    function db_add_region(string $name, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("INSERT INTO regions (name) VALUES (?)");
            $stmt->execute([$name]);
            return ['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Region added successfully!'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function db_add_zone(string $name, int $region_id, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("INSERT INTO zones (name, region_id) VALUES (?, ?)");
            $stmt->execute([$name, $region_id]);
            return ['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Zone added successfully!'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function db_add_woreda(string $name, int $zone_id, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("INSERT INTO woredas (name, zone_id) VALUES (?, ?)");
            $stmt->execute([$name, $zone_id]);
            return ['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Woreda added successfully!'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function db_delete_region(int $id, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM zones WHERE region_id = ?");
            $check_stmt->execute([$id]);
            $zone_count = $check_stmt->fetchColumn();

            if ($zone_count > 0) {
                return ['success' => false, 'error' => 'Cannot delete region with existing zones'];
            }

            $stmt = $pdo->prepare("DELETE FROM regions WHERE id = ?");
            $stmt->execute([$id]);
            return ['success' => true, 'message' => 'Region deleted successfully!'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function db_delete_zone(int $id, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM woredas WHERE zone_id = ?");
            $check_stmt->execute([$id]);
            $woreda_count = $check_stmt->fetchColumn();

            if ($woreda_count > 0) {
                return ['success' => false, 'error' => 'Cannot delete zone with existing woredas'];
            }

            $stmt = $pdo->prepare("DELETE FROM zones WHERE id = ?");
            $stmt->execute([$id]);
            return ['success' => true, 'message' => 'Zone deleted successfully!'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function db_delete_woreda(int $id, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("DELETE FROM woredas WHERE id = ?");
            $stmt->execute([$id]);
            return ['success' => true, 'message' => 'Woreda deleted successfully!'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ----------------------------- NEW LOCATION HELPERS --------------------

    /**
     * Find a region by name (case-insensitive).
     */
    function db_find_region_by_name(string $name, ?PDO $pdo = null): ?array {
        if (!$pdo) $pdo = getPDO();
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM regions WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log("Error finding region by name: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a zone by name + region (case-insensitive).
     */
    function db_find_zone_by_name_and_region(string $name, int $region_id, ?PDO $pdo = null): ?array {
        if (!$pdo) $pdo = getPDO();
        $name = trim($name);
        if ($name === '' || $region_id <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM zones WHERE region_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$region_id, $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log("Error finding zone by name: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a woreda by name + zone (case-insensitive).
     */
    function db_find_woreda_by_name_and_zone(string $name, int $zone_id, ?PDO $pdo = null): ?array {
        if (!$pdo) $pdo = getPDO();
        $name = trim($name);
        if ($name === '' || $zone_id <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM woredas WHERE zone_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$zone_id, $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log("Error finding woreda by name: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ensure region / zone / woreda IDs exist given names (used for "Other (specify)").
     * Returns ['region_id' => ?, 'zone_id' => ?, 'woreda_id' => ?].
     */
    function db_ensure_location_ids_from_names(?string $region_name, ?string $zone_name, ?string $woreda_name, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();

        $region_id = null;
        $zone_id   = null;
        $woreda_id = null;

        $region_name = $region_name !== null ? trim($region_name) : '';
        $zone_name   = $zone_name !== null ? trim($zone_name) : '';
        $woreda_name = $woreda_name !== null ? trim($woreda_name) : '';

        try {
            // Region
            if ($region_name !== '') {
                $existingRegion = db_find_region_by_name($region_name, $pdo);
                if ($existingRegion) {
                    $region_id = (int)$existingRegion['id'];
                } else {
                    $add = db_add_region($region_name, $pdo);
                    if (!empty($add['success']) && $add['success']) {
                        $region_id = (int)$add['id'];
                    }
                }
            }

            // Zone
            if ($zone_name !== '' && $region_id !== null) {
                $existingZone = db_find_zone_by_name_and_region($zone_name, $region_id, $pdo);
                if ($existingZone) {
                    $zone_id = (int)$existingZone['id'];
                } else {
                    $add = db_add_zone($zone_name, $region_id, $pdo);
                    if (!empty($add['success']) && $add['success']) {
                        $zone_id = (int)$add['id'];
                    }
                }
            }

            // Woreda
            if ($woreda_name !== '' && $zone_id !== null) {
                $existingWoreda = db_find_woreda_by_name_and_zone($woreda_name, $zone_id, $pdo);
                if ($existingWoreda) {
                    $woreda_id = (int)$existingWoreda['id'];
                } else {
                    $add = db_add_woreda($woreda_name, $zone_id, $pdo);
                    if (!empty($add['success']) && $add['success']) {
                        $woreda_id = (int)$add['id'];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("[" . DB_APP_TAG . "] db_ensure_location_ids_from_names failed: " . $e->getMessage());
        }

        return [
            'region_id' => $region_id,
            'zone_id'   => $zone_id,
            'woreda_id' => $woreda_id,
        ];
    }

    /**
     * Get location names from IDs.
     */
    function db_get_location_names_by_ids(?int $region_id, ?int $zone_id, ?int $woreda_id, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();

        $region_name = null;
        $zone_name   = null;
        $woreda_name = null;

        try {
            if ($region_id) {
                $stmt = $pdo->prepare("SELECT name FROM regions WHERE id = ? LIMIT 1");
                $stmt->execute([$region_id]);
                $region_name = $stmt->fetchColumn() ?: null;
            }
            if ($zone_id) {
                $stmt = $pdo->prepare("SELECT name FROM zones WHERE id = ? LIMIT 1");
                $stmt->execute([$zone_id]);
                $zone_name = $stmt->fetchColumn() ?: null;
            }
            if ($woreda_id) {
                $stmt = $pdo->prepare("SELECT name FROM woredas WHERE id = ? LIMIT 1");
                $stmt->execute([$woreda_id]);
                $woreda_name = $stmt->fetchColumn() ?: null;
            }
        } catch (PDOException $e) {
            error_log("Error fetching location names by IDs: " . $e->getMessage());
        }

        return [
            'region_name' => $region_name,
            'zone_name'   => $zone_name,
            'woreda_name' => $woreda_name,
        ];
    }

    /**
     * Single JSON-ready structure for JS dropdowns:
     *   [
     *      'regions' => [ ['id'=>..,'name'=>..], ... ],
     *      'zones'   => [ ['id'=>..,'region_id'=>..,'name'=>..], ... ],
     *      'woredas' => [ ['id'=>..,'zone_id'=>..,'name'=>..], ... ],
     *   ]
     * If DB is empty, it falls back to the master PHP array.
     */
    function db_get_locations_for_js(?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();

        try {
            $regions = db_get_regions($pdo);
            $zones   = db_fetch_all("SELECT id, region_id, name FROM zones ORDER BY name");
            $woredas = db_fetch_all("SELECT id, zone_id, name FROM woredas ORDER BY name");

            if (empty($regions) && empty($zones) && empty($woredas)) {
                // Fallback: build from master array with synthetic IDs
                $master    = get_location_master_array();
                $regionsJ  = [];
                $zonesJ    = [];
                $woredasJ  = [];
                $rid       = 1;
                $zid       = 1;

                foreach ($master as $rName => $zonesArr) {
                    $regionsJ[] = ['id' => $rid, 'name' => $rName];
                    foreach ($zonesArr as $zName => $woredasArr) {
                        $zonesJ[] = ['id' => $zid, 'region_id' => $rid, 'name' => $zName];
                        foreach ($woredasArr as $wName) {
                            $woredasJ[] = ['id' => null, 'zone_id' => $zid, 'name' => $wName];
                        }
                        $zid++;
                    }
                    $rid++;
                }

                return [
                    'regions' => $regionsJ,
                    'zones'   => $zonesJ,
                    'woredas' => $woredasJ,
                ];
            }

            return [
                'regions' => $regions,
                'zones'   => $zones,
                'woredas' => $woredas,
            ];
        } catch (Throwable $e) {
            error_log("[" . DB_APP_TAG . "] db_get_locations_for_js failed: " . $e->getMessage());
            return [
                'regions' => [],
                'zones'   => [],
                'woredas' => [],
            ];
        }
    }

    /**
     * Import comprehensive geographical data from the master $NEXUS_LOCATIONS array.
     * This will:
     * - Insert regions if missing
     * - Insert zones for each region
     * - Insert woredas for each zone
     *
     * Uses INSERT IGNORE + UNIQUE constraints, so it is safe to run more than once.
     */
    function db_import_comprehensive_geographical_data(?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        global $NEXUS_LOCATIONS;

        if (!isset($NEXUS_LOCATIONS) || !is_array($NEXUS_LOCATIONS) || empty($NEXUS_LOCATIONS)) {
            return ['success' => false, 'error' => 'Master location array (NEXUS_LOCATIONS) is not defined or empty'];
        }

        try {
            $pdo->beginTransaction();

            $regionsImported = 0;
            $zonesImported   = 0;
            $woredasImported = 0;
            $totalImported   = 0;

            $regionInsert = $pdo->prepare("INSERT IGNORE INTO regions (name) VALUES (?)");
            $regionSelect = $pdo->prepare("SELECT id FROM regions WHERE name = ?");

            $zoneInsert   = $pdo->prepare("INSERT IGNORE INTO zones (name, region_id) VALUES (?, ?)");
            $zoneSelect   = $pdo->prepare("SELECT id FROM zones WHERE name = ? AND region_id = ?");

            $woredaInsert = $pdo->prepare("INSERT IGNORE INTO woredas (name, zone_id) VALUES (?, ?)");
            $woredaSelect = $pdo->prepare("SELECT id FROM woredas WHERE name = ? AND zone_id = ?");

            foreach ($NEXUS_LOCATIONS as $regionName => $zones) {
                // REGION
                $regionInsert->execute([$regionName]);
                $regionId = $pdo->lastInsertId();

                if (!$regionId) {
                    $regionSelect->execute([$regionName]);
                    $regionId = $regionSelect->fetchColumn();
                } else {
                    $regionsImported++;
                }

                if (!$regionId) {
                    continue; // skip if we still didn't get id
                }

                // ZONES
                foreach ($zones as $zoneName => $woredas) {
                    $zoneInsert->execute([$zoneName, $regionId]);
                    $zoneId = $pdo->lastInsertId();

                    if (!$zoneId) {
                        $zoneSelect->execute([$zoneName, $regionId]);
                        $zoneId = $zoneSelect->fetchColumn();
                    } else {
                        $zonesImported++;
                    }

                    if (!$zoneId) {
                        continue;
                    }

                    // WOREDAS
                    foreach ($woredas as $woredaName) {
                        $woredaInsert->execute([$woredaName, $zoneId]);
                        if ($pdo->lastInsertId()) {
                            $woredasImported++;
                            $totalImported++;
                        }
                    }
                }
            }

            $pdo->commit();
            return [
                'success' => true,
                'message' => "Imported geographical data: $regionsImported regions, $zonesImported zones, $woredasImported woredas",
                'stats'   => [
                    'regions' => $regionsImported,
                    'zones'   => $zonesImported,
                    'woredas' => $woredasImported,
                    'total'   => $totalImported,
                ],
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // -----------------------------------------------------------------
    // 5. CFM REPORT FUNCTIONS
    // -----------------------------------------------------------------

    function db_get_cfm_reports_with_details(?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();

        try {
            $sql = "
                SELECT 
                    cr.*,
                    p.title as project_title,
                    r.name as region_name,
                    z.name as zone_name,
                    w.name as woreda_name,
                    u.full_name as creator_name
                FROM cfm_reports cr
                LEFT JOIN projects p ON cr.project_id = p.id
                LEFT JOIN regions r ON cr.region_id = r.id
                LEFT JOIN zones z ON cr.zone_id = z.id
                LEFT JOIN woredas w ON cr.woreda_id = w.id
                LEFT JOIN users u ON cr.created_by = u.id
                ORDER BY cr.date_feedback_received DESC, cr.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching CFM reports: " . $e->getMessage());
            return [];
        }
    }

    function db_add_cfm_report(array $data, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();

        try {
            $zone_id               = !empty($data['zone_id']) ? $data['zone_id'] : null;
            $woreda_id             = !empty($data['woreda_id']) ? $data['woreda_id'] : null;
            $age                   = !empty($data['age']) ? $data['age'] : null;
            $expected_closure_date = !empty($data['expected_closure_date']) ? $data['expected_closure_date'] : null;

            $stmt = $pdo->prepare("
                INSERT INTO cfm_reports (
                    project_id, reported_by, position, date_feedback_received, date_of_report,
                    feedback_type, organization, region_id, zone_id, woreda_id, gender, age,
                    community_type, vulnerability, language, actual_feedback, feedback_channel,
                    feedback_category, feedback_concern, feedback_status, actions_taken,
                    responsibility_follow_up, expected_closure_date, reason_closure_passed,
                    recommendation, created_by, ai_recommendation
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['project_id'] ?? null,
                $data['reported_by'] ?? '',
                $data['position'] ?? '',
                $data['date_feedback_received'] ?? '',
                $data['date_of_report'] ?? date('Y-m-d'),
                $data['feedback_type'] ?? 'new',
                $data['organization'] ?? '',
                $data['region_id'] ?? null,
                $zone_id,
                $woreda_id,
                $data['gender'] ?? '',
                $age,
                $data['community_type'] ?? '',
                $data['vulnerability'] ?? '',
                $data['language'] ?? 'Amharic',
                $data['actual_feedback'] ?? '',
                $data['feedback_channel'] ?? '',
                $data['feedback_category'] ?? '',
                $data['feedback_concern'] ?? '',
                $data['feedback_status'] ?? 'New',
                $data['actions_taken'] ?? '',
                $data['responsibility_follow_up'] ?? '',
                $expected_closure_date,
                $data['reason_closure_passed'] ?? '',
                $data['recommendation'] ?? '',
                $data['created_by'] ?? 1,
                $data['ai_recommendation'] ?? ''
            ]);

            $report_id = $pdo->lastInsertId();

            return ['success' => true, 'id' => $report_id, 'message' => 'CFM report added successfully!'];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // -----------------------------------------------------------------
    // 6. PROJECTS / LOCATIONS HELPERS
    // -----------------------------------------------------------------

    function db_get_projects(?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("SELECT id, title FROM projects ORDER BY title");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching projects: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get primary location row for a project (from project_locations).
     */
    function db_get_project_primary_location(int $project_id, ?PDO $pdo = null): ?array {
        if (!$pdo) $pdo = getPDO();
        if ($project_id <= 0) {
            return null;
        }

        try {
            $sql = "
                SELECT 
                    pl.*,
                    r.name AS region_name_resolved,
                    z.name AS zone_name_resolved,
                    w.name AS woreda_name_resolved
                FROM project_locations pl
                LEFT JOIN regions r ON pl.region_id = r.id
                LEFT JOIN zones z   ON pl.zone_id   = z.id
                LEFT JOIN woredas w ON pl.woreda_id = w.id
                WHERE pl.project_id = ? AND pl.is_primary = 1
                ORDER BY pl.id ASC
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$project_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log("Error fetching primary project location: " . $e->getMessage());
            return null;
        }
    }

    // -----------------------------------------------------------------
    // 7. SCHEMA UPDATE FOR CROSS-APP COMPATIBILITY
    // -----------------------------------------------------------------

    function db_update_schema_if_needed(PDO $pdo): void {
        error_log("[" . DB_APP_TAG . "] Starting schema update check...");

        $columns_to_check = [
            'reports' => [
                'region_id'          => 'INT NULL',
                'zone_id'            => 'INT NULL',
                'woreda_id'          => 'INT NULL',
                'timeliness_score'   => 'DECIMAL(5,2) DEFAULT 0',
                'completeness_score' => 'DECIMAL(5,2) DEFAULT 0',
                'accuracy_score'     => 'DECIMAL(5,2) DEFAULT 0',
                'year'               => 'INT NOT NULL',
                'month'              => 'INT DEFAULT NULL',
                'start_date'         => 'DATE NULL'
            ],
            'projects' => [
                // Core identifiers & descriptive fields
                'code'           => 'VARCHAR(100) NULL',
                'title'          => 'VARCHAR(255) NOT NULL',
                'description'    => 'TEXT NULL',
                'location'       => 'VARCHAR(255) NULL',

                // Donor / type fields used across apps
                'donor'          => 'VARCHAR(255) NULL',
                'donor_ref'      => 'VARCHAR(255) NULL',
                'donor_id'       => 'INT NULL',
                'project_type'   => 'VARCHAR(150) NULL',
                'project_type_id'=> 'INT NULL',
                'ip_name'        => 'VARCHAR(255) NULL',
                'PM_email'       => 'VARCHAR(255) NULL',

                // Geo scope
                'region_id'      => 'INT NULL',
                'zone_id'        => 'INT NULL',
                'woreda_id'      => 'INT NULL',
                'region_other'   => 'VARCHAR(150) NULL',
                'zone_other'     => 'VARCHAR(150) NULL',
                'woreda_other'   => 'VARCHAR(150) NULL',

                // Sector fields
                'main_sectors'   => 'TEXT NULL',
                'specific_sectors'=> 'TEXT NULL',

                // Budget fields used by budget.php / aggregation.php / reporting
                'total_budget'   => 'DECIMAL(18,2) DEFAULT 0',
                'budget_currency'=> "VARCHAR(20) NOT NULL DEFAULT 'USD'",
                'total_fund_usd' => 'DECIMAL(18,2) DEFAULT 0',
                'currency_rate'  => 'DECIMAL(18,4) DEFAULT 0',
                'total_fund_etb' => 'DECIMAL(18,2) DEFAULT 0',

                // Status and dates
                'status'         => "VARCHAR(50) NOT NULL DEFAULT 'active'",
                'start_date'     => 'DATE NULL',
                'end_date'       => 'DATE NULL',

                // Ownership / audit (API & future use)
                'created_by'     => 'INT NULL'
            ],
            'project_beneficiaries' => [
                'region_other' => 'VARCHAR(150) NULL',
                'zone_other'   => 'VARCHAR(150) NULL',
                'woreda_other' => 'VARCHAR(150) NULL',
                'hh_count'     => 'INT DEFAULT 0'
            ],

            'users' => [
                'access_all_projects' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'is_active'           => 'TINYINT(1) NOT NULL DEFAULT 1',
                'full_name'           => 'VARCHAR(190) NULL',
                'role'                => "ENUM('admin','user','guest') NOT NULL DEFAULT 'user'",
                'phone'               => 'VARCHAR(50) NULL',
                'photo_path'          => 'VARCHAR(255) NULL',
                'last_login_at'       => 'DATETIME NULL',
                'is_online'           => 'TINYINT(1) NOT NULL DEFAULT 0'
            ],
            'activities' => [
                'project_id' => 'INT NOT NULL',
                'code'       => 'VARCHAR(50) NULL',
                'name'       => 'TEXT NOT NULL',
                'unit'       => 'VARCHAR(50) NULL',
                'target'     => 'INT DEFAULT 0'
            ],
            'messages' => [
                'project_id'              => 'INT NULL',
                'to_email'                => 'VARCHAR(255) NULL',
                'cc_emails'               => 'TEXT NULL',
                'bcc_emails'              => 'TEXT NULL',
                'is_read'                 => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_draft'                => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_deleted_by_sender'    => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_deleted_by_recipient' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'attachment_path'         => 'VARCHAR(255) NULL',
                'attachment_name'         => 'VARCHAR(255) NULL',
                'reply_to_id'             => 'INT NULL',
                'read_at'                 => 'DATETIME NULL',
                'updated_at'              => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'
            ],
            // NEW: ensure project_locations has full columns so all apps share same structure
            'project_locations' => [
                'is_primary'             => 'TINYINT(1) NOT NULL DEFAULT 0',
                'region_id'              => 'INT NULL',
                'zone_id'                => 'INT NULL',
                'woreda_id'              => 'INT NULL',
                'region_name'            => 'VARCHAR(150) NULL',
                'zone_name'              => 'VARCHAR(150) NULL',
                'woreda_name'            => 'VARCHAR(150) NULL',
                'latitude'               => 'DECIMAL(10,7) NULL',
                'longitude'              => 'DECIMAL(10,7) NULL',
                'sectors_json'           => 'TEXT NULL',
                'specific_sectors_json'  => 'TEXT NULL'
            ]
        ];

        foreach ($columns_to_check as $table => $columns) {
            foreach ($columns as $column => $definition) {
                ensure_column($pdo, $table, $column, $definition);
            }
        }

        // Ensure budget_installments table exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS budget_installments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    budget_id INT NOT NULL,
                    installment_no INT NOT NULL,
                    currency VARCHAR(10) DEFAULT 'ETB',
                    rate DECIMAL(18,4) DEFAULT 1,
                    percent DECIMAL(5,2) DEFAULT 0,
                    amount_etb DECIMAL(18,2) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log("[" . DB_APP_TAG . "] Error creating budget_installments table: " . $e->getMessage());
        }

        

        // PLANNING MODULE: ensure tables/columns exist and expand results_chain levels (objective/outcome/output + impact)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS impact_indicators (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                result_id INT NOT NULL,
                beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
                indicator_code VARCHAR(50),
                indicator_name TEXT NOT NULL,
                unit_type VARCHAR(50) DEFAULT 'persons',
                input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
                total_target DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                boys_u5 INT DEFAULT 0,
                girls_u5 INT DEFAULT 0,
                boys_5_17 INT DEFAULT 0,
                girls_5_17 INT DEFAULT 0,
                men_18_59 INT DEFAULT 0,
                women_18_59 INT DEFAULT 0,
                men_60p INT DEFAULT 0,
                women_60p INT DEFAULT 0,
                pwd_count INT DEFAULT 0,
                hh_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS outcome_indicators (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                result_id INT NOT NULL,
                beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
                indicator_code VARCHAR(50),
                indicator_name TEXT NOT NULL,
                unit_type VARCHAR(50) DEFAULT 'persons',
                input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
                total_target DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                boys_u5 INT DEFAULT 0,
                girls_u5 INT DEFAULT 0,
                boys_5_17 INT DEFAULT 0,
                girls_5_17 INT DEFAULT 0,
                men_18_59 INT DEFAULT 0,
                women_18_59 INT DEFAULT 0,
                men_60p INT DEFAULT 0,
                women_60p INT DEFAULT 0,
                pwd_count INT DEFAULT 0,
                hh_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS output_indicators (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                result_id INT NOT NULL,
                beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
                indicator_code VARCHAR(50),
                indicator_name TEXT NOT NULL,
                unit_type VARCHAR(50) DEFAULT 'persons',
                input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
                target_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                boys_u5 INT DEFAULT 0,
                girls_u5 INT DEFAULT 0,
                boys_5_17 INT DEFAULT 0,
                girls_5_17 INT DEFAULT 0,
                men_18_59 INT DEFAULT 0,
                women_18_59 INT DEFAULT 0,
                men_60p INT DEFAULT 0,
                women_60p INT DEFAULT 0,
                pwd_count INT DEFAULT 0,
                hh_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            error_log("[" . DB_APP_TAG . "] Planning tables create warning: " . $e->getMessage());
        }

        // Expand results_chain levels to include 'impact' (keeps existing values)
        try {
            $pdo->exec("ALTER TABLE results_chain MODIFY level ENUM('objective','impact','outcome','output') NOT NULL");
        } catch (Throwable $e) {
            error_log("[" . DB_APP_TAG . "] results_chain enum update warning: " . $e->getMessage());
        }

        // Ensure hh_count exists (older installs)
        ensure_column($pdo, 'impact_indicators', 'hh_count', 'INT DEFAULT 0');
        ensure_column($pdo, 'outcome_indicators', 'hh_count', 'INT DEFAULT 0');
        ensure_column($pdo, 'output_indicators', 'hh_count', 'INT DEFAULT 0');
        ensure_column($pdo, 'output_indicators', 'target_total', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00');


        // Ensure indicator totals support decimals (%, kg, km, meter, m2, etc)
        // (Older installs might have INT columns; MODIFY keeps existing values.)
        try { $pdo->exec("ALTER TABLE impact_indicators MODIFY COLUMN total_target DECIMAL(14,2) NOT NULL DEFAULT 0.00"); } catch (PDOException $e) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE outcome_indicators MODIFY COLUMN total_target DECIMAL(14,2) NOT NULL DEFAULT 0.00"); } catch (PDOException $e) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE output_indicators MODIFY COLUMN target_total DECIMAL(14,2) NOT NULL DEFAULT 0.00"); } catch (PDOException $e) { /* ignore */ }

error_log("[" . DB_APP_TAG . "] Schema update check completed");
    }

    // -----------------------------------------------------------------
    // 8. SCHEMA CREATION + SEEDING
    // -----------------------------------------------------------------

    function db_ensure_schema(PDO $pdo): void {
        error_log("[" . DB_APP_TAG . "] Ensuring database schema...");

        // USERS
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(200) NOT NULL,
            email VARCHAR(200) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','user','guest') NOT NULL DEFAULT 'user',
            phone VARCHAR(50),
            photo_path VARCHAR(255),
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            access_all_projects TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            is_online TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // If old DB used `name`, rename to `full_name` (ignore error if not)
        try {
            $pdo->exec("ALTER TABLE users CHANGE name full_name VARCHAR(200) NOT NULL");
        } catch (PDOException $e) {
            // ignore
        }

        // REGIONS / ZONES / WOREDAS
        $pdo->exec("CREATE TABLE IF NOT EXISTS regions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS zones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            region_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
            UNIQUE KEY unique_zone_region (name, region_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS woredas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zone_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
            UNIQUE KEY unique_woreda_zone (name, zone_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // PROJECTS (create BEFORE cfm_reports to avoid FK issues)
        $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(100) NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            location VARCHAR(255) NULL,

            donor VARCHAR(255) NULL,
            donor_ref VARCHAR(255) NULL,
            donor_id INT NULL,

            project_type VARCHAR(150) NULL,
            project_type_id INT NULL,

            ip_name VARCHAR(255) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            total_budget DECIMAL(18,2) DEFAULT 0,
            budget_currency VARCHAR(20) DEFAULT 'USD',

            total_fund_usd DECIMAL(18,2) DEFAULT 0,
            currency_rate DECIMAL(18,4) DEFAULT 0,
            total_fund_etb DECIMAL(18,2) DEFAULT 0,

            owner_name VARCHAR(255) NULL,
            owner_email VARCHAR(255) NULL,
            owner_phone VARCHAR(50) NULL,
            ed_email VARCHAR(255) NULL,
            opm_email VARCHAR(255) NULL,
            PM_email VARCHAR(255) NULL,
            merl_email VARCHAR(255) NULL,
            finance_head_email VARCHAR(255) NULL,
            finance_officer_email VARCHAR(255) NULL,
            finance_officer_email2 VARCHAR(255) NULL,
            project_officer_email VARCHAR(255) NULL,

            region_id INT NULL,
            zone_id INT NULL,
            woreda_id INT NULL,
            region_other VARCHAR(150) NULL,
            zone_other VARCHAR(150) NULL,
            woreda_other VARCHAR(150) NULL,

            main_sectors TEXT NULL,
            specific_sectors TEXT NULL,

            start_date DATE NULL,
            end_date DATE NULL,

            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

            INDEX idx_projects_code (code),
            INDEX idx_projects_status (status),
            INDEX idx_projects_region (region_id),
            INDEX idx_projects_zone (zone_id),
            INDEX idx_projects_woreda (woreda_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // NEW: PROJECT LOCATIONS – used by projects.php, planning.php, geography, etc.
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            region_id INT NULL,
            zone_id INT NULL,
            woreda_id INT NULL,
            region_name VARCHAR(150) NULL,
            zone_name VARCHAR(150) NULL,
            woreda_name VARCHAR(150) NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            sectors_json TEXT NULL,
            specific_sectors_json TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_project_locations_project
                FOREIGN KEY (project_id) REFERENCES projects(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // CFM REPORTS
        $pdo->exec("CREATE TABLE IF NOT EXISTS cfm_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NULL,
            reported_by VARCHAR(255) NOT NULL,
            position VARCHAR(255) NOT NULL,
            date_feedback_received DATE NOT NULL,
            date_of_report DATE NOT NULL,
            feedback_type ENUM('new','pending') NOT NULL DEFAULT 'new',
            organization VARCHAR(255) NOT NULL,
            region_id INT NULL,
            zone_id INT NULL,
            woreda_id INT NULL,
            gender ENUM('Male','Female','Other') NOT NULL,
            age INT NULL,
            community_type VARCHAR(100) NULL,
            vulnerability VARCHAR(100) NULL,
            language VARCHAR(100) NOT NULL DEFAULT 'Amharic',
            actual_feedback TEXT NOT NULL,
            feedback_channel VARCHAR(100) NOT NULL,
            feedback_category VARCHAR(100) NOT NULL,
            feedback_concern TEXT NOT NULL,
            feedback_status ENUM('New','Under Review','Action Taken','Resolved','Closed') NOT NULL DEFAULT 'New',
            actions_taken TEXT NULL,
            responsibility_follow_up VARCHAR(255) NULL,
            expected_closure_date DATE NULL,
            reason_closure_passed TEXT NULL,
            recommendation TEXT NULL,
            ai_recommendation TEXT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
            FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
            FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
            FOREIGN KEY (woreda_id) REFERENCES woredas(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // INDICATORS
        $pdo->exec("CREATE TABLE IF NOT EXISTS indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50),
            name TEXT NOT NULL,
            unit_type VARCHAR(50) DEFAULT 'persons',
            input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
            is_active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("ALTER TABLE indicators ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        } catch (PDOException $e) {
            // ignore if exists
        }

        // REPORTS
        $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT,
            region_id INT,
            zone_id INT,
            woreda_id INT,
            period_type ENUM('weekly','monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
            year INT NOT NULL,
            month INT DEFAULT NULL,
            week INT DEFAULT NULL,
            start_date DATE,
            end_date DATE,
            status ENUM('draft','submitted') NOT NULL DEFAULT 'submitted',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            submitted_at TIMESTAMP NULL DEFAULT NULL,
            timeliness_score DECIMAL(5,2) DEFAULT 0,
            completeness_score DECIMAL(5,2) DEFAULT 0,
            accuracy_score DECIMAL(5,2) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // REPORT VALUES
        $pdo->exec("CREATE TABLE IF NOT EXISTS report_values (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            indicator_id INT NOT NULL,
            beneficiary_type ENUM('host','refugee','pwd','total') NOT NULL DEFAULT 'total',
            boys_u5 INT DEFAULT 0,
            girls_u5 INT DEFAULT 0,
            boys_5_17 INT DEFAULT 0,
            girls_5_17 INT DEFAULT 0,
            men_18_59 INT DEFAULT 0,
            women_18_59 INT DEFAULT 0,
            men_60p INT DEFAULT 0,
            women_60p INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            non_beneficiary INT DEFAULT 0,
            UNIQUE (report_id, indicator_id, beneficiary_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // MESSAGES
        $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NULL,
            from_user_id INT NOT NULL,
            to_user_id INT NULL,
            to_email VARCHAR(255),
            cc_emails TEXT,
            bcc_emails TEXT,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            is_draft TINYINT(1) NOT NULL DEFAULT 0,
            is_deleted_by_sender TINYINT(1) NOT NULL DEFAULT 0,
            is_deleted_by_recipient TINYINT(1) NOT NULL DEFAULT 0,
            attachment_path VARCHAR(255) DEFAULT NULL,
            attachment_name VARCHAR(255) DEFAULT NULL,
            reply_to_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // DASHBOARD WIDGETS
        $pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_widgets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            config TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // RESULTS CHAIN
        $pdo->exec("CREATE TABLE IF NOT EXISTS results_chain (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            level ENUM('objective','impact','outcome','output') NOT NULL,
            code VARCHAR(50),
            name TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ACTIVITIES
        $pdo->exec("CREATE TABLE IF NOT EXISTS activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            output_id INT,
            code VARCHAR(50),
            name TEXT NOT NULL,
            unit VARCHAR(50),
            target INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


        // PLANNING MODULE: INDICATORS & BENEFICIARIES (DHIS2-like logframe support)
        $pdo->exec("CREATE TABLE IF NOT EXISTS impact_indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            result_id INT NOT NULL,
            beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
            indicator_code VARCHAR(50),
            indicator_name TEXT NOT NULL,
            unit_type VARCHAR(50) DEFAULT 'persons',
            input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
            total_target DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            boys_u5 INT DEFAULT 0,
            girls_u5 INT DEFAULT 0,
            boys_5_17 INT DEFAULT 0,
            girls_5_17 INT DEFAULT 0,
            men_18_59 INT DEFAULT 0,
            women_18_59 INT DEFAULT 0,
            men_60p INT DEFAULT 0,
            women_60p INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            hh_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS outcome_indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            result_id INT NOT NULL,
            beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
            indicator_code VARCHAR(50),
            indicator_name TEXT NOT NULL,
            unit_type VARCHAR(50) DEFAULT 'persons',
            input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
            total_target DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            boys_u5 INT DEFAULT 0,
            girls_u5 INT DEFAULT 0,
            boys_5_17 INT DEFAULT 0,
            girls_5_17 INT DEFAULT 0,
            men_18_59 INT DEFAULT 0,
            women_18_59 INT DEFAULT 0,
            men_60p INT DEFAULT 0,
            women_60p INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            hh_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS output_indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            result_id INT NOT NULL,
            beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
            indicator_code VARCHAR(50),
            indicator_name TEXT NOT NULL,
            unit_type VARCHAR(50) DEFAULT 'persons',
            input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
            target_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            boys_u5 INT DEFAULT 0,
            girls_u5 INT DEFAULT 0,
            boys_5_17 INT DEFAULT 0,
            girls_5_17 INT DEFAULT 0,
            men_18_59 INT DEFAULT 0,
            women_18_59 INT DEFAULT 0,
            men_60p INT DEFAULT 0,
            women_60p INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            hh_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS project_beneficiaries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            region_id INT NULL,
            zone_id INT NULL,
            woreda_id INT NULL,
            region_other VARCHAR(150) NULL,
            zone_other VARCHAR(150) NULL,
            woreda_other VARCHAR(150) NULL,
            beneficiary_type ENUM('host_community','refugee','returnee','idp','pwd','other') NOT NULL,
            women INT DEFAULT 0,
            girls INT DEFAULT 0,
            men INT DEFAULT 0,
            boys INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            hh_count INT DEFAULT 0,
            total INT DEFAULT 0,
            share_percentage DECIMAL(5,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


        // ACTIVITY BUDGET
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_budget (
            id INT AUTO_INCREMENT PRIMARY KEY,
            activity_id INT NOT NULL,
            budget_code VARCHAR(50),
            description TEXT,
            unit_description VARCHAR(100),
            unit_quantity DECIMAL(18,2) DEFAULT 0,
            unit_cost DECIMAL(18,2) DEFAULT 0,
            duration INT DEFAULT 1,
            percent_cbpf DECIMAL(5,2) DEFAULT 0,
            total_cost DECIMAL(18,2) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // BUDGET FORECAST
        $pdo->exec("CREATE TABLE IF NOT EXISTS budget_forecast (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            activity_id INT,
            budget_line_code VARCHAR(50),
            budget_line_desc TEXT,
            total_budget DECIMAL(18,2) DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'USD',
            inst1_pct DECIMAL(5,2) DEFAULT 0,
            inst2_pct DECIMAL(5,2) DEFAULT 0,
            inst3_pct DECIMAL(5,2) DEFAULT 0,
            m1 DECIMAL(18,2) DEFAULT 0,
            m2 DECIMAL(18,2) DEFAULT 0,
            m3 DECIMAL(18,2) DEFAULT 0,
            m4 DECIMAL(18,2) DEFAULT 0,
            m5 DECIMAL(18,2) DEFAULT 0,
            m6 DECIMAL(18,2) DEFAULT 0,
            m7 DECIMAL(18,2) DEFAULT 0,
            m8 DECIMAL(18,2) DEFAULT 0,
            m9 DECIMAL(18,2) DEFAULT 0,
            m10 DECIMAL(18,2) DEFAULT 0,
            m11 DECIMAL(18,2) DEFAULT 0,
            m12 DECIMAL(18,2) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // APP PERMISSIONS
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                app_key VARCHAR(50) NOT NULL,
                can_view TINYINT(1) NOT NULL DEFAULT 1,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                is_hidden TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_user_app (user_id, app_key),
                CONSTRAINT fk_app_permissions_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // USER PROJECTS
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_projects (
                user_id   INT NOT NULL,
                project_id INT NOT NULL,
                PRIMARY KEY (user_id, project_id),
                INDEX idx_user_projects_user (user_id),
                INDEX idx_user_projects_project (project_id),
                CONSTRAINT fk_user_projects_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // DONORS (minimal) - Legacy table, kept for backward compatibility
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS donors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                short_name VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Initialize currencies and donors tables (from helpers.php)
        if (function_exists('init_currencies_donors_tables')) {
            try {
                init_currencies_donors_tables($pdo);
                // Seed currencies if empty
                $currCount = $pdo->query("SELECT COUNT(*) as cnt FROM system_currencies")->fetch()['cnt'] ?? 0;
                if ($currCount == 0 && function_exists('seed_comprehensive_currencies')) {
                    seed_comprehensive_currencies($pdo);
                }
                // Seed donors if empty
                $donorCount = $pdo->query("SELECT COUNT(*) as cnt FROM gms_donors")->fetch()['cnt'] ?? 0;
                if ($donorCount == 0 && function_exists('seed_comprehensive_donors')) {
                    seed_comprehensive_donors($pdo);
                }
            } catch (Exception $e) {
                error_log("[" . DB_APP_TAG . "] Error initializing currencies/donors: " . $e->getMessage());
            }
        }

        // ---- SEEDING ----

        // Default admin user
        $stmt  = $pdo->query("SELECT COUNT(*) AS c FROM users");
        $count = (int)$stmt->fetch()['c'];
        if ($count === 0) {
            $password = password_hash('Admin@123', PASSWORD_DEFAULT);
            $stmtIns  = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?,?)");
            $stmtIns->execute(['System Admin', 'admin@example.com', $password, 'admin']);
            error_log("[" . DB_APP_TAG . "] Seeded default admin user");
        }

        // Geographical data (regions + zones + woredas) from $NEXUS_LOCATIONS
        // Sentinel check: if a woreda like 'Sasiga' (Oromia, East Welega) is missing, we seed.
        $stmt          = $pdo->prepare("SELECT COUNT(*) AS c FROM woredas WHERE name = ?");
        $stmt->execute(['Sasiga']);
        $hasFullGeo    = (int)$stmt->fetch()['c'] > 0;
        if (!$hasFullGeo) {
            $result = db_import_comprehensive_geographical_data($pdo);
            if ($result['success']) {
                error_log("[" . DB_APP_TAG . "] Seeded geographical data: " . $result['message']);
            } else {
                error_log("[" . DB_APP_TAG . "] Failed to seed geographical data: " . $result['error']);
            }
        }

        // Default project
        $stmt          = $pdo->query("SELECT COUNT(*) AS c FROM projects");
        $countProjects = (int)$stmt->fetch()['c'];
        if ($countProjects === 0) {
            $pdo->prepare("INSERT INTO projects (title, donor, ip_name) VALUES (?,?,?)")
                ->execute(['Gambella Cholera Health Project', 'EHF / UNOCHA', 'Nexus Ethiopia']);
            error_log("[" . DB_APP_TAG . "] Seeded default project");
        }

        // Indicators
        $stmt            = $pdo->query("SELECT COUNT(*) AS c FROM indicators");
        $countIndicators = (int)$stmt->fetch()['c'];
        if ($countIndicators === 0) {
            $indicators = [
                ['IND1',  'Number of people with access to timely and quality cholera prevention and treatment services in targeted areas', 'persons',   'sadd'],
                ['IND2A', '% of cholera patients admitted to CTCs/ORPs who recover without complications - Numerator',                    'persons',   'count'],
                ['IND2B', '% of cholera patients admitted to CTCs/ORPs who recover without complications - Denominator',                  'persons',   'count'],
                ['IND3',  'Number of outbreak-prone disease alerts verified and responded to within 48 hours',                            'alerts',    'count'],
                ['IND4A', '% of weekly surveillance reports submitted on time by health facilities - Facilities reported',                'facilities','count'],
                ['IND4B', '% of weekly surveillance reports submitted on time by health facilities - Functional facilities',              'facilities','count'],
                ['IND5',  'Number of health workers trained with the capacity to manage an outbreak',                                     'persons',   'count'],
                ['IND6',  '(Global) AP.2b - AAP - % of affected people aware of feedback and complaints mechanisms',                      'percent',   'percent_direct'],
                ['IND7',  'Number of CTCs/ORPs supported with cholera kits and IPC supplies',                                             'facilities','count'],
                ['IND8A', '% of health facilities implementing IPC protocols - Implementing',                                            'facilities','count'],
                ['IND8B', '% of health facilities implementing IPC protocols - Functional',                                              'facilities','count'],
                ['IND9',  '(Global) AP.4b - AAP - % who state assistance/services met their needs',                                       'percent',   'percent_direct'],
                ['IND10', 'Number of people in cholera-affected areas reached with hygiene promotion, OCV messaging, and RCCE',          'persons',   'sadd'],
                ['IND11', 'Number of community volunteers trained and actively promoting hygiene practices',                             'persons',   'count'],
                ['IND12', '(Global) AP.1b - AAP - % of affected people aware of their rights',                                           'percent',   'percent_direct'],
                ['IND13', 'Number of people reached with OCV campaign mobilization and messaging',                                       'persons',   'sadd'],
                ['IND14', 'Number of households visited with hygiene promotion messages',                                                'households','count'],
                ['IND15A','Number of IEC materials developed/distributed (posters, leaflets, radio spots)',                             'materials', 'count'],
                ['IND15B','Number of people reached with IEC materials',                                                                 'persons',   'count'],
                ['IND16', '(Global) AP.5b - AAP - % able to access services safely and accountably',                                     'percent',   'percent_direct'],
            ];
            $stmtIns = $pdo->prepare("INSERT INTO indicators (code, name, unit_type, input_mode) VALUES (?,?,?,?)");
            foreach ($indicators as $ind) {
                $stmtIns->execute($ind);
            }
            error_log("[" . DB_APP_TAG . "] Seeded " . count($indicators) . " indicators");
        }

        error_log("[" . DB_APP_TAG . "] Database schema setup completed");
    }

    // -----------------------------------------------------------------
    // 9. CORE CONNECTION – getPDO() + STANDARD WRAPPERS
    // -----------------------------------------------------------------

    /**
     * Core PDO connection builder used by all helper functions.
     * Creates DB if needed, sets schema, updates schema, checks integrity.
     */
    function getPDO(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Create DB if not exists, then use it
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . DB_NAME . '`');

            // Build schema + seeds (do not fail hard if a single CREATE/ALTER fails)
            try { db_ensure_schema($pdo); } catch (Throwable $e) { error_log('[' . DB_APP_TAG . '] Schema ensure failed: ' . $e->getMessage()); }

            // Update schema for cross-app compatibility
            try { db_update_schema_if_needed($pdo); } catch (Throwable $e) { error_log('[' . DB_APP_TAG . '] Schema update failed: ' . $e->getMessage()); }

            // Check data integrity
            try { check_data_integrity($pdo); } catch (Throwable $e) { error_log('[' . DB_APP_TAG . '] Data integrity check failed: ' . $e->getMessage()); }

            return $pdo;
        } catch (PDOException $e) {
            error_log('[' . DB_APP_TAG . '] Database connection failed: ' . $e->getMessage());

            // Fallback to in-memory SQLite (very limited, mostly to avoid full crash)
            try {
                $pdo = new PDO('sqlite::memory:');
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                return $pdo;
            } catch (PDOException $e2) {
                die('Database connection failed and fallback also failed.');
            }
        }
    }

    /**
     * NEW STANDARD ACCESSORS for all apps
     * -----------------------------------
     * Use any of these in your apps:
     *   $pdo = get_db();
     *   $pdo = db();
     *   $pdo = db_connect();
     * They all return the same shared PDO instance (same as getPDO()).
     */

    function get_db(): PDO {
        return getPDO();
    }

    function db(): PDO {
        return getPDO();
    }

    function db_connect(): PDO {
        return getPDO();
    }

    // -----------------------------------------------------------------
    // 10. GENERIC QUERY HELPERS – for all apps (imports, reports, etc.)
    // -----------------------------------------------------------------

    function db_query(string $sql, array $params = []): PDOStatement {
        $pdo  = get_db();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    function db_fetch_all(string $sql, array $params = []): array {
        return db_query($sql, $params)->fetchAll();
    }

    function db_fetch_one(string $sql, array $params = []) {
        $stmt = db_query($sql, $params);
        $row  = $stmt->fetch();
        return $row === false ? null : $row;
    }

    function db_fetch_value(string $sql, array $params = []) {
        $stmt = db_query($sql, $params);
        $val  = $stmt->fetchColumn();
        return $val === false ? null : $val;
    }

    function db_execute(string $sql, array $params = []): int {
        $stmt = db_query($sql, $params);
        return $stmt->rowCount();
    }

    function db_insert(string $table, array $data) {
        if (empty($data)) {
            return null;
        }

        $pdo       = get_db();
        $cols      = array_keys($data);
        $colNames  = '`' . implode('`, `', $cols) . '`';
        $placeHldr = ':' . implode(', :', $cols);

        $sql = "INSERT INTO `{$table}` ({$colNames}) VALUES ({$placeHldr})";

        $stmt = $pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        return $pdo->lastInsertId();
    }

    function db_update(string $table, array $data, string $where, array $params = []): int {
        if (empty($data)) {
            return 0;
        }

        $pdo      = get_db();
        $setParts = [];
        foreach ($data as $col => $val) {
            $setParts[] = "`{$col}` = :upd_" . $col;
        }

        $setSql = implode(', ', $setParts);
        $sql    = "UPDATE `{$table}` SET {$setSql} WHERE {$where}";

        $stmt = $pdo->prepare($sql);

        foreach ($data as $col => $val) {
            $stmt->bindValue(':upd_' . $col, $val);
        }
        foreach ($params as $k => $v) {
            if (strpos($k, ':') === 0) {
                $stmt->bindValue($k, $v);
            } else {
                $stmt->bindValue(':' . $k, $v);
            }
        }

        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Bulk insert (used by imports after Excel/CSV is parsed).
     * Each row is an associative array column => value.
     */
    function db_bulk_insert(string $table, array $rows): int {
        if (empty($rows)) {
            return 0;
        }

        return db_transaction(function () use ($table, $rows) {
            $count = 0;
            foreach ($rows as $row) {
                db_insert($table, $row);
                $count++;
            }
            return $count;
        });
    }

    /**
     * Transactions helper for complex operations (e.g. multi-step imports).
     */
    function db_transaction(callable $callback) {
        $pdo = get_db();

        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[' . DB_APP_TAG . '] Transaction failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Quick DB health check.
     */
    function db_ping(): bool {
        try {
            db_fetch_value('SELECT 1');
            return true;
        } catch (Throwable $e) {
            error_log('[' . DB_APP_TAG . '] DB ping failed: ' . $e->getMessage());
            return false;
        }
    }

    // -----------------------------------------------------------------
    // 11. INITIALIZE SHARED $pdo FOR LEGACY CODE
    // -----------------------------------------------------------------

    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        $GLOBALS['pdo'] = getPDO();
    }

} // end if !function_exists('health_system_db_functions_loaded')


// =====================================================================
// 12. EXTRA GENERIC HELPERS FOR ALL MODULES (ADDED, NOT REPLACING ANYTHING)
// =====================================================================

/**
 * USER HELPERS
 */
if (!function_exists('db_get_user_by_id')) {
    function db_get_user_by_id(int $user_id, ?PDO $pdo = null): ?array {
        if ($user_id <= 0) {
            return null;
        }
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log("Error fetching user by id: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('db_get_user_by_email')) {
    function db_get_user_by_email(string $email, ?PDO $pdo = null): ?array {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log("Error fetching user by email: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('db_get_active_users')) {
    function db_get_active_users(?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT * FROM users WHERE is_active = 1 ORDER BY full_name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching active users: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * PROJECT HELPERS
 */
if (!function_exists('db_get_project_by_id')) {
    function db_get_project_by_id(int $project_id, ?PDO $pdo = null): ?array {
        if ($project_id <= 0) {
            return null;
        }
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
            $stmt->execute([$project_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log("Error fetching project by id: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('db_get_projects_with_primary_locations')) {
    function db_get_projects_with_primary_locations(?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            $sql = "
                SELECT 
                    p.*,
                    pl.region_id,
                    pl.zone_id,
                    pl.woreda_id,
                    r.name AS region_name,
                    z.name AS zone_name,
                    w.name AS woreda_name
                FROM projects p
                LEFT JOIN project_locations pl 
                    ON pl.project_id = p.id AND pl.is_primary = 1
                LEFT JOIN regions r ON pl.region_id = r.id
                LEFT JOIN zones   z ON pl.zone_id   = z.id
                LEFT JOIN woredas w ON pl.woreda_id = w.id
                ORDER BY p.title
            ";
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching projects with primary locations: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('db_get_user_projects')) {
    function db_get_user_projects(int $user_id, ?PDO $pdo = null): array {
        if ($user_id <= 0) return [];
        if (!$pdo) $pdo = getPDO();

        try {
            // If user has access_all_projects = 1, return all projects
            $stmt = $pdo->prepare("SELECT access_all_projects FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $all = (int)$stmt->fetchColumn();

            if ($all === 1) {
                $stmt = $pdo->query("SELECT * FROM projects ORDER BY title");
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $sql = "
                SELECT p.*
                FROM user_projects up
                JOIN projects p ON p.id = up.project_id
                WHERE up.user_id = ?
                ORDER BY p.title
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching user projects: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('db_user_can_access_project')) {
    function db_user_can_access_project(int $user_id, int $project_id, ?PDO $pdo = null): bool {
        if ($user_id <= 0 || $project_id <= 0) return false;
        if (!$pdo) $pdo = getPDO();

        try {
            $stmt = $pdo->prepare("SELECT access_all_projects FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $all = (int)$stmt->fetchColumn();
            if ($all === 1) {
                return true;
            }

            $stmt = $pdo->prepare("SELECT 1 FROM user_projects WHERE user_id = ? AND project_id = ? LIMIT 1");
            $stmt->execute([$user_id, $project_id]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error checking project access: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * APP PERMISSIONS
 */
if (!function_exists('db_get_app_permissions_for_user')) {
    function db_get_app_permissions_for_user(int $user_id, ?PDO $pdo = null): array {
        if ($user_id <= 0) return [];
        if (!$pdo) $pdo = getPDO();

        try {
            $stmt = $pdo->prepare("SELECT * FROM app_permissions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $result[$row['app_key']] = $row;
            }
            return $result;
        } catch (PDOException $e) {
            error_log("Error fetching app permissions for user: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('db_user_can_view_app')) {
    function db_user_can_view_app(int $user_id, string $app_key, ?PDO $pdo = null): bool {
        if (!$pdo) $pdo = getPDO();
        $perms = db_get_app_permissions_for_user($user_id, $pdo);
        if (!isset($perms[$app_key])) {
            // default: visible and viewable if not explicitly restricted
            return true;
        }
        return (int)$perms[$app_key]['can_view'] === 1 && (int)$perms[$app_key]['is_hidden'] === 0;
    }
}

if (!function_exists('db_user_can_edit_app')) {
    function db_user_can_edit_app(int $user_id, string $app_key, ?PDO $pdo = null): bool {
        if (!$pdo) $pdo = getPDO();
        $perms = db_get_app_permissions_for_user($user_id, $pdo);
        if (!isset($perms[$app_key])) {
            return false;
        }
        return (int)$perms[$app_key]['can_edit'] === 1;
    }
}

/**
 * INDICATOR HELPERS
 */
if (!function_exists('db_get_all_indicators')) {
    function db_get_all_indicators(bool $only_active = true, ?PDO $pdo = null): array {
        if (!$pdo) $pdo = getPDO();
        try {
            if ($only_active) {
                $stmt = $pdo->query("SELECT * FROM indicators WHERE is_active = 1 ORDER BY code");
            } else {
                $stmt = $pdo->query("SELECT * FROM indicators ORDER BY code");
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching indicators: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('db_get_indicator_by_id')) {
    function db_get_indicator_by_id(int $indicator_id, ?PDO $pdo = null): ?array {
        if ($indicator_id <= 0) return null;
        if (!$pdo) $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("SELECT * FROM indicators WHERE id = ? LIMIT 1");
            $stmt->execute([$indicator_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log("Error fetching indicator by id: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * REPORT HELPERS
 */
if (!function_exists('db_get_reports_for_project')) {
    function db_get_reports_for_project(int $project_id, array $filters = [], ?PDO $pdo = null): array {
        if ($project_id <= 0) return [];
        if (!$pdo) $pdo = getPDO();

        $sql    = "SELECT * FROM reports WHERE project_id = :pid";
        $params = [':pid' => $project_id];

        if (!empty($filters['year'])) {
            $sql .= " AND year = :year";
            $params[':year'] = (int)$filters['year'];
        }
        if (!empty($filters['period_type'])) {
            $sql .= " AND period_type = :ptype";
            $params[':ptype'] = $filters['period_type'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }

        $sql .= " ORDER BY year DESC, month DESC, week DESC, id DESC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching reports for project: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('db_get_report_values')) {
    function db_get_report_values(int $report_id, ?PDO $pdo = null): array {
        if ($report_id <= 0) return [];
        if (!$pdo) $pdo = getPDO();

        try {
            $sql = "
                SELECT rv.*, i.code AS indicator_code, i.name AS indicator_name, i.unit_type, i.input_mode
                FROM report_values rv
                JOIN indicators i ON rv.indicator_id = i.id
                WHERE rv.report_id = ?
                ORDER BY i.code
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$report_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching report values: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * RESULTS CHAIN / ACTIVITIES
 */
if (!function_exists('db_get_results_chain_for_project')) {
    function db_get_results_chain_for_project(int $project_id, ?PDO $pdo = null): array {
        if ($project_id <= 0) return [];
        if (!$pdo) $pdo = getPDO();

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM results_chain
                WHERE project_id = ?
                ORDER BY 
                    FIELD(level,'objective','outcome','output'),
                    code
            ");
            $stmt->execute([$project_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching results chain: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('db_get_activities_for_project')) {
    function db_get_activities_for_project(int $project_id, ?PDO $pdo = null): array {
        if ($project_id <= 0) return [];
        if (!$pdo) $pdo = getPDO();

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM activities
                WHERE project_id = ?
                ORDER BY code
            ");
            $stmt->execute([$project_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching activities for project: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * SIMPLE ALIASES FOR OLDER CODE (avoid 'undefined function' in old scripts)
 */
if (!function_exists('get_projects')) {
    function get_projects(): array {
        return db_get_projects();
    }
}

if (!function_exists('get_regions')) {
    function get_regions(): array {
        return db_get_regions();
    }
}

if (!function_exists('get_zones_by_region')) {
    function get_zones_by_region(int $region_id): array {
        return db_get_zones_by_region($region_id);
    }
}

if (!function_exists('get_woredas_by_zone')) {
    function get_woredas_by_zone(int $zone_id): array {
        return db_get_woredas_by_zone($zone_id);
    }
}

if (!function_exists('get_locations_json')) {
    function get_locations_json(): string {
        $loc = db_get_locations_for_js();
        return json_encode($loc, JSON_UNESCAPED_UNICODE);
    }
}
