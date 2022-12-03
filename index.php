<?php

namespace Facebook\WebDriver;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Facebook\WebDriver\Firefox\FirefoxOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;

require_once('vendor/autoload.php');

const BASE_URI = 'https://www.smashingmagazine.com';
const IMAGE_LINK_PATTERN =
'`https:\/\/(www\.)?smashingmagazine\.com\/files\/wallpapers\/([a-z\-_\d\/])+-nocal-(1920x1080|2560x1440|3840x2160)\.(jpeg|jpg|png)`i';

$client = new Client(['base_uri' => BASE_URI]);


/**
 * This script scraps the wallpapers from Smashing Magazine 😍
 * At first, i've tried to get everything from regular requests
 * performed with Guzzle, but finally, i’ve noticed that the
 * url of all month pages weren't built according to the same
 * logic. It's basically ok, but there are some exceptions and
 * dealing with these is rather boring.
 * I’ve switched to another strategy. My first idea was to perform
 * search requests like Wallpaper 2020 on the website with Guzzle.
 * Unfortunately, the search is powered by algolia and the page is
 * built client side with JS.
 * So the third approach leverages the search engine, but like a
 * human would. I’ve decided to use GeckoDriver, a project built
 * on Selenium that makes it possible to interact programmatically
 * with Firefox like a human would.
 * I don’t make everything with GeckoDriver. I only perform the search
 * to build an array of the links of each month archive. After that, I
 * make regular requests with Guzzle and some regex (should I use
 * DomDocument instead ? It should probably less dependant to url
 * formats…)
 */

/* Don't forget to start GeckoDriver */
$host = 'http://localhost:4444';
$firefoxOptions = new FirefoxOptions();
// Let’s go headless to improve the performances a bit…
$firefoxOptions->addArguments(['-headless']);
$capabilities = DesiredCapabilities::firefox();
$capabilities->setCapability(FirefoxOptions::CAPABILITY, $firefoxOptions);
$driver = RemoteWebDriver::create($host, $capabilities);


$year = 2022;
$months_links = [];
for ($y = $year; $y > 2009; $y--) {
    echo $y.PHP_EOL;
    $driver->get('https://www.smashingmagazine.com/search/?q=wallpaper%20'.$y);
    $driver->wait(2000)->until(
        WebDriverExpectedCondition::visibilityOfAnyElementLocated(
            WebDriverBy::cssSelector('h2.article--post__title')
        )
    );
    $current_year_archive_links = $driver->findElements(
        WebDriverBy::cssSelector('.article--post__title a')
    );
    foreach ($current_year_archive_links as $month_link) {
        $href = $month_link->getAttribute('href');
        if (!in_array($href, $months_links)) {
            $months_links[] = $href;
        }
    }

}
// terminate the session and close the browser
$driver->quit();

// Let’s persist those links, it might prove useful in some time…
if (file_exists('links.json')) {
    unlink('links.json');
}
file_put_contents('links.json', json_encode($months_links));
if(!file_exists('/Users/dominique/Downloads/sm/')){
    if (!mkdir('/Users/dominique/Downloads/sm/') && !is_dir('/Users/dominique/Downloads/sm/')) {
        throw new \RuntimeException(sprintf('Directory "%s" was not created', '/Users/dominique/Downloads/sm/'));
    }
}

foreach ($months_links as $month_link) {
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
        $client->get($image_link, ['sink' => '/Users/dominique/Downloads/sm/'.$filename]);
    } catch (ClientException $guzzle_exception) {
        var_dump($guzzle_exception->getMessage());
        die();
    }
}

function removeBaseUriFromImageLink($image_link)
{
    if (str_contains(BASE_URI, $image_link)) {
        str_replace(BASE_URI, '', $image_link);
    }
    return $image_link;
}


