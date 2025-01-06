<?php

namespace Websitemacherei\KeycdnPurge;

class Scanner {
  public static $files = [];

  public static function scan($dir) {
    $dirs = array_diff( scandir( $dir ), Array( ".", ".." ));
    $dir_array = Array();
    foreach( $dirs AS $fileOrDir ) {
      if (is_dir($dir . "/" . $fileOrDir)) {
        self::scan($dir . "/" . $fileOrDir);
      } elseif(substr($fileOrDir, strrpos($fileOrDir, ".") + 1) == 'md') {
        $file = $dir .'/'. $fileOrDir;
        $yaml = yaml_parse_file($file);

        $data = [
          'file' => $file,
          'title' => @$yaml['title'] .' '. @$yaml['subtitle']
        ];

        if(isset($yaml['published'])) {
          $data['published'] = $yaml['published'] ?: false;
        }

        if(isset($yaml['date'])) {
          $data['date'] = $yaml['date'];
        }

        if(isset($yaml['publish_date'])) {
          $data['publish_date'] = $yaml['publish_date'];
        }

        if(isset($yaml['unpublish_date'])) {
          $data['unpublish_date'] = $yaml['unpublish_date'];
        }

        $data['mtime'] = filemtime($file);
        $data['mtime_date'] = date ("Y-m-d H:i", filemtime($file));

        $data['ctime'] = filectime($file);
        $data['ctime_date'] = date ("Y-m-d H:i", filectime($file));

        $data = self::realStatus($data);

        self::$files[] = $data;
      }
    }
  }

  public static function sortFilesLatest() {
    usort(self::$files, function($a, $b) {
      return $a['mtime'] < $b['mtime'];
    });
  }

  public static function filterUpcomingGolive($treshold = 150) {
    self::$files = [];
    self::scan('user/pages');
    $golive = [];
    foreach(self::$files AS $file) {
      if(!isset($file['published']) && isset($file['publish_date'])) {
        $publishTimestamp = strtotime($file['publish_date']);
        $goliveTresholdMin = time()-1;
        $goliveTresholdMax = time()+$treshold;

        if($publishTimestamp >= $goliveTresholdMin) {

          $file['real_published_seconds_left'] = ($publishTimestamp-$goliveTresholdMin);
          $golive[] = $file;
        }
      }
    }

    return $golive;
  }
  
  public static function filterGoliveNow() {
    self::$files = [];
    self::scan('user/pages');
    $golive = [];
    foreach(self::$files AS $file) {
      if(!isset($file['published']) && isset($file['publish_date'])) {
        $publishTimestamp = strtotime($file['publish_date']);
        $goliveTresholdMin = time()-30;
        $goliveTresholdMax = time()+30;

        if($publishTimestamp >= $goliveTresholdMin && $publishTimestamp <= $goliveTresholdMax) {

          $file['real_published_seconds_left'] = ($publishTimestamp-time());
          $golive[] = $file;
        }
      }
    }

    return $golive;
  }

  public static function realStatus($data) {

    // keine angaben gemacht
    if(!isset($data['published']) && !isset($data['publish_date'])) {
      $data['real_published_status'] = 2;
      $data['real_published'] = 'JA';
      $data['real_published_string'] = 'implizit online weil keine Angaben';
    }

    // explizit online
    if(isset($data['published']) && $data['published'] == true) {
      $data['real_published_status'] = 1;
      $data['real_published'] = 'JA';
      $data['real_published_string'] = 'manuell live geschalten';

      // explizit offline
    } elseif (isset($data['published']) && $data['published'] == false) {
      $data['real_published_status'] = 0;
      $data['real_published'] = 'NEIN';
      $data['real_published_string'] = 'manuell off geschalten';

      // golive datum gesetzt
    } elseif(!isset($data['published']) && isset($data['publish_date'])) {

      // golive in der Zukunft
      if(strtotime($data['publish_date']) > time()) {
        $data['real_published_status'] = 2;
        $data['real_published'] = 'NEIN';
        $data['real_published_date'] = date('Y-m-d H:i',strtotime($data['publish_date']));
        $data['real_published_string'] = 'golive: '. $data['publish_date'];

        // golive in der Vergangenheit
      } elseif(strtotime($data['publish_date']) <= time()) {
        $data['real_published_status'] = 1;
        $data['real_published'] = 'JA';
        $data['real_published_date'] = date('Y-m-d H:i',strtotime($data['publish_date']));
        $data['real_published_string'] = 'online seit ' . $data['publish_date'];
      }

    }

    // soll auch wieder offline?
    if(!isset($data['published'])
      && isset($data['unpublish_date'])) {
		  
		// offline in der Zukunft
      if(strtotime($data['unpublish_date']) > time()) {
        $data['real_published_status'] = 2;
        $data['real_published_string'] .= '; bis '. $data['unpublish_date'];

      } elseif(strtotime($data['unpublish_date']) <= time()) {
        $data['real_published_status'] = 0;
        $data['real_published'] = 'NEIN';
        $data['real_published_string'] .= '; wieder offline seit '. $data['unpublish_date'];
      }
    }
    return $data;
  }

  public static function showSearchableTable($files = []) {
	  /*
    if(empty($files)) {
      self::scan('user/pages');
      self::sortFilesLatest();
      $files = self::$files;
    }
	*/

    echo '<table id="golivescanner" class="display">'.
      '<thead>'.
      '<tr>'.
      '  <th>Titel</th>'.
      '  <th>Real VÖ</th>'.
      '  <th>Real VÖ Datum</th>'.
      '  <th>Real VÖ Erklärung</th>'.
      '  <th>Bearbeitung</th>'.
      '</tr>'.
      '</thead>'.
      '<tbody>';

    foreach($files AS $file) {
      echo '<tr>'.
        '<td>'. $file['title'] .'</td>'.
        '<td style="color:'.(($file['real_published'] == 'JA') ?: "red") .'">'. $file['real_published'] .'</td>'.
        '<td>'. $file['real_published_date'] .'</td>'.
        '<td>'. $file['real_published_string'] .'</td>'.
        //'<td>'. $file['published'] .'</td>'.
        //'<td>'. $file['publish_date'] .'</td>'.
        '<td>'. $file['mtime_date'] .'</td>'.
        '</tr>';
    }

    echo  '</tbody>'.
      '</table>';

    echo '<script type="text/javascript" charset="utf8" src="https://code.jquery.com/jquery-3.5.1.js"></script>';
    echo '<link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css" />';
    echo '<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>';
    echo '<script>let table = new DataTable("#golivescanner", { pageLength:25, responsive: true, order:[[5,"desc"]]});</script>';
  }
}
