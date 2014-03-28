<?php
/*
 * Example script SMTP queue script for the SendEmail() class
 *
 *  Sending email directly from your PHP script will result in a multiple
 *  second long "hang" while the script is communicating with the external
 *  SMTP server. An alternative to sending e-mails directly is to queue them somewhere
 *  and have another script process the queued messages.
 *
 *  This script will receive a list of unsent messages from a mySQL table and process them
 *  one by one.
 *    * prevents multiple scripts from processing the queue. This protects the server from
 *      consuming too much RAM when sending large e-mail bodies.
 *    * may be called periodically by a cronjob or via exec to send e-mails right away
 *      The following exec call will execute the script in the background.
 *        exec( 'php -f "/path/to/smtp_queue_processor.php" &> /dev/null &' );
 */
/*
CREATE TABLE IF NOT EXISTS `smtp_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `send_to` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'recipient email separated by comma',
  `send_status` smallint(6) NOT NULL DEFAULT '0' COMMENT '0 == new , 1 == error , 2 == sent',
  `send_date` datetime DEFAULT NULL,
  `added2queue` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `header` text COLLATE utf8_unicode_ci,
  `subject` text COLLATE utf8_unicode_ci NOT NULL,
  `body` mediumtext COLLATE utf8_unicode_ci NOT NULL,
  `smtpd_return` text COLLATE utf8_unicode_ci COMMENT 'contents of SendEmail->srv_ret[''full'']',
  PRIMARY KEY (`id`),
  KEY `send_status` (`send_status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1;
 */

/* ###### CONFIG START ###### */
set_time_limit(90); //should protect the queue processor from hanging too long during
                    // a communication error with the server.
                    // never seen it happended, only a precaution...
ini_set('memory_limit', '5M'); //large e-mail bodies will consume a lot of ram during
                               // fwrite to the socket - 5M should cover most scenarios


$CONFIG = array();
/* database information */
$CONFIG['DB_SERVER'] = 'localhost'; //database server name
$CONFIG['DB_NAME'] = 'smtp_queue'; //name of database containing the "smtp_queue" table above
$CONFIG['DB_USER'] = 'foo'; //database username
$CONFIG['DB_PASSWORD'] = 'bar'; //database password


/* SMTP Server account info */
$CONFIG['SMTP_SERVER'] = 'smtp.gmail.com'; //IP or FQFDN of your server
$CONFIG['SMTP_PORT'] = 465; // port number for your smtp server
$CONFIG['SMTP_USER'] = 'user@gmail.com'; // smtp account name
$CONFIG['SMTP_PASSWORD'] = '123'; // smtp account password
$CONFIG['SMTP_FROM'] = 'Your Name'; //Name of sender
$CONFIG['SMTP_FROM_EMAIL'] = 'user@gmail.com'; //reply to e-mail address
$CONFIG['SMTP_CRYPTO'] = 'tls'; // encryption type of smtp connection
                                //on of the following      starttls , tls ,  ssl or none
$CONFIG['WEBSERVER_RDNS'] = php_uname('n'); //reverse DNS name of your webserver. this needs to be
                                            // the correct rDNS/FQDN for some STMP servers


/* script settings - default values should be fine in this section */
$CONFIG['SMTP_CLEAR_SUCCESS'] = false; //delete entry from queue table on success or keep
                                      // to have a record of processed mails
$CONFIG['SMTP_SEND_DELAY'] = 1000000; //in us, deleay between processing DB row entries
                                      //  1 * 1000000 == 1 second delay
$CONFIG['SMTP_PROCESS_BATCH'] = 10; // process this many items from the queue table and exit
                                    //  this option is an "exploit protection" mechanism
                                    //  in case some figures out how to write to
                                    //  the smtp_queue table directly.
                                    //  The following settings will only process upto
                                    //   5 messages every 15 seconds.                                    //
                                    //    $CONFIG['SMTP_QUEUE_DIFF'] = 15;
                                    //    $CONFIG['SMTP_PROCESS_BATCH'] = 5;
$CONFIG['SMTP_QUEUE_DIFF'] = 10; // this many seconds must pass between script runs

/* ###### CONFIG END ###### */
$debug = false;
//<!--BUILD_SCRIPT_OPTION_CONFIG_STRING-->



/* script starts here */
$PID = rand_string(20); //unique by chance :)
$CONFIG['SMTP_PROCESS_BATCH'] = ( is_int($CONFIG['SMTP_PROCESS_BATCH']) === true
                                  && $CONFIG['SMTP_PROCESS_BATCH'] > 0 )
                                    ? $CONFIG['SMTP_PROCESS_BATCH'] : 1;
