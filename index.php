<?php
// Mittlere Kosten pro WG-Zimmer in deutschen Städten
// Filter: 2-4 Zimmer WGs, unbefristet, zwischen 100 und 1000 €

/* TODOs:
	- gewerblich rausfiltern? (FFM z.B.)
*/
$pages = 2;		// Anzahl der Ergebnisseiten die gelesen werden sollen, normal 20 je Seite
$DEBUG = 0;
$city = "";
$cityenc = "";
$cid = 0;
$db_name = "./wgg_db.sqlite";
$header = array(
	'Referer: https://www.wg-gesucht.de/',
	'Accept-Language: de-DE,de;q=0.9',
	'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
	'X-Requested-With: XMLHttpRequest'
);
error_reporting(E_ALL);
ini_set('display_errors', 1);

function POST($c) {
	if(isset($_POST[$c]))
		return htmlspecialchars($_POST[$c]);
}

function logg($msg) {
	global $DEBUG;
	if($DEBUG) {
		print "<pre>[*] $msg</pre>\n";
	}
}

$db = new SQLite3($db_name, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$db-> exec("CREATE TABLE IF NOT EXISTS cityIDs(
	id INTEGER PRIMARY KEY AUTOINCREMENT, 
	cityname TEXT NOT NULL DEFAULT '',
	cityid INTEGER NOT NULL DEFAULT '0')"
);

// Create new table
$date = POST("date");
if(isset($date)) {
	$date = "stats" . substr($date, 2, 2) . substr($date, 5, 2);

	$db->exec("CREATE TABLE IF NOT EXISTS $date(
		id INTEGER PRIMARY KEY AUTOINCREMENT, 
		cityname TEXT NOT NULL DEFAULT '',
		cityid INTEGER NOT NULL DEFAULT '0',
		amount INTEGER NOT NULL DEFAULT '0',
		cost REAL NOT NULL DEFAULT '0.0',
		roomsize REAL NOT NULL DEFAULT '0.0',
		bsp16 REAL NOT NULL DEFAULT '0.0')"
	);
	echo "Neue Tabelle erstellt ($date)";
}

// 1. Enter City, get city code
$city = POST("city");
$table = POST("table");
if(isset($city) && isset($table)) {
	// check if already in db
	$res = $db->query("SELECT c.cityid FROM cityIDs AS c WHERE c.cityname = '$city'");
	$row = $res->fetchArray();

	if(!empty($row)) {
		logg("city id found in db");
		$cid = $row["cityid"];
	} else {
		logg("looking up city id online");
		$cityurl = urlencode($city);
		$ch = curl_init("https://www.wg-gesucht.de/ajax/getCities.php?country_parameter=&query=$cityurl");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		$resp = curl_exec($ch);
		curl_close($ch);

		if(!empty($resp)) {
			$cobj = json_decode($resp);
			$cid = (int) $cobj[0]->city_id;
			if(!$cid) {
				logg("error with cityID");
				exit;
			}
			
			// save to db
			$db->exec("INSERT INTO cityIDs (
				cityname, 
				cityid) VALUES (
				'$city',
				'$cid')");
		} else {
			logg("error: no city_id response");
			exit;
		}
	}

	// 2. get Response, parse HTML
	$costPerSqrm = 0.0;
	$roomSize = 0;
	$bsp16 = 0;
	$cost = 0;
	$sqrm = 0;
	$anzahl = 0;
	$cityenc = preg_replace('/ü/','u',$city);
	$cityenc = preg_replace('/ö/','oe',$cityenc);
	$cityenc = preg_replace('/ä/','a',$cityenc);
	$cityenc = preg_replace('/ /','-',$cityenc);

	for($page = 0; $page < $pages; $page++) {
		$ch = curl_init("https://www.wg-gesucht.de/wg-zimmer-in-$cityenc.$cid.0.1.$page.html?csrf_token=&offer_filter=1&city_id=$cid&noDeact=1&categories%5B%5D=0&rent_types%5B%5D=2&wgMnF=1&wgMxT=3&pagination=1&pu=");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		$res = curl_exec($ch);
		curl_close($ch);
		$results = new DOMDocument();

		//var_dump($res);
		//exit;

		if(!empty($res)) {
			$dom = new DOMDocument();
			@ $dom->loadHTML($res);

			$xpath = new DOMXpath($dom);
			$nodes = $xpath->query("//div[contains(@class,'wgg_card offer_list_item')]");

			foreach ($nodes as $node) {
				$results->appendChild($results->importNode($node, true));
			}
		} else {
			logg("error: no results in response");
			exit;
		}

		foreach($results->childNodes as $result) {
			$bs = $result->getElementsByTagName("b");
			$count = 0;
			$localcost = 0;
			$localsqrm = 0;

			foreach($bs as $b) {
				if($count == 0) {
					//logg(strlen($b->nodeValue));
					if(strlen($b->nodeValue) > 8) {
						logg($anzahl . ": cost var too large! skipping...");
					} else {
						//logg($b->nodeValue);
						$localcost = (int) substr($b->nodeValue, 0, -4);
					}
				} elseif ($count == 1) {
					//logg(strlen($b->nodeValue));
					if(strlen($b->nodeValue) > 6) {
						logg($anzahl . ": sqrm var too large! skipping...");
					} else {
						//logg($b->nodeValue);
						$localsqrm = (int) substr($b->nodeValue, 0, -4);
					}
				}
				++$count;
			}
			if(!empty($localcost) && !empty($localsqrm)) {
				$cost += $localcost;
				$sqrm += $localsqrm;
				++$anzahl;
			}
		}
	}
	if(empty($anzahl)) {
		logg("error: no results");
		exit();
	}
	logg("Gesamt: " . $anzahl);
	$costPerSqrm = round($cost / $sqrm, 2);
	$roomSize = round($sqrm / $anzahl, 2);
	$bsp16 = round($costPerSqrm * 16);


	// 4. save data to db
	$db->exec("CREATE TABLE IF NOT EXISTS $table(
		id INTEGER PRIMARY KEY AUTOINCREMENT, 
		cityname TEXT NOT NULL DEFAULT '',
		cityid INTEGER NOT NULL DEFAULT '0',
		amount INTEGER NOT NULL DEFAULT '0',
		cost REAL NOT NULL DEFAULT '0.0',
		roomsize REAL NOT NULL DEFAULT '0.0',
		bsp16 REAL NOT NULL DEFAULT '0.0')"
	);

	$res = $db->query("SELECT * FROM $table WHERE cityid=$cid");
	$rows = $res->fetchArray();

	if(empty($rows)) {
		logg("adding data to db");

		$db->exec("INSERT INTO $table (
			cityname, 
			cityid,
			amount,
			cost,
			roomsize,
			bsp16) VALUES (
			'$city',
			'$cid',
			'$anzahl',
			'$costPerSqrm',
			'$roomSize',
			'$bsp16')");
	} else {
		logg("data already in db");
	}

}

