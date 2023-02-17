<?php

namespace Facebook\WebDriver;

require_once('vendor/autoload.php');


use Carbon\Carbon;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Facebook\WebDriver\Firefox\FirefoxOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;

const PATH_TO_DOWNLOAD_FILES = '/Users/dominique/Downloads/sm/';
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
const OLDEST_YEAR = 2009;
const BASE_URI = 'https://www.smashingmagazine.com';
const IMAGE_LINK_PATTERN =
'`https:\/\/(www\.)?smashingmagazine\.com\/files\/wallpapers\/([a-z\-_\d\/])+-nocal-(1920x1080|2560x1440|3840x2160)\.(jpeg|jpg|png)`i';
const SEARCH_STRING = 'https://www.smashingmagazine.com/search/?q=wallpaper%20';
const ARCHIVE_FILE_PATH = 'links.txt';
$client = new Client(['base_uri' => BASE_URI]);
$archive_links = [];
if (file_exists(ARCHIVE_FILE_PATH)) {
    $archive_links = file(ARCHIVE_FILE_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

/**
 * This script scraps the wallpapers from Smashing Magazine 😍
 * At first, I've tried to get everything from regular requests
 * performed with Guzzle, but finally, I’ve noticed that the
 * url of all month archive pages weren't built according to the
 * same logic. It's basically ok, but there are some exceptions
 * and dealing with these inconsistencies is rather boring.
 *
 * I’ve switched to another strategy. My first idea was to perform
 * search requests like "Wallpaper 2020" on the website with Guzzle.
 * Unfortunately, the search is powered by Algolia and the page is
 * built client side with JS.
 *
 * So the third approach still uses the search engine, but like a
 * human would. I’ve decided to use GeckoDriver, a project built
 * on Selenium that makes it possible to interact programmatically
 * with Firefox like a human would.
 *
 * I don’t make everything with GeckoDriver though. I only perform
 * the search to build an array of the links of each month archive.
 * After that, I make regular HTTP requests with Guzzle and some
 * regex (should I use DomDocument instead ? I should try, because
 * It should make the scraper less dependant to url formats…)
 *
 * The script has some dependencies. It’s not mandatory to use these,
 * like Carbon (DateTime exists) or GuzzleHTTP (Curl can be used
 * through PHP). Let’s say I’m a bit lazy…
 */

/*
 * Don't forget to install and start GeckoDriver or ChromeDriver !
 * Since I use Firefox, it’s GeckoDriver for me.
*/
$host = $_ENV['DRIVER_HOST'];
$firefoxOptions = new FirefoxOptions();
// Let’s go headless to improve the performances a bit…
$firefoxOptions->addArguments(['-headless']);
$capabilities = DesiredCapabilities::firefox();
$capabilities->setCapability(FirefoxOptions::CAPABILITY, $firefoxOptions);
$driver = RemoteWebDriver::create($host, $capabilities);


/*
* Everything is ready, let's begin. We’ll go backwards through time,
* starting at the current year
* */
$year = Carbon::now()->year;
/* An array to keep all the archive links we’ve fetched with the web driver */
$months_links = [];

/** Let’s iterate through time **/
for ($y = $year; $y > OLDEST_YEAR; $y--) {
    echo "Requesting year {$y}";
    $driver->get(SEARCH_STRING . $y);
    $driver->wait(4000)->until(
        WebDriverExpectedCondition::visibilityOfAnyElementLocated(
            WebDriverBy::cssSelector('h2.article--post__title')
        )
    );
    $current_year_archive_links = $driver->findElements(
        WebDriverBy::cssSelector('.article--post__title a')
    );
    foreach ($current_year_archive_links as $month_link) {
        $href = $month_link->getAttribute('href');
        if (!in_array($href, $months_links) && !in_array($href, $archive_links)) {
            echo ".";
            $months_links[] = $href;
        }
    }
    echo "\n";
}
$links = array_map(fn ($link) => $link . PHP_EOL, array_merge($months_links, $archive_links));
// terminate the session
$driver->quit();
$count_months = count($links);
echo "Finished requesting the website. Found {$count_months} month links" . PHP_EOL;

// Let’s persist those links, it might prove useful in some time…

file_put_contents(ARCHIVE_FILE_PATH, sort($links, SORT_NATURAL));
die();
// Prepare a folder before downloading the images
if (!file_exists(PATH_TO_DOWNLOAD_FILES) && !mkdir(PATH_TO_DOWNLOAD_FILES) && !is_dir(PATH_TO_DOWNLOAD_FILES)) {
    throw new \RuntimeException(sprintf('Directory "%s" was not created', PATH_TO_DOWNLOAD_FILES));
}

foreach ($months_links as $month_link) {
    //var_dump($month_link);
    scrap($month_link);
}

function scrap(string $url): void
{
    $image_links_from_month_archive = grabImageLinksFromMonthArchive($url);
    if ($image_links_from_month_archive) {
        foreach ($image_links_from_month_archive[0] as $image_link) {
            grabImageFromLink(removeBaseUriFromImageLink($image_link));
        }
    }
}

function grabImageLinksFromMonthArchive(string $url): array
{
    $links = [];
    global $client;

    try {
        $response = $client->get($url);
    } catch (ClientException $e) {
        $response = null;
    }

    if ($response) {
        $body = $response->getBody();
        preg_match_all(IMAGE_LINK_PATTERN, (string) $body, $links);
    }
    return $links;
}

function grabImageFromLink(string $image_link): void
{
    global $client;
    $filenameParts = explode('/', $image_link);
    $filename = $filenameParts[count($filenameParts) - 1];

    try {
        $client->get($image_link, ['sink' => PATH_TO_DOWNLOAD_FILES . $filename]);
    } catch (ClientException $guzzle_exception) {
        var_dump($guzzle_exception->getMessage());
        die();
    }
}

function removeBaseUriFromImageLink(string $image_link): string
{
    if (str_contains(BASE_URI, $image_link)) {
        str_replace(BASE_URI, '', $image_link);
    }
    return $image_link;
}
