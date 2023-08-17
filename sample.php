<?php

require_once "general_properties_csv.php";
require_once "area_properties_csv.php";

$latitude = "";
$longitude = "";
$city = "";
$state = "";

// Parse command-line arguments
$options = getopt("", ["latitude:", "longitude:", "city:", "state:"]);

if (isset($options["latitude"])) {
    $latitude = $options["latitude"];
}

if (isset($options["longitude"])) {
    $longitude = $options["longitude"];
}

if (isset($options["city"])) {
    $city = $options["city"];
}

if (isset($options["state"])) {
    $state = $options["state"];
}

if (empty($latitude) || empty($longitude) || empty($city) || empty($state)) {
    die(
        "Please provide --latitude, --longitude, --city, and --state options.\n"
    );
}

$regionId = "";
$regionType = "";

function getZillowCandidates($City, $State, &$regionId, &$regionType)
{
    $City = str_replace(" ", "%20", $City);
    $URL = sprintf("https://www.zillow.com/%s-%s", $City, $State);
    $Command = sprintf(
        "curl_chrome100 -L -s -x \"http://172.30.1.121:80\" '%s'",
        $URL
    );

    $HTML = shell_exec($Command);

    $DOM = new DOMDocument();
    libxml_use_internal_errors(true);
    $DOM->loadHTML($HTML);
    libxml_clear_errors();

    $XP = new DOMXPath($DOM);
    $ScriptTags = $XP->query("//script[@id=\"__NEXT_DATA__\"]");

    if ($ScriptTags->length == 0) {
        die("No script tag found with id=__NEXT_DATA__");
    }
    $ScriptTag = $ScriptTags[0];
    $Raw = $ScriptTag->nodeValue;

    // It seems like the actual JSON content is embedded in a <!-- --> comment, let's remove those
    $Raw = str_replace(["<!--", "-->"], "", $Raw);
    $Parsed = json_decode($Raw, true);

    if (
        !isset(
            $Parsed["props"]["pageProps"]["searchPageState"]["queryState"][
                "regionSelection"
            ]
        )
    ) {
        die("Could not find the regionSelection in the JSON data.");
    }

    $regionSelection =
        $Parsed["props"]["pageProps"]["searchPageState"]["queryState"][
            "regionSelection"
        ];

    foreach ($regionSelection as $region) {
        printf("Region ID: %d\n", $region["regionId"]);
        printf("Region Type: %d\n", $region["regionType"]);
        printf("\n");

        $regionId = $region["regionId"];
        $regionType = $region["regionType"];
    }
}

$options = getopt("", ["city:", "state:"]);

if (isset($options["city"]) && isset($options["state"])) {
    $City = $options["city"];
    $State = $options["state"];

    getZillowCandidates($City, $State, $regionId, $regionType);
} else {
    die("Please provide both --city and --state options.\n");
}

$degreeLatitude = 500 / 111000; // Represents the degree equivalent for 500 meters

// Adjusts for the decreasing circumference of longitudinal lines as we move away from the equator
$degreeLongitude = 500 / (111000 * cos(deg2rad($latitude)));

$output = [
    "pagination" => new stdClass(),
    "mapBounds" => [
        "north" => $latitude + $degreeLatitude,
        "east" => $longitude + $degreeLongitude,
        "south" => $latitude - $degreeLatitude,
        "west" => $longitude - $degreeLongitude,
    ],
    "mapZoom" => 13,
    "regionSelection" => [
        [
            "regionId" => $regionId,
            "regionType" => $regionType,
        ],
    ],
    "isMapVisible" => true,
    "filterState" => [
        "isForSaleForeclosure" => [
            "value" => false,
        ],
        "isAllHomes" => [
            "value" => true,
        ],
        "sortSelection" => [
            "value" => "days",
        ],
        "isAuction" => ["value" => false],
        "isNewConstruction" => ["value" => false],
        "isRecentlySold" => ["value" => true],
        "isForSaleByOwner" => ["value" => false],
        "isComingSoon" => ["value" => false],
        "isForSaleByAgent" => ["value" => false],
    ],
    "isListVisible" => true,
];

$north = $latitude + $degreeLatitude;
$east = $longitude + $degreeLongitude;
$south = $latitude - $degreeLatitude;
$west = $longitude - $degreeLongitude;

// Generate JSON for each subregion
$subregions = create_subregions(
    $north,
    $south,
    $east,
    $west,
    $regionId,
    $regionType
);