$CONFIG['SMTP_CLEAR_SUCCESS'] = ( is_bool($CONFIG['SMTP_CLEAR_SUCCESS']) === true
                                && $CONFIG['SMTP_CLEAR_SUCCESS'] === true )
                                  ? true : false;


//Check when the queue was last processed and die() if too soon
process_lockfile( $CONFIG['SMTP_QUEUE_DIFF'], $PID );



/* setup e-mail object */
require_once 'SendEmail.php';
$e = new SendEmail();
$e->set_hostname( $CONFIG['WEBSERVER_RDNS'] ); // rDNS name of your server - php_uname('n') is only guess
$e->set_server( $CONFIG['SMTP_SERVER'] , $CONFIG['SMTP_PORT'] );
$e->set_auth( $CONFIG['SMTP_USER'],  $CONFIG['SMTP_PASSWORD'] );
$e->set_sender( $CONFIG['SMTP_FROM'] , $CONFIG['SMTP_FROM_EMAIL'] ); //this is the FROM: info of the email
$e->set_crypto( $CONFIG['SMTP_CRYPTO'] ); // starttls , tls ,  ssl or none
if( $CONFIG['SMTP_PASSWORD'] == 12345 ){ echo "1,2,3,4,5? .... That's amazing! I've got the same combination on my luggage!\n"; }


/* connect to database */
$conn = @mysqli_connect( $CONFIG['DB_SERVER'], $CONFIG['DB_USER'], $CONFIG['DB_PASSWORD'], $CONFIG['DB_NAME'] );
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
$sql = "SELECT id,send_to,subject,body FROM smtp_queue WHERE send_status = 0 OR send_Status = 1 LIMIT ?";
if( !$stmt_outer = mysqli_prepare( $conn, $sql) ){
  $msg = "Error executing the following query: $sql\n"
        ."mySQL reports: ".mysqli_error($conn);
  throw new Exception($msg);
}
$stmt_outer->bind_param("i", $CONFIG['SMTP_PROCESS_BATCH'] );
$stmt_outer->execute();
$stmt_outer->store_result();
$row = array();
$stmt_outer->bind_result( $row['id'] , $row['send_to'] , $row['subject'] , $row['body'] );


$error_count = 0;
$processed_count = 0;
while( $stmt_outer->fetch() )
{
  $send_to = trim( $row['send_to'] , ',');
  if(strstr($send_to, ','))
  {
    $asend_to = explode(',', $send_to);
  }else{
    $asend_to = array( $send_to);
  }


  foreach( $asend_to as $to )
  {
    if( $e->mail( $to , $row['subject'] ,  $row['body'] ) === true )
    {
      if( $CONFIG['SMTP_CLEAR_SUCCESS'] === true ){
        $sql = 'DELETE FROM smtp_queue WHERE id = ?';
        if( $stmt = mysqli_prepare( $conn, $sql) ){
          $stmt->bind_param("i", $row['id'] );
          $stmt->execute();
          $stmt->close();
        }

      }else{
        $sql = 'UPDATE smtp_queue SET send_status = 2, send_date = NOW(), smtpd_return = ? WHERE id = ?';
        if( $stmt = mysqli_prepare( $conn, $sql) ){
          $stmt->bind_param("si", $e->srv_ret['full'], $row['id'] );
          $stmt->execute();
          $stmt->close();
        }
      }

      echo "One out\n";
      ++$processed_count;

    }else{
      $sql = 'UPDATE smtp_queue SET send_status = 1, send_date = NOW(), smtpd_return = ? WHERE id = ?';
      if( $stmt = mysqli_prepare( $conn, $sql) ){
        $stmt->bind_param("si", $e->srv_ret['full'], $row['id'] );
        $stmt->execute();
        $stmt->close();
      }
      echo "One failed - see 'smtpd_return' in smtp_queue table for row {$row['id']}\n";
      ++$error_count;
    }

  }
  usleep( $CONFIG['SMTP_SEND_DELAY'] ); //don't hammer the smtp server!

} //outer while


//clean up
$stmt_outer->close();
mysqli_close($conn);
set_action_flag( 0 , $PID );


echo "All done - processed $processed_count messages with $error_count errors\n";
if( $error_count === 0 ){ exit(0); }else{ exit(1); }














/**
 * checks if the script is allowed to process the smtp queue
 * ends execution or updates the lockfile
 * @param int $lock_seconds this many seconds must have passed between script runs
 * @param string &$PID process ID of this script - some unique string
 */
