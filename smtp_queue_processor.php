<?php
/*
 * this script will process the SMTP queue and send the messages
 */
/*
CREATE TABLE IF NOT EXISTS `smtp_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `send_to` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `send_status` smallint(6) NOT NULL DEFAULT '0',
  `send_date` datetime DEFAULT NULL,
  `added2queue` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `header` text COLLATE utf8_unicode_ci,
  `subject` text COLLATE utf8_unicode_ci NOT NULL,
  `body` mediumtext COLLATE utf8_unicode_ci NOT NULL,
  `smtpd_return` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `send_status` (`send_status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;
 */

set_time_limit(90);
ini_set('memory_limit', '5M'); //large e-mail bodies will consume a lot of ram on fwrite 5M should be enough
$CONFIG = array();

/* database information */

$CONFIG['DB_SERVER'] = 'localhost'; //database server name
$CONFIG['DB_NAME'] = 'smtp_queue'; //name of database containing the "smtp_queue" table above
$CONFIG['DB_USER'] = 'foo'; //database username
$CONFIG['DB_PASSWORD'] = 'bar'; //database password


/* SMTP Server account info */
$CONFIG['SMTP_SERVER'] = 'smtp.gmail.com'; //IP or FQFDN of your server
$CONFIG['SMTP_PORT'] = 143; // port number for your smtp server
$CONFIG['SMTP_USER'] = 'user@gmail.com'; // smtp account name
$CONFIG['SMTP_PASSWORD'] = '123'; // smtp account password
$CONFIG['SMTP_FROM'] = 'Your Name'; //Name of sender
$CONFIG['SMTP_FROM_EMAIL'] = 'user@gmail.com'; //reply to e-mail address
$CONFIG['SMTP_CRYPTO'] = 'tls'; // encryption type of smtp connection
                                //on of the following      starttls , tls ,  ssl or none
$CONFIG['WEBSERVER_RDNS'] = php_uname('n'); //reverse DNS name of your webserver. this needs to be
                                            // the correct rDNS/FQDN for some STMP servers


/* script settings - default values should be fine in this section */
$CONFIG['SMTP_QUEUE_DIFF'] = 300; // this many seconds must have passed between runs
$CONFIG['SMTP_SEND_DELAY'] = 5000000; //in us, deleay between processing DB row entried
                                      //  1 * 1000000 = 1 second delay

/* ###### CONFIG END ###### */
//if( !isset($base_dir) ){ $base_dir = '../../'; }
//require_once $base_dir.'conf/config.php';



/* script starts here */

//Check when the queue was last processed and die() the request if
// $CONFIG['SMTP_QUEUE_DIFF'] seconds have not passed
process_lockfile( $CONFIG['SMTP_QUEUE_DIFF'] );


/* setup e-mail object */
require_once 'SendEmail.php';
$e = new SendEmail();
$e->set_hostname( $CONFIG['WEBSERVER_RDNS'] ); // rDNS name of your server - php_uname('n') is only guess
$e->set_server( $CONFIG['SMTP_SERVER'] , $CONFIG['SMTP_PORT'] );
$e->set_auth( $CONFIG['SMTP_USER'],  $CONFIG['SMTP_PASSWORD'] );
$e->set_sender( $CONFIG['SMTP_FROM'] , $CONFIG['SMTP_FROM_EMAIL'] ); //this is the FROM: info of the email
$e->set_crypto( $CONFIG['SMTP_CRYPTO'] ); // starttls , tls ,  ssl or none


/* connect to database */
$conn = mysqli_connect( $CONFIG['DB_SERVER'], $CONFIG['DB_USER'], $CONFIG['DB_PASSWORD'], $CONFIG['DB_NAME'] );
if( !$conn ) {
  $msg = "Unable to connect with MySQL server\n"
        ."Error reported by mySQL: ".mysqli_connect_error();
  throw new Exception($msg);
}
if( !mysqli_set_charset( $conn, 'utf8') )
{
  $msg = "Failed to set charset to utf8!\n"
        ."Error reported by mySQL: ".mysqli_error($conn);
  throw new Exception($msg);
}



/*
 * queue status
 * 0 = not sent
 * 1 = error
 * 2 = sent
 *
 */
$sql = "SELECT * FROM smtp_queue WHERE send_status = 0 OR send_Status = 1 LIMIT 10";
$res = @mysqli_query($conn, $sql);// or die("{$error_msg} <p>$sql</p>".mysqli_error($this->conn));
if( !$res ){
  $msg = "Error executing the following query: $sql\n"
        ."mySQL reports: ".mysqli_error($this->conn);
  throw new Exception($msg);
}

