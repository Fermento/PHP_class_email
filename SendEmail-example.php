<?PHP
die('remove this');

//test script for class_email

require 'SendEmail.php';
//require 'class_email-small.php';


$e = new SendEmail();
$e->set_server( 'mail.local', 25);
//$e->set_server( 'smtp.gmail.com', 587);

$e->set_auth('your smtp username', 'your smtp password');

$e->set_sender( 'Name of sender', 'email@ofsender.local' );

$e->set_hostname('some servers require a valid rdns name');
//$e->set_debug(true);
//$e->set_crypto('ssl');
//$e->set_smtp_try(false);

$subject = 'This is a text subject Ää, Öö, Üü';
$body = "This is the test body of the message\r\nIt may contain special characters: Ää, Öö, Üü\r\n";
//$e->set_type(0);


/* send e-mail right away */
$e->mail('send@mailto.local', $subject, $body);

echo 'last: '.$e->srv_ret['last']."\n";
echo 'all: '.$e->srv_ret['all']."\n";
echo 'full'.$e->srv_ret['full']."\n";



/* or add the message to the queue table to be processed by smtp_queue_processor.php
 *  Please see "smtp_queue_processor.php" for the required table structure
 */
//  $conn = mysqli_connect();
//  mysqli_set_charset( $conn, 'utf8');
//  $e->queue( 'send@mailto.local', $subject, $body, null, $conn);
//
//  Under Windows - also update paths in run_windows.bat
//    $cmd = 'T:\xampp\htdocs\class_email\run_windows.bat';
//    pclose(popen('start /B '.$cmd, 'r'));
//
//  Under Linux
//    exec( 'php -f "/path/to/smtp_queue_processor.php" &> /dev/null &' );


?>