function create_subregions($north, $south, $east, $west, $regionId, $regionType)
{
    $lat_step = ($north - $south) / 5;
    $long_step = ($east - $west) / 5;

   // $subregions = [];

    for ($i = 0; $i < 5; $i++) {
        for ($j = 0; $j < 5; $j++) {
            $subregion_north = $south + $lat_step * ($i + 1);
            $subregion_south = $south + $lat_step * $i;
            $subregion_east = $west + $long_step * ($j + 1);
            $subregion_west = $west + $long_step * $j;

            $subregion = [
                "pagination" => new stdClass(),
                "mapBounds" => [
                    "north" => $subregion_north,
                    "east" => $subregion_east,
                    "south" => $subregion_south,
                    "west" => $subregion_west,
                ],
                "mapZoom" => 15, // Increased zoom for subregions
                "regionSelection" => [
                    [
                        "regionId" => $regionId,
                        "regionType" => $regionType,
                    ],
                ],
                "isMapVisible" => true,
                "filterState" => [
                    "isForSaleForeclosure" => [
                        "value" => false,
                    ],
                    "isAllHomes" => [
                        "value" => true,
                    ],
                    "sortSelection" => [
                        "value" => "days",
                    ],
                    "isAuction" => ["value" => false],
                    "isNewConstruction" => ["value" => false],
                    "isRecentlySold" => ["value" => true],
                    "isForSaleByOwner" => ["value" => false],
                    "isComingSoon" => ["value" => false],
                    "isForSaleByAgent" => ["value" => false],
                ],
                "isListVisible" => true,
            ];

            $subregions[] = json_encode($subregion);
        }
    }

    return $subregions;
}

$file = fopen("subregions.csv", "a");

// Write each subregion to file
foreach ($subregions as $jsonOutput) {
    fputcsv($file, [$jsonOutput]);
}

// Close the file
fclose($file);

$jsonOutput = json_encode($output);

function fetchData($url, $timeout = 10)
{
    $Command = sprintf(
        "curl_chrome100 -s -x \"http://172.30.1.121:80\" --max-time %d '%s'",
	$timeout,
	$url
    );
    $Output = shell_exec($Command);

    if ($Output === NULL) {
	printf("\n");    
	echo "Failed to fetch data from $url within $timeout seconds. Retrying...\n";
	printf("\n");
	sleep(1); // Optional sleep before retry
        return fetchData($url, $timout); // Recursive retry
    }

    return json_decode($Output, true);
}

$query["searchQueryState"] = $jsonOutput;
$query["wants"] = json_encode(["cat1" => ["mapResults"]]);
$query["requestId"] = 2;

$URL =
    "https://www.zillow.com/search/GetSearchPageState.htm?" .
    http_build_query($query);

$firstPageData = fetchData($URL);

$resultsPerPage = $firstPageData["cat1"]["searchList"]["resultsPerPage"];
$totalPages = $firstPageData["cat1"]["searchList"]["totalPages"];
$totalResultCount = $firstPageData["cat1"]["searchList"]["totalResultCount"];


for ($page = 1; $page <= $totalPages; $page++) {
    $query["requestId"] = 2;
    $url = $URL;
    printf("Requesting URL: %s\n", $url);

    $pageData = fetchData($url); //here pasted

    foreach ($pageData["cat1"]["searchResults"]["mapResults"] as $MapResult) {
        if (!isset($MapResult["zpid"])) {
            continue;
        }
        $dataArray = [
            "ZPID" => "",
            "Beds" => "",
            "Baths" => "",
            "Price" => "",
            "City" => "",
            "State" => "",
            "Zip" => "",
            "Address" => "",
            "Latitude" => "",
            "Longitude" => "",
            "SquareFootage" => "",
        ];

        printf("ZPID: %s\n", $MapResult["zpid"]);
        printf("Beds: %d\n", $MapResult["beds"]);
        printf("Baths: %.2f\n", $MapResult["baths"]);
        printf("Price: %s\n", $MapResult["price"]);

        //Add values to dataArray
        $dataArray["ZPID"] = $MapResult["zpid"];
        $dataArray["Beds"] = $MapResult["beds"];
        $dataArray["Baths"] = $MapResult["baths"];
        $dataArray["Price"] = $MapResult["price"];

        //Print adress
        if (isset($MapResult["address"])) {
            printf("Address: %s\n", $MapResult["address"]);
            // Extract and print city, state, and zip if available
            $dataArray["Address"] = $MapResult["address"];
            $city = "";
            $state = "";
            $zip = "";
            if (isset($MapResult["hdpData"]["homeInfo"]["city"])) {
                $city = $MapResult["hdpData"]["homeInfo"]["city"];
            }
            if (isset($MapResult["hdpData"]["homeInfo"]["state"])) {
                $state = $MapResult["hdpData"]["homeInfo"]["state"];
            }
            if (isset($MapResult["hdpData"]["homeInfo"]["zipcode"])) {
                $zip = $MapResult["hdpData"]["homeInfo"]["zipcode"];
            }
            printf("City: %s\n", $city);
            printf("State: %s\n", $state);
            printf("Zip: %s\n", $zip);

            //Add values to dataArray
            $dataArray["City"] = $city;
            $dataArray["State"] = $state;
            $dataArray["Zip"] = $zip;
        }

        //prints long and lat
        if (isset($MapResult["latLong"])) {
            printf("Latitude: %f\n", $MapResult["latLong"]["latitude"]);
            printf("Longitude: %f\n", $MapResult["latLong"]["longitude"]);

            //Add values to dataArray
            $dataArray["Latitude"] = $MapResult["latLong"]["latitude"];
            $dataArray["Longitude"] = $MapResult["latLong"]["longitude"];
        }

        // Print the square footage if available
        if (isset($MapResult["hdpData"]["homeInfo"]["livingArea"])) {
            printf(
                "Square Footage: %.2f sqft\n",
                $MapResult["hdpData"]["homeInfo"]["livingArea"]
            );

            //Add values to dataArray
            $dataArray["SquareFootage"] =
                $MapResult["hdpData"]["homeInfo"]["livingArea"];
        }

	printf("\n");
//	printf("\n");
    printf("Writing to CSV...");
	printf("\n");
	printf("\n");

        writeToCSV($dataArray);
    }
}