// 5. show results
?><!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>WG-Gesucht Auswertung</title>
	<script src="sorttable.js"></script>
	<style>
tr:nth-child(2n) {
  background-color: rgba(255, 174, 98, 0.4);
}

th:nth-child(3n),td:nth-child(3n) {
  background-color: rgba(255, 174, 98, 0.4);
}
td { text-align: center; }
td:nth-child(1) { text-align: left; }
</style>
</head>
<body>
<div id="wrap">
<h2>Auswertung</h2>

<form action="./" method="post">
	Neue Tabelle <input type="month" name="date" value="<?= date('Y-m') ?>" style="width: 70px;" />
	<input type="submit" value="Erstellen" />
</form>

<?php if(isset($city)): ?>
<h2>Kosten WG-Zimmer in <?php echo $city; ?></h2>

<table>
<tr>
	<td>Betrachtete Angebote: </td>
	<td><?php echo $anzahl; ?></td>
</tr>
<tr>
	<td>Monatliche Kosten pro m²: </td>
	<td><?php echo $costPerSqrm . " €/m²"; ?></td>
</tr>
<tr>
	<td>Druchschnittliche Zimmergröße (arithm. Mittel): </td>
	<td><?php echo $roomSize . " m²"; ?></td>
</tr>
<tr>
	<td>Beispielzimmer mit 16 m²: </td>
	<td><?php echo $bsp16 . " €"; ?></td>
</tr>
</table>
<?php endif; ?>

</div>

<?php

function table2date($t) {
	# stats2211 -> 2022-11
	return "20" . substr($t, 5, 2) . "-" . substr($t, 7, 2);
}

# get all statsXXXX table names
$tables = array();
$res = $db->query("SELECT name FROM sqlite_master WHERE type='table';");

while($table = $res->fetchArray(SQLITE3_ASSOC)) {
	if(substr($table["name"], 0, 5) === "stats")
		$tables[] = $table['name'];
}

# get all stats data
$stats = array();

foreach($tables as $t) {
	$rows = array();
	$res = $db->query("SELECT * FROM $t ORDER by cityid");
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
		$rows[] = $row;
	}
	$stats[$t] = $rows;
}

$cityset = false;
$fmt = numfmt_create( 'de_DE', NumberFormatter::CURRENCY );
$fmt_nofrac = numfmt_create( 'de_DE', NumberFormatter::CURRENCY );
$fmt_nofrac->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
?>
<div id="stats">
<h2>Ergebnisse</h2>
<table class="sortable">
<thead>
<tr>
	<th>Stadt</th>
<?php foreach($tables as $t): ?>
	<th>Größe<sup>1</sup></th>
	<th>€/m²</th>
	<th>16 m²</th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php for($x = 0; $x < sizeof($stats[$tables[0]]); ++$x): ?>
<tr>
	<td style="white-space: nowrap;min-width: 170px;"><?=$stats[$tables[0]][$x]["cityname"] ?></td>
<?php foreach($tables as $t): ?>
	<td><?php
	if(isset($stats[$t][$x]["cost"])) {
		echo $stats[$t][$x]["roomsize"] . " m²";
	} ?>
	</td>
	<td><?php
	if(isset($stats[$t][$x]["cost"])) {
		echo numfmt_format_currency($fmt, $stats[$t][$x]["cost"], "EUR");
	} else {
		echo "<form method='post'><input type='hidden' name='table' value='$t'><input type='hidden' name='city' value='" . $stats[$tables[0]][$x]["cityname"] . "'><input type='submit' value='Abrufen'></form>";
	} ?>
	</td>
	<td><?php
	if(isset($stats[$t][$x]["cost"])) {
		echo numfmt_format_currency($fmt_nofrac, $stats[$t][$x]["bsp16"], "EUR");
	} ?>
	</td>
<?php endforeach; ?>
</tr>
<?php $cityset = true; endfor; ?>
<tfoot>
<tr>
	<th></th>
<?php foreach($tables as $t):
	$td = table2date($t) ?>
	<th colspan="3" style="min-width: 320px;"><?=$td?></th>
<?php endforeach; ?>
</tr>
</tfoot>
</table>

<p><small>1) Mittlere Zimmergröße</small></p>
</div>

</body>
</html>

