<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use RocketTheme\Toolbox\Event\Event;
use GuzzleHttp\Client as Guzzle;

const TMP_FILE = '/tmp/grav-keycdn-purge';

class KeycdnPurgePlugin extends Plugin {

  public static function getSubscribedEvents() {
    return [
      'onPluginsInitialized' => ['onPluginsInitialized', 0],
    ];
  }

  public function onPluginsInitialized() {

    require __DIR__ . '/vendor/autoload.php';

    if(!\file_exists(TMP_FILE)) {
      \file_put_contents(TMP_FILE, json_encode([ 'purge' => false, 'purging' => false ]));
    }
    
    if (!$this->isAdmin()) {
        $this->enable([
            'onTask.purge-sitemap'      => ['purgeSitemap', 0],
        ]);

    } else {
        $this->enable([
            'onAdminAfterSave'     => ['purgeCache', 10],
            'onAdminAfterSaveAs'   => ['purgeCache', 10],
            'onAdminAfterDelete'   => ['purgeCache', 10],
            'onShutdown'           => ['onShutdown', 10000]
        ]);
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
            foreach($xml->children() AS $url) {
                $url = parse_url($url->loc);
                if(isset($url['path']) && $url['path'] != '' && $url['path'] != '/suche') {
                    $urls[] = $keyHost . $url['path'];
                }
            }
        }

        $this->grav['log']->info('keycdn-purge-sitemap: '. count($urls) .' found to purge');

        \file_put_contents(TMP_FILE, json_encode([ 'purge' => false, 'purging' => true ]));

        $chunks = array_chunk($urls, 19);
        foreach($chunks AS $chunkNo => $chunk) {
            $res = $client->delete("https://api.keycdn.com/zones/purgeurl/$zoneId.json", [
                'headers' => ['content-Type' => 'application/json'],
                'auth' => [ $token, '' ],
                'body'  => json_encode([ "urls" => $chunk]),
            ]);
			sleep(1);
            $this->grav['log']->info('keycdn-purge-sitemap: Chunk '. $chunkNo .' Http Response Body: ' . $res->getBody());
        }

        // unlock purge process
        \file_put_contents(TMP_FILE, json_encode([ 'purge' => false, 'purging' => false ]));


    }

  public function purgeCache(Event $event) {
	\file_put_contents(TMP_FILE, json_encode([ 'purge' => true, 'purging' => false, 'hersteller' => $event['object']->header()->taxonomy['hersteller'] ]));
  }

  public function onShutdown() {

    $client = new Guzzle();

    $token = $this->config->get('plugins.keycdn-purge.token');
    $zoneId = $this->config->get('plugins.keycdn-purge.zone_id');
	$keyHost = $this->config->get('plugins.keycdn-purge.cache_key_host.enabled') ? $this->config->get('plugins.keycdn-purge.cache_key_host.url') : '';

    $purge = json_decode(file_get_contents(TMP_FILE));


    if ($purge->purge && !$purge->purging) {

      \file_put_contents(TMP_FILE, json_encode([ 'purge' => false, 'purging' => true ]));
	  
	  $urls = [  
			$keyHost .'/',
			$keyHost .'/neuigkeiten',
			$keyHost .'/fahrberichte',
			$keyHost .'/klassiker',
			$keyHost .'/automarken',
			str_replace(
				$this->config->get('plugins.admin.route') .'/pages',
				'',
				$keyHost . $this->grav['uri']->url()
			)
		];
		
		if(!empty($purge->hersteller)) {
			foreach($purge->hersteller AS $hersteller) {
				$urls[] = $keyHost . '/automarken/hersteller:'. $hersteller;
			}
		}
		
		if($this->grav['uri']->url() == '/admin/pages/automarken/honda') {
			$urls[] = $keyHost . '/specials/honda';	
			$urls[] = $keyHost . '/automarken/hersteller:honda';
		}
  	
       $res = $client->delete("https://api.keycdn.com/zones/purgeurl/$zoneId.json", [ 
		'headers' => [
			'Content-Type' => 'application/json'
		],      
		'auth' => [ $token, '' ],
		'body'  => json_encode(["urls" => $urls]),
      ]);    

      $this->grav['log']->info('keycdn-purge: Http Response Body: ' . $res->getBody());
      
      \file_put_contents(TMP_FILE, json_encode([ 'purge' => false, 'purging' => false, 'hersteller' => [] ]));
    }
    
  }
}