function process_lockfile( &$lock_seconds , &$PID ){
  global $debug;
  $filename = 'smtp_process_processor.php.lock';
  $dir_slash = get_dir_slash();
  $run_now = microtime(true);
  $lock_seconds_stale = (int)ini_get('max_execution_time') + 10;

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
      $lockfile_active = (int)$parts[0]; // 0 / 1
      $lockfile_last_run = (int)$parts[1]; // timestamp
      unset($parts);
    }else{
      if( $debug === true ){
        echo "lockfile empty\n";
      }
      $lockfile_active = 0;
      $lockfile_last_run = 0;
    }


    // process info from lockfile
    if( $lockfile_active !== 0 )
    {
      $last_run = ($lockfile_last_run > 0 ) ? $lockfile_last_run : $run_now - $lock_seconds - 1;

      //too early or died last time?
      if( $run_now - $lock_seconds > $last_run )
      {
        // died, write new lock info
        if( $debug === true ){
          echo "lock seconds($lock_seconds) have passed, set to active\n";
        }
        set_action_flag( 1 , $PID);
        validate_pid( $PID );


      }elseif( $run_now - $lock_seconds_stale > $last_run ){
        // died, write new lock info
        if( $debug === true ){
          echo "lock expired - script may have died, set to active\n";
        }
        set_action_flag( 1 , $PID);
        validate_pid( $PID );

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
      set_action_flag( 1 , $PID);
      validate_pid( $PID );
    }


  }else{
    /* lockfile does not exist, create a new file */
    if( $debug === true ){
      echo "lockfile does not exist, write new\n";
    }
    set_action_flag( 1 , $PID);
    validate_pid( $PID );
  }
}



/**
 * compares script PID with PID from lockfile and dies when not the same
 * @param string $PID unique id for this script run
 */
function validate_pid( &$PID ){
  global $debug;
  usleep(1000000); // 1 second delay to ensure that other scripts are done writing

  $content = get_lockfile();
  $c = explode('|', $content);
  $lock_pid = (string)$c[2];

  if( $PID !== $lock_pid ){
    if( $debug === true ){
      echo "error another script overwrote our PID - die() now\n";
    }
    die();
  }
}



/**
 * retrieves everything from the lockfile
 * @return string full content of lockfile, after trim()
 */
function get_lockfile(){
  $filename = 'smtp_process_processor.php.lock';
  $dir_slash = get_dir_slash();

  $lock = getcwd().$dir_slash.$filename;
  $handle = fopen($lock, "r");
  $content = @fread($handle, filesize($lock));
  fclose($handle);

  return (string)trim($content);
}


/**
 * function to set the lockfile action flag to 0
 * @param int $flag 0 if queue is done or 1 if queue is running
 * @param string $PID string written to lockfile to ensure that this script
 *                    owns the lock. should prevent race condition
 */
function set_action_flag( $flag , $PID ){
  $filename = 'smtp_process_processor.php.lock';
  $w_flag = ( $flag == 1 ) ? 1 : 0 ;
  $dir_slash = get_dir_slash();
  $run_now = microtime(true);

  $lock = getcwd().$dir_slash.$filename;
  $handle = fopen($lock, "wb");
  $write_this = $w_flag.'|'.$run_now.'|'.$PID; // mark script as active
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

/**
 * create a random string, uses mt_rand. This one is faster then my old GetRandomString()
 * rand_string(20, array('A','Z','a','z',0,9), '`,~,!,@,#,%,^,&,*,(,),_,|,+,=,-');
 * rand_string(16, array('A','Z','a','z',0,9), '.,/')
 * @param integer $lenth length of random string
 * @param array $range specify range as array array('A','Z','a','z',0,9) == [A-Za-z0-9]
 * @param string $other comma separated list of characters !,@,#,$,%,^,&,*,(,)
 * @return string random string of requested length
 */
function rand_string($lenth, $range=array('A','Z','a','z',0,9), $other='' ) {
  $out = '';
  $sel_range = array();

  $cnt_range = count($range);
  for( $x=0 ; $x < $cnt_range ; $x=$x+2 ){
	$sel_range = array_merge($sel_range, range($range[$x], $range[$x+1]));
  }

  if( $other !== '' ){
	$sel_range = array_merge($sel_range, explode (',', $other));
  }

  $cnt_sel = count($sel_range);
  $max_sel = $cnt_sel - 1;
  for( $x = 0 ; $x < $lenth ; ++$x )
	$out .= $sel_range[mt_rand( 0 , $max_sel)];
  return $out;
}
?>