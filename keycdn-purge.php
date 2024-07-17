<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Plugin\Admin\Admin;
use RocketTheme\Toolbox\Event\Event;
use GuzzleHttp\Client as Guzzle;
use Websitemacherei\KeycdnPurge\Scanner;
use Grav\Common\Data;
use Grav\Common\Grav;
use SplFileInfo;
use Grav\Common\Utils;

const TMP_FILE = '/tmp/grav-keycdn-purge';

class KeycdnPurgePlugin extends Plugin
{

  public static function getSubscribedEvents()
  {
    return [
      'onPluginsInitialized' => [
        ['autoload', 100000],
        ['onPluginsInitialized', 0],
      ],
      'onBlueprintCreated' => ['onBlueprintCreated', 0],
      'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
    ];
  }

  /**
   * Composer autoload.
   *is
   * @return ClassLoader
   */
  public function autoload(): ClassLoader
  {
    return require __DIR__ . '/vendor/autoload.php';
  }

  public function onPluginsInitialized()
  {

    if (!\file_exists(TMP_FILE)) {
      \file_put_contents(TMP_FILE, json_encode(['purge' => false, 'purging' => false]));
    }

    if (!$this->isAdmin()) {
      /*
      $this->enable([
        'onTask.purge-sitemap' => ['purgeSitemap', 0],
        'onTask.upcoming-golives' => ['listUpcomingGolives', 0],
      ]);
      */

    } else {
      $this->enable([
        'onTask.purge-sitemap' => ['purgeSitemap', 0],
        'onTask.upcoming-golives' => ['listUpcomingGolives', 0],
        'onAdminAfterSave' => ['purgeCache', 10],
        'onAdminAfterSaveAs' => ['purgeCache', 10],
        'onAdminAfterDelete' => ['purgeCache', 10],
        'onShutdown' => ['onShutdown', 10000]
      ]);
    }

  }

  /**
   * Extend page blueprints with feed configuration options.
   *
   * @param Event $event
   */
  public function onBlueprintCreated(Event $event)
  {
    static $inEvent = false;

    /** @var Data\Blueprint $blueprint */
    $blueprint = $event['blueprint'];
    if (!$inEvent && $blueprint->get('form/fields/tabs', null, '/')) {
      if (!in_array($blueprint->getFilename(), array_keys($this->grav['pages']->modularTypes()))) {
        $inEvent = true;
        $blueprints = new Data\Blueprints(__DIR__ . '/blueprints/');
        $extends = $blueprints->get('keycdn');
        $blueprint->extend($extends, true);
        $inEvent = false;
      }
    }
  }

