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

$e->mail('send@mailto.local', $subject, $body);

echo 'last: '.$e->srv_ret['last']."\n";
echo 'all: '.$e->srv_ret['all']."\n";
echo 'full'.$e->srv_ret['full']."\n";

?>