function getPageData($query) {
//require_once "test_csv.php";     
    $URL = "https://www.zillow.com/search/GetSearchPageState.htm?" . http_build_query($query);

    $firstPageData = fetchData($URL);

    
    $resultsPerPage = $firstPageData["cat1"]["searchList"]["resultsPerPage"];
    $totalPages = $firstPageData["cat1"]["searchList"]["totalPages"];
    $totalResultCount = $firstPageData["cat1"]["searchList"]["totalResultCount"];


    // Other code to process the page data
    for ($page = 1; $page <= $totalPages; $page++) {
    $query['requestId'] = 2;
    $url = $URL;
    printf("Requesting URL: %s\n", $url);

    printf("\n");
    printf("Total Pages: %d\n", $totalPages);
    printf("Total Results: %d\n", $totalResultCount);
    printf("\n");
    
    $pageData = fetchData($url); //here pasted

foreach ($pageData['cat1']['searchResults']['mapResults'] as $MapResult) {
	if (!isset($MapResult['zpid'])){
	       	continue;
	}
    $dataArray = [
        'ZPID' => '',
            'Beds' => '',
            'Baths' => '',
            'Price' => '',
            'City' => '',
            'State' => '',
        'Zip' => '',
        'Address' => '',
            'Latitude' => '',
            'Longitude' => '',
        'SquareFootage' => ''
    ];

    printf ("ZPID: %s\n", $MapResult ['zpid']);
    printf ("Beds: %d\n", $MapResult ['beds']);
    printf ("Baths: %.2f\n", $MapResult ['baths']);
    printf ("Price: %s\n", $MapResult ['price']);

        //Add values to dataArray
    $dataArray['ZPID'] = $MapResult ['zpid'];
        $dataArray['Beds'] = $MapResult ['beds'];
    $dataArray['Baths'] = $MapResult ['baths'];
    $dataArray['Price'] = $MapResult ['price'];


    //Print adress
    if (isset($MapResult['address'])) {
        printf("Address: %s\n", $MapResult['address']);
        // Extract and print city, state, and zip if available
        $dataArray['Address'] = $MapResult['address'];
        $city = '';
        $state = '';
        $zip = '';
        if (isset($MapResult['hdpData']['homeInfo']['city'])) {
            $city = $MapResult['hdpData']['homeInfo']['city'];
        }
        if (isset($MapResult['hdpData']['homeInfo']['state'])) {
            $state = $MapResult['hdpData']['homeInfo']['state'];
        }
        if (isset($MapResult['hdpData']['homeInfo']['zipcode'])) {
            $zip = $MapResult['hdpData']['homeInfo']['zipcode'];
        }
        printf("City: %s\n", $city);
        printf("State: %s\n", $state);
        printf("Zip: %s\n", $zip);

                //Add values to dataArray
        $dataArray['City'] = $city;
        $dataArray['State'] = $state;
        $dataArray['Zip'] = $zip;
    }

    //prints long and lat
    if (isset($MapResult['latLong'])) {
        printf("Latitude: %f\n", $MapResult['latLong']['latitude']);
        printf("Longitude: %f\n", $MapResult['latLong']['longitude']);

                //Add values to dataArray
        $dataArray['Latitude'] = $MapResult['latLong']['latitude'];
        $dataArray['Longitude'] = $MapResult['latLong']['longitude'];
    }

    // Print the square footage if available
    if (isset($MapResult['hdpData']['homeInfo']['livingArea'])) {
        printf("Square Footage: %.2f sqft\n", $MapResult['hdpData']['homeInfo']['livingArea']);

        //Add values to dataArray
        $dataArray['SquareFootage'] = $MapResult['hdpData']['homeInfo']['livingArea'];
    }
    printf("\n");
    printf("Writing to CSV...");
    printf("\n");
   
    noteProperties($dataArray);
printf("\n");
}
    }
    // return $pageData;
}
foreach ($subregions as $subregion) {
    $query["searchQueryState"] = $subregion;
    $query["wants"] = json_encode(["cat1" => ["mapResults"]]);
    $query["requestId"] = 2;

    $pageData = getPageData($query);

   // Process the $pageData for the current subregion...
    print($pageData);
}

printf("Your AirBnb is within this region: ");
echo $jsonOutput;
?>