while( $row = mysqli_fetch_array($res, MYSQL_ASSOC) )
{
  $smtp_ret = null;
  $send_to = trim($row['send_to'], ',');
  if(strstr($send_to, ','))
  {
    $asend_to = explode(',', $send_to);
  }else{
    $asend_to = array( $send_to);
  }


  foreach( $asend_to as $to )
  {
    if( $e->mail( $to , $row['subject'] ,  $row['body']) == true )
    {
      $sql = 'UPDATE smtp_queue SET send_status = 2, send_date = NOW(), smtpd_return = ? WHERE id = ?';
      $stmt = mysqli_prepare( $conn, $sql);
      $stmt->bind_param("si", $e->srv_ret['full'], $row['id'] );
      $stmt->execute();
      $stmt->close();

      echo "One out+\n";
    }else{
      $sql = 'UPDATE smtp_queue SET send_status = 1, send_date = NOW(), smtpd_return = ? WHERE id = ?';
      $stmt = mysqli_prepare( $conn, $sql);
      $stmt->bind_param("si", $e->srv_ret['full'], $row['id'] );
      $stmt->execute();
      $stmt->close();
      echo "One failed+ -- $smtp_ret\n";
    }
  }
  usleep( $CONF['SMTP_SEND_DELAY'] ); //don't hammer the smtp server!

} //outer while


set_action_flag(0);
//all done
die("All done\n");














/**
 * checks if the script is allowed to process the smtp queue
 * ends execution or updates the lockfile
 * @param int $lock_seconds this many seconds must have passed between script runs
 */
function process_lockfile( &$lock_seconds ){
  $filename = 'smtp_process_processor.php.lock';
  $debug = true;
  $dir_slash = get_dir_slash();
  $run_now = microtime(true);

  $lock = getcwd().$dir_slash.$filename;
  if(file_exists($lock) === true )
  {
    //get info from lockfile, active|timestamp  => 0/1|12345678
    $handle = fopen($lock, "r");
    $lock_content = @fread($handle, filesize($lock));
    fclose($handle);
    $parts = explode('|', $lock_content);
    if( is_array($parts) && isset($parts[0]) && isset($parts[1]) ){
      if( $debug === true ){
        echo "parts from lockfile\n";
      }
      $lockfile_active = (int)$parts[0];
      $lockfile_last_run = (int)$parts[1];
      unset($parts);
    }else{
      if( $debug === true ){
        echo "lockfile empty\n";
      }
      $lockfile_active = 0;
      $lockfile_last_run = 0;
    }


    $run_last = ($lockfile_last_run > 0 ) ? ($lockfile_last_run) : $run_now-1-$lock_seconds; //set last run

    if( $lockfile_active !== 0 )
    {
      //too early or died last time?
      if( $run_now - $lock_seconds > $run_last )
      {
        // died, write new lock info
        if( $debug === true ){
          echo "lock expired - might have died, write new info\n";
        }
        set_action_flag(1);

      }else{
        if( $debug === true ){
          echo "lock still good, die() here\n";
        }
        die(); //too early
      }

    }else{
      /* lockfile exist but script not currently active */
      if( $debug === true ){
        echo "lockfile exists but script not active\n";
      }
      set_action_flag(1);
    }


  }else{
    /* lockfile does not exist, create a new file */
    if( $debug === true ){
      echo "lockfile does not exist, write new\n";
    }
    set_action_flag(1);
  }
}



/**
 * function to set the lockfile action flag to 0
 * @param int $flag 0 if queue is done or 1 if queue is running
 */
function set_action_flag( $flag=0 ){
  $filename = 'smtp_process_processor.php.lock';
  $w_flag = ( $flag == 1 ) ? 1 : 0 ;
  $dir_slash = get_dir_slash();
  $run_now = microtime(true);

  $lock = getcwd().$dir_slash.$filename;
  $handle = fopen($lock, "wb");
  $write_this = $w_flag.'|'.$run_now; // mark script as active
  fwrite( $handle, $write_this);
  fclose($handle);
}

/**
 * returns the correct directory slash for the current OS
 * @return string
 */
function get_dir_slash(){
  static $slash = '';

  if( $slash === '' ){
    if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'){
      $slash = '\\';
    }else{
      $slash = '/';
    }
  }

  return $slash;
}
?>