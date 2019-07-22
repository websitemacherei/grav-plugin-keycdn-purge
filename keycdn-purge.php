<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
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
    
    if (!$this->isAdmin()) return;
    
    $this->enable([
      'onAdminAfterSave'     => ['purgeCache', 10],
      'onAdminAfterSaveAs'   => ['purgeCache', 10],
      'onAdminAfterDelete'   => ['purgeCache', 10],
      'onShutdown'           => ['onShutdown', 10000]
    ]);
  }

  public function purgeCache() {
    \file_put_contents(TMP_FILE, json_encode([ 'purge' => true, 'purging' => false ]));
  }

  public function onShutdown() {

    $client = new Guzzle();

    $token = $this->config->get('plugins.keycdn-purge.token');
    $zoneId = $this->config->get('plugins.keycdn-purge.zone_id');

    $purge = json_decode(file_get_contents(TMP_FILE));

    if ($purge->purge && !$purge->purging) {

      \file_put_contents(TMP_FILE, json_encode([ 'purge' => false, 'purging' => true ]));
  
      $res = $client->get("https://api.keycdn.com/zones/purge/$zoneId.json", [ 'auth' => [ $token, '' ] ]);    
  
      $this->grav['log']->info('keycdn-purge: Http Respone Body: ' . $res->getBody());
      
      \file_put_contents(TMP_FILE, json_encode([ 'purge' => false, 'purging' => false ]));
    }
    
  }
}