  /**
   * @param Event $event
   */
  public function onSchedulerInitialized(Event $e): void
  {
    $config = $this->config();

    if (!empty($config['enabled'])) {
      $scheduler = $e['scheduler'];
      $golives = Scanner::filterGoliveNow();
      //$golives = Scanner::filterUpcomingGolive();

      $job = $scheduler->addFunction(function () use ($golives) {

		$keyHost = Grav::instance()['config']->get('plugins.keycdn-purge.cache_key_host.enabled') ? $this->config->get('plugins.keycdn-purge.cache_key_host.url') : '';
		
		if(!empty($golives)) {
          $goliveURLs = [];

          // collect URLS
          $urls = [
            $keyHost . '/',
            $keyHost . '/neuigkeiten',
            $keyHost . '/fahrberichte',
            $keyHost . '/klassiker',
            $keyHost . '/automarken',
          ];

          foreach($golives AS $golive) {
			$golivePage = new Page;
            $golivePage = $golivePage->init(new SplFileInfo($golive['file']));

            $grav = Grav::instance();

            $primaryURL = $keyHost . preg_replace('/[0-9]+\./u', '', str_replace('user/pages','',$golivePage->path()));

            $urls[] = $primaryURL;
            $goliveURLs[] = $primaryURL;

            // Hersteller
            if (!empty($golivePage->header()->taxonomy['hersteller'])) {
              foreach ($golivePage->header()->taxonomy['hersteller'] as $hersteller) {
                $urls[] = $keyHost . '/automarken/hersteller:' . $hersteller;
              }
            }

            if (in_array('honda', $golivePage->header()->taxonomy['hersteller'])) {
              $urls[] = $keyHost . '/specials/honda';
            }
		  }
		  
		  // INVALIDATE URLS
          if(!empty($urls)) {
            $client = new Guzzle();

            $token = Grav::instance()['config']->get('plugins.keycdn-purge.token');
            $zoneId = Grav::instance()['config']->get('plugins.keycdn-purge.zone_id');
            $keyHost = Grav::instance()['config']->get('plugins.keycdn-purge.cache_key_host.enabled') ? $this->config->get('plugins.keycdn-purge.cache_key_host.url') : '';

            $res = $client->delete("https://api.keycdn.com/zones/purgeurl/$zoneId.json", [
              'headers' => ['content-Type' => 'application/json'],
              'auth' => [$token, ''],
              'body' => json_encode(["urls" => $urls]),
              ]);
          }
		  
		  // PRETTIFY URLS
		  echo '<strong>GOLIVE der folgenden URLs:</strong><br>';
          foreach($goliveURLs AS $goliveURL) {
            echo '<a href="'. $goliveURL .'">'. $goliveURL .'</a><br>';
          }
          echo '<br>';
          echo '<strong>Dafür wurden zusätzlich die folgenden URLS invalidiert:</strong><br>';
          foreach($urls AS $url) {
            echo '<a href="'. $url .'">'. $url .'</a><br>';
          }
		  
			Grav::instance()['log']->info('keycdn-purge: Http Response Body: ' . $res->getBody());
			
			echo '<br><br><italic>Antwort des CDN:'. $res->getBody() .'</italic>';
		}
		
		
      }, [], 'purgeGolive');
      //$job->at('*/5 * * * *');
      $job->at('* * * * *');
      $job->output('logs/scheduler.golive.log');

      if(!empty($golives)) {
		$job->email(['bernd@autonotizen.de','info@diewebsitemacherei.de']);
      }
    }
  }

  public function purgeSitemap()
  {
    $client = new Guzzle();
    $token = $this->config->get('plugins.keycdn-purge.token');
    $zoneId = $this->config->get('plugins.keycdn-purge.zone_id');
    $keyHost = $this->config->get('plugins.keycdn-purge.cache_key_host.enabled') ? $this->config->get('plugins.keycdn-purge.cache_key_host.url') : '';
    $urls = [];

    // get sitemap and extract urls
    $response = $client->get('https://aws.autonotizen.de/sitemap.xml');
    if ($response->getBody()) {
      //$sitemap = \file_get_contents(__DIR__.'/sitemap.20240320.xml');
      $xml = simplexml_load_string($response->getBody());
      //$xml = simplexml_load_string($sitemap);
      foreach ($xml->children() as $url) {
        $url = parse_url($url->loc);
        if (isset($url['path']) && $url['path'] != '' && $url['path'] != '/suche') {
          $urls[] = $keyHost . $url['path'];
        }
      }
    }

    $this->grav['log']->info('keycdn-purge-sitemap: ' . count($urls) . ' found to purge');

    \file_put_contents(TMP_FILE, json_encode(['purge' => false, 'purging' => true]));

    $chunks = array_chunk($urls, 19);
    foreach ($chunks as $chunkNo => $chunk) {
      $res = $client->delete("https://api.keycdn.com/zones/purgeurl/$zoneId.json", [
        'headers' => ['content-Type' => 'application/json'],
        'auth' => [$token, ''],
        'body' => json_encode(["urls" => $chunk]),
      ]);
      sleep(1);
      $this->grav['log']->info('keycdn-purge-sitemap: Chunk ' . $chunkNo . ' Http Response Body: ' . $res->getBody());
    }

    // unlock purge process
    \file_put_contents(TMP_FILE, json_encode(['purge' => false, 'purging' => false]));


  }

  public function listUpcomingGolives()
  {
    $golives = Scanner::filterUpcomingGolive();
    Scanner::showSearchableTable($golives);

    die();
  }

  public function purgeCache(Event $event)
  {
    if(method_exists($event['object'],'header')) {
      \file_put_contents(TMP_FILE, json_encode([
        'purge' => true,
        'purging' => false,
        'hersteller' => @$event['object']->header()->taxonomy['hersteller']]));
    }
  }

  public function onShutdown()
  {
    $client = new Guzzle();

    $token = $this->config->get('plugins.keycdn-purge.token');
    $zoneId = $this->config->get('plugins.keycdn-purge.zone_id');
    $keyHost = $this->config->get('plugins.keycdn-purge.cache_key_host.enabled') ? $this->config->get('plugins.keycdn-purge.cache_key_host.url') : '';

    $purge = json_decode(file_get_contents(TMP_FILE));


    if ($purge->purge && !$purge->purging) {

      \file_put_contents(TMP_FILE, json_encode(['purge' => false, 'purging' => true]));

      $urls = [
        $keyHost . '/',
        $keyHost . '/neuigkeiten',
        $keyHost . '/fahrberichte',
        $keyHost . '/klassiker',
        $keyHost . '/automarken',
        str_replace(
          $this->config->get('plugins.admin.route') . '/pages',
          '',
          $keyHost . $this->grav['uri']->url()
        )
      ];

      if (!empty($purge->hersteller)) {
        foreach ($purge->hersteller as $hersteller) {
          $urls[] = $keyHost . '/automarken/hersteller:' . $hersteller;
        }
      }

      if ($this->grav['uri']->url() == '/admin/pages/automarken/honda') {
        $urls[] = $keyHost . '/specials/honda';
        $urls[] = $keyHost . '/automarken/hersteller:honda';
      }

      $res = $client->delete("https://api.keycdn.com/zones/purgeurl/$zoneId.json", [
        'headers' => [
          'Content-Type' => 'application/json'
        ],
        'auth' => [$token, ''],
        'body' => json_encode(["urls" => $urls]),
      ]);

      $this->grav['log']->info('keycdn-purge: Http Response Body: ' . $res->getBody());

      \file_put_contents(TMP_FILE, json_encode(['purge' => false, 'purging' => false, 'hersteller' => []]));
    }

  }

  public static function getCurrentPublishedStatus() {


    $grav = Grav::instance();

    $route = '/' . ltrim($grav['admin']->route, '/');

    /* +the GRAV1.7Way
      $pages = Admin::enablePages();
      $page = $pages->find($route);
    */

    // GRAV16
    $page = $grav['pages']->find($route);

    $data = Scanner::realStatus((array)$page->header());

    $alertMode = 'notice';
    if($data['real_published_status'] == 0) {
      $alertMode = 'error';
    }
    if($data['real_published_status'] == 2) {
      $alertMode = 'info';
    }

    if($route == '/publish-test/implizit-online') {
    }

    /*
      if($route == '/publish-test/implizit-online') {
        $golives = Scanner::filterUpcomingGolive();

        $urls = [
          $keyHost . '/',
          $keyHost . '/neuigkeiten',
          $keyHost . '/fahrberichte',
          $keyHost . '/klassiker',
          $keyHost . '/automarken',
        ];

        foreach($golives AS $golive) {

          $golivePage = new Page;
          $golivePage = $golivePage->init(new SplFileInfo($golive['file']));

          $grav = Grav::instance();

          $primaryURL = preg_replace('/[0-9]+\./u', '', str_replace('user/pages','',$golivePage->path()));

          $urls[] = $primaryURL;

          // Hersteller
          if (!empty($golivePage->header()->taxonomy['hersteller'])) {
            foreach ($golivePage->header()->taxonomy['hersteller'] as $hersteller) {
              $urls[] = $keyHost . '/automarken/hersteller:' . $hersteller;
            }
            }

          if (in_array('honda', $golivePage->header()->taxonomy['hersteller'])) {
            $urls[] = $keyHost . '/specials/honda';
          }

        }


      }
      */

    return '<br><div class="alert '. $alertMode .'">'.
      '<strong>Onlinestatus: '. $data['real_published'] .'</strong><br><small>['. $data['real_published_string'] .']</small><br>'.
      '</div>'.
      '&raquo; <a href="/admin/task:upcoming-golives" target="_blank">Alle kommenden Golive in der Übersicht</a>'.
      // '<br>&raquo; <a href="/admin/task:purge-url" target="_blank">Diese Seite im CDN invalidieren</a>'.
      '';
  }
}
